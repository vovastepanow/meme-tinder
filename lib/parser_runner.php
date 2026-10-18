<?php
// Запуск фонового парсера (cron_parser.php) и чтение его журнала.

const PARSER_ACTIVITY_LINES = 400; // с потоковыми размышлениями строк в журнале заметно больше

/** Последние строки лога парсера для консоли. Строки не обрезаются: в них могут быть полные размышления модели. */
function content_parser_activity() {
    $lines = @file(parser_runtime_file('.log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];

    $activity = [];
    foreach (array_slice($lines, -PARSER_ACTIVITY_LINES) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || str_starts_with($line, 'PHP Warning:')) continue;
        $activity[] = $line;
    }
    return $activity;
}

function content_parser_process_running($pid) {
    if ($pid < 1) return false;
    $command_line = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($command_line !== false) {
        return strpos(str_replace("\0", ' ', $command_line), PARSER_SCRIPT) !== false;
    }
    return function_exists('posix_kill') && @posix_kill($pid, 0);
}

/**
 * Запускает парсер в фоне, если он ещё не работает. $force — запуск без учёта паузы между запусками.
 * Возвращает 'started', 'running', 'cooldown' или 'unavailable'.
 */
/** Работает ли сейчас фоновый парсер. */
function content_parser_is_running() {
    $state = json_decode((string)@file_get_contents(parser_runtime_file('.json')), true) ?: [];
    return content_parser_process_running((int)($state['pid'] ?? 0));
}

function start_content_parser_if_needed($force = false) {
    if (!function_exists('exec')) return 'unavailable';
    $state_file = parser_runtime_file('.json');
    $lock_handle = @fopen($state_file . '.lock', 'c+');
    if (!$lock_handle || !flock($lock_handle, LOCK_EX)) return 'unavailable';
    try {
        return launch_content_parser_locked($state_file, $force);
    } finally {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }
}

function launch_content_parser_locked($state_file, $force) {
    $state = json_decode(@file_get_contents($state_file), true) ?: [];
    if (content_parser_process_running((int)($state['pid'] ?? 0))) return 'running';
    if (!$force && !empty($state['started_at']) && time() - (int)$state['started_at'] < PARSER_COOLDOWN_SECONDS) return 'cooldown';

    $php_binary = PHP_BINARY;
    if ($php_binary === '' || !is_executable($php_binary)) $php_binary = '/usr/bin/php';
    if (!is_executable($php_binary)) return 'unavailable';

    $command = 'nohup ' . escapeshellarg($php_binary) . ' ' . escapeshellarg(PARSER_SCRIPT) .
        ' > ' . escapeshellarg(parser_runtime_file('.log')) . ' 2>&1 < /dev/null & echo $!';
    exec($command, $output, $exit_code);
    $pid = (int)trim($output[0] ?? '0');
    if ($exit_code !== 0 || $pid < 1) return 'unavailable';

    file_put_contents($state_file, json_encode(['pid' => $pid, 'started_at' => time()]), LOCK_EX);
    return 'started';
}
