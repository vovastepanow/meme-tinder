<?php
// Публикация из приложения в Telegram-канал пользователя: отправка сразу или в очередь, список очереди, отмена.
// Вся работа с Bot API — lib/telegram_publish.php; подключение бота и канала — handlers/account.php.

/** Файлы, загруженные редактором поста ($_FILES['files'][]): [индекс => ['path', 'name']]. На них ссылается поле upload у медиа. */
function publish_collect_uploads() {
    $files = $_FILES['files'] ?? null;
    if (!$files || !is_array($files['name'] ?? null)) return [];
    $uploads = [];
    foreach ($files['name'] as $index => $name) {
        $label = '«' . mb_substr((string)$name, 0, 80) . '»';
        $error = (int)$files['error'][$index];
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) throw new RuntimeException("Файл {$label} слишком большой для загрузки на сервер.");
        if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$index])) throw new RuntimeException("Не удалось загрузить файл {$label}.");
        if ((int)$files['size'][$index] > TELEGRAM_UPLOAD_MAX_BYTES) throw new RuntimeException("Файл {$label} больше " . (TELEGRAM_UPLOAD_MAX_BYTES >> 20) . ' МБ.');
        $uploads[(int)$index] = ['path' => $files['tmp_name'][$index], 'name' => (string)$name];
    }
    return $uploads;
}

/**
 * POST (multipart или JSON): text, media (JSON-список [{kind, src, name, upload}]), scheduled_ts (unix-время, пусто — сразу),
 * files[] (файлы из редактора). Сразу — отправка и ссылка на пост; с временем — пост уходит в очередь.
 */
function handle_telegram_publish($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме публикация отключена.', 403);
    $connection = telegram_connection();
    if ($connection === null) json_error('Telegram не подключён: подключите бота и канал в «Личном кабинете» → «Соцсети».', 409);

    // Запрос больше post_max_size: PHP молча отбрасывает и поля, и файлы — без этой проверки пользователь увидел бы «Добавьте медиа или текст»
    if (!$_POST && !$_FILES && !$input && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_parse_quantity((string)ini_get('post_max_size'))) {
        json_error('Файлы вместе весят больше лимита сервера (' . ini_get('post_max_size') . '). Уберите часть файлов или выполните tools/apache-setup.sh, чтобы поднять лимит.', 413);
    }

    $fields = $_POST ?: $input;
    $text = trim((string)($fields['text'] ?? ''));
    $items = $fields['media'] ?? [];
    if (is_string($items)) $items = json_decode($items, true);
    if (!is_array($items)) json_error('Некорректный список медиа.');
    $items = array_slice(array_values(array_filter($items, 'is_array')), 0, TELEGRAM_MAX_MEDIA + 1);   // +1: чтобы подготовка заметила превышение
    $scheduled_ts = (int)($fields['scheduled_ts'] ?? 0);
    $now = time();
    if ($scheduled_ts !== 0 && $scheduled_ts < $now + TELEGRAM_MIN_DELAY_SECONDS) json_error('Отложенный пост нужно назначить минимум на минуту вперёд.');
    if ($scheduled_ts > $now + TELEGRAM_MAX_DELAY_DAYS * 86400) json_error('Отложить пост можно не больше чем на ' . TELEGRAM_MAX_DELAY_DAYS . ' дней.');

    @set_time_limit(900);       // большое видео заливается и перекодируется долго
    ignore_user_abort(true);    // закрытая вкладка не должна оборвать отправку на середине
    $work = null;
    $response = null;
    $failure = null;
    try {
        telegram_check_text($text, count($items));
        $uploads = publish_collect_uploads();
        $work = telegram_work_dir();
        $prepared = telegram_prepare_media($items, $uploads, $work, $scheduled_ts !== 0 ? 'account' : 'bot');
        $summary = telegram_post_summary($text, count($prepared));
        if ($scheduled_ts !== 0) {
            // Отложенные посты живут только в «Отложенных» самого Telegram (очередь приложения и cron больше не используются):
            // для этого нужен подключённый личный аккаунт — «Личный кабинет» → «Соцсети» → Telegram.
            if (telegram_user_connection() === null) {
                throw new RuntimeException('Отложенные посты ставятся средствами Telegram: подключите свой аккаунт в «Личном кабинете» → «Соцсети» → Telegram (блок «Отложенные посты средствами Telegram»). Без отложки пост можно опубликовать сразу.');
            }
            $message_ids = telegram_user_schedule(app_user_id(), $connection, $text, $prepared, $scheduled_ts);
            $id = telegram_record_native_post(app_user_id(), $text, $prepared, $scheduled_ts, $message_ids);
            $native = true;
            $native_error = '';
            $response = ['status' => 'success', 'scheduled' => true, 'id' => $id, 'publish_at' => $scheduled_ts, 'native' => $native, 'native_error' => $native_error];
        } else {
            $sent = telegram_publish_prepared($connection, $text, $prepared);
            telegram_log_published(app_user_id(), $summary + ['at' => time(), 'scheduled_for' => 0, 'url' => $sent['url'], 'status' => 'ok', 'error' => '']);
            $response = ['status' => 'success', 'scheduled' => false, 'url' => $sent['url'], 'messages' => count($sent['message_ids'])];
        }
    } catch (RuntimeException $error) {
        $failure = $error->getMessage();
    } finally {
        if ($work !== null) telegram_remove_dir($work);   // exit внутри try блок finally не выполнил бы, поэтому ответ отдаётся ниже
    }
    if ($failure !== null) json_error($failure, 422);
    json_response($response);
}

/** Очередь отложенных постов, последние отправленные и состояние подключения — для «Личного кабинета». */
function handle_telegram_queue($debug_mode) {
    if ($debug_mode) json_response(['connected' => false, 'scheduled' => [], 'published' => []]);
    $connection = telegram_connection();
    telegram_settle_native_posts(app_user_id());   // давно прошедшие отложенные посты Telegram уже опубликованы: убираем из списка
    $scheduled = array_map(fn($entry) => telegram_post_summary($entry['text'], count($entry['media'])) + [
        'id' => $entry['id'], 'publish_at' => (int)$entry['publish_at'], 'status' => $entry['status'] ?? 'pending',
        'attempts' => (int)($entry['attempts'] ?? 0), 'error' => (string)($entry['error'] ?? '')
    ], load_data(outbox_file(), []));
    usort($scheduled, fn($a, $b) => $a['publish_at'] <=> $b['publish_at']);
    json_response([
        'connected' => $connection !== null,
        'channel' => $connection === null ? null : ['title' => $connection['chat_title'] ?? '', 'username' => $connection['chat_username'] ?? ''],
        'scheduled' => $scheduled,
        'published' => array_slice(load_data(published_file(), []), 0, 10)
    ]);
}

/** POST {id}: убирает отложенный пост из очереди вместе с его файлами. Отправляемый прямо сейчас отменить нельзя. */
function handle_telegram_cancel($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме очереди нет.', 403);
    $id = (string)($input['id'] ?? '');
    if (!preg_match('/^[a-f0-9]{12}$/', $id)) json_error('Некорректный номер поста.');
    foreach (load_data(outbox_file(), []) as $entry) {
        if (($entry['id'] ?? '') !== $id) continue;
        if (($entry['status'] ?? '') === 'sending') json_error('Пост отправляется прямо сейчас — отменить уже нельзя.', 409);
        if (($entry['status'] ?? '') === 'telegram') {   // пост лежит в «Отложенных» канала: убираем и там
            $bot = telegram_connection();
            try {
                if ($bot !== null) telegram_user_cancel(app_user_id(), $bot, array_map('intval', $entry['tg_ids'] ?? []));
            } catch (RuntimeException $error) {
                json_error('Не удалось убрать пост из отложенных в Telegram: ' . $error->getMessage() . ' Удалите его в канале вручную («Отложенные»).', 502);
            }
        }
    }
    if (!telegram_unqueue_post(app_user_id(), $id)) json_error('Этого поста уже нет в очереди: возможно, он отправлен.', 404);
    json_response(['status' => 'success']);
}
