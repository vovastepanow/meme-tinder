<?php

// ---- VK ID (вход для подбора пабликов) ----
// Заполните на сервере. Остальные переменные окружения не нужны.
// VK_CLIENT_ID — ID приложения из кабинета VK ID.
// VK_REDIRECT_URI — ровно такой же адрес, как в настройках приложения VK ID, например:
//   https://ВАШ_ДОМЕН/index.php?action=vk_oauth_callback
const VK_CLIENT_ID = '';
const VK_REDIRECT_URI = '';
const VK_SERVICE_TOKEN = '';   // необязательно
const VK_OAUTH_SCOPE = '';     // необязательно: права, которые просит приложение

// Значения выше попадают в окружение процесса, чтобы vk_oauth_config() и фоновый парсер видели их.
foreach (['VK_CLIENT_ID' => VK_CLIENT_ID, 'VK_REDIRECT_URI' => VK_REDIRECT_URI, 'VK_SERVICE_TOKEN' => VK_SERVICE_TOKEN, 'VK_OAUTH_SCOPE' => VK_OAUTH_SCOPE] as $name => $value) {
    if (getenv($name) === false || getenv($name) === '') putenv($name . '=' . $value);
}

const POOL_FILE = __DIR__ . '/memes_pool.json';
const HISTORY_FILE = __DIR__ . '/meme_history.json';
const SOURCES_FILE = __DIR__ . '/sources_db.json';
const PARSER_SCRIPT = __DIR__ . '/cron_parser.php';

const OLLAMA_GENERATE_URL = 'http://localhost:11434/api/generate';
const TEXT_MODEL = 'qwen3.5-vision:latest';
const VISION_MODEL = 'qwen3.5-vision:latest';

// Модели, у которых включаем отдельный вывод размышлений (поле "thinking" в ответе Ollama).
// Для моделей без поддержки thinking оставьте список пустым или не добавляйте их.
const OLLAMA_THINKING_MODELS = [VISION_MODEL];

// Парсер: сколько мемов держать в пуле и сколько работы делать за один запуск.
const MEME_POOL_TARGET = 40;        // пул перестаёт пополняться, когда в нём столько мемов
const MEME_ADDED_PER_RUN = 30;      // максимум новых мемов за один запуск
const MEME_ANALYZE_PER_RUN = 80;    // максимум кандидатов, которые уйдут в модель за один запуск
const PARSER_MAX_SECONDS = 2400;    // жёсткий лимит времени одного запуска (40 минут)

// Источники: сколько постов брать с каждой площадки.
const DVACH_THREADS_PER_BOARD = 8;
const DVACH_POSTS_PER_THREAD = 60;
const TELEGRAM_POSTS_PER_CHANNEL = 40;
const VK_POSTS_PER_PUBLIC = 60;

// Видео: максимальный размер файла и число кадров, которые уходят в модель.
const VIDEO_MAX_BYTES = 30 * 1024 * 1024;
const VIDEO_FRAMES = 3;

const HISTORY_COLLECTIONS = ['liked_memes', 'disliked_memes', 'undecided_memes', 'wrong_topic_memes'];
const COMPLAINT_REASONS = ['not_meme', 'broken', 'other'];
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

function empty_history() {
    return array_fill_keys(HISTORY_COLLECTIONS, []);
}

function parser_runtime_file($suffix) {
    return __DIR__ . '/.meme-tinder-' . substr(hash('sha256', __DIR__), 0, 16) . $suffix;
}
