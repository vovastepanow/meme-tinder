<?php
// Общая загрузка для веб-API (index.php) и фонового парсера (cron_parser.php).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/project_storage.php';
require_once __DIR__ . '/lib/users.php';
require_once __DIR__ . '/http_client.php';
require_once __DIR__ . '/image_input.php';
require_once __DIR__ . '/content_analysis.php';

require_once __DIR__ . '/sources/common.php';
require_once __DIR__ . '/sources/dvach.php';
require_once __DIR__ . '/sources/telegram.php';
require_once __DIR__ . '/sources/vk.php';

require_once __DIR__ . '/lib/catalog.php';
require_once __DIR__ . '/lib/topics.php';
require_once __DIR__ . '/lib/media_cache.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/engagement.php';
require_once __DIR__ . '/lib/secrets.php';
require_once __DIR__ . '/lib/telegram_publish.php';
require_once __DIR__ . '/lib/telegram_limits.php';
require_once __DIR__ . '/lib/telegram_user.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/source_activity.php';
require_once __DIR__ . '/lib/source_quality.php';
require_once __DIR__ . '/lib/source_liveness.php';
require_once __DIR__ . '/lib/gallery_cache.php';
require_once __DIR__ . '/lib/ollama_runner.php';
require_once __DIR__ . '/lib/source_feedback.php';
require_once __DIR__ . '/lib/dvach_tags.php';
require_once __DIR__ . '/lib/source_discovery.php';
require_once __DIR__ . '/lib/parser_runner.php';
require_once __DIR__ . '/lib/parser_slots.php';
require_once __DIR__ . '/lib/tab_session.php';
