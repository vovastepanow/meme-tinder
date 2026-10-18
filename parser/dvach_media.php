<?php
// Двач для фонового парсера: картинки и видео из свежих тредов досок.

// Убирает ссылки на посты (>>123, >>123 (OP)) и лишние пробелы
function clean_dvach_text($raw_text) {
    $text = html_entity_decode(strip_tags((string)$raw_text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/>>\d+(\s*\((?:OP|You)\))?/u', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function dvach_media_item($board, $thread_id, $post_id, $text, $image, $video) {
    return [
        'source' => 'Двач /' . $board . '/ · тред #' . $thread_id . ' · пост #' . $post_id,
        'board' => $board,
        'thread_id' => $thread_id,
        'post_id' => $post_id,
        'source_url' => dvach_post_url($board, $thread_id, $post_id),
        'image' => $image,
        'images' => [$image],
        'video' => $video,
        'captions' => [$text],
        'text' => $text
    ];
}

/** Pictures and videos from the fresh threads of one board. A video item uses its thumbnail as the picture. */
function parse_dvach($board, $thread_limit = DVACH_THREADS_PER_BOARD) {
    if (!is_valid_dvach_board($board)) return [];
    $json_raw = http_get('https://2ch.hk/' . rawurlencode($board) . '/catalog.json');
    if (!$json_raw) return [];
    $res = json_decode($json_raw, true);
    if (empty($res['threads']) || !is_array($res['threads'])) return [];

    // Пропускаем закреплённые, бесконечные и закрытые треды: там правила, реферальные ссылки и реклама
    $threads = array_values(array_filter($res['threads'], function ($thread) {
        return empty($thread['sticky']) && empty($thread['endless']) && empty($thread['closed']);
    }));

    $quoted_board = preg_quote($board, '#');
    $image_pattern = '#^/' . $quoted_board . '/src/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';
    $video_pattern = '#^/' . $quoted_board . '/src/[0-9]+/[^/]+\.(?:webm|mp4)$#i';
    $thumb_pattern = '#^/' . $quoted_board . '/thumb/[0-9]+/[^/]+\.(?:jpe?g|png|gif|webp)$#i';

    $output = [];
    foreach (array_slice($threads, 0, $thread_limit) as $catalog_thread) {
        $thread_id = (string)($catalog_thread['num'] ?? '');
        if (!is_valid_dvach_id($thread_id)) continue;
        $thread_raw = http_get('https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.json');
        if (!$thread_raw) continue;
        $thread_data = json_decode($thread_raw, true);
        $thread = $thread_data['threads'][0] ?? null;
        if (!is_array($thread) || empty($thread['posts']) || !is_array($thread['posts'])) continue;

        foreach (array_slice($thread['posts'], 0, DVACH_POSTS_PER_THREAD) as $post) {
            $text = clean_dvach_text($post['comment'] ?? $post['subject'] ?? '');
            $post_id = (string)($post['num'] ?? '');
            foreach (($post['files'] ?? []) as $file) {
                $path = (string)($file['path'] ?? '');
                $thumb = (string)($file['thumbnail'] ?? '');
                if (preg_match($image_pattern, $path)) {
                    $output[] = dvach_media_item($board, $thread_id, $post_id, $text, 'https://2ch.hk' . $path, '');
                } elseif (preg_match($video_pattern, $path) && preg_match($thumb_pattern, $thumb)) {
                    $output[] = dvach_media_item($board, $thread_id, $post_id, $text, 'https://2ch.hk' . $thumb, 'https://2ch.hk' . $path);
                }
            }
            if (count($output) >= 200) return $output;
        }
    }
    return $output;
}
