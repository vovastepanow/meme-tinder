<?php
// Вход через VK ID (OAuth 2 + PKCE) для подбора пабликов.
// ---- VK OAuth ----

function handle_vk_oauth_status($debug_mode) {
    $config = vk_oauth_config();
    $tokens = load_vk_tokens();
    json_response([
        'configured' => !$debug_mode && $config['client_id'] !== '' && $config['redirect_uri'] !== '',
        'connected' => !$debug_mode && !empty($tokens['refresh_token']),
        'user_id' => $debug_mode ? null : ($tokens['user_id'] ?? null)
    ]);
}

function handle_vk_oauth_start($debug_mode) {
    $config = vk_oauth_config();
    if ($debug_mode || $config['client_id'] === '' || $config['redirect_uri'] === '') {
        redirect_to_source_tab('misconfigured');
    }
    ensure_session_started();
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

function handle_vk_oauth_callback() {
    ensure_session_started();
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
        redirect_to_source_tab('error');
    }
    if ($code === '' || $device_id === '' || $config['client_id'] === '' || $config['redirect_uri'] === '') {
        redirect_to_source_tab('error');
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
        redirect_to_source_tab('connected');
    } catch (Throwable $error) {
        error_log('VK OAuth callback failed: ' . $error->getMessage());
        redirect_to_source_tab('error');
    }
}
