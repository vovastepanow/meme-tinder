<?php
// index.php — JSON API для интерфейса (index.html, js/). Здесь только маршрутизация: логика в lib/ и handlers/.
require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/lib/http_json.php';
require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/database_editor.php';
require_once __DIR__ . '/lib/vk_oauth.php';

require_once __DIR__ . '/handlers/database.php';
require_once __DIR__ . '/handlers/content.php';
require_once __DIR__ . '/handlers/sources.php';
require_once __DIR__ . '/handlers/model.php';
require_once __DIR__ . '/handlers/account.php';
require_once __DIR__ . '/handlers/control.php';
require_once __DIR__ . '/handlers/publish.php';
require_once __DIR__ . '/handlers/telegram_user.php';
require_once __DIR__ . '/handlers/proposals.php';

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
    'topics' => ['GET', 'route_topics'],
    'topic_sources' => ['GET', 'route_topic_sources'],
    'discovery_status' => ['GET', 'route_discovery_status'],
    'parser_status' => ['GET', 'route_parser_status'],
    'model_status' => ['GET', 'route_model_status'],
    'account_info' => ['GET', 'route_account_info'],
    'telegram_queue' => ['GET', 'route_telegram_queue'],
    'proposals' => ['GET', 'route_proposals'],
    'tab_open' => ['POST', 'route_tab_open'],
    'tab_heartbeat' => ['POST', 'route_tab_heartbeat'],
    'tab_close' => ['POST', 'route_tab_close'],

    'database_add' => ['POST', 'route_database_change'],
    'database_update' => ['POST', 'route_database_change'],
    'database_delete' => ['POST', 'route_database_change'],
    'database_reset' => ['POST', 'route_database_reset'],
    'analyze_image' => ['POST', 'route_analyze_image'],
    'vote' => ['POST', 'route_vote'],
    'refresh_content' => ['POST', 'route_refresh_content'],
    'save_topic' => ['POST', 'route_save_topic'],
    'topic_parent' => ['POST', 'route_topic_parent'],
    'remove_topic_source' => ['POST', 'route_remove_topic_source'],
    'topic_reprofile' => ['POST', 'route_topic_reprofile'],
    'save_second_topic' => ['POST', 'route_save_second_topic'],
    'control_search' => ['POST', 'route_control_search'],
    'control_sources' => ['POST', 'route_control_sources'],
    'app_stop' => ['POST', 'route_app_stop'],
    'save_filters' => ['POST', 'route_save_filters'],
    'suggest_sources' => ['POST', 'route_suggest_sources'],
    'source_vote' => ['POST', 'route_source_vote'],
    'discover_sources' => ['POST', 'route_discover_sources'],
    'debug_reset' => ['POST', 'route_debug_reset'],
    'model_control' => ['POST', 'route_model_control'],
    'telegram_connect' => ['POST', 'route_telegram_connect'],
    'telegram_disconnect' => ['POST', 'route_telegram_disconnect'],
    'telegram_publish' => ['POST', 'route_telegram_publish'],
    'telegram_cancel' => ['POST', 'route_telegram_cancel'],
    'telegram_user_start' => ['POST', 'route_telegram_user_start'],
    'telegram_user_confirm' => ['POST', 'route_telegram_user_confirm'],
    'telegram_user_qr_start' => ['POST', 'route_telegram_user_qr_start'],
    'telegram_user_qr_status' => ['GET', 'route_telegram_user_qr_status'],
    'telegram_user_resend' => ['POST', 'route_telegram_user_resend'],
    'telegram_user_disconnect' => ['POST', 'route_telegram_user_disconnect'],
    'proposal_hide' => ['POST', 'route_proposal_hide'],
];

// Тонкие адаптеры к единой сигнатуре маршрута.
function route_vk_oauth_status($input, $debug) { handle_vk_oauth_status($debug); }
function route_vk_oauth_start($input, $debug) { handle_vk_oauth_start($debug); }
function route_vk_oauth_callback($input, $debug) { handle_vk_oauth_callback(); }
function route_database_options($input, $debug) { handle_database_options($debug); }
function route_database_read($input, $debug) { handle_database_read($debug); }
function route_telegram_user_start($input, $debug) { handle_telegram_user_start($input, $debug); }
function route_telegram_user_resend($input, $debug) { handle_telegram_user_resend($debug); }
function route_telegram_user_qr_start($input, $debug) { handle_telegram_user_qr_start($input, $debug); }
function route_telegram_user_qr_status($input, $debug) { handle_telegram_user_qr_status($debug); }
function route_telegram_user_confirm($input, $debug) { handle_telegram_user_confirm($input, $debug); }
function route_telegram_user_disconnect($input, $debug) { handle_telegram_user_disconnect($debug); }
function route_proposals($input, $debug) { handle_proposals($debug); }
function route_proposal_hide($input, $debug) { handle_proposal_hide($input, $debug); }
function route_get_current_item($input, $debug) { handle_get_current_item($debug); }
function route_topics($input, $debug) { handle_topics($debug); }
function route_settings($input, $debug) { handle_get_settings($debug); }
function route_parser_status($input, $debug) { handle_parser_status($debug); }
function route_tab_open($input, $debug) { handle_tab_open($input, $debug); }
function route_tab_heartbeat($input, $debug) { handle_tab_heartbeat($input, $debug); }
function route_tab_close($input, $debug) { handle_tab_close($input, $debug); }
function route_database_change($input, $debug, $action) { handle_database_change($action, $input, $debug); }
function route_database_reset($input, $debug) { handle_database_reset($debug); }
function route_analyze_image($input, $debug) { handle_analyze_image($input, $debug); }
function route_vote($input, $debug) { handle_vote($input, $debug); }
function route_refresh_content($input, $debug) { handle_refresh_content($debug); }
function route_save_topic($input, $debug) { handle_save_topic($input, $debug); }
function route_topic_parent($input, $debug) { handle_topic_parent($input, $debug); }
function route_topic_sources($input, $debug) { handle_topic_sources($debug); }
function route_remove_topic_source($input, $debug) { handle_remove_topic_source($input, $debug); }
function route_topic_reprofile($input, $debug) { handle_topic_reprofile($input, $debug); }
function route_save_second_topic($input, $debug) { handle_save_second_topic($input, $debug); }
function route_control_search($input, $debug) { handle_control_search($debug); }
function route_control_sources($input, $debug) { handle_control_sources($debug); }
function route_app_stop($input, $debug) { handle_app_stop($input, $debug); }
function route_save_filters($input, $debug) { handle_save_filters($input, $debug); }
function route_suggest_sources($input, $debug) { handle_suggest_sources($input, $debug); }
function route_source_vote($input, $debug) { handle_source_vote($input, $debug); }
function route_discover_sources($input, $debug) { handle_discover_sources($debug); }
function route_discovery_status($input, $debug) { handle_discovery_status($debug); }
function route_model_status($input, $debug) { handle_model_status($debug); }
function route_model_control($input, $debug) { handle_model_control($input, $debug); }
function route_account_info($input, $debug) { handle_account_info($debug); }
function route_telegram_connect($input, $debug) { handle_telegram_connect($input, $debug); }
function route_telegram_disconnect($input, $debug) { handle_telegram_disconnect($debug); }
function route_telegram_queue($input, $debug) { handle_telegram_queue($debug); }
function route_telegram_publish($input, $debug) { handle_telegram_publish($input, $debug); }
function route_telegram_cancel($input, $debug) { handle_telegram_cancel($input, $debug); }

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

    if (!$debug_mode) {
        app_user_from_request();
        ensure_default_project_data();
        user_touch();
    }
    $handler($input, $debug_mode, $action);
}

dispatch_api_request();
