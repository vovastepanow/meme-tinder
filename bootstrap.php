<?php
// Общая загрузка для веб-API (index.php) и фонового парсера (cron_parser.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/image_input.php';
require_once __DIR__ . '/meme_analysis.php';

require_once __DIR__ . '/sources/common.php';
require_once __DIR__ . '/sources/dvach.php';
require_once __DIR__ . '/sources/telegram.php';
require_once __DIR__ . '/sources/vk.php';

require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/memes.php';
