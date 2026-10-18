<?php
// Обработчики вкладок «Оценка мемов» и «Тест ИИ».

const MEME_POSTPONE_VOTE = 'postpone';

function handle_get_current_meme($debug_mode) {
    $pool = $debug_mode ? debug_state()['pool'] : load_data(POOL_FILE, []);
    if ($pool) json_response(meme_for_display($pool[0]));

    $parser_status = $debug_mode ? 'cooldown' : start_meme_parser_if_needed();
    if (in_array($parser_status, ['started', 'running'], true)) {
        json_response(['status' => 'loading', 'message' => 'Ищем посты...', 'activity' => meme_parser_activity()]);
    }
    json_response(['status' => 'empty', 'message' => 'Новых мемов пока нет. Можно повторить поиск или добавить запись вручную.']);
}

function handle_analyze_image($input, $debug_mode) {
    try {
        $image_data = read_request_image($input, 'Не удалось загрузить файл.', 'Добавьте ссылку или выберите файл изображения.');
        $history = $debug_mode ? debug_state()['history'] : load_history();
        json_response(analyze_image_local($image_data, $history));
    } catch (Throwable $error) {
        json_error($error->getMessage());
    }
}

/**
 * Голос за мем. Интерфейс присылает только адрес картинки мема (image), а сами данные мема
 * сервер берёт из пула: так в историю не попадёт подменённый рейтинг или текст.
 * Голос 'postpone' не пишет историю, а переносит мем в конец очереди.
 */
function handle_meme_vote($input, $debug_mode) {
    $vote = (string)($input['vote'] ?? '');
    // Старый формат запроса присылал весь мем целиком в поле meme.
    $image = (string)($input['image'] ?? meme_image_key($input['meme'] ?? null));
    if ((!isset(MEME_VOTE_COLLECTIONS[$vote]) && $vote !== MEME_POSTPONE_VOTE) || $image === '') {
        json_error('Некорректный голос или мем');
    }
    $complaint_reason = $vote === 'wrong' && in_array($input['reason'] ?? '', COMPLAINT_REASONS, true) ? $input['reason'] : null;

    if ($debug_mode) {
        $state = &debug_state();
        $state['pool'] = apply_meme_vote($state['pool'], $image, $vote, $complaint_reason, function ($record, $collection) use (&$state) {
            $state['history'][$collection][] = $record;
        });
        json_response(['status' => 'success', 'debug' => true]);
    }

    // Пул блокируется на всё время голосования, история — внутри него (тот же порядок, что у парсера).
    update_data(POOL_FILE, [], function ($pool) use ($image, $vote, $complaint_reason) {
        return apply_meme_vote($pool, $image, $vote, $complaint_reason, function ($record, $collection) {
            update_data(HISTORY_FILE, empty_history(), function ($history) use ($record, $collection) {
                $history[$collection][] = $record;
                return $history;
            });
        });
    });
    json_response(['status' => 'success']);
}

/**
 * Применяет голос к пулу и возвращает новый пул (или null, если мема уже нет в пуле).
 * $save_history($record, $collection) вызывается до удаления мема из пула.
 */
function apply_meme_vote(array $pool, $image, $vote, $complaint_reason, callable $save_history) {
    $meme = pool_find_meme($pool, $image);
    if ($meme === null) return null; // мем уже оценён в другой вкладке или удалён
    if ($vote === MEME_POSTPONE_VOTE) return pool_move_image_to_end($pool, $image);

    $save_history(meme_history_record($meme, $complaint_reason), MEME_VOTE_COLLECTIONS[$vote]);
    return pool_without_image($pool, $image);
}

function handle_refresh_memes($debug_mode) {
    if ($debug_mode) json_error('Поиск мемов отключён в демо-режиме.', 403);

    $parser_status = start_meme_parser_if_needed(true);
    if (!in_array($parser_status, ['started', 'running'], true)) {
        json_error('Не удалось запустить поиск мемов на сервере.', 503);
    }
    json_response(['status' => 'loading', 'message' => 'Ищем посты...']);
}
