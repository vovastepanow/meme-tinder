<?php
// Обработчики вкладки «Подбор источников»: кандидаты, превью постов и решения пользователя.

const SOURCE_PLATFORM_FILTERS = ['any' => null, 'telegram' => PLATFORM_TELEGRAM, 'vk' => PLATFORM_VK, 'dvach' => PLATFORM_DVACH];
const SOURCE_DVACH_BOARDS_TO_SCAN = 4;
const SOURCE_DVACH_MAX_CANDIDATES = 30;
const SOURCE_PREVIEW_ATTEMPTS = 12;
const SOURCE_PREVIEW_POSTS = 4;

function source_candidate($source_id, $platform, $name, $reason, array $extra = []) {
    return ['source_id' => $source_id, 'platform' => $platform, 'name' => $name, 'reason' => $reason] + $extra;
}

function dvach_source_candidates($database, array $voted_ids) {
    $candidates = [];
    $boards = $database['2ch_boards'] ?? [];
    shuffle($boards);
    foreach (array_slice($boards, 0, SOURCE_DVACH_BOARDS_TO_SCAN) as $board) {
        foreach ((fetch_2ch_json($board)['threads'] ?? []) as $thread) {
            $thread_id = (string)($thread['num'] ?? '');
            if (!is_valid_dvach_id($thread_id)) continue;
            $source_id = 'dvach:' . $board . ':' . $thread_id;
            if (isset($voted_ids[$source_id])) continue;
            $candidates[] = source_candidate(
                $source_id,
                PLATFORM_DVACH,
                PLATFORM_DVACH . ' /' . $board . '/ · тред #' . $thread_id,
                clean_html_text($thread['subject'] ?? '') ?: 'Тред с постами и изображениями.',
                ['board' => $board, 'thread_id' => $thread_id]
            );
            if (count($candidates) >= SOURCE_DVACH_MAX_CANDIDATES) return $candidates;
        }
    }
    return $candidates;
}

/** Каналы Telegram и паблики VK текущей темы из каталога, по которым ещё не было решения. */
function catalog_source_candidates($database, $catalog_key, $platform, array $voted_ids, $topic) {
    $id_prefix = $platform === PLATFORM_TELEGRAM ? 'telegram:' : 'vk:';
    $reason = $platform === PLATFORM_TELEGRAM
        ? 'Публичный Telegram-канал с недавними постами.'
        : 'Публичное сообщество ВКонтакте с изображениями в постах.';

    $candidates = [];
    foreach (($database[$catalog_key] ?? []) as $entry) {
        if (!catalog_entry_matches_topic($entry, $topic)) continue;
        $id = ltrim(trim((string)($entry['id'] ?? '')), '@');
        $is_valid = $platform === PLATFORM_TELEGRAM ? is_valid_telegram_channel($id) : is_valid_vk_domain($id);
        $source_id = $id_prefix . strtolower($id);
        if (!$is_valid || isset($voted_ids[$source_id])) continue;
        $candidates[] = source_candidate($source_id, $platform, source_display_name($platform, $entry['name'] ?? $id), $reason, ['id' => $id]);
    }
    return $candidates;
}

/** Площадки с меньшим числом прошлых решений идут первыми; внутри площадки кандидаты перемешаны. */
function order_candidates_by_platform_votes($candidates, $source_votes) {
    $by_platform = [];
    foreach ($candidates as $candidate) {
        $by_platform[$candidate['platform']][] = $candidate;
    }
    $vote_counts = array_count_values(array_map(function ($vote) {
        return (string)($vote['platform'] ?? '');
    }, $source_votes));

    $platforms = array_keys($by_platform);
    shuffle($platforms);
    usort($platforms, function ($first, $second) use ($vote_counts) {
        return ($vote_counts[$first] ?? 0) <=> ($vote_counts[$second] ?? 0);
    });

    $ordered = [];
    foreach ($platforms as $platform) {
        shuffle($by_platform[$platform]);
        array_push($ordered, ...$by_platform[$platform]);
    }
    return $ordered;
}

/** Решения по источникам: в демо-режиме — из сессии, иначе — из каталога. */
/** Предложения автоподбора (лучшие первыми) как кандидаты для вкладки «Источники». */
function suggestion_source_candidates($database, $wanted_platform, array $voted_ids) {
    $suggestions = $database['source_suggestions'] ?? [];
    usort($suggestions, function ($a, $b) {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    });
    $candidates = [];
    foreach ($suggestions as $suggestion) {
        $platform = $suggestion['platform'] ?? '';
        if (($wanted_platform !== null && $platform !== $wanted_platform) || isset($voted_ids[$suggestion['source_id']])) continue;
        if ($platform === PLATFORM_VK && !vk_is_connected()) continue; // без токена не показать посты паблика
        $candidates[] = source_candidate($suggestion['source_id'], $platform, $suggestion['name'], $suggestion['reason'] ?? '',
            ['id' => $suggestion['id'], 'suggested' => true, 'score' => $suggestion['score'] ?? null]);
    }
    return $candidates;
}

function current_source_votes($database, $debug_mode) {
    return $debug_mode ? debug_state()['source_votes'] : ($database['source_votes'] ?? []);
}

function handle_suggest_sources($input, $debug_mode) {
    $platform_filter = strtolower(trim((string)($input['platform'] ?? 'any')));
    if (!array_key_exists($platform_filter, SOURCE_PLATFORM_FILTERS)) {
        json_error('Неизвестная платформа источника.');
    }
    $wanted_platform = SOURCE_PLATFORM_FILTERS[$platform_filter];
    $includes = function ($platform) use ($wanted_platform) {
        return $wanted_platform === null || $wanted_platform === $platform;
    };

    $database = load_source_db();
    $topic = $debug_mode ? (debug_state()['topic'] ?? DEFAULT_TOPIC) : current_topic();
    $source_votes = current_source_votes($database, $debug_mode);
    $voted_ids = array_flip(array_map('strval', array_column($source_votes, 'source_id')));

    $candidates = [];
    if ($includes(PLATFORM_DVACH)) {
        array_push($candidates, ...dvach_source_candidates($database, $voted_ids));
    }
    if ($includes(PLATFORM_TELEGRAM)) {
        array_push($candidates, ...catalog_source_candidates($database, 'telegram_channels', PLATFORM_TELEGRAM, $voted_ids, $topic));
    }
    $vk_connected = $includes(PLATFORM_VK) && vk_is_connected();
    if ($vk_connected) {
        array_push($candidates, ...catalog_source_candidates($database, 'vk_publics', PLATFORM_VK, $voted_ids, $topic));
    }

    if (!$candidates && !$debug_mode) $candidates = suggestion_source_candidates($database, $wanted_platform, $voted_ids);
    if (!$candidates) {
        $message = $wanted_platform === PLATFORM_VK && !$vk_connected
            ? 'Сначала подключите VK OAuth, чтобы искать паблики.'
            : 'Новых неоценённых источников на этой платформе пока нет.';
        json_response(['discovered' => null, 'message' => $message]);
    }

    if ($wanted_platform === null) {
        $candidates = order_candidates_by_platform_votes($candidates, $source_votes);
    } else {
        shuffle($candidates);
    }
    // Предложения автоподбора показываются первыми: ради них подбор и запускался.
    if (!$debug_mode) $candidates = array_merge(suggestion_source_candidates($database, $wanted_platform, $voted_ids), $candidates);

    foreach (array_slice($candidates, 0, SOURCE_PREVIEW_ATTEMPTS) as $candidate) {
        try {
            $candidate['posts'] = build_source_preview_posts($candidate, SOURCE_PREVIEW_POSTS);
        } catch (Throwable $error) {
            error_log('Source preview failed (' . $candidate['platform'] . '): ' . $error->getMessage());
            continue;
        }
        if ($candidate['posts']) json_response(['discovered' => $candidate]);
    }
    json_response([
        'discovered' => null,
        'message' => 'Не удалось получить посты с изображениями ни из одного кандидата. Для VK подключите аккаунт, Telegram-каналы должны быть публичными.'
    ], 502);
}

/** Запись о решении по источнику. Принимаются только поля с проверенными значениями. */
function source_vote_record(array $source, $vote) {
    $source_id = trim((string)($source['source_id'] ?? ''));
    if ($source_id === '' || strlen($source_id) > 200) json_error('Некорректный источник.');

    $record = [
        'source_id' => $source_id,
        'platform' => in_array($source['platform'] ?? '', [PLATFORM_DVACH, PLATFORM_TELEGRAM, PLATFORM_VK], true) ? $source['platform'] : 'Неизвестно',
        'name' => mb_substr(trim((string)($source['name'] ?? '')), 0, 200) ?: 'Источник',
        'vote' => $vote,
        'timestamp' => time()
    ];
    if (is_valid_dvach_board($source['board'] ?? '')) $record['board'] = (string)$source['board'];
    if (is_valid_dvach_id($source['thread_id'] ?? '')) $record['thread_id'] = (string)$source['thread_id'];
    return $record;
}

/** Добавляет одобренный источник в каталог парсера, если его там ещё нет. $topic — привязка к теме (для предложений). */
function add_approved_source_to_catalog(array $db, array $source, $topic = '') {
    $platform = $source['platform'] ?? '';
    $id = ltrim(trim((string)($source['id'] ?? '')), '@');

    if ($platform === PLATFORM_DVACH) {
        $board = (string)($source['board'] ?? '');
        if (is_valid_dvach_board($board) && !in_array($board, $db['2ch_boards'], true)) $db['2ch_boards'][] = $board;
        return $db;
    }

    $catalog_key = [PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'][$platform] ?? null;
    $is_valid = $platform === PLATFORM_TELEGRAM ? is_valid_telegram_channel($id) : is_valid_vk_domain($id);
    if ($catalog_key === null || !$is_valid) return $db;

    foreach ($db[$catalog_key] as $existing) {
        if (strcasecmp((string)($existing['id'] ?? ''), $id) === 0) return $db;
    }
    $prefix = SOURCE_NAME_PREFIXES[$platform];
    $name = (string)($source['name'] ?? '');
    if (str_starts_with($name, $prefix)) $name = substr($name, strlen($prefix));
    $entry = ['id' => $id, 'name' => mb_substr(trim($name), 0, 200) ?: $id];
    if ($topic !== '') $entry['topics'] = [$topic];
    $db[$catalog_key][] = $entry;
    return $db;
}

/** Убирает отклонённый канал или паблик из каталога, чтобы парсер его больше не сканировал. */
function remove_rejected_source_from_catalog(array $db, array $source) {
    $catalog_key = [PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'][$source['platform'] ?? ''] ?? null;
    $id = ltrim(trim((string)($source['id'] ?? '')), '@');
    if ($catalog_key === null || $id === '') return $db;
    $db[$catalog_key] = array_values(array_filter($db[$catalog_key], function ($entry) use ($id) {
        return strcasecmp((string)($entry['id'] ?? ''), $id) !== 0;
    }));
    return $db;
}

/** Запустить автоподбор источников по текущей теме (выполнит фоновый парсер). */
function handle_discover_sources($debug_mode) {
    if ($debug_mode) json_error('Автоподбор источников отключён в демо-режиме.', 403);
    request_source_discovery();
    $parser = start_content_parser_if_needed(true);
    $message = $parser === 'started'
        ? 'Автоподбор запущен. Ход поиска — в журнале.'
        : ($parser === 'running'
            ? 'Парсер уже работает: автоподбор начнётся при следующем запуске.'
            : 'Не удалось запустить парсер на сервере.');
    json_response(['status' => $parser === 'unavailable' ? 'error' : 'success', 'parser' => $parser, 'message' => $message], $parser === 'unavailable' ? 503 : 200);
}

/** Сводка автоподбора для вкладки «Источники». */
function handle_discovery_status($debug_mode) {
    if ($debug_mode) json_response(['enabled' => false]);
    $db = load_source_db();
    $topic = current_topic();
    $auto = 0;
    $frozen = 0;
    foreach (['telegram_channels', 'vk_publics'] as $key) {
        foreach ($db[$key] as $entry) {
            if (!catalog_entry_matches_topic($entry, $topic)) continue;
            if (!empty($entry['auto'])) $auto++;
            if (isset($entry['frozen_until'])) $frozen++;
        }
    }
    $suggestions = count(array_filter($db['source_suggestions'], function ($suggestion) use ($topic) {
        return mb_strtolower((string)($suggestion['topic'] ?? '')) === mb_strtolower($topic);
    }));
    json_response([
        'enabled' => SOURCE_DISCOVERY_ENABLED,
        'topic' => $topic,
        'last_topic' => $db['discovery']['topic'],
        'last_run' => (int)$db['discovery']['last_run'],
        'requested' => (bool)$db['discovery']['requested'],
        'auto_added' => $auto,
        'suggestions' => $suggestions,
        'frozen' => $frozen,
        'vk_connected' => vk_is_connected()
    ]);
}

function handle_source_vote($input, $debug_mode) {
    $vote = $input['vote'] ?? '';
    $source = $input['source'] ?? null;
    if (!in_array($vote, ['approve', 'reject'], true) || !is_array($source)) {
        json_error('Некорректное решение');
    }
    $record = source_vote_record($source, $vote);

    if ($debug_mode) {
        $state = &debug_state();
        if (!in_array($record['source_id'], array_column($state['source_votes'], 'source_id'), true)) {
            $state['source_votes'][] = $record;
        }
        json_response(['status' => 'success', 'debug' => true]);
    }

    update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($record, $source, $vote) {
        $db = normalize_source_db($db);
        if (!source_vote_exists($db, $record['source_id'])) $db['source_votes'][] = $record;
        // Для предложения автоподбора данные берутся с сервера, а не из запроса; само предложение снимается.
        $topic = '';
        foreach ($db['source_suggestions'] as $index => $suggestion) {
            if ($suggestion['source_id'] !== $record['source_id']) continue;
            $source = ['platform' => $suggestion['platform'], 'id' => $suggestion['id'], 'name' => $suggestion['name']];
            $topic = (string)($suggestion['topic'] ?? '');
            unset($db['source_suggestions'][$index]);
        }
        $db['source_suggestions'] = array_values($db['source_suggestions']);
        return $vote === 'approve' ? add_approved_source_to_catalog($db, $source, $topic) : remove_rejected_source_from_catalog($db, $source);
    });
    json_response(['status' => 'success']);
}
