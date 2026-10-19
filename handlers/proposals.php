<?php
// Вкладка «Предложка»: записи, которые вы лайкнули или отметили «не знаю». Берутся из истории оценок; файлы остаются в кэше медиа.

/** GET: записи для карточек, новые первыми. У каждой — vote ('liked' | 'undecided') и поля для показа, как у обычной записи. */
function handle_proposals($debug_mode) {
    $refreshing = !$debug_mode && proposal_backfill_async();   // записи, не сверенные с источником, достраиваются в фоне, а не пока вы ждёте
    $history = $debug_mode ? (debug_state()['history'] ?? empty_history()) : load_data(history_file(), empty_history());
    $items = [];
    foreach (PROPOSAL_COLLECTIONS as $collection) {
        foreach ($history[$collection] ?? [] as $record) {
            if (!is_array($record) || !empty($record['proposal_hidden']) || content_image_key($record) === '') continue;
            $item = content_for_display($record);
            $item['vote'] = $collection;
            $items[] = $item;
        }
    }
    usort($items, fn($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
    json_response(['items' => array_slice($items, 0, PROPOSALS_LIMIT), 'refreshing' => $refreshing]);
}

/** POST image: убрать запись из «Предложки». В истории она остаётся (оценка нужна статистике), пропадает только из вкладки. */
function handle_proposal_hide($input, $debug_mode) {
    $image = (string)($input['image'] ?? '');
    if ($image === '') json_error('Не указана запись.');
    if ($debug_mode) {
        $state = &debug_state();
        foreach (PROPOSAL_COLLECTIONS as $collection) {
            foreach ($state['history'][$collection] as &$record) if (content_image_key($record) === $image) $record['proposal_hidden'] = true;
            unset($record);
        }
        json_response(['status' => 'success', 'debug' => true]);
    }
    $hidden = [];
    update_data(history_file(), empty_history(), function ($history) use ($image, &$hidden) {
        foreach (PROPOSAL_COLLECTIONS as $collection) {
            foreach ($history[$collection] ?? [] as $index => $record) {
                if (content_image_key($record) === $image && empty($record['proposal_hidden'])) {
                    $history[$collection][$index]['proposal_hidden'] = true;
                    $hidden[] = $record;
                }
            }
        }
        return $hidden ? $history : null;
    });
    foreach ($hidden as $record) {   // файлы больше никому не нужны, если их не держит чужой пул
        foreach (media_cache_item_urls($record) as $url) media_cache_remove($url);
    }
    json_response(['status' => 'success']);
}

/**
 * Дополняет запись истории медиафайлами поста (видео, остальные картинки): записи, оценённые до появления «Предложки»,
 * хранили только обложку, и видео показывалось картинкой. Возвращает ['video' =>, 'gallery' =>] или null, если пост не удалось получить
 * (тогда запись проверится позже); у удалённого поста поля пустые.
 */
function proposal_fetch_media(array $record) {
    $image = content_image_key($record);
    $url = (string)($record['source_url'] ?? '');
    if (preg_match('~^https://t\.me/([A-Za-z0-9_]{3,64})/(\d+)$~', $url, $match)) {
        $html = http_get('https://t.me/s/' . rawurlencode($match[1]) . '?before=' . ((int)$match[2] + 1), [
            CURLOPT_TIMEOUT => 12, CURLOPT_MAXREDIRS => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Postmaker/1.0)'
        ]);
        if (!$html) return null;
        foreach (telegram_posts_from_html($html, ['id' => $match[1]], 30) as $post) {
            if ((string)$post['post_id'] !== $match[2]) continue;
            // Ссылки на картинки Telegram живут недолго (потом 404, хотя пост на месте): отдаём свежую ссылку на главный файл записи
            $fresh = $post['image'];
            $stored = array_column(is_array($record['gallery'] ?? null) ? $record['gallery'] : [], 'image');
            $position = array_search($image, $stored, true);
            if ($position !== false && isset($post['gallery'][$position]['image'])) $fresh = $post['gallery'][$position]['image'];
            return ['video' => $post['video'], 'gallery' => $post['gallery'] ?? [], 'image' => $fresh];
        }
        return ['video' => '', 'gallery' => []];
    }
    $board = (string)($record['board'] ?? '');
    $thread_id = (string)($record['thread_id'] ?? '');
    $post_id = (string)($record['post_id'] ?? '');
    if ($board !== '' && $thread_id !== '' && $post_id !== '') {
        $data = fetch_2ch_json($board, $thread_id, []);
        if (!$data) return null;
        foreach (dvach_thread_posts($data) as $post) {
            if ((string)($post['num'] ?? '') !== $post_id) continue;
            $files = dvach_post_media($board, $post, true);
            $own = null;
            foreach ($files as $file) if ($file['image'] === $image) $own = $file;
            return ['video' => $own['video'] ?? '', 'gallery' => count($files) > 1 ? $files : []];
        }
    }
    return ['video' => '', 'gallery' => []];
}

/** Дополняет до $limit записей «Предложки», у которых медиа поста ещё не сверялось с источником (media_checked), см. proposal_fetch_media. Возвращает число обновлённых. */
function proposal_backfill_media($limit = 6) {
    $todo = [];
    $history = load_data(history_file(), empty_history());
    foreach (PROPOSAL_COLLECTIONS as $collection) {
        foreach ($history[$collection] ?? [] as $record) {
            if (is_array($record) && empty($record['media_cached']) && empty($record['proposal_hidden']) && count($todo) < $limit) $todo[content_image_key($record)] = $record;
        }
    }
    $found = [];
    foreach ($todo as $image => $record) {
        $media = proposal_fetch_media($record);
        if ($media === null) continue;
        $found[$image] = proposal_cache_media($record, $media);
    }
    if (!$found) return 0;
    update_data(history_file(), empty_history(), function ($history) use ($found) {
        foreach (PROPOSAL_COLLECTIONS as $collection) {
            foreach ($history[$collection] ?? [] as $index => $record) {
                $key = content_image_key($record);
                if (isset($found[$key]) && empty($record['media_cached'])) {
                    $media = $found[$key];   // пустой ответ не затирает то, что запись уже знала
                    $history[$collection][$index] = ['video' => $media['video'] ?: (string)($record['video'] ?? ''),
                        'gallery' => $media['gallery'] ?: ($record['gallery'] ?? []),
                        'video_local' => $media['video_local'] ?: (string)($record['video_local'] ?? ''),
                        'image_local' => $media['image_local'] ?: (string)($record['image_local'] ?? ''),
                        'media_checked' => true, 'media_cached' => true] + $record;
                }
            }
        }
        return $history;
    });
    return count($found);
}

/**
 * Сохраняет файлы поста в кэш сайта, пока ссылки свежие: у Telegram они с токеном и через несколько часов перестают открываться
 * (видео в «Предложке» тогда не играло), у Двача файлы исчезают вместе с тредом. Возвращает $media с video_local / image_local
 * и галереей, где у файлов есть свои копии.
 */
function proposal_cache_media(array $record, array $media) {
    require_once __DIR__ . '/../parser/video.php';
    $media += ['video_local' => '', 'image_local' => ''];
    $video = (string)($media['video'] ?: ($record['video'] ?? ''));
    if ($video !== '' && empty($record['video_local'])) {
        $downloaded = download_video_temp($video);
        if ($downloaded !== null) {
            $playable = browser_playable_video($downloaded);
            $media['video_local'] = media_cache_store_video($video, $playable, $playable !== $downloaded);
            if ($playable !== null && $playable !== $downloaded) @unlink($playable);
            @unlink($downloaded);
        }
    }
    if (empty($record['image_local']) || media_cache_existing($record['image_local']) === '') {
        $bytes = gallery_download_image(content_image_key($record));
        if ($bytes === null && !empty($media['image'])) $bytes = gallery_download_image($media['image']);   // записанная ссылка протухла — берём свежую из поста
        if ($bytes !== null) $media['image_local'] = media_cache_store_image(content_image_key($record), gallery_prepare_image($bytes));
    }
    $gallery = $media['gallery'] ?: ($record['gallery'] ?? []);
    if (count($gallery) > 1) $media['gallery'] = gallery_cache_files($gallery, content_image_key($record));   // у файлов, у которых копии уже есть, пропускается
    return $media;
}

/**
 * Запускает достройку записей «Предложки» в фоне (tools/backfill_proposals.php), если есть не сверенные с источником.
 * Раньше она шла прямо в запросе: десяток обращений к Telegram и Двачу — и вкладка открывалась долго. Возвращает true, если
 * достройка идёт (интерфейс перезагрузит список чуть позже).
 */
function proposal_backfill_async() {
    $pending = false;
    foreach (load_data(history_file(), empty_history()) as $collection => $records) {
        if (!in_array($collection, PROPOSAL_COLLECTIONS, true)) continue;
        foreach ($records as $record) {
            if (is_array($record) && empty($record['media_cached']) && empty($record['proposal_hidden'])) { $pending = true; break 2; }
        }
    }
    $marker = user_dir() . '/proposals-checked';
    $liveness_due = !is_file($marker) || time() - filemtime($marker) >= POOL_LIVENESS_INTERVAL;   // пора проверить, живы ли треды
    if (!$pending && !$liveness_due) return false;
    $lock = sys_get_temp_dir() . '/postmaker-backfill-' . app_user_id() . '.lock';
    if (is_file($lock) && time() - (int)filemtime($lock) < 180) return true;   // уже идёт
    @touch($lock);
    exec('/usr/bin/php ' . escapeshellarg(__DIR__ . '/../tools/backfill_proposals.php') . ' ' . escapeshellarg(app_user_id()) . ' >/dev/null 2>&1 &');
    return true;
}

/**
 * Прячет из «Предложки» посты мёртвых тредов Двача (тред удалён или поста в нём уже нет), если у записи нет своей копии картинки:
 * такие карточки показывали пустые плитки. Записи с сохранённой копией остаются — их по-прежнему можно запостить.
 * Проверка раз в POOL_LIVENESS_INTERVAL, не больше POOL_LIVENESS_MAX_THREADS тредов за раз. Возвращает число скрытых записей.
 */
function proposal_prune_dead_threads($force = false) {
    $marker = user_dir() . '/proposals-checked';
    if (!$force && is_file($marker) && time() - filemtime($marker) < POOL_LIVENESS_INTERVAL) return 0;
    $threads = [];
    foreach (load_data(history_file(), empty_history()) as $collection => $records) {
        if (!in_array($collection, PROPOSAL_COLLECTIONS, true)) continue;
        foreach ($records as $record) {
            if (is_array($record) && empty($record['proposal_hidden']) && !empty($record['board']) && !empty($record['thread_id'])) {
                $threads[$record['board'] . '/' . $record['thread_id']] = [$record['board'], $record['thread_id']];
            }
        }
    }
    $dead = [];
    $alive_posts = [];
    foreach (array_slice($threads, 0, POOL_LIVENESS_MAX_THREADS, true) as $key => [$board, $thread_id]) {
        $state = dvach_thread_state($board, $thread_id, $posts);
        if ($state === 'gone') $dead[$key] = true;
        elseif ($state === 'alive') $alive_posts[$key] = array_flip(array_map(fn($post) => (string)($post['num'] ?? ''), $posts));
        usleep(300000);
    }
    $hidden = 0;
    update_data(history_file(), empty_history(), function ($history) use ($dead, $alive_posts, &$hidden) {
        foreach (PROPOSAL_COLLECTIONS as $collection) {
            foreach ($history[$collection] ?? [] as $index => $record) {
                $key = ($record['board'] ?? '') . '/' . ($record['thread_id'] ?? '');
                if (!empty($record['proposal_hidden']) || (!isset($dead[$key]) && !(isset($alive_posts[$key]) && !empty($record['post_id']) && !isset($alive_posts[$key][(string)$record['post_id']])))) continue;
                if (media_cache_existing($record['image_local'] ?? '') !== '') continue;   // своя копия есть: карточка работает
                $history[$collection][$index]['proposal_hidden'] = true;
                $history[$collection][$index]['proposal_dead'] = true;
                $hidden++;
            }
        }
        return $hidden ? $history : null;
    });
    @touch($marker);
    return $hidden;
}
