<?php
// Ответы API и общие проверки запросов.
function json_response($data, $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json');
    if (is_array($data) && !array_is_list($data) && ai_exchange_log()) $data['ai_log'] = ai_exchange_log();
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect_to_source_tab($vk_result): never {
    header('Location: index.html?vk=' . $vk_result . '#source-tab');
    exit;
}

function ensure_session_started() {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

function require_editable_databases($debug_mode) {
    if ($debug_mode) throw new RuntimeException('Редактирование баз отключено в демо-режиме.');
}
