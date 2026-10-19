<?php
// Проверка живости тредов Двача: записи пула из удалённых (ушедших в архив) тредов убираются, пока их не показали.

/** Состояние треда: 'alive' | 'gone' | 'unknown'. Только 404 считается «удалён»: сбой сети или 403/5xx — неизвестно. */
function dvach_thread_state($board, $thread_id, &$posts = null) {
    $posts = null;
    if (!is_valid_dvach_board($board) || !is_valid_dvach_id($thread_id)) return 'unknown';
    $url = 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.json';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'Postmaker/1.0']);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($code === 404) return 'gone';
    if ($code !== 200 || !is_string($body)) return 'unknown';
    $data = json_decode($body, true);
    $list = is_array($data) ? dvach_thread_posts($data) : [];
    if (!$list) return 'unknown';
    $posts = $list;
    return 'alive';
}

function pool_liveness_marker($uid = null) { return user_dir($uid) . '/threads-checked'; }

/**
 * Проверяет треды записей пула и убирает записи удалённых тредов (и удалённых постов живых тредов).
 * Возвращает ['threads' => проверено, 'gone' => удалено тредов, 'removed' => удалено записей]; null — ещё рано.
 */
function prune_dead_pool_items($uid = null, $dry_run = false, $force = false, $log = true) {
    $marker = pool_liveness_marker($uid);
    if (!$force && is_file($marker) && time() - filemtime($marker) < POOL_LIVENESS_INTERVAL) return null;

    $threads = [];
    foreach (load_data(pool_file($uid), []) as $item) {
        if (empty($item['board']) || empty($item['thread_id'])) continue;
        $threads[$item['board'] . '/' . $item['thread_id']] = [$item['board'], $item['thread_id']];
    }
    $dead = [];
    $dead_posts = [];
    $checked = 0;
    foreach (array_slice($threads, 0, POOL_LIVENESS_MAX_THREADS, true) as $key => [$board, $thread_id]) {
        $state = dvach_thread_state($board, $thread_id, $posts);
        $checked++;
        if ($state === 'gone') $dead[$key] = true;
        elseif ($state === 'alive') {
            $ids = [];
            foreach ($posts as $post) $ids[(string)($post['num'] ?? '')] = true;
            $dead_posts[$key] = $ids;
        }
        usleep(300000);
    }
    $removed_items = [];
    $mutator = function ($pool) use ($dead, $dead_posts, &$removed_items) {
        $kept = [];
        foreach ($pool as $item) {
            $key = ($item['board'] ?? '') . '/' . ($item['thread_id'] ?? '');
            $gone = isset($dead[$key])
                || (isset($dead_posts[$key]) && !empty($item['post_id']) && !isset($dead_posts[$key][(string)$item['post_id']]));
            if ($gone) $removed_items[] = $item; else $kept[] = $item;
        }
        return $removed_items ? $kept : null;
    };
    $before = load_data(pool_file($uid), []);
    if ($dry_run) { $mutator($before); }
    else {
        update_data(pool_file($uid), [], $mutator);
        if ($removed_items) media_cache_release_removed($before, array_values(array_udiff($before, $removed_items, fn($a, $b) => $a <=> $b)));
        @touch($marker);
    }
    $result = ['threads' => $checked, 'gone' => count($dead), 'removed' => count($removed_items)];
    if ($log && $removed_items) echo "Проверка тредов: проверено {$checked}, удалённых тредов {$result['gone']}, убрано записей из пула: {$result['removed']}.\n";
    return $result;
}
