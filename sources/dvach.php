<?php
// Двач: каталог досок, треды и изображения постов.

const DVACH_PREVIEW_HTTP_OPTIONS = [
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_MAXREDIRS => 2,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
];

/** Catalog JSON when $thread_id is null, otherwise the thread JSON. */
function fetch_2ch_json($board, $thread_id = null, array $http_options = DVACH_PREVIEW_HTTP_OPTIONS) {
    if (!is_valid_dvach_board($board)) return [];
    if ($thread_id !== null && !is_valid_dvach_id($thread_id)) return [];
    $url = $thread_id === null
        ? 'https://2ch.hk/' . rawurlencode($board) . '/catalog.json'
        : 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.json';
    return http_get_json($url, $http_options);
}

function dvach_thread_posts($thread_data) {
    $thread = $thread_data['threads'][0] ?? null;
    if (!is_array($thread) || empty($thread['posts']) || !is_array($thread['posts'])) return [];
    return $thread['posts'];
}

function dvach_post_images($board, $post, $limit = PHP_INT_MAX) {
    $pattern = '#^/' . preg_quote($board, '#') . '/src/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';
    $images = [];
    foreach (($post['files'] ?? []) as $file) {
        $path = $file['path'] ?? '';
        if (preg_match($pattern, $path)) $images[] = 'https://2ch.hk' . $path;
        if (count($images) >= $limit) break;
    }
    return $images;
}

function dvach_post_text($post) {
    return clean_html_text($post['comment'] ?? $post['subject'] ?? '');
}

function build_dvach_preview_posts($source, $count = 4) {
    $board = $source['board'] ?? '';
    $posts = [];
    foreach (dvach_thread_posts(fetch_2ch_json($board, $source['thread_id'] ?? '')) as $post) {
        if (count($posts) >= $count) break;
        $images = dvach_post_images($board, $post, 4);
        if (!$images) continue;
        $text = dvach_post_text($post);
        $posts[] = [
            'image' => $images[0],
            'images' => $images,
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'],
            'post_id' => $post['num'] ?? ''
        ];
    }
    return $posts;
}
