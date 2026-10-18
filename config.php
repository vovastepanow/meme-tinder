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

// Быстрая предварительная оценка: перед долгим анализом (с размышлениями и раскадровкой) каждый кандидат
// оценивается по уменьшенной картинке (у видео — по обложке) без размышлений, ответом из одного числа.
// На полный анализ уходят только кандидаты с оценкой не ниже PRESCREEN_THRESHOLD, лучшие — первыми.
// Размер: модель режет картинку на квадраты 28×28 px (один квадрат — один токен). 384 px ≈ 190 токенов —
// модель уверенно видит сцену и читает крупный текст мема; 256 px — только сцена и крупные объекты.
const PRESCREEN_ENABLED = true;
const PRESCREEN_MAX_SIDE = 384;
const PRESCREEN_THRESHOLD = 40;   // мягкий порог: лучше лишний раз проанализировать, чем потерять годное
const PRESCREEN_BATCH = 10;       // кандидатов в пачке: быстрая оценка пачки → полный анализ лучших из неё

// ---- Автоподбор источников по теме ----
// Нейросеть придумывает поисковые запросы (для VK, нужен подключённый аккаунт) и вероятные адреса Telegram-каналов,
// а также ищутся каналы, на которые ссылаются уже известные. Каждый найденный источник проверяется: есть ли
// посты с картинками, и насколько они подходят под тему (быстрая оценка 3 картинок + название и описание).
const SOURCE_DISCOVERY_ENABLED = true;
const SOURCE_DISCOVERY_INTERVAL = 12 * 3600;  // повторный автоподбор по той же теме не чаще, чем раз в 12 часов
const SOURCE_DISCOVERY_MAX_SECONDS = 600;     // лимит времени одного подбора (10 минут), потом — сбор контента
const SOURCE_DISCOVERY_MAX_CHECKS = 30;       // сколько найденных источников проверить за один подбор
const SOURCE_AUTO_ADD_SCORE = 65;             // с такой оценкой источник сразу попадает в каталог и используется
const SOURCE_SUGGEST_SCORE = 40;              // с такой — попадает в предложения на вкладке «Источники»
const SOURCE_SAMPLE_IMAGES = 3;               // сколько картинок источника оценивать

// ---- Свежесть контента и активность источников ----
// Посты старше CONTENT_MAX_AGE_DAYS в кандидаты не попадают.
// Канал или паблик замораживается, если последний пост старше SOURCE_STALE_DAYS или он публикует реже
// SOURCE_MIN_POSTS_PER_WEEK раз в неделю. Замороженный источник парсер пропускает и раз в
// SOURCE_FROZEN_RECHECK_DAYS дней проверяет снова: если источник ожил, он размораживается.
const CONTENT_MAX_AGE_DAYS = 30;
const SOURCE_STALE_DAYS = 14;
const SOURCE_MIN_POSTS_PER_WEEK = 2;
const SOURCE_FROZEN_RECHECK_DAYS = 3;

// Источники: сколько постов брать с каждой площадки.
const DVACH_THREADS_PER_BOARD = 8;
const DVACH_POSTS_PER_THREAD = 60;
const TELEGRAM_POSTS_PER_CHANNEL = 40;
const VK_POSTS_PER_PUBLIC = 60;

// Видео: Ollama принимает только картинки, поэтому ролик уходит в модель раскадровкой с метками времени.
// Кадров — примерно один в VIDEO_SECONDS_PER_FRAME секунд, но не меньше MIN и не больше MAX.
// Кадры уменьшаются до VIDEO_FRAME_MAX_SIDE по длинной стороне, чтобы 16 кадров вместе с промптом
// и размышлениями укладывались в контекст модели (num_ctx 8192). Нужен ffmpeg.
const VIDEO_MAX_BYTES = 30 * 1024 * 1024;
const VIDEO_FRAMES_MIN = 8;
const VIDEO_FRAMES_MAX = 16;
const VIDEO_SECONDS_PER_FRAME = 1.0;
const VIDEO_FRAME_MAX_SIDE = 448;

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
