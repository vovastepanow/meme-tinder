// core.js — общее для всех вкладок: запросы к API, журнал приложения, вкладки и мелкие DOM-помощники.

export const DEBUG_MODE = new URLSearchParams(window.location.search).get('debug') === '1';

export const $ = id => document.getElementById(id);

// ---- Мелкие DOM-помощники ----

export function show(element, visible = true) {
    (typeof element === 'string' ? $(element) : element).hidden = !visible;
}

export function hide(element) {
    show(element, false);
}

/** Показывает текст в блоке ошибки или статуса; пустой текст прячет блок. */
export function setMessage(element, text) {
    const target = typeof element === 'string' ? $(element) : element;
    target.textContent = text || '';
    target.hidden = !text;
}

/** Создаёт элемент: el('div', { className: 'x', textContent: 'y' }, child1, child2). */
export function el(tag, properties = {}, ...children) {
    const element = Object.assign(document.createElement(tag), properties);
    element.append(...children);
    return element;
}

const TRUSTED_SOURCE_HOSTS = ['2ch.hk', 't.me', 'vk.com'];

/** Ссылка на исходный пост — только HTTPS и только известные площадки (та же проверка, что на сервере). */
export function safeSourcePostUrl(record) {
    if (!record || typeof record !== 'object' || typeof record.source_url !== 'string') return '';
    try {
        const url = new URL(record.source_url);
        return url.protocol === 'https:' && TRUSTED_SOURCE_HOSTS.includes(url.hostname) ? url.href : '';
    } catch {
        return '';
    }
}

export function pluralRu(count, one, few, many) {
    const mod100 = count % 100;
    const mod10 = count % 10;
    if (mod100 >= 11 && mod100 <= 14) return many;
    if (mod10 === 1) return one;
    if (mod10 >= 2 && mod10 <= 4) return few;
    return many;
}

// ---- Журнал приложения ----

const CONSOLE_MAX_LINES = 200;
const consoleState = { lineCount: 0, parserLines: [], followsTail: true };

// Строки обмена с моделью (см. record_ai_exchange в http_client.php):
// "[ИИ → model] запрос", "[ИИ ← model] ответ", "[ИИ мысли model] начало размышлений", "[ИИ мысли+ model] продолжение".
const AI_LOG_LINE_PATTERN = /^\[ИИ (→|←|мысли\+?) ([^\]]+)\] ?([\s\S]*)$/;
const AI_LABELS = { '→': 'запрос →', '←': 'ответ ←', 'мысли': 'размышления' };
const AI_CLASSES = { '→': 'ai-request', '←': 'ai-response', 'мысли': 'ai-thinking' };

/** Блок размышлений, который модель ещё дописывает: продолжения ('мысли+') добавляются в него. */
let liveThinkingEntry = null;

function finishLiveThinking() {
    liveThinkingEntry?.classList.remove('is-live');
    liveThinkingEntry = null;
}

/** Обмен с моделью показывается «пузырём»: запрос, ответ или размышления, свёрнутые до 4 строк. */
function renderAiConsoleEntry(entry, timestamp, direction, model, text) {
    entry.classList.add('ai-message', AI_CLASSES[direction] || 'ai-response');
    entry.title = 'Клик — развернуть или свернуть';
    entry.append(
        el('span', { className: 'ai-message-meta', textContent: `${timestamp} · ${AI_LABELS[direction] || direction} ${model}` }),
        el('span', { className: 'ai-message-text', textContent: text })
    );
    entry.addEventListener('click', () => entry.classList.toggle('is-expanded'));
}

function appendConsoleEntry(entry) {
    const track = $('activity-track');
    track.appendChild(entry);
    consoleState.lineCount++;
    while (consoleState.lineCount > CONSOLE_MAX_LINES) {
        const first = track.firstElementChild;
        if (first === liveThinkingEntry) break;
        first?.remove();
        consoleState.lineCount--;
    }
}

function scrollConsoleToTail() {
    const track = $('activity-track');
    if (consoleState.followsTail) track.scrollTop = track.scrollHeight;
}

/** Добавляет строку в журнал на странице. */
export function log(message) {
    const timestamp = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const aiExchange = AI_LOG_LINE_PATTERN.exec(message);
    if (!aiExchange) {
        appendConsoleEntry(el('p', { className: 'activity-console-line', textContent: `${timestamp} ${message}` }));
        scrollConsoleToTail();
        return;
    }

    const [, direction, model, text] = aiExchange;
    if (direction === 'мысли+' && liveThinkingEntry) {
        liveThinkingEntry.querySelector('.ai-message-text').append(` ${text}`);
        scrollConsoleToTail();
        return;
    }
    if (direction !== 'мысли+') finishLiveThinking();
    const entry = el('p', { className: 'activity-console-line' });
    renderAiConsoleEntry(entry, timestamp, direction === 'мысли+' ? 'мысли' : direction, model, text);
    if (direction.startsWith('мысли')) {
        entry.classList.add('is-live');
        liveThinkingEntry = entry;
    }
    appendConsoleEntry(entry);
    scrollConsoleToTail();
}

export function setConsoleStatus(text, active = false) {
    $('activity-console-status').textContent = text;
    $('activity-console').classList.toggle('is-active', active);
}

/** Начинает новый сеанс журнала парсера: следующая порция строк будет выведена целиком. */
export function resetParserActivity() {
    consoleState.parserLines = [];
}

/**
 * Сервер каждый раз присылает хвост журнала парсера целиком. Выводим только новые строки:
 * ищем самое длинное совпадение конца прошлой порции с началом новой.
 */
export function appendParserActivity(activity) {
    const incoming = Array.isArray(activity) ? activity.filter(line => typeof line === 'string' && line.trim()) : [];
    const previous = consoleState.parserLines;
    let overlap = 0;
    for (let size = Math.min(previous.length, incoming.length); size > 0; size--) {
        const tail = previous.slice(-size);
        if (tail.every((line, index) => line === incoming[index])) {
            overlap = size;
            break;
        }
    }
    consoleState.parserLines = incoming;
    const newLines = incoming.slice(overlap);
    if (!previous.length && !incoming.length) newLines.push('Запуск парсера и загрузка источников...');
    newLines.forEach(log);
}

export function initializeActivityConsole() {
    const track = $('activity-track');
    const followButton = $('activity-console-follow');
    const updateFollowState = () => {
        consoleState.followsTail = track.scrollHeight - track.scrollTop - track.clientHeight <= 24;
        followButton.hidden = consoleState.followsTail;
    };
    track.addEventListener('scroll', updateFollowState, { passive: true });
    followButton.addEventListener('click', () => {
        consoleState.followsTail = true;
        track.scrollTop = track.scrollHeight;
        followButton.hidden = true;
    });
    updateFollowState();
}

// ---- API ----

/** Подписи действий API для журнала. Частые фоновые запросы (get_current_item, parser_status, settings, vk_oauth_status) не логируются. */
const ACTION_LOG_LABELS = {
    refresh_content: 'Запущен поиск контента',
    save_topic: 'Сохранение темы поиска',
    analyze_image: 'Запущен анализ изображения',
    suggest_sources: 'Поиск нового источника',
    vote: 'Сохранение оценки',
    source_vote: 'Сохранение решения по источнику',
    database_add: 'Добавление записи в базу',
    database_update: 'Обновление записи в базе',
    database_delete: 'Удаление записи из базы',
    database_read: 'Загрузка записей базы',
    database_options: 'Загрузка списка баз',
    database_reset: 'Очистка всех баз',
    discover_sources: 'Запуск автоподбора источников',
    debug_reset: 'Сброс демо-данных'
};

export class ApiError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

export function apiUrl(action, params = {}) {
    const query = new URLSearchParams({ action, ...params });
    if (DEBUG_MODE) query.set('debug', '1');
    return `index.php?${query}`;
}

/**
 * Запрос к index.php. body: объект (уйдёт как JSON), FormData или ничего.
 * Возвращает разобранный JSON; при HTTP-ошибке бросает ApiError с сообщением сервера.
 * Строки обмена с моделью из ответа (ai_log) попадают в журнал.
 */
export async function api(action, { body, params } = {}) {
    if (ACTION_LOG_LABELS[action]) log(ACTION_LOG_LABELS[action]);
    const options = { method: body === undefined ? 'GET' : 'POST', headers: {} };
    if (options.method === 'POST') {
        options.headers['X-Content-Tinder'] = '1';
        if (body instanceof FormData) {
            options.body = body;
        } else if (body !== null) {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(body);
        }
    }

    const response = await fetch(apiUrl(action, params), options);
    let data = null;
    try {
        data = await response.json();
    } catch {
        // Сервер вернул не JSON (например, фатальную ошибку PHP) — сообщим статус ниже.
    }
    (Array.isArray(data?.ai_log) ? data.ai_log : []).forEach(log);
    if (!response.ok) throw new ApiError(data?.message || `HTTP ${response.status}`, response.status, data);
    if (data === null) throw new ApiError('Сервер вернул некорректный ответ.', response.status, null);
    return data;
}

// ---- Наблюдение за парсером ----

const PARSER_POLL_ACTIVE_MS = 2000;   // пока парсер работает — часто, чтобы размышления модели шли «вживую»
const PARSER_POLL_IDLE_MS = 15000;    // иначе — редко, только чтобы заметить запуск
const parserMonitor = { timer: null, running: false, inFlight: false, listeners: [] };

/** Подписка на смену состояния парсера: callback(running). */
export function onParserStateChange(listener) {
    parserMonitor.listeners.push(listener);
}

async function pollParser() {
    clearTimeout(parserMonitor.timer);
    if (parserMonitor.inFlight) return; // опрос уже идёт; он сам назначит следующий
    parserMonitor.inFlight = true;
    let running = parserMonitor.running;
    try {
        const data = await api('parser_status');
        running = Boolean(data.running);
        if (running && !parserMonitor.running) resetParserActivity();
        if (running || data.activity?.length) appendParserActivity(data.activity);
    } catch {
        // Сервер временно недоступен — попробуем при следующем опросе.
    }
    if (running !== parserMonitor.running) {
        parserMonitor.running = running;
        if (!running) finishLiveThinking();
        parserMonitor.listeners.forEach(listener => listener(running));
    }
    setConsoleStatus(running ? 'парсер работает' : 'готово', running);
    parserMonitor.inFlight = false;
    parserMonitor.timer = setTimeout(pollParser, running ? PARSER_POLL_ACTIVE_MS : PARSER_POLL_IDLE_MS);
}

/** Запускает опрос парсера; повторный вызов опрашивает сразу (например, после нажатия «Найти контент»). */
export function watchParser() {
    if (DEBUG_MODE) return;
    pollParser();
}

// ---- Вкладки ----

const tabOpenHandlers = {};

/** Регистрирует действие при открытии вкладки (например, загрузку данных). */
export function onTabOpen(tabId, handler) {
    tabOpenHandlers[tabId] = handler;
}

export function switchTab(tabId) {
    const button = document.querySelector(`.tab-btn[data-tab="${tabId}"]`);
    if (!button || button.hidden) return;
    log(`Открыта вкладка: ${button.textContent.trim()}`);
    document.querySelectorAll('.view-panel').forEach(panel => panel.classList.toggle('active', panel.id === tabId));
    document.querySelectorAll('.tabs .tab-btn').forEach(tab => {
        const isActive = tab === button;
        tab.classList.toggle('active', isActive);
        tab.setAttribute('aria-selected', String(isActive));
    });
    tabOpenHandlers[tabId]?.();
}

export function initializeTabs() {
    document.querySelectorAll('.tabs .tab-btn').forEach(button => {
        button.addEventListener('click', () => switchTab(button.dataset.tab));
    });
}
