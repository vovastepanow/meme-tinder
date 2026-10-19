<?php
// Отладочная панель модели: что загружено в Ollama и на видеокарте, загрузка, выгрузка и перезапуск моделей парсера.
// Перезапустить саму службу Ollama отсюда нельзя (нужен root): «Перезапустить» выгружает модели и загружает нужную заново.

const MODEL_ROLES = ['prescreen', 'analysis'];
const MODEL_LOAD_TIMEOUT = 300;   // загрузка большой модели с диска на процессор занимает до минуты, с запасом

/**
 * Модели парсера по ролям, без пробного запроса: prescreen_model() проверяет модель генерацией (и загружает её),
 * а панель не должна ничего грузить, пока её просто открыли.
 */
function model_panel_roles(array $installed) {
    $installed_set = array_flip($installed);
    $prescreen = ANALYSIS_MODEL;
    foreach (PRESCREEN_MODELS as $candidate) {
        if (isset($installed_set[$candidate]) || isset($installed_set[$candidate . ':latest'])) {
            $prescreen = $candidate;
            break;
        }
    }
    return ['prescreen' => $prescreen, 'analysis' => ANALYSIS_MODEL];
}

/** Загруженные модели из /api/ps: имя, размер, доля на видеокарте, контекст, когда выгрузится. */
function model_panel_loaded() {
    $models = [];
    foreach (http_get_json(OLLAMA_URL . '/api/ps', [CURLOPT_TIMEOUT => 5])['models'] ?? [] as $model) {
        $size = (int)($model['size'] ?? 0);
        $vram = (int)($model['size_vram'] ?? 0);
        $models[] = [
            'name' => (string)($model['name'] ?? ''),
            'size_mb' => (int)round($size / 1048576),
            'vram_mb' => (int)round($vram / 1048576),
            'gpu_percent' => $size > 0 ? (int)round(100 * $vram / $size) : 0,
            'context' => (int)($model['context_length'] ?? 0),
            'expires_at' => (string)($model['expires_at'] ?? '')
        ];
    }
    return $models;
}

/**
 * Видеопамять по nvidia-smi: ['used_mb', 'total_mb', 'util'] или null. Из-под Apache обычно null: служба apache2 запущена
 * с PrivateDevices=yes и видеокарты не видит. Тогда панель показывает, сколько видеопамяти заняли модели (по Ollama).
 */
function model_panel_gpu() {
    if (!function_exists('shell_exec')) return null;
    $line = trim((string)@shell_exec('nvidia-smi --query-gpu=memory.used,memory.total,utilization.gpu --format=csv,noheader,nounits 2>/dev/null'));
    if (!preg_match('/^(\d+),\s*(\d+),\s*(\d+)/', $line, $match)) return null;
    return ['used_mb' => (int)$match[1], 'total_mb' => (int)$match[2], 'util' => (int)$match[3]];
}

function handle_model_status($debug_mode) {
    $version = http_get_json(OLLAMA_URL . '/api/version', [CURLOPT_TIMEOUT => 3])['version'] ?? null;
    $installed = $version !== null ? ollama_installed_models() : [];
    json_response([
        'ollama' => $version !== null,
        'version' => $version,
        'loaded' => $version !== null ? model_panel_loaded() : [],
        'roles' => model_panel_roles($installed),
        'gpu' => model_panel_gpu(),
        'parser_running' => !$debug_mode && content_parser_is_running()
    ]);
}

/** Загружает модель с теми же параметрами, что у парсера (иначе первый же запрос парсера перезагрузит её). */
function model_panel_load($model) {
    foreach (model_panel_loaded() as $loaded) {
        if ($loaded['name'] !== $model) ollama_unload_model($loaded['name']); // на 3 ГБ две модели вместе не помещаются
    }
    // Запрос без prompt Ollama понимает как «только загрузить»
    $payload = ollama_common_payload(['model' => $model]);
    unset($payload['think']);
    $response = http_post(OLLAMA_GENERATE_URL, json_encode($payload + ['stream' => false]), [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => MODEL_LOAD_TIMEOUT
    ]);
    $data = json_decode((string)$response['body'], true);
    if ($response['status'] !== 200) {
        throw new RuntimeException('Ollama не загрузила ' . $model . ': ' . ($data['error'] ?? ($response['error'] ?: 'HTTP ' . $response['status'])));
    }
}

/** POST {op: load|unload|unload_all|restart, role?: prescreen|analysis, model?: имя загруженной модели}. */
function handle_model_control($input, $debug_mode) {
    if ($debug_mode) json_error('В демо-режиме модель не управляется.', 403);
    $op = (string)($input['op'] ?? '');
    $roles = model_panel_roles(ollama_installed_models());
    $loaded = array_column(model_panel_loaded(), 'name');
    $started = microtime(true);

    if ($op === 'load') {
        $role = (string)($input['role'] ?? '');
        if (!in_array($role, MODEL_ROLES, true)) json_error('Неизвестная роль модели.');
        model_panel_load($roles[$role]);
        $message = "Загружена {$roles[$role]}";
    } elseif ($op === 'unload') {
        $model = (string)($input['model'] ?? '');
        if (!in_array($model, $loaded, true)) json_error('Эта модель сейчас не загружена.');
        ollama_unload_model($model);
        $message = "Выгружена {$model}";
    } elseif ($op === 'unload_all') {
        foreach ($loaded as $model) ollama_unload_model($model);
        $message = $loaded ? 'Выгружены: ' . implode(', ', $loaded) : 'Загруженных моделей не было';
    } elseif ($op === 'restart') {
        // Перезапуск runner: выгрузить всё и загрузить заново то, что было загружено (или быструю модель)
        $target = $loaded ? $loaded[0] : $roles['prescreen'];
        foreach ($loaded as $model) ollama_unload_model($model);
        model_panel_load($target);
        $message = "Перезапущена {$target}";
    } else {
        json_error('Неизвестное действие.');
    }
    json_response(['status' => 'success', 'message' => $message . sprintf(' за %.1f с.', microtime(true) - $started)]);
}
