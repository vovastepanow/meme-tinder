<?php
// Пул мемов и история оценок: поиск мема, перестановки в пуле, запись голоса, ссылки на исходные посты.

/** Голос из интерфейса → коллекция истории. Жалобы хранятся в wrong_topic_memes для совместимости со старыми файлами. */
const MEME_VOTE_COLLECTIONS = [
    'like' => 'liked_memes',
    'dislike' => 'disliked_memes',
    'maybe' => 'undecided_memes',
    'wrong' => 'wrong_topic_memes'
];

/** Мем однозначно определяется адресом своей картинки (у видео — превью). */
function meme_image_key($meme) {
    if (!is_array($meme)) return '';
    if (!empty($meme['image']) && is_string($meme['image'])) return $meme['image'];
    $first = is_array($meme['images'] ?? null) ? ($meme['images'][0] ?? '') : '';
    return is_string($first) ? $first : '';
}

function pool_find_meme(array $pool, $image) {
    foreach ($pool as $meme) {
        if (meme_image_key($meme) === $image) return $meme;
    }
    return null;
}

function pool_without_image(array $pool, $image) {
    return array_values(array_filter($pool, function ($meme) use ($image) {
        return meme_image_key($meme) !== $image;
    }));
}

/** Отложенный мем уходит в конец очереди, порядок остальных не меняется. */
function pool_move_image_to_end(array $pool, $image) {
    $postponed = array_filter($pool, function ($meme) use ($image) {
        return meme_image_key($meme) === $image;
    });
    return array_values(array_merge(pool_without_image($pool, $image), $postponed));
}

function meme_history_record(array $meme, $complaint_reason) {
    return [
        'image' => meme_image_key($meme),
        'text' => (string)($meme['text'] ?? ''),
        'ai_short_description' => $meme['ai_description'] ?? 'Без описания ИИ',
        'why_funny' => $meme['why_funny'] ?? '',
        'is_funny' => $meme['is_funny'] ?? null,
        'meme_probability' => $meme['meme_probability'] ?? null,
        'rating_assigned' => $meme['rating'] ?? 0,
        'source' => $meme['source'] ?? '',
        'board' => $meme['board'] ?? '',
        'thread_id' => $meme['thread_id'] ?? '',
        'post_id' => $meme['post_id'] ?? '',
        'source_url' => source_post_url($meme),
        'complaint_reason' => $complaint_reason,
        'timestamp' => time()
    ];
}

/** Мем для карточки: первый в пуле, с полями, которые ждёт интерфейс. */
function meme_for_display(array $meme) {
    $meme['images'] = [meme_image_key($meme)];
    $meme['captions'] = [$meme['captions'][0] ?? ($meme['text'] ?? '')];
    $meme['source_url'] = source_post_url($meme);
    return $meme;
}

/** Ссылка на исходный пост: сохранённая (только известные площадки по HTTPS) или собранная из полей Двача. */
function source_post_url($meme) {
    $existing_url = (string)($meme['source_url'] ?? '');
    if (is_trusted_source_url($existing_url)) return $existing_url;

    $board = (string)($meme['board'] ?? '');
    if ($board === '' && preg_match('~Двач\s+/([a-z0-9_-]+)/~iu', (string)($meme['source'] ?? ''), $match)) {
        $board = $match[1];
    }
    $thread_id = (string)($meme['thread_id'] ?? '');
    $post_id = (string)($meme['post_id'] ?? '');
    if (!is_valid_dvach_board($board) || !is_valid_dvach_id($thread_id) || !is_valid_dvach_id($post_id)) {
        return '';
    }
    return dvach_post_url($board, $thread_id, $post_id);
}
