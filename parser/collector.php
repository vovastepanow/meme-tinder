<?php
// Фоновый сборщик: посты со всех источников → очередь кандидатов → анализ моделью → пул.
// Точка входа — run_collector(); её вызывает cron_parser.php.

/**
 * Посты одного канала или паблика с учётом активности. Замороженный источник пропускается до времени
 * перепроверки; после загрузки активность пересчитывается, и источник замораживается или размораживается.
 * Возвращает [посты (без устаревших), обновлённая запись каталога или null, событие для статистики].
 */
function collect_catalog_source($platform, array $entry, $topic) {
    $id = ltrim(trim((string)($entry['id'] ?? '')), '@');
    $label = $platform === PLATFORM_TELEGRAM ? '@' . $id : $id;
    if ($id === '') return [[], null, null];
    if (is_source_frozen($entry)) return [[], null, 'skipped_frozen'];

    $source = ['id' => $id, 'name' => source_display_name($platform, $entry['name'] ?? $id)];
    echo "Парсинг {$platform} {$label}...\n";
    try {
        if ($platform === PLATFORM_TELEGRAM) {
            $html = fetch_telegram_channel_page($id);
            if (!$html) {
                echo "-> {$platform} {$label}: страница не загрузилась.\n";
                return [[], null, null];
            }
            $posts = telegram_posts_from_html($html, $source, TELEGRAM_POSTS_PER_CHANNEL);
            $times = telegram_post_times($html);
        } else {
            $posts = build_vk_preview_posts($source, VK_POSTS_PER_PUBLIC);
            $times = array_column($posts, 'posted_at');
        }
    } catch (Throwable $error) {
        echo "-> {$platform} {$label}: " . $error->getMessage() . "\n";
        return [[], null, null];
    }

    $activity = source_activity($times);
    [$updated, $event] = apply_source_activity($entry, $activity);
    $recheck = 'перепроверка через ' . SOURCE_FROZEN_RECHECK_DAYS . ' дн';
    if ($event === 'frozen' || $event === 'still_frozen') {
        echo "-> {$platform} {$label}: " . ($event === 'frozen' ? 'заморожен' : 'всё ещё не активен') . " ({$updated['frozen_reason']}), {$recheck}.\n";
        return [[], $updated, $event];
    }
    if ($event === 'revived') echo "-> {$platform} {$label}: ожил и разморожен (" . describe_source_activity($activity) . ").\n";

    $fresh = array_values(array_filter($posts, function ($post) {
        return !is_post_outdated($post['posted_at'] ?? 0);
    }));
    $old = count($posts) - count($fresh);
    echo "-> {$platform} {$label}: найдено медиа-постов: " . count($fresh) . ($old ? ", устаревших пропущено: {$old}" : '')
        . " (" . describe_source_activity($activity) . ").\n";
    return [array_map('prepare_external_post', $fresh), $updated, $event];
}

/** Посты со всех источников текущей темы, сгруппированные по площадкам. */
function collect_posts_by_platform($database, $topic) {
    $posts_by_platform = [PLATFORM_TELEGRAM => [], PLATFORM_VK => [], PLATFORM_DVACH => []];
    $stats = ['other_topic' => 0, 'skipped_frozen' => 0, 'frozen' => 0, 'still_frozen' => 0, 'revived' => 0];
    $updates = [];

    foreach (($database['2ch_boards'] ?? []) as $board) {
        echo "Парсинг Двача /" . $board . "/...\n";
        $found = parse_dvach($board);
        echo "-> Двач /" . $board . "/: найдено медиа-постов: " . count($found) . "\n";
        array_push($posts_by_platform[PLATFORM_DVACH], ...$found);
    }

    $vk_connected = true;
    try {
        $vk_connected = vk_access_token() !== '';
    } catch (Throwable $error) {
        echo "VK OAuth: " . $error->getMessage() . "\n";
        $vk_connected = false;
    }
    if (!$vk_connected) echo "VK OAuth не подключен; паблики VK пропущены.\n";

    $catalogs = [PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'];
    foreach ($catalogs as $platform => $key) {
        if ($platform === PLATFORM_VK && !$vk_connected) continue;
        foreach (($database[$key] ?? []) as $entry) {
            if (!catalog_entry_matches_topic($entry, $topic)) {
                $stats['other_topic']++;
                continue;
            }
            [$posts, $updated, $event] = collect_catalog_source($platform, $entry, $topic);
            if ($event !== null) $stats[$event]++;
            if ($updated !== null) $updates[$key . ':' . strtolower((string)$entry['id'])] = $updated;
            array_push($posts_by_platform[$platform], ...$posts);
        }
    }
    save_catalog_entry_updates($updates);

    $notes = [];
    if ($stats['other_topic']) $notes[] = "другой темы: {$stats['other_topic']}";
    if ($stats['skipped_frozen']) $notes[] = "заморожено (ждут перепроверки): {$stats['skipped_frozen']}";
    if ($stats['frozen']) $notes[] = "заморожено сейчас: {$stats['frozen']}";
    if ($stats['revived']) $notes[] = "ожило: {$stats['revived']}";
    if ($notes) echo "Источники: " . implode(', ', $notes) . ".\n";
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
            if (is_post_outdated($item['posted_at'] ?? 0)) continue; // старше CONTENT_MAX_AGE_DAYS
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

/** Скачивает и проверяет картинку кандидата (у видео — обложку). Возвращает байты или null. */
function fetch_candidate_poster($item) {
    $poster_data = http_get($item['image']);
    if (!$poster_data) {
        echo "-> Превью не скачалось, пропуск.\n";
        return null;
    }
    try {
        validate_image_bytes($poster_data);
    } catch (Throwable $error) {
        echo "-> Пропуск: " . $error->getMessage() . "\n";
        return null;
    }
    return $poster_data;
}

/**
 * Полный анализ кандидата: кадры (для видео), анализ моделью с размышлениями, порог и запись в пул.
 * Возвращает true, если запись добавлена в пул.
 */
function analyze_candidate($item, $poster_data, $history, $topic) {
    $is_video = ($item['video'] ?? '') !== '';
    echo "Полный анализ [{$item['source']}] " . ($is_video ? 'видео' : 'картинка') . " {$item['image']}\n";
    $video = $is_video ? video_analysis_input($item['video'], $poster_data) : null;
    try {
        return analyze_and_store_candidate($item, $poster_data, $video, $history, $topic);
    } finally {
        release_video_analysis($video); // скачанный ролик нужен только на время анализа и сохранения в кэш
    }
}

/**
 * Быстрая оценка пачки кандидатов. Возвращает прошедших порог, лучшие первыми:
 * [['item' => ..., 'poster' => байты, 'score' => число], ...]. $stats считает оценённых и отсеянных.
 */
function prescreen_batch(array $batch, $topic, array &$stats, $deadline) {
    $passed = [];
    foreach ($batch as $item) {
        if (time() > $deadline) break;
        $kind = ($item['video'] ?? '') !== '' ? 'видео' : 'картинка';
        echo "Быстрая оценка [{$item['source']}] {$kind}\n";
        $poster = fetch_candidate_poster($item);
        if ($poster === null) continue;
        if (!PRESCREEN_ENABLED) {
            $passed[] = ['item' => $item, 'poster' => $poster, 'score' => 0];
            continue;
        }
        $quick = quick_relevance_score($poster, post_caption_for_ai($item), $topic);
        $stats['prescreened']++;
        if ($quick['score'] === null) {
            // Модель не дала число — не отбрасываем: решит полный анализ.
            echo "-> Быстрая оценка не получена ({$quick['seconds']} с" . ($quick['error'] ? ", {$quick['error']}" : '') . "), отправляю на полный анализ.\n";
            $passed[] = ['item' => $item, 'poster' => $poster, 'score' => PRESCREEN_THRESHOLD];
            continue;
        }
        if ($quick['score'] < PRESCREEN_THRESHOLD) {
            $stats['rejected']++;
            echo "-> Быстрая оценка {$quick['score']}% ({$quick['seconds']} с) — отсеяно.\n";
            continue;
        }
        echo "-> Быстрая оценка {$quick['score']}% ({$quick['seconds']} с) — на полный анализ.\n";
        $passed[] = ['item' => $item, 'poster' => $poster, 'score' => $quick['score']];
    }
    usort($passed, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return $passed;
}

function analyze_and_store_candidate($item, $poster_data, $video, $history, $topic) {
    $frames = $video['frames'] ?? [$poster_data];
    $caption = post_caption_for_ai($item);
    $is_storyboard = $video !== null && count($frames) > 1;

    echo "-> " . ($is_storyboard ? 'Раскадровка видео уходит' : 'Картинка уходит') . " в vision-модель: описание и оценка по теме «{$topic}»...\n";
    try {
        $analysis = analyze_content_with_vision($frames, $caption, $history, $topic, $is_storyboard ? $video : null);
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

    $item = cache_candidate_media($item, $poster_data, $video['file'] ?? null);
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
function cache_candidate_media($item, $poster_data, $downloaded_video = null) {
    $item['image_local'] = media_cache_store_image($item['image'], $poster_data);
    if (($item['video'] ?? '') !== '') {
        // HEVC и другие форматы, которые браузер не воспроизведёт, перекодируются в H.264 перед сохранением.
        $playable = $downloaded_video !== null ? browser_playable_video($downloaded_video) : null;
        $item['video_local'] = media_cache_store_video($item['video'], $playable, $playable !== $downloaded_video);
        if ($playable !== null && $playable !== $downloaded_video) @unlink($playable);
        echo $item['video_local'] !== '' ? "-> Видео сохранено в кэш сайта.\n" : "-> Видео не удалось сохранить в кэш, на сайте будет исходная ссылка.\n";
    }
    if ($item['image_local'] === '') echo "-> Картинку не удалось сохранить в кэш, на сайте будет исходная ссылка.\n";
    return $item;
}

/** Причина остановить сбор (лимиты из config.php) или null, если можно продолжать. */
function collector_stop_reason($started_at, $analyzed, $added) {
    if (time() - $started_at > PARSER_MAX_SECONDS) return 'Достигнут лимит времени одного запуска.';
    if ($analyzed >= CONTENT_ANALYZE_PER_RUN) return 'Достигнут лимит полных анализов за запуск.';
    if ($added >= CONTENT_ADDED_PER_RUN) return 'Достигнут лимит новых записей за запуск.';
    // Актуальное состояние пула с диска, а не устаревшая копия из начала запуска
    if (count(load_data(POOL_FILE, [])) >= CONTENT_POOL_TARGET) return 'Пул заполнен до ' . CONTENT_POOL_TARGET . ' записей.';
    return null;
}

/**
 * Точка входа: сбор, очередь кандидатов, затем пачками — быстрая оценка и полный анализ лучших из пачки.
 * Пачки нужны, чтобы первые записи появлялись в пуле сразу, а не после быстрой оценки всей очереди.
 */
function run_collector() {
    $started_at = time();
    $removed = media_cache_cleanup(load_data(POOL_FILE, []));
    if ($removed) echo "Кэш медиа: удалено устаревших файлов: {$removed}.\n";
    $history = load_data(HISTORY_FILE, empty_history());
    $seen_images = collect_known_images(POOL_FILE, HISTORY_FILE);

    $topic = current_topic();
    if (source_discovery_due(load_source_db(), $topic)) run_source_discovery($topic);

    $posts_by_platform = collect_posts_by_platform(load_source_db(), $topic);
    $candidates = build_candidate_queue($posts_by_platform, $seen_images);
    echo "Тема поиска: «" . current_topic() . "». Кандидатов: " . count($candidates) . "."
        . (PRESCREEN_ENABLED ? " Сначала быстрая оценка по картинке " . PRESCREEN_MAX_SIDE . " px, порог " . PRESCREEN_THRESHOLD . "%." : '') . "\n";

    $stats = ['prescreened' => 0, 'rejected' => 0];
    $analyzed = 0;
    $added = 0;
    $stop = null;
    foreach (array_chunk($candidates, PRESCREEN_BATCH) as $batch) {
        if ($stop = collector_stop_reason($started_at, $analyzed, $added)) break;
        // Тема читается с диска для каждой пачки: смена темы на сайте действует сразу
        $passed = prescreen_batch($batch, current_topic(), $stats, $started_at + PARSER_MAX_SECONDS);
        foreach ($passed as $candidate) {
            if ($stop = collector_stop_reason($started_at, $analyzed, $added)) break 2;
            $analyzed++;
            // История тоже читается с диска, чтобы вкусы учитывали свежие голоса
            $history = load_data(HISTORY_FILE, $history);
            if (analyze_candidate($candidate['item'], $candidate['poster'], $history, current_topic())) $added++;
        }
    }
    if ($stop) echo $stop . "\n";
    echo "Сбор завершён. Быстро оценено: {$stats['prescreened']}, отсеяно быстрой оценкой: {$stats['rejected']},"
        . " полных анализов: {$analyzed}, добавлено в пул: {$added}.\n";
}
