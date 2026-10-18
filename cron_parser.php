<?php
// cron_parser.php — background collector. Collects posts with pictures and videos from every source,
// sends each candidate once to the vision model (a video is judged by a few of its frames), fills the pool.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/source_adapters.php';
require_once __DIR__ . '/image_input.php';
require_once __DIR__ . '/meme_analysis.php';

set_time_limit(0);

// ---- Text ----

// Убирает ссылки на посты (>>123, >>123 (OP)) и лишние пробелы
function clean_dvach_text($raw_text) {
    $text = html_entity_decode(strip_tags((string)$raw_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/>>\d+(\s*\((?:OP|You)\))?/u', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

// Подпись поста для ИИ: на Дваче комментарий почти всегда не относится к картинке, поэтому его не передаём
function post_caption_for_ai($meme) {
    if (str_starts_with((string)($meme['source'] ?? ''), 'Двач /')) return '';
    $text = trim((string)($meme['text'] ?? ''));
    return $text === 'Пост без текста' ? '' : $text;
}

// ---- Dvach ----

function dvach_media_item($board, $thread_id, $post_id, $text, $image, $video) {
    return [
        'source' => 'Двач /' . $board . '/ · тред #' . $thread_id . ' · пост #' . $post_id,
        'board' => $board,
        'thread_id' => $thread_id,
        'post_id' => $post_id,
        'source_url' => 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.html#' . rawurlencode($post_id),
        'image' => $image,
        'images' => [$image],
        'video' => $video,
        'captions' => [$text],
        'text' => $text
    ];
}

/** Pictures and videos from the fresh threads of one board. A video item uses its thumbnail as the picture. */
function parse_dvach($board, $thread_limit = DVACH_THREADS_PER_BOARD) {
    if (!is_valid_dvach_board($board)) return [];
    $json_raw = http_get('https://2ch.hk/' . rawurlencode($board) . '/catalog.json');
    if (!$json_raw) return [];
    $res = json_decode($json_raw, true);
    if (empty($res['threads']) || !is_array($res['threads'])) return [];

    // Пропускаем закреплённые, бесконечные и закрытые треды: там правила, реферальные ссылки и реклама
    $threads = array_values(array_filter($res['threads'], function ($thread) {
        return empty($thread['sticky']) && empty($thread['endless']) && empty($thread['closed']);
    }));

    $quoted_board = preg_quote($board, '#');
    $image_pattern = '#^/' . $quoted_board . '/src/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';
    $video_pattern = '#^/' . $quoted_board . '/src/[0-9]+/[^/]+\.(?:webm|mp4)$#i';
    $thumb_pattern = '#^/' . $quoted_board . '/thumb/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';

    $output = [];
    foreach (array_slice($threads, 0, $thread_limit) as $catalog_thread) {
        $thread_id = (string)($catalog_thread['num'] ?? '');
        if (!preg_match('/^[0-9]{1,20}$/', $thread_id)) continue;
        $thread_raw = http_get('https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.json');
        if (!$thread_raw) continue;
        $thread_data = json_decode($thread_raw, true);
        $thread = $thread_data['threads'][0] ?? null;
        if (!is_array($thread) || empty($thread['posts']) || !is_array($thread['posts'])) continue;

        foreach (array_slice($thread['posts'], 0, DVACH_POSTS_PER_THREAD) as $post) {
            $text = clean_dvach_text($post['comment'] ?? $post['subject'] ?? '');
            $post_id = (string)($post['num'] ?? '');
            foreach (($post['files'] ?? []) as $file) {
                $path = (string)($file['path'] ?? '');
                $thumb = (string)($file['thumbnail'] ?? '');
                if (preg_match($image_pattern, $path)) {
                    $output[] = dvach_media_item($board, $thread_id, $post_id, $text, 'https://2ch.hk' . $path, '');
                } elseif (preg_match($video_pattern, $path) && preg_match($thumb_pattern, $thumb)) {
                    $output[] = dvach_media_item($board, $thread_id, $post_id, $text, 'https://2ch.hk' . $thumb, 'https://2ch.hk' . $path);
                }
            }
            if (count($output) >= 200) return $output;
        }
    }
    return $output;
}

// ---- Telegram (with videos; the source tab keeps the picture-only adapter) ----

function telegram_background_images($content) {
    preg_match_all('~background-image:\s*url\([\'"]?(https://[^\'")\s]+)~i', $content, $matches);
    $urls = [];
    foreach (($matches[1] ?? []) as $url) {
        $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    preg_match_all('~href="(https://[^"]*(?:cdn-telegram\.org|telegram-cdn\.org)[^"]+\.(?:jpe?g|png|webp)(?:\?[^"]*)?)"~i', $content, $matches);
    foreach (($matches[1] ?? []) as $url) {
        $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return array_values(array_unique($urls));
}

/** Public channel posts with a picture or a video. A video post gets 'video' (file URL) and a poster as 'image'. */
function telegram_media_posts($source, $count = TELEGRAM_POSTS_PER_CHANNEL) {
    $channel = ltrim((string)($source['id'] ?? ''), '@');
    $html = fetch_telegram_channel_page($channel);
    if (!$html) return [];

    preg_match_all('/data-post="([^"]+)"(.*?)(?=data-post="[^"]+"|$)/s', $html, $blocks, PREG_SET_ORDER);
    $posts = [];
    foreach ($blocks as $block) {
        if (count($posts) >= $count) break;
        $parts = explode('/', $block[1], 2);
        $post_id = $parts[1] ?? '';
        if (!preg_match('/^[0-9]+$/', $post_id)) continue;
        $content = $block[2] ?? '';

        $text = '';
        if (preg_match('~<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>~si', $content, $text_match)) {
            $text = clean_html_text($text_match[1]);
        }

        $images = telegram_background_images($content);
        $video = '';
        if (preg_match('~<video[^>]*?\ssrc="([^"]+)"~i', $content, $video_match)) {
            $video = html_entity_decode($video_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($video !== '') {
            $poster = '';
            if (preg_match('~<video[^>]*?\sposter="([^"]+)"~i', $content, $poster_match)) {
                $poster = html_entity_decode($poster_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($poster === '' && $images) $poster = $images[0];
            if ($poster === '') continue; // without a poster there is nothing to show or vote on
            $images = [$poster];
        }
        if (!$images) continue;

        $posts[] = [
            'image' => $images[0],
            'images' => array_slice($images, 0, 4),
            'video' => $video,
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'] ?? ('Telegram | ' . $channel),
            'post_id' => $post_id,
            'url' => 'https://t.me/' . rawurlencode($channel) . '/' . rawurlencode($post_id)
        ];
    }
    return $posts;
}

// ---- Video frames ----

function ffmpeg_binary() {
    static $binary = null;
    if ($binary === null) {
        $binary = '';
        if (function_exists('shell_exec') && function_exists('exec')) {
            $found = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
            if ($found !== '') $binary = $found;
        }
    }
    return $binary;
}

/** Downloads a video to a temp file. Returns its path, or null if it is missing, too big or unreachable. */
function download_video_temp($url, $max_bytes = VIDEO_MAX_BYTES) {
    $path = tempnam(sys_get_temp_dir(), 'mt-video-');
    if ($path === false) return null;
    $handle = @fopen($path, 'wb');
    if (!$handle) {
        @unlink($path);
        return null;
    }
    $size = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'MemeTinder/1.0',
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$size, $max_bytes, $handle) {
            $size += strlen($chunk);
            if ($size > $max_bytes) return 0; // abort: the file is too big
            return fwrite($handle, $chunk);
        }
    ]);
    $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    fclose($handle);
    if (!$ok || $size === 0) {
        @unlink($path);
        return null;
    }
    return $path;
}

/** Cuts evenly spaced frames (JPEG bytes) from a video file. Returns [] when ffmpeg is unavailable. */
function video_frame_jpegs($video_path, $count = VIDEO_FRAMES) {
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === '' || !is_file($video_path)) return [];

    $duration = (float)trim((string)@shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($video_path) . ' 2>/dev/null'));
    if ($duration <= 0) $duration = 3.0;

    $frames = [];
    for ($i = 1; $i <= $count; $i++) {
        $at = $duration * $i / ($count + 1);
        $base = tempnam(sys_get_temp_dir(), 'mt-frame-');
        if ($base === false) break;
        $out = $base . '.jpg';
        $command = escapeshellarg($ffmpeg) . ' -v error -y -ss ' . sprintf('%.2F', $at) . ' -i ' . escapeshellarg($video_path)
            . ' -frames:v 1 -vf scale=768:-2 -q:v 4 ' . escapeshellarg($out) . ' 2>/dev/null';
        exec($command, $output, $exit_code);
        if ($exit_code === 0 && is_file($out) && filesize($out) > 0) {
            $frames[] = file_get_contents($out);
        }
        @unlink($out);
        @unlink($base);
    }
    return array_values(array_filter($frames, 'is_string'));
}

/** Frames of a video for the vision model; falls back to the poster picture with a console note. */
function video_analysis_frames($video_url, $poster_data) {
    $path = download_video_temp($video_url);
    if ($path === null) {
        echo "-> Видео не скачалось (размер или ошибка сети), анализирую превью.\n";
        return [$poster_data];
    }
    $frames = video_frame_jpegs($path);
    @unlink($path);
    if (!$frames) {
        echo "-> Кадры видео не извлечены (нужен ffmpeg на сервере), анализирую превью.\n";
        return [$poster_data];
    }
    echo "-> Извлечено кадров видео: " . count($frames) . "\n";
    return $frames;
}

// ---- Candidates and pool ----

// Нормализует пост из любого источника к одной форме кандидата
function prepare_external_post($post) {
    $post['source_url'] = $post['url'] ?? ($post['source_url'] ?? '');
    $post['captions'] = array_fill(0, count($post['images'] ?? []), $post['text'] ?? '');
    $post['video'] = $post['video'] ?? '';
    return $post;
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

function meme_platform($meme) {
    $source = (string)($meme['source'] ?? '');
    if (str_starts_with($source, 'Telegram |')) return 'Telegram';
    if (str_starts_with($source, 'ВКонтакте |')) return 'ВКонтакте';
    return 'Двач';
}

// Все изображения, которые уже лежат в пуле или уже оценены (читается заново с диска)
function collect_known_images($pool_file, $history_file) {
    $known = array_column(load_data($pool_file, []), 'image');
    $history = load_data($history_file, []);
    foreach (HISTORY_COLLECTIONS as $key) {
        $known = array_merge($known, array_column($history[$key] ?? [], 'image'));
    }
    return array_flip(array_filter($known, 'is_string'));
}

// Добавляет мем в пул, не затирая изменения, сделанные пока парсер работал (голосование пользователя)
function add_meme_to_pool_safely($pool_file, $history_file, $meme) {
    $lock = @fopen($pool_file . '.lock', 'c+');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $known = collect_known_images($pool_file, $history_file);
        $pool = load_data($pool_file, []);
        if (isset($known[$meme['image']])) {
            return [false, $pool];
        }
        $pool[] = $meme;
        usort($pool, function ($a, $b) { return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0); });
        save_data($pool_file, $pool);
        return [true, $pool];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

// ---- Run ----

$started_at = time();
$history = load_data(HISTORY_FILE, empty_history());
$seen_images = collect_known_images(POOL_FILE, HISTORY_FILE);

$database = load_data(SOURCES_FILE, [
    '2ch_boards' => ['b', 'cc'],
    'vk_publics' => [],
    'telegram_channels' => []
]);

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
if ($vk_access_token !== '') {
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
} else {
    echo "VK OAuth не подключен; паблики VK пропущены.\n";
}

// Все картинки всех постов, без уже виденных; затем площадки чередуются, чтобы ни одна не забивала очередь.
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

    $is_video = ($m['video'] ?? '') !== '';
    echo "Анализ [{$m['source']}] " . ($is_video ? 'видео' : 'картинка') . " {$m['image']}\n";
    $poster_data = http_get($m['image']);
    if (!$poster_data) {
        echo "-> Превью не скачалось, пропуск.\n";
        continue;
    }
    try {
        validate_image_bytes($poster_data);
    } catch (Throwable $error) {
        echo "-> Пропуск: " . $error->getMessage() . "\n";
        continue;
    }

    $frames = $is_video ? video_analysis_frames($m['video'], $poster_data) : [$poster_data];

    // История тоже читается с диска, чтобы вкусы учитывали свежие голоса
    $history = load_data(HISTORY_FILE, $history);
    echo "-> " . ($is_video ? 'Кадры видео' : 'Картинка') . " уходит в vision-модель один раз: описание, оценка мема и шутки...\n";
    try {
        $analysis = analyze_meme_with_vision($frames, post_caption_for_ai($m), $history, $is_video);
    } catch (Throwable $error) {
        echo "-> ИИ не вернул корректную оценку: " . $error->getMessage() . "\n";
        continue;
    }

    $rule_score = get_meme_rule_score_local(post_caption_for_ai($m), $analysis['description']);
    if ($rule_score === null) {
        echo "-> Отброшено: явный маркер рекламы или спама.\n";
        continue;
    }
    echo "-> Вероятность мема: {$analysis['meme_probability']}%, смешно: " . ($analysis['is_funny'] ? 'да' : 'нет') . ", rule score: {$rule_score}.\n";
    if (!passes_meme_threshold_local($analysis['meme_probability'], $rule_score, $analysis['is_funny'])) {
        echo "-> Отброшено по порогу: вероятность {$analysis['meme_probability']}%, смешно: " . ($analysis['is_funny'] ? 'да' : 'нет') . ", rule score: {$rule_score}.\n";
        continue;
    }

    $m['ai_description'] = $analysis['description'] ?: 'Описание не получено';
    $m['why_funny'] = $analysis['why_funny'] ?: 'Нейросеть не сформулировала объяснение.';
    $m['is_funny'] = $analysis['is_funny'];
    $m['meme_probability'] = $analysis['meme_probability'];
    $m['rating'] = $analysis['rating'];

    [$was_added] = add_meme_to_pool_safely(POOL_FILE, HISTORY_FILE, $m);
    if ($was_added) {
        $added_in_this_session++;
        echo "[!] Мем сохранен на диск и выведен на сайт.\n";
    } else {
        echo "-> Мем уже в пуле или оценён пользователем, пропускаю.\n";
    }
}
echo "Сбор полностью завершен! Добавлено за запуск: {$added_in_this_session}, проанализировано: {$analyzed_in_this_session}.\n";
