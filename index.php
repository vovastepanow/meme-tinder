<?php
// index.php — JSON API для интерфейса (index.html, js/). Здесь только маршрутизация: логика в lib/ и handlers/.
require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/lib/http_json.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/parser_runner.php';
require_once __DIR__ . '/lib/database_editor.php';
require_once __DIR__ . '/lib/vk_oauth.php';

require_once __DIR__ . '/handlers/database.php';
require_once __DIR__ . '/handlers/content.php';
require_once __DIR__ . '/handlers/sources.php';

// Любая непойманная ошибка становится JSON-ответом, а не HTML-страницей PHP.
set_exception_handler(function (Throwable $error) {
    error_log(APP_NAME . ' API error: ' . $error);
    json_error('Внутренняя ошибка сервера: ' . $error->getMessage(), 500);
});

/**
 * action => [HTTP-метод, обработчик]. Обработчик получает ($input, $debug_mode, $action)
 * и всегда завершает запрос сам (json_response или редирект).
 */
const API_ROUTES = [
    'vk_oauth_status' => ['GET', 'route_vk_oauth_status'],
    'vk_oauth_start' => ['GET', 'route_vk_oauth_start'],
    'vk_oauth_callback' => ['GET', 'route_vk_oauth_callback'],
    'database_options' => ['GET', 'route_database_options'],
    'database_read' => ['GET', 'route_database_read'],
    'get_current_item' => ['GET', 'route_get_current_item'],
    'settings' => ['GET', 'route_settings'],
    'parser_status' => ['GET', 'route_parser_status'],

    'database_add' => ['POST', 'route_database_change'],
    'database_update' => ['POST', 'route_database_change'],
    'database_delete' => ['POST', 'route_database_change'],
    'database_reset' => ['POST', 'route_database_reset'],
    'analyze_image' => ['POST', 'route_analyze_image'],
    'vote' => ['POST', 'route_vote'],
    'refresh_content' => ['POST', 'route_refresh_content'],
    'save_topic' => ['POST', 'route_save_topic'],
    'suggest_sources' => ['POST', 'route_suggest_sources'],
    'source_vote' => ['POST', 'route_source_vote'],
    'debug_reset' => ['POST', 'route_debug_reset'],
];

// Тонкие адаптеры к единой сигнатуре маршрута.
function route_vk_oauth_status($input, $debug) { handle_vk_oauth_status($debug); }
function route_vk_oauth_start($input, $debug) { handle_vk_oauth_start($debug); }
function route_vk_oauth_callback($input, $debug) { handle_vk_oauth_callback(); }
function route_database_options($input, $debug) { handle_database_options($debug); }
function route_database_read($input, $debug) { handle_database_read($debug); }
function route_get_current_item($input, $debug) { handle_get_current_item($debug); }
function route_settings($input, $debug) { handle_get_settings($debug); }
function route_parser_status($input, $debug) { handle_parser_status($debug); }
function route_database_change($input, $debug, $action) { handle_database_change($action, $input, $debug); }
function route_database_reset($input, $debug) { handle_database_reset($debug); }
function route_analyze_image($input, $debug) { handle_analyze_image($input, $debug); }
function route_vote($input, $debug) { handle_vote($input, $debug); }
function route_refresh_content($input, $debug) { handle_refresh_content($debug); }
function route_save_topic($input, $debug) { handle_save_topic($input, $debug); }
function route_suggest_sources($input, $debug) { handle_suggest_sources($input, $debug); }
function route_source_vote($input, $debug) { handle_source_vote($input, $debug); }

function route_debug_reset($input, $debug) {
    if (!$debug) json_error('Сброс доступен только в демо-режиме.', 403);
    debug_reset_state();
    json_response(['status' => 'success', 'debug' => true]);
}

function dispatch_api_request() {
    $action = (string)($_GET['action'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $debug_mode = ($_GET['debug'] ?? '') === '1';

    $route = API_ROUTES[$action] ?? null;
    if ($route === null) json_error('Неизвестное действие API.', 404);
    [$route_method, $handler] = $route;
    if ($method !== $route_method) {
        header('Allow: ' . $route_method);
        json_error('Метод ' . $method . ' не поддерживается для этого действия.', 405);
    }

    $input = [];
    if ($method === 'POST') {
        require_api_request_header();
        $input = request_json_body();
    }

    if (!$debug_mode) ensure_default_project_data();
    $handler($input, $debug_mode, $action);
}

dispatch_api_request();
