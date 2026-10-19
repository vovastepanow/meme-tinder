<?php
// Подключение личного аккаунта Telegram для отложенных постов (lib/telegram_user.php): вход по номеру и коду, как в приложении.

/** Данные аккаунта для интерфейса: без секретов. */
function telegram_user_public($connections) {
    $entry = $connections['telegram_user'] ?? null;
    $pending = $connections['telegram_user_pending'] ?? null;
    return [
        'connected' => is_array($entry) && !empty($entry['session_enc']),
        'name' => (string)($entry['name'] ?? ''),
        'username' => (string)($entry['username'] ?? ''),
        'connected_at' => (int)($entry['connected_at'] ?? 0),
        'warning' => (string)($entry['warning'] ?? ''),
        'app_keys' => telegram_app_keys() !== null,   // ключи приложения уже сохранены: в форме их вводить не нужно
        'awaiting_code' => is_array($pending) && time() - (int)($pending['at'] ?? 0) < TELEGRAM_USER_PENDING_TTL,
        'phone' => is_array($pending) ? (string)($pending['phone'] ?? '') : '',
        'qr' => is_array($pending) && !empty($pending['qr']),
        'code_type' => is_array($pending) ? (string)($pending['code_type'] ?? '') : '',
        'next_type' => is_array($pending) ? (string)($pending['next_type'] ?? '') : ''
    ];
}

/**
 * Ключи для входа: введённые в форме (и тогда они запоминаются как ключи приложения, если своих ещё нет) или сохранённые ключи
 * приложения. Нет ни тех, ни других — ошибка с подсказкой, где их взять.
 */
function telegram_user_keys_from_input(array $input) {
    $api_id = trim((string)($input['api_id'] ?? ''));
    $api_hash = trim((string)($input['api_hash'] ?? ''));
    if ($api_id === '' && $api_hash === '') {
        $saved = telegram_app_keys();
        if ($saved === null) json_error('Введите api_id и api_hash: их выдаёт my.telegram.org/apps (один раз, дальше они запомнятся для всех пользователей сайта).');
        return [$saved['api_id'], $saved['api_hash']];
    }
    if (!preg_match('/^\d{3,10}$/', $api_id)) json_error('api_id — число, его выдаёт my.telegram.org.');
    if (!preg_match('/^[a-f0-9]{32}$/i', $api_hash)) json_error('api_hash — 32 символа (цифры и буквы a–f), его выдаёт my.telegram.org.');
    if (telegram_app_keys() === null) telegram_app_keys_save($api_id, $api_hash);
    return [$api_id, $api_hash];
}

/** Телефон в международный вид (те же правила, что в js/account.js): 8999…, 7999…, 999… → +7999…; чужие номера — с «+». */
function telegram_user_normalize_phone($value) {
    $digits = preg_replace('/\D+/', '', $value);
    if ($digits === '') return '';
    $plus = str_starts_with(trim($value), '+');
    if ($plus && $digits[0] !== '7') return '+' . $digits;
    if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) return '+7' . substr($digits, 1);
    if (strlen($digits) === 10) return '+7' . $digits;
    return '+' . $digits;
}

/** POST {api_id, api_hash, phone}: просит Telegram прислать код входа (придёт в приложение Telegram на этом аккаунте). */
function handle_telegram_user_start($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме аккаунт не подключается.', 403);
    if (telegram_connection() === null) json_error('Сначала подключите бота и канал: отложенный пост ставится в тот же канал.');
    [$api_id, $api_hash] = telegram_user_keys_from_input($input);
    $phone = telegram_user_normalize_phone((string)($input['phone'] ?? ''));
    if (!preg_match('/^\+\d{8,15}$/', $phone)) json_error('Номер указывается с кодом страны: +79991234567.');
    try {
        $result = telegram_user_run(['action' => 'send_code', 'api_id' => $api_id, 'api_hash' => $api_hash, 'session' => '', 'phone' => $phone], 60);
    } catch (RuntimeException $error) {
        json_error($error->getMessage());
    }
    update_data(connections_file(), [], function ($connections) use ($api_id, $api_hash, $phone, $result) {
        $connections = is_array($connections) ? $connections : [];
        $connections['telegram_user_pending'] = [
            'api_id' => $api_id, 'api_hash_enc' => secret_encrypt($api_hash), 'session_enc' => secret_encrypt($result['session'] ?? ''),
            'phone' => $phone, 'phone_code_hash' => (string)$result['phone_code_hash'], 'code_type' => (string)($result['code_type'] ?? ''),
            'next_type' => (string)($result['next_type'] ?? ''), 'at' => time()
        ];
        return $connections;
    });
    @chmod(connections_file(), 0660);
    json_response(['status' => 'success', 'telegram_user' => telegram_user_public(load_data(connections_file(), []))]);
}

/** POST: просит Telegram прислать код другим способом (SMS и т. д.), если в приложение он не пришёл. */
function handle_telegram_user_resend($debug_mode) {
    if ($debug_mode) json_error('В демо-режиме аккаунт не подключается.', 403);
    $pending = load_data(connections_file(), [])['telegram_user_pending'] ?? null;
    if (!is_array($pending) || empty($pending['next_type'])) json_error('Другого способа доставки кода Telegram не предлагает: подождите несколько минут и запросите код заново.', 409);
    $api_hash = secret_decrypt($pending['api_hash_enc']);
    $session = secret_decrypt($pending['session_enc']);
    if ($api_hash === null || $session === null) json_error('Не удалось прочитать сохранённые данные: запросите код заново.', 409);
    try {
        $result = telegram_user_run(['action' => 'resend_code', 'api_id' => $pending['api_id'], 'api_hash' => $api_hash, 'session' => $session,
            'phone' => $pending['phone'], 'phone_code_hash' => $pending['phone_code_hash']], 60);
    } catch (RuntimeException $error) {
        json_error($error->getMessage());
    }
    update_data(connections_file(), [], function ($connections) use ($result) {
        $entry = &$connections['telegram_user_pending'];
        $entry['session_enc'] = secret_encrypt($result['session'] ?? '');
        $entry['phone_code_hash'] = (string)$result['phone_code_hash'];
        $entry['code_type'] = (string)($result['code_type'] ?? '');
        $entry['next_type'] = (string)($result['next_type'] ?? '');
        $entry['at'] = time();
        return $connections;
    });
    json_response(['status' => 'success', 'telegram_user' => telegram_user_public(load_data(connections_file(), []))]);
}

/** POST {code, password?}: завершает вход. Если включена двухэтапная защита, сначала приходит needs_password. */
function handle_telegram_user_confirm($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме аккаунт не подключается.', 403);
    $bot = telegram_connection();
    if ($bot === null) json_error('Сначала подключите бота и канал.');
    $connections = load_data(connections_file(), []);
    $pending = $connections['telegram_user_pending'] ?? null;
    if (!is_array($pending) || time() - (int)($pending['at'] ?? 0) > TELEGRAM_USER_PENDING_TTL) json_error('Код устарел: запросите новый.', 409);
    $code = preg_replace('/\D+/', '', (string)($input['code'] ?? ''));
    $password = (string)($input['password'] ?? '');
    if ($code === '' && $password === '') json_error('Введите код из Telegram.');
    $api_hash = secret_decrypt($pending['api_hash_enc']);
    $session = secret_decrypt($pending['session_enc']);
    if ($api_hash === null || $session === null) json_error('Не удалось прочитать сохранённые данные: запросите код заново.', 409);
    try {
        $request = ['api_id' => $pending['api_id'], 'api_hash' => $api_hash, 'session' => $session];
        $result = telegram_user_run($request + (!empty($pending['qr'])
            ? ['action' => 'password', 'password' => $password]   // вход по QR: аккаунт уже открыт, не хватает только пароля
            : ['action' => 'sign_in', 'phone' => $pending['phone'], 'code' => $code, 'phone_code_hash' => $pending['phone_code_hash'], 'password' => $password]), 60);
        if (!empty($result['needs_password'])) {
            update_data(connections_file(), [], function ($connections) use ($result) {
                $connections['telegram_user_pending']['session_enc'] = secret_encrypt($result['session'] ?? '');
                return $connections;
            });
            json_response(['status' => 'needs_password']);
        }
        // Права аккаунта в канале проверяются сразу: иначе ошибка вылезла бы только при отложенной публикации
        $check = telegram_user_run(['api_id' => $pending['api_id'], 'api_hash' => $api_hash, 'session' => $result['session'], 'action' => 'check_channel', 'channel' => telegram_user_channel_ref($bot)], 60);
    } catch (RuntimeException $error) {
        json_error($error->getMessage());
    }
    telegram_user_save($pending['api_id'], $api_hash, $check['session'] ?? $result['session'], $result['user'] ?? [], telegram_user_channel_warning($check));
    json_response(['status' => 'success', 'telegram_user' => telegram_user_public(load_data(connections_file(), []))]);
}

/** Предупреждение, если Telegram не видит у аккаунта права публиковать в канале (вход при этом принимается: пост просто уйдёт через очередь приложения). */
function telegram_user_channel_warning(array $check) {
    if (!empty($check['can_post'])) return '';
    return 'Вы вошли как ' . ($check['account'] ?? 'неизвестный аккаунт') . ', а в канале «' . ($check['title'] ?? '') . '» у него роль: ' . ($check['role'] ?? 'неизвестна')
        . '. Нужен владелец или администратор с правом публикации — иначе отложенные посты будут идти через очередь приложения.';
}

/** Сохраняет вошедший аккаунт (секреты шифруются) и убирает незаконченный вход. */
function telegram_user_save($api_id, $api_hash, $session, array $user, $warning = '') {
    update_data(connections_file(), [], function ($connections) use ($api_id, $api_hash, $session, $user, $warning) {
        $connections = is_array($connections) ? $connections : [];
        unset($connections['telegram_user_pending']);
        $connections['telegram_user'] = [
            'api_id' => $api_id, 'api_hash_enc' => secret_encrypt($api_hash), 'session_enc' => secret_encrypt($session),
            'name' => (string)($user['name'] ?? ''), 'username' => (string)($user['username'] ?? ''), 'connected_at' => time(), 'warning' => $warning
        ];
        return $connections;
    });
    @chmod(connections_file(), 0660);
    if (telegram_app_keys() === null) telegram_app_keys_save($api_id, $api_hash);   // ключи подошли для входа — запоминаем для всех
}

/** POST {api_id, api_hash}: вход по QR-коду, если код входа не приходит. Сам код показывает qr_status. */
function handle_telegram_user_qr_start($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме аккаунт не подключается.', 403);
    if (telegram_connection() === null) json_error('Сначала подключите бота и канал: отложенный пост ставится в тот же канал.');
    [$api_id, $api_hash] = telegram_user_keys_from_input($input);
    update_data(connections_file(), [], function ($connections) use ($api_id, $api_hash) {
        $connections = is_array($connections) ? $connections : [];
        unset($connections['telegram_user_pending']);
        $connections['telegram_user_qr'] = ['api_id' => $api_id, 'api_hash_enc' => secret_encrypt($api_hash), 'at' => time()];
        return $connections;
    });
    @chmod(connections_file(), 0660);
    telegram_user_qr_start($api_id, $api_hash);
    json_response(['status' => 'waiting']);
}

/** GET: состояние входа по QR: waiting (+ svg с кодом), needs_password, ok, expired или error. */
function handle_telegram_user_qr_status($debug_mode) {
    if ($debug_mode) json_error('В демо-режиме аккаунт не подключается.', 403);
    $bot = telegram_connection();
    $connections = load_data(connections_file(), []);
    if (telegram_user_connection() !== null) json_response(['status' => 'success', 'telegram_user' => telegram_user_public($connections)]);   // уже вошли (запрос догнал предыдущий)
    $qr = $connections['telegram_user_qr'] ?? null;
    $state = @json_decode((string)@file_get_contents(telegram_user_qr_state_file()), true);
    if ($bot === null || !is_array($qr) || !is_array($state)) json_response(['status' => 'none']);
    $api_hash = secret_decrypt($qr['api_hash_enc']);
    switch ($state['status'] ?? '') {
        case 'waiting':
            json_response(['status' => 'waiting', 'svg' => (string)($state['svg'] ?? '')]);
        case 'needs_password':
            update_data(connections_file(), [], function ($connections) use ($qr, $state) {
                unset($connections['telegram_user_qr']);
                $connections['telegram_user_pending'] = ['api_id' => $qr['api_id'], 'api_hash_enc' => $qr['api_hash_enc'], 'session_enc' => secret_encrypt((string)$state['session']),
                    'qr' => true, 'phone' => '', 'phone_code_hash' => '', 'code_type' => '', 'next_type' => '', 'at' => time()];
                return $connections;
            });
            @unlink(telegram_user_qr_state_file());
            json_response(['status' => 'needs_password']);
        case 'finishing':
            json_response(['status' => 'busy']);   // другой запрос уже завершает вход
        case 'ok':
            $state_file = telegram_user_qr_state_file();
            file_put_contents($state_file, json_encode(['token' => $state['token'], 'status' => 'finishing']));   // повторные запросы ждут, пока этот закончит
            $fail = function ($message) use ($state_file, $state) {
                file_put_contents($state_file, json_encode(['token' => $state['token'], 'status' => 'error', 'error' => $message]));
                json_response(['status' => 'error', 'error' => $message]);
            };
            try {
                $check = telegram_user_run(['api_id' => $qr['api_id'], 'api_hash' => $api_hash, 'session' => $state['session'], 'action' => 'check_channel', 'channel' => telegram_user_channel_ref($bot)], 60);
            } catch (RuntimeException $error) {
                $fail('Вход выполнен, но канал проверить не удалось: ' . $error->getMessage());
            }
            @unlink($state_file);
            telegram_user_save($qr['api_id'], $api_hash, $check['session'] ?? $state['session'], $state['user'] ?? [], telegram_user_channel_warning($check));
            update_data(connections_file(), [], function ($connections) { unset($connections['telegram_user_qr']); return $connections; });
            json_response(['status' => 'success', 'telegram_user' => telegram_user_public(load_data(connections_file(), []))]);
        case 'expired':
            json_response(['status' => 'expired']);
        case 'error':
            json_response(['status' => 'error', 'error' => (string)($state['error'] ?? 'Ошибка входа.')]);
    }
    json_response(['status' => 'waiting', 'svg' => '']);
}

function handle_telegram_user_disconnect($debug_mode) {
    if ($debug_mode) json_error('В демо-режиме соцсети не подключаются.', 403);
    update_data(connections_file(), [], function ($connections) {
        if (!is_array($connections) || (!isset($connections['telegram_user']) && !isset($connections['telegram_user_pending']))) return null;
        unset($connections['telegram_user'], $connections['telegram_user_pending']);
        return $connections;
    });
    json_response(['status' => 'success', 'telegram_user' => telegram_user_public(load_data(connections_file(), []))]);
}
