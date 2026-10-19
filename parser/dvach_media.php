<?php
// Двач для фонового парсера: картинки и видео из свежих тредов доски.

function dvach_media_item($board, $thread_id, $post_id, $text, $image, $video, $posted_at = 0, $engagement = null, $thread_subject = '') {
    return [
        'source_key' => 'dvach:' . $board,
        'posted_at' => (int)$posted_at,
        'source' => PLATFORM_DVACH . ' /' . $board . '/ · ' . dvach_thread_title($thread_subject, $thread_id),
        'board' => $board,
        'thread_id' => $thread_id,
        'post_id' => $post_id,
        'source_url' => dvach_post_url($board, $thread_id, $post_id),
        'image' => $image,
        'images' => [$image],
        'video' => $video,
        'captions' => [$text],
        'text' => $text,
        'engagement' => $engagement
    ];
}

/** Картинки и видео из свежих тредов одной доски (не больше 200). У видео в 'image' лежит превью. */
function parse_dvach($board, $thread_limit = DVACH_THREADS_PER_BOARD) {
    $catalog = fetch_2ch_json($board, null, []);
    if (empty($catalog['threads']) || !is_array($catalog['threads'])) return [];

    // Пропускаем закреплённые, бесконечные и закрытые треды: там правила, реферальные ссылки и реклама.
    // «Только актуальные источники» — ещё и мёртвые: пустые и те, в которые не писали DVACH_THREAD_ALIVE_HOURS часов.
    $alive_only = content_filters()['active_sources_only'];
    $threads = array_values(array_filter($catalog['threads'], function ($thread) use ($alive_only) {
        if (!empty($thread['sticky']) || !empty($thread['endless']) || !empty($thread['closed'])) return false;
        return !$alive_only || dvach_thread_is_alive($thread);
    }));

    $output = [];
    foreach (array_slice($threads, 0, $thread_limit) as $catalog_thread) {
        $thread_id = (string)($catalog_thread['num'] ?? '');
        if (!is_valid_dvach_id($thread_id)) continue;
        $posts = dvach_thread_posts(fetch_2ch_json($board, $thread_id, []));
        $engagement = dvach_thread_engagement($posts, $catalog['threads']);   // активность считается по всему треду, не только по первым постам

        foreach (array_slice($posts, 0, DVACH_POSTS_PER_THREAD) as $post) {
            $text = dvach_post_text($post);
            $post_id = (string)($post['num'] ?? '');
            // Один пост — одна запись: оценивается первый файл, остальные идут в галерею карточки
            $files = dvach_post_media($board, $post, true);
            if ($files) {
                $item = dvach_media_item($board, $thread_id, $post_id, $text, $files[0]['image'], $files[0]['video'], $post['timestamp'] ?? 0, $engagement[$post_id] ?? null, (string)($catalog_thread['subject'] ?? ''));
                if (count($files) > 1) $item['gallery'] = $files;
                $output[] = $item;
            }
            if (count($output) >= 200) return $output;
        }
    }
    return $output;
}
