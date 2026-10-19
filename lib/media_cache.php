<?php
// Кэш медиа отобранного контента: файлы в media_cache/, на которые ссылается пул (поля image_local и video_local).
// Сохраняются в момент отбора записи парсером и удаляются, когда запись уходит из пула (голос, правка базы, очистка).

const MEDIA_CACHE_IMAGE_TYPES = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    'image/bmp' => 'bmp'
];
const MEDIA_CACHE_VIDEO_EXTENSIONS = ['mp4', 'webm'];

function media_cache_ensure_dir() {
    if (!is_dir(MEDIA_CACHE_DIR) && !@mkdir(MEDIA_CACHE_DIR, 0755, true) && !is_dir(MEDIA_CACHE_DIR)) {
        throw new RuntimeException('Не удалось создать каталог кэша медиа.');
    }
}

/** Имя файла кэша: хэш исходного адреса (у одной записи всегда один и тот же файл) и расширение по типу. */
function media_cache_file_name($source_url, $extension) {
    return substr(hash('sha256', (string)$source_url), 0, 32) . '.' . $extension;
}

/** Атомарно записывает файл в кэш с правами 0644 (веб-сервер должен его читать). Возвращает адрес для браузера. */
function media_cache_write($name, $bytes) {
    media_cache_ensure_dir();
    $path = MEDIA_CACHE_DIR . '/' . $name;
    $temporary = tempnam(MEDIA_CACHE_DIR, '.tmp-');
    if ($temporary === false) throw new RuntimeException('Не удалось создать временный файл кэша.');
    if (file_put_contents($temporary, $bytes) !== strlen($bytes) || !@chmod($temporary, 0644) || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось сохранить файл в кэш медиа.');
    }
    return MEDIA_CACHE_URL . '/' . $name;
}

/** Сохраняет проверенную картинку. Возвращает адрес для браузера или '' (не картинка, ошибка записи). */
function media_cache_store_image($source_url, $bytes) {
    $info = is_string($bytes) && $bytes !== '' ? @getimagesizefromstring($bytes) : false;
    $extension = MEDIA_CACHE_IMAGE_TYPES[$info['mime'] ?? ''] ?? null;
    if ($extension === null) return '';
    try {
        return media_cache_write(media_cache_file_name($source_url, $extension), $bytes);
    } catch (Throwable $error) {
        error_log('Media cache: ' . $error->getMessage());
        return '';
    }
}

/**
 * Сохраняет видео в кэш. $downloaded_file — уже скачанный ролик (после раскадровки), иначе видео скачивается
 * заново (не больше VIDEO_MAX_BYTES). Возвращает адрес для браузера или ''.
 */
function media_cache_store_video($source_url, $downloaded_file = null, $force_mp4 = false) {
    $extension = strtolower(pathinfo((string)parse_url($source_url, PHP_URL_PATH), PATHINFO_EXTENSION));
    if ($force_mp4 || !in_array($extension, MEDIA_CACHE_VIDEO_EXTENSIONS, true)) $extension = 'mp4'; // перекодированное — всегда MP4
    $temporary = null;
    if ($downloaded_file === null || !is_file($downloaded_file)) {
        $temporary = download_video_temp($source_url);
        if ($temporary === null) return '';
        $downloaded_file = $temporary;
    }
    try {
        $bytes = file_get_contents($downloaded_file);
        return is_string($bytes) && $bytes !== '' ? media_cache_write(media_cache_file_name($source_url, $extension), $bytes) : '';
    } catch (Throwable $error) {
        error_log('Media cache: ' . $error->getMessage());
        return '';
    } finally {
        if ($temporary !== null) @unlink($temporary);
    }
}

/** Путь на диске для адреса из кэша, или null, если адрес не из кэша. */
function media_cache_path($url) {
    $prefix = MEDIA_CACHE_URL . '/';
    if (!is_string($url) || !str_starts_with($url, $prefix)) return null;
    $name = substr($url, strlen($prefix));
    return preg_match('/^[a-f0-9]{32}\.[a-z0-9]{2,5}$/', $name) ? MEDIA_CACHE_DIR . '/' . $name : null;
}

/** Адрес из кэша, если файл на месте, иначе ''. */
function media_cache_existing($url) {
    $path = media_cache_path($url);
    return $path !== null && is_file($path) ? $url : '';
}

/** Все адреса из кэша, на которые ссылается запись: главная картинка и видео, а также файлы галереи поста. */
function media_cache_item_urls($item) {
    $urls = [];
    foreach ([$item, ...(is_array($item['gallery'] ?? null) ? $item['gallery'] : [])] as $entry) {
        if (!is_array($entry)) continue;
        foreach (['image_local', 'video_local'] as $field) {
            $url = (string)($entry[$field] ?? '');
            if ($url !== '') $urls[] = $url;
        }
    }
    return $urls;
}

/** Показывается ли файл кэша в пуле другого пользователя (файл общий: имя — хэш адреса источника). */
function media_cache_used_by_others($url) {
    foreach (all_users_pool_items(app_user_bind() ?: null) as $item) {
        if (in_array($url, media_cache_item_urls($item), true)) return true;
    }
    return false;
}

function media_cache_remove($url) {
    if (media_cache_used_by_others($url)) return;
    $path = media_cache_path($url);
    if ($path !== null) @unlink($path);
}

/**
 * Удаляет файлы кэша, на которые не ссылается ни одна запись пула.
 * $include_fresh = false щадит файлы моложе MEDIA_CACHE_GRACE_SECONDS (их мог только что сохранить парсер).
 */
function media_cache_cleanup(array $pool, $include_fresh = false) {
    if (!is_dir(MEDIA_CACHE_DIR)) return 0;
    $referenced = [];
    foreach ($pool as $item) {
        foreach (media_cache_item_urls($item) as $url) {
            $path = media_cache_path($url);
            if ($path !== null) $referenced[basename($path)] = true;
        }
    }
    $removed = 0;
    foreach (scandir(MEDIA_CACHE_DIR) ?: [] as $name) {
        $path = MEDIA_CACHE_DIR . '/' . $name;
        if ($name === '.' || $name === '..' || isset($referenced[$name]) || !is_file($path)) continue;
        if (!$include_fresh && time() - (int)@filemtime($path) < MEDIA_CACHE_GRACE_SECONDS) continue;
        if (@unlink($path)) $removed++;
    }
    return $removed;
}

/**
 * Удаляет файлы записей, которые были в пуле $before и пропали из $after (оценены, удалены, отложены не считаются).
 * Имя файла — хэш адреса картинки, а адрес картинки уникален в пуле, поэтому чужой файл так не удалится.
 */
function media_cache_release_removed(array $before, array $after) {
    $kept = [];
    foreach ($after as $item) {
        foreach (media_cache_item_urls($item) as $url) $kept[$url] = true;
    }
    foreach ($before as $item) {
        foreach (media_cache_item_urls($before_item = $item) as $url) {
            if (!isset($kept[$url])) media_cache_remove($url);
        }
    }
}
