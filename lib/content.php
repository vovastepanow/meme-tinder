<?php
// Пул контента и история оценок: поиск записи, перестановки в пуле, запись голоса, ссылки на исходные посты.

/** Голос из интерфейса → коллекция истории. */
const CONTENT_VOTE_COLLECTIONS = [
    'like' => 'liked',
    'dislike' => 'disliked',
    'maybe' => 'undecided',
    'wrong' => 'complaints'
];

/** Запись однозначно определяется адресом своей картинки (у видео — превью). */
function content_image_key($item) {
    if (!is_array($item)) return '';
    if (!empty($item['image']) && is_string($item['image'])) return $item['image'];
    $first = is_array($item['images'] ?? null) ? ($item['images'][0] ?? '') : '';
    return is_string($first) ? $first : '';
}

function pool_find_item(array $pool, $image) {
    foreach ($pool as $item) {
        if (content_image_key($item) === $image) return $item;
    }
    return null;
}

function pool_without_image(array $pool, $image) {
    return array_values(array_filter($pool, function ($item) use ($image) {
        return content_image_key($item) !== $image;
    }));
}

/** Отложенная запись уходит в конец очереди, порядок остальных не меняется. */
function pool_move_image_to_end(array $pool, $image) {
    $postponed = array_filter($pool, function ($item) use ($image) {
        return content_image_key($item) === $image;
    });
    return array_values(array_merge(pool_without_image($pool, $image), $postponed));
}

function content_history_record(array $item, $complaint_reason) {
    return [
        'image' => content_image_key($item),
        'text' => (string)($item['text'] ?? ''),
        'description' => $item['description'] ?? '',
        'why_relevant' => $item['why_relevant'] ?? '',
        'relevance' => $item['relevance'] ?? null,
        'rating_assigned' => $item['rating'] ?? 0,
        'topic' => $item['topic'] ?? '',
        'source' => $item['source'] ?? '',
        'board' => $item['board'] ?? '',
        'thread_id' => $item['thread_id'] ?? '',
        'post_id' => $item['post_id'] ?? '',
        'source_url' => source_post_url($item),
        'posted_at' => $item['posted_at'] ?? 0,
        'complaint_reason' => $complaint_reason,
        'timestamp' => time()
    ];
}

/** Адрес картинки для браузера: сохранённая копия из кэша, если она есть, иначе исходная ссылка. */
function content_display_image(array $item) {
    return media_cache_existing($item['image_local'] ?? '') ?: content_image_key($item);
}

/**
 * Запись для карточки, с полями, которые ждёт интерфейс. display_image / display_video — что показывать
 * (копия из кэша или исходная ссылка); image и video — исходные адреса, запасной вариант при ошибке загрузки.
 * $upcoming — следующие записи пула: их картинки браузер подгрузит заранее.
 */
function content_for_display(array $item, array $upcoming = []) {
    $item['images'] = [content_image_key($item)];
    $item['captions'] = [$item['captions'][0] ?? ($item['text'] ?? '')];
    $item['source_url'] = source_post_url($item);
    $item['display_image'] = content_display_image($item);
    $item['display_video'] = media_cache_existing($item['video_local'] ?? '') ?: ($item['video'] ?? '');
    $item['preload'] = array_values(array_filter(array_map('content_display_image', $upcoming)));
    return $item;
}

/** Ссылка на исходный пост: сохранённая (только известные площадки по HTTPS) или собранная из полей Двача. */
function source_post_url($item) {
    $existing_url = (string)($item['source_url'] ?? '');
    if (is_trusted_source_url($existing_url)) return $existing_url;

    $board = (string)($item['board'] ?? '');
    if ($board === '' && preg_match('~Двач\s+/([a-z0-9_-]+)/~iu', (string)($item['source'] ?? ''), $match)) {
        $board = $match[1];
    }
    $thread_id = (string)($item['thread_id'] ?? '');
    $post_id = (string)($item['post_id'] ?? '');
    if (!is_valid_dvach_board($board) || !is_valid_dvach_id($thread_id) || !is_valid_dvach_id($post_id)) {
        return '';
    }
    return dvach_post_url($board, $thread_id, $post_id);
}
