<?php
// cron_parser.php — фоновый сборщик мемов. Запускается из index.php (nohup) и пишет журнал в консоль сайта.
// Собирает посты с картинками и видео из всех источников, каждого кандидата отправляет модели один раз
// (видео оценивается по нескольким кадрам) и пополняет пул. Логика — в parser/collector.php.

// Сборщик работает десятки минут и тратит ресурсы модели: запускать его можно только из командной строки.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cron_parser.php запускается только из командной строки.');
}

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/parser/dvach_media.php';
require_once __DIR__ . '/parser/telegram_media.php';
require_once __DIR__ . '/parser/video.php';
require_once __DIR__ . '/parser/candidates.php';
require_once __DIR__ . '/parser/collector.php';

set_time_limit(0);
run_collector();
