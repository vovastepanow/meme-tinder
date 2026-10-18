<?php
// ВКонтакте: токены VK ID (OAuth) и выборка постов через VK API.

const VK_ID_AUTHORIZE_URL = 'https://id.vk.ru/authorize';
const VK_ID_TOKEN_URL = 'https://id.vk.ru/oauth2/auth';
const VK_API_DEFAULT_VERSION = '5.199';

function vk_oauth_config() {
    return [
        'client_id' => trim((string)getenv('VK_CLIENT_ID')),
        'redirect_uri' => trim((string)getenv('VK_REDIRECT_URI')),
        'service_token' => trim((string)getenv('VK_SERVICE_TOKEN')),
        'scope' => trim((string)getenv('VK_OAUTH_SCOPE'))
    ];
}

function vk_token_file() {
    $configured_path = trim((string)getenv('CONTENT_TINDER_VK_TOKEN_FILE'));
    if ($configured_path !== '') return $configured_path;
    return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'content-tinder-vk-' . substr(hash('sha256', __DIR__), 0, 16) . '.json';
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
    $updated = vk_oauth_post(VK_ID_TOKEN_URL, $parameters);
    if (empty($updated['access_token']) || empty($updated['refresh_token'])) return '';
    if (!empty($updated['state']) && !hash_equals($state, (string)$updated['state'])) {
        throw new RuntimeException('VK вернул неожиданный state при обновлении токена.');
    }
    $tokens = array_merge($tokens, $updated, ['expires_at' => time() + max(0, (int)($updated['expires_in'] ?? 0))]);
    save_vk_tokens($tokens);
    return (string)$tokens['access_token'];
}

/** Есть ли рабочий токен VK. Ошибка обновления токена логируется и считается отсутствием подключения. */
function vk_is_connected() {
    try {
        return vk_access_token() !== '';
    } catch (Throwable $error) {
        error_log('VK token refresh failed: ' . $error->getMessage());
        return false;
    }
}

function vk_oauth_is_configured() {
    $config = vk_oauth_config();
    return $config['client_id'] !== '' && $config['redirect_uri'] !== '';
}

function vk_api_request($method, $parameters) {
    $access_token = vk_access_token();
    if ($access_token === '') return [];
    $parameters['access_token'] = $access_token;
    $parameters['v'] = trim((string)getenv('VK_API_VERSION')) ?: VK_API_DEFAULT_VERSION;
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

function vk_largest_photo_url(array $sizes) {
    $best_url = '';
    $best_area = -1;
    foreach ($sizes as $size) {
        $area = (int)($size['width'] ?? 0) * (int)($size['height'] ?? 0);
        if ($area > $best_area && is_string($size['url'] ?? null)) {
            $best_area = $area;
            $best_url = $size['url'];
        }
    }
    return $best_url;
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
            $url = vk_largest_photo_url($attachment['photo']['sizes'] ?? []);
            if (is_string($url) && str_starts_with($url, 'https://')) $images[] = $url;
        }
        if (!$images) continue;
        $text = clean_html_text($post['text'] ?? '');
        $owner_id = (string)($post['owner_id'] ?? '');
        $post_id = (string)($post['id'] ?? '');
        $posts[] = [
            'image' => $images[0],
            'images' => array_values(array_unique($images)),
            'text' => $text ?: EMPTY_POST_TEXT,
            'source' => $source['name'] ?? source_display_name(PLATFORM_VK, $domain),
            'post_id' => $post_id,
            'url' => $owner_id !== '' && $post_id !== '' ? 'https://vk.com/wall' . $owner_id . '_' . $post_id : 'https://vk.com/' . rawurlencode($domain)
        ];
        if (count($posts) >= $count) break;
    }
    return $posts;
}
