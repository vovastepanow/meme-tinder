<?php
// Отложенные посты средствами самого Telegram: у бота такой возможности нет, у аккаунта — есть («Отложенные» в канале).
// Работа с Telegram идёт через tools/tg_user.py (Telethon, MTProto). У каждого пользователя приложения свой api_id/api_hash
// и своя сессия (connections.json → telegram_user, секреты зашифрованы так же, как токен бота: lib/secrets.php).

const TELEGRAM_USER_PYTHON = '/usr/bin/python3';
const TELEGRAM_USER_TIMEOUT = 300;           // загрузка видео в Telegram занимает минуты
const TELEGRAM_USER_PENDING_TTL = 900;       // код из Telegram действует недолго

/** Запускает tools/tg_user.py с JSON-запросом. Возвращает ответ помощника (ok = true) или бросает исключение с причиной. */
function telegram_user_run(array $request, $timeout = TELEGRAM_USER_TIMEOUT) {
    $process = @proc_open([TELEGRAM_USER_PYTHON, __DIR__ . '/../tools/tg_user.py'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
        null, ['PATH' => '/usr/bin:/bin', 'PYTHONDONTWRITEBYTECODE' => '1', 'LANG' => 'C.UTF-8']);
    if (!is_resource($process)) throw new RuntimeException('Не удалось запустить помощника Telegram (нужен python3).');
    fwrite($pipes[0], json_encode($request, JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    $output = '';
    $deadline = time() + $timeout;
    while (true) {
        $chunk = fread($pipes[1], 65536);
        if ($chunk !== false && $chunk !== '') $output .= $chunk;
        $status = proc_get_status($process);
        if (!$status['running']) { $output .= (string)stream_get_contents($pipes[1]); break; }
        if (time() > $deadline) { proc_terminate($process, 9); fclose($pipes[1]); fclose($pipes[2]); proc_close($process); throw new RuntimeException('Telegram не ответил вовремя.'); }
        usleep(50000);
    }
    $errors = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    $result = json_decode($output, true);
    if (!is_array($result)) throw new RuntimeException('Помощник Telegram не ответил' . ($errors !== '' ? ': ' . mb_substr($errors, -300) : '.'));
    if (empty($result['ok'])) throw new RuntimeException((string)($result['error'] ?? 'Неизвестная ошибка Telegram.'));
    return $result;
}

/** Подключённый аккаунт пользователя с расшифрованными секретами или null. */
function telegram_user_connection($uid = null) {
    $connections = load_data(connections_file($uid), []);
    $entry = $connections['telegram_user'] ?? null;
    if (!is_array($entry) || empty($entry['session_enc']) || empty($entry['api_hash_enc'])) return null;
    $hash = secret_decrypt($entry['api_hash_enc']);
    $session = secret_decrypt($entry['session_enc']);
    if ($hash === null || $session === null) return null;
    return ['api_id' => (string)$entry['api_id'], 'api_hash' => $hash, 'session' => $session] + $entry;
}

/** Общая часть запроса к помощнику: ключи приложения и сессия. */
function telegram_user_request(array $connection, array $extra) {
    return ['api_id' => $connection['api_id'], 'api_hash' => $connection['api_hash'], 'session' => $connection['session']] + $extra;
}

/** Канал для аккаунта: @имя публичного канала, иначе числовой id канала, к которому подключён бот. */
function telegram_user_channel_ref(array $bot_connection) {
    $username = (string)($bot_connection['chat_username'] ?? '');
    return $username !== '' ? '@' . $username : (string)$bot_connection['chat_id'];
}

/** Сохраняет обновлённую сессию (Telethon может обновить ключи в ходе работы). */
function telegram_user_store_session($uid, $session) {
    if (!is_string($session) || $session === '') return;
    update_data(connections_file($uid), [], function ($connections) use ($session) {
        if (!is_array($connections) || !isset($connections['telegram_user'])) return null;
        $connections['telegram_user']['session_enc'] = secret_encrypt($session);
        return $connections;
    });
}

/**
 * Ставит пост в «Отложенные» канала от имени аккаунта. $prepared — файлы после telegram_prepare_media.
 * Возвращает id отложенных сообщений в Telegram.
 */
function telegram_user_schedule($uid, array $bot_connection, $text, array $prepared, $publish_at) {
    $connection = telegram_user_connection($uid);
    if ($connection === null) throw new RuntimeException('Аккаунт Telegram не подключён.');
    $result = telegram_user_run(telegram_user_request($connection, [
        'action' => 'schedule',
        'channel' => telegram_user_channel_ref($bot_connection),
        'text' => $text,
        'publish_at' => (int)$publish_at,
        'media' => array_map(fn($file) => ['kind' => $file['kind'], 'path' => $file['path'], 'width' => $file['width'] ?? 0, 'height' => $file['height'] ?? 0, 'duration' => $file['duration'] ?? 0], $prepared)
    ]));
    telegram_user_store_session($uid, $result['session'] ?? '');
    return array_map('intval', $result['ids'] ?? []);
}

/** Убирает отложенные сообщения из «Отложенных» канала. */
function telegram_user_cancel($uid, array $bot_connection, array $ids) {
    $connection = telegram_user_connection($uid);
    if ($connection === null) throw new RuntimeException('Аккаунт Telegram не подключён.');
    $result = telegram_user_run(telegram_user_request($connection, ['action' => 'cancel', 'channel' => telegram_user_channel_ref($bot_connection), 'ids' => $ids]), 60);
    telegram_user_store_session($uid, $result['session'] ?? '');
}

/**
 * Поиск каналов для автоподбора источников от имени подключённого аккаунта пользователя: глобальный поиск Telegram
 * по запросам ($queries) или «похожие каналы» для известных ($similar_to). Аккаунт не подключён или Telegram отказал — [].
 * Возвращает [['username', 'title', 'subscribers', 'origin']].
 */
function telegram_user_find_channels(array $queries, array $similar_to = [], $uid = null) {
    $uid = $uid ?? app_user_id();
    $connection = telegram_user_connection($uid);
    if ($connection === null || (!$queries && !$similar_to)) return [];
    $request = $queries ? ['action' => 'search_channels', 'queries' => array_values($queries), 'limit' => 30]
        : ['action' => 'similar_channels', 'channels' => array_values($similar_to)];
    $result = telegram_user_run(telegram_user_request($connection, $request), 120);
    telegram_user_store_session($uid, $result['session'] ?? '');
    return array_values(array_filter((array)($result['channels'] ?? []), fn($channel) => is_valid_telegram_channel((string)($channel['username'] ?? ''))));
}

/** Файл состояния входа по QR: во временной папке, а не в users/ — сессия в нём лежит открытой, и сайт не должен её отдавать. */
function telegram_user_qr_state_file($uid = null) {
    return sys_get_temp_dir() . '/postmaker-tgu-' . ($uid ?? app_user_id()) . '.json';
}

/** Запускает вход по QR в фоне (до двух с половиной минут). Возвращает токен запуска. */
function telegram_user_qr_start($api_id, $api_hash) {
    $uid = app_user_id();
    $token = bin2hex(random_bytes(8));
    $state_file = telegram_user_qr_state_file($uid);
    file_put_contents($state_file, json_encode(['token' => $token, 'status' => 'starting']));
    @chmod($state_file, 0600);
    $request_file = tempnam(sys_get_temp_dir(), 'postmaker-tgu-req-');
    file_put_contents($request_file, json_encode(['api_id' => $api_id, 'api_hash' => $api_hash, 'state_file' => $state_file, 'token' => $token]));
    @chmod($request_file, 0600);
    $command = '/usr/bin/setsid ' . escapeshellarg(TELEGRAM_USER_PYTHON) . ' ' . escapeshellarg(__DIR__ . '/../tools/tg_user.py') . ' qr-login ' . escapeshellarg($request_file) . ' >/dev/null 2>&1 &';
    exec('PYTHONDONTWRITEBYTECODE=1 PATH=/usr/bin:/bin ' . $command);
    return $token;
}

// ---- Ключи приложения Telegram (api_id и api_hash) ----
// Они выдаются приложению, а не человеку, поэтому достаточно ввести их один раз: дальше они (api_hash зашифрован) лежат на сервере
// в telegram_app.json и подставляются всем пользователям сайта. Новому пользователю остаются только телефон и код.

const TELEGRAM_APP_KEYS_FILE = __DIR__ . '/../telegram_app.json';

/** Сохранённые ключи приложения ['api_id', 'api_hash'] или null. */
function telegram_app_keys() {
    $data = load_data(TELEGRAM_APP_KEYS_FILE, []);
    $hash = is_array($data) && !empty($data['api_hash_enc']) ? secret_decrypt($data['api_hash_enc']) : null;
    return $hash !== null && !empty($data['api_id']) ? ['api_id' => (string)$data['api_id'], 'api_hash' => $hash] : null;
}

function telegram_app_keys_save($api_id, $api_hash) {
    update_data(TELEGRAM_APP_KEYS_FILE, [], function () use ($api_id, $api_hash) {
        return ['api_id' => (string)$api_id, 'api_hash_enc' => secret_encrypt($api_hash), 'saved_at' => time()];
    });
    @chmod(TELEGRAM_APP_KEYS_FILE, 0660);
}
