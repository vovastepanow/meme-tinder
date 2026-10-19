<?php
// Сборщик (cron_parser.php): посты всех источников темы → очередь кандидатов → быстрая оценка пачками → полный анализ лучших → пул.

function post_caption_for_ai($item) { $t = trim((string)($item['text'] ?? '')); return $t === EMPTY_POST_TEXT ? '' : $t; }

/** Метка для журнала: «[источник](ссылка)» — сайт делает её ссылкой. */
function candidate_log_label($item) {
    $url = (string)($item['source_url'] ?? '');
    return "[{$item['source']}]" . (is_trusted_source_url($url) ? "($url)" : '');
}

// ---- Сбор постов ----

/** Посты Двача со свежих тредов доски (без закреплённых, бесконечных, закрытых; при «актуальных» — только живые). */
function parse_dvach($board) {
    $catalog = fetch_2ch_json($board, null, []);
    if (!is_array($catalog['threads'] ?? null)) return [];
    $alive_only = content_filters()['active_sources_only'];
    $threads = array_filter($catalog['threads'], fn($t) => empty($t['sticky']) && empty($t['endless']) && empty($t['closed']) && (!$alive_only || dvach_thread_is_alive($t)));
    $out = [];
    foreach (array_slice(array_values($threads), 0, DVACH_THREADS_PER_BOARD) as $t) {
        if (!is_valid_dvach_id($thread = (string)($t['num'] ?? ''))) continue;
        $posts = dvach_thread_posts(fetch_2ch_json($board, $thread, []));
        $engagement = dvach_thread_engagement($posts, $catalog['threads']);
        foreach (array_slice($posts, 0, DVACH_POSTS_PER_THREAD) as $post) {
            if (!($files = dvach_post_media($board, $post))) continue;
            $text = dvach_post_text($post);
            $id = (string)($post['num'] ?? '');
            $out[] = ['source_key' => "dvach:$board", 'posted_at' => (int)($post['timestamp'] ?? 0), 'board' => $board, 'thread_id' => $thread, 'post_id' => $id,
                'source' => PLATFORM_DVACH . " /$board/ · " . dvach_thread_title($t['subject'] ?? '', $thread), 'source_url' => dvach_post_url($board, $thread, $id),
                'image' => $files[0]['image'], 'images' => [$files[0]['image']], 'video' => $files[0]['video'], 'captions' => [$text], 'text' => $text,
                'engagement' => $engagement[$id] ?? null] + (count($files) > 1 ? ['gallery' => $files] : []);
            if (count($out) >= 200) return $out;
        }
    }
    return $out;
}

/** Канал или паблик каталога с учётом активности. [посты, обновлённая запись каталога|null, событие|null]. */
function collect_catalog_source($platform, array $entry) {
    if (($id = ltrim(trim((string)($entry['id'] ?? '')), '@')) === '') return [[], null, null];
    $label = $platform === PLATFORM_TELEGRAM ? "@$id" : $id;
    $active_only = content_filters()['active_sources_only'];
    if ($active_only && is_source_frozen($entry)) return [[], null, 'skipped_frozen'];
    $source = ['id' => $id, 'name' => source_display_name($platform, $entry['name'] ?? $id)];
    cli_echo("Парсинг {$platform} {$label}...");
    $gone = null;
    try {
        if ($platform === PLATFORM_TELEGRAM) {
            if (!($html = fetch_telegram_channel_page($id))) { cli_echo("-> {$platform} {$label}: страница не загрузилась."); return [[], null, null]; }
            $posts = telegram_posts_from_html($html, $source, TELEGRAM_POSTS_PER_CHANNEL);
            $facts = telegram_page_facts($html);
            [$times, $subs] = [$facts['post_times'], $facts['subscribers']];
            $rate = source_view_rate($subs, $facts['post_views']);
            if (!str_contains($html, 'data-post="')) $gone = 'канала нет или он закрыт';
        } else {
            $posts = build_vk_preview_posts($source, VK_POSTS_PER_PUBLIC);
            [$times, $subs, $rate] = [array_column($posts, 'posted_at'), 0, null];
        }
    } catch (Throwable $e) {
        cli_echo("-> {$platform} {$label}: " . $e->getMessage());
        return [[], null, null];
    }
    $activity = source_activity($times);
    [$updated, $event] = apply_source_activity($entry, $activity, null, $gone ?? source_dead_audience_reason($subs, $rate));
    if ($subs > 0) $updated['audience'] = ['subscribers' => $subs, 'view_rate' => $rate, 'checked_at' => time()];
    if ($platform === PLATFORM_TELEGRAM && preg_match('~<meta property="og:image" content="([^"]+)"~', $html, $m)) $updated['avatar'] = html_entity_decode($m[1]);
    if ($preview = source_preview_images($posts)) $updated['preview'] = $preview;
    if ($event === 'frozen' || $event === 'still_frozen') {
        $skip = $active_only || $gone !== null;
        cli_echo("-> {$platform} {$label}: " . ($event === 'frozen' ? 'заморожен' : 'всё ещё не активен') . " ({$updated['frozen_reason']}), перепроверка через " . SOURCE_FROZEN_RECHECK_DAYS . ' дн'
            . ($skip ? '.' : ' — «Только актуальные источники» выключено, посты всё равно берутся.'));
        if ($skip) return [[], $updated, $event];
    }
    if ($event === 'revived') cli_echo("-> {$platform} {$label}: ожил и разморожен (" . describe_source_activity($activity) . ').');
    $fresh = array_filter($posts, fn($p) => !is_post_outdated($p['posted_at'] ?? 0, null, content_max_age_days()));
    $playable = array_values(array_filter($fresh, fn($p) => empty($p['video_unavailable'])));   // большое видео без файла показать нечем
    [$old, $hidden] = [count($posts) - count($fresh), count($fresh) - count($playable)];
    cli_echo("-> {$platform} {$label}: найдено медиа-постов: " . count($playable) . ($old ? ", устаревших пропущено: $old" : '') . ($hidden ? ", больших видео без файла пропущено: $hidden" : '')
        . ' (' . describe_source_activity($activity) . ($subs > 0 ? '; ' . describe_source_audience($subs, $rate) : '') . ').');
    $key = source_id_for($platform, $id);
    return [array_map(fn($p) => ['source_key' => $key, 'source_url' => $p['url'] ?? '', 'video' => $p['video'] ?? ''] + $p, $playable), $updated, $event];
}

/** Длинный список досок обходится порциями: половина — лучшие по прошлым запускам, остальное — случайные. */
function dvach_boards_this_run(array $boards, $topic) {
    if (count($boards) <= DVACH_BOARDS_PER_RUN) return $boards;
    $rates = combined_source_rates(source_pass_rates(screened_cache_load(), $topic), $topic);
    $rate = fn($b, $default) => $rates["dvach:$b"] ?? $default;
    $boards = array_values(array_filter($boards, fn($b) => $rate($b, 1.0) > 0.0));
    $proven = array_values(array_filter($boards, fn($b) => $rate($b, 0.0) >= 0.2));
    usort($proven, fn($a, $b) => $rate($b, 0) <=> $rate($a, 0));
    $chosen = array_slice($proven, 0, intdiv(DVACH_BOARDS_PER_RUN, 2));
    $rest = array_values(array_diff($boards, $chosen));
    shuffle($rest);
    $chosen = array_merge($chosen, array_slice($rest, 0, DVACH_BOARDS_PER_RUN - count($chosen)));
    cli_echo('Двач: в списке ' . count($boards) . ' досок, в этот запуск — ' . count($chosen) . ' (лучшие по прошлым запускам и случайные, остальные — в следующие запуски).');
    return $chosen;
}

/** Посты всех источников темы по площадкам. */
function collect_posts_by_platform(array $db, $topic) {
    $by = [PLATFORM_TELEGRAM => [], PLATFORM_VK => [], PLATFORM_DVACH => []];
    $stats = array_fill_keys(['other_topic', 'skipped_frozen', 'frozen', 'still_frozen', 'revived'], 0);
    $updates = [];
    if (!($boards = dvach_boards_for_topic($topic))) cli_echo('Подходящих теме досок Двача нет — Двач пропущен.');
    foreach (dvach_boards_this_run($boards, $topic) as $board) {
        cli_echo("Парсинг Двача /$board/...");
        array_push($by[PLATFORM_DVACH], ...($found = parse_dvach($board)));
        cli_echo("-> Двач /$board/: найдено медиа-постов: " . count($found));
    }
    try { $vk = vk_access_token() !== ''; } catch (Throwable $e) { cli_echo('VK OAuth: ' . $e->getMessage()); $vk = false; }
    if (!$vk) cli_echo('VK OAuth не подключен; паблики VK пропущены.');
    foreach (CATALOG_KEYS as $platform => $key) {
        if ($platform === PLATFORM_VK && !$vk) continue;
        foreach ($db[$key] as $entry) {
            if (!catalog_entry_matches_topic($entry, $topic)) { $stats['other_topic']++; continue; }
            [$posts, $updated, $event] = collect_catalog_source($platform, $entry);
            if ($event !== null) $stats[$event]++;
            if ($updated !== null) $updates[$key . ':' . strtolower((string)$entry['id'])] = $updated;
            array_push($by[$platform], ...$posts);
        }
    }
    save_catalog_entry_updates($updates);
    // У новой темы мало своих источников: пока пользователь не разобрал находки подбора, посты берутся и из них
    if (topic_lacks_sources($db, $topic)) {
        $taken = 0;
        foreach ($db['source_suggestions'] as $s) {
            $platform = $s['platform'] ?? '';
            if (!isset(CATALOG_KEYS[$platform]) || ($platform === PLATFORM_VK && !$vk) || !topic_same((string)($s['topic'] ?? ''), $topic) || (int)($s['score'] ?? 0) < SOURCE_SUGGEST_SCORE) continue;
            if (++$taken > 12) break;
            $name = preg_replace('/^(Telegram|ВКонтакте) \| /u', '', (string)($s['name'] ?? $s['id']));
            array_push($by[$platform], ...collect_catalog_source($platform, ['id' => $s['id'], 'name' => $name, 'topics' => [$topic]])[0]);
        }
        if ($taken) cli_echo('Своих источников у темы мало — временно беру посты и из ' . min($taken, 12) . ' находок автоподбора (решение по ним — на вкладке «Источники»).');
    }
    $labels = ['other_topic' => 'другой темы', 'skipped_frozen' => 'заморожено (ждут перепроверки)', 'frozen' => 'заморожено сейчас', 'revived' => 'ожило'];
    $notes = array_map(fn($k) => "{$labels[$k]}: {$stats[$k]}", array_keys(array_filter(array_intersect_key($stats, $labels))));
    if ($notes) cli_echo('Источники: ' . implode(', ', $notes) . '.');
    return $by;
}

// ---- Кандидаты ----

/** Один кандидат на пост: оценивается первая картинка, остальные — в галерею. */
function expand_post_images($posts) {
    $out = [];
    foreach ($posts as $post) {
        $images = array_values(array_unique(array_filter((array)($post['images'] ?? []) ?: [$post['image'] ?? null], 'is_string')));
        if (!$images) continue;
        $c = ['image' => $images[0], 'images' => [$images[0]], 'captions' => [$post['captions'][0] ?? ($post['text'] ?? '')]] + $post;
        if (count($post['gallery'] ?? []) > 1) $c['gallery'] = $post['gallery'];
        elseif (count($images) > 1) $c['gallery'] = array_map(fn($i) => ['image' => $i, 'video' => ''], $images);
        $out[] = $c;
    }
    return $out;
}

/** Ключ поста (ссылка на пост): после склейки альбомов первой картинкой мог стать другой файл. */
function candidate_post_key(array $item) {
    $url = (string)($item['source_url'] ?? $item['url'] ?? '');
    if ($url === '' && !empty($item['board']) && !empty($item['thread_id']) && !empty($item['post_id'])) $url = dvach_post_url($item['board'], $item['thread_id'], $item['post_id']);
    return $url === '' ? '' : 'post:' . preg_replace('~[?#].*$~', '', $url) . (str_contains($url, '2ch.hk') ? '#' . ($item['post_id'] ?? '') : '');
}

/** Ключи записей: картинки (главная и галерея) и пост. */
function record_known_keys(array $records) {
    $known = [];
    foreach ($records as $r) {
        if (!is_array($r)) continue;
        foreach ([content_image_key($r), ...array_column((array)($r['gallery'] ?? []), 'image')] as $img) if (is_string($img) && $img !== '') $known[$img] = true;
        if (($post = candidate_post_key($r)) !== '') $known[$post] = true;
    }
    return $known;
}

function history_records($history_file) {
    $history = load_data($history_file, []);
    return array_merge(...array_map(fn($c) => (array)($history[$c] ?? []), HISTORY_COLLECTIONS));
}

function collect_known_images($pool_file, $history_file) { return record_known_keys(array_merge(load_data($pool_file, []), history_records($history_file))); }

function candidate_is_known(array $item, array $known) {
    foreach ([$item['image'] ?? '', ...array_column((array)($item['gallery'] ?? []), 'image')] as $img) if (isset($known[$img])) return true;
    return ($post = candidate_post_key($item)) !== '' && isset($known[$post]);
}

/** Убирает из пула записи, чей пост уже оценён. */
function prune_pool_already_rated($pool_file, $history_file) {
    $known = record_known_keys(history_records($history_file));
    $before = load_data($pool_file, []);
    $after = update_data($pool_file, [], function ($pool) use ($known) {
        $kept = array_values(array_filter($pool, fn($i) => !is_array($i) || !candidate_is_known($i, $known)));
        return count($kept) !== count($pool) ? $kept : null;
    });
    media_cache_release_removed($before, $after);
    return count($before) - count($after);
}

/** Добавляет запись в пул под блокировкой (лучшие первыми). false — уже в пуле или оценена. */
function add_item_to_pool_safely($pool_file, $history_file, $item) {
    $added = false;
    update_data($pool_file, [], function ($pool) use ($pool_file, $history_file, $item, &$added) {
        if (candidate_is_known($item, collect_known_images($pool_file, $history_file))) return null;
        $pool[] = $item;
        $key = PERSONAL_TASTE_ENABLED ? 'rating' : 'relevance';
        usort($pool, fn($a, $b) => ($b[$key] ?? 0) <=> ($a[$key] ?? 0));
        $added = true;
        return $pool;
    });
    return $added;
}

/** Если видео есть только в галерее, оно становится главным файлом записи. */
function post_item_video_promote(array &$item) {
    if (trim((string)($item['video'] ?? '')) !== '') return true;
    foreach ((array)($item['gallery'] ?? []) as $f) {
        if (trim((string)($f['video'] ?? '')) === '') continue;
        [$item['image'], $item['video']] = [$f['image'] ?? $item['image'], $f['video']];
        return true;
    }
    return false;
}

/** Очередь: без виденных, устаревших, отсеянных раньше и отключённых источников; лучшие источники первыми, площадки чередуются. До 500. */
function build_candidate_queue($by_platform, $seen, array $rejected = [], array $rates = [], array &$skipped = []) {
    $skipped = ['outdated' => 0, 'rejected_before' => 0, 'disabled_sources' => 0, 'not_video' => 0];
    $video_only = content_filters()['video_only'];
    $unique = [];
    foreach ($by_platform as $posts) foreach (expand_post_images($posts) as $item) {
        if (empty($item['image']) || candidate_is_known($item, $seen) || isset($unique[$item['image']])) continue;
        $source = content_source_key($item);
        $reason = match (true) {
            $video_only && !post_item_video_promote($item) => 'not_video',
            is_post_outdated($item['posted_at'] ?? 0, null, content_max_age_days(str_starts_with($source, 'dvach:'))) => 'outdated',
            isset($rejected[$item['image']]) => 'rejected_before',
            isset($rates[$source]) && $rates[$source] <= 0.0 => 'disabled_sources',
            default => null
        };
        if ($reason) $skipped[$reason]++; else $unique[$item['image']] = $item;
    }
    $queues = [];
    foreach ($unique as $item) $queues[content_platform($item)][content_source_key($item)][] = $item;
    foreach ($queues as $platform => $groups) {
        uksort($groups, fn($a, $b) => ($rates[$b] ?? 0.5) <=> ($rates[$a] ?? 0.5));
        $queues[$platform] = interleave(...array_map(function ($g) { shuffle($g); return $g; }, array_values($groups)));
    }
    return array_slice(interleave(...array_values($queues)), 0, 500);
}

// ---- Кэши оценок ----

function screened_cache_load() {
    return array_filter(load_data(SCREENED_CACHE_FILE, []), fn($e) => is_array($e) && (int)($e['at'] ?? 0) >= time() - SCREENED_CACHE_DAYS * DAY_SECONDS);
}

function screened_scores_for_topic(array $cache, $topic) {
    $topic = mb_strtolower($topic);
    return array_map(fn($e) => (int)$e['score'], array_filter($cache, fn($e) => mb_strtolower((string)($e['topic'] ?? '')) === $topic));
}

/** Сглаженная доля прошедших быструю оценку по источникам. */
function source_pass_rates(array $cache, $topic) {
    $counts = [];
    foreach ($cache as $e) {
        if (mb_strtolower((string)($e['topic'] ?? '')) !== mb_strtolower($topic) || empty($e['source'])) continue;
        $c = &$counts[$e['source']];
        $c = [($c[0] ?? 0) + 1, ($c[1] ?? 0) + ((int)$e['score'] >= PRESCREEN_THRESHOLD ? 1 : 0)];
        unset($c);
    }
    return array_map(fn($c) => ($c[1] + 1) / ($c[0] + 2), $counts);
}

/** Добавляет в кэш и оставляет $limit самых свежих записей. */
function cache_merge($file, array $new, $limit) {
    if (!$new) return;
    update_data($file, [], function ($cache) use ($new, $limit) {
        $cache = array_merge($cache, $new);
        if (count($cache) > $limit) { uasort($cache, fn($a, $b) => (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0)); $cache = array_slice($cache, 0, $limit, true); }
        return $cache;
    });
}

function analysis_cache_key($item, $topic) { return sha1(content_image_key($item) . '|' . mb_strtolower(trim($topic))); }

function analysis_cache_get($item, $topic) {
    $e = load_data(ANALYSIS_CACHE_FILE, [])[analysis_cache_key($item, $topic)] ?? null;
    return is_array($e) && time() - (int)($e['at'] ?? 0) <= ANALYSIS_CACHE_DAYS * DAY_SECONDS && is_array($e['analysis'] ?? null) ? $e['analysis'] : null;
}

// ---- Оценка ----

function fetch_candidate_poster($item) {
    $bytes = http_get($item['image']) ?: http_get($item['image'], [CURLOPT_TIMEOUT => 25]);
    if (!$bytes) { cli_echo('-> Превью не скачалось, пропуск.'); return null; }
    try { validate_image_bytes($bytes); } catch (Throwable $e) { cli_echo('-> Пропуск: ' . $e->getMessage()); return null; }
    return $bytes;
}

/**
 * Быстрая оценка пачки. Возвращает прошедших, лучшие первыми: [['item', 'poster', 'score']].
 * $ctx: stats, misses (промахи подряд по источникам), cached (оценки темы из кэша), new_cache, passed_total.
 */
function prescreen_batch(array $batch, $topic, array &$ctx, $deadline) {
    $passed = $below = [];
    foreach ($batch as $item) {
        if (time() > $deadline || !parser_session_alive()) break;
        $source = content_source_key($item);
        // Промахи считаются против источника, только когда у темы что-то проходит: иначе ошибается модель, а не источники
        if (!empty($ctx['passed_total']) && ($ctx['misses'][$source] ?? 0) >= PRESCREEN_SOURCE_MISS_LIMIT) { $ctx['stats']['source_skipped']++; continue; }
        cli_echo('Быстрая оценка ' . candidate_log_label($item) . ' ' . (($item['video'] ?? '') !== '' ? 'видео' : 'картинка'));
        if (($poster = fetch_candidate_poster($item)) === null) continue;
        if (($cached = $ctx['cached'][$item['image']] ?? null) !== null) {
            [$quick, $note] = [['score' => $cached, 'error' => ''], 'из кэша'];
        } else {
            $quick = quick_relevance_score($poster, post_caption_for_ai($item), $topic);
            $note = "{$quick['seconds']} с" . ($quick['detail'] !== '' ? ": {$quick['detail']}" : '');
            $ctx['stats']['prescreened']++;
        }
        $row = ['item' => $item, 'poster' => $poster, 'score' => $quick['score']];
        if ($quick['score'] === null) {
            cli_echo("-> Быстрая оценка не получена ({$note}" . ($quick['error'] ? ", {$quick['error']}" : ($quick['raw'] !== '' ? ", ответ: «{$quick['raw']}»" : ', пустой ответ')) . '), отправляю на полный анализ.');
            $passed[] = ['score' => PRESCREEN_THRESHOLD] + $row;
            continue;
        }
        if ($cached === null) $ctx['new_cache'][$item['image']] = ['score' => $quick['score'], 'topic' => $topic, 'source' => $source, 'at' => time()];
        if ($quick['score'] < PRESCREEN_THRESHOLD) {
            $ctx['stats']['rejected']++;
            $misses = $ctx['misses'][$source] = ($ctx['misses'][$source] ?? 0) + 1;
            $below[] = $row;
            cli_echo("-> Быстрая оценка {$quick['score']}% ({$note}) — отсеяно.");
            if (!empty($ctx['passed_total']) && $misses === PRESCREEN_SOURCE_MISS_LIMIT) cli_echo("-> Источник {$source}: " . PRESCREEN_SOURCE_MISS_LIMIT . ' промахов подряд — пропускаю его до конца запуска.');
            continue;
        }
        $ctx['misses'][$source] = 0;
        $ctx['passed_total'] = ($ctx['passed_total'] ?? 0) + 1;
        cli_echo("-> Быстрая оценка {$quick['score']}% ({$note}) — на полный анализ.");
        $passed[] = $row;
    }
    // Порог не прошёл никто и никогда: быстрая модель, похоже, не поняла тему — лучшие из пачки идут на полный анализ
    if (!$passed && empty($ctx['passed_total']) && $below) {
        usort($below, fn($a, $b) => $b['score'] <=> $a['score']);
        if ($passed = array_values(array_filter(array_slice($below, 0, PRESCREEN_RELATIVE_PICK), fn($r) => $r['score'] >= PRESCREEN_RELATIVE_FLOOR))) {
            cli_echo('-> Порог быстрой оценки не прошла ни одна запись — для этой темы она слишком строга. На полный анализ идут лучшие из пачки (' . implode('%, ', array_column($passed, 'score')) . '%).');
        }
    }
    usort($passed, fn($a, $b) => $b['score'] <=> $a['score']);
    return $passed;
}

/** Полный анализ кандидата (видео — раскадровкой), порог, кэш медиа и запись в пул. true — добавлено. */
function analyze_candidate($item, $poster, $history, $topic) {
    $is_video = ($item['video'] ?? '') !== '';
    cli_echo('Полный анализ ' . candidate_log_label($item) . ' ' . ($is_video ? 'видео' : 'картинка') . " {$item['image']}");
    $video = $is_video ? video_analysis_input($item['video'], $poster) : null;
    try {
        $frames = $video['frames'] ?? [$poster];
        $storyboard = $video !== null && count($frames) > 1;
        $caption = post_caption_for_ai($item);
        cli_echo('-> ' . ($storyboard ? 'Раскадровка видео уходит' : 'Картинка уходит') . " в vision-модель: описание и оценка по теме «{$topic}»...");
        if (($a = analysis_cache_get($item, $topic)) !== null) {
            cli_echo('-> Анализ взят из общего кэша: эту запись по этой теме уже оценивали.');
        } else {
            try { $a = analyze_content_with_vision($frames, $caption, $history, $topic, $storyboard ? $video : null, PARSER_THINKING, $item['source'] ?? ''); }
            catch (Throwable $e) { cli_echo('-> ИИ не вернул корректную оценку: ' . $e->getMessage()); return false; }
            $cache_cutoff = time() - ANALYSIS_CACHE_DAYS * DAY_SECONDS;
            update_data(ANALYSIS_CACHE_FILE, [], fn($c) => array_slice(array_filter([analysis_cache_key($item, $topic) => ['analysis' => $a, 'at' => time()]] + $c, fn($e) => (int)($e['at'] ?? 0) >= $cache_cutoff), 0, ANALYSIS_CACHE_LIMIT, true));
        }
        if (is_spam_content($caption, $a['description'])) { cli_echo('-> Отброшено: явный маркер рекламы или спама.'); return false; }
        if (!PERSONAL_TASTE_ENABLED) $a['rating'] = null;
        $verdict = "соответствие теме {$a['relevance']}%" . ($a['rating'] !== null ? ", прогноз оценки {$a['rating']}%" : '');
        if ($a['relevance'] < RELEVANCE_THRESHOLD) { cli_echo("-> Отброшено по порогу: {$verdict}."); return false; }
        cli_echo("-> Принято: {$verdict}.");
        if (search_topic_key() !== $topic) { cli_echo('-> Тема поиска сменилась — запись по старой теме не сохраняю.'); return false; }
        $item = ['description' => $a['description'] ?: 'Описание не получено', 'why_relevant' => $a['why_relevant'] ?: 'Нейросеть не сформулировала объяснение.',
            'relevance' => $a['relevance'], 'rating' => $a['rating'], 'topic' => $topic, 'image_local' => media_cache_store_image($item['image'], $poster)] + $item;
        if ($is_video) {
            $item['video_local'] = !empty($video['file']) ? cache_downloaded_video($item['video'], $video['file']) : media_cache_store_video($item['video']);
            cli_echo($item['video_local'] !== '' ? '-> Видео сохранено в кэш сайта.' : '-> Видео не удалось сохранить в кэш, на сайте будет исходная ссылка.');
        }
        if (!empty($item['gallery'])) $item['gallery'] = gallery_cache_files($item['gallery'], $item['image']);
        if ($item['image_local'] === '') cli_echo('-> Картинку не удалось сохранить в кэш, на сайте будет исходная ссылка.');
        $added = add_item_to_pool_safely(pool_file(), history_file(), $item);
        cli_echo($added ? '[!] Запись сохранена и выведена на сайт.' : '-> Запись уже в пуле или оценена пользователем, пропускаю.');
        return $added;
    } finally {
        if (!empty($video['file'])) @unlink($video['file']);
    }
}

function collector_stop_reason($started, $analyzed, $added) {
    return match (true) {
        !parser_session_alive() => PARSER_SESSION_ENDED,
        time() - $started > PARSER_MAX_SECONDS => 'Достигнут лимит времени одного запуска.',
        $analyzed >= CONTENT_ANALYZE_PER_RUN => 'Достигнут лимит полных анализов за запуск.',
        $added >= CONTENT_ADDED_PER_RUN => 'Достигнут лимит новых записей за запуск.',
        count(load_data(pool_file(), [])) >= CONTENT_POOL_TARGET => 'Пул заполнен до ' . CONTENT_POOL_TARGET . ' записей.',
        default => null
    };
}

/**
 * Один проход: уборка, тема и её профиль, подбор (если источников нет), сбор, пачки «быстрая оценка → анализ лучших».
 * Возвращает true, если тема или фильтры сменились и сбор нужно начать заново.
 */
function run_collector() {
    $started = time();
    if ($n = media_cache_cleanup(all_users_pool_items())) cli_echo("Кэш медиа: удалено устаревших файлов: $n.");
    if ($n = prune_pool_already_rated(pool_file(), history_file())) cli_echo("Пул: убрано записей, пост которых уже оценён: $n.");
    cache_pool_galleries();
    prune_dead_pool_items();
    $history = load_history();
    $seen = collect_known_images(pool_file(), history_file());
    $topic = current_topic();
    $key = search_topic_key();   // одна тема или «первая + вторая»
    $filters = content_filters();
    cli_echo('Модели: быстрая оценка — ' . prescreen_model() . ', полный анализ — ' . analysis_model() . (PARSER_THINKING ? ' (с размышлениями)' : ' (без размышлений)') . '.');
    topic_ensure($topic);
    topic_classify_pending(1, $topic);
    topic_profile_ensure($topic);
    dvach_boards_for_topic($topic);
    $due = source_discovery_due(load_source_db(), $topic);
    $discovered = false;
    if ($due && topic_lacks_sources(load_source_db(), $topic)) {
        $own = topic_own_sources_count(load_source_db(), $topic);
        cli_echo("У темы «{$topic}» " . ($own ? "всего {$own} своих источника" : 'пока нет своих источников') . ' — сначала ищу новые.');
        run_source_discovery($topic);
        [$due, $discovered] = [false, true];
        if (current_topic() !== $topic) return true;
    }
    if ($reason = collector_stop_reason($started, 0, 0)) {
        cli_echo($reason);
        if ($due && parser_session_alive()) run_source_discovery($topic);
        return false;
    }
    $cache = screened_cache_load();
    $scores = screened_scores_for_topic($cache, $key);
    $skipped = [];
    $candidates = build_candidate_queue(collect_posts_by_platform(load_source_db(), $topic), $seen, array_filter($scores, fn($s) => $s < PRESCREEN_THRESHOLD),
        combined_source_rates(source_pass_rates($cache, $key), $topic), $skipped);
    cli_echo("Тема поиска: «{$topic}». Кандидатов: " . count($candidates) . '.'
        . ($skipped['rejected_before'] ? " Уже отсеяны в прошлых запусках: {$skipped['rejected_before']}." : '')
        . ($skipped['outdated'] ? " Устаревших: {$skipped['outdated']}." : '')
        . ($skipped['not_video'] ? " Без видео пропущено: {$skipped['not_video']} (включено «Только с видео»)." : '')
        . ($skipped['disabled_sources'] ? " Пропущено из источников, которые пользователи оценивают плохо: {$skipped['disabled_sources']}." : '')
        . ($filters['fresh_only'] ? ' Только новый контент (за последние сутки).' : '')
        . ($filters['active_sources_only'] ? ' Только живые паблики, каналы и треды.' : ' Неактивные источники тоже используются.')
        . ' Быстрая оценка по картинке ' . PRESCREEN_MAX_SIDE . ' px, порог ' . PRESCREEN_THRESHOLD . '%.');
    $ctx = ['stats' => ['prescreened' => 0, 'rejected' => 0, 'source_skipped' => 0], 'misses' => [], 'cached' => $scores, 'new_cache' => []];
    [$analyzed, $added, $stop, $restart] = [0, 0, null, false];
    foreach (array_chunk($candidates, PRESCREEN_BATCH) as $batch) {
        if ($stop = collector_stop_reason($started, $analyzed, $added)) break;
        // Тема и фильтры перечитываются для каждой пачки: изменения на сайте действуют сразу
        $stop = current_topic() !== $topic ? 'Тема поиска изменилась на «' . current_topic() . '» — начинаю заново с новой темой.'
            : (search_topic_key() !== $key ? 'Вторая тема изменилась — собираю заново.' : (content_filters() !== $filters ? 'Фильтры подбора изменились — собираю заново.' : null));
        if ($restart = $stop !== null) break;
        $passed = prescreen_batch($batch, $key, $ctx, $started + PARSER_MAX_SECONDS);
        cache_merge(SCREENED_CACHE_FILE, $ctx['new_cache'], SCREENED_CACHE_LIMIT);
        $ctx['new_cache'] = [];
        foreach ($passed as $c) {
            if ($stop = collector_stop_reason($started, $analyzed, $added)) break 2;
            $analyzed++;
            if (analyze_candidate($c['item'], $c['poster'], $history = load_data(history_file(), $history), $key)) $added++;
        }
    }
    cache_merge(SCREENED_CACHE_FILE, $ctx['new_cache'], SCREENED_CACHE_LIMIT);
    if ($stop) cli_echo($stop);
    $s = $ctx['stats'];
    cli_echo('Сбор завершён за ' . round((time() - $started) / 60, 1) . " мин. Быстро оценено: {$s['prescreened']}, отсеяно: {$s['rejected']},"
        . " пропущено из источников не по теме: {$s['source_skipped']}, полных анализов: {$analyzed}, добавлено в пул: {$added}.");
    // Источники ничего не дали: ищем новые (не чаще DISCOVERY_SHORTAGE_INTERVAL) и, если нашлись, собираем заново
    if (!$restart && !$discovered && !$added && !$candidates && parser_session_alive() && current_topic() === $topic && !topic_same(DEFAULT_TOPIC, $topic)
        && time() - discovery_last_run($db = load_source_db(), $topic) >= DISCOVERY_SHORTAGE_INTERVAL) {
        cli_echo('Из источников темы нечего оценивать — ищу новые источники и соберу заново.');
        $before = topic_own_sources_count($db, $topic);
        run_source_discovery($topic);
        return topic_own_sources_count(load_source_db(), $topic) > $before;
    }
    if ($due && !$restart && parser_session_alive()) run_source_discovery($topic);
    return $restart;
}
