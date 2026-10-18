<?php
// Редактор баз данных: чтение, проверка и сохранение записей JSON-файлов.

function database_editor_definitions() {
    return [
        'memes_pool' => [
            'label' => 'Пул мемов',
            'file' => POOL_FILE,
            'collections' => ['items' => 'Мемы']
        ],
        'meme_history' => [
            'label' => 'История оценок',
            'file' => HISTORY_FILE,
            'collections' => [
                'liked_memes' => 'Лайки',
                'disliked_memes' => 'Дизлайки',
                'undecided_memes' => 'Без решения',
                'wrong_topic_memes' => 'Жалобы'
            ]
        ],
        'sources_db' => [
            'label' => 'Каталог источников',
            'file' => SOURCES_FILE,
            'collections' => [
                '2ch_boards' => 'Доски 2ch',
                'vk_publics' => 'Паблики ВКонтакте',
                'telegram_channels' => 'Каналы Telegram',
                'source_votes' => 'Оценки источников'
            ]
        ]
    ];
}

function database_editor_definition($database) {
    $definitions = database_editor_definitions();
    if (!is_string($database) || !isset($definitions[$database])) throw new InvalidArgumentException('Неизвестная база данных.');
    return $definitions[$database];
}

/** Загружает файл базы. В отличие от load_data, битый JSON — ошибка, а не пустая база: иначе сохранение затрёт файл. */
function database_editor_load($database) {
    $definition = database_editor_definition($database);
    $raw = is_file($definition['file']) ? file_get_contents($definition['file']) : false;
    if ($raw === false) throw new RuntimeException('Файл базы не найден или недоступен для чтения.');
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        throw new RuntimeException('Файл базы содержит некорректный JSON.');
    }
    return [$definition, $data];
}

function database_editor_collection($database, $definition, $data, $collection) {
    if (!is_string($collection) || !array_key_exists($collection, $definition['collections'])) {
        throw new InvalidArgumentException('Неизвестная коллекция.');
    }
    $items = $database === 'memes_pool' ? $data : ($data[$collection] ?? []);
    if (!is_array($items)) throw new RuntimeException('Коллекция должна быть массивом записей.');
    return array_values($items);
}

function database_editor_save($database, $definition, $data, $collection, $items) {
    if ($database === 'memes_pool') {
        $data = array_values($items);
    } else {
        $data[$collection] = array_values($items);
    }
    save_data($definition['file'], $data);
}

function database_editor_validate_record($database, $collection, $record) {
    if ($database === 'memes_pool') {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['image'] ?? '')) === '') {
            throw new InvalidArgumentException('Мему нужны поля-объект и непустой image.');
        }
        if (isset($record['rating']) && (!is_numeric($record['rating']) || $record['rating'] < 0 || $record['rating'] > 100)) {
            throw new InvalidArgumentException('rating должен быть числом от 0 до 100.');
        }
        return;
    }

    if (in_array($collection, HISTORY_COLLECTIONS, true)) {
        if (!is_array($record) || array_is_list($record) || !is_string($record['text'] ?? null) || !is_numeric($record['rating_assigned'] ?? null)) {
            throw new InvalidArgumentException('Для записи истории нужны объект, строка text и число rating_assigned.');
        }
        return;
    }

    if ($database === 'sources_db' && $collection === '2ch_boards') {
        if (!is_string($record) || !preg_match('/^[a-z0-9_-]{1,30}$/i', $record)) {
            throw new InvalidArgumentException('Доска 2ch должна содержать только буквы, цифры, _ или - (до 30 символов).');
        }
        return;
    }

    if ($database === 'sources_db' && in_array($collection, ['vk_publics', 'telegram_channels'], true)) {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['id'] ?? '')) === '' || trim((string)($record['name'] ?? '')) === '') {
            throw new InvalidArgumentException('Источнику нужны непустые поля id и name.');
        }
        return;
    }

    if ($database === 'sources_db' && $collection === 'source_votes') {
        if (!is_array($record) || array_is_list($record) || trim((string)($record['source_id'] ?? '')) === '' || !in_array($record['vote'] ?? '', ['approve', 'reject'], true)) {
            throw new InvalidArgumentException('Оценке источника нужны source_id и vote: approve или reject.');
        }
    }
}

function database_editor_require_record($input) {
    if (!array_key_exists('record', $input)) throw new InvalidArgumentException('Не переданы данные записи.');
    return $input['record'];
}

function database_editor_assert_same_shape($existing, $record) {
    if (is_array($existing) !== is_array($record)) {
        throw new InvalidArgumentException('Нельзя менять тип записи.');
    }
    if (is_array($existing) && array_is_list($existing) !== array_is_list($record)) {
        throw new InvalidArgumentException('Тип структуры записи не совпадает.');
    }
    if (!is_array($existing) && gettype($existing) !== gettype($record)) {
        throw new InvalidArgumentException('Нельзя менять тип значения записи.');
    }
}
