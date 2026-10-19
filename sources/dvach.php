<?php
// Двач: каталог досок, треды, текст и медиафайлы постов. Используется подбором источников и парсером.

const DVACH_PREVIEW_HTTP_OPTIONS = [
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_MAXREDIRS => 2,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS
];

const DVACH_IMAGE_EXTENSIONS = 'jpe?g|png|gif|webp';
const DVACH_VIDEO_EXTENSIONS = 'webm|mp4';

/** JSON каталога доски, если $thread_id не задан, иначе JSON треда. */
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

/** Шаблон пути файла доски: /<board>/<directory>/<число>/<имя>.<расширение>. */
function dvach_file_pattern($board, $directory, $extensions) {
    return '#^/' . preg_quote($board, '#') . '/' . $directory . '/[0-9]+/[^/]+\.(?:' . $extensions . ')$#i';
}

/**
 * Медиафайлы поста: [['image' => URL, 'video' => URL или '']].
 * У видео в 'image' лежит превью; видео без превью пропускается. $with_video = false — только картинки.
 */
function dvach_post_media($board, $post, $with_video = false) {
    $image_pattern = dvach_file_pattern($board, 'src', DVACH_IMAGE_EXTENSIONS);
    $video_pattern = dvach_file_pattern($board, 'src', DVACH_VIDEO_EXTENSIONS);
    $thumb_pattern = dvach_file_pattern($board, 'thumb', DVACH_IMAGE_EXTENSIONS);

    $media = [];
    foreach (($post['files'] ?? []) as $file) {
        $path = (string)($file['path'] ?? '');
        $thumb = (string)($file['thumbnail'] ?? '');
        if (preg_match($image_pattern, $path)) {
            $media[] = ['image' => 'https://2ch.hk' . $path, 'video' => ''];
        } elseif ($with_video && preg_match($video_pattern, $path) && preg_match($thumb_pattern, $thumb)) {
            $media[] = ['image' => 'https://2ch.hk' . $thumb, 'video' => 'https://2ch.hk' . $path];
        }
    }
    return $media;
}

/** Текст поста без HTML, ссылок на посты (>>123, >>123 (OP)) и лишних пробелов. */
function dvach_post_text($post) {
    $text = clean_html_text($post['comment'] ?? $post['subject'] ?? '');
    $text = preg_replace('/>>\d+(\s*\((?:OP|You)\))?/u', '', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

/**
 * Превью треда для карточки источника: подборка медиа со ВСЕГО треда, а не только из шапки. Все файлы треда (картинки и видео
 * с обложкой) собираются по порядку постов, и $count штук берутся через равные промежутки — от начала до конца обсуждения.
 */
function build_dvach_preview_posts($source, $count = 4) {
    $board = $source['board'] ?? '';
    $thread_id = (string)($source['thread_id'] ?? '');
    $items = [];
    foreach (dvach_thread_posts(fetch_2ch_json($board, $thread_id)) as $post) {
        foreach (dvach_post_media($board, $post, true) as $media) $items[] = [$post, $media];
    }
    $total = count($items);
    if ($total === 0) return [];
    $picked = [];
    if ($total <= $count) {
        $picked = $items;
    } else {
        for ($i = 0; $i < $count; $i++) $picked[] = $items[(int)floor(($i + 0.5) * $total / $count)];
    }
    return array_map(function ($pair) use ($source, $board, $thread_id) {
        [$post, $media] = $pair;
        $post_id = (string)($post['num'] ?? '');
        return [
            'image' => $media['image'],
            'images' => [$media['image']],
            'video' => $media['video'],
            'text' => dvach_post_text($post) ?: EMPTY_POST_TEXT,
            'source' => $source['name'] ?? '',
            'post_id' => $post_id,
            'posted_at' => (int)($post['timestamp'] ?? 0),
            'url' => dvach_post_url($board, $thread_id, $post_id)
        ];
    }, $picked);
}

/**
 * Короткое название треда для журнала и карточек: тема треда (subject) без разметки, не длиннее DVACH_THREAD_TITLE_MAX.
 * У треда без темы — номер треда, как раньше.
 */
function dvach_thread_title($thread_subject, $thread_id) {
    $title = str_replace(['[', ']'], '', clean_html_text($thread_subject));   // в журнале название — текст ссылки «[…](адрес)»
    if ($title === '') return '#' . $thread_id;
    return mb_strlen($title) > DVACH_THREAD_TITLE_MAX ? rtrim(mb_substr($title, 0, DVACH_THREAD_TITLE_MAX - 1)) . '…' : $title;
}
