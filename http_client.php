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
        CURLOPT_USERAGENT => 'Postmaker/1.0'
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

/** Модели, установленные в Ollama (запрашивается один раз за процесс). */
function ollama_installed_models() {
    static $models = null;
    if ($models === null) {
        $tags = http_get_json(OLLAMA_URL . '/api/tags', [CURLOPT_TIMEOUT => 5]);
        $models = array_values(array_filter(array_column($tags['models'] ?? [], 'name'), 'is_string'));
    }
    return $models;
}

/**
 * Проверка кандидата на быструю предоценку: отвечает ли он коротким JSON. Возвращает true / false (модель размышляет) или null,
 * если проверить не удалось (тогда вызывающий берёт модель без проверки, а не уходит на медленную основную). Thinking-версии маленьких моделей
 * (например, qwen3-vl:2b) размышляют, даже когда просишь иначе, и на короткий лимит токенов отдают пустой ответ.
 */
function ollama_model_answers_briefly($model) {
    $payload = ['model' => $model, 'prompt' => 'Ответь только JSON: {"ok": true}', 'format' => 'json', 'think' => false,
        'options' => ['temperature' => 0, 'num_predict' => 24]];
    try {
        $result = ollama_stream($payload, 180, function () {});
    } catch (Throwable $error) {
        return null;
    }
    // Таймаут, ошибка сети или загрузки модели (например, Ollama занята чужой моделью) — это «проверить не удалось», а не «модель не годится»
    if ($result['error'] !== '' || $result['api_error'] !== '') return null;
    return trim($result['response']) !== '' && stripos($result['response'], 'ok') !== false;
}

/**
 * Модель для быстрой предоценки: первая установленная из PRESCREEN_MODELS, которая отвечает коротко
 * (instruct-версии да, thinking-версии часто нет), иначе ANALYSIS_MODEL — медленнее, но надёжно.
 */
function prescreen_model() {
    static $model = null;
    if ($model === null) {
        $installed = array_flip(ollama_installed_models());
        $model = ANALYSIS_MODEL;
        foreach (PRESCREEN_MODELS as $candidate) {
            if (!isset($installed[$candidate]) && !isset($installed[$candidate . ':latest'])) continue;
            $answers = null;
            for ($attempt = 0; $attempt < 2 && $answers === null; $attempt++) $answers = ollama_model_answers_briefly($candidate);
            if ($answers !== false) {
                // true — проверена; null — Ollama занята или не ответила: лучше быстрая модель без проверки, чем основная на весь запуск
                if ($answers === null && PHP_SAPI === 'cli') echo "-> Не удалось проверить модель {$candidate} (Ollama занята): использую её без проверки.\n";
                $model = $candidate;
                break;
            }
            if (PHP_SAPI === 'cli') {
                echo "-> Модель {$candidate} размышляет вместо короткого ответа и для быстрой оценки не годится."
                    . (str_ends_with($candidate, '-instruct') ? '' : " Установите instruct-версию: ollama pull {$candidate}-instruct") . "\n";
            }
        }
    }
    return $model;
}

/**
 * Модель полного анализа. Обычно ANALYSIS_MODEL, но если она не укладывается во время запроса (на слабой видеокарте
 * большая модель считает на процессоре и обрывается по таймауту), на ближайшие часы анализ идёт быстрой моделью:
 * лучше чуть менее подробное описание, чем пустой пул.
 */
function analysis_model() {
    $fast = prescreen_model();
    if ($fast !== ANALYSIS_MODEL && time() - (int)@filemtime(MAIN_MODEL_SLOW_FLAG) < MAIN_MODEL_SLOW_HOURS * 3600) return $fast;
    return ANALYSIS_MODEL;
}

/** Освобождает видеопамять: просит Ollama выгрузить модель. */
function ollama_unload_model($model) {
    $handle = curl_init(OLLAMA_GENERATE_URL);
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode(['model' => $model, 'keep_alive' => 0])]);
    curl_exec($handle);
    curl_close($handle);
    // Ollama освобождает память не сразу: ждём, пока модель пропадёт из /api/ps, иначе следующая загрузится «рядом» с ней
    for ($i = 0; $i < 50 && in_array($model, array_column(http_get_json(OLLAMA_URL . '/api/ps')['models'] ?? [], 'name'), true); $i++) usleep(100000);
}

/**
 * Перед работой модели $model выгружает другие модели проекта (PRESCREEN_MODELS и ANALYSIS_MODEL). На видеокарте в 3 ГБ
 * они вместе не помещаются: Ollama грузит новую модель рядом со старой, не учитывая буфер обработчика картинок, и
 * быстрая модель падает с «unexpected EOF» или считает картинку на процессоре, а большая получает несколько процентов
 * видеокарты. Вызывается перед каждым запросом (ollama_stream) — так это работает для парсера, подбора источников и
 * «Теста ИИ»; проверка — один короткий GET /api/ps. Чужие модели (не из настроек проекта) не трогаются.
 */
function ollama_prepare_model($model) {
    $project_models = array_merge(PRESCREEN_MODELS, [ANALYSIS_MODEL]);
    $normalize = function ($name) { return preg_replace('/:latest$/', '', (string)$name); };
    if (!in_array($normalize($model), array_map($normalize, $project_models), true)) return;
    ollama_recycle_runner_if_needed();   // процесс модели разросся — перезапуск до запроса, а не после зависания компьютера
    foreach (http_get_json(OLLAMA_URL . '/api/ps', [CURLOPT_TIMEOUT => 3])['models'] ?? [] as $loaded) {
        $name = (string)($loaded['name'] ?? '');
        if ($normalize($name) !== $normalize($model) && in_array($normalize($name), array_map($normalize, $project_models), true)) {
            ollama_unload_model($name);
        }
    }
}

/** Запоминает таймаут основной модели; после MAIN_MODEL_SLOW_TIMEOUTS таймаутов переключает анализ на быструю модель. */
function main_model_note_failure(Throwable $error) {
    static $timeouts = 0;
    $fast = prescreen_model();
    if ($fast === ANALYSIS_MODEL || analysis_model() !== ANALYSIS_MODEL || stripos($error->getMessage(), 'timed out') === false) return;
    if (++$timeouts < MAIN_MODEL_SLOW_TIMEOUTS) return;
    users_ensure_root();
    @touch(MAIN_MODEL_SLOW_FLAG);
    ollama_unload_model(ANALYSIS_MODEL);
    if (PHP_SAPI === 'cli') {
        echo "-> Модель " . ANALYSIS_MODEL . " не укладывается во время запроса ({$timeouts} таймаута подряд): на " . MAIN_MODEL_SLOW_HOURS
            . " ч полный анализ переходит на быструю модель {$fast}, а {$fast} целиком помещается в видеопамять.\n";
    }
}

/**
 * Умеет ли модель размышлять (думать перед ответом): модель из OLLAMA_THINKING_MODELS или та, у которой
 * /api/show называет способность thinking (так устроен qwen3-vl). Модели с размышлениями без явного think=false
 * тратят короткий лимит токенов на размышления и отвечают пустотой — поэтому им всегда нужно слать think.
 */
function ollama_model_can_think($model) {
    static $cache = [];
    if (isset($cache[$model])) return $cache[$model];
    $can = in_array($model, OLLAMA_THINKING_MODELS, true);
    if (!$can) {
        $show = http_post(OLLAMA_URL . '/api/show', json_encode(['model' => $model, 'name' => $model]), [
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5
        ]);
        $info = $show['body'] ? json_decode($show['body'], true) : null;
        $can = is_array($info) && in_array('thinking', $info['capabilities'] ?? [], true);
    }
    return $cache[$model] = $can;
}

/**
 * Общие параметры каждого запроса: один num_ctx (иначе Ollama перезагружает модель при каждой смене)
 * и keep_alive, чтобы модель не выгружалась между запросами парсера. Параметр think отправляется только
 * моделям, умеющим размышлять (ollama_model_can_think), — остальные на него отвечают ошибкой.
 */
function ollama_common_payload(array $payload) {
    $payload['options'] = ($payload['options'] ?? []) + ['num_ctx' => OLLAMA_NUM_CTX];
    if (OLLAMA_NUM_GPU !== null && in_array($payload['model'] ?? '', PRESCREEN_MODELS, true) && !is_file(NUM_GPU_FAILED_FLAG)) $payload['options']['num_gpu'] = OLLAMA_NUM_GPU;
    $payload['keep_alive'] = $payload['keep_alive'] ?? OLLAMA_KEEP_ALIVE;
    if (!ollama_model_can_think($payload['model'] ?? '')) unset($payload['think']);
    elseif (!isset($payload['think'])) $payload['think'] = false;
    return $payload;
}

/**
 * Один потоковый запрос к Ollama (stream=true): ответ приходит строками JSON.
 * Размышления передаются в $on_thinking по мере генерации. Они бывают в двух видах:
 *  - отдельным полем thinking (если Ollama сама разбирает размышления модели);
 *  - прямо в тексте ответа до тега </think>: шаблон некоторых моделей (например, Qwen 3.5) сам открывает
 *    <think>, а Ollama его не разбирает. Тогда текст до </think> — размышления, после — ответ.
 * Возвращает ['status', 'error', 'api_error', 'response', 'thinking', 'logprobs'] (logprobs — по токенам ответа,
 * если в запросе было 'logprobs' => true).
 */
function ollama_stream(array $payload, $timeout, callable $on_thinking) {
    $original_payload = $payload;
    ollama_prepare_model($payload['model'] ?? '');
    $payload = ollama_common_payload($payload);
    $payload['stream'] = true;
    $inline_thinking = !empty($payload['think']);
    $result = ['status' => 0, 'error' => '', 'response' => '', 'thinking' => '', 'api_error' => '', 'logprobs' => []];
    $raw_response = '';    // весь текст ответа, как его прислала модель
    $answer_started = !$inline_thinking;
    $pending = '';

    $handle_line = function ($line) use (&$result, &$raw_response, &$answer_started, $on_thinking) {
        $data = json_decode($line, true);
        if (!is_array($data)) return;
        if (!empty($data['error'])) $result['api_error'] = (string)$data['error'];
        if (!empty($data['logprobs']) && is_array($data['logprobs'])) array_push($result['logprobs'], ...array_values($data['logprobs']));
        if (!empty($data['done']) && isset($data['total_duration'])) {
            $result['timing'] = [ // секунды: загрузка модели, обработка промпта (картинка и текст), генерация ответа
                'load' => round(($data['load_duration'] ?? 0) / 1e9, 1),
                'prompt' => round(($data['prompt_eval_duration'] ?? 0) / 1e9, 1),
                'prompt_tokens' => (int)($data['prompt_eval_count'] ?? 0),
                'answer' => round(($data['eval_duration'] ?? 0) / 1e9, 1)
            ];
        }
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
        // Вкладка закрыта — обрываем запрос: Ollama при этом перестаёт генерировать
        CURLOPT_NOPROGRESS => false,
        CURLOPT_PROGRESSFUNCTION => function () {
            return parser_session_alive() ? 0 : 1;
        },
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

    // Принудительная загрузка всех слоёв на видеокарту (num_gpu) на слабой карте может обрушить runner Ollama
    // («unexpected EOF»): запоминаем это и повторяем запрос с автоматическим выбором, дальше num_gpu не используется.
    if (isset($payload['options']['num_gpu']) && preg_match('/unexpected EOF|runner|out of memory|CUDA|cudaMalloc/i', $result['api_error'] . ' ' . $result['error'])) {
        users_ensure_root();
        @touch(NUM_GPU_FAILED_FLAG);
        if (!is_file(NUM_GPU_FAILED_FLAG)) return $result; // флаг не записать — повтор зациклился бы
        if (PHP_SAPI === 'cli') echo "-> Модель упала при загрузке всех слоёв на видеокарту (num_gpu): дальше Ollama сама выбирает, сколько слоёв отдать видеокарте.\n";
        return ollama_stream($original_payload, $timeout, $on_thinking);
    }

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
    $wants_thinking = $think && ollama_model_can_think($model);
    if ($wants_thinking) {
        $payload['think'] = true;
        unset($payload['format']); // format=json отключает размышления; JSON разбирается из текста ответа
    } elseif (ollama_model_can_think($model)) {
        $payload['think'] = false;
    }
    $timeout = $wants_thinking ? OLLAMA_THINKING_TIMEOUT : OLLAMA_TIMEOUT;

    $mode = $wants_thinking ? '' : (ollama_model_can_think($model) ? '[без размышлений] ' : '');
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
