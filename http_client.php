<?php

function http_get($url, array $options = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $options + [
        CURLOPT_RETURNTRANSFER => true,
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
        CURLOPT_RETURNTRANSFER => true,
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

function ollama_post(array $payload, $timeout) {
    return http_post(OLLAMA_GENERATE_URL, json_encode($payload), [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => $timeout
    ]);
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
