<?php
// Функции доступа к данным: замена load_data/update_data для всех таблиц.

// ---- Пользователи и настройки ----

/** Загрузить настройки пользователя из БД (raw, без нормализации). */
function db_load_settings($uid = null): array {
    $uid = $uid ?: app_user_id();
    $settings = db_value('SELECT settings FROM users WHERE id = ?', [$uid]);
    return db_unjson($settings, default_settings());
}

/** Сохранить настройки пользователя в БД (raw). */
function db_save_settings(array $settings, $uid = null): void {
    $uid = $uid ?: app_user_id();
    db_tx(function () use ($uid, $settings) {
        db_exec('INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)', [$uid, time()]);
        db_exec('UPDATE users SET settings = ? WHERE id = ?', [db_json($settings), $uid]);
    });
}


/** Обновить настройки через мутатор: внутри db_tx. */
function update_settings(callable $fn, $uid = null): array {
    $uid = $uid ?: app_user_id();
    return db_tx(function () use ($uid, $fn) {
        $current = load_settings($uid);
        $updated = $fn($current);
        if ($updated !== null) {
            save_settings($updated, $uid);
            return $updated;
        }
        return $current;
    });
}


/** Список всех пользователей. */
function user_ids(): array {
    return array_column(db_all('SELECT id FROM users ORDER BY last_seen DESC'), 'id');
}

/** Обновить proposals_checked_at. */
function user_proposals_checked($uid = null): void {
    $uid = $uid ?: app_user_id();
    db_exec('UPDATE users SET proposals_checked_at = ? WHERE id = ?', [time(), $uid]);
}

/** Обновить threads_checked_at. */
function user_threads_checked($uid = null): void {
    $uid = $uid ?: app_user_id();
    db_exec('UPDATE users SET threads_checked_at = ? WHERE id = ?', [time(), $uid]);
}

// ---- Пул записей ----

/** Загрузить пул пользователя как массив записей (в порядке position). */
function pool_load($uid = null): array {
    $uid = $uid ?: app_user_id();
    $rows = db_all('SELECT data FROM pool_items WHERE user_id = ? ORDER BY position', [$uid]);
    return array_map(fn($r) => db_unjson($r['data'], []), $rows);
}

/** Заменить пул пользователя целиком (через db_tx). */
function pool_update(callable $fn, $uid = null): array {
    $uid = $uid ?: app_user_id();
    return db_tx(function () use ($uid, $fn) {
        db_exec('INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)', [$uid, time()]);
        $current = pool_load($uid);
        $updated = $fn($current);
        if ($updated === null) return $current;

        db_exec('DELETE FROM pool_items WHERE user_id = ?', [$uid]);
        foreach ($updated as $pos => $item) {
            $key = content_image_key($item);
            db_exec('INSERT INTO pool_items (user_id, position, image_key, data) VALUES (?, ?, ?, ?)',
                    [$uid, $pos, $key, db_json($item)]);
        }
        return $updated;
    });
}

// ---- История голосования ----

/** Загрузить историю в формате [collection => [...]]. */
function history_load($uid = null): array {
    $uid = $uid ?: app_user_id();
    $history = empty_history();

    foreach (HISTORY_COLLECTIONS as $collection) {
        $rows = db_all('SELECT data FROM history_items WHERE user_id = ? AND collection = ? ORDER BY position',
                      [$uid, $collection]);
        $history[$collection] = array_map(fn($r) => db_unjson($r['data'], []), $rows);
    }
    return $history;
}

/** Обновить историю целиком (через db_tx). */
function history_update(callable $fn, $uid = null): array {
    $uid = $uid ?: app_user_id();
    return db_tx(function () use ($uid, $fn) {
        db_exec('INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)', [$uid, time()]);
        $current = history_load($uid);
        $updated = $fn($current);
        if ($updated === null) return $current;

        db_exec('DELETE FROM history_items WHERE user_id = ?', [$uid]);
        foreach (HISTORY_COLLECTIONS as $collection) {
            foreach ($updated[$collection] ?? [] as $pos => $item) {
                $key = content_image_key($item);
                $hidden = (!empty($item['proposal_hidden']) ? 1 : 0);
                db_exec('INSERT INTO history_items (user_id, collection, position, image_key, proposal_hidden, data)
                        VALUES (?, ?, ?, ?, ?, ?)',
                       [$uid, $collection, $pos, $key, $hidden, db_json($item)]);
            }
        }
        return $updated;
    });
}

/** Добавить одну запись в историю (для голосования). */
function history_append($collection, array $record, $uid = null): void {
    $uid = $uid ?: app_user_id();
    db_tx(function () use ($uid, $collection, $record) {
        db_exec('INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)', [$uid, time()]);
        $pos = db_value('SELECT COALESCE(MAX(position), -1) + 1 FROM history_items WHERE user_id = ? AND collection = ?',
                       [$uid, $collection]) ?? 0;
        $key = content_image_key($record);
        $hidden = (!empty($record['proposal_hidden']) ? 1 : 0);
        db_exec('INSERT INTO history_items (user_id, collection, position, image_key, proposal_hidden, data)
                VALUES (?, ?, ?, ?, ?, ?)',
               [$uid, $collection, $pos, $key, $hidden, db_json($record)]);
    });
}

/** Для редактора баз и отбора: все записи пула и скрытые в истории по всем пользователям. */
function all_users_pool_items(): array {
    $items = [];

    // Пул
    $rows = db_all('SELECT user_id, data FROM pool_items ORDER BY user_id, position');
    foreach ($rows as $r) {
        $items[] = db_unjson($r['data'], []);
    }

    // История (предложки, скрытые)
    $proposal_cols = implode(',', array_map(fn($c) => "'$c'", PROPOSAL_COLLECTIONS));
    $rows = db_all("SELECT data FROM history_items WHERE collection IN ($proposal_cols) AND proposal_hidden = 0");
    foreach ($rows as $r) {
        $items[] = db_unjson($r['data'], []);
    }

    return $items;
}

// ---- Соединения (Telegram, VK) ----

/** Загрузить connections.json. */
function connections_load($uid = null): array {
    $uid = $uid ?: app_user_id();
    $data = db_value('SELECT connections FROM users WHERE id = ?', [$uid]);
    return db_unjson($data, []);
}

/** Обновить connections. */
function connections_update(callable $fn, $uid = null): array {
    $uid = $uid ?: app_user_id();
    return db_tx(function () use ($uid, $fn) {
        db_exec('INSERT OR IGNORE INTO users (id, created_at) VALUES (?, ?)', [$uid, time()]);
        $current = connections_load($uid);
        $updated = $fn($current);
        if ($updated === null) return $current;
        db_exec('UPDATE users SET connections = ? WHERE id = ?', [db_json($updated), $uid]);
        return $updated;
    });
}
