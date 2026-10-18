<?php
// Ответы API и общие проверки запросов.

/** Заголовок, который интерфейс ставит на каждый POST. Чужой сайт не может отправить его без CORS-разрешения. */
const API_REQUEST_HEADER = 'HTTP_X_MEME_TINDER';

const VK_REDIRECT_RESULTS = ['connected', 'misconfigured', 'error'];

function json_response($data, $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (is_array($data) && !array_is_list($data) && ai_exchange_log()) $data['ai_log'] = ai_exchange_log();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function json_error($message, $status = 400): never {
    json_response(['status' => 'error', 'message' => $message], $status);
}

function redirect_to_source_tab($vk_result): never {
    if (!in_array($vk_result, VK_REDIRECT_RESULTS, true)) $vk_result = 'error';
    header('Location: index.html?vk=' . $vk_result . '#source-tab');
    exit;
}

function ensure_session_started() {
    if (session_status() === PHP_SESSION_NONE) session_start();
}

/** Защита от CSRF: изменяющие запросы принимаются только от собственного интерфейса. */
function require_api_request_header() {
    if (($_SERVER[API_REQUEST_HEADER] ?? '') !== '1') {
        json_error('Запрос отклонён: отсутствует заголовок X-Meme-Tinder.', 403);
    }
}

/** Тело POST-запроса как массив (JSON). Для multipart-форм возвращает пустой массив. */
function request_json_body() {
    $content_type = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with($content_type, 'application/json')) return [];
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) json_error('Тело запроса должно быть JSON-объектом.');
    return $input;
}

function require_editable_databases($debug_mode) {
    if ($debug_mode) throw new RuntimeException('Редактирование баз отключено в демо-режиме.');
}
