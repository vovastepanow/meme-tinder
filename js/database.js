// database.js — Вкладка «Базы данных»: список, добавление, правка и удаление записей.
let databaseEditorOptions = [];

let databaseEditorReady = false;

let databaseEditingIndex = null;

function setDatabaseStatus(message, isError = false) {
    const status = document.getElementById('database-status');
    status.textContent = message;
    status.classList.toggle('error-msg', isError);
}

async function loadDatabaseOptions() {
    if (databaseEditorReady) return;
    setDatabaseStatus('Загружаю базы данных...');
    try {
        const response = await fetch(apiUrl('database_options'));
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        databaseEditorOptions = data.databases || [];
        const databaseSelect = document.getElementById('database-select');
        databaseSelect.replaceChildren(...databaseEditorOptions.map(database => {
            const option = document.createElement('option');
            option.value = database.id;
            option.textContent = database.label;
            return option;
        }));
        databaseEditorReady = true;
        await loadDatabaseCollections();
    } catch (error) {
        setDatabaseStatus(`Не удалось загрузить базы: ${error.message}`, true);
    }
}

async function loadDatabaseCollections() {
    const database = databaseEditorOptions.find(item => item.id === document.getElementById('database-select').value);
    const collectionSelect = document.getElementById('database-collection-select');
    collectionSelect.replaceChildren(...(database?.collections || []).map(collection => {
        const option = document.createElement('option');
        option.value = collection.id;
        option.textContent = collection.label;
        return option;
    }));
    await loadDatabaseRecords();
}

function databaseRecordSummary(record) {
    if (typeof record === 'string') return record;
    if (record === null || typeof record !== 'object') return String(record);
    const preview = record.text || record.name || record.title || record.source || record.url || record.image || '';
    const funny = typeof record.is_funny === 'boolean' ? `Смешно: ${record.is_funny ? 'да' : 'нет'}` : '';
    const secondary = [record.rating !== undefined ? `${record.rating}%` : '', funny, record.source || '', record.why_funny || '', record.ai_description || '']
        .filter(Boolean).join(' · ');
    return [preview || JSON.stringify(record), secondary].filter(Boolean).join('\n');
}

function databaseRecordCountLabel(count) {
    const remainder100 = count % 100;
    const remainder10 = count % 10;
    const word = remainder100 >= 11 && remainder100 <= 14
        ? 'записей'
        : remainder10 === 1
            ? 'запись'
            : remainder10 >= 2 && remainder10 <= 4
                ? 'записи'
                : 'записей';
    return `${count} ${word}`;
}

function renderDatabaseRecords(items) {
    const container = document.getElementById('database-records');
    container.replaceChildren();
    if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'database-empty';
        empty.textContent = 'В этой коллекции пока нет записей.';
        container.appendChild(empty);
        return;
    }

    items.forEach((record, index) => {
        const row = document.createElement('article');
        row.className = 'database-record';
        const number = document.createElement('span');
        number.className = 'database-record-number';
        number.textContent = `#${index + 1}`;
        const summary = document.createElement('div');
        summary.className = 'database-record-summary';
        summary.textContent = databaseRecordSummary(record);
        const actions = document.createElement('div');
        actions.className = 'database-record-actions';

        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'database-edit-button';
        editButton.textContent = 'Редактировать';
        editButton.addEventListener('click', () => openDatabaseEditor(index, record));

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'database-delete-button';
        deleteButton.textContent = 'Удалить';
        deleteButton.addEventListener('click', () => deleteDatabaseRecord(index));

        actions.append(editButton);
        const sourceUrl = safeSourcePostUrl(record);
        if (sourceUrl) {
            const sourceLink = document.createElement('a');
            sourceLink.className = 'database-source-link';
            sourceLink.href = sourceUrl;
            sourceLink.target = '_blank';
            sourceLink.rel = 'noopener noreferrer';
            sourceLink.textContent = 'Пост ↗';
            actions.appendChild(sourceLink);
        }
        actions.append(deleteButton);
        row.append(number, summary, actions);
        container.appendChild(row);
    });
}

async function loadDatabaseRecords() {
    const database = document.getElementById('database-select').value;
    const collection = document.getElementById('database-collection-select').value;
    if (!database || !collection) return;
    setDatabaseStatus('Загружаю записи...');
    try {
        const query = `&database=${encodeURIComponent(database)}&collection=${encodeURIComponent(collection)}`;
        const response = await fetch(`${apiUrl('database_read')}${query}`);
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        renderDatabaseRecords(data.items || []);
        setDatabaseStatus(`${data.database_label} · ${data.collection_label} · ${databaseRecordCountLabel(data.items.length)}`);
    } catch (error) {
        renderDatabaseRecords([]);
        setDatabaseStatus(`Не удалось прочитать коллекцию: ${error.message}`, true);
    }
}

function openDatabaseEditor(index, record) {
    databaseEditingIndex = index;
    document.getElementById('database-dialog-title').textContent = 'Редактировать запись';
    document.getElementById('database-record-json').value = JSON.stringify(record, null, 2);
    document.getElementById('database-edit-dialog').showModal();
}

function databaseRecordTemplate(database, collection) {
    if (database === 'memes_pool') {
        return { image: '', text: '', ai_description: '', why_funny: '', is_funny: null, meme_probability: 0, rating: 0, source: '', board: '', thread_id: '', post_id: '', source_url: '' };
    }
    if (database === 'meme_history') {
        return {
            image: '',
            text: '',
            ai_short_description: '',
            rating_assigned: 0,
            source: '',
            timestamp: Math.floor(Date.now() / 1000)
        };
    }
    if (database === 'sources_db' && collection === '2ch_boards') return 'b';
    if (database === 'sources_db' && ['vk_publics', 'telegram_channels'].includes(collection)) {
        return { id: '', name: '' };
    }
    if (database === 'sources_db' && collection === 'source_votes') {
        return { source_id: '', platform: '', name: '', vote: 'approve', timestamp: Math.floor(Date.now() / 1000) };
    }
    return {};
}

function openDatabaseCreator() {
    databaseEditingIndex = null;
    document.getElementById('database-dialog-title').textContent = 'Добавить запись';
    document.getElementById('database-record-json').value = JSON.stringify(
        databaseRecordTemplate(
            document.getElementById('database-select').value,
            document.getElementById('database-collection-select').value
        ),
        null,
        2
    );
    document.getElementById('database-edit-dialog').showModal();
}

async function openEmptyMemeEditor() {
    const tabButton = document.querySelector('[data-tab-id="database-tab"]');
    if (DEBUG_MODE || !tabButton) return;
    switchTab('database-tab', tabButton);
    await loadDatabaseOptions();
    if (!databaseEditorReady) return;
    document.getElementById('database-select').value = 'memes_pool';
    await loadDatabaseCollections();
    openDatabaseCreator();
}

async function saveDatabaseRecord(event) {
    event.preventDefault();
    let record;
    try {
        record = JSON.parse(document.getElementById('database-record-json').value);
    } catch (error) {
        setDatabaseStatus(`Некорректный JSON: ${error.message}`, true);
        return;
    }

    try {
        const action = databaseEditingIndex === null ? 'database_add' : 'database_update';
        const payload = {
            database: document.getElementById('database-select').value,
            collection: document.getElementById('database-collection-select').value,
            record
        };
        if (databaseEditingIndex !== null) payload.index = databaseEditingIndex;
        const response = await fetch(apiUrl(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        document.getElementById('database-edit-dialog').close();
        databaseEditingIndex = null;
        await loadDatabaseRecords();
    } catch (error) {
        setDatabaseStatus(`Не удалось сохранить запись: ${error.message}`, true);
    }
}

async function deleteDatabaseRecord(index) {
    if (!window.confirm(`Удалить запись #${index + 1}? Это действие нельзя отменить.`)) return;
    try {
        const response = await fetch(apiUrl('database_delete'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                database: document.getElementById('database-select').value,
                collection: document.getElementById('database-collection-select').value,
                index
            })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        await loadDatabaseRecords();
    } catch (error) {
        setDatabaseStatus(`Не удалось удалить запись: ${error.message}`, true);
    }
}
