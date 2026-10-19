<?php
// Лимиты Telegram на размер видео и их самообучение. Цифры из документации (50 МБ у Bot API, 2 ГБ у аккаунта) могут измениться,
// поэтому проверка не считает их истиной: после каждой отправки приложение запоминает, какой размер реально приняли (ok_max)
// и на каком отказали (fail_min). Файл больше известного, но не доказанно запрещённого, помечается «рискованным» — его всё равно
// можно попробовать отправить, и результат уточнит лимит. Запоминание — telegram_limits.json рядом с config.php.

const TELEGRAM_LIMIT_DEFAULTS = ['bot' => 50 * 1024 * 1024, 'account' => 2000 * 1024 * 1024];   // по документации; account — для отложки через аккаунт
const TELEGRAM_LIMITS_FILE = __DIR__ . '/../telegram_limits.json';

function telegram_limits_state() {
    $state = load_data(TELEGRAM_LIMITS_FILE, []);
    return is_array($state) ? $state : [];
}

/** Что известно про лимит видео для пути отправки ($route: bot | account): ['default', 'ok_max', 'fail_min'] в байтах (0 — не известно). */
function telegram_video_limit($route = 'bot') {
    $known = telegram_limits_state()[$route]['video'] ?? [];
    return ['default' => TELEGRAM_LIMIT_DEFAULTS[$route] ?? TELEGRAM_LIMIT_DEFAULTS['bot'], 'ok_max' => (int)($known['ok_max'] ?? 0), 'fail_min' => (int)($known['fail_min'] ?? 0)];
}

/**
 * Можно ли отправить видео такого размера: 'ok' (по документации или уже принимали), 'risky' (больше известного — пробуем),
 * 'blocked' (Telegram уже отказывал на таком размере или приложение не умеет грузить столько).
 */
function telegram_video_verdict($bytes, $route = 'bot') {
    $limit = telegram_video_limit($route);
    if ($limit['fail_min'] > 0 && $bytes >= $limit['fail_min']) return 'blocked';
    if ($bytes <= max($limit['default'], $limit['ok_max'])) return 'ok';
    return $bytes <= TELEGRAM_UPLOAD_MAX_BYTES ? 'risky' : 'blocked';
}

/** Текст про размер видео для сообщений: «весит 62 МБ; Telegram принимает до 50 МБ». */
function telegram_video_limit_text($bytes, $route = 'bot') {
    $limit = telegram_video_limit($route);
    $known = $limit['fail_min'] > 0 ? $limit['fail_min'] - 1 : max($limit['default'], $limit['ok_max']);
    if ($bytes > TELEGRAM_UPLOAD_MAX_BYTES && $bytes > $known) return sprintf('весит %.0f МБ, а приложение загружает не больше %d МБ', $bytes / 1048576, TELEGRAM_UPLOAD_MAX_BYTES >> 20);
    return sprintf('весит %.0f МБ, Telegram принимает до %.0f МБ', $bytes / 1048576, $known / 1048576);
}

function telegram_video_limit_update($route, callable $change) {
    update_data(TELEGRAM_LIMITS_FILE, [], function ($state) use ($route, $change) {
        $state = is_array($state) ? $state : [];
        $entry = ['ok_max' => (int)($state[$route]['video']['ok_max'] ?? 0), 'fail_min' => (int)($state[$route]['video']['fail_min'] ?? 0)];
        $state[$route]['video'] = $change($entry) + ['updated' => time()];
        return $state;
    });
    @chmod(TELEGRAM_LIMITS_FILE, 0664);
}

/** Видео такого размера приняли: лимит не меньше. Если на этом размере раньше отказывали — лимит, видимо, подняли. */
function telegram_video_limit_success($bytes, $route = 'bot') {
    if ($bytes <= 0) return;
    $limit = telegram_video_limit($route);
    if ($bytes <= $limit['ok_max'] && ($limit['fail_min'] === 0 || $bytes < $limit['fail_min'])) return;   // ничего нового
    telegram_video_limit_update($route, function ($entry) use ($bytes) {
        $entry['ok_max'] = max($entry['ok_max'], $bytes);
        if ($entry['fail_min'] > 0 && $bytes >= $entry['fail_min']) $entry['fail_min'] = 0;
        return $entry;
    });
}

/** Видео такого размера не приняли («слишком большой»): лимит ниже. Если раньше принимали больше — лимит, видимо, снизили. */
function telegram_video_limit_failure($bytes, $route = 'bot') {
    if ($bytes <= 0) return;
    telegram_video_limit_update($route, function ($entry) use ($bytes) {
        $entry['fail_min'] = $entry['fail_min'] > 0 ? min($entry['fail_min'], $bytes) : $bytes;
        if ($entry['ok_max'] >= $entry['fail_min']) $entry['ok_max'] = $entry['fail_min'] - 1;
        return $entry;
    });
}
