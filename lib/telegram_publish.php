<?php
// Публикация постов в Telegram-канал пользователя через Bot API: сразу или в назначенное время.
// Бот создаёт сам пользователь у @BotFather, добавляет администратором в канал и вставляет токен в «Личном кабинете»
// (handlers/account.php хранит подключение в users/<id>/connections.json). Регистрировать приложение на my.telegram.org не нужно.
//
// Что умеет: текст; одно фото, видео или гифка; альбом до 10 файлов (фото и видео вперемешку). Файлы приводятся к тому,
// что принимает Bot API: фото ≤ 10 МБ (иначе пережимаются), видео — MP4/H.264 ≤ 50 МБ (иначе перекодируются через ffmpeg).
// Отложенные посты: Bot API не умеет планировать, поэтому пост со всеми файлами кладётся в users/<id>/outbox.json и
// отправляется скриптом tools/publish_scheduled.php, который запускает cron раз в минуту.

const TELEGRAM_MAX_PHOTO_BYTES = 10 * 1024 * 1024;   // лимит Bot API на фото
const TELEGRAM_MAX_VIDEO_BYTES = 50 * 1024 * 1024;   // лимит Bot API на загрузку файла
const TELEGRAM_MAX_MEDIA = 10;                       // файлов в альбоме
const TELEGRAM_CAPTION_LIMIT = 1024;                 // подпись к медиа
const TELEGRAM_TEXT_LIMIT = 4096;                    // пост без медиа
const TELEGRAM_MIN_DELAY_SECONDS = 60;               // отложить можно не раньше чем через минуту
const TELEGRAM_MAX_DELAY_DAYS = 365;
const TELEGRAM_RETRY_SECONDS = 300;                  // пауза перед повторной отправкой, если Telegram не принял пост
const TELEGRAM_MAX_ATTEMPTS = 3;
const TELEGRAM_CLAIM_TIMEOUT = 900;                  // «отправляется» дольше этого считается зависшим и берётся заново
const TELEGRAM_PUBLISHED_LOG_LIMIT = 30;
const TELEGRAM_UPLOAD_MAX_BYTES = 64 * 1024 * 1024;  // файл, загруженный из редактора

/** Адрес Bot API. Для проверок можно подменить переменной окружения POSTMAKER_TELEGRAM_API (локальный поддельный сервер). */
function telegram_api_base() {
    $custom = getenv('POSTMAKER_TELEGRAM_API');
    return $custom !== false && $custom !== '' ? $custom : 'https://api.telegram.org/bot';   // к нему дописывается токен: …/bot<ТОКЕН>/метод
}

/** Понятное описание ошибки Bot API по ответу Telegram. */
function telegram_error_text(array $data, $status = 0) {
    $description = (string)($data['description'] ?? '');
    $lower = mb_strtolower($description);
    $retry = (int)($data['parameters']['retry_after'] ?? 0);
    if ($status === 401 || str_contains($lower, 'unauthorized')) return 'Токен бота недействителен: подключите Telegram заново в «Личном кабинете».';
    if (str_contains($lower, 'chat not found')) return 'Telegram не нашёл канал: проверьте, что бот добавлен в канал.';
    if (str_contains($lower, 'not a member') || str_contains($lower, 'was kicked') || str_contains($lower, 'bot was blocked')) return 'Бот не состоит в канале или удалён из него: добавьте его администратором.';
    if (str_contains($lower, 'not enough rights') || str_contains($lower, 'have no rights') || str_contains($lower, 'need administrator rights')) return 'У бота нет права публиковать в канале: сделайте его администратором с правом «Публикация сообщений».';
    if (str_contains($lower, 'too big') || $status === 413) return 'Файл слишком большой для Telegram.';
    if ($status === 429 || str_contains($lower, 'too many requests')) return 'Telegram просит подождать' . ($retry ? " {$retry} с" : '') . ' перед следующей отправкой.';
    if (str_contains($lower, 'wrong file') || str_contains($lower, 'wrong type')) return 'Telegram не принял файл: проверьте формат (фото JPEG/PNG, видео MP4).';
    return 'Telegram: ' . ($description !== '' ? $description : 'ответ не распознан (HTTP ' . $status . ')');
}

/**
 * Запрос к Bot API (POST). $files — [поле => путь к файлу]; поля с файлами уходят как multipart.
 * Возвращает result или бросает RuntimeException с понятным текстом.
 */
function telegram_bot_request($token, $method, array $fields = [], array $files = [], $timeout = 60) {
    $post = $fields;
    foreach ($files as $name => $path) {
        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
        $post[$name] = new CURLFile($path, $mime, basename($path));
    }
    $ch = curl_init(telegram_api_base() . $token . '/' . $method);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $timeout, CURLOPT_USERAGENT => 'Postmaker/1.0']);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Telegram недоступен: ' . $error);
    $data = json_decode((string)$body, true);
    if (!is_array($data) || empty($data['ok'])) throw new RuntimeException(telegram_error_text(is_array($data) ? $data : [], $status));
    return $data['result'];
}

// ---- Подготовка файлов ----

function telegram_work_dir() {
    $dir = sys_get_temp_dir() . '/postmaker-publish-' . bin2hex(random_bytes(6));
    if (!@mkdir($dir, 0700) && !is_dir($dir)) throw new RuntimeException('Не удалось создать временную папку.');
    return $dir;
}

function telegram_remove_dir($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) ?: [] as $name) if ($name !== '.' && $name !== '..') @unlink($dir . '/' . $name);
    @rmdir($dir);
}

/** Скачивает публичный файл (картинку или видео) в $dest. Возвращает MIME. Внутренняя сеть закрыта (public_url_resolve). */
function fetch_public_media($url, $dest, $max_bytes) {
    $resolve = public_url_resolve($url);
    $handle = @fopen($dest, 'wb');
    if (!$handle) throw new RuntimeException('Не удалось сохранить файл во временную папку.');
    $size = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 180,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'Postmaker/1.0',
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$size, $max_bytes, $handle) {
            $size += strlen($chunk);
            return $size > $max_bytes ? 0 : fwrite($handle, $chunk);
        }]);
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
    $ok = curl_exec($ch) !== false;
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = strtolower((string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE));
    curl_close($ch);
    fclose($handle);
    if (!$ok || $status < 200 || $status >= 300 || $size === 0 || (!str_starts_with($type, 'image/') && !str_starts_with($type, 'video/'))) {
        @unlink($dest);
        throw new RuntimeException($size > $max_bytes ? 'Файл по ссылке слишком большой.' : 'Не удалось скачать файл по ссылке (' . $url . ').');
    }
    return $type;
}

/** Фото в виде, который принимает Bot API: JPEG или PNG до 10 МБ, сумма сторон ≤ 10000, пропорции не вытянутее 20:1. */
function telegram_prepare_photo($path, $work_dir, $index) {
    $info = @getimagesize($path);
    if (!$info) throw new RuntimeException('Файл не похож на картинку.');
    [$width, $height] = $info;
    $mime = $info['mime'] ?? '';
    $fits = in_array($mime, ['image/jpeg', 'image/png'], true) && filesize($path) <= TELEGRAM_MAX_PHOTO_BYTES
        && $width + $height <= 10000 && max($width, $height) / max(1, min($width, $height)) <= 20;
    if ($fits) return ['path' => $path, 'width' => $width, 'height' => $height];
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('На сервере нет PHP GD: не удалось подготовить картинку.');
    $source = @imagecreatefromstring((string)file_get_contents($path));
    if (!$source) throw new RuntimeException('Не удалось прочитать картинку.');
    $scale = min(1.0, 2560 / max($width, $height));
    $new_width = max(1, (int)round($width * $scale));
    $new_height = max(1, (int)round($height * $scale));
    $canvas = imagecreatetruecolor($new_width, $new_height);
    imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));   // прозрачность заменяется белым: JPEG её не хранит
    imagecopyresampled($canvas, $source, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    $out = $work_dir . "/photo-{$index}.jpg";
    for ($quality = 90; $quality >= 60; $quality -= 10) {
        imagejpeg($canvas, $out, $quality);
        if (filesize($out) <= TELEGRAM_MAX_PHOTO_BYTES) break;
    }
    imagedestroy($source);
    imagedestroy($canvas);
    if (filesize($out) > TELEGRAM_MAX_PHOTO_BYTES) throw new RuntimeException('Картинка слишком большая даже после сжатия.');
    return ['path' => $out, 'width' => $new_width, 'height' => $new_height];
}

/** Свойства видео по ffprobe: контейнер, кодеки, размер кадра, длительность. */
function telegram_video_info($path) {
    $json = @shell_exec('ffprobe -v error -show_entries format=format_name,duration:stream=codec_type,codec_name,width,height -of json ' . escapeshellarg($path) . ' 2>/dev/null');
    $data = json_decode((string)$json, true) ?: [];
    $info = ['container' => (string)($data['format']['format_name'] ?? ''), 'duration' => (int)round((float)($data['format']['duration'] ?? 0)), 'video' => '', 'audio' => '', 'width' => 0, 'height' => 0];
    foreach ($data['streams'] ?? [] as $stream) {
        if (($stream['codec_type'] ?? '') === 'video' && $info['video'] === '') { $info['video'] = (string)$stream['codec_name']; $info['width'] = (int)($stream['width'] ?? 0); $info['height'] = (int)($stream['height'] ?? 0); }
        if (($stream['codec_type'] ?? '') === 'audio' && $info['audio'] === '') $info['audio'] = (string)$stream['codec_name'];
    }
    return $info;
}

/** Видео в виде, который Telegram проигрывает в ленте: MP4 + H.264 (+ AAC) до 50 МБ. Остальное перекодируется ffmpeg. */
function telegram_prepare_video($path, $work_dir, $index, $route = 'bot') {
    $info = telegram_video_info($path);
    if ($info['video'] === '') throw new RuntimeException('Файл не похож на видео (или на сервере нет ffprobe).');
    $ready = str_contains($info['container'], 'mp4') && $info['video'] === 'h264' && in_array($info['audio'], ['', 'aac'], true);
    if (!$ready) {
        $ffmpeg = function_exists('ffmpeg_binary') ? ffmpeg_binary() : '';
        if ($ffmpeg === '') throw new RuntimeException('Видео нужно перекодировать в MP4, но на сервере нет ffmpeg.');
        $out = $work_dir . "/video-{$index}.mp4";
        $command = 'timeout 600 ' . escapeshellarg($ffmpeg) . ' -v error -y -i ' . escapeshellarg($path)
            . ' -map 0:v:0 -map 0:a:0? -c:v libx264 -preset veryfast -crf 26 -pix_fmt yuv420p -vf "scale=trunc(iw/2)*2:trunc(ih/2)*2"'
            . ' -c:a aac -b:a 128k -movflags +faststart -f mp4 ' . escapeshellarg($out) . ' 2>/dev/null';
        exec($command, $output, $exit_code);
        if ($exit_code !== 0 || !is_file($out) || filesize($out) === 0) throw new RuntimeException('Не удалось перекодировать видео в MP4.');
        $path = $out;
        $info = telegram_video_info($path);
    }
    if (telegram_video_verdict(filesize($path), $route) === 'blocked') {
        throw new RuntimeException('Видео ' . telegram_video_limit_text(filesize($path), $route) . '.');   // «рискованный» размер не отсекается: Telegram мог поднять лимит, отправка это проверит
    }
    return ['path' => $path, 'width' => $info['width'], 'height' => $info['height'], 'duration' => $info['duration']];
}

/**
 * Файлы поста → готовые к отправке: [['kind' => 'photo'|'video'|'animation', 'path', 'name', 'width', 'height', 'duration']].
 * $items — [{kind, src, name, upload}] из запроса; $uploads — загруженные файлы [['path', 'name']] (на них ссылается upload).
 * Источник файла: загруженный файл, кэш сайта (media_cache/…) или публичная ссылка (скачивается без доступа во внутреннюю сеть).
 */
function telegram_prepare_media(array $items, array $uploads, $work_dir, $route = 'bot') {
    if (count($items) > TELEGRAM_MAX_MEDIA) throw new RuntimeException('В пост помещается не больше ' . TELEGRAM_MAX_MEDIA . ' файлов.');
    $prepared = [];
    foreach (array_values($items) as $index => $item) {
        $name = mb_substr((string)($item['name'] ?? ''), 0, 120) ?: 'файл ' . ($index + 1);
        $label = "«{$name}»";
        $source = (string)($item['src'] ?? '');
        $path = '';
        if (isset($item['upload']) && $item['upload'] !== null && $item['upload'] !== '' && isset($uploads[(int)$item['upload']])) {
            $path = $uploads[(int)$item['upload']]['path'];
        } elseif (($cached = media_cache_path($source)) !== null && is_file($cached)) {
            $path = $cached;
        } elseif (preg_match('~^https?://~i', $source)) {
            $path = $work_dir . "/source-{$index}";
            try {
                fetch_public_media($source, $path, TELEGRAM_UPLOAD_MAX_BYTES);
            } catch (RuntimeException $error) {
                throw new RuntimeException("{$label}: " . $error->getMessage());
            }
        } else {
            throw new RuntimeException("{$label}: файл недоступен (ни в кэше сайта, ни по ссылке).");
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
        try {
            if (str_starts_with($mime, 'image/')) {
                if ($mime === 'image/gif') {
                    $prepared[] = ['kind' => 'animation', 'path' => $path, 'name' => $name, 'width' => 0, 'height' => 0, 'duration' => 0];
                } else {
                    $prepared[] = ['kind' => 'photo', 'name' => $name, 'duration' => 0] + telegram_prepare_photo($path, $work_dir, $index);
                }
            } elseif (str_starts_with($mime, 'video/')) {
                $prepared[] = ['kind' => 'video', 'name' => $name] + telegram_prepare_video($path, $work_dir, $index, $route);
            } else {
                throw new RuntimeException('поддерживаются только картинки и видео');
            }
        } catch (RuntimeException $error) {
            throw new RuntimeException("{$label}: " . $error->getMessage());
        }
    }
    // Гифка в альбоме не поддерживается Bot API: перекодируем в видео (без звука)
    if (count($prepared) > 1) {
        foreach ($prepared as $index => $file) {
            if ($file['kind'] !== 'animation') continue;
            $ffmpeg = function_exists('ffmpeg_binary') ? ffmpeg_binary() : '';
            if ($ffmpeg === '') throw new RuntimeException("«{$file['name']}»: гифку в альбоме нельзя отправить без ffmpeg. Отправьте её отдельным постом.");
            $out = $work_dir . "/gif-{$index}.mp4";
            exec('timeout 300 ' . escapeshellarg($ffmpeg) . ' -v error -y -i ' . escapeshellarg($file['path']) . ' -movflags +faststart -pix_fmt yuv420p -vf "scale=trunc(iw/2)*2:trunc(ih/2)*2" -an -f mp4 ' . escapeshellarg($out) . ' 2>/dev/null', $output, $exit_code);
            if ($exit_code !== 0 || !is_file($out) || filesize($out) === 0) throw new RuntimeException("«{$file['name']}»: не удалось превратить гифку в видео.");
            $prepared[$index] = ['kind' => 'video', 'path' => $out, 'name' => $file['name']] + telegram_video_info($out) + ['duration' => 0];
        }
    }
    return $prepared;
}

// ---- Предварительная проверка ----

/** Размер файла по ссылке (HEAD) или null, если узнать не удалось. */
function telegram_remote_size($url) {
    // Ответ помнится в файле: вкладка «Предложка» проверяет десятки записей, а HEAD к чужому серверу — это сеть на каждую
    $cache_file = sys_get_temp_dir() . '/postmaker-remote-sizes.json';
    $cache = @json_decode((string)@file_get_contents($cache_file), true);
    $cache = is_array($cache) ? $cache : [];
    $key = substr(sha1($url), 0, 16);
    if (isset($cache[$key]) && time() - (int)$cache[$key][1] < ($cache[$key][0] === null ? 600 : 6 * 3600)) return $cache[$key][0];
    $size = telegram_remote_size_fetch($url);
    $cache = array_filter($cache, fn($entry) => time() - (int)$entry[1] < 6 * 3600);
    $cache[$key] = [$size, time()];
    @file_put_contents($cache_file, json_encode($cache), LOCK_EX);
    return $size;
}

function telegram_remote_size_fetch($url) {
    try {
        $resolve = public_url_resolve($url);
    } catch (Throwable $error) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_NOBODY => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Postmaker/1.0']);
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $length = (float)curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);
    return $status === 200 && $length > 0 ? (int)$length : null;
}

/**
 * Можно ли запостить файлы записи в Telegram: ['status' => 'ok' | 'partial' (часть файлов не пройдёт) | 'blocked' (ни один),
 * 'problems' => [текст], 'bad' => [номера файлов в карточке]]. Проверяются видео (картинки Telegram принимает всегда: большие
 * сжимаются): размер до 50 МБ, наличие видеодорожки, ffmpeg для перекодирования. Сеть нужна только видео без копии в кэше сайта.
 */
function telegram_preflight_item(array $item) {
    $files = [];
    $gallery = is_array($item['gallery'] ?? null) ? $item['gallery'] : [];
    if (count($gallery) > 1) {
        foreach ($gallery as $index => $file) {
            $is_main = ($file['image'] ?? '') === content_image_key($item);   // у главного файла копия лежит в самой записи
            $files[] = ['video' => (string)($file['video'] ?? ''), 'video_local' => $is_main ? (string)($item['video_local'] ?? '') : (string)($file['video_local'] ?? '')];
        }
    } else {
        $files[] = ['video' => (string)($item['video'] ?? ''), 'video_local' => (string)($item['video_local'] ?? '')];
    }
    $problems = [];
    $warnings = [];
    $bad = [];
    foreach ($files as $index => $file) {
        if ($file['video'] === '') continue;
        $number = count($files) > 1 ? 'Видео ' . ($index + 1) . ': ' : 'Видео: ';
        $path = media_cache_path($file['video_local']);
        $problem = '';
        $size = null;
        if ($path !== null && is_file($path)) {
            $size = filesize($path);
            $info = telegram_video_info($path);
            $ready = $info['video'] === 'h264' && str_contains($info['container'], 'mp4') && in_array($info['audio'], ['', 'aac'], true);
            if ($info['video'] === '') $problem = 'файл не распознан как видео (или на сервере нет ffprobe)';
            elseif (!$ready && (!function_exists('ffmpeg_binary') || ffmpeg_binary() === '')) $problem = 'нужно перекодировать в MP4, а ffmpeg на сервере не найден';
        } else {
            $size = telegram_remote_size($file['video']);   // копии в кэше нет: обычно из-за размера (кэш держит до 30 МБ)
        }
        if ($problem === '' && $size !== null) {
            $verdict = telegram_video_verdict($size, 'bot');
            if ($verdict === 'blocked') $problem = telegram_video_limit_text($size, 'bot');
            elseif ($verdict === 'risky') $warnings[] = $number . telegram_video_limit_text($size, 'bot') . ' по последним данным; Telegram мог изменить лимит, поэтому попробуем отправить.';
        }
        if ($problem !== '') { $problems[] = $number . $problem; $bad[] = $index; }
    }
    $status = !$bad ? 'ok' : (count($bad) >= count($files) ? 'blocked' : 'partial');
    return ['status' => $status, 'problems' => $problems, 'warnings' => $warnings, 'bad' => $bad];
}

// ---- Отправка ----

/** Ссылка на опубликованное сообщение: у публичного канала через @имя, у закрытого — t.me/c/…. */
function telegram_message_url(array $connection, $message_id) {
    if (!empty($connection['chat_username'])) return 'https://t.me/' . rawurlencode($connection['chat_username']) . '/' . (int)$message_id;
    $chat = (string)($connection['chat_id'] ?? '');
    return preg_match('/^-100(\d+)$/', $chat, $m) ? 'https://t.me/c/' . $m[1] . '/' . (int)$message_id : '';
}

/** Проверка текста по лимитам Telegram: подпись к медиа — 1024 символа, пост без медиа — 4096. */
function telegram_check_text($text, $media_count) {
    $limit = $media_count > 0 ? TELEGRAM_CAPTION_LIMIT : TELEGRAM_TEXT_LIMIT;
    if (mb_strlen($text) > $limit) throw new RuntimeException("Текст длиннее лимита Telegram: " . mb_strlen($text) . " из {$limit}" . ($media_count > 0 ? ' (подпись к медиа)' : '') . '.');
    if ($media_count === 0 && trim($text) === '') throw new RuntimeException('Добавьте медиа или текст.');
}

/** Отправляет подготовленный пост. Возвращает ['message_ids' => [...], 'url' => ссылка или '']. */
function telegram_publish_prepared(array $connection, $text, array $prepared) {
    $token = (string)($connection['token'] ?? '');
    $chat = (string)($connection['chat_id'] ?? '');
    if ($token === '' || $chat === '') throw new RuntimeException('Telegram не подключён: подключите бота и канал в «Личном кабинете».');
    telegram_check_text($text, count($prepared));
    $timeout = 600;
    $common = ['chat_id' => $chat];
    $biggest_video = 0;
    foreach ($prepared as $file) if ($file['kind'] === 'video') $biggest_video = max($biggest_video, (int)@filesize($file['path']));
    try {
        return telegram_publish_prepared_send($token, $chat, $connection, $text, $prepared, $timeout, $common, $biggest_video);
    } catch (RuntimeException $error) {
        if ($biggest_video > 0 && $error->getMessage() === 'Файл слишком большой для Telegram.') {
            telegram_video_limit_failure($biggest_video, 'bot');   // запоминаем: на таком размере Telegram отказал
            throw new RuntimeException('Telegram не принял видео (' . telegram_video_limit_text($biggest_video, 'bot') . '). Лимит запомнен: такие видео приложение больше не предлагает.');
        }
        throw $error;
    }
}

function telegram_publish_prepared_send($token, $chat, array $connection, $text, array $prepared, $timeout, array $common, $biggest_video) {

    if (!$prepared) {
        $message = telegram_bot_request($token, 'sendMessage', $common + ['text' => $text, 'disable_web_page_preview' => 'true']);
        $ids = [(int)$message['message_id']];
    } elseif (count($prepared) === 1) {
        $file = $prepared[0];
        $caption = $text !== '' ? ['caption' => $text] : [];
        if ($file['kind'] === 'photo') $message = telegram_bot_request($token, 'sendPhoto', $common + $caption, ['photo' => $file['path']], $timeout);
        elseif ($file['kind'] === 'animation') $message = telegram_bot_request($token, 'sendAnimation', $common + $caption, ['animation' => $file['path']], $timeout);
        else $message = telegram_bot_request($token, 'sendVideo', $common + $caption + ['supports_streaming' => 'true', 'width' => $file['width'], 'height' => $file['height'], 'duration' => $file['duration']], ['video' => $file['path']], $timeout);
        $ids = [(int)$message['message_id']];
    } else {
        $media = [];
        $files = [];
        foreach ($prepared as $index => $file) {
            $entry = ['type' => $file['kind'] === 'video' ? 'video' : 'photo', 'media' => "attach://file{$index}"];
            if ($file['kind'] === 'video') $entry += ['supports_streaming' => true, 'width' => $file['width'], 'height' => $file['height'], 'duration' => $file['duration']];
            if ($index === 0 && $text !== '') $entry['caption'] = $text;   // у альбома подпись показывается, если она у первого файла
            $media[] = $entry;
            $files["file{$index}"] = $file['path'];
        }
        $messages = telegram_bot_request($token, 'sendMediaGroup', $common + ['media' => json_encode($media, JSON_UNESCAPED_UNICODE)], $files, $timeout);
        $ids = array_map(fn($message) => (int)$message['message_id'], $messages);
    }
    if ($biggest_video > 0) telegram_video_limit_success($biggest_video, 'bot');   // приняли — лимит не меньше этого размера
    return ['message_ids' => $ids, 'url' => telegram_message_url($connection, $ids[0] ?? 0)];
}

// ---- Очередь отложенных постов и журнал отправленных ----

function outbox_file($uid = null) { return user_dir($uid) . '/outbox.json'; }
function outbox_files_dir($post_id, $uid = null) { return user_dir($uid) . '/outbox/' . $post_id; }
function published_file($uid = null) { return user_dir($uid) . '/published.json'; }

/**
 * Подключённый Telegram-канал пользователя или null. Токен на диске хранится зашифрованным (token_enc, lib/secrets.php);
 * здесь он расшифровывается в поле token. Запись старого вида (token открытым текстом) тоже читается.
 */
function telegram_connection($uid = null) {
    $connections = load_data(connections_file($uid), []);
    $telegram = $connections['telegram'] ?? null;
    if (!is_array($telegram) || empty($telegram['chat_id'])) return null;
    if (!empty($telegram['token_enc'])) $telegram['token'] = secret_decrypt($telegram['token_enc']);
    unset($telegram['token_enc']);
    return !empty($telegram['token']) ? $telegram : null;
}

function telegram_log_published($uid, array $entry) {
    update_data(published_file($uid), [], function ($log) use ($entry) {
        $log = is_array($log) ? $log : [];
        array_unshift($log, $entry);
        return array_slice($log, 0, TELEGRAM_PUBLISHED_LOG_LIMIT);
    });
}

/** Краткая запись для журнала и списков: начало текста и число файлов. */
function telegram_post_summary($text, $media_count) {
    return ['text' => mb_substr(trim((string)$text), 0, 120), 'media' => (int)$media_count];
}

/** Кладёт пост в очередь: файлы копируются в папку пользователя (ссылки и временные файлы к назначенному часу могут пропасть). */
function telegram_queue_post($uid, $text, array $prepared, $publish_at) {
    $id = bin2hex(random_bytes(6));
    $dir = outbox_files_dir($id, $uid);
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Не удалось создать папку очереди.');
    $media = [];
    foreach ($prepared as $index => $file) {
        $extension = strtolower(pathinfo($file['path'], PATHINFO_EXTENSION)) ?: ($file['kind'] === 'video' ? 'mp4' : 'jpg');
        $name = "{$index}." . preg_replace('/[^a-z0-9]/', '', $extension);
        if (!@copy($file['path'], $dir . '/' . $name)) throw new RuntimeException('Не удалось сохранить файл в очередь.');
        @chmod($dir . '/' . $name, 0664);
        $media[] = ['kind' => $file['kind'], 'file' => $name, 'name' => $file['name'], 'width' => $file['width'] ?? 0, 'height' => $file['height'] ?? 0, 'duration' => $file['duration'] ?? 0];
    }
    update_data(outbox_file($uid), [], function ($queue) use ($id, $text, $media, $publish_at) {
        $queue = is_array($queue) ? $queue : [];
        $queue[] = ['id' => $id, 'created_at' => time(), 'publish_at' => (int)$publish_at, 'text' => $text, 'media' => $media, 'status' => 'pending', 'attempts' => 0, 'error' => ''];
        return $queue;
    });
    return $id;
}

/** Убирает пост из очереди (отмена или отправлен) вместе с его файлами. Возвращает true, если запись была. */
function telegram_unqueue_post($uid, $post_id) {
    $found = false;
    update_data(outbox_file($uid), [], function ($queue) use ($post_id, &$found) {
        $queue = is_array($queue) ? $queue : [];
        $kept = array_values(array_filter($queue, fn($entry) => ($entry['id'] ?? '') !== $post_id));
        $found = count($kept) !== count($queue);
        return $found ? $kept : null;
    });
    if ($post_id !== '' && preg_match('/^[a-f0-9]{12}$/', $post_id)) telegram_remove_dir(outbox_files_dir($post_id, $uid));
    return $found;
}

/** Запись в списке о посте, который уже стоит в «Отложенных» Telegram (status = telegram): cron её не трогает. */
function telegram_record_native_post($uid, $text, array $prepared, $publish_at, array $message_ids) {
    $id = bin2hex(random_bytes(6));
    $media = array_map(fn($file) => ['kind' => $file['kind'], 'name' => $file['name'] ?? ''], $prepared);
    update_data(outbox_file($uid), [], function ($queue) use ($id, $text, $media, $publish_at, $message_ids) {
        $queue = is_array($queue) ? $queue : [];
        $queue[] = ['id' => $id, 'created_at' => time(), 'publish_at' => (int)$publish_at, 'text' => $text, 'media' => $media,
            'status' => 'telegram', 'tg_ids' => $message_ids, 'attempts' => 0, 'error' => ''];
        return $queue;
    });
    return $id;
}

/** Посты из «Отложенных» Telegram, время которых прошло, переносятся в журнал отправленных. */
function telegram_settle_native_posts($uid) {
    $settled = [];
    update_data(outbox_file($uid), [], function ($queue) use (&$settled) {
        $queue = is_array($queue) ? $queue : [];
        $kept = [];
        foreach ($queue as $entry) {
            if (($entry['status'] ?? '') === 'telegram' && (int)$entry['publish_at'] + 120 < time()) $settled[] = $entry; else $kept[] = $entry;
        }
        return $settled ? $kept : null;
    });
    foreach ($settled as $entry) {
        telegram_log_published($uid, telegram_post_summary($entry['text'], count($entry['media'])) + ['at' => (int)$entry['publish_at'], 'scheduled_for' => (int)$entry['publish_at'], 'url' => '', 'status' => 'ok', 'error' => '']);
    }
}

/** Берёт в работу один созревший пост пользователя (помечает «отправляется»). Возвращает запись или null. */
function telegram_claim_due_post($uid, $now) {
    $claimed = null;
    update_data(outbox_file($uid), [], function ($queue) use (&$claimed, $now) {
        $queue = is_array($queue) ? $queue : [];
        foreach ($queue as $index => $entry) {
            $status = $entry['status'] ?? 'pending';
            $stale = $status === 'sending' && $now - (int)($entry['claimed_at'] ?? 0) > TELEGRAM_CLAIM_TIMEOUT;
            if (($status === 'pending' || $stale) && (int)$entry['publish_at'] <= $now) {
                $queue[$index]['status'] = 'sending';
                $queue[$index]['claimed_at'] = $now;
                $queue[$index]['attempts'] = (int)($entry['attempts'] ?? 0) + 1;
                $claimed = $queue[$index];
                return $queue;
            }
        }
        return null;
    });
    return $claimed;
}

/** Отправляет созревшие посты одного пользователя. Возвращает число отправленных и неудачных. */
function telegram_publish_due_for_user($uid, $now = null) {
    $now = $now ?? time();
    $result = ['sent' => 0, 'failed' => 0];
    while (($entry = telegram_claim_due_post($uid, $now)) !== null) {
        $summary = telegram_post_summary($entry['text'], count($entry['media']));
        try {
            $connection = telegram_connection($uid);
            if ($connection === null) throw new RuntimeException('Telegram не подключён: подключите бота и канал в «Личном кабинете».');
            $dir = outbox_files_dir($entry['id'], $uid);
            $prepared = array_map(function ($file) use ($dir) {
                $path = $dir . '/' . $file['file'];
                if (!is_file($path)) throw new RuntimeException('Файл отложенного поста пропал: ' . $file['name']);
                return ['kind' => $file['kind'], 'path' => $path, 'name' => $file['name'], 'width' => $file['width'] ?? 0, 'height' => $file['height'] ?? 0, 'duration' => $file['duration'] ?? 0];
            }, $entry['media']);
            $sent = telegram_publish_prepared($connection, $entry['text'], $prepared);
            telegram_unqueue_post($uid, $entry['id']);
            telegram_log_published($uid, $summary + ['at' => time(), 'scheduled_for' => (int)$entry['publish_at'], 'url' => $sent['url'], 'status' => 'ok', 'error' => '']);
            $result['sent']++;
        } catch (Throwable $error) {
            $final = (int)$entry['attempts'] >= TELEGRAM_MAX_ATTEMPTS;
            update_data(outbox_file($uid), [], function ($queue) use ($entry, $error, $final, $now) {
                $queue = is_array($queue) ? $queue : [];
                foreach ($queue as $index => $item) {
                    if (($item['id'] ?? '') !== $entry['id']) continue;
                    $queue[$index]['status'] = $final ? 'failed' : 'pending';
                    $queue[$index]['error'] = $error->getMessage();
                    if (!$final) $queue[$index]['publish_at'] = $now + TELEGRAM_RETRY_SECONDS;
                }
                return $queue;
            });
            if ($final) telegram_log_published($uid, $summary + ['at' => time(), 'scheduled_for' => (int)$entry['publish_at'], 'url' => '', 'status' => 'failed', 'error' => $error->getMessage()]);
            $result['failed']++;
            break;   // остальные созревшие посты этого пользователя подождут следующего запуска: канал или токен, скорее всего, не в порядке
        }
    }
    return $result;
}
