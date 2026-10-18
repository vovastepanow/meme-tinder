<?php

function clean_html_text($raw) {
    $text = html_entity_decode(strip_tags((string)$raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function is_valid_dvach_board($board) {
    return preg_match('/^[a-z0-9_-]{1,30}$/i', (string)$board) === 1;
}

function is_valid_dvach_id($id) {
    return preg_match('/^[0-9]{1,20}$/', (string)$id) === 1;
}

function is_valid_telegram_channel($channel) {
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', (string)$channel) === 1;
}

function is_valid_vk_domain($domain) {
    return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string)$domain) === 1;
}

function dvach_post_url($board, $thread_id, $post_id) {
    return 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.html#' . rawurlencode($post_id);
}

const DVACH_PREVIEW_HTTP_OPTIONS = [
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_MAXREDIRS => 2,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
];

/** Catalog JSON when $thread_id is null, otherwise the thread JSON. */
function fetch_2ch_json($board, $thread_id = null, array $http_options = DVACH_PREVIEW_HTTP_OPTIONS) {
    if (!is_valid_dvach_board($board)) return [];
    if ($thread_id !== null && !is_valid_dvach_id($thread_id)) return [];
    $url = $thread_id === null
        ? 'https://2ch.hk/' . rawurlencode($board) . '/catalog.json'
        : 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.json';
    return http_get_json($url, $http_options);
}

function dvach_thread_posts($thread_data) {
    $thread = $thread_data['threads'][0] ?? null;
    if (!is_array($thread) || empty($thread['posts']) || !is_array($thread['posts'])) return [];
    return $thread['posts'];
}

function dvach_post_images($board, $post, $limit = PHP_INT_MAX) {
    $pattern = '#^/' . preg_quote($board, '#') . '/src/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';
    $images = [];
    foreach (($post['files'] ?? []) as $file) {
        $path = $file['path'] ?? '';
        if (preg_match($pattern, $path)) $images[] = 'https://2ch.hk' . $path;
        if (count($images) >= $limit) break;
    }
    return $images;
}

function dvach_post_text($post) {
    return clean_html_text($post['comment'] ?? $post['subject'] ?? '');
}

function build_dvach_preview_posts($source, $count = 4) {
    $board = $source['board'] ?? '';
    $posts = [];
    foreach (dvach_thread_posts(fetch_2ch_json($board, $source['thread_id'] ?? '')) as $post) {
        if (count($posts) >= $count) break;
        $images = dvach_post_images($board, $post, 4);
        if (!$images) continue;
        $text = dvach_post_text($post);
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

function build_source_preview_posts($source, $count = 4) {
    $platform = $source['platform'] ?? '';
    if ($platform === 'Telegram') return build_telegram_preview_posts($source, $count);
    if ($platform === 'ВКонтакте') return build_vk_preview_posts($source, $count);
    if ($platform === 'Двач') return build_dvach_preview_posts($source, $count);
    return [];
}


function vk_oauth_config() {
    return [
        'client_id' => trim((string)getenv('VK_CLIENT_ID')),
        'redirect_uri' => trim((string)getenv('VK_REDIRECT_URI')),
        'service_token' => trim((string)getenv('VK_SERVICE_TOKEN')),
        'scope' => trim((string)getenv('VK_OAUTH_SCOPE'))
    ];
}

function vk_token_file() {
    $configured_path = trim((string)getenv('MEME_TINDER_VK_TOKEN_FILE'));
    if ($configured_path !== '') return $configured_path;
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'meme-tinder-vk-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
}

function load_vk_tokens() {
    $file = vk_token_file();
    if (!is_file($file) || !is_readable($file)) return [];
    $tokens = json_decode((string)file_get_contents($file), true);
    return is_array($tokens) ? $tokens : [];
}

function save_vk_tokens($tokens) {
    $file = vk_token_file();
    $directory = dirname($file);
    if (!is_dir($directory) || !is_writable($directory)) throw new RuntimeException('Каталог для серверных токенов VK недоступен для записи.');
    $temporary_file = tempnam($directory, '.vk-token-');
    if ($temporary_file === false) throw new RuntimeException('Не удалось создать временный файл токена VK.');
    $json = json_encode($tokens, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $written = $json !== false ? file_put_contents($temporary_file, $json, LOCK_EX) : false;
    @chmod($temporary_file, 0600);
    if ($written === false || !rename($temporary_file, $file)) {
        @unlink($temporary_file);
        throw new RuntimeException('Не удалось сохранить токены VK на сервере.');
    }
    @chmod($file, 0600);
}

function vk_oauth_post($url, $parameters) {
    $response = http_post($url, http_build_query($parameters), [
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20
    ]);
    $status = $response['status'];
    $result = $response['body'] === false ? null : json_decode($response['body'], true);
    if (!is_array($result) || $status < 200 || $status >= 300 || !empty($result['error'])) {
        $message = is_array($result) ? ($result['error_description'] ?? $result['error'] ?? '') : '';
        throw new RuntimeException($message ?: ($response['error'] ?: 'Ошибка запроса к VK ID (HTTP ' . $status . ').'));
    }
    return $result;
}

function vk_access_token() {
    $tokens = load_vk_tokens();
    if (empty($tokens['access_token'])) return '';
    if ((int)($tokens['expires_at'] ?? 0) > time() + 60) return (string)$tokens['access_token'];
    $config = vk_oauth_config();
    if (empty($tokens['refresh_token']) || $config['client_id'] === '' || empty($tokens['device_id'])) return '';

    $state = bin2hex(random_bytes(16));
    $parameters = [
        'grant_type' => 'refresh_token',
        'refresh_token' => $tokens['refresh_token'],
        'client_id' => $config['client_id'],
        'device_id' => $tokens['device_id'],
        'state' => $state
    ];
    if ($config['service_token'] !== '') $parameters['service_token'] = $config['service_token'];
    $updated = vk_oauth_post('https://id.vk.ru/oauth2/auth', $parameters);
    if (empty($updated['access_token']) || empty($updated['refresh_token'])) return '';
    if (!empty($updated['state']) && !hash_equals($state, (string)$updated['state'])) {
        throw new RuntimeException('VK вернул неожиданный state при обновлении токена.');
    }
    $tokens = array_merge($tokens, $updated, ['expires_at' => time() + max(0, (int)($updated['expires_in'] ?? 0))]);
    save_vk_tokens($tokens);
    return (string)$tokens['access_token'];
}

function vk_api_request($method, $parameters) {
    $access_token = vk_access_token();
    if ($access_token === '') return [];
    $parameters['access_token'] = $access_token;
    $parameters['v'] = trim((string)getenv('VK_API_VERSION')) ?: '5.199';
    $response = http_post('https://api.vk.com/method/' . rawurlencode($method), http_build_query($parameters));
    $status = $response['status'];
    $result = $response['body'] === false ? null : json_decode($response['body'], true);
    if (!is_array($result) || $status < 200 || $status >= 300) {
        throw new RuntimeException($response['error'] ?: 'Не удалось получить ответ VK API.');
    }
    if (!empty($result['error'])) {
        throw new RuntimeException((string)($result['error']['error_msg'] ?? 'VK API отклонил запрос.'));
    }
    return is_array($result['response'] ?? null) ? $result['response'] : [];
}

function fetch_telegram_channel_page($channel) {
    if (!is_valid_telegram_channel($channel)) return false;
    return http_get('https://t.me/s/' . rawurlencode($channel), [
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MemeTinder/1.0)'
    ]);
}

function build_telegram_preview_posts($source, $count = 4) {
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
        $images = [];
        preg_match_all('~background-image:\s*url\([\'"]?(https://[^\'")\s]+)~i', $content, $image_matches);
        foreach (($image_matches[1] ?? []) as $url) {
            $images[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!$images) {
            preg_match_all('~href="(https://[^\"]*(?:cdn-telegram\.org|telegram-cdn\.org)[^\"]+\.(?:jpe?g|png|webp)(?:\?[^\"]*)?)"~i', $content, $image_matches);
            foreach (($image_matches[1] ?? []) as $url) {
                $images[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $images = array_values(array_unique($images));
        if (!$images) continue;
        $text = '';
        if (preg_match('~<div class="tgme_widget_message_text[^\"]*"[^>]*>(.*?)</div>~si', $content, $text_match)) {
            $text = clean_html_text($text_match[1]);
        }
        $posts[] = [
            'image' => $images[0],
            'images' => array_slice($images, 0, 4),
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'] ?? ('Telegram | ' . $channel),
            'post_id' => $post_id,
            'url' => 'https://t.me/' . rawurlencode($channel) . '/' . rawurlencode($post_id)
        ];
    }
    return $posts;
}

function build_vk_preview_posts($source, $count = 4) {
    $domain = trim((string)($source['id'] ?? ''));
    if (!is_valid_vk_domain($domain)) return [];
    $response = vk_api_request('wall.get', ['domain' => $domain, 'count' => max(1, min(100, $count))]);
    $posts = [];
    foreach (($response['items'] ?? []) as $post) {
        $images = [];
        foreach (($post['attachments'] ?? []) as $attachment) {
            if (($attachment['type'] ?? '') !== 'photo') continue;
            $sizes = $attachment['photo']['sizes'] ?? [];
            usort($sizes, function($first, $second) {
                return ((int)($second['width'] ?? 0) * (int)($second['height'] ?? 0)) <=> ((int)($first['width'] ?? 0) * (int)($first['height'] ?? 0));
            });
            $url = $sizes[0]['url'] ?? '';
            if (is_string($url) && str_starts_with($url, 'https://')) $images[] = $url;
        }
        if (!$images) continue;
        $text = clean_html_text($post['text'] ?? '');
        $owner_id = (string)($post['owner_id'] ?? '');
        $post_id = (string)($post['id'] ?? '');
        $posts[] = [
            'image' => $images[0],
            'images' => array_values(array_unique($images)),
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'] ?? ('ВКонтакте | ' . $domain),
            'post_id' => $post_id,
            'url' => $owner_id !== '' && $post_id !== '' ? 'https://vk.com/wall' . $owner_id . '_' . $post_id : 'https://vk.com/' . rawurlencode($domain)
        ];
        if (count($posts) >= $count) break;
    }
    return $posts;
}
