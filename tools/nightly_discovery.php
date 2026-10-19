<?php
// tools/nightly_discovery.php — ночной подбор источников для самых популярных тем. Запускается по расписанию (cron):
//   0 4 * * * php /var/www/html/postmaker/tools/nightly_discovery.php >> /var/log/postmaker-discovery.log 2>&1
// Каталог источников общий, поэтому к приходу пользователя по популярной теме источники уже найдены и проверены.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Только из командной строки.'); }
require_once __DIR__ . '/../bootstrap.php';
set_time_limit(0);

$limit = max(1, (int)($argv[1] ?? 5));
$topics = popular_topics($limit);
echo date('c') . " Ночной подбор источников. Темы: " . ($topics ? implode(', ', $topics) : 'нет') . "\n";
topic_classify_pending(10);   // темы, заведённые без модели, получают вид и широкую тему
foreach ($topics as $topic) {
    topic_ensure($topic);
    topic_profile_ensure($topic);   // профиль темы — до подбора: от него план поиска
    if (!source_discovery_due(load_source_db(), $topic)) {
        echo "Тема «{$topic}»: подбор недавно уже был — пропускаю.\n";
        continue;
    }
    run_source_discovery($topic);
}
echo date('c') . " Готово.\n";
