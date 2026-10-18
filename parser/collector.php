<?php
// Фоновый сборщик: посты со всех источников → очередь кандидатов → анализ моделью → пул.
// Точка входа — run_collector(); её вызывает cron_parser.php.

/** Посты одного канала или паблика. Ошибка одного источника пишется в журнал и не останавливает сбор. */
function collect_catalog_posts($platform, $label, $source, callable $fetch) {
    echo "Парсинг {$platform} {$label}...\n";
    try {
        $found = array_map('prepare_external_post', $fetch($source));
        echo "-> {$platform} {$label}: найдено медиа-постов: " . count($found) . "\n";
        return $found;
    } catch (Throwable $error) {
        echo "-> {$platform} {$label}: " . $error->getMessage() . "\n";
        return [];
    }
}

/** Посты со всех источников, сгруппированные по площадкам. */
function collect_posts_by_platform($database) {
    $posts_by_platform = [PLATFORM_TELEGRAM => [], PLATFORM_VK => [], PLATFORM_DVACH => []];

    foreach (($database['2ch_boards'] ?? []) as $board) {
        echo "Парсинг Двача /" . $board . "/...\n";
        $found = parse_dvach($board);
        echo "-> Двач /" . $board . "/: найдено медиа-постов: " . count($found) . "\n";
        array_push($posts_by_platform[PLATFORM_DVACH], ...$found);
    }

    foreach (($database['telegram_channels'] ?? []) as $channel) {
        $channel_id = ltrim(trim((string)($channel['id'] ?? '')), '@');
        if ($channel_id === '') continue;
        $source = ['id' => $channel_id, 'name' => source_display_name(PLATFORM_TELEGRAM, $channel['name'] ?? $channel_id)];
        array_push($posts_by_platform[PLATFORM_TELEGRAM], ...collect_catalog_posts(PLATFORM_TELEGRAM, '@' . $channel_id, $source, 'telegram_media_posts'));
    }

    $vk_access_token = '';
    try {
        $vk_access_token = vk_access_token();
    } catch (Throwable $error) {
        echo "VK OAuth: " . $error->getMessage() . "\n";
    }
    if ($vk_access_token === '') {
        echo "VK OAuth не подключен; паблики VK пропущены.\n";
        return $posts_by_platform;
    }
    foreach (($database['vk_publics'] ?? []) as $public) {
        $public_id = trim((string)($public['id'] ?? ''));
        if ($public_id === '') continue;
        $source = ['id' => $public_id, 'name' => source_display_name(PLATFORM_VK, $public['name'] ?? $public_id)];
        array_push($posts_by_platform[PLATFORM_VK], ...collect_catalog_posts(PLATFORM_VK, $public_id, $source, function ($source) {
            return build_vk_preview_posts($source, VK_POSTS_PER_PUBLIC);
        }));
    }
    return $posts_by_platform;
}

/**
 * Очередь кандидатов: все картинки без уже виденных, затем площадки чередуются,
 * чтобы ни одна не забивала очередь. До 500 кандидатов.
 */
function build_candidate_queue($posts_by_platform, $seen_images) {
    $unique = [];
    foreach ($posts_by_platform as $posts) {
        foreach (expand_post_images($posts) as $item) {
            if (empty($item['image']) || isset($seen_images[$item['image']]) || isset($unique[$item['image']])) continue;
            $unique[$item['image']] = $item;
        }
    }

    $queues = [];
    foreach (array_values($unique) as $item) {
        $queues[content_platform($item)][] = $item;
    }
    foreach ($queues as $platform => $list) {
        shuffle($queues[$platform]);
    }
    $queues = array_values($queues);

    $candidates = [];
    while ($queues && count($candidates) < 500) {
        foreach ($queues as $index => $queue) {
            if (!$queue) {
                unset($queues[$index]);
                continue;
            }
            $candidates[] = array_shift($queues[$index]);
        }
    }
    return $candidates;
}

/**
 * Один кандидат: превью, кадры (для видео), анализ моделью по текущей теме, порог и запись в пул.
 * Возвращает true, если запись добавлена в пул.
 */
function analyze_candidate($item, $history, $topic) {
    $is_video = ($item['video'] ?? '') !== '';
    echo "Анализ [{$item['source']}] " . ($is_video ? 'видео' : 'картинка') . " {$item['image']}\n";
    $poster_data = http_get($item['image']);
    if (!$poster_data) {
        echo "-> Превью не скачалось, пропуск.\n";
        return false;
    }
    try {
        validate_image_bytes($poster_data);
    } catch (Throwable $error) {
        echo "-> Пропуск: " . $error->getMessage() . "\n";
        return false;
    }

    $frames = $is_video ? video_analysis_frames($item['video'], $poster_data) : [$poster_data];
    $caption = post_caption_for_ai($item);

    echo "-> " . ($is_video ? 'Кадры видео уходят' : 'Картинка уходит') . " в vision-модель: описание и оценка по теме «{$topic}»...\n";
    try {
        $analysis = analyze_content_with_vision($frames, $caption, $history, $topic, $is_video);
    } catch (Throwable $error) {
        echo "-> ИИ не вернул корректную оценку: " . $error->getMessage() . "\n";
        return false;
    }

    if (is_spam_content($caption, $analysis['description'])) {
        echo "-> Отброшено: явный маркер рекламы или спама.\n";
        return false;
    }
    $verdict = "соответствие теме {$analysis['relevance']}%, прогноз оценки {$analysis['rating']}%";
    if (!passes_relevance_threshold($analysis['relevance'])) {
        echo "-> Отброшено по порогу: {$verdict}.\n";
        return false;
    }
    echo "-> Принято: {$verdict}.\n";

    $item['description'] = $analysis['description'] ?: 'Описание не получено';
    $item['why_relevant'] = $analysis['why_relevant'] ?: 'Нейросеть не сформулировала объяснение.';
    $item['relevance'] = $analysis['relevance'];
    $item['rating'] = $analysis['rating'];
    $item['topic'] = $topic;

    $item = cache_candidate_media($item, $poster_data);
    $was_added = add_item_to_pool_safely(POOL_FILE, HISTORY_FILE, $item);
    if ($was_added) {
        echo "[!] Запись сохранена и выведена на сайт.\n";
    } else {
        // Файл кэша не удаляем сразу: у записи, которая уже лежит в пуле, тот же файл (имя — хэш адреса).
        // Лишние файлы уберёт media_cache_cleanup().
        echo "-> Запись уже в пуле или оценена пользователем, пропускаю.\n";
    }
    return $was_added;
}

/**
 * Сохраняет медиа отобранной записи в кэш: ссылки источников со временем протухают,
 * а копия доступна, пока запись лежит в пуле. Без кэша запись показывается по исходной ссылке.
 */
function cache_candidate_media($item, $poster_data) {
    $item['image_local'] = media_cache_store_image($item['image'], $poster_data);
    if (($item['video'] ?? '') !== '') {
        $item['video_local'] = media_cache_store_video($item['video']);
        echo $item['video_local'] !== '' ? "-> Видео сохранено в кэш сайта.\n" : "-> Видео не удалось сохранить в кэш, на сайте будет исходная ссылка.\n";
    }
    if ($item['image_local'] === '') echo "-> Картинку не удалось сохранить в кэш, на сайте будет исходная ссылка.\n";
    return $item;
}

/** Точка входа: сбор, очередь и анализ кандидатов в пределах лимитов из config.php. */
function run_collector() {
    $started_at = time();
    $removed = media_cache_cleanup(load_data(POOL_FILE, []));
    if ($removed) echo "Кэш медиа: удалено устаревших файлов: {$removed}.\n";
    $history = load_data(HISTORY_FILE, empty_history());
    $seen_images = collect_known_images(POOL_FILE, HISTORY_FILE);

    $posts_by_platform = collect_posts_by_platform(load_source_db());
    $candidates = build_candidate_queue($posts_by_platform, $seen_images);
    echo "Тема поиска: «" . current_topic() . "». Кандидатов для ИИ-анализа: " . count($candidates) . ".\n";

    $analyzed_in_this_session = 0;
    $added_in_this_session = 0;
    foreach ($candidates as $item) {
        if (time() - $started_at > PARSER_MAX_SECONDS) {
            echo "Достигнут лимит времени одного запуска.\n";
            break;
        }
        if ($analyzed_in_this_session >= CONTENT_ANALYZE_PER_RUN || $added_in_this_session >= CONTENT_ADDED_PER_RUN) break;
        // Актуальное состояние пула с диска, а не устаревшая копия из начала запуска
        if (count(load_data(POOL_FILE, [])) >= CONTENT_POOL_TARGET) {
            echo "Пул заполнен до " . CONTENT_POOL_TARGET . " записей.\n";
            break;
        }
        $analyzed_in_this_session++;
        // История и тема тоже читаются с диска: вкус учитывает свежие голоса, а смена темы действует сразу
        $history = load_data(HISTORY_FILE, $history);
        if (analyze_candidate($item, $history, current_topic())) $added_in_this_session++;
    }
    echo "Сбор полностью завершен! Добавлено за запуск: {$added_in_this_session}, проанализировано: {$analyzed_in_this_session}.\n";
}
