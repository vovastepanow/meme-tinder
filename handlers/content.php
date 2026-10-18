<?php
// Обработчики вкладок «Оценка контента» и «Тест ИИ»: текущая запись, голоса, тема поиска, состояние парсера.

const CONTENT_POSTPONE_VOTE = 'postpone';
const CONTENT_PRELOAD_COUNT = 3; // сколько следующих картинок браузер подгружает заранее

function handle_get_current_item($debug_mode) {
    $pool = $debug_mode ? debug_state()['pool'] : load_data(POOL_FILE, []);
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

function handle_get_settings($debug_mode) {
    json_response(['topic' => $debug_mode ? (debug_state()['topic'] ?? DEFAULT_TOPIC) : current_topic()]);
}

/**
 * Новая тема поиска. Неоценённый контент по старой теме убирается из пула, и сразу запускается поиск
 * по новой теме. История оценок сохраняется: она описывает вкус пользователя, а не тему.
 */
function handle_save_topic($input, $debug_mode) {
    $topic = normalize_topic($input['topic'] ?? '');
    if ($topic === '') json_error('Тема поиска не может быть пустой.');

    if ($debug_mode) {
        $state = &debug_state();
        $state['topic'] = $topic;
        json_response(['status' => 'success', 'topic' => $topic, 'debug' => true]);
    }

    $previous = current_topic();
    save_topic($topic);
    $parser = 'unchanged';
    if ($topic !== $previous) {
        update_data(POOL_FILE, [], function () { return []; });
        media_cache_cleanup([], true);
        $parser = start_content_parser_if_needed(true);
    }
    json_response(['status' => 'success', 'topic' => $topic, 'parser' => $parser]);
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
    $pool = update_data(POOL_FILE, [], function ($pool) use ($image, $vote, $complaint_reason, &$pool_before) {
        $pool_before = $pool;
        return apply_vote($pool, $image, $vote, $complaint_reason, function ($record, $collection) {
            update_data(HISTORY_FILE, empty_history(), function ($history) use ($record, $collection) {
                $history[$collection][] = $record;
                return $history;
            });
        });
    });
    media_cache_release_removed($pool_before, $pool); // картинка оценённой записи больше не нужна
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
