<?php
// Каталог источников, стартовые файлы данных и загрузка истории.
//
// Каналы Telegram и паблики VK в каталоге могут быть привязаны к темам (поле topics): парсер берёт из них
// контент, только когда текущая тема поиска есть в списке. Источник без topics используется для любой темы
// (так работают добавленные вручную). Найденные автоподбором источники привязываются к теме, по которой найдены.

/** Стандартный каталог. Мемные каналы привязаны к теме по умолчанию. */
function create_default_source_catalog() {
    $tag = function (array $entries) {
        return array_map(function ($entry) {
            return $entry + ['topics' => [DEFAULT_TOPIC]];
        }, $entries);
    };
    return [
        '2ch_boards' => ['b', 'me', 'pr', 'r', 'cc', 'a', 'mu', 'd', 's', 'b2'],
        'vk_publics' => $tag([
            ['id' => 'mrzlk', 'name' => 'MDK'],
            ['id' => 'leprum', 'name' => 'Лепрекон'],
            ['id' => 'sci_one', 'name' => 'Умный Юмор'],
            ['id' => 'british_humor', 'name' => 'БРИТИШ ЮМОР'],
            ['id' => 'bessm_humor', 'name' => 'ОВСЯНКА, СЭР!'],
            ['id' => 'memes_2024', 'name' => 'Мемы на каждый день']
        ]),
        'telegram_channels' => $tag([
            ['id' => 'memesdaily', 'name' => 'Мемы каждый день'],
            ['id' => 'this_is_not_funny', 'name' => 'Это не смешно'],
            ['id' => 'humorhub', 'name' => 'Humor Hub'],
            ['id' => 'trashmemes', 'name' => 'Trash memes'],
            ['id' => 'russmemes', 'name' => 'Русские мемы'],
            ['id' => 'memechi', 'name' => 'Meme Chi']
        ]),
        'source_votes' => [],
        // Найденные автоподбором источники со средней оценкой — ждут решения пользователя.
        'source_suggestions' => [],
        // Когда и по какой теме последний раз подбирались источники; requested — подбор заказан кнопкой.
        'discovery' => ['topic' => '', 'last_run' => 0, 'requested' => false]
    ];
}

/** Подходит ли источник каталога для темы: без topics — для любой, иначе тема должна быть в списке. */
function catalog_entry_matches_topic($entry, $topic) {
    $topics = is_array($entry['topics'] ?? null) ? $entry['topics'] : [];
    if (!$topics) return true;
    $topic = mb_strtolower($topic);
    foreach ($topics as $entry_topic) {
        if (mb_strtolower((string)$entry_topic) === $topic) return true;
    }
    return false;
}

/**
 * Создаёт недостающие файлы данных с тем содержимым, которое им нужно:
 * история — пустые коллекции, каталог — стандартные источники, пул — пустой (его наполняет парсер),
 * настройки — тема поиска по умолчанию.
 */
function ensure_default_project_data() {
    if (!is_file(HISTORY_FILE)) {
        save_data(HISTORY_FILE, empty_history());
    }

    if (!is_file(SOURCES_FILE)) {
        save_data(SOURCES_FILE, create_default_source_catalog());
    } else {
        $source_db = load_data(SOURCES_FILE, create_default_source_catalog());
        $normalized = normalize_source_db($source_db);
        if ($source_db !== $normalized) {
            save_data(SOURCES_FILE, $normalized);
        }
    }

    // Пул без файла — пустой: первый же запрос записи запустит парсер, и он наполнит пул.
    if (!is_file(POOL_FILE)) {
        save_data(POOL_FILE, []);
    }
    if (!is_file(SETTINGS_FILE)) {
        save_data(SETTINGS_FILE, default_settings());
    }
}

function normalize_source_db($db) {
    $defaults = create_default_source_catalog();
    $normalized = $defaults;

    foreach (['2ch_boards', 'vk_publics', 'telegram_channels'] as $key) {
        $normalized[$key] = is_array($db[$key] ?? null) ? array_values($db[$key]) : $defaults[$key];
    }

    // Стандартные каналы из файлов, созданных до привязки к темам, получают тему по умолчанию.
    $default_topics = [];
    foreach (['vk_publics', 'telegram_channels'] as $key) {
        foreach ($defaults[$key] as $entry) $default_topics[$key][strtolower($entry['id'])] = $entry['topics'];
        foreach ($normalized[$key] as $index => $entry) {
            if (!is_array($entry) || isset($entry['topics'])) continue;
            $known = $default_topics[$key][strtolower((string)($entry['id'] ?? ''))] ?? null;
            if ($known !== null) $normalized[$key][$index]['topics'] = $known;
        }
    }

    $votes = is_array($db['source_votes'] ?? null) ? $db['source_votes'] : [];
    $normalized['source_votes'] = array_values(array_filter($votes, function($vote) {
        return is_array($vote) && !empty($vote['source_id'] ?? '');
    }));
    $suggestions = is_array($db['source_suggestions'] ?? null) ? $db['source_suggestions'] : [];
    $normalized['source_suggestions'] = array_values(array_filter($suggestions, function ($suggestion) {
        return is_array($suggestion) && !empty($suggestion['source_id'] ?? '');
    }));
    $normalized['discovery'] = (is_array($db['discovery'] ?? null) ? $db['discovery'] : []) + $defaults['discovery'];

    return $normalized;
}

function source_vote_exists($db, $source_id) {
    foreach (($db['source_votes'] ?? []) as $vote) {
        if (($vote['source_id'] ?? null) === $source_id) {
            return true;
        }
    }
    return false;
}
/**
 * Очищает все базы: пул и история пустые, каталог источников — стандартный. Тема поиска сохраняется.
 * Каждый файл меняется под своей блокировкой, поэтому работающий парсер не затрёт очистку. Кэш медиа удаляется целиком.
 */
function reset_project_data() {
    update_data(POOL_FILE, [], function () { return []; });
    update_data(HISTORY_FILE, empty_history(), function () { return empty_history(); });
    update_data(SOURCES_FILE, create_default_source_catalog(), function () { return create_default_source_catalog(); });
    return media_cache_cleanup([], true);
}

function load_history() {
    return load_data(HISTORY_FILE, empty_history());
}

function load_source_db() {
    return normalize_source_db(load_data(SOURCES_FILE, create_default_source_catalog()));
}
