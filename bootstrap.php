<?php
// Общая загрузка для API (index.php), сборщика (cron_parser.php) и утилит (tools/cli.php).
require_once __DIR__ . '/config.php';
foreach (['core', 'ai', 'sources', 'topics', 'discovery', 'content', 'telegram', 'runtime', 'parser'] as $lib) require_once __DIR__ . "/lib/$lib.php";
