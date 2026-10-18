<?php
// Кандидаты для ИИ и пул мемов: нормализация постов, учёт уже виденных картинок, безопасная запись в пул.

/** Подпись поста для ИИ. На Дваче комментарий почти всегда не относится к картинке, поэтому его не передаём. */
function post_caption_for_ai($meme) {
    if (meme_platform($meme) === PLATFORM_DVACH) return '';
    $text = trim((string)($meme['text'] ?? ''));
    return $text === EMPTY_POST_TEXT ? '' : $text;
}

/** Приводит пост из Telegram или VK к форме кандидата. */
function prepare_external_post($post) {
    $post['source_url'] = $post['url'] ?? ($post['source_url'] ?? '');
    $post['captions'] = array_fill(0, count($post['images'] ?? []), $post['text'] ?? '');
    $post['video'] = $post['video'] ?? '';
    return $post;
}

/** Делит пост с несколькими картинками на отдельных кандидатов — по одному на картинку. */
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

/** Все картинки, которые уже лежат в пуле или уже оценены (читается заново с диска). Ключи — адреса картинок. */
function collect_known_images($pool_file, $history_file) {
    $known = array_map('meme_image_key', load_data($pool_file, []));
    $history = load_data($history_file, []);
    foreach (HISTORY_COLLECTIONS as $key) {
        $known = array_merge($known, array_column($history[$key] ?? [], 'image'));
    }
    return array_flip(array_filter($known, function ($image) {
        return is_string($image) && $image !== '';
    }));
}

/**
 * Добавляет мем в пул под блокировкой, не затирая голоса пользователя, сделанные пока парсер работал.
 * Возвращает true, если мем добавлен (false — он уже в пуле или уже оценён).
 */
function add_meme_to_pool_safely($pool_file, $history_file, $meme) {
    $added = false;
    update_data($pool_file, [], function ($pool) use ($pool_file, $history_file, $meme, &$added) {
        if (isset(collect_known_images($pool_file, $history_file)[$meme['image']])) return null;
        $pool[] = $meme;
        usort($pool, function ($a, $b) {
            return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
        });
        $added = true;
        return $pool;
    });
    return $added;
}
