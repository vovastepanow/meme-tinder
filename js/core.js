// core.js — Общие части: состояние журнала, API-адреса, вкладки, журнал приложения и ответы ИИ.
let memeActivityLines = [];

let parserActivityLines = [];

let consoleFollowsTail = true;

const DEBUG_MODE = new URLSearchParams(window.location.search).get('debug') === '1';

function apiUrl(action) {
    const activityLabels = {
        refresh_memes: 'Запущен поиск мемов',
        analyze_image: 'Запущен анализ изображения',
        suggest_sources: 'Поиск нового источника',
        meme_vote: 'Сохранение оценки мема',
        source_vote: 'Сохранение решения по источнику',
        database_add: 'Добавление записи в базу',
        database_update: 'Обновление записи в базе',
        database_delete: 'Удаление записи из базы',
        database_read: 'Загрузка записей базы',
        database_options: 'Загрузка списка баз',
        debug_reset: 'Сброс демо-данных'
    };
    if (activityLabels[action]) appendAppLog(activityLabels[action]);
    return `index.php?action=${action}${DEBUG_MODE ? '&debug=1' : ''}`;
}

function resetMemeActivity() {
    parserActivityLines = [];
}

// Строки вида "[ИИ → model] текст", "[ИИ ← model] текст" и "[ИИ мысли model] текст" (см. record_ai_exchange в http_client.php).
const AI_LOG_LINE_PATTERN = /^\[ИИ (→|←|мысли) ([^\]]+)\] ?([\s\S]*)$/;

const AI_LABELS = { '→': 'запрос →', '←': 'ответ ←', 'мысли': 'размышления' };

const AI_CLASSES = { '→': 'ai-request', '←': 'ai-response', 'мысли': 'ai-thinking' };

/** Lines from record_ai_exchange() become chat-style bubbles: prompt, reply and reasoning, collapsed to 4 lines. */

function renderAiConsoleEntry(entry, timestamp, direction, model, text) {
    entry.classList.add('ai-message', AI_CLASSES[direction] || 'ai-response');
    entry.title = 'Клик — развернуть или свернуть';
    const meta = document.createElement('span');
    meta.className = 'ai-message-meta';
    meta.textContent = `${timestamp} · ${AI_LABELS[direction] || direction} ${model}`;
    const body = document.createElement('span');
    body.className = 'ai-message-text';
    body.textContent = text;
    entry.append(meta, body);
    entry.addEventListener('click', () => entry.classList.toggle('is-expanded'));
}

function appendConsoleLine(message) {
    const track = document.getElementById('meme-activity-track');
    const timestamp = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const entry = document.createElement('p');
    entry.className = 'activity-console-line';
    const aiExchange = AI_LOG_LINE_PATTERN.exec(message);
    if (aiExchange) {
        renderAiConsoleEntry(entry, timestamp, ...aiExchange.slice(1));
    } else {
        entry.textContent = `${timestamp} ${message}`;
    }
    memeActivityLines.push(`${timestamp} ${message}`);
    track.appendChild(entry);

    while (memeActivityLines.length > 200) {
        memeActivityLines.shift();
        track.firstElementChild?.remove();
    }
    if (consoleFollowsTail) track.scrollTop = track.scrollHeight;
}

function appendAppLog(message) {
    appendConsoleLine(message);
}

function logAiExchanges(lines) {
    (Array.isArray(lines) ? lines : []).forEach(line => appendAppLog(line));
}

function appendMemeActivity(activity) {
    const incoming = Array.isArray(activity) ? activity.filter(line => typeof line === 'string' && line.trim()) : [];
    let overlap = 0;
    for (let size = Math.min(parserActivityLines.length, incoming.length); size > 0; size--) {
        const oldTail = parserActivityLines.slice(-size);
        if (oldTail.every((line, index) => line === incoming[index])) {
            overlap = size;
            break;
        }
    }

    const newLines = incoming.slice(overlap);
    parserActivityLines = incoming;
    if (!memeActivityLines.length && !incoming.length) newLines.push('Запуск парсера и загрузка источников...');
    newLines.forEach(appendConsoleLine);
}

function initializeActivityConsole() {
    const track = document.getElementById('meme-activity-track');
    const followButton = document.getElementById('activity-console-follow');
    const updateFollowState = () => {
        consoleFollowsTail = track.scrollHeight - track.scrollTop - track.clientHeight <= 24;
        followButton.hidden = consoleFollowsTail;
    };

    track.addEventListener('scroll', updateFollowState, { passive: true });
    followButton.addEventListener('click', () => {
        consoleFollowsTail = true;
        track.scrollTop = track.scrollHeight;
        followButton.hidden = true;
    });
    updateFollowState();
}

// Переключение между вкладками SPA без перезагрузки страницы
function switchTab(tabId, element) {
    appendAppLog(`Открыта вкладка: ${element.textContent.trim()}`);
    document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tabs .tab-btn').forEach(b => b.classList.remove('active'));

    document.getElementById(tabId).classList.add('active');
    element.classList.add('active');
    if (tabId === 'database-tab') loadDatabaseOptions();
    if (tabId === 'source-tab') loadVkOAuthStatus();
}

function safeSourcePostUrl(record) {
    if (!record || typeof record !== 'object' || typeof record.source_url !== 'string') return '';
    try {
        const url = new URL(record.source_url);
        return url.protocol === 'https:' && ['2ch.hk', 't.me', 'vk.com'].includes(url.hostname) ? url.href : '';
    } catch (error) {
        return '';
    }
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
}
