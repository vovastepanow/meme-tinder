<?php
// cron_parser.php — фоновый сборщик мемов. Запускается из index.php (nohup) и пишет журнал в консоль сайта.
// Собирает посты с картинками и видео из всех источников, каждого кандидата отправляет модели один раз
// (видео оценивается по нескольким кадрам) и пополняет пул.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/sources/common.php';
require_once __DIR__ . '/sources/dvach.php';
require_once __DIR__ . '/sources/telegram.php';
require_once __DIR__ . '/sources/vk.php';
require_once __DIR__ . '/image_input.php';
require_once __DIR__ . '/meme_analysis.php';
require_once __DIR__ . '/parser/dvach_media.php';
require_once __DIR__ . '/parser/telegram_media.php';
require_once __DIR__ . '/parser/video.php';
require_once __DIR__ . '/parser/candidates.php';

set_time_limit(0);

/** Постов со всех источников, сгруппированных по площадкам. Ошибка одного канала не останавливает сбор. */
function collect_posts_by_platform($database) {
    $posts_by_platform = ['Telegram' => [], 'ВКонтакте' => [], 'Двач' => []];

    foreach (($database['2ch_boards'] ?? []) as $board) {
        echo "Парсинг Двача /" . $board . "/...\n";
        $found = parse_dvach($board);
        echo "-> Двач /" . $board . "/: найдено медиа-постов: " . count($found) . "\n";
        $posts_by_platform['Двач'] = array_merge($posts_by_platform['Двач'], $found);
    }

    foreach (($database['telegram_channels'] ?? []) as $channel) {
        $channel_id = ltrim(trim((string)($channel['id'] ?? '')), '@');
        if ($channel_id === '') continue;
        echo "Парсинг Telegram @" . $channel_id . "...\n";
        $source = ['id' => $channel_id, 'name' => 'Telegram | ' . ($channel['name'] ?? $channel_id)];
        try {
            $found = array_map('prepare_external_post', telegram_media_posts($source));
            echo "-> Telegram @" . $channel_id . ": найдено медиа-постов: " . count($found) . "\n";
            $posts_by_platform['Telegram'] = array_merge($posts_by_platform['Telegram'], $found);
        } catch (Throwable $error) {
            echo "-> Telegram @" . $channel_id . ": " . $error->getMessage() . "\n";
        }
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
        echo "Парсинг ВКонтакте " . $public_id . "...\n";
        $source = ['id' => $public_id, 'name' => 'ВКонтакте | ' . ($public['name'] ?? $public_id)];
        try {
            $found = array_map('prepare_external_post', build_vk_preview_posts($source, VK_POSTS_PER_PUBLIC));
            echo "-> ВКонтакте " . $public_id . ": найдено медиа-постов: " . count($found) . "\n";
            $posts_by_platform['ВКонтакте'] = array_merge($posts_by_platform['ВКонтакте'], $found);
        } catch (Throwable $error) {
            echo "-> ВКонтакте " . $public_id . ": " . $error->getMessage() . "\n";
        }
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
        foreach (expand_post_images($posts) as $meme) {
            if (empty($meme['image']) || isset($seen_images[$meme['image']]) || isset($unique[$meme['image']])) continue;
            $unique[$meme['image']] = $meme;
        }
    }

    $queues = [];
    foreach (array_values($unique) as $meme) {
        $queues[meme_platform($meme)][] = $meme;
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
 * Один кандидат: превью, кадры (для видео), анализ моделью, порог и запись в пул.
 * Возвращает true, если мем добавлен в пул.
 */
function analyze_candidate($m, $history) {
    $is_video = ($m['video'] ?? '') !== '';
    echo "Анализ [{$m['source']}] " . ($is_video ? 'видео' : 'картинка') . " {$m['image']}\n";
    $poster_data = http_get($m['image']);
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

    $frames = $is_video ? video_analysis_frames($m['video'], $poster_data) : [$poster_data];

    echo "-> " . ($is_video ? 'Кадры видео' : 'Картинка') . " уходит в vision-модель один раз: описание, оценка мема и шутки...\n";
    try {
        $analysis = analyze_meme_with_vision($frames, post_caption_for_ai($m), $history, $is_video);
    } catch (Throwable $error) {
        echo "-> ИИ не вернул корректную оценку: " . $error->getMessage() . "\n";
        return false;
    }

    $rule_score = get_meme_rule_score_local(post_caption_for_ai($m), $analysis['description']);
    if ($rule_score === null) {
        echo "-> Отброшено: явный маркер рекламы или спама.\n";
        return false;
    }
    echo "-> Вероятность мема: {$analysis['meme_probability']}%, смешно: " . ($analysis['is_funny'] ? 'да' : 'нет') . ", rule score: {$rule_score}.\n";
    if (!passes_meme_threshold_local($analysis['meme_probability'], $rule_score, $analysis['is_funny'])) {
        echo "-> Отброшено по порогу: вероятность {$analysis['meme_probability']}%, смешно: " . ($analysis['is_funny'] ? 'да' : 'нет') . ", rule score: {$rule_score}.\n";
        return false;
    }

    $m['ai_description'] = $analysis['description'] ?: 'Описание не получено';
    $m['why_funny'] = $analysis['why_funny'] ?: 'Нейросеть не сформулировала объяснение.';
    $m['is_funny'] = $analysis['is_funny'];
    $m['meme_probability'] = $analysis['meme_probability'];
    $m['rating'] = $analysis['rating'];

    [$was_added] = add_meme_to_pool_safely(POOL_FILE, HISTORY_FILE, $m);
    if ($was_added) {
        echo "[!] Мем сохранен на диск и выведен на сайт.\n";
    } else {
        echo "-> Мем уже в пуле или оценён пользователем, пропускаю.\n";
    }
    return $was_added;
}

/** Точка входа: сбор, очередь и анализ кандидатов в пределах лимитов из config.php. */
function run_collector() {
    $started_at = time();
    $history = load_data(HISTORY_FILE, empty_history());
    $seen_images = collect_known_images(POOL_FILE, HISTORY_FILE);

    $database = load_data(SOURCES_FILE, [
        '2ch_boards' => ['b', 'cc'],
        'vk_publics' => [],
        'telegram_channels' => []
    ]);

    $posts_by_platform = collect_posts_by_platform($database);
    $candidates = build_candidate_queue($posts_by_platform, $seen_images);
    echo "Кандидатов для ИИ-анализа: " . count($candidates) . ".\n";

    $analyzed_in_this_session = 0;
    $added_in_this_session = 0;
    foreach ($candidates as $m) {
        if (time() - $started_at > PARSER_MAX_SECONDS) {
            echo "Достигнут лимит времени одного запуска.\n";
            break;
        }
        if ($analyzed_in_this_session >= MEME_ANALYZE_PER_RUN || $added_in_this_session >= MEME_ADDED_PER_RUN) break;
        // Актуальное состояние пула с диска, а не устаревшая копия из начала запуска
        if (count(load_data(POOL_FILE, [])) >= MEME_POOL_TARGET) {
            echo "Пул заполнен до " . MEME_POOL_TARGET . " мемов.\n";
            break;
        }
        $analyzed_in_this_session++;
        // История тоже читается с диска, чтобы вкусы учитывали свежие голоса
        $history = load_data(HISTORY_FILE, $history);
        if (analyze_candidate($m, $history)) $added_in_this_session++;
    }
    echo "Сбор полностью завершен! Добавлено за запуск: {$added_in_this_session}, проанализировано: {$analyzed_in_this_session}.\n";
}

run_collector();
