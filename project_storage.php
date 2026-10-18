<?php
// Хранилище JSON-файлов: чтение, атомарная запись и изменение под блокировкой.

function load_data($file, $default = []) {
    if (!is_file($file)) return $default;
    $json = file_get_contents($file);
    if ($json === false) return $default;

    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

/** Пишет JSON во временный файл и переименовывает его, чтобы читатели никогда не видели половину файла. */
function save_data($file, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Не удалось сериализовать данные в JSON.');

    $temporary_file = tempnam(dirname($file), '.content-data-');
    if ($temporary_file === false) throw new RuntimeException('Не удалось создать временный файл базы.');

    $written = file_put_contents($temporary_file, $json, LOCK_EX);
    $permissions = is_file($file) ? fileperms($file) : false;
    @chmod($temporary_file, $permissions !== false ? ($permissions & 0777) : 0664);
    if ($written !== strlen($json) || !rename($temporary_file, $file)) {
        @unlink($temporary_file);
        throw new RuntimeException('Не удалось сохранить JSON-базу.');
    }
}

/** Выполняет $callback под эксклюзивной блокировкой файла (<file>.lock). */
function with_file_lock($file, callable $callback) {
    $lock = @fopen($file . '.lock', 'c+');
    if ($lock && !flock($lock, LOCK_EX)) {
        fclose($lock);
        $lock = false;
    }
    try {
        return $callback();
    } finally {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}

/**
 * Читает файл, передаёт данные в $mutator и сохраняет результат — всё под одной блокировкой,
 * чтобы веб-запросы и фоновый парсер не затирали изменения друг друга.
 * $mutator возвращает новые данные; если он вернул null, файл не перезаписывается.
 * Возвращает данные после изменения.
 */
function update_data($file, $default, callable $mutator) {
    return with_file_lock($file, function () use ($file, $default, $mutator) {
        $data = load_data($file, $default);
        $updated = $mutator($data);
        if ($updated === null) return $data;
        save_data($file, $updated);
        return $updated;
    });
}
