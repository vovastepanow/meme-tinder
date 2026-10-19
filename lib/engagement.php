<?php
// Активность вокруг поста у самой площадки: просмотры и реакции в Telegram, ответы в тредах Двача. Снимок делается в
// момент, когда парсер видит пост, и едет вместе с записью (пул → история оценок, поле engagement), чтобы потом можно было
// проверить, угадывают ли эти числа голоса пользователей (tools/engagement_report.php).
// Числа сами по себе ничего не значат: у каждого канала и доски своя аудитория, поэтому рядом лежит база сравнения —
// медианы по соседним постам канала и по тредам доски.

const ENGAGEMENT_VERSION = 1;

function engagement_median(array $values) {
    $values = array_values(array_filter($values, fn($x) => $x !== null));
    if (!$values) return null;
    sort($values);
    $n = count($values);
    return $n % 2 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

// ---- Telegram ----

/** Просмотры и реакции из блока поста (текст от data-post до следующего поста). views — null, если счётчика нет. */
function telegram_block_engagement($content) {
    $views = preg_match('~tgme_widget_message_views">([^<]*)~', $content, $match) ? telegram_parse_counter($match[1]) : null;
    $reactions = [];
    if (preg_match_all('~<i class="emoji"[^>]*><b>([^<]+)</b></i>([\d.,KkMm ]*)</span>~u', $content, $all, PREG_SET_ORDER)) {
        foreach ($all as $reaction) $reactions[$reaction[1]] = ($reactions[$reaction[1]] ?? 0) + telegram_parse_counter($reaction[2]);
    }
    return ['views' => $views, 'reactions' => $reactions];
}

/** Активность всех постов страницы канала t.me/s/…: [id поста => telegram_block_engagement()]. */
function telegram_page_engagement($html) {
    preg_match_all('/data-post="([^"]+)"(.*?)(?=data-post="[^"]+"|$)/s', (string)$html, $blocks, PREG_SET_ORDER);
    $page = [];
    foreach ($blocks as $block) {
        $id = explode('/', $block[1], 2)[1] ?? '';
        if (preg_match('/^[0-9]+$/', $id)) $page[$id] = telegram_block_engagement($block[2] ?? '');
    }
    return $page;
}

/** Реакций на один просмотр. */
function engagement_reaction_rate(array $post) {
    return array_sum($post['reactions']) / max(1, (int)$post['views']);
}

/**
 * Снимок активности поста Telegram: его числа и медианы по другим постам той же страницы канала (база сравнения).
 * $page — [id поста => telegram_block_engagement()] для всех постов страницы.
 */
function telegram_engagement_snapshot(array $page, $post_id, $posted_at) {
    $post = $page[$post_id] ?? null;
    if ($post === null || $post['views'] === null) return null;
    $others = array_filter($page, fn($p, $id) => (string)$id !== (string)$post_id && $p['views'] !== null, ARRAY_FILTER_USE_BOTH);
    return [
        'v' => ENGAGEMENT_VERSION,
        'source' => 'telegram',
        'at' => time(),
        'age_hours' => $posted_at ? round((time() - $posted_at) / 3600, 1) : null,
        'views' => $post['views'],
        'reactions' => $post['reactions'],
        'neighbors' => count($others),
        'neighbors_median_views' => engagement_median(array_column($others, 'views')),
        'neighbors_median_reaction_rate' => engagement_median(array_map('engagement_reaction_rate', $others))
    ];
}

// ---- Двач ----

/**
 * Активность постов треда. Ответом считается ссылка >>номер в тексте другого поста; посты самого ОПа не считаются
 * (ОП-пост собирает ответы почти всего треда, поэтому для него важнее размер и скорость треда, а не число ответов).
 * Возвращает [номер поста => снимок]. $catalog_threads — треды доски из каталога (база сравнения).
 */
function dvach_thread_engagement(array $posts, array $catalog_threads) {
    $count = count($posts);
    if ($count === 0) return [];
    $replies = [];
    foreach ($posts as $post) {
        if (!empty($post['op'])) continue;
        $number = (string)($post['num'] ?? '');
        if (!preg_match_all('~data-num="(\d+)"~', (string)($post['comment'] ?? ''), $links)) continue;
        foreach (array_unique($links[1]) as $target) if ($target !== $number) $replies[$target] = ($replies[$target] ?? 0) + 1;
    }
    $now = time();
    $board_posts = array_map(fn($t) => (int)($t['posts_count'] ?? 0), $catalog_threads);
    $board_velocity = array_map(function ($t) use ($now) {
        $age = !empty($t['timestamp']) ? max(1.0, ($now - (int)$t['timestamp']) / 3600) : 24.0;
        return (int)($t['posts_count'] ?? 0) / $age;
    }, $catalog_threads);
    $first = (int)($posts[0]['timestamp'] ?? 0);
    $last = (int)($posts[$count - 1]['timestamp'] ?? 0);
    $thread = [
        'thread_posts' => $count,
        'thread_hours' => $first && $last ? round(max(1.0, ($last - $first) / 3600), 1) : null,
        'thread_files' => array_sum(array_map(fn($p) => count($p['files'] ?? []), $posts)),
        'board_median_thread_posts' => engagement_median($board_posts),
        'board_median_velocity' => engagement_median($board_velocity) === null ? null : round(engagement_median($board_velocity), 2)
    ];

    $snapshots = [];
    foreach ($posts as $index => $post) {
        $number = (string)($post['num'] ?? '');
        $snapshots[$number] = [
            'v' => ENGAGEMENT_VERSION,
            'source' => 'dvach',
            'at' => $now,
            'is_op' => $index === 0,
            'index' => $index,
            'posts_after' => $count - $index - 1,
            'replies' => $replies[$number] ?? 0,
            'post_views' => isset($post['views']) ? (int)$post['views'] : null
        ] + $thread;
    }
    return $snapshots;
}

// ---- Дособор для записей, найденных до включения сбора ----

/**
 * Снимок активности для записи, у которой его нет (запись уже в пуле): страница канала или тред заново.
 * Помечается backfilled — числа сняты позже, чем при парсинге, и в отчёт (tools/engagement_report.php) не попадают,
 * но на карточке показываются. $cache — страницы и треды, уже скачанные в этом проходе.
 */
function engagement_backfill_item(array $item, array &$cache) {
    $snapshot = null;
    if (preg_match('~^https://t\.me/([A-Za-z0-9_]+)/(\d+)$~', source_post_url($item), $match)) {
        [$channel, $id] = [$match[1], (int)$match[2]];
        $page_key = 'tg:' . $channel . ':' . intdiv($id, 8);
        if (!isset($cache[$page_key])) {
            $html = http_get('https://t.me/s/' . rawurlencode($channel) . '?before=' . ($id + 11), [CURLOPT_USERAGENT => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36', CURLOPT_TIMEOUT => 25]);
            $cache[$page_key] = $html ? telegram_page_engagement($html) : [];
            usleep(300000);
        }
        $snapshot = isset($cache[$page_key][$id]) ? telegram_engagement_snapshot($cache[$page_key], (string)$id, (int)($item['posted_at'] ?? 0)) : null;
    } elseif (!empty($item['board']) && !empty($item['thread_id']) && !empty($item['post_id'])) {
        $thread_key = 'dv:' . $item['board'] . '/' . $item['thread_id'];
        if (!isset($cache[$thread_key])) {
            $posts = dvach_thread_posts(fetch_2ch_json($item['board'], (string)$item['thread_id'], [CURLOPT_TIMEOUT => 20]));
            if (!isset($cache['cat:' . $item['board']])) $cache['cat:' . $item['board']] = fetch_2ch_json($item['board'], null, [CURLOPT_TIMEOUT => 25])['threads'] ?? [];
            $cache[$thread_key] = $posts ? dvach_thread_engagement($posts, $cache['cat:' . $item['board']]) : [];
            usleep(300000);
        }
        $snapshot = $cache[$thread_key][(string)$item['post_id']] ?? null;
    }
    return $snapshot === null ? null : $snapshot + ['backfilled' => true];
}
