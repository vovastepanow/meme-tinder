<?php
// Дособирает снимок активности (lib/engagement.php) для записей, которые уже лежат в пулах пользователей и были найдены
// до включения сбора, чтобы на карточке сразу было что показать. Нужны сеть и работающие t.me и 2ch.hk.
// php tools/backfill_engagement.php
if (PHP_SAPI !== 'cli') exit('Только из командной строки.');
require_once __DIR__ . '/../bootstrap.php';

$cache = [];
foreach (user_ids() as $uid) {
    $pool = load_data(pool_file($uid), []);
    $snapshots = [];
    foreach ($pool as $item) {
        if (!empty($item['engagement'])) continue;
        $snapshot = engagement_backfill_item($item, $cache);
        if ($snapshot !== null) $snapshots[content_image_key($item)] = $snapshot;
    }
    // Запись в пул — коротко и под блокировкой: пока шли запросы, парсер мог добавить записи
    $applied = 0;
    update_data(pool_file($uid), [], function ($pool) use ($snapshots, &$applied) {
        foreach ($pool as &$item) {
            $key = content_image_key($item);
            if (empty($item['engagement']) && isset($snapshots[$key])) { $item['engagement'] = $snapshots[$key]; $applied++; }
        }
        return $applied ? $pool : null;
    });
    printf("%s: в пуле %d, дособрано %d\n", substr($uid, 0, 8), count($pool), $applied);
}
