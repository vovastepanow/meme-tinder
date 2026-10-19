<?php
// Кэш остальных файлов поста (галерея записи): без него листание в карточке тянет полноразмерные оригиналы с чужого сервера.

const GALLERY_CACHE_MAX_FILES = 4;          // сколько дополнительных файлов поста сохранять
const GALLERY_IMAGE_MAX_SIDE = 1600;        // картинки крупнее уменьшаются: оригиналы с Двача весят мегабайты и долго раскодируются
const GALLERY_IMAGE_RECOMPRESS_BYTES = 600 * 1024;

/** Картинка для кэша: большая перекодируется в JPEG не крупнее GALLERY_IMAGE_MAX_SIDE (анимированные GIF остаются как есть). */
function gallery_prepare_image($bytes) {
    $info = is_string($bytes) && $bytes !== '' ? @getimagesizefromstring($bytes) : false;
    if (!$info || ($info['mime'] ?? '') === 'image/gif') return $bytes;
    $big = $info[0] > GALLERY_IMAGE_MAX_SIDE || $info[1] > GALLERY_IMAGE_MAX_SIDE || strlen($bytes) > GALLERY_IMAGE_RECOMPRESS_BYTES;
    if (!$big || !function_exists('imagecreatefromstring')) return $bytes;
    $image = @imagecreatefromstring($bytes);
    if (!$image) return $bytes;
    $scale = min(1, GALLERY_IMAGE_MAX_SIDE / max($info[0], $info[1]));
    if ($scale < 1) {
        $resized = imagescale($image, max(1, (int)round($info[0] * $scale)), max(1, (int)round($info[1] * $scale)));
        if ($resized) $image = $resized;
    }
    $flat = imagecreatetruecolor(imagesx($image), imagesy($image));   // прозрачность PNG ложится на белый фон
    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
    imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
    ob_start();
    imagejpeg($flat, null, 85);
    $jpeg = (string)ob_get_clean();
    return $jpeg !== '' && strlen($jpeg) < strlen($bytes) ? $jpeg : $bytes;
}

function gallery_download_image($url) {
    $bytes = http_get($url, [CURLOPT_TIMEOUT => 20, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => 'Postmaker/1.0']);
    return is_string($bytes) && $bytes !== '' && strlen($bytes) <= MAX_IMAGE_BYTES ? $bytes : null;
}

/**
 * Сохраняет в кэш файлы галереи, кроме первого (он кэшируется вместе с записью): картинки уменьшенными, видео — в H.264.
 * Возвращает галерею с полями image_local / video_local. Файл, который не удалось сохранить, остаётся по исходной ссылке.
 */
function gallery_cache_files(array $gallery, $main_image = null) {
    $done = 0;
    foreach ($gallery as $index => $file) {
        // Главный файл записи кэшируется вместе с ней. Он первый в галерее — кроме записей, оценённых когда пост ещё делился по файлам:
        // у них главный файл может стоять в галерее где угодно, поэтому ищем его по адресу картинки.
        $is_main = $main_image !== null ? ($file['image'] ?? '') === $main_image : $index === 0;
        if ($is_main || !empty($file['image_local']) || !empty($file['video_local']) || $done >= GALLERY_CACHE_MAX_FILES) continue;
        $done++;
        $bytes = gallery_download_image($file['image']);
        if ($bytes !== null) $gallery[$index]['image_local'] = media_cache_store_image($file['image'], gallery_prepare_image($bytes));
        if (($file['video'] ?? '') !== '') {
            $downloaded = download_video_temp($file['video']);
            if ($downloaded !== null) {
                $playable = browser_playable_video($downloaded);
                $gallery[$index]['video_local'] = media_cache_store_video($file['video'], $playable, $playable !== $downloaded);
                if ($playable !== null && $playable !== $downloaded) @unlink($playable);
                @unlink($downloaded);
            }
        }
    }
    return $gallery;
}

/** Дополняет кэшем галереи записи пула, оценённые до появления кэша галерей (по несколько за запуск). */
function cache_pool_galleries($limit = 4) {
    $todo = [];
    foreach (load_data(pool_file(), []) as $item) {
        $gallery = is_array($item['gallery'] ?? null) ? $item['gallery'] : [];
        if (count($gallery) < 2 || count($todo) >= $limit) continue;
        $cached = false;
        foreach (array_slice($gallery, 1) as $file) if (!empty($file['image_local']) || !empty($file['video_local'])) $cached = true;
        if (!$cached) $todo[content_image_key($item)] = gallery_cache_files($gallery, content_image_key($item));
    }
    if (!$todo) return 0;
    update_data(pool_file(), [], function ($pool) use ($todo) {
        foreach ($pool as $index => $item) {
            $key = content_image_key($item);
            if (isset($todo[$key])) $pool[$index]['gallery'] = $todo[$key];
        }
        return $pool;
    });
    echo 'Кэш галерей: обработано записей: ' . count($todo) . ".\n";
    return count($todo);
}
