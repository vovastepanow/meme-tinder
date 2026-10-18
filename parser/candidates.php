<?php
// Кандидаты для ИИ и пул мемов: нормализация постов, учёт уже виденных картинок, безопасная запись в пул.

// Подпись поста для ИИ: на Дваче комментарий почти всегда не относится к картинке, поэтому его не передаём
function post_caption_for_ai($meme) {
    if (str_starts_with((string)($meme['source'] ?? ''), 'Двач /')) return '';
    $text = trim((string)($meme['text'] ?? ''));
    return $text === 'Пост без текста' ? '' : $text;
}

// Нормализует пост из любого источника к одной форме кандидата
function prepare_external_post($post) {
    $post['source_url'] = $post['url'] ?? ($post['source_url'] ?? '');
    $post['captions'] = array_fill(0, count($post['images'] ?? []), $post['text'] ?? '');
    $post['video'] = $post['video'] ?? '';
    return $post;
}

/** Splits a post with several pictures into one candidate per picture. */
function expand_post_images($posts) {
    $candidates = [];
    foreach ($posts as $post) {
        $images = is_array($post['images'] ?? null) ? $post['images'] : [];
        if (!$images && !empty($post['image'])) $images = [$post['image']];
        $images = array_values(array_unique(array_filter($images, 'is_string')));

        foreach ($images as $index => $image) {
            $candidate = $post;
            $candidate['image'] = $image;
            $candidate['images'] = [$image];
            $candidate['captions'] = [$post['captions'][$index] ?? ($post['text'] ?? '')];
            $candidates[] = $candidate;
        }
    }
    return $candidates;
}

function meme_platform($meme) {
    $source = (string)($meme['source'] ?? '');
    if (str_starts_with($source, 'Telegram |')) return 'Telegram';
    if (str_starts_with($source, 'ВКонтакте |')) return 'ВКонтакте';
    return 'Двач';
}

// Все изображения, которые уже лежат в пуле или уже оценены (читается заново с диска)
function collect_known_images($pool_file, $history_file) {
    $known = array_column(load_data($pool_file, []), 'image');
    $history = load_data($history_file, []);
    foreach (HISTORY_COLLECTIONS as $key) {
        $known = array_merge($known, array_column($history[$key] ?? [], 'image'));
    }
    return array_flip(array_filter($known, 'is_string'));
}

// Добавляет мем в пул, не затирая изменения, сделанные пока парсер работал (голосование пользователя)
function add_meme_to_pool_safely($pool_file, $history_file, $meme) {
    $lock = @fopen($pool_file . '.lock', 'c+');
    if ($lock) flock($lock, LOCK_EX);
    try {
        $known = collect_known_images($pool_file, $history_file);
        $pool = load_data($pool_file, []);
        if (isset($known[$meme['image']])) {
            return [false, $pool];
        }
        $pool[] = $meme;
        usort($pool, function ($a, $b) { return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0); });
        save_data($pool_file, $pool);
        return [true, $pool];
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
