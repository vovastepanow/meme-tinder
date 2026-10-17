<?php
// cron_parser.php — background job: collects posts, runs OCR + AI scoring, fills memes_pool.json.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/source_adapters.php';
require_once __DIR__ . '/ocr_helpers.php';
require_once __DIR__ . '/meme_analysis.php';

const PARSER_CANDIDATE_LIMIT = 12;
const POOL_TARGET_SIZE = 12;
const MAX_ADDED_PER_RUN = 8;

const DVACH_PARSER_HTTP_OPTIONS = [];

// Used only when sources_db.json is missing (index.php normally creates it).
const PARSER_FALLBACK_SOURCES = [
    '2ch_boards' => ['b', 'cc'],
    'vk_publics' => [
        ['id' => 'british_humor', 'name' => 'БРИТИШ ЮМОР'],
        ['id' => 'bessm_humor', 'name' => 'ОВСЯНКА, СЭР!']
    ]
];

function parse_dvach($board, $thread_limit = 2) {
    $catalog = fetch_2ch_json($board, null, DVACH_PARSER_HTTP_OPTIONS);
    if (empty($catalog['threads']) || !is_array($catalog['threads'])) return [];

    $output = [];
    foreach (array_slice($catalog['threads'], 0, $thread_limit) as $catalog_thread) {
        $thread_id = (string)($catalog_thread['num'] ?? '');
        if (!is_valid_dvach_id($thread_id)) continue;
        $posts = dvach_thread_posts(fetch_2ch_json($board, $thread_id, DVACH_PARSER_HTTP_OPTIONS));

        foreach (array_slice($posts, 0, 25) as $post) {
            $images = dvach_post_images($board, $post);
            if (!$images) continue;

            $text = dvach_post_text($post);
            $post_id = (string)($post['num'] ?? '');
            $output[] = [
                'source' => 'Двач /' . $board . '/ · тред #' . $thread_id . ' · пост #' . $post_id,
                'board' => $board,
                'thread_id' => $thread_id,
                'post_id' => $post_id,
                'source_url' => dvach_post_url($board, $thread_id, $post_id),
                'image' => $images[0],
                'images' => $images,
                'captions' => array_fill(0, count($images), $text),
                'text' => $text
            ];
            if (count($output) >= 24) break 2;
        }
    }
    return $output;
}

function expand_post_images($posts) {
    $candidates = [];
    foreach ($posts as $post) {
        $images = is_array($post['images'] ?? null) ? $post['images'] : [];
        if (!$images && !empty($post['image'])) $images = [$post['image']];
        $images = array_values(array_unique(array_filter($images, 'is_string')));

        foreach ($images as $index => $image) {
            $candidate = $post;
            $candidate['image'] = $image;
            $candidate['images'] = [$image];
            $candidate['captions'] = [$post['captions'][$index] ?? ($post['text'] ?? '')];
            $candidates[] = $candidate;
        }
    }
    return $candidates;
}

function as_parser_candidate($post) {
    $post['source_url'] = $post['url'] ?? '';
    $post['captions'] = array_fill(0, count($post['images'] ?? []), $post['text'] ?? '');
    return $post;
}

function collect_telegram_posts($channels) {
    $posts = [];
    foreach ($channels as $channel) {
        $channel_id = ltrim(trim((string)($channel['id'] ?? '')), '@');
        if ($channel_id === '') continue;
        echo "Парсинг Telegram @" . $channel_id . "...\n";
        $source = ['id' => $channel_id, 'name' => 'Telegram | ' . ($channel['name'] ?? $channel_id)];
        try {
            foreach (build_telegram_preview_posts($source, 24) as $post) $posts[] = as_parser_candidate($post);
        } catch (Throwable $error) {
            echo "-> Telegram @" . $channel_id . ": " . $error->getMessage() . "\n";
        }
    }
    return $posts;
}

function collect_vk_posts($publics) {
    $vk_access_token = '';
    try {
        $vk_access_token = vk_access_token();
    } catch (Throwable $error) {
        echo "VK OAuth: " . $error->getMessage() . "\n";
    }
    if ($vk_access_token === '') {
        echo "VK OAuth не подключен; паблики VK пропущены.\n";
        return [];
    }

    $posts = [];
    foreach ($publics as $public) {
        $public_id = trim((string)($public['id'] ?? ''));
        if ($public_id === '') continue;
        echo "Парсинг ВКонтакте " . $public_id . "...\n";
        $source = ['id' => $public_id, 'name' => 'ВКонтакте | ' . ($public['name'] ?? $public_id)];
        try {
            foreach (build_vk_preview_posts($source, 40) as $post) $posts[] = as_parser_candidate($post);
        } catch (Throwable $error) {
            echo "-> ВКонтакте " . $public_id . ": " . $error->getMessage() . "\n";
        }
    }
    return $posts;
}

function collect_all_posts($database) {
    $posts = [];
    foreach (($database['2ch_boards'] ?? ['b', 'cc']) as $board) {
        echo "Парсинг Двача /" . $board . "/...\n";
        $posts = array_merge($posts, parse_dvach($board));
    }
    $posts = array_merge($posts, collect_telegram_posts($database['telegram_channels'] ?? []));
    return array_merge($posts, collect_vk_posts($database['vk_publics'] ?? []));
}

function meme_platform($meme) {
    $source = (string)($meme['source'] ?? '');
    if (str_starts_with($source, 'Telegram |')) return 'Telegram';
    if (str_starts_with($source, 'ВКонтакте |')) return 'ВКонтакте';
    return 'Двач';
}

/** Drops already seen images and picks up to $limit candidates round-robin across platforms. */
function select_balanced_candidates($memes, $seen_images, $limit) {
    $unique_memes = [];
    foreach ($memes as $meme) {
        if (in_array($meme['image'], $seen_images)) continue;
        $unique_memes[$meme['image']] = $meme;
    }
    $memes_by_platform = ['Telegram' => [], 'ВКонтакте' => [], 'Двач' => []];
    foreach ($unique_memes as $meme) {
        $memes_by_platform[meme_platform($meme)][] = $meme;
    }
    foreach ($memes_by_platform as &$platform_memes) shuffle($platform_memes);
    unset($platform_memes);

    $selected = [];
    while (count($selected) < $limit) {
        $added_candidate = false;
        foreach ($memes_by_platform as &$platform_memes) {
            if (!$platform_memes) continue;
            $selected[] = array_shift($platform_memes);
            $added_candidate = true;
            if (count($selected) >= $limit) break;
        }
        unset($platform_memes);
        if (!$added_candidate) break;
    }
    return $selected;
}

function ocr_image_for_parser($image_file) {
    $candidates = [];
    foreach ([6, 11] as $page_mode) {
        $candidate = run_tesseract_pass($image_file, $page_mode);
        $candidates[] = $candidate;
        $rejected_summary = array_map(function($token) {
            return $token['text'] . '(' . $token['confidence'] . ':' . $token['reason'] . ')';
        }, array_slice($candidate['rejected'], 0, 8));
        echo "[OCR] PSM {$page_mode}: raw={$candidate['raw_word_count']}, accepted={$candidate['word_count']} words/{$candidate['line_count']} lines, confidence={$candidate['confidence']}, boxes=" . implode(';', array_map('ocr_box_label', $candidate['regions'])) . ", rejected=" . implode(';', $rejected_summary) . "\n";
    }
    return select_consensus_tesseract_candidate($candidates);
}

/** Returns the meme enriched with AI fields, or null when it should be skipped. */
function analyze_parser_candidate($m, $history) {
    echo "Анализ медиа [{$m['source']}]...\n";
    $img_data = http_get($m['image']);
    if (!$img_data) return null;

    $tmp_img = tempnam(sys_get_temp_dir(), 'meme_');
    file_put_contents($tmp_img, $img_data);
    $ocr_result = ocr_image_for_parser($tmp_img);
    $raw_ocr = $ocr_result['text'];
    $ocr_regions = $ocr_result['regions'];
    $tesseract_text = $raw_ocr !== '' ? clean_ocr_text_local($raw_ocr) : '';
    echo "[OCR] selected PSM {$ocr_result['psm']} scale={$ocr_result['scale']} confirmed=" . ($ocr_result['consensus_confirmed'] ? 'yes' : 'no') . " confidence={$ocr_result['confidence']}: raw=\"{$raw_ocr}\", cleaned=\"{$tesseract_text}\", boxes=" . implode(';', array_map('ocr_box_label', $ocr_regions)) . "\n";
    $raw_english_desc = describe_scene_for_parser(base64_encode(blur_ocr_regions($img_data, $ocr_regions)));
    @unlink($tmp_img);

    $post_text_for_ai = str_starts_with((string)($m['source'] ?? ''), 'Двач /') ? '' : (string)($m['text'] ?? '');
    $rule_score = get_meme_rule_score_local($post_text_for_ai . ' ' . $tesseract_text, $raw_english_desc);
    if ($rule_score === null) {
        echo "-> Отброшено: найден явный маркер рекламы, спама или интерфейса.\n";
        return null;
    }

    echo "-> Оцениваем мем, шутку и персональный рейтинг одним запросом...\n";
    $analysis = assess_meme_local(build_meme_analysis_context($m, $tesseract_text, $raw_english_desc), $history);
    if (!$analysis) {
        echo "-> ИИ не вернул корректную оценку.\n";
        return null;
    }
    echo "-> Вероятность мема: {$analysis['meme_probability']}%, rule score: {$rule_score}.\n";
    if (!passes_meme_threshold_local($analysis['meme_probability'], $rule_score, $analysis['is_funny'])) {
        echo "-> Отброшено по порогу: вероятность {$analysis['meme_probability']}%, смешно: " . ($analysis['is_funny'] ? 'да' : 'нет') . ", rule score: {$rule_score}.\n";
        return null;
    }

    $m['ai_description'] = $analysis['description'] ?: 'Описание не получено';
    $m['why_funny'] = $analysis['why_funny'] ?: 'Нейросеть не сформулировала объяснение.';
    $m['is_funny'] = $analysis['is_funny'];
    $m['meme_probability'] = $analysis['meme_probability'];
    $m['rating'] = $analysis['rating'];
    if (!empty($tesseract_text)) {
        $m['ai_description'] .= "\n\nТекст на картинке: " . $tesseract_text;
    }
    return $m;
}

function run_meme_parser() {
    $history = load_data(HISTORY_FILE, empty_history());
    $final_pool = load_data(POOL_FILE, []);
    $seen_images = array_merge(
        array_column($final_pool, 'image'),
        array_column($history['liked_memes'] ?? [], 'image'),
        array_column($history['disliked_memes'] ?? [], 'image')
    );
    $database = load_data(SOURCES_FILE, PARSER_FALLBACK_SOURCES);

    $candidates = select_balanced_candidates(expand_post_images(collect_all_posts($database)), $seen_images, PARSER_CANDIDATE_LIMIT);
    echo "Отобрано " . count($candidates) . " уникальных картинок для ИИ-анализа.\n";

    $added_in_this_session = 0;
    foreach ($candidates as $candidate) {
        if (count($final_pool) >= POOL_TARGET_SIZE || $added_in_this_session >= MAX_ADDED_PER_RUN) break;
        $meme = analyze_parser_candidate($candidate, $history);
        if ($meme === null) continue;

        $final_pool[] = $meme;
        $added_in_this_session++;
        usort($final_pool, function($a, $b) { return $b['rating'] <=> $a['rating']; });
        save_data(POOL_FILE, $final_pool);
        echo "[!] Мем сохранен на диск и выведен на сайт.\n";
    }
    echo "Сбор полностью завершен!\n";
}

run_meme_parser();
