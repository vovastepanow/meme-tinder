<?php
// Панель управления: остановка всех процессов приложения (сборщики контента, помощник Telegram, модели Ollama).
// Веб-сервер и сами данные не трогаются: после остановки страницу достаточно обновить.

/**
 * Процессы приложения, которые можно остановить: сборщики контента (cron_parser.php) и помощник Telegram (tg_user.py).
 * Возвращает [pid => строка команды]. Читается /proc, права не нужны.
 */
function app_process_list() {
    $found = [];
    $self = getmypid();
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        $pid = (int)substr($file, 6, -8);
        if ($pid === $self) continue;
        $command = str_replace("\0", ' ', (string)@file_get_contents($file));
        if ($command !== '' && (str_contains($command, PARSER_SCRIPT) || str_contains($command, 'tg_user.py'))) {
            $found[$pid] = trim($command);
        }
    }
    return $found;
}

/** Останавливает процессы приложения: сначала вежливо (SIGTERM), через пару секунд — жёстко. Возвращает число остановленных. */
function app_stop_processes() {
    $found = app_process_list();
    foreach (array_keys($found) as $pid) {
        if (function_exists('posix_kill')) @posix_kill($pid, 15);
        else @exec('kill -TERM ' . (int)$pid);
    }
    for ($i = 0; $i < 20 && app_process_list(); $i++) usleep(200000);
    foreach (array_keys(app_process_list()) as $pid) {
        if (function_exists('posix_kill')) @posix_kill($pid, 9);
        else @exec('kill -KILL ' . (int)$pid);
    }
    return count($found);
}

/** Выгружает модели проекта из памяти видеокарты (после остановки сборщиков они больше не нужны). */
function app_unload_models() {
    foreach (array_unique(array_merge(PRESCREEN_MODELS, [ANALYSIS_MODEL, SMART_MODEL])) as $model) {
        try {
            ollama_unload_model($model);
        } catch (Throwable $error) {
            // модель уже выгружена или Ollama недоступна — остановке приложения это не мешает
        }
    }
}

function handle_app_stop($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме остановка недоступна.', 403);
    $tab = request_tab_id($input);
    tab_session_close($tab);   // вкладка больше не ведёт сессию: сборщик не перезапустится сам
    $stopped = app_stop_processes();
    app_unload_models();
    json_response(['status' => 'success', 'stopped' => $stopped]);
}

/** POST: поиск контента — то же, что кнопка «Искать контент» на вкладке «Подбор». */
function handle_control_search($debug_mode) {
    handle_refresh_content($debug_mode);
}

/** POST: обновить базу источников — то же, что «Подобрать источники» на вкладке «Источники». */
function handle_control_sources($debug_mode) {
    handle_discover_sources($debug_mode);
}
