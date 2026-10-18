<?php
// Обработчики оценки мемов и текущего пула.
// ---- Memes ----

function handle_get_current_meme($debug_mode) {
    $pool = $debug_mode ? $_SESSION['meme_tinder_debug']['pool'] : load_data(POOL_FILE, []);
    if (empty($pool)) {
        $parser_status = $debug_mode ? 'cooldown' : start_meme_parser_if_needed();
        if (in_array($parser_status, ['started', 'running'], true)) {
            json_response(['status' => 'loading', 'message' => 'Ищем посты...', 'activity' => meme_parser_activity()]);
        }
        json_response(['status' => 'empty', 'message' => 'Новых мемов пока нет. Можно повторить поиск или добавить запись вручную.']);
    }
    $meme = $pool[0];
    $meme['images'] = [$meme['image']];
    $meme['captions'] = [$meme['captions'][0] ?? ($meme['text'] ?? '')];
    $meme['source_url'] = source_post_url($meme);
    json_response($meme);
}

function handle_analyze_image($input, $debug_mode) {
    try {
        $image_data = read_request_image($input, 'Не удалось загрузить файл.', 'Добавьте ссылку или выберите файл изображения.');
        $history = $debug_mode ? $_SESSION['meme_tinder_debug']['history'] : load_history();
        json_response(analyze_image_local($image_data, $history));
    } catch (Throwable $error) {
        json_response(['status' => 'error', 'message' => $error->getMessage()], 400);
    }
}

/** Skips a meme without rating it: moves it to the end of the pool and writes nothing to history. */
function handle_meme_postpone($meme, $debug_mode): never {
    $image = !empty($meme['image']) ? $meme['image'] : ($meme['images'][0] ?? '');
    $move_to_end = function(array $pool) use ($image) {
        $postponed = array_filter($pool, function($item) use ($image) { return ($item['image'] ?? '') === $image; });
        $rest = array_filter($pool, function($item) use ($image) { return ($item['image'] ?? '') !== $image; });
        return array_values(array_merge($rest, $postponed));
    };
    if ($debug_mode) {
        $_SESSION['meme_tinder_debug']['pool'] = $move_to_end($_SESSION['meme_tinder_debug']['pool']);
    } else {
        save_data(POOL_FILE, $move_to_end(load_data(POOL_FILE, [])));
    }
    json_response(['status' => 'success', 'postponed' => true]);
}

function handle_meme_vote($input, $debug_mode) {
    $vote = $input['vote'] ?? '';
    $meme = $input['meme'] ?? null;
    $map = ['like' => 'liked_memes', 'dislike' => 'disliked_memes', 'maybe' => 'undecided_memes', 'wrong' => 'wrong_topic_memes'];
    $has_media = !empty($meme['image']) || (!empty($meme['images']) && is_array($meme['images']));
    if ((!isset($map[$vote]) && $vote !== 'postpone') || !is_array($meme) || !$has_media) {
        json_response(['status' => 'error', 'message' => 'Некорректный голос или мем'], 400);
    }
    if ($vote === 'postpone') handle_meme_postpone($meme, $debug_mode);

    $first_image = !empty($meme['image']) ? $meme['image'] : (is_array($meme['images'] ?? null) ? ($meme['images'][0] ?? '') : '');
    // Complaints ("wrong") are stored in wrong_topic_memes for compatibility with existing history files.
    $complaint_reason = $vote === 'wrong' && in_array($input['reason'] ?? '', COMPLAINT_REASONS, true) ? $input['reason'] : null;

    if ($debug_mode) {
        $state = &$_SESSION['meme_tinder_debug'];
        $state['history'][$map[$vote]][] = ['text' => $meme['text'] ?? '', 'rating_assigned' => $meme['rating'] ?? 0, 'complaint_reason' => $complaint_reason, 'timestamp' => time()];
        $state['pool'] = array_values(array_filter($state['pool'], function($item) use ($first_image) { return ($item['image'] ?? '') !== $first_image; }));
        json_response(['status' => 'success', 'debug' => true]);
    }

    $history = load_history();
    $history[$map[$vote]][] = [
        'image' => $first_image,
        'text' => $meme['text'],
        'ai_short_description' => $meme['ai_description'] ?? 'Без описания ИИ',
        'why_funny' => $meme['why_funny'] ?? '',
        'is_funny' => $meme['is_funny'] ?? null,
        'meme_probability' => $meme['meme_probability'] ?? null,
        'rating_assigned' => $meme['rating'],
        'source' => $meme['source'],
        'board' => $meme['board'] ?? '',
        'thread_id' => $meme['thread_id'] ?? '',
        'post_id' => $meme['post_id'] ?? '',
        'source_url' => source_post_url($meme),
        'complaint_reason' => $complaint_reason,
        'timestamp' => time()
    ];
    save_data(HISTORY_FILE, $history);

    $pool = array_filter(load_data(POOL_FILE, []), function($item) use ($meme) { return $item['image'] !== $meme['image']; });
    save_data(POOL_FILE, array_values($pool));

    json_response(['status' => 'success']);
}

function handle_refresh_memes($debug_mode) {
    if ($debug_mode) {
        json_response(['status' => 'error', 'message' => 'Поиск мемов отключён в демо-режиме.'], 403);
    }
    $parser_status = start_meme_parser_if_needed(true);
    json_response([
        'status' => in_array($parser_status, ['started', 'running'], true) ? 'loading' : 'error',
        'message' => $parser_status === 'unavailable' ? 'Не удалось запустить поиск мемов на сервере.' : 'Ищем посты...'
    ]);
}
