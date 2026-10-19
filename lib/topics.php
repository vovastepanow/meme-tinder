<?php
// Реестр тем поиска (sources_db.json → topics). У каждой темы своё аккуратное название, вид и свои источники.
//
// Тема — запись ['id', 'title', 'scope', 'parent', 'aliases', 'about', 'classified', 'created_at']:
//  - title — короткое название по единым правилам: 1–4 слова, с заглавной буквы («Мемы и юмор», «Кошки», «Ретро-автомобили»);
//  - scope — broad (широкая: область вроде «Животные», «Мемы и юмор») или narrow (узкая: предмет внутри области — «Кошки»);
//  - parent — id широкой темы, в которую входит узкая; у широкой parent = null;
//  - aliases — как ещё пользователи называли эту тему («котики», «кошечки»): ввод по любому из них ведёт сюда же.
//
// Источники каталога привязаны к темам по названию (поле topics у канала или паблика). Для темы годятся:
//  - её собственные источники (по названию и синонимам);
//  - у широкой темы — ещё и источники её узких тем (они тоже по ней);
//  - у узкой темы, пока своих источников меньше TOPIC_PARENT_FALLBACK_MIN, — источники её широкой темы: подборка не пустует,
//    пока автоподбор ищет свои. Модель всё равно оценивает каждую запись по самой узкой теме.
// Другие темы своих источников не делят: раньше похожесть тем решала модель попарно и путала совсем разные темы.

const TOPIC_PARENT_FALLBACK_MIN = 3;
const TOPIC_TITLE_MAX = 40;
const TOPIC_SCOPES = ['broad', 'narrow'];
const TOPIC_EMBED_MODEL = 'bge-m3';      // эмбеддинги для проверки «это та же тема» (ollama pull bge-m3); нет модели — проверка строже
const TOPIC_SMART_TIMEOUT = 150;        // сколько ждать умную модель при определении темы, потом — быстрая
const TOPIC_SAME_SIMILARITY = 0.85;     // «мемы и приколы» ↔ «Мемы и юмор» ≈ 0.91, «котики» ↔ «Кошки» ≈ 0.76, «собаки» ↔ «Кошки» ≈ 0.71

/** Нормальная форма для сравнения: нижний регистр, ё → е, без знаков препинания и лишних пробелов. */
function topic_norm($text) {
    $text = mb_strtolower(trim((string)$text));
    $text = str_replace('ё', 'е', $text);
    $text = preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $text) ?? $text;
    return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
}

function topic_id_for($title) {
    return 't' . substr(sha1(topic_norm($title)), 0, 8);
}

/** Название с заглавной буквы, без кавычек и лишних пробелов. */
function topic_title_case($text) {
    $text = trim(preg_replace('/\s+/u', ' ', str_replace(['«', '»', '"', '“', '”'], '', (string)$text)) ?? '');
    return $text === '' ? '' : mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
}

/** Приводит запись темы к нужной форме или возвращает null. */
function topic_normalize_entry($entry) {
    if (!is_array($entry) || trim((string)($entry['title'] ?? '')) === '') return null;
    $title = mb_substr(topic_title_case($entry['title']), 0, TOPIC_TITLE_MAX);
    $aliases = [];
    foreach ((array)($entry['aliases'] ?? []) as $alias) {
        $alias = trim((string)$alias);
        if ($alias !== '' && topic_norm($alias) !== topic_norm($title)) $aliases[topic_norm($alias)] = mb_substr($alias, 0, 80);
    }
    $scope = in_array($entry['scope'] ?? '', TOPIC_SCOPES, true) ? $entry['scope'] : 'narrow';
    return [
        'id' => preg_match('/^t[0-9a-f]{8}$/', (string)($entry['id'] ?? '')) ? $entry['id'] : topic_id_for($title),
        'title' => $title,
        'scope' => $scope,
        'parent' => $scope === 'narrow' && preg_match('/^t[0-9a-f]{8}$/', (string)($entry['parent'] ?? '')) ? $entry['parent'] : null,
        'aliases' => array_values(array_slice($aliases, 0, 30)),
        'about' => mb_substr(trim((string)($entry['about'] ?? '')), 0, 200),
        'classified' => !empty($entry['classified']),
        'smart' => !empty($entry['smart']),     // описана умной моделью (SMART_MODEL); иначе её переопишут (topic_classify_pending)
        'profile' => topic_profile_clean($entry['profile'] ?? []),   // профиль для промптов и план поиска (topic_profile_ensure)
        'manual' => !empty($entry['manual']),   // общую тему поправил пользователь: модель её больше не меняет
        'created_at' => (int)($entry['created_at'] ?? time())
    ];
}

/**
 * Реестр тем из каталога (с первым заполнением): тема по умолчанию — широкая «Мемы и юмор»; темы, которые уже встречаются
 * у источников, предложений и пользователей, регистрируются как есть (уточнит их модель при запуске парсера);
 * пары тем, которые прежняя проверка признала равнозначными в обе стороны, сливаются в одну тему с синонимом.
 * Вызывается из normalize_source_db.
 */
function topic_registry_from_db(array $db) {
    $registry = [];
    foreach ((array)($db['topics'] ?? []) as $entry) {
        $entry = topic_normalize_entry($entry);
        if ($entry === null) continue;
        if (topic_norm($entry['title']) === topic_norm(DEFAULT_TOPIC)) $entry['smart'] = true;   // описание темы по умолчанию написано вручную
        $registry[$entry['id']] = $entry;
    }
    $find = function ($text) use (&$registry) {
        $norm = topic_norm($text);
        foreach ($registry as $id => $entry) {
            if (topic_norm($entry['title']) === $norm) return $id;
            foreach ($entry['aliases'] as $alias) if (topic_norm($alias) === $norm) return $id;
        }
        return null;
    };
    if ($find(DEFAULT_TOPIC) === null) {
        $entry = topic_normalize_entry(['title' => DEFAULT_TOPIC, 'scope' => 'broad', 'classified' => true, 'smart' => true,
            'about' => 'Мемы, приколы, смешные картинки и видео на любые темы.', 'created_at' => 0]);
        $registry[$entry['id']] = $entry;
    }
    // Взаимно равнозначные темы прежней проверки → синонимы одной темы
    $aliases = is_array($db['topic_aliases'] ?? null) ? $db['topic_aliases'] : [];
    foreach ($aliases as $key => $match) {
        [$a, $b] = array_pad(explode('|', (string)$key, 2), 2, '');
        if (!$match || empty($aliases[$b . '|' . $a]) || $a === '' || $b === '') continue;
        $id = $find($a) ?? $find($b);
        if ($id === null) continue;
        foreach ([$a, $b] as $name) if ($find($name) === null) $registry[$id]['aliases'][] = $name;
    }
    // Темы, которые уже где-то используются, но ещё не в реестре
    $used = [];
    foreach (['telegram_channels', 'vk_publics'] as $key) {
        foreach ((array)($db[$key] ?? []) as $entry) foreach ((array)($entry['topics'] ?? []) as $topic) $used[] = (string)$topic;
    }
    foreach ((array)($db['source_suggestions'] ?? []) as $suggestion) $used[] = (string)($suggestion['topic'] ?? '');
    foreach (array_keys((array)($db['dvach_topic_boards'] ?? [])) as $topic) $used[] = (string)$topic;
    foreach (array_keys((array)($db['discovery']['runs'] ?? [])) as $topic) $used[] = (string)$topic;
    foreach ($used as $topic) {
        if (trim($topic) === '' || $find($topic) !== null) continue;
        $entry = topic_normalize_entry(['title' => $topic]);
        if ($entry !== null) $registry[$entry['id']] = $entry;
    }
    return array_values(array_map('topic_normalize_entry', $registry));
}

/** Реестр [id => тема]. Кэшируется на время запроса; $reload — перечитать с диска. */
function topic_registry($reload = false) {
    static $registry = null;
    if ($registry === null || $reload) {
        $registry = [];
        foreach (load_source_db()['topics'] as $entry) $registry[$entry['id']] = $entry;
        topic_related_norms(null, true);
    }
    return $registry;
}

/** Тема реестра по названию или синониму (без учёта регистра, «ё» и знаков) или null. */
function topic_lookup($text) {
    $norm = topic_norm($text);
    if ($norm === '') return null;
    foreach (topic_registry() as $entry) {
        if (topic_norm($entry['title']) === $norm) return $entry;
        foreach ($entry['aliases'] as $alias) if (topic_norm($alias) === $norm) return $entry;
    }
    return null;
}

/** Все нормальные формы имени темы: название и синонимы. */
function topic_names(array $entry) {
    return array_values(array_unique(array_map('topic_norm', array_merge([$entry['title']], $entry['aliases']))));
}

/** Сохраняет (добавляет или заменяет) тему в реестре. Возвращает сохранённую запись. */
function topic_save(array $entry) {
    $entry = topic_normalize_entry($entry);
    if ($entry === null) throw new InvalidArgumentException('Пустое название темы.');
    update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($entry) {
        $db = normalize_source_db($db);
        $db['topics'] = array_values(array_filter($db['topics'], fn($old) => $old['id'] !== $entry['id']));
        $db['topics'][] = $entry;
        return $db;
    });
    topic_registry(true);
    return $entry;
}

/** Тема из реестра; если такой нет — регистрирует её как есть (без модели, уточнится при запуске парсера). */
function topic_ensure($text) {
    $found = topic_lookup($text);
    if ($found !== null || trim((string)$text) === '') return $found;
    return topic_save(['title' => $text, 'scope' => 'narrow', 'classified' => false]);
}

/** Добавляет синоним к теме (то, как пользователь её назвал), если его ещё нет. */
function topic_add_alias(array $entry, $alias) {
    if (in_array(topic_norm($alias), topic_names($entry), true)) return $entry;
    $entry['aliases'][] = trim((string)$alias);
    return topic_save($entry);
}

function topic_children(array $entry) {
    return array_values(array_filter(topic_registry(), fn($child) => $child['parent'] === $entry['id']));
}

/** Сколько у темы своих каналов и пабликов в каталоге (без унаследованных от широкой темы). */
function topic_own_source_count(array $entry, ?array $db = null) {
    $db = $db ?? load_source_db();
    $names = array_flip(topic_names($entry));
    $count = 0;
    foreach (['telegram_channels', 'vk_publics'] as $key) {
        foreach ($db[$key] as $source) {
            foreach ((array)($source['topics'] ?? []) as $topic) {
                if (isset($names[topic_norm($topic)])) { $count++; break; }
            }
        }
    }
    return $count;
}

/**
 * Нормальные формы тем, источники которых годятся для темы поиска $topic (см. начало файла). $reset — сбросить кэш.
 */
function topic_related_norms($topic, $reset = false) {
    static $cache = [];
    if ($reset) { $cache = []; return []; }
    $key = topic_norm($topic);
    if (isset($cache[$key])) return $cache[$key];
    $entry = topic_lookup($topic);
    if ($entry === null) return $cache[$key] = [$key => true];
    $norms = array_flip(topic_names($entry));
    if ($entry['scope'] === 'broad') {
        foreach (topic_children($entry) as $child) $norms += array_flip(topic_names($child));
    } elseif ($entry['parent'] !== null && isset(topic_registry()[$entry['parent']]) && topic_own_source_count($entry) < TOPIC_PARENT_FALLBACK_MIN) {
        $norms += array_flip(topic_names(topic_registry()[$entry['parent']]));
    }
    return $cache[$key] = $norms;
}

/** Одна ли это тема (то же название или синоним). */
function topic_same($a, $b) {
    $norm_a = topic_norm($a);
    $norm_b = topic_norm($b);
    if ($norm_a === $norm_b) return true;
    $entry = topic_lookup($a);
    return $entry !== null && in_array($norm_b, topic_names($entry), true);
}

/**
 * Тема для промптов модели: название, что в неё входит, широкая тема и другие названия. Одна и та же строка во всех
 * промптах (подбор источников, оценка записей) — маленькая модель понимает узкую или непривычную тему по описанию,
 * а не по одному-двум словам названия.
 */
/** Части составной темы «первая + вторая» (для одной темы — она сама). */
function topic_parts($topic) {
    return array_values(array_filter(array_map('trim', explode(' + ', (string)$topic)), fn($part) => $part !== ''));
}

function topic_prompt_context($topic) {
    $parts = topic_parts($topic);
    if (count($parts) > 1) return implode(' и ', array_map('topic_prompt_context', $parts));
    $entry = topic_lookup($topic);
    if ($entry === null) return "«{$topic}»";
    $parent = $entry['parent'] !== null ? (topic_registry()[$entry['parent']]['title'] ?? '') : '';
    $parts = [];
    if ($entry['about'] !== '') $parts[] = rtrim($entry['about'], '.');
    if ($parent !== '') $parts[] = "часть темы «{$parent}»";
    if ($entry['aliases']) $parts[] = 'ещё называют: ' . implode(', ', array_slice($entry['aliases'], 0, 4));
    return "«{$entry['title']}»" . ($parts ? ' (' . implode('; ', $parts) . ')' : '');
}

/** Для интерфейса: тема с её широкой темой и счётчиками. */
function topic_public(array $entry, ?array $db = null) {
    $parent = $entry['parent'] !== null ? (topic_registry()[$entry['parent']] ?? null) : null;
    $own = topic_own_source_count($entry, $db);
    return [
        'id' => $entry['id'],
        'title' => $entry['title'],
        'scope' => $entry['scope'],
        'parent' => $parent ? ['id' => $parent['id'], 'title' => $parent['title']] : null,
        'path' => $parent ? $parent['title'] . ' › ' . $entry['title'] : $entry['title'],
        'about' => $entry['about'],
        'aliases' => $entry['aliases'],
        'own_sources' => $own,
        'uses_parent_sources' => $parent !== null && $own < TOPIC_PARENT_FALLBACK_MIN,
        'children' => array_map(fn($child) => $child['title'], topic_children($entry)),
        'criteria' => (string)($entry['profile']['criteria'] ?? ''),
        'exclude' => (string)($entry['profile']['exclude'] ?? ''),
        'question' => (string)($entry['profile']['question'] ?? ''),
        'profile' => $entry['profile'] ? ['smart' => $entry['profile']['smart'], 'at' => $entry['profile']['at']] : null
    ];
}

// ---- Название и место новой темы: решает быстрая модель ----

function topic_ai_schema() {
    return ['type' => 'object', 'properties' => [
        'match' => ['type' => 'string'],
        'title' => ['type' => 'string'],
        'scope' => ['type' => 'string', 'enum' => TOPIC_SCOPES],
        'parent' => ['type' => 'string'],
        'parent_title' => ['type' => 'string'],
        'about' => ['type' => 'string']
    ], 'required' => ['match', 'title', 'scope', 'parent', 'parent_title', 'about']];
}

/**
 * Спрашивает модель, что за тема введена: совпадает ли с существующей (match = id), как её аккуратно назвать, широкая она
 * или узкая и в какую широкую тему входит. $allow_match = false — пользователь сказал, что это новая тема.
 * Возвращает разобранный ответ или null (модель недоступна или ответила не по формату).
 */
function topic_ai_classify($text, $allow_match = true, ?string $skip_id = null) {
    $lines = [];
    foreach (topic_registry() as $entry) {
        if ($entry['id'] === $skip_id) continue;
        $parent = $entry['parent'] !== null ? (topic_registry()[$entry['parent']]['title'] ?? '') : '';
        $lines[] = "- {$entry['id']}: «{$entry['title']}» — " . ($entry['scope'] === 'broad' ? 'широкая' : 'узкая' . ($parent !== '' ? ", внутри «{$parent}»" : ''))
            . ($entry['aliases'] ? '; ещё называли: ' . implode(', ', array_slice($entry['aliases'], 0, 5)) : '');
    }
    $prompt = "Пользователь задаёт тему для подбора контента из соцсетей (картинки, видео, мемы). Приведи тему к аккуратному названию и найди ей место среди уже существующих тем.\n\n"
        . "Существующие темы:\n" . ($lines ? implode("\n", $lines) : '(пока нет)') . "\n\n"
        . "Тема пользователя: «{$text}»\n\n"
        . "Правила:\n"
        . ($allow_match
            ? "1. match — id существующей темы, если тема пользователя означает ровно её же (синоним, другая формулировка, опечатка, другой язык). Если тема пользователя — частный случай существующей (например, «мемы про котов» при теме «Мемы и юмор»), это НЕ совпадение: match = \"\". Если похожей нет — match = \"\".\n"
            : "1. match всегда \"\": пользователь подтвердил, что это новая тема.\n")
        . "2. title — короткое название новой темы: 1–4 слова по-русски, с заглавной буквы, без кавычек и эмодзи. Существительные — во множественном числе, где это естественно («Кошки», «Ретро-автомобили», «Мемы про котов»). Смысл не меняй и не добавляй лишнего.\n"
        . "3. scope: \"broad\" — только большая общая область, в которой много разных предметов (как «Животные», «Мемы и юмор», «Автомобили», «Наука и космос», «Еда», «Спорт», «Игры»)."
        . " Всё более конкретное — \"narrow\": предмет, жанр, вид, персонаж, хобби внутри области (как «Кошки», «Мемы про котов», «Ретро-автомобили», «Футбол», «Выпечка»). Сомневаешься — narrow.\n"
        . "4. У узкой темы обязательно есть общая тема — область, частным случаем которой она является. parent — id подходящей широкой темы из списка;"
        . " если подходящей широкой темы в списке нет — parent = \"\", а parent_title — название такой общей темы (1–3 слова, как в примерах выше). У широкой темы parent = \"\" и parent_title = \"\".\n"
        . "5. about — одно короткое предложение: какие картинки и видео входят в тему (конкретно, чтобы по нему можно было отличить подходящую запись).\n"
        . "Примеры: «котики» → title «Кошки», narrow, общая «Животные»; «мемы про работу» → title «Мемы про работу», narrow, общая «Мемы и юмор»;"
        . " «тачки 90-х» → title «Автомобили 90-х», narrow, общая «Автомобили»; «космос» → title «Космос», broad.\n"
        . "Ответь только JSON.";
    // Тему определяет умная модель (SMART_MODEL): от её ответа зависят название, общая тема и описание во всех промптах.
    // Без размышлений — пользователь ждёт ответа при сохранении темы, а с ними на этом железе это минуты.
    // Умная модель не ответила вовремя — быстрая, чтобы тема всё равно сохранилась (с ответом похуже).
    $answer = null;
    foreach (array_unique([SMART_MODEL, prescreen_model()]) as $model) {
        $payload = ['model' => $model, 'prompt' => $prompt, 'format' => topic_ai_schema(), 'think' => false,
            'options' => ['temperature' => 0, 'num_predict' => 300]];
        try {
            $result = ollama_stream($payload, $model === SMART_MODEL ? TOPIC_SMART_TIMEOUT : 90, function () {});
            $answer = decode_model_json($result['response'] ?? '');
        } catch (Throwable $error) {
            $answer = null;
        }
        if (is_array($answer) && trim((string)($answer['title'] ?? '')) !== '') break;
        $answer = null;
    }
    if (!is_array($answer)) return null;
    $answered_by = $model;
    $title = topic_title_case(mb_substr((string)($answer['title'] ?? ''), 0, TOPIC_TITLE_MAX));
    $parent = (string)($answer['parent'] ?? '');
    $parent = isset(topic_registry()[$parent]) && topic_registry()[$parent]['scope'] === 'broad' ? $parent : '';
    $parent_title = topic_title_case(mb_substr((string)($answer['parent_title'] ?? ''), 0, TOPIC_TITLE_MAX));
    // Модель указала id одной темы, а назвала другую («Мемы и юмор» и «Животные»): верим названию — id она ставит по привычке
    if ($parent !== '' && $parent_title !== '' && !in_array(topic_norm($parent_title), topic_names(topic_registry()[$parent]), true)) {
        $parent = topic_lookup($parent_title)['id'] ?? '';
    }
    $words = preg_split('/\s+/u', $title, -1, PREG_SPLIT_NO_EMPTY);
    // «Частная», но общую тему модель не назвала — значит, сама и есть общая область («Аниме»)
    if (($answer['scope'] ?? '') === 'narrow' && $parent === '' && $parent_title === '') $answer['scope'] = 'broad';
    return [
        'match' => $allow_match && isset(topic_registry()[(string)($answer['match'] ?? '')]) ? (string)$answer['match'] : '',
        'title' => $title !== '' && count($words) <= 5 ? $title : '',
        'scope' => in_array($answer['scope'] ?? '', TOPIC_SCOPES, true) ? $answer['scope'] : 'narrow',
        'parent' => $parent,
        'parent_title' => $parent_title,
        'about' => trim((string)($answer['about'] ?? '')),
        'smart' => $answered_by === SMART_MODEL
    ];
}

/** Косинусная близость смысла двух коротких текстов по эмбеддингам TOPIC_EMBED_MODEL; 0, если модель недоступна. */
function topic_similarity($a, $b) {
    $handle = curl_init(OLLAMA_URL . '/api/embed');
    curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['model' => TOPIC_EMBED_MODEL, 'input' => [mb_strtolower($a), mb_strtolower($b)]])]);
    $response = json_decode((string)curl_exec($handle), true);
    curl_close($handle);
    [$x, $y] = array_pad((array)($response['embeddings'] ?? []), 2, null);
    if (!is_array($x) || !is_array($y) || count($x) !== count($y)) return 0.0;
    $dot = $norm_x = $norm_y = 0.0;
    foreach ($x as $i => $value) {
        $dot += $value * $y[$i];
        $norm_x += $value * $value;
        $norm_y += $y[$i] * $y[$i];
    }
    return $norm_x > 0 && $norm_y > 0 ? $dot / sqrt($norm_x * $norm_y) : 0.0;
}

/** Широкая тема по названию: существующая или новая. */
function topic_ensure_broad($title) {
    $found = topic_lookup($title);
    if ($found !== null) return $found['scope'] === 'broad' ? $found : null;   // узкую тему родителем не делаем
    return topic_save(['title' => $title, 'scope' => 'broad', 'classified' => true]);
}

/** Создаёт новую тему по ответу модели (или как есть, если модель не ответила). */
function topic_create_from_answer($text, ?array $answer) {
    $title = $answer['title'] ?? '';
    if ($title === '') $title = topic_title_case(normalize_topic($text));
    $existing = topic_lookup($title);   // модель могла предложить название, которое уже есть
    if ($existing !== null) return topic_add_alias($existing, $text);
    $parent = null;
    if ($answer !== null && $answer['scope'] === 'narrow') {
        $parent = $answer['parent'] !== '' ? $answer['parent'] : null;
        if ($parent === null && $answer['parent_title'] !== '' && topic_norm($answer['parent_title']) !== topic_norm($title)) {
            $parent = topic_ensure_broad($answer['parent_title'])['id'] ?? null;
        }
    }
    $entry = ['title' => $title, 'scope' => $answer['scope'] ?? 'narrow', 'parent' => $parent, 'about' => $answer['about'] ?? '',
        'aliases' => topic_norm($text) !== topic_norm($title) ? [$text] : [], 'classified' => $answer !== null, 'smart' => !empty($answer['smart'])];
    return topic_save($entry);
}

/**
 * Тема поиска по вводу пользователя: существующая (по названию, синониму или решению модели) или новая с аккуратным названием.
 * Возвращает ['topic' => запись, 'matched' => ввод оказался существующей темой, 'created' => тема новая, 'ai' => отвечала ли модель].
 */
function topic_resolve($text, $force_new = false) {
    $text = normalize_topic($text);
    if (!$force_new) {
        $found = topic_lookup($text);
        if ($found !== null) return ['topic' => $found, 'matched' => topic_norm($found['title']) !== topic_norm($text), 'created' => false, 'ai' => false];
    }
    $answer = topic_ai_classify($text, !$force_new);
    // Маленькая модель почти на любой ввод отвечает «совпадает с Мемы и юмор» («аниме», «котики»...). Поэтому её match
    // принимается, только если её же название для ввода — это название или синоним той темы, либо смысл почти одинаков
    // по эмбеддингам. Иначе тема новая (а если её название уже есть в реестре — topic_create_from_answer найдёт её сама).
    if ($answer !== null && $answer['match'] !== '') {
        $matched = topic_registry()[$answer['match']];
        $same_title = $answer['title'] !== '' && in_array(topic_norm($answer['title']), topic_names($matched), true);
        if (!$same_title && topic_similarity($text, $matched['title']) < TOPIC_SAME_SIMILARITY) $answer['match'] = '';
    }
    if ($answer !== null && $answer['match'] !== '') {
        $entry = topic_add_alias(topic_registry()[$answer['match']], $text);   // в следующий раз ввод узнаётся без модели
        return ['topic' => $entry, 'matched' => true, 'created' => false, 'ai' => true];
    }
    if ($force_new && ($existing = topic_lookup($text)) !== null) {
        // «Это другая тема»: ввод был синонимом существующей — убираем его оттуда и заводим отдельную тему
        $existing['aliases'] = array_values(array_filter($existing['aliases'], fn($alias) => topic_norm($alias) !== topic_norm($text)));
        topic_save($existing);
        if (topic_norm($existing['title']) === topic_norm($text)) return ['topic' => $existing, 'matched' => false, 'created' => false, 'ai' => false];
    }
    return ['topic' => topic_create_from_answer($text, $answer), 'matched' => false, 'created' => true, 'ai' => $answer !== null];
}

/**
 * Уточняет темы, зарегистрированные без модели или описанные быстрой моделью: описание (по нему оцениваются записи),
 * вид и общую тему (если пользователь их не правил) даёт умная модель. Название не меняется — по нему к теме уже привязаны
 * источники и настройки пользователей. Вызывается парсером ($first — его текущая тема) и ночным подбором.
 */
function topic_classify_pending($limit = 1, $first = null) {
    $done = 0;
    $entries = topic_registry();
    $preferred = $first !== null ? topic_lookup($first) : null;
    if ($preferred !== null) $entries = [$preferred['id'] => $preferred] + $entries;   // тема, по которой сейчас идёт сбор, — первой
    foreach ($entries as $entry) {
        if (($entry['classified'] && $entry['smart']) || $done >= $limit) continue;
        $done++;
        $answer = topic_ai_classify($entry['title'], false, $entry['id']);
        if ($answer === null || ($entry['classified'] && !$answer['smart'])) continue;   // переописывать стоит только умной моделью
        // Описание — от умной модели (по нему модель оценивает записи); место темы — если пользователь его не правил
        $entry['about'] = $answer['about'] !== '' ? $answer['about'] : $entry['about'];
        if (!$entry['manual']) {
            $entry['scope'] = $answer['scope'];
            $entry['parent'] = null;
            if ($answer['scope'] === 'narrow') {
                $entry['parent'] = $answer['parent'] !== '' ? $answer['parent'] : null;
                if ($entry['parent'] === null && $answer['parent_title'] !== '' && topic_norm($answer['parent_title']) !== topic_norm($entry['title'])) {
                    $entry['parent'] = topic_ensure_broad($answer['parent_title'])['id'] ?? null;
                }
            }
            if (topic_children($entry)) { $entry['scope'] = 'broad'; $entry['parent'] = null; }   // в неё входят другие темы — остаётся общей
        }
        $entry['classified'] = true;
        $entry['smart'] = $answer['smart'];
        topic_save($entry);
        if (PHP_SAPI === 'cli') echo "Тема «{$entry['title']}»: " . ($entry['scope'] === 'broad' ? 'общая' : 'частная'
            . ($entry['parent'] ? ', внутри «' . (topic_registry()[$entry['parent']]['title'] ?? '') . '»' : '')) . ". Описание: {$entry['about']}\n";
    }
    return $done;
}

/**
 * Пользователь поправил место темы: $parent — id широкой темы, название новой общей темы или '' (тема сама общая).
 * Узкую тему, у которой есть свои узкие темы, общей другой темы не сделать (вложенность — один уровень).
 */
function topic_set_parent($id, $parent) {
    $entry = topic_registry()[$id] ?? null;
    if ($entry === null) throw new InvalidArgumentException('Такой темы нет.');
    $parent = trim((string)$parent);
    if ($parent === '') {
        $entry['scope'] = 'broad';
        $entry['parent'] = null;
    } else {
        $target = topic_registry()[$parent] ?? topic_lookup($parent);
        if ($target !== null && $target['id'] === $entry['id']) throw new InvalidArgumentException('Тема не может входить сама в себя.');
        if ($target !== null && $target['scope'] !== 'broad') throw new InvalidArgumentException("«{$target['title']}» — частная тема, общей она быть не может.");
        if (topic_children($entry)) throw new InvalidArgumentException("В тему «{$entry['title']}» входят другие темы — она должна остаться общей.");
        $target = $target ?? topic_save(['title' => $parent, 'scope' => 'broad', 'classified' => true]);
        $entry['scope'] = 'narrow';
        $entry['parent'] = $target['id'];
    }
    $entry['classified'] = true;
    $entry['manual'] = true;
    return topic_save($entry);
}

// ---- Профиль темы: критерии отбора, вопрос для быстрой оценки и план поиска ----
// Пишет умная модель один раз на тему и хранит в записи темы (sources_db.json → topics). Эти же тексты идут во все
// промпты, связанные с темой: быстрая оценка картинок, полный анализ, оценка источников и поиск новых. Так промпт для
// «Фап контент» не совпадает с промптом для «Мемов». Поля: criteria — что видно, если подходит; exclude — что похоже,
// но не подходит; question — вопрос «да/нет» для быстрой оценки картинки; queries, telegram — план поиска; v — версия
// схемы (при смене TOPIC_PROFILE_VERSION профиль пишется заново); smart — писала умная модель; at — когда.

const TOPIC_PROFILE_VERSION = 1;
const TOPIC_PROFILE_FALLBACK_RETRY = 3600;   // профиль, написанный не умной моделью, переписывается не раньше чем через час

/** Профиль в каноническом виде (или []). Лишнее и битое отбрасывается. */
function topic_profile_clean($profile) {
    if (!is_array($profile) || empty($profile['criteria']) || empty($profile['question'])) return [];
    $text = fn($value, $length) => mb_substr(trim((string)$value), 0, $length);
    return [
        'criteria' => $text($profile['criteria'], 300),
        'exclude' => $text($profile['exclude'] ?? '', 300),
        'question' => $text($profile['question'], 200),
        'queries' => array_values(array_slice(array_map(fn($query) => $text($query, 60), (array)($profile['queries'] ?? [])), 0, 12)),
        'telegram' => array_values(array_slice(array_filter(array_map(fn($handle) => (string)$handle, (array)($profile['telegram'] ?? [])), 'is_valid_telegram_channel'), 0, 20)),
        'v' => (int)($profile['v'] ?? 0),
        'smart' => !empty($profile['smart']),
        'at' => (int)($profile['at'] ?? 0)
    ];
}

function topic_profile_schema() {
    $list = ['type' => 'array', 'items' => ['type' => 'string']];
    return ['type' => 'object', 'properties' => [
        'criteria' => ['type' => 'string'], 'exclude' => ['type' => 'string'], 'question' => ['type' => 'string'],
        'queries' => $list, 'queries_en' => $list, 'telegram' => $list
    ], 'required' => ['criteria', 'exclude', 'question', 'queries', 'queries_en', 'telegram']];
}

/** Пишет профиль темы умной моделью (без размышлений; при неудаче — быстрой). Возвращает профиль или [] . */
function topic_profile_build(array $entry) {
    $context = topic_prompt_context($entry['title']);
    $prompt = "Тема подбора картинок и видео из соцсетей: {$context}.\n"
        . "Составь профиль для отбора записей по этой теме:\n"
        . "criteria — что должно быть видно на картинке или в видео, чтобы запись подходила под тему (1–2 предложения, конкретные признаки, не общие слова).\n"
        . "exclude — что похоже на тему, но не подходит (1 предложение).\n"
        . "question — один вопрос по-русски, на который отвечают «да» или «нет» по одной картинке; начинается со слов «Это …?» и проверяет критерии (например: «Это картинка с мотоциклом или мотоциклистом?»).\n"
        . "queries — 8 поисковых запросов по-русски, которыми люди ищут такие каналы и паблики: 1–3 слова, от прямого названия к синонимам, сленгу и подтемам. Все строго про тему, без слов «канал», «телеграм», «паблик», «группа», без опечаток.\n"
        . "queries_en — 3 запроса по-английски.\n"
        . "telegram — адреса (username, латиница, цифры и _) публичных Telegram-каналов по теме, которые ты точно знаешь. Не уверен — пустой список.\n"
        . "Ответь только JSON.";
    $answer = null;
    $smart = true;
    foreach ([[SMART_MODEL, true], [prescreen_model(), false]] as [$model, $is_smart]) {
        try {
            $answer = decode_model_json(ai_generate_local($model, $prompt, [], topic_profile_schema(), ['temperature' => 0.3, 'num_predict' => 700], false));
        } catch (Throwable $error) {
            $answer = null;
        }
        if (is_array($answer) && trim((string)($answer['criteria'] ?? '')) !== '') { $smart = $is_smart; break; }
        $answer = null;
    }
    if ($answer === null) return [];
    $question = trim((string)($answer['question'] ?? ''));
    if (!str_contains($question, '?')) $question = "Подходит ли эта картинка под тему «{$entry['title']}»?";
    $queries = [];
    foreach (array_merge((array)($answer['queries'] ?? []), (array)($answer['queries_en'] ?? [])) as $query) {
        $query = discovery_clean_query($query);
        if ($query !== '') $queries[topic_norm($query)] = $query;
    }
    $handles = array_map(fn($handle) => preg_replace('~^(https?://)?t\.me/(s/)?~i', '', ltrim(trim((string)$handle), '@')), (array)($answer['telegram'] ?? []));
    return topic_profile_clean([
        'criteria' => $answer['criteria'], 'exclude' => $answer['exclude'] ?? '', 'question' => $question,
        'queries' => array_values($queries), 'telegram' => array_values(array_unique($handles)),
        'v' => TOPIC_PROFILE_VERSION, 'smart' => $smart, 'at' => time()
    ]);
}

/**
 * Профиль темы: из записи, а если его нет, устарел или написан не умной моделью — пишется заново и сохраняется.
 * Смена критериев сбрасывает прежние быстрые оценки этой темы: они были по другому вопросу. Возвращает профиль или [].
 */
function topic_profile_ensure($topic) {
    $entry = topic_lookup($topic);
    if ($entry === null) return [];
    $profile = $entry['profile'];
    $fresh = $profile && $profile['v'] === TOPIC_PROFILE_VERSION
        && ($profile['smart'] || time() - $profile['at'] < TOPIC_PROFILE_FALLBACK_RETRY);
    if ($fresh) return $profile;
    $built = topic_profile_build($entry);
    if ($built === []) return $profile ?: [];
    if ($profile && $profile['question'] !== $built['question']) topic_profile_forget_scores($entry['title']);
    $entry['profile'] = $built;
    topic_save($entry);
    if (PHP_SAPI === 'cli') echo "Профиль темы «{$entry['title']}»" . ($built['smart'] ? '' : ' (быстрой моделью)') . ": {$built['criteria']}\n";
    return $built;
}

/** Убирает быстрые оценки записей по теме: они относятся к прежнему вопросу. */
function topic_profile_forget_scores($title) {
    $cache = load_data(SCREENED_CACHE_FILE, []);
    if (!is_array($cache)) return;
    $kept = array_filter($cache, fn($entry) => !is_array($entry) || !topic_same((string)($entry['topic'] ?? ''), $title));
    if (count($kept) !== count($cache)) save_data(SCREENED_CACHE_FILE, $kept);
}

/** Профиль темы для промптов (без обращения к модели): [] — профиля ещё нет. */
function topic_profile_for_prompts($topic) {
    $parts = topic_parts($topic);
    if (count($parts) > 1) $topic = $parts[0];   // вопрос для быстрой оценки — от первой темы, критерии обеих — в подсказке
    $entry = topic_lookup($topic);
    return $entry !== null && $entry['profile'] && $entry['profile']['v'] === TOPIC_PROFILE_VERSION ? $entry['profile'] : [];
}

/** Строка для промптов: что подходит и что нет по критериям темы (или пустая строка, если профиля нет). */
function topic_profile_hint($topic) {
    $parts = topic_parts($topic);
    if (count($parts) > 1) return implode('', array_map('topic_profile_hint', $parts));
    $profile = topic_profile_for_prompts($topic);
    if (!$profile) return '';
    return " Подходит: {$profile['criteria']}" . ($profile['exclude'] !== '' ? " Не подходит: {$profile['exclude']}" : '') . '.';
}

