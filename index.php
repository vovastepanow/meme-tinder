<?php
// index.php
$pool_file = __DIR__ . '/memes_pool.json';
$history_file = __DIR__ . '/meme_history.json';
$sources_file = __DIR__ . '/sources_db.json';
require_once __DIR__ . '/source_adapters.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/ocr_helpers.php';

function debug_image($title, $background, $accent) {
    $title = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><rect width="640" height="480" fill="' . $background . '"/><circle cx="510" cy="90" r="145" fill="' . $accent . '"/><path d="M0 370 Q160 280 320 370 T640 340 V480 H0Z" fill="#171717"/><text x="42" y="190" fill="#fff" font-family="sans-serif" font-size="42" font-weight="700">' . $title . '</text><text x="44" y="245" fill="#fff" font-family="sans-serif" font-size="22">ТЕСТОВЫЙ МЕМ</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function debug_initial_state() {
    return [
        'pool' => [
            ['image' => debug_image('ПОНЕДЕЛЬНИК', '#176b87', '#e95d3f'), 'text' => 'План на утро: стать продуктивным. Факт: уже обед.', 'ai_description' => 'Человек строит амбициозные планы, пока время незаметно уходит.', 'rating' => 91, 'source' => 'Демо-сообщество'],
            ['image' => debug_image('ОЖИДАНИЕ / РЕАЛЬНОСТЬ', '#58734b', '#f1c453'), 'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.', 'ai_description' => 'Шутка о привычке отвлекаться в интернете.', 'rating' => 76, 'source' => 'Локальный тест']
        ],
        'history' => ['liked_memes' => [], 'disliked_memes' => [], 'undecided_memes' => [], 'wrong_topic_memes' => []],
        'source_index' => 0,
        'source_votes' => []
    ];
}

function create_demo_pool() {
    return [
        [
            'image' => 'https://images.unsplash.com/photo-1517849845537-4d257902454a?auto=format&fit=crop&w=1200&q=80',
            'text' => 'План на утро: стать продуктивным. Факт: уже обед.',
            'ai_description' => 'Шутка про оптимистичные обещания и реальную жизнь, где время уходит быстрее, чем кажется.',
            'rating' => 92,
            'source' => 'Демо-сообщество'
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1493246507139-91e8fad9978e?auto=format&fit=crop&w=1200&q=80',
            'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.',
            'ai_description' => 'Мем про бесконечную прокрутку и неспособность удержать фокус.',
            'rating' => 84,
            'source' => 'Локальный тест'
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80',
            'text' => 'Когда код работает с первого раза, а потом ты всё равно проверяешь его ещё раз.',
            'ai_description' => 'Реалистичная ирония про разработчика, который не может доверять счастью без перепроверки.',
            'rating' => 88,
            'source' => 'Демо-мемы'
        ]
    ];
}

function create_default_source_catalog() {
    return [
        '2ch_boards' => ['b', 'me', 'pr', 'r', 'cc', 'a', 'mu', 'd', 's', 'b2'],
        'vk_publics' => [
            ['id' => 'mrzlk', 'name' => 'MDK'],
            ['id' => 'leprum', 'name' => 'Лепрекон'],
            ['id' => 'sci_one', 'name' => 'Умный Юмор'],
            ['id' => 'british_humor', 'name' => 'БРИТИШ ЮМОР'],
            ['id' => 'bessm_humor', 'name' => 'ОВСЯНКА, СЭР!'],
            ['id' => 'memes_2024', 'name' => 'Мемы на каждый день']
        ],
        'telegram_channels' => [
            ['id' => 'memesdaily', 'name' => 'Мемы каждый день'],
            ['id' => 'this_is_not_funny', 'name' => 'Это не смешно'],
            ['id' => 'humorhub', 'name' => 'Humor Hub'],
            ['id' => 'trashmemes', 'name' => 'Trash memes'],
            ['id' => 'russmemes', 'name' => 'Русские мемы'],
            ['id' => 'memechi', 'name' => 'Meme Chi']
        ],
        'source_votes' => []
    ];
}

function ensure_default_project_data() {
    global $pool_file, $history_file, $sources_file;

    if (!is_file($history_file)) {
        save_data($history_file, ['liked_memes' => [], 'disliked_memes' => [], 'undecided_memes' => [], 'wrong_topic_memes' => []]);
    }

    if (!is_file($sources_file)) {
        save_data($sources_file, create_default_source_catalog());
    } else {
        $source_db = load_data($sources_file, create_default_source_catalog());
        $normalized = normalize_source_db($source_db);
        if ($source_db !== $normalized) {
            save_data($sources_file, $normalized);
        }
    }

    if (!is_file($pool_file)) {
        save_data($pool_file, create_demo_pool());
    }
}

function normalize_source_db($db) {
    $defaults = create_default_source_catalog();
    $normalized = $defaults;

    foreach (['2ch_boards', 'vk_publics', 'telegram_channels'] as $key) {
        $normalized[$key] = is_array($db[$key] ?? null) ? array_values($db[$key]) : $defaults[$key];
    }

    $votes = is_array($db['source_votes'] ?? null) ? $db['source_votes'] : [];
    $normalized['source_votes'] = array_values(array_filter($votes, function($vote) {
        return is_array($vote) && !empty($vote['source_id'] ?? '');
    }));

    return $normalized;
}

function source_vote_exists($db, $source_id) {
    foreach (($db['source_votes'] ?? []) as $vote) {
        if (($vote['source_id'] ?? null) === $source_id) {
            return true;
        }
    }
    return false;
}

function fetch_2ch_json($board, $thread_id = null) {
    if (!preg_match('/^[a-z0-9_-]{1,30}$/i', (string)$board)) return [];
    if ($thread_id !== null && !preg_match('/^[0-9]{1,20}$/', (string)$thread_id)) return [];
    $url = $thread_id === null
        ? 'https://2ch.hk/' . rawurlencode($board) . '/catalog.json'
        : 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'MemeTinder/1.0'
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status < 200 || $status >= 300) return [];
    $data = json_decode($response, true);
    return is_array($data) ? $data : [];
}

function build_source_preview_posts($source, $count = 4) {
    if (($source['platform'] ?? '') === 'Telegram') return build_telegram_preview_posts($source, $count);
    if (($source['platform'] ?? '') === 'ВКонтакте') return build_vk_preview_posts($source, $count);
    if (($source['platform'] ?? '') !== 'Двач') return [];
    $board = $source['board'] ?? '';
    $thread_id = $source['thread_id'] ?? '';
    $thread_data = fetch_2ch_json($board, $thread_id);
    $thread = $thread_data['threads'][0] ?? null;
    if (!is_array($thread) || empty($thread['posts']) || !is_array($thread['posts'])) return [];

    $posts = [];
    foreach ($thread['posts'] as $post) {
        if (count($posts) >= $count) break;
        $images = [];
        foreach (($post['files'] ?? []) as $file) {
            $path = $file['path'] ?? '';
            $pattern = '#^/' . preg_quote($board, '#') . '/src/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';
            if (preg_match($pattern, $path)) $images[] = 'https://2ch.hk' . $path;
            if (count($images) >= 4) break;
        }
        $raw_text = $post['comment'] ?? $post['subject'] ?? '';
        $text = html_entity_decode(strip_tags((string)$raw_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (!$images) continue;
        $posts[] = [
            'image' => $images[0],
            'images' => $images,
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'],
            'post_id' => $post['num'] ?? ''
        ];
    }
    return $posts;
}

function meme_parser_activity() {
    $key = substr(hash('sha256', __DIR__), 0, 16);
    $log_file = __DIR__ . '/.meme-tinder-' . $key . '.log';
    $lines = @file($log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];

    $activity = [];
    foreach (array_slice($lines, -32) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || str_starts_with($line, 'PHP Warning:')) continue;
        if (function_exists('mb_strimwidth')) {
            $line = mb_strimwidth($line, 0, 500, '...', 'UTF-8');
        } elseif (strlen($line) > 500) {
            $line = substr($line, 0, 497) . '...';
        }
        $activity[] = $line;
    }
    return array_slice($activity, -24);
}

function meme_parser_process_running($pid) {
    if ($pid < 1) return false;
    $command_line = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($command_line !== false) {
        return strpos(str_replace("\0", ' ', $command_line), __DIR__ . '/cron_parser.php') !== false;
    }
    return function_exists('posix_kill') && @posix_kill($pid, 0);
}

function start_meme_parser_if_needed($force = false) {
    if (!function_exists('exec')) return 'unavailable';
    $key = substr(hash('sha256', __DIR__), 0, 16);
    $state_file = __DIR__ . '/.meme-tinder-' . $key . '.json';
    $lock_handle = @fopen($state_file . '.lock', 'c+');
    if (!$lock_handle || !flock($lock_handle, LOCK_EX)) return 'unavailable';

    $state = json_decode(@file_get_contents($state_file), true) ?: [];
    $pid = (int)($state['pid'] ?? 0);
    if (meme_parser_process_running($pid)) {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        return 'running';
    }
    if (!$force && !empty($state['started_at']) && time() - (int)$state['started_at'] < 600) {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        return 'cooldown';
    }

    $log_file = __DIR__ . '/.meme-tinder-' . $key . '.log';
    $php_binary = PHP_BINARY;
    if ($php_binary === '' || !is_executable($php_binary)) $php_binary = '/usr/bin/php';
    if (!is_executable($php_binary)) {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        return 'unavailable';
    }
    $command = 'nohup ' . escapeshellarg($php_binary) . ' ' . escapeshellarg(__DIR__ . '/cron_parser.php') .
        ' > ' . escapeshellarg($log_file) . ' 2>&1 < /dev/null & echo $!';
    exec($command, $output, $exit_code);
    $pid = (int)trim($output[0] ?? '0');
    if ($exit_code !== 0 || $pid < 1) {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
        return 'unavailable';
    }

    file_put_contents($state_file, json_encode(['pid' => $pid, 'started_at' => time()]), LOCK_EX);
    flock($lock_handle, LOCK_UN);
    fclose($lock_handle);
    return 'started';
}

function ai_generate_local($model, $prompt, $images = [], $format = null) {
    $payload = ['model' => $model, 'prompt' => $prompt, 'stream' => false];
    if ($images) $payload['images'] = $images;
    if ($format) $payload['format'] = $format;

    $ch = curl_init('http://localhost:11434/api/generate');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 180
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        $api_error = is_string($response) ? (json_decode($response, true)['error'] ?? '') : '';
        throw new RuntimeException($api_error ?: ($error ?: 'Ошибка локальной Ollama (HTTP ' . $status . ').'));
    }
    $result = json_decode($response, true);
    if (!is_array($result) || !isset($result['response'])) {
        throw new RuntimeException('Модель вернула некорректный ответ.');
    }
    return trim($result['response']);
}

function validate_image_bytes($image_data) {
    if (!is_string($image_data) || strlen($image_data) > 10 * 1024 * 1024) {
        throw new RuntimeException('Изображение должно быть не больше 10 МБ.');
    }
    if (!function_exists('getimagesizefromstring')) {
        throw new RuntimeException('На сервере недоступна проверка изображений (PHP GD).');
    }
    $image = @getimagesizefromstring($image_data);
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    if (!$image || !in_array($image['mime'] ?? '', $allowed_types, true)) {
        throw new RuntimeException('Поддерживаются только JPEG, PNG, GIF, WebP и BMP.');
    }
    if (($image[0] * $image[1]) > 40000000) {
        throw new RuntimeException('Слишком большое разрешение изображения.');
    }
}

function fetch_public_image($url) {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Укажите прямую публичную ссылку на изображение.');
    }
    $host = rtrim(strtolower($parts['host']), '.');
    $scheme = strtolower($parts['scheme']);
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true) || ($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
        throw new RuntimeException('Ссылка должна использовать стандартный порт HTTP или HTTPS.');
    }

    $resolve = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || strpos($host, ':') !== false) {
            throw new RuntimeException('Ссылка должна вести на публичный IPv4-адрес или домен.');
        }
    } else {
        $addresses = gethostbynamel($host);
        if (!$addresses) throw new RuntimeException('Не удалось найти адрес сайта из ссылки.');
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Ссылка ведёт на непубличный адрес.');
            }
        }
        $resolve[] = $host . ':' . $port . ':' . $addresses[0];
    }

    $image_data = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'MemeTinder/1.0',
        CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$image_data) {
            if (strlen($image_data) + strlen($chunk) > 10 * 1024 * 1024) return 0;
            $image_data .= $chunk;
            return strlen($chunk);
        }
    ]);
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
    $success = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = strtolower(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $error = curl_error($ch);
    curl_close($ch);

    if ($success === false || $status < 200 || $status >= 300 || strpos($content_type, 'image/') !== 0) {
        throw new RuntimeException($error ? 'Не удалось скачать изображение: ' . $error : 'Ссылка должна вести прямо на файл изображения, без страницы или редиректа.');
    }
    return $image_data;
}

function read_image_text_local($image_data, &$best_regions = null, $clean_text = true, &$ocr_meta = null) {
    $best_regions = [];
    $ocr_meta = ['status' => 'unavailable', 'text' => '', 'regions' => [], 'rejected' => []];
    if (!function_exists('exec')) return '';
    $input_file = tempnam(sys_get_temp_dir(), 'meme_test_');
    if ($input_file === false) return '';
    file_put_contents($input_file, $image_data);
    $ocr_files = [$input_file];
    $ocr_scales = [1.0];
    $prepared_file = null;
    $image_info = @getimagesizefromstring($image_data);
    if ($image_info && max($image_info[0], $image_info[1]) <= 1800 && function_exists('imagecreatefromstring')) {
        $source = @imagecreatefromstring($image_data);
        if ($source) {
            $scale = min(2, 1800 / max($image_info[0], $image_info[1]));
            if ($scale > 1.05) {
                $width = (int)round($image_info[0] * $scale);
                $height = (int)round($image_info[1] * $scale);
                $prepared = imagecreatetruecolor($width, $height);
                imagecopyresampled($prepared, $source, 0, 0, 0, 0, $width, $height, $image_info[0], $image_info[1]);
                imagefilter($prepared, IMG_FILTER_GRAYSCALE);
                imagefilter($prepared, IMG_FILTER_CONTRAST, -15);
                $prepared_file = tempnam(sys_get_temp_dir(), 'meme_ocr_');
                if ($prepared_file !== false && imagepng($prepared, $prepared_file)) {
                    $ocr_files[] = $prepared_file;
                    $ocr_scales[] = $scale;
                } else {
                    $prepared_file = null;
                }
                imagedestroy($prepared);
            }
            imagedestroy($source);
        }
    }

    $candidates = [];
    foreach ($ocr_files as $ocr_index => $ocr_file) {
        $coordinate_scale = $ocr_scales[$ocr_index] ?? 1.0;
        foreach ([6, 11] as $page_mode) {
            $lines = [];
            exec('tesseract ' . escapeshellarg($ocr_file) . ' stdout -l rus+eng --psm ' . $page_mode . ' tsv 2>/dev/null', $lines, $status);
            $candidate = $status === 0
                ? select_tesseract_tsv_candidate($lines, $coordinate_scale)
                : select_tesseract_tsv_candidate([]);
            $candidate['psm'] = $page_mode;
            $candidate['scale'] = $coordinate_scale;
            $candidates[] = $candidate;
        }
    }

    @unlink($input_file);
    if ($prepared_file) @unlink($prepared_file);
    $best = select_consensus_tesseract_candidate($candidates);
    $best_regions = $best['regions'];
    $ocr_meta = [
        'status' => $best['word_count']
            ? 'text-found'
            : (!empty($best['raw_word_count']) && empty($best['consensus_confirmed']) ? 'unconfirmed' : 'no-text'),
        'consensus_confirmed' => $best['consensus_confirmed'] ?? false,
        'image_width' => (int)($image_info[0] ?? 0),
        'image_height' => (int)($image_info[1] ?? 0),
        'psm' => $best['psm'] ?? null,
        'scale' => $best['scale'] ?? 1,
        'raw_word_count' => $best['raw_word_count'],
        'word_count' => $best['word_count'],
        'line_count' => $best['line_count'],
        'confidence' => $best['confidence'],
        'text' => $best['text'],
        'regions' => $best_regions,
        'rejected' => $best['rejected']
    ];
    if (ocr_text_length($best['text']) < 3) return '';
    if (!$clean_text) return $best['text'];
    $cleaned = ai_generate_local('qwen2.5:3b', "Исправь очевидные ошибки OCR в короткой надписи. Сохрани язык и знаки препинания, не заменяй кириллицу латиницей. Не переводи и не добавляй слова. Удали только явные повторы; если фрагмент неразборчив, оставь как есть. Верни одну строку с исправленным текстом:\n" . $best['text']);
    $ocr_meta['cleaned_text'] = $cleaned !== '' ? $cleaned : $best['text'];
    return $ocr_meta['cleaned_text'];
}

function blur_ocr_regions_for_vision_local($image_data, $regions) {
    if (!$regions || !function_exists('imagecreatefromstring') || !function_exists('imagefilter')) return $image_data;
    $image = @imagecreatefromstring($image_data);
    if (!$image) return $image_data;
    $image_width = imagesx($image);
    $image_height = imagesy($image);
    $padding = max(4, min(12, (int)(min($image_width, $image_height) * 0.01)));
    $mask_color = imagecolorallocate($image, 8, 8, 8);
    $outline_color = imagecolorallocate($image, 255, 145, 40);

    foreach ($regions as $region) {
        $left = max(0, $region['left'] - $padding);
        $top = max(0, $region['top'] - $padding);
        $right = min($image_width, $region['left'] + $region['width'] + $padding);
        $bottom = min($image_height, $region['top'] + $region['height'] + $padding);
        $width = $right - $left;
        $height = $bottom - $top;
        if ($width < 1 || $height < 1) continue;

        imagefilledrectangle($image, $left, $top, $right - 1, $bottom - 1, $mask_color);
        imagerectangle($image, $left, $top, $right - 1, $bottom - 1, $outline_color);
    }

    ob_start();
    imagepng($image);
    $blurred_image = ob_get_clean();
    imagedestroy($image);
    return $blurred_image !== false && $blurred_image !== '' ? $blurred_image : $image_data;
}

function analyze_image_local($image_data, $history) {
    validate_image_bytes($image_data);
    $ocr_regions = [];
    $ocr_meta = [];
    $ocr = read_image_text_local($image_data, $ocr_regions, true, $ocr_meta);
    $vision_image = blur_ocr_regions_for_vision_local($image_data, $ocr_regions);
    $image_info = @getimagesizefromstring($vision_image);
    $region_list = array_map(function($region) {
        return ['left' => $region['left'], 'top' => $region['top'], 'width' => $region['width'], 'height' => $region['height']];
    }, $ocr_regions);
    $vision_prompt = "Tesseract found text in the image. Its detected regions are covered by solid dark rectangles with orange outlines. " .
        "The image is {$image_info[0]}x{$image_info[1]} pixels. Detected text boxes (left, top, width, height): " .
        json_encode($region_list) . ". Do not read, transcribe, infer, reconstruct, or guess any text, including text inside or outside the marked regions. " .
        "Ignore the marked rectangles completely. Describe only the non-text scene in 3 to 5 complete sentences, around 50 to 80 words. Include concrete objects, expressions, actions, spatial relationships, and relevant visual context. Do not infer hidden text or invent details.";
    $description = ai_generate_local('moondream', $vision_prompt, [base64_encode($vision_image)]);
    if ($description === '') throw new RuntimeException('Модель не смогла описать изображение.');

    $liked = array_slice(array_column($history['liked_memes'] ?? [], 'text'), -15);
    $disliked = array_slice(array_column($history['disliked_memes'] ?? [], 'text'), -15);
    $prompt = "Оцени мем и верни только JSON: {\"meme_probability\":0,\"rating\":0,\"description\":\"подробное описание сцены в 2-4 предложениях\",\"why_funny\":\"короткое объяснение\",\"is_funny\":false}.\n" .
        "meme_probability и rating: числа 0-100. rating учитывает вкусы пользователя. description: 2-4 полных предложения, примерно 40-70 слов; конкретно опиши видимую сцену, действия, выражения и связь деталей с шуткой. Не повторяй OCR дословно, не додумывай скрытый текст и не добавляй невидимые детали. why_funny объясняет суть шутки кратко; если ее нет, скажи почему. is_funny: true только если действительно смешно.\n" .
        'OCR (возможны ошибки): ' . $ocr . "\nОписание сцены: " . $description . "\nЛАЙКИ: " .
        json_encode($liked, JSON_UNESCAPED_UNICODE) . "\nДИЗЛАЙКИ: " . json_encode($disliked, JSON_UNESCAPED_UNICODE);
    $assessment = json_decode(ai_generate_local('qwen2.5:3b', $prompt, [], 'json'), true);
    if (!is_array($assessment) || !is_numeric($assessment['rating'] ?? null) || !is_numeric($assessment['meme_probability'] ?? null)) {
        throw new RuntimeException('Модель вернула некорректный JSON-анализ.');
    }
    $is_funny = $assessment['is_funny'] ?? false;
    if (!is_bool($is_funny)) $is_funny = in_array(strtolower((string)$is_funny), ['true', 'yes', 'да', '1'], true);
    $vision_mime = $image_info['mime'] ?? 'image/png';

    return [
        'rating' => max(0, min(100, (int)$assessment['rating'])),
        'meme_probability' => max(0, min(100, (int)$assessment['meme_probability'])),
        'description' => trim((string)($assessment['description'] ?? $description)),
        'why_funny' => trim((string)($assessment['why_funny'] ?? '')),
        'is_funny' => $is_funny,
        'ocr' => $ocr,
        'ocr_diagnostics' => $ocr_meta,
        'vision_preview' => 'data:' . $vision_mime . ';base64,' . base64_encode($vision_image),
        'model' => 'moondream + qwen2.5:3b'
    ];
}

function source_post_url($meme) {
    $existing_url = parse_url($meme['source_url'] ?? '');
    if (($existing_url['scheme'] ?? '') === 'https' && in_array(strtolower($existing_url['host'] ?? ''), ['2ch.hk', 't.me', 'vk.com'], true)) {
        return $meme['source_url'];
    }

    $board = (string)($meme['board'] ?? '');
    if ($board === '' && preg_match('~Двач\s+/([a-z0-9_-]+)/~iu', (string)($meme['source'] ?? ''), $match)) {
        $board = $match[1];
    }
    $thread_id = (string)($meme['thread_id'] ?? '');
    $post_id = (string)($meme['post_id'] ?? '');
    if (!preg_match('/^[a-z0-9_-]{1,30}$/i', $board) || !preg_match('/^[0-9]{1,20}$/', $thread_id) || !preg_match('/^[0-9]{1,20}$/', $post_id)) {
        return '';
    }
    return 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.html#' . rawurlencode($post_id);
}

function database_editor_definitions() {
    return [
        'memes_pool' => [
            'label' => 'Пул мемов',
            'file' => __DIR__ . '/memes_pool.json',
            'collections' => ['items' => 'Мемы']
        ],
        'meme_history' => [
            'label' => 'История оценок',
            'file' => __DIR__ . '/meme_history.json',
            'collections' => [
                'liked_memes' => 'Лайки',
                'disliked_memes' => 'Дизлайки',
                'undecided_memes' => 'Без решения',
                'wrong_topic_memes' => 'Не по теме'
            ]
        ],
        'sources_db' => [
            'label' => 'Каталог источников',
            'file' => __DIR__ . '/sources_db.json',
            'collections' => [
                '2ch_boards' => 'Доски 2ch',
                'vk_publics' => 'Паблики ВКонтакте',
                'telegram_channels' => 'Каналы Telegram',
                'source_votes' => 'Оценки источников'
            ]
        ]
    ];
}

function database_editor_load($database) {
    $definitions = database_editor_definitions();
    if (!isset($definitions[$database])) throw new InvalidArgumentException('Неизвестная база данных.');
    $raw = file_get_contents($definitions[$database]['file']);
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('Файл базы содержит некорректный JSON.');
    }
    return [$definitions[$database], $data];
}

function database_editor_collection($database, $definition, $data, $collection) {
    if (!array_key_exists($collection, $definition['collections'])) {
        throw new InvalidArgumentException('Неизвестная коллекция.');
    }
    $items = $database === 'memes_pool' ? $data : ($data[$collection] ?? []);
    if (!is_array($items)) throw new RuntimeException('Коллекция должна быть массивом записей.');
    return array_values($items);
}

function database_editor_save($database, $definition, $data, $collection, $items) {
    if ($database === 'memes_pool') {
        $data = array_values($items);
    } else {
        $data[$collection] = array_values($items);
    }

    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Не удалось сериализовать базу в JSON.');
    $file = $definition['file'];
    $temporary_file = tempnam(dirname($file), '.meme-db-');
    if ($temporary_file === false) throw new RuntimeException('Нет прав на запись в каталог баз данных.');

    $written = file_put_contents($temporary_file, $json, LOCK_EX);
    $mode = is_file($file) ? (fileperms($file) & 0777) : 0664;
    @chmod($temporary_file, $mode);
    if ($written === false || !rename($temporary_file, $file)) {
        @unlink($temporary_file);
        throw new RuntimeException('Не удалось сохранить базу данных. Проверьте права каталога.');
    }
}

function database_editor_validate_record($database, $collection, $record) {
    if ($database === 'memes_pool') {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['image'] ?? '')) === '') {
            throw new InvalidArgumentException('Мему нужны поля-объект и непустой image.');
        }
        if (isset($record['rating']) && (!is_numeric($record['rating']) || $record['rating'] < 0 || $record['rating'] > 100)) {
            throw new InvalidArgumentException('rating должен быть числом от 0 до 100.');
        }
        return;
    }

    if (in_array($collection, ['liked_memes', 'disliked_memes', 'undecided_memes', 'wrong_topic_memes'], true)) {
        if (!is_array($record) || array_is_list($record) || !is_string($record['text'] ?? null) || !is_numeric($record['rating_assigned'] ?? null)) {
            throw new InvalidArgumentException('Для записи истории нужны объект, строка text и число rating_assigned.');
        }
        return;
    }

    if ($database === 'sources_db' && $collection === '2ch_boards') {
        if (!is_string($record) || !preg_match('/^[a-z0-9_-]{1,30}$/i', $record)) {
            throw new InvalidArgumentException('Доска 2ch должна содержать только буквы, цифры, _ или - (до 30 символов).');
        }
        return;
    }

    if ($database === 'sources_db' && in_array($collection, ['vk_publics', 'telegram_channels'], true)) {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['id'] ?? '')) === '' || trim((string)($record['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Источнику нужны непустые поля id и name.');
        }
        return;
    }

    if ($database === 'sources_db' && $collection === 'source_votes') {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['source_id'] ?? '')) === '' || !in_array($record['vote'] ?? '', ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Оценке источника нужны source_id и vote: approve или reject.');
        }
    }
}

$action = $_GET['action'] ?? ''; // Безопасное объявление $action
$debug_mode = ($_GET['debug'] ?? '') === '1';
ensure_default_project_data();

if ($debug_mode) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!isset($_SESSION['meme_tinder_debug'])) {
        $_SESSION['meme_tinder_debug'] = debug_initial_state();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'vk_oauth_status') {
    header('Content-Type: application/json');
    $config = vk_oauth_config();
    $tokens = load_vk_tokens();
    echo json_encode([
        'configured' => !$debug_mode && $config['client_id'] !== '' && $config['redirect_uri'] !== '',
        'connected' => !$debug_mode && !empty($tokens['refresh_token']),
        'user_id' => $debug_mode ? null : ($tokens['user_id'] ?? null)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'vk_oauth_start') {
    $config = vk_oauth_config();
    if ($debug_mode || $config['client_id'] === '' || $config['redirect_uri'] === '') {
        header('Location: index.html?vk=misconfigured#source-tab');
        exit;
    }
    if (session_status() === PHP_SESSION_NONE) session_start();
    $state = bin2hex(random_bytes(24));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $_SESSION['vk_oauth'] = [
        'state' => $state,
        'code_verifier' => $verifier,
        'started_at' => time()
    ];
    $parameters = [
        'response_type' => 'code',
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'lang_id' => '0'
    ];
    if ($config['scope'] !== '') $parameters['scope'] = $config['scope'];
    header('Location: https://id.vk.ru/authorize?' . http_build_query($parameters), true, 302);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'vk_oauth_callback') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $callback = $_GET;
    if (isset($callback['payload'])) {
        $payload = json_decode((string)$callback['payload'], true);
        if (is_array($payload)) $callback = array_merge($callback, $payload);
    }
    $oauth_state = $_SESSION['vk_oauth'] ?? [];
    unset($_SESSION['vk_oauth']);
    $state = (string)($callback['state'] ?? '');
    $code = (string)($callback['code'] ?? '');
    $device_id = (string)($callback['device_id'] ?? '');
    $config = vk_oauth_config();
    if ((int)($oauth_state['started_at'] ?? 0) < time() - 600 || empty($oauth_state['state']) || !hash_equals((string)$oauth_state['state'], $state)) {
        header('Location: index.html?vk=error#source-tab');
        exit;
    }
    if ($code === '' || $device_id === '' || $config['client_id'] === '' || $config['redirect_uri'] === '') {
        header('Location: index.html?vk=error#source-tab');
        exit;
    }
    try {
        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => (string)$oauth_state['code_verifier'],
            'client_id' => $config['client_id'],
            'device_id' => $device_id,
            'redirect_uri' => $config['redirect_uri'],
            'state' => $state
        ];
        if ($config['service_token'] !== '') $parameters['service_token'] = $config['service_token'];
        $tokens = vk_oauth_post('https://id.vk.ru/oauth2/auth', $parameters);
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new RuntimeException('VK ID не вернул access_token и refresh_token.');
        }
        $tokens['device_id'] = $device_id;
        $tokens['expires_at'] = time() + max(0, (int)($tokens['expires_in'] ?? 0));
        save_vk_tokens($tokens);
        session_regenerate_id(true);
        header('Location: index.html?vk=connected#source-tab');
    } catch (Throwable $error) {
        error_log('VK OAuth callback failed: ' . $error->getMessage());
        header('Location: index.html?vk=error#source-tab');
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'database_options') {
    header('Content-Type: application/json');
    if ($debug_mode) {
        http_response_code(403);
        echo json_encode(['message' => 'Редактирование баз отключено в демо-режиме.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $databases = [];
    foreach (database_editor_definitions() as $id => $definition) {
        $collections = [];
        foreach ($definition['collections'] as $collection_id => $label) {
            $collections[] = ['id' => $collection_id, 'label' => $label];
        }
        $databases[] = ['id' => $id, 'label' => $definition['label'], 'collections' => $collections];
    }
    echo json_encode(['databases' => $databases], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'database_read') {
    header('Content-Type: application/json');
    try {
        if ($debug_mode) throw new RuntimeException('Редактирование баз отключено в демо-режиме.');
        $database = $_GET['database'] ?? '';
        $collection = $_GET['collection'] ?? '';
        [$definition, $data] = database_editor_load($database);
        $items = database_editor_collection($database, $definition, $data, $collection);
        echo json_encode([
            'database_label' => $definition['label'],
            'collection_label' => $definition['collections'][$collection],
            'items' => $items
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $error) {
        http_response_code(400);
        echo json_encode(['message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_current_meme') {
    header('Content-Type: application/json');
    $pool = $debug_mode ? $_SESSION['meme_tinder_debug']['pool'] : load_data($pool_file, []);
    if (empty($pool)) {
        $parser_status = $debug_mode ? 'cooldown' : start_meme_parser_if_needed();
        if (in_array($parser_status, ['started', 'running'], true)) {
            echo json_encode(['status' => 'loading', 'message' => 'Ищем посты...', 'activity' => meme_parser_activity()], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['status' => 'empty', 'message' => 'Новых мемов пока нет. Можно повторить поиск или добавить запись вручную.'], JSON_UNESCAPED_UNICODE);
        }
    } else {
        $meme = $pool[0];
        $meme['images'] = [$meme['image']];
        $meme['captions'] = [$meme['captions'][0] ?? ($meme['text'] ?? '')];
        $meme['source_url'] = source_post_url($meme);
        echo json_encode($meme, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    if (in_array($action, ['database_add', 'database_update', 'database_delete'], true)) {
        try {
            if ($debug_mode) throw new RuntimeException('Редактирование баз отключено в демо-режиме.');
            if (!is_array($input)) throw new InvalidArgumentException('Некорректный запрос.');
            $database = $input['database'] ?? '';
            $collection = $input['collection'] ?? '';
            [$definition, $data] = database_editor_load($database);
            $items = database_editor_collection($database, $definition, $data, $collection);

            if ($action === 'database_add') {
                if (!array_key_exists('record', $input)) throw new InvalidArgumentException('Не переданы данные записи.');
                $record = $input['record'];
                database_editor_validate_record($database, $collection, $record);
                $items[] = $record;
            } else {
                $index = filter_var($input['index'] ?? null, FILTER_VALIDATE_INT);
                if ($index === false || $index < 0) throw new InvalidArgumentException('Некорректный номер записи.');
                if (!array_key_exists($index, $items)) throw new OutOfBoundsException('Запись уже удалена или не существует.');

                if ($action === 'database_update') {
                if (!array_key_exists('record', $input)) throw new InvalidArgumentException('Не переданы данные записи.');
                $existing = $items[$index];
                $record = $input['record'];
                if (is_array($existing) !== is_array($record)) {
                    throw new InvalidArgumentException('Нельзя менять тип записи.');
                }
                if (is_array($existing) && array_is_list($existing) !== array_is_list($record)) {
                    throw new InvalidArgumentException('Тип структуры записи не совпадает.');
                }
                if (!is_array($existing) && gettype($existing) !== gettype($record)) {
                    throw new InvalidArgumentException('Нельзя менять тип значения записи.');
                }
                database_editor_validate_record($database, $collection, $record);
                $items[$index] = $record;
                } else {
                    array_splice($items, $index, 1);
                }
            }

            database_editor_save($database, $definition, $data, $collection, $items);
            echo json_encode(['status' => 'success', 'count' => count($items)]);
        } catch (Throwable $error) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'prepare_vision_preview') {
        try {
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['image_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Не удалось загрузить изображение для OCR.');
                if ($file['size'] > 10 * 1024 * 1024) throw new RuntimeException('Изображение должно быть не больше 10 МБ.');
                $image_data = file_get_contents($file['tmp_name']);
            } else {
                $image_url = trim($_POST['image_url'] ?? '');
                if ($image_url === '') throw new RuntimeException('Не передана ссылка на изображение.');
                $image_data = fetch_public_image($image_url);
            }
            validate_image_bytes($image_data);
            $ocr_regions = [];
            $ocr_meta = [];
            read_image_text_local($image_data, $ocr_regions, false, $ocr_meta);
            $vision_image = blur_ocr_regions_for_vision_local($image_data, $ocr_regions);
            $image_info = @getimagesizefromstring($vision_image);
            $mime = $image_info['mime'] ?? 'image/png';
            echo json_encode([
                'vision_preview' => 'data:' . $mime . ';base64,' . base64_encode($vision_image),
                'ocr_regions_count' => count($ocr_regions),
                'ocr_diagnostics' => $ocr_meta
            ], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            http_response_code(400);
            echo json_encode(['message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'analyze_image') {
        try {
            if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                $file = $_FILES['image_file'];
                if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Не удалось загрузить файл.');
                if ($file['size'] > 10 * 1024 * 1024) throw new RuntimeException('Изображение должно быть не больше 10 МБ.');
                $image_data = file_get_contents($file['tmp_name']);
            } else {
                $image_url = trim($_POST['image_url'] ?? ($input['image_url'] ?? ''));
                if ($image_url === '') throw new RuntimeException('Добавьте ссылку или выберите файл изображения.');
                $image_data = fetch_public_image($image_url);
            }

            $history = $debug_mode
                ? $_SESSION['meme_tinder_debug']['history']
                : load_data($history_file, ['liked_memes' => [], 'disliked_memes' => []]);
            echo json_encode(analyze_image_local($image_data, $history), JSON_UNESCAPED_UNICODE);
        } catch (Throwable $error) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($debug_mode && $action === 'debug_reset') {
        $_SESSION['meme_tinder_debug'] = debug_initial_state();
        echo json_encode(['status' => 'success', 'debug' => true]);
        exit;
    }
    
    if ($action === 'meme_vote') {
        $vote = $input['vote'] ?? '';
        $meme = $input['meme'] ?? null;
        $map = ['like' => 'liked_memes', 'dislike' => 'disliked_memes', 'maybe' => 'undecided_memes', 'wrong' => 'wrong_topic_memes'];
        $has_media = !empty($meme['image']) || (!empty($meme['images']) && is_array($meme['images']));
        if (!isset($map[$vote]) || !is_array($meme) || !$has_media) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Некорректный голос или мем']);
            exit;
        }

        $first_image = !empty($meme['image']) ? $meme['image'] : (is_array($meme['images'] ?? null) ? ($meme['images'][0] ?? '') : '');

        if ($debug_mode) {
            $state = &$_SESSION['meme_tinder_debug'];
            $state['history'][$map[$vote]][] = ['text' => $meme['text'] ?? '', 'rating_assigned' => $meme['rating'] ?? 0, 'timestamp' => time()];
            $state['pool'] = array_values(array_filter($state['pool'], function($item) use ($first_image) { return ($item['image'] ?? '') !== $first_image; }));
            echo json_encode(['status' => 'success', 'debug' => true]);
            exit;
        }

        $history = load_data($history_file, ['liked_memes' => [], 'disliked_memes' => [], 'undecided_memes' => [], 'wrong_topic_memes' => []]);
        $history_entry = [
            'image' => $first_image,
            'text' => $meme['text'],
            'ai_short_description' => $meme['ai_description'] ?? 'Без описания ИИ',
            'why_funny' => $meme['why_funny'] ?? '',
            'is_funny' => $meme['is_funny'] ?? null,
            'meme_probability' => $meme['meme_probability'] ?? null,
            'rating_assigned' => $meme['rating'],
            'source' => $meme['source'],
            'board' => $meme['board'] ?? '',
            'thread_id' => $meme['thread_id'] ?? '',
            'post_id' => $meme['post_id'] ?? '',
            'source_url' => source_post_url($meme),
            'timestamp' => time()
        ];
        
        $history[$map[$vote]][] = $history_entry;
        save_data($history_file, $history);
        
        $pool = load_data($pool_file, []);
        $pool = array_filter($pool, function($m) use ($meme) { return $m['image'] !== $meme['image']; });
        save_data($pool_file, array_values($pool));
        
        echo json_encode(['status' => 'success']);
        exit;
    }
    
    if ($action === 'suggest_sources') {
        $database = normalize_source_db(load_data($sources_file, create_default_source_catalog()));
        $candidates = [];
        $platform_filter = strtolower(trim((string)($input['platform'] ?? 'any')));
        $platform_filters = ['any' => 'any', 'telegram' => 'Telegram', 'vk' => 'ВКонтакте', 'dvach' => 'Двач'];
        if (!isset($platform_filters[$platform_filter])) {
            http_response_code(400);
            echo json_encode(['message' => 'Неизвестная платформа источника.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $include_dvach = in_array($platform_filter, ['any', 'dvach'], true);
        $include_telegram = in_array($platform_filter, ['any', 'telegram'], true);
        $include_vk = in_array($platform_filter, ['any', 'vk'], true);

        $boards = $database['2ch_boards'] ?? [];
        shuffle($boards);
        if ($include_dvach) foreach (array_slice($boards, 0, 4) as $board) {
            $catalog = fetch_2ch_json($board);
            foreach (($catalog['threads'] ?? []) as $thread) {
                $thread_id = (string)($thread['num'] ?? '');
                if (!preg_match('/^[0-9]{1,20}$/', $thread_id)) continue;
                $source_id = 'dvach:' . $board . ':' . $thread_id;
                if (source_vote_exists($database, $source_id)) continue;
                $subject = html_entity_decode(strip_tags((string)($thread['subject'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? '');
                $candidates[] = [
                    'source_id' => $source_id,
                    'name' => 'Двач /' . $board . '/ · тред #' . $thread_id,
                    'board' => $board,
                    'thread_id' => $thread_id,
                    'platform' => 'Двач',
                    'reason' => $subject ?: 'Тред с постами и изображениями.'
                ];
                if (count($candidates) >= 30) break 2;
            }
        }

        if ($include_telegram) foreach (($database['telegram_channels'] ?? []) as $channel) {
            $channel_id = ltrim(trim((string)($channel['id'] ?? '')), '@');
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $channel_id)) continue;
            $source_id = 'telegram:' . strtolower($channel_id);
            if (source_vote_exists($database, $source_id)) continue;
            $candidates[] = [
                'source_id' => $source_id,
                'id' => $channel_id,
                'name' => 'Telegram | ' . ($channel['name'] ?? $channel_id),
                'platform' => 'Telegram',
                'reason' => 'Публичный Telegram-канал с недавними постами.'
            ];
        }

        $vk_connected = false;
        if ($include_vk) {
            try {
                $vk_connected = vk_access_token() !== '';
            } catch (Throwable $error) {
                error_log('VK token refresh failed during source discovery: ' . $error->getMessage());
            }
        }
        if ($vk_connected) {
            foreach (($database['vk_publics'] ?? []) as $public) {
                $public_id = trim((string)($public['id'] ?? ''));
                if (!preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $public_id)) continue;
                $source_id = 'vk:' . strtolower($public_id);
                if (source_vote_exists($database, $source_id)) continue;
                $candidates[] = [
                    'source_id' => $source_id,
                    'id' => $public_id,
                    'name' => 'ВКонтакте | ' . ($public['name'] ?? $public_id),
                    'platform' => 'ВКонтакте',
                    'reason' => 'Публичное сообщество ВКонтакте с изображениями в постах.'
                ];
            }
        }

        if (empty($candidates)) {
            $message = $platform_filter === 'vk' && !$vk_connected
                ? 'Сначала подключите VK OAuth, чтобы искать паблики.'
                : 'Новых неоценённых источников на этой платформе пока нет.';
            echo json_encode(['discovered' => null, 'message' => $message], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($platform_filter === 'any') {
            $platform_candidates = [];
            foreach ($candidates as $candidate) {
                $platform_candidates[$candidate['platform']][] = $candidate;
            }
            $platform_order = array_keys($platform_candidates);
            shuffle($platform_order);
            $platform_vote_counts = [];
            foreach (($database['source_votes'] ?? []) as $vote) {
                $voted_platform = $vote['platform'] ?? '';
                $platform_vote_counts[$voted_platform] = ($platform_vote_counts[$voted_platform] ?? 0) + 1;
            }
            usort($platform_order, function($first, $second) use ($platform_vote_counts) {
                return ($platform_vote_counts[$first] ?? 0) <=> ($platform_vote_counts[$second] ?? 0);
            });
            $ordered_candidates = [];
            foreach ($platform_order as $platform) {
                shuffle($platform_candidates[$platform]);
                $ordered_candidates = array_merge($ordered_candidates, $platform_candidates[$platform]);
            }
            $candidates = $ordered_candidates;
        } else {
            shuffle($candidates);
        }
        $discovered = null;
        foreach (array_slice($candidates, 0, 12) as $candidate) {
            try {
                $candidate['posts'] = build_source_preview_posts($candidate, 4);
            } catch (Throwable $error) {
                error_log('Source preview failed (' . ($candidate['platform'] ?? 'unknown') . '): ' . $error->getMessage());
                continue;
            }
            if ($candidate['posts']) {
                $discovered = $candidate;
                break;
            }
        }
        if (!$discovered) {
            http_response_code(502);
            echo json_encode(['discovered' => null, 'message' => 'Не удалось получить посты с изображениями ни из одного кандидата. Для VK подключите аккаунт, Telegram-каналы должны быть публичными.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode(['discovered' => $discovered], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'source_vote') {
        $vote = $input['vote'] ?? '';
        $source = $input['source'] ?? null;
        if (!in_array($vote, ['approve', 'reject'], true) || !is_array($source)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Некорректное решение']);
            exit;
        }

        $db = normalize_source_db(load_data($sources_file, create_default_source_catalog()));

        $source_id = $source['source_id'] ?? ($source['id'] ?? ($source['board'] ?? $source['name']));
        $vote_item = [
            'source_id' => (string)$source_id,
            'platform' => $source['platform'] ?? 'Неизвестно',
            'name' => $source['name'] ?? 'Источник',
            'vote' => $vote,
            'timestamp' => time()
        ];
        if (isset($source['thread_id'])) $vote_item['thread_id'] = (string)$source['thread_id'];
        if (isset($source['board'])) $vote_item['board'] = (string)$source['board'];

        if (!source_vote_exists($db, (string)$source_id)) {
            $db['source_votes'][] = $vote_item;
        }

        if ($vote === 'approve') {
            if (($source['platform'] ?? '') === 'Двач' && isset($source['board'])) {
                if (!in_array($source['board'], $db['2ch_boards'], true)) {
                    $db['2ch_boards'][] = $source['board'];
                }
            } elseif (($source['platform'] ?? '') === 'ВКонтакте' && isset($source['id'])) {
                $exists = false;
                foreach (($db['vk_publics'] ?? []) as $pub) {
                    if (($pub['id'] ?? null) === $source['id']) { $exists = true; break; }
                }
                if (!$exists) {
                    $db['vk_publics'][] = ['id' => $source['id'], 'name' => str_replace('ВКонтакте | ', '', $source['name'])];
                }
            } elseif (($source['platform'] ?? '') === 'Telegram' && isset($source['id'])) {
                $exists = false;
                foreach (($db['telegram_channels'] ?? []) as $chan) {
                    if (($chan['id'] ?? null) === $source['id']) { $exists = true; break; }
                }
                if (!$exists) {
                    $db['telegram_channels'][] = ['id' => $source['id'], 'name' => str_replace('Telegram | ', '', $source['name'])];
                }
            }
        }

        save_data($sources_file, $db);
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'refresh_memes') {
        if ($debug_mode) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Поиск мемов отключён в демо-режиме.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $parser_status = start_meme_parser_if_needed(true);
        echo json_encode([
            'status' => in_array($parser_status, ['started', 'running'], true) ? 'loading' : 'error',
            'message' => $parser_status === 'unavailable' ? 'Не удалось запустить поиск мемов на сервере.' : 'Ищем посты...'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}