<?php
// Достраивает записи «Предложки», оценённые до появления вкладки: видео и остальные файлы поста (по ссылке на пост).
// php tools/backfill_proposals.php [id_пользователя] [--force]   (без id — все пользователи; --force — проверить треды сразу, не дожидаясь срока)
if (PHP_SAPI !== 'cli') exit('Только из командной строки.');
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../handlers/proposals.php';
$args = array_values(array_filter(array_slice($argv, 1), fn($a) => !str_starts_with($a, '--')));
$ids = $args ? [$args[0]] : user_ids();
foreach ($ids as $uid) {
    app_user_bind($uid);
    $total = 0;
    while (($n = proposal_backfill_media(10)) > 0) $total += $n;
    if ($total) echo substr($uid, 0, 8) . ": обновлено записей: $total\n";
    $dead = proposal_prune_dead_threads(in_array('--force', $argv, true));
    if ($dead) echo substr($uid, 0, 8) . ": скрыто записей из мёртвых тредов: $dead\n";
}
