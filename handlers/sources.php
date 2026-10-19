<?php
// Обработчики вкладки «Подбор источников»: кандидаты, превью постов и решения пользователя.

const SOURCE_PLATFORM_FILTERS = ['any' => null, 'telegram' => PLATFORM_TELEGRAM, 'vk' => PLATFORM_VK, 'dvach' => PLATFORM_DVACH];
const SOURCE_DVACH_BOARDS_TO_SCAN = 4;
const SOURCE_DVACH_MAX_CANDIDATES = 30;
const SOURCE_PREVIEW_ATTEMPTS = 12;
const SOURCE_PREVIEW_POSTS = 6;
const SOURCE_REJECT_REASONS = ['off_topic', 'dead', 'other'];
const SOURCE_DVACH_BLOCK_REJECTS = 3;   // столько отклонённых тредов доски без единого одобренного — доска больше не предлагается

function source_candidate($source_id, $platform, $name, $reason, array $extra = []) {
    return ['source_id' => $source_id, 'platform' => $platform, 'name' => $name, 'reason' => $reason] + $extra;
}

/** Размер и свежесть треда для подписи кандидата: «319 постов, последний 3 ч назад». */
function dvach_thread_summary(array $thread) {
    $posts = (int)($thread['posts_count'] ?? 0);
    $hours = isset($thread['lasthit']) ? (int)floor((time() - (int)$thread['lasthit']) / 3600) : null;
    $last = $hours === null ? '' : ', последний ' . ($hours < 1 ? 'меньше часа' : ($hours < 48 ? "{$hours} ч" : intdiv($hours, 24) . ' дн')) . ' назад';
    return "{$posts} постов{$last}";
}

/**
 * Доски Двача, треды которых не стоит предлагать: по оценкам постов их плохо оценивают (парсер такие доски уже не сканирует,
 * см. source_feedback.php) или вы отклонили не меньше SOURCE_DVACH_BLOCK_REJECTS тредов доски и не одобрили ни одного.
 */
function dvach_blocked_boards(array $source_votes, $topic) {
    $blocked = [];
    foreach (source_feedback_stats($topic) as $key => $row) {
        if (str_starts_with($key, 'dvach:') && $row['votes'] >= SOURCE_FEEDBACK_BAN_VOTES && $row['score'] < SOURCE_FEEDBACK_BAN_SCORE) $blocked[substr($key, 6)] = true;
    }
    $decisions = [];
    foreach ($source_votes as $vote) {
        if (empty($vote['board'])) continue;
        $decisions[$vote['board']]['reject'] = ($decisions[$vote['board']]['reject'] ?? 0) + (($vote['vote'] ?? '') === 'reject' ? 1 : 0);
        $decisions[$vote['board']]['approve'] = ($decisions[$vote['board']]['approve'] ?? 0) + (($vote['vote'] ?? '') === 'approve' ? 1 : 0);
    }
    foreach ($decisions as $board => $count) {
        if ($count['reject'] >= SOURCE_DVACH_BLOCK_REJECTS && $count['approve'] === 0) $blocked[$board] = true;
    }
    return array_keys($blocked);
}

function dvach_source_candidates($database, array $voted_ids, array $blocked_boards = [], $topic = DEFAULT_TOPIC) {
    $candidates = [];
    // Доски этой темы: у темы по умолчанию — общий список, у остальных — подобранные под тему (их выбирает парсер)
    $boards = array_values(array_diff(dvach_boards_for_topic($topic, false), $blocked_boards));
    shuffle($boards);
    foreach (array_slice($boards, 0, SOURCE_DVACH_BOARDS_TO_SCAN) as $board) {
        foreach ((fetch_2ch_json($board)['threads'] ?? []) as $thread) {
            $thread_id = (string)($thread['num'] ?? '');
            if (!is_valid_dvach_id($thread_id)) continue;
            $source_id = 'dvach:' . $board . ':' . $thread_id;
            if (isset($voted_ids[$source_id])) continue;
            if (dvach_thread_dead_reason($thread) !== null) continue;   // закрытый, пустой или давно молчащий тред не показываем
            $candidates[] = source_candidate(
                $source_id,
                PLATFORM_DVACH,
                PLATFORM_DVACH . ' /' . $board . '/ · ' . dvach_thread_title($thread['subject'] ?? '', $thread_id),
                (clean_html_text($thread['subject'] ?? '') ?: 'Тред с постами и изображениями.') . ' · ' . dvach_thread_summary($thread),
                ['board' => $board, 'thread_id' => $thread_id, 'thread_posts' => (int)($thread['posts_count'] ?? 0), 'thread_files' => (int)($thread['files_count'] ?? 0), 'last_hit' => (int)($thread['lasthit'] ?? 0)]
            );
            if (count($candidates) >= SOURCE_DVACH_MAX_CANDIDATES) return $candidates;
        }
    }
    return $candidates;
}

/**
 * Превью кандидата и сводка о нём для карточки: посты с картинками и ['url', 'description', 'subscribers', 'view_rate',
 * 'posts_per_week', 'last_post', 'thread_posts', 'dead']. dead — причина, по которой источник мёртв (тогда его не показывают).
 * Для Telegram всё берётся одной загрузкой страницы канала.
 */
function build_source_preview(array $candidate, $count) {
    $stats = ['url' => '', 'description' => '', 'subscribers' => 0, 'view_rate' => null, 'posts_per_week' => null, 'last_post' => null, 'thread_posts' => null, 'thread_files' => null, 'dead' => null];
    $platform = $candidate['platform'] ?? '';
    if ($platform === PLATFORM_TELEGRAM) {
        $info = telegram_channel_info($candidate['id'] ?? '', max($count, 6));
        if ($info === null) return ['posts' => [], 'stats' => ['dead' => 'канала нет или он закрыт'] + $stats];
        $activity = source_activity($info['post_times'] ?? []);
        $stats = [
            'url' => 'https://t.me/' . rawurlencode($info['id']),
            'description' => $info['description'],
            'subscribers' => $info['subscribers'],
            'view_rate' => source_view_rate($info['subscribers'], $info['post_views'] ?? []),
            'posts_per_week' => $activity['per_week'] ?? null,
            'last_post' => $activity['last_post'] ?? null
        ] + $stats;
        $stats['dead'] = source_inactivity_reason($activity) ?? source_dead_audience_reason($stats['subscribers'], $stats['view_rate']);
        $posts = array_values(array_filter($info['posts'], fn($post) => !is_post_outdated($post['posted_at'] ?? 0)));
        return ['posts' => array_slice($posts, 0, $count), 'stats' => $stats];
    }
    if ($platform === PLATFORM_VK) {
        $stats['url'] = 'https://vk.com/' . rawurlencode((string)($candidate['id'] ?? ''));
        $posts = build_vk_preview_posts($candidate, $count);
        $activity = source_activity(array_column($posts, 'posted_at'));
        $stats['posts_per_week'] = $activity['per_week'] ?? null;
        $stats['last_post'] = $activity['last_post'] ?? null;
        $stats['dead'] = $posts ? source_inactivity_reason($activity) : null;
        return ['posts' => $posts, 'stats' => $stats];
    }
    // Двач: тред
    $stats['url'] = dvach_post_url($candidate['board'] ?? '', $candidate['thread_id'] ?? '', $candidate['thread_id'] ?? '');
    $stats['thread_posts'] = $candidate['thread_posts'] ?? null;
    $stats['thread_files'] = $candidate['thread_files'] ?? null;
    $stats['last_post'] = !empty($candidate['last_hit']) ? (int)$candidate['last_hit'] : null;
    return ['posts' => build_source_preview_posts($candidate, $count), 'stats' => $stats];
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
function suggestion_source_candidates($database, $wanted_platform, array $voted_ids, $topic = null) {
    // Только находки по этой теме (и связанным с ней, lib/topics.php): у каждой темы свои источники
    $suggestions = array_filter($database['source_suggestions'] ?? [], fn($suggestion) => $topic === null || topics_are_equivalent((string)($suggestion['topic'] ?? ''), $topic));
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
    // Решения считаются по теме: отказ «не по теме» для одной темы не прячет источник в другой. Старые решения без темы — для всех.
    $source_votes = array_values(array_filter(current_source_votes($database, $debug_mode),
        fn($vote) => empty($vote['topic']) || topic_same((string)$vote['topic'], $topic)));
    $voted_ids = array_flip(array_map('strval', array_column($source_votes, 'source_id')));
    // Отложенные в этой сессии («Отложить») клиент присылает сам: решения по ним не записываются
    foreach (array_slice((array)($input['skip'] ?? []), 0, 200) as $skipped_id) {
        if (is_string($skipped_id) && strlen($skipped_id) <= 200) $voted_ids[$skipped_id] = true;
    }

    // Лёгкий запрос от вкладки, пока идёт подбор: только готовые находки, без обхода досок Двача и каталога
    if (!empty($input['suggested_only']) && !$debug_mode) {
        $found = suggestion_source_candidates($database, $wanted_platform, $voted_ids, $topic);
        foreach (array_slice($found, 0, SOURCE_PREVIEW_ATTEMPTS) as $candidate) {
            try {
                $preview = build_source_preview($candidate, SOURCE_PREVIEW_POSTS);
            } catch (Throwable $error) {
                continue;
            }
            if ($preview['stats']['dead'] !== null || !$preview['posts']) continue;
            json_response(['discovered' => $candidate + ['posts' => $preview['posts'], 'stats' => $preview['stats']]]);
        }
        json_response(['discovered' => null, 'message' => 'Подбор пока ничего не нашёл.']);
    }

    $candidates = [];
    if ($includes(PLATFORM_DVACH)) {
        array_push($candidates, ...dvach_source_candidates($database, $voted_ids, $debug_mode ? [] : dvach_blocked_boards($source_votes, $topic), $topic));
    }
    if ($includes(PLATFORM_TELEGRAM)) {
        array_push($candidates, ...catalog_source_candidates($database, 'telegram_channels', PLATFORM_TELEGRAM, $voted_ids, $topic));
    }
    $vk_connected = $includes(PLATFORM_VK) && vk_is_connected();
    if ($vk_connected) {
        array_push($candidates, ...catalog_source_candidates($database, 'vk_publics', PLATFORM_VK, $voted_ids, $topic));
    }

    if (!$candidates && !$debug_mode) $candidates = suggestion_source_candidates($database, $wanted_platform, $voted_ids, $topic);
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
    if (!$debug_mode) $candidates = array_merge(suggestion_source_candidates($database, $wanted_platform, $voted_ids, $topic), $candidates);

    foreach (array_slice($candidates, 0, SOURCE_PREVIEW_ATTEMPTS) as $candidate) {
        try {
            $preview = build_source_preview($candidate, SOURCE_PREVIEW_POSTS);
        } catch (Throwable $error) {
            error_log('Source preview failed (' . $candidate['platform'] . '): ' . $error->getMessage());
            continue;
        }
        if ($preview['stats']['dead'] !== null) continue;   // мёртвые каналы, пустые и молчащие треды пользователю не показываем
        $candidate['posts'] = $preview['posts'];
        $candidate['stats'] = $preview['stats'];
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
        if (!is_valid_dvach_board($board)) return $db;
        if ($topic === '' || topic_same(DEFAULT_TOPIC, $topic)) {
            if (!in_array($board, $db['2ch_boards'], true)) $db['2ch_boards'][] = $board;
        } else {   // у другой темы свой список досок
            $key = mb_strtolower($topic);
            $boards = $db['dvach_topic_boards'][$key] ?? [];
            if (!in_array($board, $boards, true)) $db['dvach_topic_boards'][$key] = array_merge($boards, [$board]);
        }
        return $db;
    }

    $catalog_key = [PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'][$platform] ?? null;
    $is_valid = $platform === PLATFORM_TELEGRAM ? is_valid_telegram_channel($id) : is_valid_vk_domain($id);
    if ($catalog_key === null || !$is_valid) return $db;

    foreach ($db[$catalog_key] as $index => $existing) {
        if (strcasecmp((string)($existing['id'] ?? ''), $id) !== 0) continue;
        // Источник уже есть у другой темы — теперь он и источник этой
        $topics = (array)($existing['topics'] ?? []);
        if ($topic !== '' && $topics && !array_filter($topics, fn($old) => topic_same((string)$old, $topic))) $db[$catalog_key][$index]['topics'][] = $topic;
        return $db;
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
/** Отказ по теме $topic: источник перестаёт быть источником этой темы; у других тем он остаётся. */
function remove_rejected_source_from_catalog(array $db, array $source, $topic = '') {
    $catalog_key = [PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'][$source['platform'] ?? ''] ?? null;
    $id = ltrim(trim((string)($source['id'] ?? '')), '@');
    if ($catalog_key === null || $id === '') return $db;
    $kept = [];
    foreach ($db[$catalog_key] as $entry) {
        if (strcasecmp((string)($entry['id'] ?? ''), $id) === 0) {
            $topics = (array)($entry['topics'] ?? []);
            if ($topic === '' || !$topics) continue;   // источник без темы (для всех) убирается целиком
            $entry['topics'] = array_values(array_filter($topics, fn($old) => !topic_same((string)$old, $topic)));
            if (!$entry['topics']) continue;
        }
        $kept[] = $entry;
    }
    $db[$catalog_key] = $kept;
    return $db;
}

/** Запустить автоподбор источников по текущей теме (выполнит фоновый парсер). */
function handle_discover_sources($debug_mode) {
    if ($debug_mode) json_error('Автоподбор источников отключён в демо-режиме.', 403);
    request_source_discovery();
    $parser = start_content_parser_if_needed(true);
    $message = [
        'started' => 'Автоподбор запущен. Ход поиска — в журнале.',
        'running' => 'Парсер уже работает: автоподбор начнётся при следующем запуске.',
        'no_session' => 'Окно приложения неактивно: обновите страницу, и автоподбор начнётся.'
    ][$parser] ?? 'Не удалось запустить парсер на сервере.';
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
    $suggestions = count(array_filter($db['source_suggestions'], fn($suggestion) => topics_are_equivalent((string)($suggestion['topic'] ?? ''), $topic)));
    $entry = topic_ensure($topic);
    json_response([
        'enabled' => SOURCE_DISCOVERY_ENABLED,
        'topic' => $topic,
        'topic_info' => $entry ? topic_public($entry, $db) : null,
        'last_topic' => $db['discovery']['topic'],
        'last_run' => (int)$db['discovery']['last_run'],
        'requested' => (bool)$db['discovery']['requested'],
        'running' => time() - (int)($db['discovery']['running_since'] ?? 0) < SOURCE_DISCOVERY_MAX_SECONDS + 120,
        'auto_added' => $auto,
        'suggestions' => $suggestions,
        'frozen' => $frozen,
        'vk_connected' => vk_is_connected()
    ]);
}

function handle_source_vote($input, $debug_mode) {
    $vote = $input['vote'] ?? '';
    $source = $input['source'] ?? null;
    if (!in_array($vote, ['approve', 'reject', 'maybe'], true) || !is_array($source)) {
        json_error('Некорректное решение');
    }
    $record = source_vote_record($source, $vote);
    $current = $debug_mode ? (debug_state()['topic'] ?? DEFAULT_TOPIC) : current_topic();
    $record['topic'] = $current;   // решение относится к теме, по которой источник показан
    // Причина отказа («не по теме», «мёртвый»…) — по ней потом видно, где подбор ошибается
    $reason = (string)($input['reason'] ?? '');
    if ($vote === 'reject' && in_array($reason, SOURCE_REJECT_REASONS, true)) $record['reason'] = $reason;

    if ($debug_mode) {
        $state = &debug_state();
        if (!in_array($record['source_id'], array_column($state['source_votes'], 'source_id'), true)) {
            $state['source_votes'][] = $record;
        }
        json_response(['status' => 'success', 'debug' => true]);
    }

    update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($record, $source, $vote, $current) {
        $db = normalize_source_db($db);
        $already = array_filter($db['source_votes'], fn($old) => $old['source_id'] === $record['source_id'] && topic_same((string)($old['topic'] ?? ''), $current));
        if (!$already) $db['source_votes'][] = $record;
        // Для предложения автоподбора данные берутся с сервера, а не из запроса; само предложение снимается.
        $topic = $current;   // одобренный источник привязывается к теме, по которой его показали
        foreach ($db['source_suggestions'] as $index => $suggestion) {
            if ($suggestion['source_id'] !== $record['source_id'] || !topic_same((string)($suggestion['topic'] ?? $current), $current)) continue;
            $source = ['platform' => $suggestion['platform'], 'id' => $suggestion['id'], 'name' => $suggestion['name']];
            $topic = (string)($suggestion['topic'] ?? '') ?: $current;
            unset($db['source_suggestions'][$index]);
        }
        $db['source_suggestions'] = array_values($db['source_suggestions']);
        if ($vote === 'maybe') return $db;   // «не знаю»: решение записано, в каталоге ничего не меняется
        return $vote === 'approve' ? add_approved_source_to_catalog($db, $source, $topic) : remove_rejected_source_from_catalog($db, $source, $topic);
    });
    json_response(['status' => 'success']);
}

// ---- Источники темы: карточки на вкладке «Источники» ----

/** Карточка источника каталога для интерфейса. $own — источник привязан к самой теме (а не к её общей теме или ко всем). */
function topic_source_card($platform, array $entry, $own) {
    $id = ltrim((string)($entry['id'] ?? ''), '@');
    $activity = is_array($entry['activity'] ?? null) ? $entry['activity'] : null;
    $frozen = isset($entry['frozen_until']);
    return [
        'source_id' => ($platform === PLATFORM_TELEGRAM ? 'telegram:' : 'vk:') . strtolower($id),
        'platform' => $platform,
        'id' => $id,
        'name' => (string)($entry['name'] ?? $id),
        'url' => $platform === PLATFORM_TELEGRAM ? 'https://t.me/' . rawurlencode($id) : 'https://vk.com/' . rawurlencode($id),
        'handle' => $platform === PLATFORM_TELEGRAM ? '@' . $id : 'vk.com/' . $id,
        'topics' => array_values((array)($entry['topics'] ?? [])),
        'own' => $own,
        'auto' => !empty($entry['auto']),
        'score' => isset($entry['score']) ? (int)$entry['score'] : null,
        'subscribers' => (int)($entry['audience']['subscribers'] ?? 0),
        'subscribers_label' => !empty($entry['audience']['subscribers']) ? format_subscribers((int)$entry['audience']['subscribers']) : '',
        'view_rate' => isset($entry['audience']['view_rate']) ? (float)$entry['audience']['view_rate'] : null,
        'activity' => $activity !== null ? describe_source_activity($activity) : '',
        'frozen' => $frozen,
        'frozen_reason' => $frozen ? (string)($entry['frozen_reason'] ?? '') : '',
        'avatar' => (string)($entry['avatar'] ?? ''),
        'preview' => array_values(array_slice((array)($entry['preview'] ?? []), 0, 4))
    ];
}

/** GET: источники текущей темы — каналы, паблики и доски Двача, по которым сейчас идёт сбор. */
function handle_topic_sources($debug_mode) {
    if ($debug_mode) json_response(['topic' => DEFAULT_TOPIC, 'sources' => []]);
    $db = load_source_db();
    $topic = current_topic();
    $cards = [];
    foreach ([PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'] as $platform => $key) {
        foreach ($db[$key] as $entry) {
            if (!catalog_entry_matches_topic($entry, $topic)) continue;
            $cards[] = topic_source_card($platform, $entry, !empty($entry['topics']) && catalog_entry_matches_topic($entry, $topic, true));
        }
    }
    $board_names = dvach_board_names();
    foreach (dvach_boards_for_topic($topic, false) as $board) {
        $title = $board_names[$board]['name'] ?? '';
        $cards[] = ['source_id' => 'dvach-board:' . $board, 'platform' => PLATFORM_DVACH, 'id' => $board,
            'name' => $title !== '' ? "/{$board}/ — {$title}" : "Доска /{$board}/", 'category' => $board_names[$board]['category'] ?? '',
            'url' => 'https://2ch.hk/' . rawurlencode($board) . '/', 'handle' => "2ch.hk/{$board}/", 'topics' => [], 'own' => true, 'auto' => false,
            'score' => null, 'subscribers' => 0, 'subscribers_label' => '', 'view_rate' => null, 'activity' => '', 'frozen' => false,
            'frozen_reason' => '', 'avatar' => '', 'preview' => []];
    }
    // Свои источники темы — первыми, внутри — активные, затем по аудитории
    usort($cards, fn($a, $b) => [$b['own'], !$b['frozen'], $b['subscribers']] <=> [$a['own'], !$a['frozen'], $a['subscribers']]);
    $entry = topic_lookup($topic);
    json_response(['topic' => $topic, 'topic_info' => $entry ? topic_public($entry, $db) : null, 'sources' => $cards]);
}

/** POST: убрать источник из текущей темы (у других тем он остаётся). Решение запоминается — автоподбор его не вернёт. */
function handle_remove_topic_source($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме источники не меняются.');
    $platform = (string)($input['platform'] ?? '');
    $id = ltrim(trim((string)($input['id'] ?? '')), '@');
    $topic = current_topic();
    if ($platform === PLATFORM_DVACH) {
        if (!is_valid_dvach_board($id)) json_error('Некорректная доска.');
        update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($id, $topic) {
            $db = normalize_source_db($db);
            if (topic_same(DEFAULT_TOPIC, $topic)) $db['2ch_boards'] = array_values(array_diff($db['2ch_boards'], [$id]));
            else $db['dvach_topic_boards'][mb_strtolower($topic)] = array_values(array_diff($db['dvach_topic_boards'][mb_strtolower($topic)] ?? [], [$id]));
            return $db;
        });
        json_response(['status' => 'success']);
    }
    if (!in_array($platform, [PLATFORM_TELEGRAM, PLATFORM_VK], true) || $id === '' || strlen($id) > 100) json_error('Некорректный источник.');
    $source = ['platform' => $platform, 'id' => $id, 'name' => (string)($input['name'] ?? $id),
        'source_id' => ($platform === PLATFORM_TELEGRAM ? 'telegram:' : 'vk:') . strtolower($id)];
    $record = source_vote_record($source, 'reject') + ['topic' => $topic, 'reason' => 'other'];
    update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($record, $source, $topic) {
        $db = normalize_source_db($db);
        $db['source_votes'] = array_values(array_filter($db['source_votes'], fn($old) => !($old['source_id'] === $record['source_id'] && topic_same((string)($old['topic'] ?? ''), $topic))));
        $db['source_votes'][] = $record;
        return remove_rejected_source_from_catalog($db, $source, $topic);
    });
    json_response(['status' => 'success']);
}
