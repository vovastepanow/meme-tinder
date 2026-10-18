<?php
// Изображения от пользователя: проверка байтов, безопасная загрузка по ссылке (без доступа к внутренней сети).

function validate_image_bytes($image_data) {
    if (!is_string($image_data) || strlen($image_data) > MAX_IMAGE_BYTES) {
        throw new RuntimeException('Изображение должно быть не больше 10 МБ.');
    }
    if (!function_exists('getimagesizefromstring')) {
        throw new RuntimeException('На сервере недоступна проверка изображений (PHP GD).');
    }
    $image = @getimagesizefromstring($image_data);
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];
    if (!$image || !in_array($image['mime'] ?? '', $allowed_types, true)) {
        throw new RuntimeException('Поддерживаются только JPEG, PNG, GIF, WebP и BMP.');
    }
    if (($image[0] * $image[1]) > 40000000) {
        throw new RuntimeException('Слишком большое разрешение изображения.');
    }
}

function fetch_public_image($url) {
    $parts = parse_url($url);
    if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
        throw new RuntimeException('Укажите прямую публичную ссылку на изображение.');
    }
    $host = rtrim(strtolower($parts['host']), '.');
    $scheme = strtolower($parts['scheme']);
    $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
    if (!in_array($port, [80, 443], true) || ($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
        throw new RuntimeException('Ссылка должна использовать стандартный порт HTTP или HTTPS.');
    }

    $resolve = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || strpos($host, ':') !== false) {
            throw new RuntimeException('Ссылка должна вести на публичный IPv4-адрес или домен.');
        }
    } else {
        $addresses = gethostbynamel($host);
        if (!$addresses) throw new RuntimeException('Не удалось найти адрес сайта из ссылки.');
        foreach ($addresses as $address) {
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Ссылка ведёт на непубличный адрес.');
            }
        }
        $resolve[] = $host . ':' . $port . ':' . $addresses[0];
    }

    $image_data = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'ContentTinder/1.0',
        CURLOPT_WRITEFUNCTION => function($curl, $chunk) use (&$image_data) {
            if (strlen($image_data) + strlen($chunk) > MAX_IMAGE_BYTES) return 0;
            $image_data .= $chunk;
            return strlen($chunk);
        }
    ]);
    if ($resolve) curl_setopt($ch, CURLOPT_RESOLVE, $resolve);
    $success = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $content_type = strtolower(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
    $error = curl_error($ch);
    curl_close($ch);

    if ($success === false || $status < 200 || $status >= 300 || strpos($content_type, 'image/') !== 0) {
        throw new RuntimeException($error ? 'Не удалось скачать изображение: ' . $error : 'Ссылка должна вести прямо на файл изображения, без страницы или редиректа.');
    }
    return $image_data;
}

/** Reads an uploaded `image_file` or downloads `image_url` from the request. */
function read_request_image($json_input, $upload_error_message, $missing_url_message) {
    if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['image_file'];
        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException($upload_error_message);
        if ($file['size'] > MAX_IMAGE_BYTES) throw new RuntimeException('Изображение должно быть не больше 10 МБ.');
        return file_get_contents($file['tmp_name']);
    }
    $image_url = trim((string)($_POST['image_url'] ?? ($json_input['image_url'] ?? '')));
    if ($image_url === '') throw new RuntimeException($missing_url_message);
    return fetch_public_image($image_url);
}
