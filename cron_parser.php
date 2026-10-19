<?php
// cron_parser.php — фоновый сборщик контента по теме из settings.json. Запускается из index.php (nohup) и пишет журнал в консоль сайта.
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
// Аргумент — идентификатор вкладки, к которой привязан запуск: пока вкладка не закрыта, парсер работает.
parser_bind_tab($argv[1] ?? '');
// Второй аргумент — пользователь, для которого идёт сбор (его пул, история и тема).
app_user_bind($argv[2] ?? '');
if (app_user_bind() === '') exit("Укажите пользователя: php cron_parser.php <вкладка> <id пользователя>\n");
// Одновременно работает не больше MAX_PARALLEL_PARSERS сборщиков; остальные ждут своей очереди.
parser_wait_for_slot();
// Временные файлы прерванных запусков (видео и кадры, остановленные сигналом) старше часа удаляем.
foreach (glob(sys_get_temp_dir() . '/ct-*') ?: [] as $leftover) {
    if (is_file($leftover) && time() - (int)@filemtime($leftover) > 3600) @unlink($leftover);
}
// Если тему сменили посреди сбора, начинаем заново с новой темой (не больше трёх раз за запуск).
for ($pass = 0; $pass < 3 && run_collector(); $pass++);
