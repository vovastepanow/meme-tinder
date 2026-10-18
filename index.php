<?php
// index.php — JSON API для фронтенда (index.html / js/). Только маршрутизация: логика в lib/ и handlers/.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/sources/common.php';
require_once __DIR__ . '/sources/dvach.php';
require_once __DIR__ . '/sources/telegram.php';
require_once __DIR__ . '/sources/vk.php';
require_once __DIR__ . '/image_input.php';
require_once __DIR__ . '/meme_analysis.php';

require_once __DIR__ . '/lib/demo.php';
require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/parser_runner.php';
require_once __DIR__ . '/lib/memes.php';
require_once __DIR__ . '/lib/database_editor.php';
require_once __DIR__ . '/lib/http_json.php';
require_once __DIR__ . '/lib/vk_oauth.php';

require_once __DIR__ . '/handlers/database.php';
require_once __DIR__ . '/handlers/memes.php';
require_once __DIR__ . '/handlers/sources.php';

// ---- Dispatch ----

$action = $_GET['action'] ?? '';
$debug_mode = ($_GET['debug'] ?? '') === '1';
ensure_default_project_data();

if ($debug_mode) {
    ensure_session_started();
    if (!isset($_SESSION['meme_tinder_debug'])) {
        $_SESSION['meme_tinder_debug'] = debug_initial_state();
    }
}

// Every handler terminates the request (json_response / redirect), so cases need no break.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    switch ($action) {
        case 'vk_oauth_status': handle_vk_oauth_status($debug_mode);
        case 'vk_oauth_start': handle_vk_oauth_start($debug_mode);
        case 'vk_oauth_callback': handle_vk_oauth_callback();
        case 'database_options': handle_database_options($debug_mode);
        case 'database_read': handle_database_read($debug_mode);
        case 'get_current_meme': handle_get_current_meme($debug_mode);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($action) {
        case 'database_add':
        case 'database_update':
        case 'database_delete':
            handle_database_change($action, $input, $debug_mode);
        case 'analyze_image': handle_analyze_image($input, $debug_mode);
        case 'debug_reset':
            if ($debug_mode) {
                $_SESSION['meme_tinder_debug'] = debug_initial_state();
                json_response(['status' => 'success', 'debug' => true]);
            }
            break;
        case 'meme_vote': handle_meme_vote($input, $debug_mode);
        case 'suggest_sources': handle_suggest_sources($input);
        case 'source_vote': handle_source_vote($input);
        case 'refresh_memes': handle_refresh_memes($debug_mode);
    }
}
