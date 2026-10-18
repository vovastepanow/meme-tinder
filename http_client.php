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
        CURLOPT_USERAGENT => 'ContentTinder/1.0'
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

/**
 * Строка обмена с моделью: в парсере (CLI) печатается в его журнал, который показывает сайт,
 * в веб-запросе копится и уходит в ответе как ai_log. $limit 0 — без обрезки.
 * Направления: '→' запрос, '←' ответ, 'мысли' начало размышлений, 'мысли+' их продолжение.
 */
function record_ai_exchange($direction, $model, $text, $limit = 1500) {
    $text = trim(preg_replace('/\s+/u', ' ', (string)$text) ?? (string)$text);
    if ($limit > 0 && function_exists('mb_strimwidth')) $text = mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
    $line = "[ИИ {$direction} {$model}] {$text}";
    if (PHP_SAPI === 'cli') {
        echo $line . "\n";
        @flush();
    } else {
        ai_exchange_log($line);
    }
}

const OLLAMA_THINKING_LOG_CHUNK = 220;  // примерная длина одной строки размышлений в журнале
const OLLAMA_TIMEOUT = 180;
const OLLAMA_THINKING_TIMEOUT = 420;   // с размышлениями ответ заметно дольше

/**
 * Пишет размышления в журнал по мере генерации: кусками по границе слова, первая строка — 'мысли',
 * следующие — 'мысли+' (сайт склеивает их в один блок). В веб-запросе размышления пишутся целиком в конце.
 */
function ollama_thinking_logger($model) {
    $buffer = '';
    $started = false;
    $live = PHP_SAPI === 'cli';
    return function ($chunk, $final = false) use ($model, &$buffer, &$started, $live) {
        $buffer .= $chunk;
        while ($live && mb_strlen($buffer) >= OLLAMA_THINKING_LOG_CHUNK) {
            $cut = mb_strrpos(mb_substr($buffer, 0, OLLAMA_THINKING_LOG_CHUNK + 40), ' ');
            if ($cut === false || $cut < OLLAMA_THINKING_LOG_CHUNK / 2) $cut = OLLAMA_THINKING_LOG_CHUNK;
            record_ai_exchange($started ? 'мысли+' : 'мысли', $model, mb_substr($buffer, 0, $cut), 0);
            $buffer = mb_substr($buffer, $cut);
            $started = true;
        }
        if ($final && trim($buffer) !== '') {
            record_ai_exchange($started ? 'мысли+' : 'мысли', $model, $buffer, 0);
            $buffer = '';
            $started = true;
        }
        return $started;
    };
}

/**
 * Один потоковый запрос к Ollama (stream=true): ответ приходит строками JSON.
 * Размышления передаются в $on_thinking по мере генерации. Они бывают в двух видах:
 *  - отдельным полем thinking (если Ollama сама разбирает размышления модели);
 *  - прямо в тексте ответа до тега </think>: шаблон некоторых моделей (например, Qwen 3.5) сам открывает
 *    <think>, а Ollama его не разбирает. Тогда текст до </think> — размышления, после — ответ.
 * Возвращает ['status', 'error', 'api_error', 'response', 'thinking'].
 */
function ollama_stream(array $payload, $timeout, callable $on_thinking) {
    $payload['stream'] = true;
    $inline_thinking = !empty($payload['think']);
    $result = ['status' => 0, 'error' => '', 'response' => '', 'thinking' => '', 'api_error' => ''];
    $raw_response = '';    // весь текст ответа, как его прислала модель
    $answer_started = !$inline_thinking;
    $pending = '';

    $handle_line = function ($line) use (&$result, &$raw_response, &$answer_started, $on_thinking) {
        $data = json_decode($line, true);
        if (!is_array($data)) return;
        if (!empty($data['error'])) $result['api_error'] = (string)$data['error'];
        if (($data['thinking'] ?? '') !== '') {
            $result['thinking'] .= $data['thinking'];
            $on_thinking($data['thinking']);
            $answer_started = true; // размышления пришли отдельным полем — текст ответа уже чистый
        }
        $piece = (string)($data['response'] ?? '');
        if ($piece === '') return;
        $raw_response .= $piece;
        if ($answer_started) {
            $result['response'] .= $piece;
            return;
        }
        // Размышления в тексте ответа: всё до </think> показываем как мысли, остальное — ответ.
        $close = strpos($raw_response, '</think>');
        if ($close === false) {
            $on_thinking(str_replace('<think>', '', $piece));
            return;
        }
        $answer_started = true;
        $before_close = strlen($raw_response) - strlen($piece);
        if ($close > $before_close) $on_thinking(substr($raw_response, $before_close, $close - $before_close));
        $result['thinking'] .= substr($raw_response, 0, $close);
        $result['response'] = ltrim(substr($raw_response, $close + strlen('</think>')));
    };

    $ch = curl_init(OLLAMA_GENERATE_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$pending, $handle_line) {
            $pending .= $chunk;
            while (($newline = strpos($pending, "\n")) !== false) {
                $handle_line(substr($pending, 0, $newline));
                $pending = substr($pending, $newline + 1);
            }
            return strlen($chunk);
        }
    ]);
    curl_exec($ch);
    if (trim($pending) !== '') $handle_line($pending);
    $result['status'] = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $result['error'] = curl_error($ch);
    curl_close($ch);

    // Тега </think> так и не было: значит, модель отвечала без размышлений и весь текст — ответ.
    if (!$answer_started) {
        $result['thinking'] = '';
        $result['response'] = trim(str_replace('<think>', '', $raw_response));
        $result['inline_thinking_unclosed'] = true;
    }
    return $result;
}

/**
 * Запрос к модели с записью в журнал: запрос, размышления (по ходу генерации) и ответ.
 * $think = false — быстрый режим без размышлений (тогда можно передать format=json).
 */
function ollama_post(array $payload, $think = true) {
    $model = $payload['model'] ?? '?';
    $image_count = count($payload['images'] ?? []);
    $wants_thinking = $think && in_array($model, OLLAMA_THINKING_MODELS, true);
    if ($wants_thinking) {
        $payload['think'] = true;
        unset($payload['format']); // format=json отключает размышления; JSON разбирается из текста ответа
    } elseif (in_array($model, OLLAMA_THINKING_MODELS, true)) {
        $payload['think'] = false;
    }
    $timeout = $wants_thinking ? OLLAMA_THINKING_TIMEOUT : OLLAMA_TIMEOUT;

    $mode = $wants_thinking ? '' : (in_array($model, OLLAMA_THINKING_MODELS, true) ? '[без размышлений] ' : '');
    record_ai_exchange('→', $model, $mode . ($image_count ? "[изображений: {$image_count}] " : '') . ($payload['prompt'] ?? ''));
    $log_thinking = ollama_thinking_logger($model);
    $result = ollama_stream($payload, $timeout, $log_thinking);

    // Модель без поддержки размышлений отвечает ошибкой на параметр think: повторяем без него и сообщаем об этом.
    if ($wants_thinking && stripos($result['api_error'], 'think') !== false) {
        record_ai_exchange('мысли', $model, 'модель не поддерживает размышления, запрос повторён без них (' . $result['api_error'] . ')');
        unset($payload['think']);
        $result = ollama_stream($payload, OLLAMA_TIMEOUT, $log_thinking);
    }

    $has_thinking = $log_thinking('', true);
    if ($wants_thinking && !$has_thinking && $result['api_error'] === '' && $result['error'] === '') {
        record_ai_exchange('мысли', $model, 'модель не вернула размышления');
    }

    $reply = $result['api_error'] !== '' ? 'ошибка: ' . $result['api_error']
        : ($result['error'] !== '' ? 'ошибка: ' . $result['error'] : $result['response']);
    record_ai_exchange('←', $model, $reply !== '' ? $reply : 'пустой ответ (HTTP ' . $result['status'] . ')');
    return $result;
}

/** Запрос к модели. Любая ошибка (сеть, HTTP, пустой ответ) — исключение. */
function ai_generate_local($model, $prompt, $images = [], $format = null, array $options = [], $think = true) {
    $payload = ['model' => $model, 'prompt' => $prompt];
    if ($images) $payload['images'] = $images;
    if ($format) $payload['format'] = $format;
    if ($options) $payload['options'] = $options;

    $result = ollama_post($payload, $think);
    if ($result['api_error'] !== '') throw new RuntimeException($result['api_error']);
    if ($result['error'] !== '' || $result['status'] < 200 || $result['status'] >= 300) {
        throw new RuntimeException($result['error'] ?: 'Ошибка локальной Ollama (HTTP ' . $result['status'] . ').');
    }
    if (trim($result['response']) === '') throw new RuntimeException('Модель вернула пустой ответ.');
    return trim($result['response']);
}
