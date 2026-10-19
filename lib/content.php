<?php
// Пул контента и история оценок: поиск записи, перестановки в пуле, запись голоса, ссылки на исходные посты.

/** Коллекции истории, записи которых показываются во вкладке «Предложка» (и держат свои файлы в кэше медиа). */
const PROPOSAL_COLLECTIONS = ['liked', 'undecided'];

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

/** Ключ источника записи: telegram:<канал>, vk:<паблик>, dvach:<доска>. */
function content_source_key($item) {
    if (!empty($item['source_key'])) return (string)$item['source_key'];
    if (!empty($item['board'])) return 'dvach:' . $item['board'];
    return 'source:' . mb_strtolower((string)($item['source'] ?? ''));
}

function content_history_record(array $item, $complaint_reason) {
    return [
        'image' => content_image_key($item),
        'text' => (string)($item['text'] ?? ''),
        'description' => $item['description'] ?? '',
        'why_relevant' => $item['why_relevant'] ?? '',
        'relevance' => $item['relevance'] ?? null,
        'rating_assigned' => $item['rating'] ?? null,   // null — прогноза не было (персональный подбор выключен)
        'topic' => $item['topic'] ?? '',
        'source' => $item['source'] ?? '',
        'source_key' => content_source_key($item),
        'board' => $item['board'] ?? '',
        'thread_id' => $item['thread_id'] ?? '',
        'post_id' => $item['post_id'] ?? '',
        'source_url' => source_post_url($item),
        'posted_at' => $item['posted_at'] ?? 0,
        'engagement' => $item['engagement'] ?? null,   // активность у площадки на момент парсинга (lib/engagement.php)
        'complaint_reason' => $complaint_reason,
        'video' => (string)($item['video'] ?? ''),            // медиа поста сохраняются для вкладки «Предложка»
        'image_local' => (string)($item['image_local'] ?? ''),
        'video_local' => (string)($item['video_local'] ?? ''),
        'gallery' => content_gallery($item),
        'timestamp' => time()
    ];
}

/** Все медиафайлы поста [['image' => URL, 'video' => URL или '']]; пусто, если файл один. */
function content_gallery(array $item) {
    $gallery = [];
    foreach (is_array($item['gallery'] ?? null) ? $item['gallery'] : [] as $file) {
        if (!is_array($file) || !is_string($file['image'] ?? null) || $file['image'] === '') continue;
        $entry = ['image' => $file['image'], 'video' => is_string($file['video'] ?? null) ? $file['video'] : ''];
        foreach (['image_local', 'video_local'] as $field) if (is_string($file[$field] ?? null) && $file[$field] !== '') $entry[$field] = $file[$field];
        $gallery[] = $entry;
    }
    return count($gallery) > 1 ? $gallery : [];
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
    $main = content_image_key($item);
    $item['gallery'] = array_map(function ($file) use ($item, $main) {   // что показывать (копия из кэша или исходная ссылка)
        if ($file['image'] === $main) {   // главный файл записи: его копии лежат в самой записи (в галерее он может стоять не первым)
            $file['image_local'] = $item['image_local'] ?? '';
            $file['video_local'] = $item['video_local'] ?? '';
        }
        $file['display_image'] = media_cache_existing($file['image_local'] ?? '') ?: $file['image'];
        $file['display_video'] = $file['video'] === '' ? '' : (media_cache_existing($file['video_local'] ?? '') ?: $file['video']);
        return $file;
    }, content_gallery($item));
    if ($item['gallery'] && !in_array($main, array_column($item['gallery'], 'image'), true)) {   // главного файла в галерее нет (источник изменился): добавляем, чтобы он не пропал
        array_unshift($item['gallery'], ['image' => $main, 'video' => (string)($item['video'] ?? ''), 'image_local' => $item['image_local'] ?? '', 'video_local' => $item['video_local'] ?? '',
            'display_image' => content_display_image($item), 'display_video' => (string)(media_cache_existing($item['video_local'] ?? '') ?: ($item['video'] ?? ''))]);
    }
    $item['post_check'] = !empty($item['video']) || !empty($item['gallery']) ? telegram_preflight_item($item) : ['status' => 'ok', 'problems' => [], 'warnings' => [], 'bad' => []];
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
