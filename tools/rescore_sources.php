<?php
// Переоценка найденных автоподбором Telegram-каналов по текущим правилам (lib/source_quality.php и evaluate_source):
// 6 картинок, подписчики, доля просмотров, мёртвые каналы. По умолчанию — только предложения («ждут вашего решения»);
// с --catalog — ещё и автодобавленные в каталог каналы. Без --apply ничего не меняет и печатает таблицу.
// Нужна работающая модель быстрой оценки: на каждый канал уходит до ~30 секунд.
// php tools/rescore_sources.php [--catalog] [--apply]
if (PHP_SAPI !== 'cli') exit('Только из командной строки.');
require_once __DIR__ . '/../bootstrap.php';

$with_catalog = in_array('--catalog', $argv, true);
$apply = in_array('--apply', $argv, true);
$db = load_source_db();

$targets = [];
foreach ($db['source_suggestions'] as $suggestion) {
    if (($suggestion['platform'] ?? '') === PLATFORM_TELEGRAM) $targets[] = ['kind' => 'предложение', 'id' => $suggestion['id'], 'topic' => $suggestion['topic'] ?? DEFAULT_TOPIC, 'name' => $suggestion['name']];
}
if ($with_catalog) {
    foreach ($db['telegram_channels'] as $entry) {
        if (!empty($entry['auto'])) $targets[] = ['kind' => 'каталог', 'id' => ltrim($entry['id'], '@'), 'topic' => ($entry['topics'][0] ?? DEFAULT_TOPIC), 'name' => $entry['name']];
    }
}
printf("Проверяю каналов: %d (%s)%s\n\n", count($targets), $with_catalog ? 'предложения и автодобавленные' : 'предложения', $apply ? ', результат будет применён' : ', без изменений');

$results = [];
foreach ($targets as $target) {
    $info = telegram_channel_info($target['id'], 14);
    if ($info === null) { $results[] = $target + ['verdict' => 'убрать', 'why' => 'канала нет или он закрыт', 'score' => null]; printf("%-9s %-24s канала нет или он закрыт\n", $target['kind'], mb_substr($target['id'], 0, 24)); continue; }
    $subscribers = $info['subscribers'];
    $view_rate = source_view_rate($subscribers, $info['post_views'] ?? []);
    $activity = source_activity($info['post_times'] ?? []);
    $dead = source_inactivity_reason($activity) ?? source_dead_audience_reason($subscribers, $view_rate);
    if ($dead !== null) { $results[] = $target + ['verdict' => 'убрать', 'why' => $dead, 'score' => null]; printf("%-9s %-24s мёртвый: %s\n", $target['kind'], mb_substr($target['id'], 0, 24), $dead); continue; }
    $posts = array_values(array_filter($info['posts'], fn($post) => !is_post_outdated($post['posted_at'] ?? 0)));
    $evaluation = $posts ? evaluate_source($info, $posts, $target['topic']) : null;
    if ($evaluation === null) { $results[] = $target + ['verdict' => 'убрать', 'why' => 'нет постов с картинками', 'score' => null]; printf("%-9s %-24s нет постов с картинками\n", $target['kind'], mb_substr($target['id'], 0, 24)); continue; }
    $quality = source_quality_score($evaluation['score'], $subscribers, $view_rate);
    $keep = $quality['score'] >= SOURCE_SUGGEST_SCORE;
    $results[] = $target + ['verdict' => $keep ? 'оставить' : 'убрать', 'why' => "по теме {$quality['content']}%", 'score' => $quality['score'], 'quality' => $quality + ['subscribers' => $subscribers, 'view_rate' => $view_rate]];
    printf("%-9s %-24s по теме %3d%% (картинки %s) %s → %3d%% — %s\n", $target['kind'], mb_substr($target['id'], 0, 24), $quality['content'], implode('/', $evaluation['images']),
        describe_source_audience($subscribers, $view_rate), $quality['score'], $keep ? 'оставить' : 'убрать');
}
$remove = array_filter($results, fn($r) => $r['verdict'] === 'убрать');
printf("\nИтого: оставить %d, убрать %d.\n", count($results) - count($remove), count($remove));
if (!$apply) exit("Чтобы применить: php tools/rescore_sources.php" . ($with_catalog ? ' --catalog' : '') . " --apply\n");

$drop_suggestions = array_map(fn($r) => strtolower($r['id']), array_filter($remove, fn($r) => $r['kind'] === 'предложение'));
$drop_catalog = array_map(fn($r) => strtolower($r['id']), array_filter($remove, fn($r) => $r['kind'] === 'каталог'));
$kept = [];
foreach ($results as $r) if ($r['verdict'] === 'оставить') $kept[strtolower($r['id'])] = $r;
update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($drop_suggestions, $drop_catalog, $kept) {
    $db = normalize_source_db($db);
    $db['source_suggestions'] = array_values(array_filter($db['source_suggestions'], fn($s) => !in_array(strtolower($s['id'] ?? ''), $drop_suggestions, true)));
    foreach ($db['source_suggestions'] as &$suggestion) {
        $new = $kept[strtolower($suggestion['id'] ?? '')] ?? null;
        if ($new && isset($new['quality'])) $suggestion['score'] = $new['score'];   // оценка по новым правилам
    }
    unset($suggestion);
    $db['telegram_channels'] = array_values(array_filter($db['telegram_channels'], fn($e) => !(!empty($e['auto']) && in_array(strtolower(ltrim($e['id'], '@')), $drop_catalog, true))));
    foreach ($db['telegram_channels'] as &$entry) {
        $new = $kept[strtolower(ltrim($entry['id'], '@'))] ?? null;
        if ($new && !empty($entry['auto']) && isset($new['quality'])) {
            $entry['score'] = $new['score'];
            $entry['audience'] = ['subscribers' => $new['quality']['subscribers'], 'view_rate' => $new['quality']['view_rate'], 'checked_at' => time()];
        }
    }
    unset($entry);
    return $db;
});
echo "Применено.\n";
