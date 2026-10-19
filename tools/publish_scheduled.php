<?php
// Отправляет в Telegram отложенные посты, время которых пришло (очередь users/<id>/outbox.json, см. lib/telegram_publish.php).
// Запускается cron раз в минуту:
//   * * * * * /usr/bin/php /var/www/html/postmaker/tools/publish_scheduled.php >> /tmp/postmaker-publish.log 2>&1
// Молчит, пока отправлять нечего; об отправленных и неудавшихся постах пишет одной строкой.
if (PHP_SAPI !== 'cli') exit('Только из командной строки.');
require_once __DIR__ . '/../bootstrap.php';

// Не больше одного запуска одновременно: долгая отправка видео не должна перекрываться следующей минутой
$lock = fopen(sys_get_temp_dir() . '/postmaker-publish.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!is_dir(USERS_DIR)) exit(0);
foreach (user_ids() as $uid) {
    $queue = load_data(outbox_file($uid), []);
    if (!$queue) continue;
    $result = telegram_publish_due_for_user($uid);
    if ($result['sent'] || $result['failed']) {
        echo date('Y-m-d H:i:s') . ' ' . substr($uid, 0, 8) . ": отправлено {$result['sent']}, не удалось {$result['failed']}\n";
    }
}
