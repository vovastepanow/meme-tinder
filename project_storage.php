<?php

function load_data($file, $default = []) {
    if (!is_file($file)) return $default;
    $json = file_get_contents($file);
    if ($json === false) return $default;

    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}

function save_data($file, $data) {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) throw new RuntimeException('Не удалось сериализовать данные в JSON.');

    $temporary_file = tempnam(dirname($file), '.meme-data-');
    if ($temporary_file === false) throw new RuntimeException('Не удалось создать временный файл базы.');

    $written = file_put_contents($temporary_file, $json, LOCK_EX);
    $permissions = is_file($file) ? fileperms($file) : false;
    $mode = $permissions !== false ? ($permissions & 0777) : 0664;
    @chmod($temporary_file, $mode);
    if ($written !== strlen($json) || !rename($temporary_file, $file)) {
        @unlink($temporary_file);
        throw new RuntimeException('Не удалось сохранить JSON-базу.');
    }
}