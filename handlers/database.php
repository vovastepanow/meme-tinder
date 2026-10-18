<?php
// Обработчики редактора баз данных.
function handle_database_options($debug_mode) {
    if ($debug_mode) json_response(['message' => 'Редактирование баз отключено в демо-режиме.'], 403);
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
        $items = database_editor_collection($database, $definition, $data, $collection);
        json_response([
            'database_label' => $definition['label'],
            'collection_label' => $definition['collections'][$collection],
            'items' => $items
        ]);
    } catch (Throwable $error) {
        json_response(['message' => $error->getMessage()], 400);
    }
}
function handle_database_change($action, $input, $debug_mode) {
    try {
        require_editable_databases($debug_mode);
        if (!is_array($input)) throw new InvalidArgumentException('Некорректный запрос.');
        $database = $input['database'] ?? '';
        $collection = $input['collection'] ?? '';
        [$definition, $data] = database_editor_load($database);
        $items = database_editor_collection($database, $definition, $data, $collection);

        if ($action === 'database_add') {
            $record = database_editor_require_record($input);
            database_editor_validate_record($database, $collection, $record);
            $items[] = $record;
        } else {
            $index = filter_var($input['index'] ?? null, FILTER_VALIDATE_INT);
            if ($index === false || $index < 0) throw new InvalidArgumentException('Некорректный номер записи.');
            if (!array_key_exists($index, $items)) throw new OutOfBoundsException('Запись уже удалена или не существует.');

            if ($action === 'database_update') {
                $record = database_editor_require_record($input);
                database_editor_assert_same_shape($items[$index], $record);
                database_editor_validate_record($database, $collection, $record);
                $items[$index] = $record;
            } else {
                array_splice($items, $index, 1);
            }
        }

        database_editor_save($database, $definition, $data, $collection, $items);
        json_response(['status' => 'success', 'count' => count($items)]);
    } catch (Throwable $error) {
        json_response(['status' => 'error', 'message' => $error->getMessage()], 400);
    }
}
