<?php
// Личный кабинет: профиль, что сайт хранит о пользователе (куки, сессия, файлы на сервере) и подключение Telegram.
// Подключение VK — общее для сайта (поиск пабликов), его обслуживает lib/vk_oauth.php.

// Куки сайта: зачем они нужны. Остальные показываются как «неизвестные» (чужие для этого пути или от старых версий).
const ACCOUNT_COOKIE_INFO = [
    USER_COOKIE => ['purpose' => 'Ваш идентификатор. По нему сайт находит вашу папку с пулом, историей оценок и темой. Без неё сайт заведёт нового пользователя с пустой историей.',
        'lifetime' => USER_COOKIE_DAYS . ' дней, продлевается при каждом заходе'],
    'PHPSESSID' => ['purpose' => 'Сессия PHP. Нужна только на время входа через VK (защита от подмены ответа) и для демо-режима.',
        'lifetime' => 'до закрытия браузера'],
];

// Файлы в папке пользователя: что в них лежит.
const ACCOUNT_FILE_INFO = [
    'pool.json' => 'Пул отобранных записей, которые ждут вашей оценки',
    'history.json' => 'История оценок: лайки, дизлайки, «не знаю», жалобы',
    'settings.json' => 'Настройки: тема поиска',
    'connections.json' => 'Подключённые соцсети (токен бота Telegram)',
    'last-seen' => 'Отметка последнего захода — по ней удаляются давно брошенные профили',
];

/** Значение для показа: целиком, если просили, иначе начало и конец. */
function account_mask($value, $reveal = false) {
    $value = (string)$value;
    if ($reveal || mb_strlen($value) <= 10) return $value;
    return mb_substr($value, 0, 6) . '…' . mb_substr($value, -4);
}

function account_file_description($name) {
    if (isset(ACCOUNT_FILE_INFO[$name])) return ACCOUNT_FILE_INFO[$name];
    if (str_ends_with($name, '.lock')) return 'Служебная блокировка (защищает файл от одновременной записи)';
    if (str_starts_with($name, 'parser-')) {
        if (str_ends_with($name, '.log')) return 'Журнал вашего парсера (то, что видно в «Журнале»)';
        if (str_ends_with($name, '.session')) return 'Какая вкладка сейчас ведёт сессию';
        return 'Состояние парсера: когда и с каким процессом запущен';
    }
    return 'Служебный файл';
}

/** Подключение Telegram для показа: без токена (он не покидает сервер). */
function account_telegram_public(array $connections) {
    $telegram = telegram_connection();   // токен расшифрован
    if ($telegram === null) return null;
    return [
        'bot' => $telegram['bot_username'] ?? '',
        'chat_title' => $telegram['chat_title'] ?? '',
        'chat_username' => $telegram['chat_username'] ?? '',
        'chat_type' => $telegram['chat_type'] ?? '',
        'token' => account_mask($telegram['token']),
        'connected_at' => (int)($telegram['connected_at'] ?? 0)
    ];
}

/**
 * Какие служебные файлы сервер отдаёт по HTTP напрямую. Не пусто — Apache не читает .htaccess (AllowOverride None) и защита
 * данных не работает: .git, истории и каталог источников можно скачать. Проверка идёт запросом к самому себе; результат помнится 10 минут.
 */
function account_web_exposure() {
    $cache_file = sys_get_temp_dir() . '/postmaker-exposure-' . substr(sha1(__DIR__), 0, 8) . '.json';
    $cached = @json_decode((string)@file_get_contents($cache_file), true);
    if (is_array($cached) && time() - (int)($cached['at'] ?? 0) < 600) return $cached['exposed'];
    $base_path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\');
    $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
    $exposed = [];
    foreach (['.git/config', 'sources_db.json', 'README.md'] as $path) {
        $ch = curl_init(((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://127.0.0.1:' . $port . $base_path . '/' . $path);
        curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Host: ' . ($_SERVER['HTTP_HOST'] ?? 'localhost')], CURLOPT_SSL_VERIFYPEER => false]);
        curl_exec($ch);
        if ((int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) $exposed[] = $path;
        curl_close($ch);
    }
    @file_put_contents($cache_file, json_encode(['at' => time(), 'exposed' => $exposed]));
    return $exposed;
}

function handle_account_info($debug_mode) {
    if ($debug_mode) json_response(['debug' => true]);
    $uid = app_user_id();
    $history = load_data(history_file(), []);
    $counts = [];
    foreach (HISTORY_COLLECTIONS as $collection) $counts[$collection] = count($history[$collection] ?? []);

    $files = [];
    foreach (glob(user_dir() . '/*') ?: [] as $path) {
        if (!is_file($path)) continue;
        $name = basename($path);
        $files[] = ['name' => $name, 'size' => filesize($path), 'modified' => filemtime($path), 'about' => account_file_description($name)];
    }
    usort($files, function ($a, $b) { return $b['size'] <=> $a['size']; });

    $cookies = [];
    foreach ($_COOKIE as $name => $value) {
        $info = ACCOUNT_COOKIE_INFO[$name] ?? null;
        $cookies[] = [
            'name' => (string)$name,
            'value' => (string)$value,
            'known' => $info !== null,
            'purpose' => $info['purpose'] ?? 'Неизвестная кука: этот сайт её не ставит (её прислал браузер для этого адреса).',
            'lifetime' => $info['lifetime'] ?? '—',
            'http_only' => $name === USER_COOKIE || ($name === session_name() && (bool)ini_get('session.cookie_httponly'))
        ];
    }

    // Что лежит в сессии PHP (только ключи и их смысл, без значений — там служебные секреты входа через VK)
    $session_keys = [];
    if (isset($_COOKIE[session_name()])) {
        @session_start(['read_and_close' => true]);
        $session_keys = array_keys($_SESSION ?? []);
    }

    json_response([
        'user_id' => $uid,
        'topic' => current_topic(),
        'last_seen' => (int)@filemtime(user_dir() . '/last-seen'),
        'pool' => count(load_data(pool_file(), [])),
        'counts' => $counts,
        'files' => $files,
        'cookies' => $cookies,
        'session_keys' => $session_keys,
        'retention_days' => USER_RETENTION_DAYS,
        'exposed' => account_web_exposure(),
        'fix_command' => 'sudo sh ' . dirname(__DIR__) . '/tools/apache-setup.sh',
        'telegram' => account_telegram_public(load_data(connections_file(), [])),
        'telegram_user' => telegram_user_public(load_data(connections_file(), []))
    ]);
}

/** Вызов Bot API (общий запрос — lib/telegram_publish.php). Возвращает result или бросает исключение с описанием ошибки от Telegram. */
function telegram_api($token, $method, array $params = []) {
    return telegram_bot_request($token, $method, $params, [], 20);
}

/**
 * POST {token, channel}: проверяет бота (getMe), канал (getChat) и права бота в канале (getChatMember) и сохраняет
 * подключение в папке пользователя. Бот должен быть администратором канала с правом публиковать сообщения.
 */
function handle_telegram_connect($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме соцсети не подключаются.', 403);
    $token = trim((string)($input['token'] ?? ''));
    $channel = trim((string)($input['channel'] ?? ''));
    if (!preg_match('/^\d{5,12}:[A-Za-z0-9_-]{30,50}$/', $token)) json_error('Токен бота выглядит иначе: 123456789:AA… — его выдаёт @BotFather.');
    $channel = preg_replace('~^(https?://)?t\.me/~i', '@', $channel);
    if (!preg_match('/^(@[A-Za-z][A-Za-z0-9_]{4,31}|-100\d{5,15})$/', $channel)) json_error('Укажите канал как @имя_канала или числовой id вида -100…');

    try {
        $bot = telegram_api($token, 'getMe');
        $chat = telegram_api($token, 'getChat', ['chat_id' => $channel]);
        $member = telegram_api($token, 'getChatMember', ['chat_id' => $chat['id'], 'user_id' => $bot['id']]);
    } catch (RuntimeException $error) {
        json_error('Telegram: ' . $error->getMessage());
    }
    $can_post = ($member['status'] ?? '') === 'creator'
        || (($member['status'] ?? '') === 'administrator' && (($chat['type'] ?? '') !== 'channel' || !empty($member['can_post_messages'])));
    if (!$can_post) json_error("Бот @{$bot['username']} не может публиковать в «" . ($chat['title'] ?? $channel) . "»: добавьте его администратором канала с правом публикации.");

    update_data(connections_file(), [], function ($connections) use ($token, $bot, $chat) {
        $connections = is_array($connections) ? $connections : [];
        $connections['telegram'] = [
            'token_enc' => secret_encrypt($token),   // открытый токен на диск не пишется
            'bot_id' => $bot['id'],
            'bot_username' => $bot['username'] ?? '',
            'chat_id' => $chat['id'],
            'chat_title' => $chat['title'] ?? '',
            'chat_username' => $chat['username'] ?? '',
            'chat_type' => $chat['type'] ?? '',
            'connected_at' => time()
        ];
        return $connections;
    });
    @chmod(connections_file(), 0660); // токен — секрет: файл не читается посторонними пользователями системы
    json_response(['status' => 'success', 'telegram' => account_telegram_public(load_data(connections_file(), []))]);
}

function handle_telegram_disconnect($debug_mode) {
    if ($debug_mode) json_error('В демо-режиме соцсети не подключаются.', 403);
    update_data(connections_file(), [], function ($connections) {
        if (!is_array($connections) || !isset($connections['telegram'])) return null;
        unset($connections['telegram']);
        return $connections;
    });
    json_response(['status' => 'success']);
}
