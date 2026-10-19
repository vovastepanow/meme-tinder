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
        '2ch_boards' => ['b', 'me', 'pr', 'r', 'cc', 'a', 'mu', 'd', 's'],
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
        // Доски Двача, подобранные нейросетью под тему: [тема в нижнем регистре => [доски]].
        // Для темы по умолчанию используется 2ch_boards.
        'dvach_topic_boards' => [],
        // Решения «эта тема источника подходит для темы поиска»: ["тема источника|тема поиска" => bool].
        'topic_aliases' => [],
        // Реестр тем поиска: названия, широкие и узкие темы, синонимы (lib/topics.php).
        'topics' => [],
        // Когда и по какой теме последний раз подбирались источники; requested — подбор заказан кнопкой.
        'discovery' => ['topic' => '', 'last_run' => 0, 'requested' => false]
    ];
}

/**
 * Подходит ли источник каталога для темы: без topics — для любой. Иначе одна из его тем должна быть той же темой или
 * связанной с ней (lib/topics.php: узкие темы внутри широкой, широкая для узкой без своих источников).
 * $strict — только сама тема и её синонимы, без связанных («есть ли у темы свои источники»).
 */
function catalog_entry_matches_topic($entry, $topic, $strict = false) {
    $topics = is_array($entry['topics'] ?? null) ? $entry['topics'] : [];
    if (!$topics) return true;
    foreach ($topics as $entry_topic) {
        if ($strict ? topic_same($topic, (string)$entry_topic) : topics_are_equivalent((string)$entry_topic, $topic)) return true;
    }
    return false;
}

// ---- Связанные темы ----
// Раньше похожесть тем решала модель попарно (topic_aliases) — и путала совсем разные темы. Теперь темы ведёт реестр
// (lib/topics.php): синонимы одной темы, широкие и узкие темы. topic_aliases осталась только для первого заполнения реестра.

function topic_alias_key($topic_a, $topic_b) {
    return mb_strtolower(trim($topic_a)) . '|' . mb_strtolower(trim($topic_b));
}

function topic_alias_map($reload = false) {
    static $map = null;
    if ($map === null || $reload) $map = load_source_db()['topic_aliases'] ?? [];
    return $map;
}

/** Годятся ли источники темы $entry_topic для поиска по теме $topic (реестр тем, lib/topics.php). */
function topics_are_equivalent($entry_topic, $topic) {
    return isset(topic_related_norms($topic)[topic_norm($entry_topic)]);
}

/**
 * Создаёт недостающие файлы данных с тем содержимым, которое им нужно:
 * история — пустые коллекции, каталог — стандартные источники, пул — пустой (его наполняет парсер),
 * настройки — тема поиска по умолчанию.
 */
function ensure_default_project_data() {
    if (!is_file(history_file())) {
        save_data(history_file(), empty_history());
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
    if (!is_file(pool_file())) {
        save_data(pool_file(), []);
    }
    if (!is_file(settings_file())) {
        save_data(settings_file(), default_settings());
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
    $topic_boards = is_array($db['dvach_topic_boards'] ?? null) ? $db['dvach_topic_boards'] : [];
    $normalized['dvach_topic_boards'] = array_map(function ($boards) {
        return array_values(array_filter(is_array($boards) ? $boards : [], 'is_valid_dvach_board'));
    }, $topic_boards);
    $aliases = is_array($db['topic_aliases'] ?? null) ? $db['topic_aliases'] : [];
    $normalized['topic_aliases'] = array_map('boolval', array_filter($aliases, 'is_scalar'));
    $normalized['discovery'] = (is_array($db['discovery'] ?? null) ? $db['discovery'] : []) + $defaults['discovery'];
    $normalized['topics'] = topic_registry_from_db($db + ['topics' => []]);   // реестр тем (lib/topics.php)

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
 * Очищает данные текущего пользователя: пул и история пустые, тема поиска сохраняется. Каталог источников общий для всех
 * пользователей, поэтому остаётся как есть. Каждый файл меняется под своей блокировкой, поэтому работающий парсер не затрёт
 * очистку. Из кэша медиа удаляются файлы, которые больше никому не показываются.
 */
function reset_project_data() {
    update_data(pool_file(), [], function () { return []; });
    update_data(history_file(), empty_history(), function () { return empty_history(); });
    return media_cache_cleanup(all_users_pool_items());
}

function load_history() {
    return load_data(history_file(), empty_history());
}

function load_source_db() {
    return normalize_source_db(load_data(SOURCES_FILE, create_default_source_catalog()));
}
