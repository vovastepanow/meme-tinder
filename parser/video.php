<?php
// Видео для фонового парсера: загрузка файла и раскадровка через ffmpeg.
// Ollama принимает только картинки, поэтому видео передаётся модели как последовательность кадров
// с метками времени: примерно кадр в секунду, от VIDEO_FRAMES_MIN до VIDEO_FRAMES_MAX кадров.

function ffmpeg_binary() {
    static $binary = null;
    if ($binary === null) {
        $binary = '';
        if (function_exists('shell_exec') && function_exists('exec')) {
            $found = trim((string)@shell_exec('command -v ffmpeg 2>/dev/null'));
            if ($found !== '') $binary = $found;
        }
    }
    return $binary;
}

/** Скачивает видео во временный файл. Возвращает путь или null (нет файла, слишком большой, сеть). */
function download_video_temp($url, $max_bytes = VIDEO_MAX_BYTES) {
    $path = tempnam(sys_get_temp_dir(), 'ct-video-');
    if ($path === false) return null;
    $handle = @fopen($path, 'wb');
    if (!$handle) {
        @unlink($path);
        return null;
    }
    $size = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'ContentTinder/1.0',
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$size, $max_bytes, $handle) {
            $size += strlen($chunk);
            if ($size > $max_bytes) return 0; // файл слишком большой — прерываем загрузку
            return fwrite($handle, $chunk);
        }
    ]);
    $ok = curl_exec($ch) !== false && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
    curl_close($ch);
    fclose($handle);
    if (!$ok || $size === 0) {
        @unlink($path);
        return null;
    }
    return $path;
}

function video_duration($video_path) {
    $command = 'ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($video_path) . ' 2>/dev/null';
    return max(0.0, (float)trim((string)@shell_exec($command)));
}

/** Моменты для кадров: примерно раз в VIDEO_SECONDS_PER_FRAME секунд, равномерно по всему ролику. */
function video_frame_timestamps($duration) {
    if ($duration <= 0) $duration = VIDEO_FRAMES_MIN * VIDEO_SECONDS_PER_FRAME;
    $count = (int)round($duration / VIDEO_SECONDS_PER_FRAME);
    $count = max(VIDEO_FRAMES_MIN, min(VIDEO_FRAMES_MAX, $count));
    $timestamps = [];
    for ($i = 0; $i < $count; $i++) {
        $timestamps[] = round($duration * ($i + 0.5) / $count, 2); // середины равных отрезков: без чёрных кадров на краях
    }
    return $timestamps;
}

/**
 * Раскадровка: JPEG-кадры, уменьшенные до VIDEO_FRAME_MAX_SIDE по длинной стороне (так 16 кадров
 * укладываются в контекст модели и для горизонтальных, и для вертикальных роликов).
 * Возвращает ['frames' => [байты], 'timestamps' => [секунды], 'duration' => секунды] или null.
 */
function video_storyboard($video_path) {
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === '' || !is_file($video_path)) return null;

    $duration = video_duration($video_path);
    $side = VIDEO_FRAME_MAX_SIDE;
    $scale = "scale='if(gt(iw,ih),{$side},-2)':'if(gt(iw,ih),-2,{$side})'";
    $frames = [];
    $timestamps = [];
    foreach (video_frame_timestamps($duration) as $at) {
        $out = tempnam(sys_get_temp_dir(), 'ct-frame-');
        if ($out === false) break;
        $command = escapeshellarg($ffmpeg) . ' -v error -y -ss ' . sprintf('%.2F', $at) . ' -i ' . escapeshellarg($video_path)
            . ' -frames:v 1 -vf ' . escapeshellarg($scale) . ' -q:v 4 -f image2 ' . escapeshellarg($out) . ' 2>/dev/null';
        exec($command, $output, $exit_code);
        $bytes = $exit_code === 0 && is_file($out) ? file_get_contents($out) : false;
        @unlink($out);
        if (is_string($bytes) && $bytes !== '') {
            $frames[] = $bytes;
            $timestamps[] = $at;
        }
    }
    if (!$frames) return null;
    return ['frames' => $frames, 'timestamps' => $timestamps, 'duration' => $duration];
}

/**
 * Видео для модели. Возвращает ['frames', 'timestamps', 'duration', 'file'] — 'file' (скачанный ролик,
 * его потом сохраняют в кэш) удаляет вызывающий через release_video_analysis().
 * Без ffmpeg видео не скачивается; если загрузка или раскадровка не удались, анализируется превью.
 */
function video_analysis_input($video_url, $poster_data) {
    $poster_only = ['frames' => [$poster_data], 'timestamps' => [], 'duration' => 0.0, 'file' => null];
    if (ffmpeg_binary() === '') {
        echo "-> ffmpeg не найден на сервере, кадры видео не извлекаются; анализирую превью.\n";
        return $poster_only;
    }
    $path = download_video_temp($video_url);
    if ($path === null) {
        echo "-> Видео не скачалось (размер или ошибка сети), анализирую превью.\n";
        return $poster_only;
    }
    $storyboard = video_storyboard($path);
    if ($storyboard === null) {
        echo "-> Кадры видео не извлечены, анализирую превью.\n";
        return ['file' => $path] + $poster_only;
    }
    echo "-> Раскадровка: " . count($storyboard['frames']) . " кадров из видео длиной " . format_video_time($storyboard['duration']) . ".\n";
    return $storyboard + ['file' => $path];
}

function release_video_analysis($video) {
    if (!empty($video['file'])) @unlink($video['file']);
}

// ---- Совместимость с браузером ----

/** Видеокодеки, которые воспроизводят все основные браузеры (HEVC/H.265 в Chrome на Linux не играет). */
const BROWSER_VIDEO_CODECS = ['h264', 'vp8', 'vp9', 'av1'];

function video_codec($video_path) {
    $command = 'ffprobe -v error -select_streams v:0 -show_entries stream=codec_name -of csv=p=0 ' . escapeshellarg($video_path) . ' 2>/dev/null';
    return strtolower(trim((string)@shell_exec($command)));
}

/**
 * Если кодек ролика браузер не воспроизведёт, перекодирует его в H.264 + AAC (MP4 с быстрым стартом).
 * Возвращает путь к файлу, который можно сохранять в кэш: исходный или новый временный
 * (его потом удаляет вызывающий — он отличается от $video_path). Без ffmpeg возвращает исходный.
 */
function browser_playable_video($video_path) {
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === '' || !is_file($video_path)) return $video_path;
    $codec = video_codec($video_path);
    if ($codec === '' || in_array($codec, BROWSER_VIDEO_CODECS, true)) return $video_path;

    echo "-> Видео в кодеке {$codec}: браузер его может не воспроизвести, перекодирую в H.264...\n";
    $out = tempnam(sys_get_temp_dir(), 'ct-h264-');
    if ($out === false) return $video_path;
    $command = escapeshellarg($ffmpeg) . ' -v error -y -i ' . escapeshellarg($video_path)
        . ' -map 0:v:0 -map 0:a:0? -c:v libx264 -preset veryfast -crf 26 -pix_fmt yuv420p'
        . ' -c:a aac -b:a 128k -movflags +faststart -f mp4 ' . escapeshellarg($out) . ' 2>/dev/null';
    exec($command, $output, $exit_code);
    if ($exit_code !== 0 || !is_file($out) || filesize($out) === 0) {
        @unlink($out);
        echo "-> Перекодировать не удалось, сохраняю как есть.\n";
        return $video_path;
    }
    return $out;
}
