<?php
// Двач для фонового парсера: картинки и видео из свежих тредов доски.

function dvach_media_item($board, $thread_id, $post_id, $text, $image, $video, $posted_at = 0) {
    return [
        'posted_at' => (int)$posted_at,
        'source' => PLATFORM_DVACH . ' /' . $board . '/ · тред #' . $thread_id . ' · пост #' . $post_id,
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

/** Картинки и видео из свежих тредов одной доски (не больше 200). У видео в 'image' лежит превью. */
function parse_dvach($board, $thread_limit = DVACH_THREADS_PER_BOARD) {
    $catalog = fetch_2ch_json($board, null, []);
    if (empty($catalog['threads']) || !is_array($catalog['threads'])) return [];

    // Пропускаем закреплённые, бесконечные и закрытые треды: там правила, реферальные ссылки и реклама.
    $threads = array_values(array_filter($catalog['threads'], function ($thread) {
        return empty($thread['sticky']) && empty($thread['endless']) && empty($thread['closed']);
    }));

    $output = [];
    foreach (array_slice($threads, 0, $thread_limit) as $catalog_thread) {
        $thread_id = (string)($catalog_thread['num'] ?? '');
        if (!is_valid_dvach_id($thread_id)) continue;
        $posts = dvach_thread_posts(fetch_2ch_json($board, $thread_id, []));

        foreach (array_slice($posts, 0, DVACH_POSTS_PER_THREAD) as $post) {
            $text = dvach_post_text($post);
            $post_id = (string)($post['num'] ?? '');
            foreach (dvach_post_media($board, $post, true) as $media) {
                $output[] = dvach_media_item($board, $thread_id, $post_id, $text, $media['image'], $media['video'], $post['timestamp'] ?? 0);
            }
            if (count($output) >= 200) return $output;
        }
    }
    return $output;
}
