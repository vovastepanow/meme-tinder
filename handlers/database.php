<?php
// Обработчики вкладки «Базы данных».

function handle_database_options($debug_mode) {
    if ($debug_mode) json_error('Редактирование баз отключено в демо-режиме.', 403);
    $databases = [];
    foreach (database_editor_definitions() as $id => $definition) {
        $collections = [];
        foreach ($definition['collections'] as $collection_id => $label) {
            $collections[] = ['id' => $collection_id, 'label' => $label];
        }
        $databases[] = ['id' => $id, 'label' => $definition['label'], 'collections' => $collections];
    }
    json_response(['databases' => $databases]);
}

function handle_database_read($debug_mode) {
    try {
        require_editable_databases($debug_mode);
        $database = $_GET['database'] ?? '';
        $collection = $_GET['collection'] ?? '';
        [$definition, $data] = database_editor_load($database);
        json_response([
            'database_label' => $definition['label'],
            'collection_label' => $definition['collections'][$collection] ?? '',
            'items' => database_editor_collection($database, $definition, $data, $collection)
        ]);
    } catch (Throwable $error) {
        json_error($error->getMessage());
    }
}

/**
 * Добавление, правка и удаление записи. Для правки и удаления интерфейс присылает номер записи
 * и её прежнее значение (expected): если за это время парсер или другой пользователь изменил
 * коллекцию и под номером уже другая запись, изменение отклоняется, а не портит чужую запись.
 */
function handle_database_change($action, $input, $debug_mode) {
    try {
        require_editable_databases($debug_mode);
        $database = $input['database'] ?? '';
        $collection = $input['collection'] ?? '';
        $definition = database_editor_definition($database);

        $count = with_file_lock($definition['file'], function () use ($action, $input, $database, $collection) {
            [$definition, $data] = database_editor_load($database);
            $before = database_editor_collection($database, $definition, $data, $collection);
            $items = database_editor_apply_change($action, $input, $database, $collection, $before);
            database_editor_save($database, $definition, $data, $collection, $items);
            if ($database === 'content_pool') media_cache_release_removed($before, $items); // удалённые записи не держат файлы
            return count($items);
        });
        json_response(['status' => 'success', 'count' => $count]);
    } catch (DomainException $error) {
        json_error($error->getMessage(), 409);
    } catch (Throwable $error) {
        json_error($error->getMessage());
    }
}

function database_editor_apply_change($action, $input, $database, $collection, array $items) {
    if ($action === 'database_add') {
        $record = database_editor_require_record($input);
        database_editor_validate_record($database, $collection, $record);
        $items[] = $record;
        return $items;
    }

    $index = filter_var($input['index'] ?? null, FILTER_VALIDATE_INT);
    if ($index === false || $index < 0) throw new InvalidArgumentException('Некорректный номер записи.');
    if (!array_key_exists($index, $items)) throw new DomainException('Запись уже удалена или не существует. Обновите список.');
    if (array_key_exists('expected', $input) && $input['expected'] != $items[$index]) {
        throw new DomainException('Запись изменилась с момента загрузки списка. Обновите список и повторите.');
    }

    if ($action === 'database_update') {
        $record = database_editor_require_record($input);
        database_editor_assert_same_shape($items[$index], $record);
        database_editor_validate_record($database, $collection, $record);
        $items[$index] = $record;
    } else {
        array_splice($items, $index, 1);
    }
    return $items;
}

/** Очистка всех баз: пустые пул и история, стандартный каталог источников, пустой кэш медиа. Сразу запускает поиск контента. */
function handle_database_reset($debug_mode) {
    if ($debug_mode) json_error('Очистка баз отключена в демо-режиме.', 403);
    $removed_files = reset_project_data();
    $parser_status = start_content_parser_if_needed(true);
    json_response([
        'status' => 'success',
        'removed_media' => $removed_files,
        'parser' => $parser_status
    ]);
}
