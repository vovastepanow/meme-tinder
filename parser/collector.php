<?php
// Фоновый сборщик: посты со всех источников → очередь кандидатов → анализ моделью → пул.
// Точка входа — run_collector(); её вызывает cron_parser.php.

/**
 * Посты одного канала или паблика с учётом активности. Замороженный источник пропускается до времени
 * перепроверки; после загрузки активность пересчитывается, и источник замораживается или размораживается.
 * Возвращает [посты (без устаревших), обновлённая запись каталога или null, событие для статистики].
 */
function collect_catalog_source($platform, array $entry, $topic) {
    $id = ltrim(trim((string)($entry['id'] ?? '')), '@');
    $label = $platform === PLATFORM_TELEGRAM ? '@' . $id : $id;
    if ($id === '') return [[], null, null];
    $active_only = content_filters()['active_sources_only'];
    if ($active_only && is_source_frozen($entry)) return [[], null, 'skipped_frozen'];

    $source = ['id' => $id, 'name' => source_display_name($platform, $entry['name'] ?? $id)];
    echo "Парсинг {$platform} {$label}...\n";
    try {
        if ($platform === PLATFORM_TELEGRAM) {
            $html = fetch_telegram_channel_page($id);
            if (!$html) {
                echo "-> {$platform} {$label}: страница не загрузилась.\n";
                return [[], null, null];
            }
            $posts = telegram_posts_from_html($html, $source, TELEGRAM_POSTS_PER_CHANNEL);
            $times = telegram_post_times($html);
            $subscribers = telegram_subscribers_from_html($html);
            $view_rate = source_view_rate($subscribers, telegram_page_views($html));
            // Страница без постов: канала нет, он закрыт или стал частным (t.me/s/ тогда отдаёт только карточку канала)
            $gone = strpos($html, 'data-post="') === false ? 'канала нет или он закрыт' : null;
        } else {
            $posts = build_vk_preview_posts($source, VK_POSTS_PER_PUBLIC);
            $times = array_column($posts, 'posted_at');
            $subscribers = 0;     // число участников у VK узнаётся только при поиске сообществ
            $view_rate = null;
            $gone = null;
        }
    } catch (Throwable $error) {
        echo "-> {$platform} {$label}: " . $error->getMessage() . "\n";
        return [[], null, null];
    }

    $activity = source_activity($times);
    [$updated, $event] = apply_source_activity($entry, $activity, null, $gone ?? source_dead_audience_reason($subscribers, $view_rate));
    if ($subscribers > 0) $updated['audience'] = ['subscribers' => $subscribers, 'view_rate' => $view_rate, 'checked_at' => time()];
    // Для карточек на вкладке «Источники»: аватар канала и картинки последних постов
    if ($platform === PLATFORM_TELEGRAM && preg_match('~<meta property="og:image" content="([^"]+)"~', $html, $match)) $updated['avatar'] = html_entity_decode($match[1]);
    if ($preview = source_preview_images($posts)) $updated['preview'] = $preview;
    $recheck = 'перепроверка через ' . SOURCE_FROZEN_RECHECK_DAYS . ' дн';
    if ($event === 'frozen' || $event === 'still_frozen') {
        echo "-> {$platform} {$label}: " . ($event === 'frozen' ? 'заморожен' : 'всё ещё не активен') . " ({$updated['frozen_reason']}), {$recheck}"
            . ($active_only || $gone !== null ? ".\n" : " — «Только актуальные источники» выключено, посты всё равно берутся.\n");
        if ($active_only || $gone !== null) return [[], $updated, $event];
    }
    if ($event === 'revived') echo "-> {$platform} {$label}: ожил и разморожен (" . describe_source_activity($activity) . ").\n";

    $max_days = content_max_age_days();
    $fresh = array_values(array_filter($posts, function ($post) use ($max_days) {
        return !is_post_outdated($post['posted_at'] ?? 0, null, $max_days);
    }));
    $old = count($posts) - count($fresh);
    // Видео, которое Telegram не отдаёт в веб-версии (слишком большое): пользователю показать нечего, кроме обложки
    $playable = array_values(array_filter($fresh, fn($post) => empty($post['video_unavailable'])));
    $hidden = count($fresh) - count($playable);
    $fresh = $playable;
    echo "-> {$platform} {$label}: найдено медиа-постов: " . count($fresh) . ($old ? ", устаревших пропущено: {$old}" : '') . ($hidden ? ", больших видео без файла пропущено: {$hidden}" : '')
        . " (" . describe_source_activity($activity) . ($subscribers > 0 ? '; ' . describe_source_audience($subscribers, $view_rate) : '') . ").\n";
    $source_key = ($platform === PLATFORM_TELEGRAM ? 'telegram:' : 'vk:') . strtolower($id);
    $posts = array_map(function ($post) use ($source_key) {
        return ['source_key' => $source_key] + prepare_external_post($post);
    }, $fresh);
    return [$posts, $updated, $event];
}

/** До 4 картинок самых свежих постов источника (для карточки на вкладке «Источники»). */
function source_preview_images(array $posts) {
    usort($posts, fn($a, $b) => (int)($b['posted_at'] ?? 0) <=> (int)($a['posted_at'] ?? 0));
    $images = [];
    foreach ($posts as $post) {
        $image = (string)($post['image'] ?? '');
        if ($image !== '' && str_starts_with($image, 'https://') && !in_array($image, $images, true)) $images[] = $image;
        if (count($images) >= 4) break;
    }
    return $images;
}

/**
 * Доски на этот запуск. Длинный список (хоть все доски Двача) за один запуск не обходится — это сотни запросов:
 * берутся DVACH_BOARDS_PER_RUN досок — сначала те, что чаще давали принятые записи по теме, остальные места — случайные,
 * так что за несколько запусков обходится весь список. Доски, которые пользователи стабильно оценивают плохо, пропускаются.
 */
function dvach_boards_this_run(array $boards, $topic) {
    if (count($boards) <= DVACH_BOARDS_PER_RUN) return $boards;
    $rates = combined_source_rates(source_pass_rates(screened_cache_load(), $topic), $topic);
    $boards = array_values(array_filter($boards, fn($board) => ($rates['dvach:' . $board] ?? 1.0) > 0.0));
    $proven = array_values(array_filter($boards, fn($board) => ($rates['dvach:' . $board] ?? 0.0) >= 0.2));
    usort($proven, fn($a, $b) => $rates['dvach:' . $b] <=> $rates['dvach:' . $a]);
    $chosen = array_slice($proven, 0, intdiv(DVACH_BOARDS_PER_RUN, 2));
    $rest = array_values(array_diff($boards, $chosen));
    shuffle($rest);
    $chosen = array_merge($chosen, array_slice($rest, 0, DVACH_BOARDS_PER_RUN - count($chosen)));
    echo "Двач: в списке " . count($boards) . " досок, в этот запуск — " . count($chosen) . " (лучшие по прошлым запускам и случайные, остальные — в следующие запуски).\n";
    return $chosen;
}

/**
 * Есть ли у записи видео: в главном файле или в одном из файлов галереи. Если главный файл — картинка, а видео есть
 * в галерее, оно становится главным (с его обложкой), чтобы раскадровка и показ шли по видео. Возвращает true/false.
 */
function post_item_video_promote(array &$item) {
    if (trim((string)($item['video'] ?? '')) !== '') return true;
    foreach (is_array($item['gallery'] ?? null) ? $item['gallery'] : [] as $file) {
        if (trim((string)($file['video'] ?? '')) === '') continue;
        $item['image'] = $file['image'] ?? $item['image'];
        $item['video'] = $file['video'];
        return true;
    }
    return false;
}

/** Посты со всех источников текущей темы, сгруппированные по площадкам. */
function collect_posts_by_platform($database, $topic) {
    $posts_by_platform = [PLATFORM_TELEGRAM => [], PLATFORM_VK => [], PLATFORM_DVACH => []];
    $stats = ['other_topic' => 0, 'skipped_frozen' => 0, 'frozen' => 0, 'still_frozen' => 0, 'revived' => 0];
    $updates = [];

    $boards = dvach_boards_for_topic($topic);
    if (!$boards) echo "Подходящих теме досок Двача нет — Двач пропущен.\n";
    $boards = dvach_boards_this_run($boards, $topic);
    foreach ($boards as $board) {
        echo "Парсинг Двача /" . $board . "/...\n";
        $found = parse_dvach($board);
        echo "-> Двач /" . $board . "/: найдено медиа-постов: " . count($found) . "\n";
        array_push($posts_by_platform[PLATFORM_DVACH], ...$found);
    }

    $vk_connected = true;
    try {
        $vk_connected = vk_access_token() !== '';
    } catch (Throwable $error) {
        echo "VK OAuth: " . $error->getMessage() . "\n";
        $vk_connected = false;
    }
    if (!$vk_connected) echo "VK OAuth не подключен; паблики VK пропущены.\n";

    $catalogs = [PLATFORM_TELEGRAM => 'telegram_channels', PLATFORM_VK => 'vk_publics'];
    foreach ($catalogs as $platform => $key) {
        if ($platform === PLATFORM_VK && !$vk_connected) continue;
        foreach (($database[$key] ?? []) as $entry) {
            if (!catalog_entry_matches_topic($entry, $topic)) {
                $stats['other_topic']++;
                continue;
            }
            [$posts, $updated, $event] = collect_catalog_source($platform, $entry, $topic);
            if ($event !== null) $stats[$event]++;
            if ($updated !== null) $updates[$key . ':' . strtolower((string)$entry['id'])] = $updated;
            array_push($posts_by_platform[$platform], ...$posts);
        }
    }
    save_catalog_entry_updates($updates);

    // Своих источников у темы мало (новая тема): пока пользователь не разобрал находки автоподбора на вкладке «Источники»,
    // посты берутся и из них — иначе новая тема так и стоит пустой. В каталог они не попадают: решает пользователь.
    if (topic_lacks_sources($database, $topic)) {
        $provisional = 0;
        foreach ($database['source_suggestions'] ?? [] as $suggestion) {
            $platform = $suggestion['platform'] ?? '';
            if (!in_array($platform, [PLATFORM_TELEGRAM, PLATFORM_VK], true) || ($platform === PLATFORM_VK && !$vk_connected)) continue;
            if (!topic_same((string)($suggestion['topic'] ?? ''), $topic) || (int)($suggestion['score'] ?? 0) < SOURCE_SUGGEST_SCORE) continue;
            if ($provisional >= 12) break;
            $provisional++;
            $name = preg_replace('/^(Telegram|ВКонтакте) \| /u', '', (string)($suggestion['name'] ?? $suggestion['id']));
            [$posts] = collect_catalog_source($platform, ['id' => $suggestion['id'], 'name' => $name, 'topics' => [$topic]], $topic);
            array_push($posts_by_platform[$platform], ...$posts);
        }
        if ($provisional) echo "Своих источников у темы мало — временно беру посты и из {$provisional} находок автоподбора (решение по ним — на вкладке «Источники»).\n";
    }

    $notes = [];
    if ($stats['other_topic']) $notes[] = "другой темы: {$stats['other_topic']}";
    if ($stats['skipped_frozen']) $notes[] = "заморожено (ждут перепроверки): {$stats['skipped_frozen']}";
    if ($stats['frozen']) $notes[] = "заморожено сейчас: {$stats['frozen']}";
    if ($stats['revived']) $notes[] = "ожило: {$stats['revived']}";
    if ($notes) echo "Источники: " . implode(', ', $notes) . ".\n";
    return $posts_by_platform;
}

/**
 * Очередь кандидатов: картинки без уже виденных, устаревших и уже отсеянных по этой теме ($rejected_images).
 * Источники с большей долей попаданий в прошлых запусках ($source_rates) идут первыми; площадки чередуются,
 * чтобы ни одна не забивала очередь. До 500 кандидатов.
 */
function build_candidate_queue($posts_by_platform, $seen_images, array $rejected_images = [], array $source_rates = [], array &$skipped = []) {
    $skipped = ['outdated' => 0, 'rejected_before' => 0, 'disabled_sources' => 0, 'not_video' => 0];
    $with_video = content_filters()['video_only'];
    $unique = [];
    foreach ($posts_by_platform as $posts) {
        foreach (expand_post_images($posts) as $item) {
            if (empty($item['image']) || candidate_is_known($item, $seen_images) || isset($unique[$item['image']])) continue;
            // «Только с видео»: пост проходит, если хотя бы один его файл — видео. Если видео не первое, оно становится главным
            if ($with_video) {
                if (!post_item_video_promote($item)) { $skipped['not_video']++; continue; }
            }
            $max_days = content_max_age_days(str_starts_with(content_source_key($item), 'dvach:'));
            if (is_post_outdated($item['posted_at'] ?? 0, null, $max_days)) { $skipped['outdated']++; continue; } // слишком старый пост
            if (isset($rejected_images[$item['image']])) { $skipped['rejected_before']++; continue; }
            if (isset($source_rates[content_source_key($item)]) && $source_rates[content_source_key($item)] <= 0.0) { $skipped['disabled_sources']++; continue; } // пользователи стабильно оценивают источник плохо
            $unique[$item['image']] = $item;
        }
    }

    // Внутри площадки: группы по источнику, лучшие по доле попаданий — первыми; внутри группы — случайный порядок.
    $queues = [];
    foreach ($unique as $item) {
        $queues[content_platform($item)][content_source_key($item)][] = $item;
    }
    foreach ($queues as $platform => $groups) {
        uksort($groups, function ($a, $b) use ($source_rates) {
            return ($source_rates[$b] ?? 0.5) <=> ($source_rates[$a] ?? 0.5);
        });
        $ordered = [];
        foreach ($groups as $group) {
            shuffle($group);
            $ordered[] = $group;
        }
        // По одному кандидату из каждого источника за круг, лучшие источники — в начале круга.
        $flat = [];
        while ($ordered) {
            foreach ($ordered as $index => $group) {
                if (!$group) {
                    unset($ordered[$index]);
                    continue;
                }
                $flat[] = array_shift($ordered[$index]);
            }
        }
        $queues[$platform] = $flat;
    }
    $queues = array_values($queues);

    $candidates = [];
    while ($queues && count($candidates) < 500) {
        foreach ($queues as $index => $queue) {
            if (!$queue) {
                unset($queues[$index]);
                continue;
            }
            $candidates[] = array_shift($queues[$index]);
        }
    }
    return $candidates;
}

/** Метка кандидата для журнала: «[источник](ссылка на пост)» — сайт показывает её ссылкой (см. linkify в js/core.js). */
function candidate_log_label($item) {
    $url = (string)($item['source_url'] ?? '');
    return "[{$item['source']}]" . (is_trusted_source_url($url) ? "({$url})" : '');
}

/** Скачивает и проверяет картинку кандидата (у видео — обложку). Возвращает байты или null. */
function fetch_candidate_poster($item) {
    $poster_data = http_get($item['image']);
    if (!$poster_data) $poster_data = http_get($item['image'], [CURLOPT_TIMEOUT => 25]);   // сеть моргнула — ещё одна попытка, подольше
    if (!$poster_data) {
        echo "-> Превью не скачалось, пропуск.\n";
        return null;
    }
    try {
        validate_image_bytes($poster_data);
    } catch (Throwable $error) {
        echo "-> Пропуск: " . $error->getMessage() . "\n";
        return null;
    }
    return $poster_data;
}

/**
 * Полный анализ кандидата: кадры (для видео), анализ моделью с размышлениями, порог и запись в пул.
 * Возвращает true, если запись добавлена в пул.
 */
function analyze_candidate($item, $poster_data, $history, $topic) {
    $is_video = ($item['video'] ?? '') !== '';
    echo "Полный анализ " . candidate_log_label($item) . ' ' . ($is_video ? 'видео' : 'картинка') . " {$item['image']}\n";
    $video = $is_video ? video_analysis_input($item['video'], $poster_data) : null;
    try {
        return analyze_and_store_candidate($item, $poster_data, $video, $history, $topic);
    } finally {
        release_video_analysis($video); // скачанный ролик нужен только на время анализа и сохранения в кэш
    }
}

/**
 * Быстрая оценка пачки кандидатов. Возвращает прошедших порог, лучшие первыми:
 * [['item' => ..., 'poster' => байты, 'score' => число], ...].
 * $context: 'stats' (оценено / отсеяно / пропущено), 'misses' (промахи подряд по источникам),
 * 'cached' (оценки этой темы из кэша), 'new_cache' (новые записи для кэша).
 */
function prescreen_batch(array $batch, $topic, array &$context, $deadline) {
    $passed = [];
    $below = [];   // не прошедшие порог — на случай, если порог для темы слишком строг
    foreach ($batch as $item) {
        if (time() > $deadline || !parser_session_alive()) break;
        $source = content_source_key($item);
        // Промахи считаются против источника, только когда у темы вообще что-то проходит: если не проходит ничего,
        // ошибается быстрая модель (не поняла тему), а не источники — иначе она отключила бы их все
        if (!empty($context['passed_total']) && ($context['misses'][$source] ?? 0) >= PRESCREEN_SOURCE_MISS_LIMIT) {
            $context['stats']['source_skipped']++;
            continue; // источник не по теме — до конца запуска не тратим на него время
        }
        $kind = ($item['video'] ?? '') !== '' ? 'видео' : 'картинка';
        echo "Быстрая оценка " . candidate_log_label($item) . " {$kind}\n";
        $poster = fetch_candidate_poster($item);
        if ($poster === null) continue;
        if (!PRESCREEN_ENABLED) {
            $passed[] = ['item' => $item, 'poster' => $poster, 'score' => 0];
            continue;
        }

        $cached = $context['cached'][$item['image']] ?? null;
        if ($cached !== null) {
            $quick = ['score' => $cached, 'seconds' => 0, 'error' => ''];
            $note = 'из кэша';
        } else {
            $quick = quick_relevance_score($poster, post_caption_for_ai($item), $topic);
            $note = "{$quick['seconds']} с" . ($quick['detail'] !== '' ? ": {$quick['detail']}" : '');
            $context['stats']['prescreened']++;
        }
        if ($quick['score'] === null) {
            // Модель не дала число — не отбрасываем: решит полный анализ.
            echo "-> Быстрая оценка не получена ({$note}" . ($quick['error'] ? ", {$quick['error']}" : ($quick['raw'] !== '' ? ", ответ: «{$quick['raw']}»" : ', пустой ответ')) . "), отправляю на полный анализ.\n";
            $passed[] = ['item' => $item, 'poster' => $poster, 'score' => PRESCREEN_THRESHOLD];
            continue;
        }
        if ($cached === null) {
            $context['new_cache'][$item['image']] = ['score' => $quick['score'], 'topic' => $topic, 'source' => $source, 'at' => time()];
        }
        if ($quick['score'] < PRESCREEN_THRESHOLD) {
            $context['stats']['rejected']++;
            $context['misses'][$source] = ($context['misses'][$source] ?? 0) + 1;
            $below[] = ['item' => $item, 'poster' => $poster, 'score' => $quick['score']];
            echo "-> Быстрая оценка {$quick['score']}% ({$note}) — отсеяно.\n";
            if (!empty($context['passed_total']) && $context['misses'][$source] === PRESCREEN_SOURCE_MISS_LIMIT) {
                echo "-> Источник {$source}: " . PRESCREEN_SOURCE_MISS_LIMIT . " промахов подряд — пропускаю его до конца запуска.\n";
            }
            continue;
        }
        $context['misses'][$source] = 0;
        $context['passed_total'] = ($context['passed_total'] ?? 0) + 1;
        echo "-> Быстрая оценка {$quick['score']}% ({$note}) — на полный анализ.\n";
        $passed[] = ['item' => $item, 'poster' => $poster, 'score' => $quick['score']];
    }
    // Порог не прошёл никто ни в этой пачке, ни раньше: быстрая модель, похоже, не поняла тему (так бывает с новой или
    // необычной темой). Тогда на полный анализ идут лучшие из пачки — решает более внимательная модель.
    if (!$passed && empty($context['passed_total']) && $below) {
        usort($below, fn($a, $b) => $b['score'] <=> $a['score']);
        $best = array_values(array_filter(array_slice($below, 0, PRESCREEN_RELATIVE_PICK), fn($row) => $row['score'] >= PRESCREEN_RELATIVE_FLOOR));
        if ($best) {
            echo "-> Порог быстрой оценки не прошла ни одна запись — для этой темы она слишком строга. На полный анализ идут лучшие из пачки ("
                . implode('%, ', array_column($best, 'score')) . "%).\n";
            $passed = $best;
        }
    }
    usort($passed, function ($a, $b) {
        return $b['score'] <=> $a['score'];
    });
    return $passed;
}

function analysis_cache_key($item, $topic) {
    return sha1(content_image_key($item) . '|' . mb_strtolower(trim($topic)));
}

/** Результат полного анализа общий для всех пользователей с той же темой: вторая оценка той же записи не нужна. */
function analysis_cache_get($item, $topic) {
    $entry = load_data(ANALYSIS_CACHE_FILE, [])[analysis_cache_key($item, $topic)] ?? null;
    if (!is_array($entry) || time() - (int)($entry['at'] ?? 0) > ANALYSIS_CACHE_DAYS * 86400) return null;
    return is_array($entry['analysis'] ?? null) ? $entry['analysis'] : null;
}

function analysis_cache_put($item, $topic, array $analysis) {
    update_data(ANALYSIS_CACHE_FILE, [], function ($cache) use ($item, $topic, $analysis) {
        $cache[analysis_cache_key($item, $topic)] = ['analysis' => $analysis, 'at' => time()];
        $cache = array_filter($cache, function ($entry) {
            return time() - (int)($entry['at'] ?? 0) <= ANALYSIS_CACHE_DAYS * 86400;
        });
        if (count($cache) > ANALYSIS_CACHE_LIMIT) {
            uasort($cache, function ($a, $b) { return ($b['at'] ?? 0) <=> ($a['at'] ?? 0); });
            $cache = array_slice($cache, 0, ANALYSIS_CACHE_LIMIT, true);
        }
        return $cache;
    });
}

function analyze_and_store_candidate($item, $poster_data, $video, $history, $topic) {
    $frames = $video['frames'] ?? [$poster_data];
    $caption = post_caption_for_ai($item);
    $is_storyboard = $video !== null && count($frames) > 1;

    echo "-> " . ($is_storyboard ? 'Раскадровка видео уходит' : 'Картинка уходит') . " в vision-модель: описание и оценка по теме «{$topic}»...\n";
    $analysis = analysis_cache_get($item, $topic);
    if ($analysis !== null) {
        echo "-> Анализ взят из общего кэша: эту запись по этой теме уже оценивали.\n";
    } else {
        try {
            $analysis = analyze_content_with_vision($frames, $caption, $history, $topic, $is_storyboard ? $video : null);
        } catch (Throwable $error) {
            echo "-> ИИ не вернул корректную оценку: " . $error->getMessage() . "\n";
            return false;
        }
        analysis_cache_put($item, $topic, $analysis);
    }

    if (is_spam_content($caption, $analysis['description'])) {
        echo "-> Отброшено: явный маркер рекламы или спама.\n";
        return false;
    }
    if (!PERSONAL_TASTE_ENABLED) $analysis['rating'] = null; // анализ из кэша мог быть сделан с прогнозом
    $verdict = "соответствие теме {$analysis['relevance']}%" . ($analysis['rating'] !== null ? ", прогноз оценки {$analysis['rating']}%" : '');
    if (!passes_relevance_threshold($analysis['relevance'])) {
        echo "-> Отброшено по порогу: {$verdict}.\n";
        return false;
    }
    echo "-> Принято: {$verdict}.\n";

    $item['description'] = $analysis['description'] ?: 'Описание не получено';
    $item['why_relevant'] = $analysis['why_relevant'] ?: 'Нейросеть не сформулировала объяснение.';
    $item['relevance'] = $analysis['relevance'];
    $item['rating'] = $analysis['rating'];
    $item['topic'] = $topic;
    // Пока шёл анализ, пользователь сменил тему: запись по старой теме в пул не попадает
    if (search_topic_key() !== $topic) {
        echo "-> Тема поиска сменилась — запись по старой теме не сохраняю.\n";
        return false;
    }

    $item = cache_candidate_media($item, $poster_data, $video['file'] ?? null);
    $was_added = add_item_to_pool_safely(pool_file(), history_file(), $item);
    if ($was_added) {
        echo "[!] Запись сохранена и выведена на сайт.\n";
    } else {
        // Файл кэша не удаляем сразу: у записи, которая уже лежит в пуле, тот же файл (имя — хэш адреса).
        // Лишние файлы уберёт media_cache_cleanup().
        echo "-> Запись уже в пуле или оценена пользователем, пропускаю.\n";
    }
    return $was_added;
}

/**
 * Сохраняет медиа отобранной записи в кэш: ссылки источников со временем протухают,
 * а копия доступна, пока запись лежит в пуле. Без кэша запись показывается по исходной ссылке.
 */
function cache_candidate_media($item, $poster_data, $downloaded_video = null) {
    $item['image_local'] = media_cache_store_image($item['image'], $poster_data);
    if (($item['video'] ?? '') !== '') {
        // HEVC и другие форматы, которые браузер не воспроизведёт, перекодируются в H.264 перед сохранением.
        $playable = $downloaded_video !== null ? browser_playable_video($downloaded_video) : null;
        $item['video_local'] = media_cache_store_video($item['video'], $playable, $playable !== $downloaded_video);
        if ($playable !== null && $playable !== $downloaded_video) @unlink($playable);
        echo $item['video_local'] !== '' ? "-> Видео сохранено в кэш сайта.\n" : "-> Видео не удалось сохранить в кэш, на сайте будет исходная ссылка.\n";
    }
    if (!empty($item['gallery'])) $item['gallery'] = gallery_cache_files($item['gallery'], $item['image']);
    if ($item['image_local'] === '') echo "-> Картинку не удалось сохранить в кэш, на сайте будет исходная ссылка.\n";
    return $item;
}

/** Причина остановить сбор (лимиты из config.php) или null, если можно продолжать. */
function collector_stop_reason($started_at, $analyzed, $added) {
    if (!parser_session_alive()) return PARSER_SESSION_ENDED;
    if (time() - $started_at > PARSER_MAX_SECONDS) return 'Достигнут лимит времени одного запуска.';
    if ($analyzed >= CONTENT_ANALYZE_PER_RUN) return 'Достигнут лимит полных анализов за запуск.';
    if ($added >= CONTENT_ADDED_PER_RUN) return 'Достигнут лимит новых записей за запуск.';
    // Актуальное состояние пула с диска, а не устаревшая копия из начала запуска
    if (count(load_data(pool_file(), [])) >= CONTENT_POOL_TARGET) return 'Пул заполнен до ' . CONTENT_POOL_TARGET . ' записей.';
    return null;
}

/**
 * Один проход сбора: автоподбор источников (если пора), сбор, очередь кандидатов, затем пачками — быстрая оценка
 * и полный анализ лучших из пачки. Пачки нужны, чтобы первые записи появлялись в пуле сразу.
 * Возвращает true, если тема сменилась посреди прохода и сбор нужно начать заново.
 */
function run_collector() {
    $started_at = time();
    $removed = media_cache_cleanup(all_users_pool_items());
    if ($removed) echo "Кэш медиа: удалено устаревших файлов: {$removed}.\n";
    $dupes = prune_pool_already_rated(pool_file(), history_file());
    if ($dupes) echo "Пул: убрано записей, пост которых уже оценён: {$dupes}.\n";
    cache_pool_galleries();   // файлы галерей уже лежащих в пуле записей (новые кэшируются при добавлении)
    prune_dead_pool_items(); // записи удалённых тредов Двача уходят из пула (раз в POOL_LIVENESS_INTERVAL)
    $history = load_data(history_file(), empty_history());
    $seen_images = collect_known_images(pool_file(), history_file());

    $topic = current_topic();
    $eval_topic = search_topic_key();   // ключ оценок и пула: одна тема или «первая + вторая»
    $filters = content_filters();
    echo "Модели: быстрая оценка — " . prescreen_model() . ", полный анализ — " . analysis_model()
        . (PARSER_THINKING ? ' (с размышлениями)' : ' (без размышлений)') . ".\n";
    // Подбор новых источников идёт ПОСЛЕ сбора из известных: контент появляется сразу, а подбор (минуты работы модели)
    // не задерживает его. Сначала подбор нужен, только если для темы ещё нет ни одного источника.
    topic_ensure($topic);       // тема в реестре (lib/topics.php): своё название, вид, свои источники
    topic_classify_pending(1, $topic);   // тема без описания от умной модели получает его (текущая — первой)
    topic_profile_ensure($topic);       // профиль темы (критерии, вопрос для быстрой оценки, план поиска) — один раз на тему
    dvach_boards_for_topic($topic); // быстрый выбор досок Двача под тему — часто это уже источники для сбора
    $discovery_due = source_discovery_due(load_source_db(), $topic);
    $discovered = false;
    if ($discovery_due && topic_lacks_sources(load_source_db(), $topic)) {
        $own = topic_own_sources_count(load_source_db(), $topic);
        echo "У темы «{$topic}» " . ($own ? "всего {$own} своих источника" : 'пока нет своих источников') . " — сначала ищу новые.\n";
        run_source_discovery($topic);
        $discovery_due = false;
        $discovered = true;
        if (current_topic() !== $topic) return true;   // пока шёл подбор, пользователь сменил тему
    }
    // Пул уже заполнен (например, вкладку просто перезагрузили) — собирать посты незачем.
    if ($reason = collector_stop_reason($started_at, 0, 0)) {
        echo $reason . "\n";
        if ($discovery_due && parser_session_alive()) run_source_discovery($topic);
        return false;
    }

    $cache = screened_cache_load();
    $cached_scores = screened_scores_for_topic($cache, $eval_topic);
    $rejected_before = array_filter($cached_scores, function ($score) {
        return $score < PRESCREEN_THRESHOLD;
    });
    $posts_by_platform = collect_posts_by_platform(load_source_db(), $topic);
    $skipped = [];
    $candidates = build_candidate_queue($posts_by_platform, $seen_images, $rejected_before, combined_source_rates(source_pass_rates($cache, $eval_topic), $topic), $skipped);
    echo "Тема поиска: «{$topic}». Кандидатов: " . count($candidates) . "."
        . ($skipped['rejected_before'] ? " Уже отсеяны в прошлых запусках: {$skipped['rejected_before']}." : '')
        . ($skipped['outdated'] ? " Устаревших: {$skipped['outdated']}." : '')
        . ($skipped['not_video'] ? " Без видео пропущено: {$skipped['not_video']} (включено «Только с видео»)." : '')
        . ($skipped['disabled_sources'] ? " Пропущено из источников, которые пользователи оценивают плохо: {$skipped['disabled_sources']}." : '')
        . ($filters['fresh_only'] ? ' Только новый контент (за последние сутки).' : '')
        . ($filters['active_sources_only'] ? ' Только живые паблики, каналы и треды.' : ' Неактивные источники тоже используются.')
        . (PRESCREEN_ENABLED ? " Быстрая оценка по картинке " . PRESCREEN_MAX_SIDE . " px, порог " . PRESCREEN_THRESHOLD . "%." : '') . "\n";

    $context = [
        'stats' => ['prescreened' => 0, 'rejected' => 0, 'source_skipped' => 0],
        'misses' => [],
        'cached' => $cached_scores,
        'new_cache' => []
    ];
    $analyzed = 0;
    $added = 0;
    $stop = null;
    $restart = false;
    foreach (array_chunk($candidates, PRESCREEN_BATCH) as $batch) {
        if ($stop = collector_stop_reason($started_at, $analyzed, $added)) break;
        // Тема и фильтры читаются с диска для каждой пачки: изменения на сайте действуют сразу
        $current_topic = current_topic();
        if ($current_topic !== $topic) {
            $stop = "Тема поиска изменилась на «{$current_topic}» — начинаю заново с новой темой.";
            $restart = true;
            break;
        }
        if (search_topic_key() !== $eval_topic) {
            $stop = "Вторая тема изменилась — собираю заново.";
            $restart = true;
            break;
        }
        if (content_filters() !== $filters) {
            $stop = "Фильтры подбора изменились — собираю заново.";
            $restart = true;
            break;
        }
        $passed = prescreen_batch($batch, $eval_topic, $context, $started_at + PARSER_MAX_SECONDS);
        screened_cache_save($context['new_cache']);
        $context['new_cache'] = [];
        foreach ($passed as $candidate) {
            if ($stop = collector_stop_reason($started_at, $analyzed, $added)) break 2;
            $analyzed++;
            // История тоже читается с диска, чтобы вкусы учитывали свежие голоса
            $history = load_data(history_file(), $history);
            if (analyze_candidate($candidate['item'], $candidate['poster'], $history, $eval_topic)) $added++;
        }
    }
    screened_cache_save($context['new_cache']);
    if ($stop) echo $stop . "\n";
    $s = $context['stats'];
    $minutes = round((time() - $started_at) / 60, 1);
    echo "Сбор завершён за {$minutes} мин. Быстро оценено: {$s['prescreened']}, отсеяно: {$s['rejected']},"
        . " пропущено из источников не по теме: {$s['source_skipped']}, полных анализов: {$analyzed}, добавлено в пул: {$added}.\n";
    // Источники ничего не дали (нет своих или все молчат): ищем новые и собираем заново уже с ними.
    // Один раз за проход и не чаще DISCOVERY_SHORTAGE_INTERVAL — чтобы не крутиться впустую, если подбор тоже ничего не найдёт.
    if (!$restart && !$discovered && $added === 0 && !$candidates && parser_session_alive() && current_topic() === $topic) {
        $db = load_source_db();
        $last_run = (int)($db['discovery']['runs'][mb_strtolower(trim($topic))] ?? 0);
        if (!topic_same(DEFAULT_TOPIC, $topic) && time() - $last_run >= DISCOVERY_SHORTAGE_INTERVAL) {
            echo "Из источников темы нечего оценивать — ищу новые источники и соберу заново.\n";
            $before = topic_own_sources_count($db, $topic);
            run_source_discovery($topic);
            return topic_own_sources_count(load_source_db(), $topic) > $before;   // нашлись новые — сбор заново
        }
    }
    if ($discovery_due && !$restart && parser_session_alive()) run_source_discovery($topic);
    return $restart;
}
