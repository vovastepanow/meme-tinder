<?php

function http_get($url, array $options = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_USERAGENT => 'MemeTinder/1.0'
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $response !== false && $status >= 200 && $status < 300 ? $response : false;
}

function http_get_json($url, array $options = []) {
    $response = http_get($url, $options);
    $data = $response === false ? null : json_decode($response, true);
    return is_array($data) ? $data : [];
}

/** @return array{body: string|false, status: int, error: string} */
function http_post($url, $body, array $options = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_BINARYTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15
    ]);
    $response = curl_exec($ch);
    $result = [
        'body' => $response,
        'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'error' => curl_error($ch)
    ];
    curl_close($ch);
    return $result;
}

/** Collects AI exchange lines for the current HTTP request (returned to the UI as `ai_log`). */
function ai_exchange_log(?string $line = null): array {
    static $lines = [];
    if ($line !== null) $lines[] = $line;
    return $lines;
}

/** CLI (parser) prints to its log file, which the UI tails; web requests buffer for json_response. */
function record_ai_exchange($direction, $model, $text) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text) ?? (string)$text);
    if (function_exists('mb_strimwidth')) $text = mb_strimwidth($text, 0, 1500, '...', 'UTF-8');
    $line = "[ИИ {$direction} {$model}] {$text}";
    if (PHP_SAPI === 'cli') echo $line . "\n";
    else ai_exchange_log($line);
}

function ollama_post(array $payload, $timeout) {
    $model = $payload['model'] ?? '?';
    $image_count = count($payload['images'] ?? []);
    record_ai_exchange('→', $model, ($image_count ? "[изображений: {$image_count}] " : '') . ($payload['prompt'] ?? ''));
    $response = http_post(OLLAMA_GENERATE_URL, json_encode($payload), [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => $timeout
    ]);
    $decoded = is_string($response['body']) ? json_decode($response['body'], true) : null;
    $reply = is_array($decoded) ? ($decoded['response'] ?? $decoded['error'] ?? null) : null;
    record_ai_exchange('←', $model, $reply ?? ('ошибка: ' . ($response['error'] ?: 'HTTP ' . $response['status'])));
    return $response;
}

// Lenient call for the background parser: any failure yields an empty array.
function generate_local(array $payload, $timeout = 90) {
    $response = ollama_post($payload, $timeout)['body'];
    $result = $response === false ? null : json_decode($response, true);
    return is_array($result) ? $result : [];
}

// Strict call for interactive requests: failures are reported as exceptions.
function ai_generate_local($model, $prompt, $images = [], $format = null) {
    $payload = ['model' => $model, 'prompt' => $prompt, 'stream' => false];
    if ($images) $payload['images'] = $images;
    if ($format) $payload['format'] = $format;

    $response = ollama_post($payload, 180);
    $status = $response['status'];
    if ($response['body'] === false || $status < 200 || $status >= 300) {
        $api_error = is_string($response['body']) ? (json_decode($response['body'], true)['error'] ?? '') : '';
        throw new RuntimeException($api_error ?: ($response['error'] ?: 'Ошибка локальной Ollama (HTTP ' . $status . ').'));
    }
    $result = json_decode($response['body'], true);
    if (!is_array($result) || !isset($result['response'])) {
        throw new RuntimeException('Модель вернула некорректный ответ.');
    }
    return trim($result['response']);
}
