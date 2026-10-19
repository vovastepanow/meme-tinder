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

/** Один кандидат на пост; если в посте несколько картинок, все они сохраняются в gallery. */
function expand_post_images($posts) {
    $candidates = [];
    foreach ($posts as $post) {
        $images = is_array($post['images'] ?? null) ? $post['images'] : [];
        if (!$images && !empty($post['image'])) $images = [$post['image']];
        $images = array_values(array_unique(array_filter($images, 'is_string')));

        if (!$images) continue;
        // Один пост — одна запись: оценивается первая картинка, остальные идут в галерею карточки
        $candidate = $post;
        $candidate['image'] = $images[0];
        $candidate['images'] = [$images[0]];
        $candidate['captions'] = [$post['captions'][0] ?? ($post['text'] ?? '')];
        if (!empty($post['gallery']) && count($post['gallery']) > 1) $candidate['gallery'] = $post['gallery'];   // альбом с видео и фото (sources/telegram.php)
        elseif (count($images) > 1) $candidate['gallery'] = array_map(fn($image) => ['image' => $image, 'video' => ''], $images);
        $candidates[] = $candidate;
    }
    return $candidates;
}

/**
 * Всё, что уже лежит в пуле или оценено (читается заново с диска): адреса картинок (главных и из галерей постов) и сами посты
 * (ключ «post:<ссылка на пост>»). Пост помнится целиком: после склейки альбомов в одну запись первой картинкой стал другой файл,
 * и без ключа поста тот же пост возвращался в подбор и снова попадал в «Предложку».
 */
function collect_known_images($pool_file, $history_file) {
    $records = load_data($pool_file, []);
    $history = load_data($history_file, []);
    foreach (HISTORY_COLLECTIONS as $key) $records = array_merge($records, $history[$key] ?? []);
    $known = [];
    foreach ($records as $record) {
        if (!is_array($record)) continue;
        foreach ([content_image_key($record), ...array_column(is_array($record['gallery'] ?? null) ? $record['gallery'] : [], 'image')] as $image) {
            if (is_string($image) && $image !== '') $known[$image] = true;
        }
        $post = candidate_post_key($record);
        if ($post !== '') $known[$post] = true;
    }
    return $known;
}

/** Убирает из пула записи, чей пост уже оценён (лайк, «не знаю», дизлайк, жалоба): такие дубли попадали туда до склейки альбомов. Возвращает число убранных. */
function prune_pool_already_rated($pool_file, $history_file) {
    $history = load_data($history_file, []);
    $known = [];
    foreach (HISTORY_COLLECTIONS as $key) {
        foreach ($history[$key] ?? [] as $record) {
            if (!is_array($record)) continue;
            $known[content_image_key($record)] = true;
            foreach (array_column(is_array($record['gallery'] ?? null) ? $record['gallery'] : [], 'image') as $image) $known[$image] = true;
            if (($post = candidate_post_key($record)) !== '') $known[$post] = true;
        }
    }
    $before = [];
    $pool = update_data($pool_file, [], function ($pool) use ($known, &$before) {
        $before = $pool;
        $kept = array_values(array_filter($pool, fn($item) => !is_array($item) || !candidate_is_known($item, $known)));
        return count($kept) !== count($pool) ? $kept : null;
    });
    $pool = is_array($pool) ? $pool : $before;
    media_cache_release_removed($before, $pool);
    return count($before) - count($pool);
}

/** Ключ поста кандидата или записи: ссылка на пост (Telegram, Двач, VK); '' — у записи нет ссылки. */
function candidate_post_key(array $item) {
    $url = (string)($item['source_url'] ?? $item['url'] ?? '');
    if ($url === '' && !empty($item['board']) && !empty($item['thread_id']) && !empty($item['post_id'])) $url = dvach_post_url($item['board'], $item['thread_id'], $item['post_id']);
    return $url !== '' ? 'post:' . preg_replace('~[?#].*$~', '', $url) . (str_contains($url, '2ch.hk') ? '#' . ($item['post_id'] ?? '') : '') : '';
}

/** Уже ли виден этот кандидат: по картинке (в том числе по любой из галереи) или по посту. */
function candidate_is_known(array $item, array $known) {
    if (isset($known[$item['image'] ?? ''])) return true;
    foreach (is_array($item['gallery'] ?? null) ? $item['gallery'] : [] as $file) if (isset($known[$file['image'] ?? ''])) return true;
    $post = candidate_post_key($item);
    return $post !== '' && isset($known[$post]);
}

/**
 * Добавляет запись в пул под блокировкой, не затирая голоса пользователя, сделанные пока парсер работал.
 * Возвращает true, если запись добавлена (false — она уже в пуле или уже оценена).
 */
function add_item_to_pool_safely($pool_file, $history_file, $item) {
    $added = false;
    update_data($pool_file, [], function ($pool) use ($pool_file, $history_file, $item, &$added) {
        if (candidate_is_known($item, collect_known_images($pool_file, $history_file))) return null;
        $pool[] = $item;
        // Лучшие — первыми: по прогнозу вкуса, а при выключенном персональном подборе — по соответствию теме
        $key = PERSONAL_TASTE_ENABLED ? 'rating' : 'relevance';
        usort($pool, function ($a, $b) use ($key) {
            return ($b[$key] ?? 0) <=> ($a[$key] ?? 0);
        });
        $added = true;
        return $pool;
    });
    return $added;
}

// ---- Кэш быстрых оценок ----
// Каждая быстро оценённая картинка запоминается вместе с темой и источником: в следующих запусках по той же теме
// отсеянные картинки не скачиваются и не оцениваются заново, а по доле попаданий источника упорядочивается очередь.

function screened_cache_load() {
    $cache = load_data(SCREENED_CACHE_FILE, []);
    $cutoff = time() - SCREENED_CACHE_DAYS * DAY_SECONDS;
    return array_filter($cache, function ($entry) use ($cutoff) {
        return is_array($entry) && (int)($entry['at'] ?? 0) >= $cutoff;
    });
}

/** Записи кэша для темы: [адрес картинки => оценка]. */
function screened_scores_for_topic(array $cache, $topic) {
    $topic = mb_strtolower($topic);
    $scores = [];
    foreach ($cache as $image => $entry) {
        if (mb_strtolower((string)($entry['topic'] ?? '')) === $topic) $scores[$image] = (int)$entry['score'];
    }
    return $scores;
}

/** Доля прошедших быструю оценку по источникам для темы (сглаженная, чтобы новые источники не были в конце). */
function source_pass_rates(array $cache, $topic) {
    $topic = mb_strtolower($topic);
    $counts = [];
    foreach ($cache as $entry) {
        if (mb_strtolower((string)($entry['topic'] ?? '')) !== $topic || empty($entry['source'])) continue;
        $key = $entry['source'];
        $counts[$key]['screened'] = ($counts[$key]['screened'] ?? 0) + 1;
        $counts[$key]['passed'] = ($counts[$key]['passed'] ?? 0) + ((int)$entry['score'] >= PRESCREEN_THRESHOLD ? 1 : 0);
    }
    $rates = [];
    foreach ($counts as $key => $count) $rates[$key] = ($count['passed'] + 1) / ($count['screened'] + 2);
    return $rates;
}

/** Добавляет новые оценки в кэш (под блокировкой) и обрезает его до SCREENED_CACHE_LIMIT самых свежих. */
function screened_cache_save(array $new_entries) {
    if (!$new_entries) return;
    update_data(SCREENED_CACHE_FILE, [], function ($cache) use ($new_entries) {
        $cache = array_merge(is_array($cache) ? $cache : [], $new_entries);
        if (count($cache) > SCREENED_CACHE_LIMIT) {
            uasort($cache, function ($a, $b) {
                return (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0);
            });
            $cache = array_slice($cache, 0, SCREENED_CACHE_LIMIT, true);
        }
        return $cache;
    });
}

