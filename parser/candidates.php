<?php
// Кандидаты для ИИ и пул контента: нормализация постов, учёт уже виденных картинок, безопасная запись в пул.

/** Подпись поста для ИИ (модель сама решает, связана ли она с картинкой). */
function post_caption_for_ai($item) {
    $text = trim((string)($item['text'] ?? ''));
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
    $known = array_map('content_image_key', load_data($pool_file, []));
    $history = load_data($history_file, []);
    foreach (HISTORY_COLLECTIONS as $key) {
        $known = array_merge($known, array_column($history[$key] ?? [], 'image'));
    }
    return array_flip(array_filter($known, function ($image) {
        return is_string($image) && $image !== '';
    }));
}

/**
 * Добавляет запись в пул под блокировкой, не затирая голоса пользователя, сделанные пока парсер работал.
 * Возвращает true, если запись добавлена (false — она уже в пуле или уже оценена).
 */
function add_item_to_pool_safely($pool_file, $history_file, $item) {
    $added = false;
    update_data($pool_file, [], function ($pool) use ($pool_file, $history_file, $item, &$added) {
        if (isset(collect_known_images($pool_file, $history_file)[$item['image']])) return null;
        $pool[] = $item;
        usort($pool, function ($a, $b) {
            return ($b['rating'] ?? 0) <=> ($a['rating'] ?? 0);
        });
        $added = true;
        return $pool;
    });
    return $added;
}
