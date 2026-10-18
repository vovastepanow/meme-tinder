<?php

// Общие настройки приложения. Секреты и значения, специфичные для сервера, задаются
// в config.local.php (он не отслеживается git). Шаблон: config.local.example.php.
$local_config = is_file(__DIR__ . '/config.local.php') ? (require __DIR__ . '/config.local.php') : [];
$local_config = is_array($local_config) ? $local_config : [];

// ---- VK ID (вход для подбора пабликов) ----
// VK_CLIENT_ID — ID приложения из кабинета VK ID.
// VK_REDIRECT_URI — ровно такой же адрес, как в настройках приложения VK ID, например:
//   https://ВАШ_ДОМЕН/index.php?action=vk_oauth_callback
$vk_settings = [
    'VK_CLIENT_ID' => $local_config['VK_CLIENT_ID'] ?? '',
    'VK_REDIRECT_URI' => $local_config['VK_REDIRECT_URI'] ?? '',
    'VK_SERVICE_TOKEN' => $local_config['VK_SERVICE_TOKEN'] ?? '',
    'VK_OAUTH_SCOPE' => $local_config['VK_OAUTH_SCOPE'] ?? '',
];
// Значения попадают в окружение процесса, чтобы vk_oauth_config() и фоновый парсер видели их.
foreach ($vk_settings as $name => $value) {
    if (getenv($name) === false || getenv($name) === '') putenv($name . '=' . $value);
}

const APP_NAME = 'Content Tinder';

// ---- Файлы данных (создаются приложением, в git не попадают) ----
const POOL_FILE = __DIR__ . '/content_pool.json';         // отобранный контент, ждущий оценки
const HISTORY_FILE = __DIR__ . '/content_history.json';   // оценки пользователя
const SOURCES_FILE = __DIR__ . '/sources_db.json';        // каталог источников и решения по ним
const SETTINGS_FILE = __DIR__ . '/settings.json';         // тема поиска и прочие настройки
const PARSER_SCRIPT = __DIR__ . '/cron_parser.php';

// Тема поиска по умолчанию. Меняется в интерфейсе (вкладка «Оценка контента») и хранится в settings.json.
const DEFAULT_TOPIC = 'мемы и юмор';
const TOPIC_MAX_LENGTH = 200;

// Кэш медиа: картинки (и видео) отобранного контента сохраняются здесь в момент отбора, потому что ссылки
// источников (особенно CDN Telegram) со временем перестают работать. Файл удаляется, как только запись уходит из пула.
const MEDIA_CACHE_DIR = __DIR__ . '/media_cache';
const MEDIA_CACHE_URL = 'media_cache';
const MEDIA_CACHE_GRACE_SECONDS = 600; // свежие файлы не трогаем: парсер мог сохранить файл, но ещё не добавить запись в пул

// ---- Модель ----
const OLLAMA_GENERATE_URL = 'http://localhost:11434/api/generate';
const VISION_MODEL = 'qwen3.5-vision:latest';

// Модели, у которых включаем размышления. Ответ таких моделей идёт потоком, и размышления появляются в журнале
// сайта по мере генерации. Для них не передаётся format=json: ограничение формата заставляет модель сразу писать
// JSON и полностью отключает размышления (JSON разбирается из текста ответа после размышлений).
// Для моделей без поддержки thinking оставьте список пустым.
const OLLAMA_THINKING_MODELS = [VISION_MODEL];
// Лимит токенов на размышления и ответ. Если модель не уложилась, запись переоценивается без размышлений.
const OLLAMA_THINKING_MAX_TOKENS = 3000;

// ---- Парсер: сколько контента держать в пуле и сколько работы делать за один запуск ----
const CONTENT_POOL_TARGET = 40;        // пул перестаёт пополняться, когда в нём столько записей
const CONTENT_ADDED_PER_RUN = 30;      // максимум новых записей за один запуск
const CONTENT_ANALYZE_PER_RUN = 80;    // максимум кандидатов, которые уйдут в модель за один запуск
const PARSER_MAX_SECONDS = 2400;       // жёсткий лимит времени одного запуска (40 минут)
const PARSER_COOLDOWN_SECONDS = 600;   // автозапуск парсера не чаще одного раза за этот срок

// Отбор: запись попадает в пул, если модель оценила её соответствие теме не ниже этого порога (0–100).
const RELEVANCE_THRESHOLD = 55;

// Источники: сколько постов брать с каждой площадки.
const DVACH_THREADS_PER_BOARD = 8;
const DVACH_POSTS_PER_THREAD = 60;
const TELEGRAM_POSTS_PER_CHANNEL = 40;
const VK_POSTS_PER_PUBLIC = 60;

// Видео: максимальный размер файла и число кадров, которые уходят в модель.
const VIDEO_MAX_BYTES = 30 * 1024 * 1024;
const VIDEO_FRAMES = 3;

// История оценок: коллекции и причины жалоб.
const HISTORY_COLLECTIONS = ['liked', 'disliked', 'undecided', 'complaints'];
const COMPLAINT_REASONS = ['off_topic', 'broken', 'other'];
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

function empty_history() {
    return array_fill_keys(HISTORY_COLLECTIONS, []);
}

// PARSER_RUNTIME_VERSION входит в имя файлов лога и состояния. Меняйте его при смене пайплайна,
// чтобы старые логи не попадали в консоль после обновления.
const PARSER_RUNTIME_VERSION = 'v3';

function parser_runtime_file($suffix) {
    return __DIR__ . '/.content-tinder-' . PARSER_RUNTIME_VERSION . '-' . substr(hash('sha256', __DIR__), 0, 16) . $suffix;
}
