<?php
// HTTP-запросы через curl и вызовы локальной Ollama с журналом обмена с моделью.

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

/** Collects AI exchange lines for the current HTTP request (returned to the UI as `ai_log`). */
function ai_exchange_log(?string $line = null): array {
    static $lines = [];
    if ($line !== null) $lines[] = $line;
    return $lines;
}

/** CLI (parser) prints to its log file, which the UI tails; web requests buffer for json_response. $limit 0 = no cutoff. */
function record_ai_exchange($direction, $model, $text, $limit = 1500) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text) ?? (string)$text);
    if ($limit > 0 && function_exists('mb_strimwidth')) $text = mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
    $line = "[ИИ {$direction} {$model}] {$text}";
    if (PHP_SAPI === 'cli') echo $line . "\n";
    else ai_exchange_log($line);
}

/** One HTTP call to Ollama. Always returns [response, decoded] where decoded is an array (empty on failure). */
function ollama_send(array $payload, $timeout) {
    $response = http_post(OLLAMA_GENERATE_URL, json_encode($payload), [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => $timeout
    ]);
    $decoded = is_string($response['body']) ? json_decode($response['body'], true) : null;
    return [$response, is_array($decoded) ? $decoded : []];
}

function ollama_post(array $payload, $timeout) {
    $model = $payload['model'] ?? '?';
    $image_count = count($payload['images'] ?? []);
    $wants_thinking = in_array($model, OLLAMA_THINKING_MODELS, true);
    if ($wants_thinking) $payload['think'] = true;

    record_ai_exchange('→', $model, ($image_count ? "[изображений: {$image_count}] " : '') . ($payload['prompt'] ?? ''));
    [$response, $decoded] = ollama_send($payload, $timeout);

    // Модель без поддержки размышлений отвечает ошибкой на параметр think: повторяем без него и сообщаем об этом.
    if ($wants_thinking && !empty($decoded['error']) && stripos((string)$decoded['error'], 'think') !== false) {
        record_ai_exchange('мысли', $model, 'модель не поддерживает размышления, запрос повторён без них (' . $decoded['error'] . ')');
        unset($payload['think']);
        [$response, $decoded] = ollama_send($payload, $timeout);
    }

    // Размышления приходят отдельным полем до ответа, поэтому пишем их первыми и целиком.
    if (!empty($decoded['thinking'])) {
        record_ai_exchange('мысли', $model, $decoded['thinking'], 0);
    } elseif ($wants_thinking && empty($decoded['error'])) {
        record_ai_exchange('мысли', $model, 'модель не вернула размышления (поле thinking пустое или отсутствует)');
    }

    $reply = $decoded['response'] ?? $decoded['error'] ?? null;
    record_ai_exchange('←', $model, $reply ?? ('ошибка: ' . ($response['error'] ?: 'HTTP ' . $response['status'])));
    return $response;
}

/** Запрос к модели. Любая ошибка (сеть, HTTP, некорректный ответ) — исключение. */
function ai_generate_local($model, $prompt, $images = [], $format = null, array $options = []) {
    $payload = ['model' => $model, 'prompt' => $prompt, 'stream' => false];
    if ($images) $payload['images'] = $images;
    if ($format) $payload['format'] = $format;
    if ($options) $payload['options'] = $options;

    $response = ollama_post($payload, 180);
    $status = $response['status'];
    if (!is_string($response['body']) || $status < 200 || $status >= 300) {
        $api_error = is_string($response['body']) ? (json_decode($response['body'], true)['error'] ?? '') : '';
        throw new RuntimeException($api_error ?: ($response['error'] ?: 'Ошибка локальной Ollama (HTTP ' . $status . ').'));
    }
    $result = json_decode($response['body'], true);
    if (!is_array($result) || !isset($result['response'])) {
        throw new RuntimeException('Модель вернула некорректный ответ.');
    }
    return trim($result['response']);
}
