<?php
// Обработчики вкладок «Подбор» и «Тест ИИ»: текущая запись, голоса, тема поиска, состояние парсера.

const CONTENT_POSTPONE_VOTE = 'postpone';
const CONTENT_PRELOAD_COUNT = 3; // сколько следующих картинок браузер подгружает заранее

function handle_get_current_item($debug_mode) {
    $pool = $debug_mode ? debug_state()['pool'] : pool_for_current_topic();
    if ($pool) json_response(content_for_display($pool[0], array_slice($pool, 1, CONTENT_PRELOAD_COUNT)));

    $parser_status = $debug_mode ? 'cooldown' : start_content_parser_if_needed();
    if (in_array($parser_status, ['started', 'running'], true)) {
        json_response(['status' => 'loading', 'message' => 'Ищем контент...']);
    }
    json_response(['status' => 'empty', 'message' => 'Нового контента пока нет. Можно повторить поиск или добавить запись вручную.']);
}

/** Состояние парсера и хвост его журнала — консоль сайта опрашивает это постоянно. */
function handle_parser_status($debug_mode) {
    if ($debug_mode) json_response(['running' => false, 'activity' => []]);
    json_response(['running' => content_parser_is_running(), 'activity' => content_parser_activity()]);
}

/** Идентификатор вкладки из запроса или ошибка 400. */
function request_tab_id($input) {
    $tab = $input['tab'] ?? '';
    if (!is_valid_tab_id($tab)) json_error('Некорректный идентификатор вкладки.');
    return $tab;
}

/** Вкладка открыта: прежние процессы останавливаются, поиск начинается заново, привязанный к этой вкладке. */
function handle_tab_open($input, $debug_mode) {
    $tab = request_tab_id($input);
    if ($debug_mode) json_response(['status' => 'success', 'debug' => true]);
    json_response(['status' => 'success', 'parser' => tab_session_open($tab)]);
}

/** Сигнал живой вкладки. owner = false — приложение открыто в другой, более новой вкладке. */
function handle_tab_heartbeat($input, $debug_mode) {
    $tab = request_tab_id($input);
    if ($debug_mode) json_response(['owner' => true, 'debug' => true]);
    json_response(tab_session_heartbeat($tab));
}

/** Вкладка закрыта: если она вела сессию, процессы останавливаются. */
function handle_tab_close($input, $debug_mode) {
    $tab = request_tab_id($input);
    if ($debug_mode) json_response(['status' => 'success', 'debug' => true]);
    json_response(['status' => 'success', 'stopped' => tab_session_close($tab)]);
}

/** Есть ли видео в записи: в главном файле или в галерее (см. post_item_video_promote в parser/collector.php). */
function post_item_has_video(array $item) {
    if (trim((string)($item['video'] ?? '')) !== '') return true;
    foreach (is_array($item['gallery'] ?? null) ? $item['gallery'] : [] as $file) {
        if (trim((string)($file['video'] ?? '')) !== '') return true;
    }
    return false;
}

/**
 * Пул без записей другой темы и без не подходящих под фильтры: устаревших («Только новый контент») и без видео («Только с видео»). Такие записи остаются в пуле,
 * если тему сменили, пока парсер дописывал запись, — они убираются здесь, чтобы пользователь не видел старую тему.
 */
function pool_for_current_topic() {
    $topic = current_topic();
    $filters = content_filters();
    $fresh_days = $filters['fresh_only'] ? CONTENT_FRESH_MAX_AGE_DAYS : null;
    $keep = function ($item) use ($topic, $fresh_days, $filters) {
        if (($item['topic'] ?? '') !== '' && !topic_same((string)$item['topic'], $topic) && !topic_same((string)$item['topic'], search_topic_key())) return false;
        if ($filters['video_only'] && !post_item_has_video($item)) return false;
        return $fresh_days === null || !is_post_outdated($item['posted_at'] ?? 0, null, $fresh_days);
    };
    $pool = load_data(pool_file(), []);
    if (count(array_filter($pool, $keep)) === count($pool)) return $pool;
    return update_data(pool_file(), [], fn($pool) => array_values(array_filter(is_array($pool) ? $pool : [], $keep)));
}

function handle_get_settings($debug_mode) {
    $topic = $debug_mode ? (debug_state()['topic'] ?? DEFAULT_TOPIC) : current_topic();
    $entry = $debug_mode ? topic_lookup($topic) : topic_ensure($topic);
    json_response(['topic' => $topic, 'topic2' => $debug_mode ? '' : current_topic2(), 'topic_info' => $entry ? topic_public($entry) : null,
        'filters' => $debug_mode ? default_settings() : content_filters()]);
}

/** POST: вторая тема (пусто — убрать). Контент ищется по обеим: запись должна подходить под обе. Пул пересобирается. */
function handle_save_second_topic($input, $debug_mode) {
    $typed = normalize_topic($input['topic2'] ?? '');
    if ($debug_mode) json_response(['status' => 'success', 'topic2' => $typed, 'debug' => true]);
    if ($typed !== '' && topic_same($typed, current_topic())) json_error('Вторая тема совпадает с первой.');
    $topic2 = '';
    if ($typed !== '') {
        $resolved = topic_resolve($typed);
        $topic2 = $resolved['topic']['title'];
        if (topic_same($topic2, current_topic())) json_error('Вторая тема совпадает с первой.');
    }
    if (topic_norm($topic2) === topic_norm(current_topic2())) json_response(['status' => 'success', 'topic2' => $topic2, 'parser' => 'unchanged']);
    save_second_topic($topic2);
    update_data(pool_file(), [], function () { return []; });
    $parser = start_content_parser_if_needed(true);
    json_response(['status' => 'success', 'topic2' => $topic2, 'parser' => $parser]);
}

/** POST: фильтры подбора («Только новый контент», «Только актуальные источники»). Пул пересобирается под них. */
function handle_save_filters($input, $debug_mode) {
    if ($debug_mode) json_response(['status' => 'success', 'filters' => array_intersect_key($input, default_settings()), 'debug' => true]);
    $before = content_filters();
    $filters = save_content_filters(array_intersect_key($input, ['fresh_only' => 1, 'active_sources_only' => 1, 'video_only' => 1]));
    $parser = 'unchanged';
    if ($filters !== $before) {
        pool_for_current_topic();   // включили «Только новый» — старые записи уходят сразу
        // Ослабили фильтр — в пул может прийти больше; запущенный парсер сам заметит смену фильтров и начнёт заново
        $parser = start_content_parser_if_needed(true);
    }
    json_response(['status' => 'success', 'filters' => $filters, 'parser' => $parser]);
}

/** POST: пересоставить профиль темы (критерии, вопрос, план поиска). Сборщик напишет его заново при следующем запуске. */
function handle_topic_reprofile($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме профили не меняются.');
    $id = (string)($input['id'] ?? '');
    $entry = topic_registry()[$id] ?? null;
    if ($entry === null) json_error('Такой темы нет.');
    $entry['profile'] = [];
    topic_save($entry);
    json_response(['status' => 'success']);
}

/** POST: пользователь поправил общую тему для темы (parent — id, название новой общей темы или '' — тема сама общая). */
function handle_topic_parent($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме темы не меняются.');
    try {
        $entry = topic_set_parent((string)($input['id'] ?? ''), (string)($input['parent'] ?? ''));
    } catch (InvalidArgumentException $error) {
        json_error($error->getMessage());
    }
    topic_related_norms(null, true);
    json_response(['status' => 'success', 'topic_info' => topic_public($entry)]);
}

/** GET: все темы реестра для выбора (широкие, внутри — их узкие) и текущая тема пользователя. */
function handle_topics($debug_mode) {
    $topic = $debug_mode ? (debug_state()['topic'] ?? DEFAULT_TOPIC) : current_topic();
    $db = load_source_db();
    $current = topic_lookup($topic);
    $topics = array_map(fn($entry) => topic_public($entry, $db), array_values(topic_registry()));
    usort($topics, fn($a, $b) => [$a['parent']['title'] ?? $a['title'], $a['parent'] ? 1 : 0, $a['title']] <=> [$b['parent']['title'] ?? $b['title'], $b['parent'] ? 1 : 0, $b['title']]);
    json_response(['current' => $current['id'] ?? null, 'topics' => $topics]);
}

/**
 * Новая тема поиска. Неоценённый контент по старой теме убирается из пула, и сразу запускается поиск
 * по новой теме. История оценок сохраняется: она описывает вкус пользователя, а не тему.
 */
function handle_save_topic($input, $debug_mode) {
    $typed = normalize_topic($input['topic'] ?? '');
    if ($typed === '') json_error('Тема поиска не может быть пустой.');
    // Ввод → тема реестра: существующая (по названию, синониму или решению модели) или новая с аккуратным названием и местом
    // среди тем (lib/topics.php). force_new — пользователь нажал «Это другая тема».
    $previous = $debug_mode ? '' : current_topic();
    // Старый парсер останавливается сразу: он занимает модель (определение темы ждало бы его) и собирает по старой теме
    $stopped = !$debug_mode && !topic_same($typed, $previous) && stop_content_parser();
    $resolved = $debug_mode ? null : topic_resolve($typed, !empty($input['force_new']));
    $topic = $resolved ? $resolved['topic']['title'] : $typed;

    if ($debug_mode) {
        $state = &debug_state();
        $state['topic'] = $topic;
        json_response(['status' => 'success', 'topic' => $topic, 'debug' => true]);
    }

    save_topic($topic);
    $parser = 'unchanged';
    // У новой темы мало своих источников — заказываем автоподбор: сборщик начнёт с поиска источников
    $lacks_sources = topic_lacks_sources(load_source_db(), $topic);
    if ($lacks_sources) request_source_discovery();
    if (!topic_same($topic, $previous)) {
        update_data(pool_file(), [], function () { return []; });
        media_cache_cleanup(all_users_pool_items());
        $parser = start_content_parser_if_needed(true);
    } elseif ($stopped) {
        $parser = start_content_parser_if_needed(true);   // ввод оказался той же темой — продолжаем сбор
    }
    json_response(['status' => 'success', 'topic' => $topic, 'parser' => $parser, 'typed' => $typed,
        'matched' => $resolved['matched'], 'created' => $resolved['created'], 'ai' => $resolved['ai'], 'discovery' => $lacks_sources,
        'topic_info' => topic_public($resolved['topic'])]);
}

function handle_analyze_image($input, $debug_mode) {
    try {
        $image_data = read_request_image($input, 'Не удалось загрузить файл.', 'Добавьте ссылку или выберите файл изображения.');
        $history = $debug_mode ? debug_state()['history'] : load_history();
        $topic = $debug_mode ? (debug_state()['topic'] ?? DEFAULT_TOPIC) : current_topic();
        json_response(analyze_image_local($image_data, $history, $topic));
    } catch (Throwable $error) {
        json_error($error->getMessage());
    }
}

/**
 * Голос за запись. Интерфейс присылает только адрес её картинки (image), а сами данные
 * сервер берёт из пула: так в историю не попадёт подменённый рейтинг или текст.
 * Голос 'postpone' не пишет историю, а переносит запись в конец очереди.
 */
function handle_vote($input, $debug_mode) {
    $vote = (string)($input['vote'] ?? '');
    $image = (string)($input['image'] ?? '');
    if ((!isset(CONTENT_VOTE_COLLECTIONS[$vote]) && $vote !== CONTENT_POSTPONE_VOTE) || $image === '') {
        json_error('Некорректный голос или запись');
    }
    $complaint_reason = $vote === 'wrong' && in_array($input['reason'] ?? '', COMPLAINT_REASONS, true) ? $input['reason'] : null;

    if ($debug_mode) {
        $state = &debug_state();
        $state['pool'] = apply_vote($state['pool'], $image, $vote, $complaint_reason, function ($record, $collection) use (&$state) {
            $state['history'][$collection][] = $record;
        }) ?? $state['pool'];
        json_response(['status' => 'success', 'debug' => true]);
    }

    // Пул блокируется на всё время голосования, история — внутри него (тот же порядок, что у парсера).
    $pool_before = [];
    $pool = update_data(pool_file(), [], function ($pool) use ($image, $vote, $complaint_reason, &$pool_before) {
        $pool_before = $pool;
        return apply_vote($pool, $image, $vote, $complaint_reason, function ($record, $collection) {
            update_data(history_file(), empty_history(), function ($history) use ($record, $collection) {
                $history[$collection][] = $record;
                return $history;
            });
        });
    });
    // картинка оценённой записи больше не нужна; лайк и «не знаю» остаются в «Предложке» вместе с файлами
    if (!in_array(CONTENT_VOTE_COLLECTIONS[$vote] ?? '', PROPOSAL_COLLECTIONS, true)) media_cache_release_removed($pool_before, $pool);
    json_response(['status' => 'success']);
}

/**
 * Применяет голос к пулу и возвращает новый пул (или null, если записи уже нет в пуле).
 * $save_history($record, $collection) вызывается до удаления записи из пула.
 */
function apply_vote(array $pool, $image, $vote, $complaint_reason, callable $save_history) {
    $item = pool_find_item($pool, $image);
    if ($item === null) return null; // запись уже оценена в другой вкладке или удалена
    if ($vote === CONTENT_POSTPONE_VOTE) return pool_move_image_to_end($pool, $image);

    $save_history(content_history_record($item, $complaint_reason), CONTENT_VOTE_COLLECTIONS[$vote]);
    return pool_without_image($pool, $image);
}

function handle_refresh_content($debug_mode) {
    if ($debug_mode) json_error('Поиск контента отключён в демо-режиме.', 403);

    $parser_status = start_content_parser_if_needed(true);
    if (!in_array($parser_status, ['started', 'running'], true)) {
        json_error('Не удалось запустить поиск контента на сервере.', 503);
    }
    json_response(['status' => 'loading', 'message' => 'Ищем контент...']);
}
