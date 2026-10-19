<?php
// Фоновый сборщик: php cron_parser.php <вкладка> <id пользователя>. Запускается из API через nohup, журнал — в консоль сайта.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('cron_parser.php запускается только из командной строки.'); }
require_once __DIR__ . '/bootstrap.php';
set_time_limit(0);
parser_bind_tab($argv[1] ?? '');   // пока вкладка жива, парсер работает
if (app_user_bind($argv[2] ?? '') === '') exit("Укажите пользователя: php cron_parser.php <вкладка> <id пользователя>\n");
parser_wait_for_slot();
foreach (glob(sys_get_temp_dir() . '/ct-*') ?: [] as $old) if (is_file($old) && time() - (int)@filemtime($old) > 3600) @unlink($old);   // остатки прерванных запусков
for ($pass = 0; $pass < 3 && run_collector(); $pass++);   // тему сменили посреди сбора — заново, не больше трёх раз
