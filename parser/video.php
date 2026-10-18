<?php
// Видео для фонового парсера: загрузка файла и вырезание кадров через ffmpeg.

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

/** Downloads a video to a temp file. Returns its path, or null if it is missing, too big or unreachable. */
function download_video_temp($url, $max_bytes = VIDEO_MAX_BYTES) {
    $path = tempnam(sys_get_temp_dir(), 'mt-video-');
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
        CURLOPT_USERAGENT => 'MemeTinder/1.0',
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$size, $max_bytes, $handle) {
            $size += strlen($chunk);
            if ($size > $max_bytes) return 0; // abort: the file is too big
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

/** Cuts evenly spaced frames (JPEG bytes) from a video file. Returns [] when ffmpeg is unavailable. */
function video_frame_jpegs($video_path, $count = VIDEO_FRAMES) {
    $ffmpeg = ffmpeg_binary();
    if ($ffmpeg === '' || !is_file($video_path)) return [];

    $duration = (float)trim((string)@shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 ' . escapeshellarg($video_path) . ' 2>/dev/null'));
    if ($duration <= 0) $duration = 3.0;

    $frames = [];
    for ($i = 1; $i <= $count; $i++) {
        $at = $duration * $i / ($count + 1);
        $base = tempnam(sys_get_temp_dir(), 'mt-frame-');
        if ($base === false) break;
        $out = $base . '.jpg';
        $command = escapeshellarg($ffmpeg) . ' -v error -y -ss ' . sprintf('%.2F', $at) . ' -i ' . escapeshellarg($video_path)
            . ' -frames:v 1 -vf scale=768:-2 -q:v 4 ' . escapeshellarg($out) . ' 2>/dev/null';
        exec($command, $output, $exit_code);
        if ($exit_code === 0 && is_file($out) && filesize($out) > 0) {
            $frames[] = file_get_contents($out);
        }
        @unlink($out);
        @unlink($base);
    }
    return array_values(array_filter($frames, 'is_string'));
}

/**
 * Frames of a video for the vision model. Without ffmpeg nothing is downloaded at all;
 * if the download or the frame extraction fails, the poster picture is analysed instead.
 */
function video_analysis_frames($video_url, $poster_data) {
    if (ffmpeg_binary() === '') {
        echo "-> ffmpeg не найден на сервере, кадры видео не извлекаются; анализирую превью.\n";
        return [$poster_data];
    }
    $path = download_video_temp($video_url);
    if ($path === null) {
        echo "-> Видео не скачалось (размер или ошибка сети), анализирую превью.\n";
        return [$poster_data];
    }
    $frames = video_frame_jpegs($path);
    @unlink($path);
    if (!$frames) {
        echo "-> Кадры видео не извлечены, анализирую превью.\n";
        return [$poster_data];
    }
    echo "-> Извлечено кадров видео: " . count($frames) . "\n";
    return $frames;
}
