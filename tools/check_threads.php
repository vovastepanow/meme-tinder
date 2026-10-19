<?php
// Проверка живости тредов Двача у записей пула каждого пользователя; записи удалённых тредов убираются.
// php tools/check_threads.php [--dry-run]
if (PHP_SAPI !== 'cli') exit('Только из командной строки.');
require_once __DIR__ . '/../bootstrap.php';
$dry = in_array('--dry-run', $argv, true);
foreach (user_ids() as $uid) {
    $r = prune_dead_pool_items($uid, $dry, true, false);
    printf("%s: проверено тредов %d, удалённых %d, записей %s: %d\n", substr($uid, 0, 8), $r['threads'], $r['gone'], $dry ? 'к удалению' : 'убрано', $r['removed']);
}
