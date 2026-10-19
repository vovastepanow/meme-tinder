<?php
// Пользователи: у каждого свой идентификатор в куке ct_uid и своя папка users/<id>/ с пулом, историей оценок,
// темой поиска и состоянием собственного парсера. Каталог источников и кэши (быстрой оценки, анализа, медиа) общие.

function is_valid_user_id($uid) {
    return is_string($uid) && preg_match('/^[a-f0-9]{32}$/', $uid) === 1;
}

/** Привязывает процесс к пользователю (веб-запрос — по куке, парсер — по аргументу). Возвращает текущий id ('' — не задан). */
function app_user_bind($uid = null) {
    static $bound = '';
    if ($uid !== null) $bound = is_valid_user_id($uid) ? $uid : '';
    return $bound;
}

function app_user_id() {
    $uid = app_user_bind();
    if ($uid === '') throw new RuntimeException('Пользователь не определён.');
    return $uid;
}

/** Веб-запрос: берёт id из куки или выдаёт новый (кука на год, HttpOnly, SameSite=Lax, Secure на HTTPS). */
function app_user_from_request() {
    $uid = $_COOKIE[USER_COOKIE] ?? '';
    $is_new = !is_valid_user_id($uid);
    if ($is_new) $uid = bin2hex(random_bytes(16));
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    if (PHP_SAPI !== 'cli' && !headers_sent()) {
        // Продлеваем куку при каждом заходе: активный пользователь не теряет данные.
        setcookie(USER_COOKIE, $uid, [
            'expires' => time() + USER_COOKIE_DAYS * 86400,
            'path' => rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/\\') . '/',
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }
    app_user_bind($uid);
    return $uid;
}

function users_ensure_root() {
    if (is_dir(USERS_DIR)) return;
    @mkdir(USERS_DIR, 0775, true);
    // Данные пользователей не должны отдаваться веб-сервером напрямую.
    @file_put_contents(USERS_DIR . '/.htaccess', "Require all denied\n");
    @file_put_contents(USERS_DIR . '/index.html', '');
}

/** Папка пользователя. Данные приложения до появления пользователей (один общий пул) достаются первому, кто зашёл. */
function user_dir($uid = null) {
    $uid = $uid ?? app_user_id();
    $dir = USERS_DIR . '/' . $uid;
    if (is_dir($dir)) return $dir;
    $first_user = !is_dir(USERS_DIR);
    users_ensure_root();
    if (!is_dir($dir) && !@mkdir($dir, 0775) && !is_dir($dir)) {
        throw new RuntimeException('Не удалось создать папку пользователя.');
    }
    if ($first_user) {
        foreach (['content_pool.json' => 'pool.json', 'content_history.json' => 'history.json', 'settings.json' => 'settings.json'] as $legacy => $name) {
            $source = __DIR__ . '/../' . $legacy;
            if (is_file($source) && !is_file($dir . '/' . $name)) @rename($source, $dir . '/' . $name);
        }
    }
    return $dir;
}

function pool_file($uid = null) { return user_dir($uid) . '/pool.json'; }
function history_file($uid = null) { return user_dir($uid) . '/history.json'; }
function settings_file($uid = null) { return user_dir($uid) . '/settings.json'; }
function connections_file($uid = null) { return user_dir($uid) . '/connections.json'; }   // подключённые соцсети (токены — только на сервере)

/** Отмечает заход пользователя (для удаления давно не заходивших). Не чаще раза в 10 минут. */
function user_touch() {
    $marker = user_dir() . '/last-seen';
    if (time() - (int)@filemtime($marker) < 600) return;
    @touch($marker);
    user_collect_garbage();
}

/** Удаляет папки пользователей, не заходивших дольше USER_RETENTION_DAYS. Раз в сутки. */
function user_collect_garbage() {
    $flag = USERS_DIR . '/.gc';
    if (time() - (int)@filemtime($flag) < 86400) return;
    @touch($flag);
    foreach (user_ids() as $uid) {
        $marker = USERS_DIR . '/' . $uid . '/last-seen';
        $seen = (int)(@filemtime($marker) ?: @filemtime(USERS_DIR . '/' . $uid));
        if ($uid === app_user_bind() || time() - $seen < USER_RETENTION_DAYS * 86400) continue;
        user_remove_directory(USERS_DIR . '/' . $uid);
    }
}

function user_remove_directory($dir) {
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $path = $dir . '/' . $name;
        is_dir($path) && !is_link($path) ? user_remove_directory($path) : @unlink($path);
    }
    @rmdir($dir);
}

function user_ids() {
    $ids = [];
    foreach (scandir(USERS_DIR) ?: [] as $name) {
        if (is_valid_user_id($name) && is_dir(USERS_DIR . '/' . $name)) $ids[] = $name;
    }
    return $ids;
}

/** Все записи пулов всех пользователей: нужны, чтобы кэш медиа не удалял файлы, которые ещё кому-то показываются. */
function all_users_pool_items($except_uid = null) {
    $items = [];
    if (!is_dir(USERS_DIR)) return $items;
    foreach (user_ids() as $uid) {
        if ($uid === $except_uid) continue;
        foreach (load_data(USERS_DIR . '/' . $uid . '/pool.json', []) as $item) $items[] = $item;
        $history = load_data(USERS_DIR . '/' . $uid . '/history.json', []);
        foreach (PROPOSAL_COLLECTIONS as $collection) {   // файлы записей из «Предложки» тоже нужны
            foreach ($history[$collection] ?? [] as $item) if (empty($item['proposal_hidden'])) $items[] = $item;
        }
    }
    return $items;
}
