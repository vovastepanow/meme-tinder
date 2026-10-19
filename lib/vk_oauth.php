<?php
// Вход через VK ID (OAuth 2 + PKCE) для подбора пабликов.

const VK_OAUTH_STATE_TTL = 600;

function base64url_encode($bytes) {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function handle_vk_oauth_status($debug_mode) {
    $tokens = $debug_mode ? [] : load_vk_tokens();
    json_response([
        'configured' => !$debug_mode && vk_oauth_is_configured(),
        'connected' => !empty($tokens['refresh_token']),
        'user_id' => $tokens['user_id'] ?? null
    ]);
}

function handle_vk_oauth_start($debug_mode) {
    if ($debug_mode || !vk_oauth_is_configured()) redirect_after_vk_oauth('misconfigured');

    $config = vk_oauth_config();
    ensure_session_started();
    $state = bin2hex(random_bytes(24));
    $verifier = base64url_encode(random_bytes(48));
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
        'code_challenge' => base64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'lang_id' => '0'
    ];
    if ($config['scope'] !== '') $parameters['scope'] = $config['scope'];
    header('Location: ' . VK_ID_AUTHORIZE_URL . '?' . http_build_query($parameters), true, 302);
    exit;
}

/** Параметры колбэка: VK ID может прислать их в query или JSON-строкой в payload. */
function vk_oauth_callback_parameters() {
    $callback = $_GET;
    if (isset($callback['payload'])) {
        $payload = json_decode((string)$callback['payload'], true);
        if (is_array($payload)) $callback = array_merge($callback, $payload);
    }
    return $callback;
}

function handle_vk_oauth_callback() {
    ensure_session_started();
    $callback = vk_oauth_callback_parameters();
    $oauth_state = $_SESSION['vk_oauth'] ?? [];
    unset($_SESSION['vk_oauth']);

    $state = (string)($callback['state'] ?? '');
    $code = (string)($callback['code'] ?? '');
    $device_id = (string)($callback['device_id'] ?? '');
    $state_is_valid = (int)($oauth_state['started_at'] ?? 0) >= time() - VK_OAUTH_STATE_TTL
        && !empty($oauth_state['state'])
        && hash_equals((string)$oauth_state['state'], $state);
    if (!$state_is_valid || $code === '' || $device_id === '' || !vk_oauth_is_configured()) {
        redirect_after_vk_oauth('error');
    }

    $config = vk_oauth_config();
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
        $tokens = vk_oauth_post(VK_ID_TOKEN_URL, $parameters);
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new RuntimeException('VK ID не вернул access_token и refresh_token.');
        }
        $tokens['device_id'] = $device_id;
        $tokens['expires_at'] = time() + max(0, (int)($tokens['expires_in'] ?? 0));
        save_vk_tokens($tokens);
        session_regenerate_id(true);
        redirect_after_vk_oauth('connected');
    } catch (Throwable $error) {
        error_log('VK OAuth callback failed: ' . $error->getMessage());
        redirect_after_vk_oauth('error');
    }
}
