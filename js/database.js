// database.js — вкладка «Базы данных»: список записей, добавление, правка и удаление.
import { $, DEBUG_MODE, api, el, log, pluralRu, safeSourcePostUrl, watchParser } from './core.js';
import { onAccountSection, openAccountSection } from './account.js';

const state = {
    databases: [],
    ready: false,
    optionsRequest: null,
    items: [],
    editingIndex: null
};

const selectedDatabase = () => $('database-select').value;
const selectedCollection = () => $('database-collection-select').value;

function setStatus(message, isError = false) {
    const status = $('database-status');
    status.textContent = message;
    status.classList.toggle('error-msg', isError);
}

const option = ({ id, label }) => el('option', { value: id, textContent: label });

/** Список баз загружается один раз; параллельные вызовы ждут один и тот же запрос. */
function loadDatabaseOptions() {
    if (state.ready) return Promise.resolve();
    state.optionsRequest ??= fetchDatabaseOptions().finally(() => { state.optionsRequest = null; });
    return state.optionsRequest;
}

async function fetchDatabaseOptions() {
    setStatus('Загружаю базы данных...');
    try {
        const data = await api('database_options');
        state.databases = data.databases || [];
        $('database-select').replaceChildren(...state.databases.map(option));
        state.ready = true;
        await loadCollections();
    } catch (error) {
        setStatus(`Не удалось загрузить базы: ${error.message}`, true);
    }
}

async function loadCollections() {
    const database = state.databases.find(item => item.id === selectedDatabase());
    $('database-collection-select').replaceChildren(...(database?.collections || []).map(option));
    await loadRecords();
}

function recordSummary(record) {
    if (typeof record === 'string') return record;
    if (record === null || typeof record !== 'object') return String(record);
    const preview = record.text || record.name || record.title || record.source || record.url || record.image || '';
    const relevance = record.relevance !== undefined && record.relevance !== null ? `по теме ${record.relevance}%` : '';
    const rating = record.rating ?? record.rating_assigned;
    const details = [rating != null ? `прогноз ${rating}%` : '', relevance, record.topic ? `тема: ${record.topic}` : '', record.source || '', record.why_relevant || '', record.description || '']
        .filter(Boolean)
        .join(' · ');
    return [preview || JSON.stringify(record), details].filter(Boolean).join('\n');
}

function renderRecord(record, index) {
    const actions = el('div', { className: 'database-record-actions' },
        el('button', { type: 'button', className: 'database-edit-button', textContent: 'Редактировать', onclick: () => openEditor(index) })
    );
    const sourceUrl = safeSourcePostUrl(record);
    if (sourceUrl) {
        actions.append(el('a', { className: 'database-source-link', href: sourceUrl, target: '_blank', rel: 'noopener noreferrer', textContent: 'Пост ↗' }));
    }
    actions.append(el('button', { type: 'button', className: 'database-delete-button', textContent: 'Удалить', onclick: () => deleteRecord(index) }));

    return el('article', { className: 'database-record' },
        el('span', { className: 'database-record-number', textContent: `#${index + 1}` }),
        el('div', { className: 'database-record-summary', textContent: recordSummary(record) }),
        actions
    );
}

function renderRecords(items) {
    state.items = items;
    const container = $('database-records');
    if (!items.length) {
        container.replaceChildren(el('div', { className: 'database-empty', textContent: 'В этой коллекции пока нет записей.' }));
        return;
    }
    container.replaceChildren(...items.map(renderRecord));
}

async function loadRecords() {
    const database = selectedDatabase();
    const collection = selectedCollection();
    if (!database || !collection) return;
    setStatus('Загружаю записи...');
    try {
        const data = await api('database_read', { params: { database, collection } });
        const items = data.items || [];
        renderRecords(items);
        const count = `${items.length} ${pluralRu(items.length, 'запись', 'записи', 'записей')}`;
        setStatus(`${data.database_label} · ${data.collection_label} · ${count}`);
    } catch (error) {
        renderRecords([]);
        setStatus(`Не удалось прочитать коллекцию: ${error.message}`, true);
    }
}

/** Заготовка новой записи под выбранную коллекцию. */
function recordTemplate(database, collection) {
    const now = Math.floor(Date.now() / 1000);
    if (database === 'content_pool') {
        return { image: '', text: '', description: '', why_relevant: '', relevance: 0, rating: 0, source: '', board: '', thread_id: '', post_id: '', source_url: '' };
    }
    if (database === 'content_history') {
        return { image: '', text: '', description: '', rating_assigned: 0, source: '', timestamp: now };
    }
    if (collection === '2ch_boards') return 'b';
    if (collection === 'vk_publics' || collection === 'telegram_channels') return { id: '', name: '' };
    if (collection === 'source_votes') return { source_id: '', platform: '', name: '', vote: 'approve', timestamp: now };
    return {};
}

function openDialog(title, record) {
    $('database-dialog-title').textContent = title;
    $('database-record-json').value = JSON.stringify(record, null, 2);
    $('database-edit-dialog').showModal();
}

function openEditor(index) {
    state.editingIndex = index;
    openDialog('Редактировать запись', state.items[index]);
}

function openCreator() {
    state.editingIndex = null;
    openDialog('Добавить запись', recordTemplate(selectedDatabase(), selectedCollection()));
}

/** Открывает редактор с заготовкой новой записи (кнопка «Добавить вручную» на пустом пуле). */
export async function openNewItemEditor() {
    if (DEBUG_MODE) return;
    openAccountSection('databases');
    await loadDatabaseOptions();
    if (!state.ready) return;
    $('database-select').value = 'content_pool';
    await loadCollections();
    openCreator();
}

/** Правка и удаление передают прежнее значение записи, чтобы сервер не изменил запись, сдвинутую парсером. */
function existingRecordPayload(index) {
    return { database: selectedDatabase(), collection: selectedCollection(), index, expected: state.items[index] };
}

async function saveRecord(event) {
    event.preventDefault();
    let record;
    try {
        record = JSON.parse($('database-record-json').value);
    } catch (error) {
        setStatus(`Некорректный JSON: ${error.message}`, true);
        return;
    }

    const isNew = state.editingIndex === null;
    const body = isNew
        ? { database: selectedDatabase(), collection: selectedCollection(), record }
        : { ...existingRecordPayload(state.editingIndex), record };
    try {
        await api(isNew ? 'database_add' : 'database_update', { body });
        $('database-edit-dialog').close();
        state.editingIndex = null;
        await loadRecords();
    } catch (error) {
        setStatus(`Не удалось сохранить запись: ${error.message}`, true);
    }
}

/** Очистка ваших данных: сервер оставляет пустые пул и историю и сразу запускает поиск. Общий каталог источников не трогает. */
async function resetAllDatabases() {
    const question = 'Очистить ваши данные? Пул контента и история оценок станут пустыми, сохранённые картинки удалятся. Тема поиска и общий каталог источников останутся. Это действие нельзя отменить.';
    if (!window.confirm(question)) return;
    const button = $('database-reset');
    button.disabled = true;
    try {
        const data = await api('database_reset', { body: null });
        log(`Базы очищены. Поиск контента: ${data.parser === 'started' || data.parser === 'running' ? 'запущен' : 'не запустился'}.`);
        watchParser();
        onDatabasesReset?.();
        await loadRecords();
    } catch (error) {
        setStatus(`Не удалось очистить базы: ${error.message}`, true);
    } finally {
        button.disabled = false;
    }
}

async function deleteRecord(index) {
    if (!window.confirm(`Удалить запись #${index + 1}? Это действие нельзя отменить.`)) return;
    try {
        await api('database_delete', { body: existingRecordPayload(index) });
        await loadRecords();
    } catch (error) {
        setStatus(`Не удалось удалить запись: ${error.message}`, true);
    }
}

let onDatabasesReset = null;

export function initializeDatabaseTab({ onReset } = {}) {
    onDatabasesReset = onReset;
    if (DEBUG_MODE) return; // раздел «Базы данных» в кабинете скрывает account.js
    $('database-select').addEventListener('change', loadCollections);
    $('database-collection-select').addEventListener('change', loadRecords);
    $('database-refresh').addEventListener('click', loadRecords);
    $('database-add').addEventListener('click', openCreator);
    $('database-edit-form').addEventListener('submit', saveRecord);
    $('database-cancel').addEventListener('click', () => $('database-edit-dialog').close());
    $('database-reset').addEventListener('click', resetAllDatabases);
    onAccountSection('databases', loadDatabaseOptions);
}
