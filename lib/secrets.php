<?php
// Шифрование секретов на диске (токены ботов). Ключ лежит в secret.key.php — PHP-файле, который при обращении по HTTP
// выполняется и ничего не показывает, поэтому даже если Apache не читает .htaccess и отдаёт .json-файлы, токен из
// users/<id>/connections.json в открытом виде не достать. Ключ создаётся при первом использовании.
// Не коммитьте secret.key.php и не копируйте его вместе с данными: без него зашифрованное не расшифровать.

function secret_key_file() { return __DIR__ . '/../secret.key.php'; }

/** 32-байтовый ключ; при первом обращении создаётся. */
function secret_key() {
    static $key = null;
    if ($key !== null) return $key;
    if (!function_exists('sodium_crypto_secretbox')) throw new RuntimeException('На сервере нет расширения PHP sodium: нельзя безопасно хранить токены.');
    $file = secret_key_file();
    if (!is_file($file)) {
        $encoded = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $contents = "<?php\n// Ключ шифрования токенов Postmaker (создан автоматически). Не публиковать и не коммитить.\nreturn '{$encoded}';\n";
        if (@file_put_contents($file, $contents, LOCK_EX) === false) throw new RuntimeException('Не удалось создать ключ шифрования (secret.key.php): проверьте права на папку сайта.');
        @chmod($file, 0640);
    }
    $decoded = base64_decode((string)(include $file), true);
    if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) throw new RuntimeException('Ключ шифрования (secret.key.php) повреждён.');
    return $key = $decoded;
}

/** Строка → зашифрованная строка (base64: nonce + шифртекст). */
function secret_encrypt($plain) {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce . sodium_crypto_secretbox((string)$plain, $nonce, secret_key()));
}

/** Обратное преобразование; null, если данные повреждены или зашифрованы другим ключом. */
function secret_decrypt($encrypted) {
    $raw = base64_decode((string)$encrypted, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), secret_key());
    return $plain === false ? null : $plain;
}
