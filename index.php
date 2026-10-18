<?php
// index.php — JSON API for the frontend (index.html / script.js).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/source_adapters.php';
require_once __DIR__ . '/image_input.php';
require_once __DIR__ . '/meme_analysis.php';

// ---- Demo mode and default data ----

function debug_image($title, $background, $accent) {
    $title = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><rect width="640" height="480" fill="' . $background . '"/><circle cx="510" cy="90" r="145" fill="' . $accent . '"/><path d="M0 370 Q160 280 320 370 T640 340 V480 H0Z" fill="#171717"/><text x="42" y="190" fill="#fff" font-family="sans-serif" font-size="42" font-weight="700">' . $title . '</text><text x="44" y="245" fill="#fff" font-family="sans-serif" font-size="22">ТЕСТОВЫЙ МЕМ</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function debug_initial_state() {
    return [
        'pool' => [
            ['image' => debug_image('ПОНЕДЕЛЬНИК', '#176b87', '#e95d3f'), 'text' => 'План на утро: стать продуктивным. Факт: уже обед.', 'ai_description' => 'Человек строит амбициозные планы, пока время незаметно уходит.', 'rating' => 91, 'source' => 'Демо-сообщество'],
            ['image' => debug_image('ОЖИДАНИЕ / РЕАЛЬНОСТЬ', '#58734b', '#f1c453'), 'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.', 'ai_description' => 'Шутка о привычке отвлекаться в интернете.', 'rating' => 76, 'source' => 'Локальный тест']
        ],
        'history' => empty_history(),
        'source_index' => 0,
        'source_votes' => []
    ];
}

function create_demo_pool() {
    return [
        [
            'image' => 'https://images.unsplash.com/photo-1517849845537-4d257902454a?auto=format&fit=crop&w=1200&q=80',
            'text' => 'План на утро: стать продуктивным. Факт: уже обед.',
            'ai_description' => 'Шутка про оптимистичные обещания и реальную жизнь, где время уходит быстрее, чем кажется.',
            'rating' => 92,
            'source' => 'Демо-сообщество'
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1493246507139-91e8fad9978e?auto=format&fit=crop&w=1200&q=80',
            'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.',
            'ai_description' => 'Мем про бесконечную прокрутку и неспособность удержать фокус.',
            'rating' => 84,
            'source' => 'Локальный тест'
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80',
            'text' => 'Когда код работает с первого раза, а потом ты всё равно проверяешь его ещё раз.',
            'ai_description' => 'Реалистичная ирония про разработчика, который не может доверять счастью без перепроверки.',
            'rating' => 88,
            'source' => 'Демо-мемы'
        ]
    ];
}

function create_default_source_catalog() {
    return [
        '2ch_boards' => ['b', 'me', 'pr', 'r', 'cc', 'a', 'mu', 'd', 's', 'b2'],
        'vk_publics' => [
            ['id' => 'mrzlk', 'name' => 'MDK'],
            ['id' => 'leprum', 'name' => 'Лепрекон'],
            ['id' => 'sci_one', 'name' => 'Умный Юмор'],
            ['id' => 'british_humor', 'name' => 'БРИТИШ ЮМОР'],
            ['id' => 'bessm_humor', 'name' => 'ОВСЯНКА, СЭР!'],
            ['id' => 'memes_2024', 'name' => 'Мемы на каждый день']
        ],
        'telegram_channels' => [
            ['id' => 'memesdaily', 'name' => 'Мемы каждый день'],
            ['id' => 'this_is_not_funny', 'name' => 'Это не смешно'],
            ['id' => 'humorhub', 'name' => 'Humor Hub'],
            ['id' => 'trashmemes', 'name' => 'Trash memes'],
            ['id' => 'russmemes', 'name' => 'Русские мемы'],
            ['id' => 'memechi', 'name' => 'Meme Chi']
        ],
        'source_votes' => []
    ];
}

function ensure_default_project_data() {
    if (!is_file(HISTORY_FILE)) {
        save_data(HISTORY_FILE, empty_history());
    }

    if (!is_file(SOURCES_FILE)) {
        save_data(SOURCES_FILE, create_default_source_catalog());
    } else {
        $source_db = load_data(SOURCES_FILE, create_default_source_catalog());
        $normalized = normalize_source_db($source_db);
        if ($source_db !== $normalized) {
            save_data(SOURCES_FILE, $normalized);
        }
    }

    if (!is_file(POOL_FILE)) {
        save_data(POOL_FILE, create_demo_pool());
    }
}

function normalize_source_db($db) {
    $defaults = create_default_source_catalog();
    $normalized = $defaults;

    foreach (['2ch_boards', 'vk_publics', 'telegram_channels'] as $key) {
        $normalized[$key] = is_array($db[$key] ?? null) ? array_values($db[$key]) : $defaults[$key];
    }

    $votes = is_array($db['source_votes'] ?? null) ? $db['source_votes'] : [];
    $normalized['source_votes'] = array_values(array_filter($votes, function($vote) {
        return is_array($vote) && !empty($vote['source_id'] ?? '');
    }));

    return $normalized;
}

function source_vote_exists($db, $source_id) {
    foreach (($db['source_votes'] ?? []) as $vote) {
        if (($vote['source_id'] ?? null) === $source_id) {
            return true;
        }
    }
    return false;
}

// ---- Background parser ----

/** Последние строки лога парсера для консоли. Строки не обрезаются: в них могут быть полные размышления модели. */
function meme_parser_activity() {
    $lines = @file(parser_runtime_file('.log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return [];

    $activity = [];
    foreach (array_slice($lines, -200) as $line) {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || str_starts_with($line, 'PHP Warning:')) continue;
        $activity[] = $line;
    }
    return array_slice($activity, -200);
}

function meme_parser_process_running($pid) {
    if ($pid < 1) return false;
    $command_line = @file_get_contents('/proc/' . $pid . '/cmdline');
    if ($command_line !== false) {
        return strpos(str_replace("\0", ' ', $command_line), PARSER_SCRIPT) !== false;
    }
    return function_exists('posix_kill') && @posix_kill($pid, 0);
}

function start_meme_parser_if_needed($force = false) {
    if (!function_exists('exec')) return 'unavailable';
    $state_file = parser_runtime_file('.json');
    $lock_handle = @fopen($state_file . '.lock', 'c+');
    if (!$lock_handle || !flock($lock_handle, LOCK_EX)) return 'unavailable';
    try {
        return launch_meme_parser_locked($state_file, $force);
    } finally {
        flock($lock_handle, LOCK_UN);
        fclose($lock_handle);
    }
}

function launch_meme_parser_locked($state_file, $force) {
    $state = json_decode(@file_get_contents($state_file), true) ?: [];
    if (meme_parser_process_running((int)($state['pid'] ?? 0))) return 'running';
    if (!$force && !empty($state['started_at']) && time() - (int)$state['started_at'] < 600) return 'cooldown';

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

function source_post_url($meme) {
    $existing_url = parse_url($meme['source_url'] ?? '');
    if (($existing_url['scheme'] ?? '') === 'https' && in_array(strtolower($existing_url['host'] ?? ''), ['2ch.hk', 't.me', 'vk.com'], true)) {
        return $meme['source_url'];
    }

    $board = (string)($meme['board'] ?? '');
    if ($board === '' && preg_match('~Двач\s+/([a-z0-9_-]+)/~iu', (string)($meme['source'] ?? ''), $match)) {
        $board = $match[1];
    }
    $thread_id = (string)($meme['thread_id'] ?? '');
    $post_id = (string)($meme['post_id'] ?? '');
    if (!is_valid_dvach_board($board) || !is_valid_dvach_id($thread_id) || !is_valid_dvach_id($post_id)) {
        return '';
    }
    return dvach_post_url($board, $thread_id, $post_id);
}

// ---- Database editor ----

function database_editor_definitions() {
    return [
        'memes_pool' => [
            'label' => 'Пул мемов',
            'file' => POOL_FILE,
            'collections' => ['items' => 'Мемы']
        ],
        'meme_history' => [
            'label' => 'История оценок',
            'file' => HISTORY_FILE,
            'collections' => [
                'liked_memes' => 'Лайки',
                'disliked_memes' => 'Дизлайки',
                'undecided_memes' => 'Без решения',
                'wrong_topic_memes' => 'Жалобы'
            ]
        ],
        'sources_db' => [
            'label' => 'Каталог источников',
            'file' => SOURCES_FILE,
            'collections' => [
                '2ch_boards' => 'Доски 2ch',
                'vk_publics' => 'Паблики ВКонтакте',
                'telegram_channels' => 'Каналы Telegram',
                'source_votes' => 'Оценки источников'
            ]
        ]
    ];
}

function database_editor_load($database) {
    $definitions = database_editor_definitions();
    if (!isset($definitions[$database])) throw new InvalidArgumentException('Неизвестная база данных.');
    $raw = file_get_contents($definitions[$database]['file']);
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('Файл базы содержит некорректный JSON.');
    }
    return [$definitions[$database], $data];
}

function database_editor_collection($database, $definition, $data, $collection) {
    if (!array_key_exists($collection, $definition['collections'])) {
        throw new InvalidArgumentException('Неизвестная коллекция.');
    }
    $items = $database === 'memes_pool' ? $data : ($data[$collection] ?? []);
    if (!is_array($items)) throw new RuntimeException('Коллекция должна быть массивом записей.');
    return array_values($items);
}

function database_editor_save($database, $definition, $data, $collection, $items) {
    if ($database === 'memes_pool') {
        $data = array_values($items);
    } else {
        $data[$collection] = array_values($items);
    }
    save_data($definition['file'], $data);
}

function database_editor_validate_record($database, $collection, $record) {
    if ($database === 'memes_pool') {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['image'] ?? '')) === '') {
            throw new InvalidArgumentException('Мему нужны поля-объект и непустой image.');
        }
        if (isset($record['rating']) && (!is_numeric($record['rating']) || $record['rating'] < 0 || $record['rating'] > 100)) {
            throw new InvalidArgumentException('rating должен быть числом от 0 до 100.');
        }
        return;
    }

    if (in_array($collection, HISTORY_COLLECTIONS, true)) {
        if (!is_array($record) || array_is_list($record) || !is_string($record['text'] ?? null) || !is_numeric($record['rating_assigned'] ?? null)) {
            throw new InvalidArgumentException('Для записи истории нужны объект, строка text и число rating_assigned.');
        }
        return;
    }

    if ($database === 'sources_db' && $collection === '2ch_boards') {
        if (!is_string($record) || !preg_match('/^[a-z0-9_-]{1,30}$/i', $record)) {
            throw new InvalidArgumentException('Доска 2ch должна содержать только буквы, цифры, _ или - (до 30 символов).');
        }
        return;
    }

    if ($database === 'sources_db' && in_array($collection, ['vk_publics', 'telegram_channels'], true)) {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['id'] ?? '')) === '' || trim((string)($record['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Источнику нужны непустые поля id и name.');
        }
        return;
    }

    if ($database === 'sources_db' && $collection === 'source_votes') {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['source_id'] ?? '')) === '' || !in_array($record['vote'] ?? '', ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Оценке источника нужны source_id и vote: approve или reject.');
        }
    }
}

// ---- HTTP helpers ----

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

function load_history() {
    return load_data(HISTORY_FILE, empty_history());
}

function load_source_db() {
    return normalize_source_db(load_data(SOURCES_FILE, create_default_source_catalog()));
}

// ---- VK OAuth ----

function handle_vk_oauth_status($debug_mode) {
    $config = vk_oauth_config();
    $tokens = load_vk_tokens();
    json_response([
        'configured' => !$debug_mode && $config['client_id'] !== '' && $config['redirect_uri'] !== '',
        'connected' => !$debug_mode && !empty($tokens['refresh_token']),
        'user_id' => $debug_mode ? null : ($tokens['user_id'] ?? null)
    ]);
}

function handle_vk_oauth_start($debug_mode) {
    $config = vk_oauth_config();
    if ($debug_mode || $config['client_id'] === '' || $config['redirect_uri'] === '') {
        redirect_to_source_tab('misconfigured');
    }
    ensure_session_started();
    $state = bin2hex(random_bytes(24));
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $_SESSION['vk_oauth'] = [
        'state' => $state,
        'code_verifier' => $verifier,
        'started_at' => time()
    ];
    $parameters = [
        'response_type' => 'code',
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'state' => $state,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'lang_id' => '0'
    ];
    if ($config['scope'] !== '') $parameters['scope'] = $config['scope'];
    header('Location: https://id.vk.ru/authorize?' . http_build_query($parameters), true, 302);
    exit;
}

function handle_vk_oauth_callback() {
    ensure_session_started();
    $callback = $_GET;
    if (isset($callback['payload'])) {
        $payload = json_decode((string)$callback['payload'], true);
        if (is_array($payload)) $callback = array_merge($callback, $payload);
    }
    $oauth_state = $_SESSION['vk_oauth'] ?? [];
    unset($_SESSION['vk_oauth']);
    $state = (string)($callback['state'] ?? '');
    $code = (string)($callback['code'] ?? '');
    $device_id = (string)($callback['device_id'] ?? '');
    $config = vk_oauth_config();
    if ((int)($oauth_state['started_at'] ?? 0) < time() - 600 || empty($oauth_state['state']) || !hash_equals((string)$oauth_state['state'], $state)) {
        redirect_to_source_tab('error');
    }
    if ($code === '' || $device_id === '' || $config['client_id'] === '' || $config['redirect_uri'] === '') {
        redirect_to_source_tab('error');
    }
    try {
        $parameters = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => (string)$oauth_state['code_verifier'],
            'client_id' => $config['client_id'],
            'device_id' => $device_id,
            'redirect_uri' => $config['redirect_uri'],
            'state' => $state
        ];
        if ($config['service_token'] !== '') $parameters['service_token'] = $config['service_token'];
        $tokens = vk_oauth_post('https://id.vk.ru/oauth2/auth', $parameters);
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new RuntimeException('VK ID не вернул access_token и refresh_token.');
        }
        $tokens['device_id'] = $device_id;
        $tokens['expires_at'] = time() + max(0, (int)($tokens['expires_in'] ?? 0));
        save_vk_tokens($tokens);
        session_regenerate_id(true);
        redirect_to_source_tab('connected');
    } catch (Throwable $error) {
        error_log('VK OAuth callback failed: ' . $error->getMessage());
        redirect_to_source_tab('error');
    }
}

// ---- Database editor ----

function handle_database_options($debug_mode) {
    if ($debug_mode) json_response(['message' => 'Редактирование баз отключено в демо-режиме.'], 403);
    $databases = [];
    foreach (database_editor_definitions() as $id => $definition) {
        $collections = [];
        foreach ($definition['collections'] as $collection_id => $label) {
            $collections[] = ['id' => $collection_id, 'label' => $label];
        }
        $databases[] = ['id' => $id, 'label' => $definition['label'], 'collections' => $collections];
    }
    json_response(['databases' => $databases]);
}

function handle_database_read($debug_mode) {
    try {
        require_editable_databases($debug_mode);
        $database = $_GET['database'] ?? '';
        $collection = $_GET['collection'] ?? '';
        [$definition, $data] = database_editor_load($database);
        $items = database_editor_collection($database, $definition, $data, $collection);
        json_response([
            'database_label' => $definition['label'],
            'collection_label' => $definition['collections'][$collection],
            'items' => $items
        ]);
    } catch (Throwable $error) {
        json_response(['message' => $error->getMessage()], 400);
    }
}

function database_editor_require_record($input) {
    if (!array_key_exists('record', $input)) throw new InvalidArgumentException('Не переданы данные записи.');
    return $input['record'];
}

function database_editor_assert_same_shape($existing, $record) {
    if (is_array($existing) !== is_array($record)) {
        throw new InvalidArgumentException('Нельзя менять тип записи.');
    }
    if (is_array($existing) && array_is_list($existing) !== array_is_list($record)) {
        throw new InvalidArgumentException('Тип структуры записи не совпадает.');
    }
    if (!is_array($existing) && gettype($existing) !== gettype($record)) {
        throw new InvalidArgumentException('Нельзя менять тип значения записи.');
    }
}

function handle_database_change($action, $input, $debug_mode) {
    try {
        require_editable_databases($debug_mode);
        if (!is_array($input)) throw new InvalidArgumentException('Некорректный запрос.');
        $database = $input['database'] ?? '';
        $collection = $input['collection'] ?? '';
        [$definition, $data] = database_editor_load($database);
        $items = database_editor_collection($database, $definition, $data, $collection);

        if ($action === 'database_add') {
            $record = database_editor_require_record($input);
            database_editor_validate_record($database, $collection, $record);
            $items[] = $record;
        } else {
            $index = filter_var($input['index'] ?? null, FILTER_VALIDATE_INT);
            if ($index === false || $index < 0) throw new InvalidArgumentException('Некорректный номер записи.');
            if (!array_key_exists($index, $items)) throw new OutOfBoundsException('Запись уже удалена или не существует.');

            if ($action === 'database_update') {
                $record = database_editor_require_record($input);
                database_editor_assert_same_shape($items[$index], $record);
                database_editor_validate_record($database, $collection, $record);
                $items[$index] = $record;
            } else {
                array_splice($items, $index, 1);
            }
        }

        database_editor_save($database, $definition, $data, $collection, $items);
        json_response(['status' => 'success', 'count' => count($items)]);
    } catch (Throwable $error) {
        json_response(['status' => 'error', 'message' => $error->getMessage()], 400);
    }
}

// ---- Memes ----

function handle_get_current_meme($debug_mode) {
    $pool = $debug_mode ? $_SESSION['meme_tinder_debug']['pool'] : load_data(POOL_FILE, []);
    if (empty($pool)) {
        $parser_status = $debug_mode ? 'cooldown' : start_meme_parser_if_needed();
        if (in_array($parser_status, ['started', 'running'], true)) {
            json_response(['status' => 'loading', 'message' => 'Ищем посты...', 'activity' => meme_parser_activity()]);
        }
        json_response(['status' => 'empty', 'message' => 'Новых мемов пока нет. Можно повторить поиск или добавить запись вручную.']);
    }
    $meme = $pool[0];
    $meme['images'] = [$meme['image']];
    $meme['captions'] = [$meme['captions'][0] ?? ($meme['text'] ?? '')];
    $meme['source_url'] = source_post_url($meme);
    json_response($meme);
}

function handle_analyze_image($input, $debug_mode) {
    try {
        $image_data = read_request_image($input, 'Не удалось загрузить файл.', 'Добавьте ссылку или выберите файл изображения.');
        $history = $debug_mode ? $_SESSION['meme_tinder_debug']['history'] : load_history();
        json_response(analyze_image_local($image_data, $history));
    } catch (Throwable $error) {
        json_response(['status' => 'error', 'message' => $error->getMessage()], 400);
    }
}

/** Skips a meme without rating it: moves it to the end of the pool and writes nothing to history. */
function handle_meme_postpone($meme, $debug_mode): never {
    $image = !empty($meme['image']) ? $meme['image'] : ($meme['images'][0] ?? '');
    $move_to_end = function(array $pool) use ($image) {
        $postponed = array_filter($pool, function($item) use ($image) { return ($item['image'] ?? '') === $image; });
        $rest = array_filter($pool, function($item) use ($image) { return ($item['image'] ?? '') !== $image; });
        return array_values(array_merge($rest, $postponed));
    };
    if ($debug_mode) {
        $_SESSION['meme_tinder_debug']['pool'] = $move_to_end($_SESSION['meme_tinder_debug']['pool']);
    } else {
        save_data(POOL_FILE, $move_to_end(load_data(POOL_FILE, [])));
    }
    json_response(['status' => 'success', 'postponed' => true]);
}

function handle_meme_vote($input, $debug_mode) {
    $vote = $input['vote'] ?? '';
    $meme = $input['meme'] ?? null;
    $map = ['like' => 'liked_memes', 'dislike' => 'disliked_memes', 'maybe' => 'undecided_memes', 'wrong' => 'wrong_topic_memes'];
    $has_media = !empty($meme['image']) || (!empty($meme['images']) && is_array($meme['images']));
    if ((!isset($map[$vote]) && $vote !== 'postpone') || !is_array($meme) || !$has_media) {
        json_response(['status' => 'error', 'message' => 'Некорректный голос или мем'], 400);
    }
    if ($vote === 'postpone') handle_meme_postpone($meme, $debug_mode);

    $first_image = !empty($meme['image']) ? $meme['image'] : (is_array($meme['images'] ?? null) ? ($meme['images'][0] ?? '') : '');
    // Complaints ("wrong") are stored in wrong_topic_memes for compatibility with existing history files.
    $complaint_reason = $vote === 'wrong' && in_array($input['reason'] ?? '', COMPLAINT_REASONS, true) ? $input['reason'] : null;

    if ($debug_mode) {
        $state = &$_SESSION['meme_tinder_debug'];
        $state['history'][$map[$vote]][] = ['text' => $meme['text'] ?? '', 'rating_assigned' => $meme['rating'] ?? 0, 'complaint_reason' => $complaint_reason, 'timestamp' => time()];
        $state['pool'] = array_values(array_filter($state['pool'], function($item) use ($first_image) { return ($item['image'] ?? '') !== $first_image; }));
        json_response(['status' => 'success', 'debug' => true]);
    }

    $history = load_history();
    $history[$map[$vote]][] = [
        'image' => $first_image,
        'text' => $meme['text'],
        'ai_short_description' => $meme['ai_description'] ?? 'Без описания ИИ',
        'why_funny' => $meme['why_funny'] ?? '',
        'is_funny' => $meme['is_funny'] ?? null,
        'meme_probability' => $meme['meme_probability'] ?? null,
        'rating_assigned' => $meme['rating'],
        'source' => $meme['source'],
        'board' => $meme['board'] ?? '',
        'thread_id' => $meme['thread_id'] ?? '',
        'post_id' => $meme['post_id'] ?? '',
        'source_url' => source_post_url($meme),
        'complaint_reason' => $complaint_reason,
        'timestamp' => time()
    ];
    save_data(HISTORY_FILE, $history);

    $pool = array_filter(load_data(POOL_FILE, []), function($item) use ($meme) { return $item['image'] !== $meme['image']; });
    save_data(POOL_FILE, array_values($pool));

    json_response(['status' => 'success']);
}

function handle_refresh_memes($debug_mode) {
    if ($debug_mode) {
        json_response(['status' => 'error', 'message' => 'Поиск мемов отключён в демо-режиме.'], 403);
    }
    $parser_status = start_meme_parser_if_needed(true);
    json_response([
        'status' => in_array($parser_status, ['started', 'running'], true) ? 'loading' : 'error',
        'message' => $parser_status === 'unavailable' ? 'Не удалось запустить поиск мемов на сервере.' : 'Ищем посты...'
    ]);
}

// ---- Source discovery ----

function dvach_source_candidates($database) {
    $candidates = [];
    $boards = $database['2ch_boards'] ?? [];
    shuffle($boards);
    foreach (array_slice($boards, 0, 4) as $board) {
        $catalog = fetch_2ch_json($board);
        foreach (($catalog['threads'] ?? []) as $thread) {
            $thread_id = (string)($thread['num'] ?? '');
            if (!is_valid_dvach_id($thread_id)) continue;
            $source_id = 'dvach:' . $board . ':' . $thread_id;
            if (source_vote_exists($database, $source_id)) continue;
            $subject = clean_html_text($thread['subject'] ?? '');
            $candidates[] = [
                'source_id' => $source_id,
                'name' => 'Двач /' . $board . '/ · тред #' . $thread_id,
                'board' => $board,
                'thread_id' => $thread_id,
                'platform' => 'Двач',
                'reason' => $subject ?: 'Тред с постами и изображениями.'
            ];
            if (count($candidates) >= 30) return $candidates;
        }
    }
    return $candidates;
}

function telegram_source_candidates($database) {
    $candidates = [];
    foreach (($database['telegram_channels'] ?? []) as $channel) {
        $channel_id = ltrim(trim((string)($channel['id'] ?? '')), '@');
        if (!is_valid_telegram_channel($channel_id)) continue;
        $source_id = 'telegram:' . strtolower($channel_id);
        if (source_vote_exists($database, $source_id)) continue;
        $candidates[] = [
            'source_id' => $source_id,
            'id' => $channel_id,
            'name' => 'Telegram | ' . ($channel['name'] ?? $channel_id),
            'platform' => 'Telegram',
            'reason' => 'Публичный Telegram-канал с недавними постами.'
        ];
    }
    return $candidates;
}

function vk_source_candidates($database) {
    $candidates = [];
    foreach (($database['vk_publics'] ?? []) as $public) {
        $public_id = trim((string)($public['id'] ?? ''));
        if (!is_valid_vk_domain($public_id)) continue;
        $source_id = 'vk:' . strtolower($public_id);
        if (source_vote_exists($database, $source_id)) continue;
        $candidates[] = [
            'source_id' => $source_id,
            'id' => $public_id,
            'name' => 'ВКонтакте | ' . ($public['name'] ?? $public_id),
            'platform' => 'ВКонтакте',
            'reason' => 'Публичное сообщество ВКонтакте с изображениями в постах.'
        ];
    }
    return $candidates;
}

function vk_is_connected() {
    try {
        return vk_access_token() !== '';
    } catch (Throwable $error) {
        error_log('VK token refresh failed during source discovery: ' . $error->getMessage());
        return false;
    }
}

/** Platforms with fewer past votes go first; candidates are shuffled within each platform. */
function order_candidates_by_platform_votes($candidates, $source_votes) {
    $platform_candidates = [];
    foreach ($candidates as $candidate) {
        $platform_candidates[$candidate['platform']][] = $candidate;
    }
    $platform_order = array_keys($platform_candidates);
    shuffle($platform_order);
    $platform_vote_counts = [];
    foreach ($source_votes as $vote) {
        $voted_platform = $vote['platform'] ?? '';
        $platform_vote_counts[$voted_platform] = ($platform_vote_counts[$voted_platform] ?? 0) + 1;
    }
    usort($platform_order, function($first, $second) use ($platform_vote_counts) {
        return ($platform_vote_counts[$first] ?? 0) <=> ($platform_vote_counts[$second] ?? 0);
    });
    $ordered_candidates = [];
    foreach ($platform_order as $platform) {
        shuffle($platform_candidates[$platform]);
        $ordered_candidates = array_merge($ordered_candidates, $platform_candidates[$platform]);
    }
    return $ordered_candidates;
}

function handle_suggest_sources($input) {
    $database = load_source_db();
    $platform_filter = strtolower(trim((string)($input['platform'] ?? 'any')));
    if (!in_array($platform_filter, ['any', 'telegram', 'vk', 'dvach'], true)) {
        json_response(['message' => 'Неизвестная платформа источника.'], 400);
    }
    $includes = function($platform) use ($platform_filter) {
        return $platform_filter === 'any' || $platform_filter === $platform;
    };

    $candidates = [];
    if ($includes('dvach')) $candidates = array_merge($candidates, dvach_source_candidates($database));
    if ($includes('telegram')) $candidates = array_merge($candidates, telegram_source_candidates($database));
    $vk_connected = $includes('vk') && vk_is_connected();
    if ($vk_connected) $candidates = array_merge($candidates, vk_source_candidates($database));

    if (empty($candidates)) {
        $message = $platform_filter === 'vk' && !$vk_connected
            ? 'Сначала подключите VK OAuth, чтобы искать паблики.'
            : 'Новых неоценённых источников на этой платформе пока нет.';
        json_response(['discovered' => null, 'message' => $message]);
    }

    if ($platform_filter === 'any') {
        $candidates = order_candidates_by_platform_votes($candidates, $database['source_votes'] ?? []);
    } else {
        shuffle($candidates);
    }
    foreach (array_slice($candidates, 0, 12) as $candidate) {
        try {
            $candidate['posts'] = build_source_preview_posts($candidate, 4);
        } catch (Throwable $error) {
            error_log('Source preview failed (' . ($candidate['platform'] ?? 'unknown') . '): ' . $error->getMessage());
            continue;
        }
        if ($candidate['posts']) json_response(['discovered' => $candidate]);
    }
    json_response(['discovered' => null, 'message' => 'Не удалось получить посты с изображениями ни из одного кандидата. Для VK подключите аккаунт, Telegram-каналы должны быть публичными.'], 502);
}

/** Adds an approved Telegram channel / VK public to its catalog list unless already present. */
function add_catalog_source(&$db, $catalog_key, $source, $name_prefix) {
    foreach (($db[$catalog_key] ?? []) as $existing) {
        if (($existing['id'] ?? null) === $source['id']) return;
    }
    $db[$catalog_key][] = ['id' => $source['id'], 'name' => str_replace($name_prefix, '', $source['name'])];
}

function handle_source_vote($input) {
    $vote = $input['vote'] ?? '';
    $source = $input['source'] ?? null;
    if (!in_array($vote, ['approve', 'reject'], true) || !is_array($source)) {
        json_response(['status' => 'error', 'message' => 'Некорректное решение'], 400);
    }

    $db = load_source_db();
    $source_id = (string)($source['source_id'] ?? ($source['id'] ?? ($source['board'] ?? $source['name'])));
    if (!source_vote_exists($db, $source_id)) {
        $vote_item = [
            'source_id' => $source_id,
            'platform' => $source['platform'] ?? 'Неизвестно',
            'name' => $source['name'] ?? 'Источник',
            'vote' => $vote,
            'timestamp' => time()
        ];
        if (isset($source['thread_id'])) $vote_item['thread_id'] = (string)$source['thread_id'];
        if (isset($source['board'])) $vote_item['board'] = (string)$source['board'];
        $db['source_votes'][] = $vote_item;
    }

    if ($vote === 'approve') {
        $platform = $source['platform'] ?? '';
        if ($platform === 'Двач' && isset($source['board'])) {
            if (!in_array($source['board'], $db['2ch_boards'], true)) {
                $db['2ch_boards'][] = $source['board'];
            }
        } elseif ($platform === 'ВКонтакте' && isset($source['id'])) {
            add_catalog_source($db, 'vk_publics', $source, 'ВКонтакте | ');
        } elseif ($platform === 'Telegram' && isset($source['id'])) {
            add_catalog_source($db, 'telegram_channels', $source, 'Telegram | ');
        }
    }

    save_data(SOURCES_FILE, $db);
    json_response(['status' => 'success']);
}

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
