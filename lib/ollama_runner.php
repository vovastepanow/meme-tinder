<?php
// Присмотр за процессом модели Ollama (llama-server). За несколько часов работы с картинками он разрастается до 10 ГБ памяти
// (при модели в 2 ГБ), память и swap кончаются, и компьютер зависает. Проект сам нагружает модель, поэтому сам и перезапускает её
// процесс: когда он разросся, модель выгружается между запросами — Ollama поднимет свежий процесс при следующем запросе.
// Права на сервис Ollama не нужны: выгрузка идёт обычным запросом keep_alive = 0.

const OLLAMA_RUNNER_MAX_RSS = 4 * 1024 * 1024 * 1024;     // процесс модели больше этого — пора перезапускать
const OLLAMA_RUNNER_MAX_AGE = 3 * 3600;                   // и процесс старше этого перезапускается в любом случае
const OLLAMA_RUNNER_LOW_MEMORY = 1536 * 1024 * 1024;      // доступной памяти меньше этого и процесс уже больше 2 ГБ — тоже
const OLLAMA_RUNNER_PERIOD = 600;                         // модель перезапускается не реже раза в столько секунд, даже если процесс не виден (ниже)
const OLLAMA_RUNNER_FALLBACK_LOW_MEMORY = 3.5 * 1024 * 1024 * 1024;   // не виден процесс, а доступной памяти в системе меньше этого — перезапуск
const OLLAMA_RUNNER_CHECK_EVERY = 30;                     // не чаще раза в столько секунд (проверка читает /proc)

/** Процессы модели Ollama: [['pid', 'rss' (байты), 'age' (секунды)]]. Читается /proc, права не нужны. */
function ollama_runner_processes() {
    if (getenv('POSTMAKER_HIDE_PROCESSES')) return [];   // проверка запасной ветки: так выглядит запуск из Apache (ProtectProc=invisible)
    $uptime = (float)explode(' ', (string)@file_get_contents('/proc/uptime'))[0];
    $processes = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
        $command = (string)@file_get_contents($file);
        $arguments = explode("\0", $command);   // процесс модели — это сам llama-server (или «ollama runner»), а не любая программа с таким словом в аргументах
        if (basename($arguments[0]) !== 'llama-server' && !(basename($arguments[0]) === 'ollama' && ($arguments[1] ?? '') === 'runner')) continue;
        $pid = (int)substr($file, 6, -8);
        $rss = preg_match('/VmRSS:\s+(\d+) kB/', (string)@file_get_contents("/proc/{$pid}/status"), $match) ? (int)$match[1] * 1024 : 0;
        $stat = (string)@file_get_contents("/proc/{$pid}/stat");
        $fields = $stat !== '' ? explode(' ', substr($stat, (int)strrpos($stat, ')') + 2)) : [];
        $age = isset($fields[19]) ? max(0, (int)round($uptime - (float)$fields[19] / 100)) : 0;   // starttime — 22-е поле stat, в тиках по 1/100 с
        $processes[] = ['pid' => $pid, 'rss' => $rss, 'age' => $age];
    }
    return $processes;
}

function ollama_available_memory() {
    return preg_match('/MemAvailable:\s+(\d+) kB/', (string)@file_get_contents('/proc/meminfo'), $match) ? (int)$match[1] * 1024 : PHP_INT_MAX;
}

/** Размер самой большой загруженной модели (байты, по /api/ps) или 0. */
function ollama_loaded_model_size() {
    $sizes = array_map(fn($model) => (int)($model['size'] ?? 0), http_get_json(OLLAMA_URL . '/api/ps', [CURLOPT_TIMEOUT => 3])['models'] ?? []);
    return $sizes ? max($sizes) : 0;
}

/**
 * Почему процесс пора перезапустить (текст) или '' — всё в порядке. Пороги считаются от размера загруженной модели:
 * 6 ГБ у 9B-модели — её обычный размер, а не утечка (иначе умную модель перезапускали бы перед каждым запросом).
 */
function ollama_runner_problem(array $process, ?int $model_size = null) {
    $gb = fn($bytes) => sprintf('%.1f ГБ', $bytes / 1073741824);
    $model_size = $model_size ?? ollama_loaded_model_size();
    $max_rss = max(OLLAMA_RUNNER_MAX_RSS, $model_size + 2 * 1073741824);   // разросся на 2 ГБ сверх самой модели
    $grown = max(2 * 1073741824, $model_size + 1073741824);
    if ($process['rss'] > $max_rss) return 'процесс модели занимает ' . $gb($process['rss']) . ' памяти';
    if ($process['age'] > OLLAMA_RUNNER_MAX_AGE && $process['rss'] > $grown) return 'процесс модели работает ' . round($process['age'] / 3600, 1) . ' ч и занимает ' . $gb($process['rss']);
    if ($process['rss'] > $grown && ollama_available_memory() < OLLAMA_RUNNER_LOW_MEMORY) return 'в системе мало памяти, а процесс модели занимает ' . $gb($process['rss']);
    return '';
}

/**
 * Если процесс модели разросся — выгружает модели проекта (Ollama поднимет новый процесс при следующем запросе).
 * Вызывается перед каждым запросом к модели (ollama_prepare_model). Возвращает true, если выгрузка была.
 */
function ollama_recycle_runner_if_needed() {
    static $last_check = 0;
    if (time() - $last_check < OLLAMA_RUNNER_CHECK_EVERY) return false;
    $last_check = time();
    $reason = '';
    $processes = ollama_runner_processes();
    $model_size = $processes ? ollama_loaded_model_size() : 0;
    foreach ($processes as $process) {
        $reason = ollama_runner_problem($process, $model_size);
        if ($reason !== '') break;
    }
    if (!$processes) {
        // Парсер запущен из Apache, а у него ProtectProc=invisible: процессы Ollama (другой пользователь) ему не видны, размер процесса
        // не узнать. Тогда ориентируемся на то, что видно всем: доступную память системы и время с прошлого перезапуска модели.
        $stamp = sys_get_temp_dir() . '/postmaker-ollama-recycled';
        if (!is_file($stamp)) @touch($stamp);
        $loaded = http_get_json(OLLAMA_URL . '/api/ps', [CURLOPT_TIMEOUT => 3])['models'] ?? [];
        if ($loaded && ollama_available_memory() < OLLAMA_RUNNER_FALLBACK_LOW_MEMORY) $reason = 'в системе мало свободной памяти (' . sprintf('%.1f ГБ', ollama_available_memory() / 1073741824) . ')';
        elseif ($loaded && time() - (int)@filemtime($stamp) > OLLAMA_RUNNER_PERIOD) $reason = 'плановый перезапуск: процесс модели растёт со временем';
    }
    if ($reason === '') return false;
    $lock = fopen(sys_get_temp_dir() . '/postmaker-ollama-recycle.lock', 'c');   // два парсера не перезапускают модель одновременно
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return false;
    $models = array_column(http_get_json(OLLAMA_URL . '/api/ps', [CURLOPT_TIMEOUT => 3])['models'] ?? [], 'name');
    if (PHP_SAPI === 'cli') echo "Память: {$reason} — перезапускаю модель, чтобы компьютер не завис (утечка памяти в Ollama).\n";
    foreach ($models as $name) ollama_unload_model($name);   // ждёт, пока модель исчезнет из /api/ps
    for ($i = 0; $i < 100 && ollama_runner_processes() && array_filter(ollama_runner_processes(), fn($process) => $process['rss'] > 1073741824); $i++) usleep(300000);   // видимый процесс — ждём его завершения
    @touch(sys_get_temp_dir() . '/postmaker-ollama-recycled');
    $left = array_filter(ollama_runner_processes(), fn($process) => ollama_runner_problem($process) !== '');
    if ($left && PHP_SAPI === 'cli') echo "-> Процесс модели не завершился сам. Выполните в терминале: sudo systemctl restart ollama\n";
    flock($lock, LOCK_UN);
    fclose($lock);
    return !$left;
}
