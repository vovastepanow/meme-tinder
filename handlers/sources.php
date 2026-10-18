<?php
// Обработчики подбора и оценки источников.
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
