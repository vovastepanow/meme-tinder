// account.js — личный кабинет: профиль, подключение соцсетей (VK для всего сайта, Telegram — свой канал),
// «Мои данные и куки» (что сайт хранит о вас: куки, хранилище браузера, файлы на сервере) и раздел «Базы данных».
import { $, DEBUG_MODE, api, apiUrl, el, log, onTabOpen, pluralRu, switchTab } from './core.js';

const VK_REDIRECT_MESSAGES = {
    connected: 'VK подключён.',
    misconfigured: 'Настройте VK_CLIENT_ID и VK_REDIRECT_URI на сервере.',
    error: 'Не удалось подключить VK. Проверьте настройки OAuth и журнал PHP.'
};
const COLLECTION_LABELS = { liked: 'Нравится', disliked: 'Не нравится', undecided: 'Не знаю', complaints: 'Жалобы' };

let currentSection = 'profile';
let pendingVkRedirectMessage = '';   // итог входа через VK ID показывается вместо обычного статуса
const sectionHandlers = {};

// ---- Разделы ----

/** Другие модули (например, database.js) подписываются на открытие своего раздела. */
export function onAccountSection(name, handler) {
    sectionHandlers[name] = handler;
}

export function openAccountSection(name) {
    if (!$('account-tab').classList.contains('active')) {
        currentSection = name;
        switchTab('account-tab');   // switchTab сам вызовет показ текущего раздела
        return;
    }
    currentSection = name;
    document.querySelectorAll('.account-nav [data-account-section]').forEach(button => {
        button.classList.toggle('is-active', button.dataset.accountSection === name);
    });
    document.querySelectorAll('.account-section').forEach(section => { section.hidden = section.dataset.section !== name; });
    sectionHandlers[name]?.();
}

// ---- Форматирование ----

function formatBytes(bytes) {
    if (bytes < 1024) return `${bytes} Б`;
    if (bytes < 1048576) return `${(bytes / 1024).toFixed(1)} КБ`;
    return `${(bytes / 1048576).toFixed(1)} МБ`;
}

function formatDate(seconds) {
    if (!seconds) return '—';
    return new Date(seconds * 1000).toLocaleString('ru-RU', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' });
}

/** Значение, скрытое до клика: начало…конец, по клику — целиком. */
function revealable(value) {
    const text = String(value);
    const short = text.length > 14 ? `${text.slice(0, 6)}…${text.slice(-4)}` : text;
    const span = el('button', { type: 'button', className: 'privacy-value', textContent: short, title: 'Показать целиком' });
    span.addEventListener('click', () => {
        const full = span.textContent === short;
        span.textContent = full ? text : short;
        span.title = full ? 'Скрыть' : 'Показать целиком';
    });
    return text.length > 14 ? span : el('code', { className: 'privacy-value', textContent: text || '(пусто)' });
}

function table(headers, rows, emptyText) {
    if (!rows.length) return el('p', { className: 'account-muted', textContent: emptyText });
    return el('table', { className: 'privacy-table' },
        el('thead', {}, el('tr', {}, ...headers.map(header => el('th', { textContent: header })))),
        el('tbody', {}, ...rows.map(cells => el('tr', {}, ...cells.map(cell => el('td', {}, cell))))));
}

// ---- Профиль ----

function stat(value, label) {
    return el('div', { className: 'account-stat' }, el('strong', { textContent: String(value) }), el('span', { textContent: label }));
}

function renderProfile(info) {
    const counts = info.counts || {};
    const votes = Object.values(counts).reduce((sum, count) => sum + count, 0);
    const copy = el('button', { type: 'button', className: 'account-secondary', textContent: 'Скопировать ID' });
    copy.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(info.user_id);
            copy.textContent = 'Скопировано';
        } catch {
            copy.textContent = 'Не удалось скопировать';
        }
        setTimeout(() => { copy.textContent = 'Скопировать ID'; }, 1500);
    });
    $('account-profile-body').replaceChildren(
        el('div', { className: 'account-profile-head' },
            el('div', { className: 'account-avatar', textContent: info.user_id.slice(0, 2).toUpperCase(), ariaHidden: 'true' }),
            el('div', {},
                el('h3', { textContent: 'Ваш профиль' }),
                el('p', { className: 'account-muted' }, 'ID ', revealable(info.user_id)),
                el('p', { className: 'account-muted', textContent: `Тема поиска: «${info.topic}» · последний заход: ${formatDate(info.last_seen)}` })),
            copy),
        el('div', { className: 'account-stats' },
            stat(info.pool, `${pluralRu(info.pool, 'запись', 'записи', 'записей')} ждёт оценки`),
            stat(votes, pluralRu(votes, 'оценка', 'оценки', 'оценок')),
            ...Object.entries(COLLECTION_LABELS).map(([key, label]) => stat(counts[key] ?? 0, label))),
        el('p', { className: 'account-muted', textContent: `Профиль держится на куке в этом браузере. В другом браузере сайт заведёт новый профиль. Профили без заходов дольше ${info.retention_days} дней удаляются.` })
    );
}

// ---- Соцсети ----

async function loadVkOAuthStatus() {
    const button = $('vk-oauth-button');
    const status = $('vk-oauth-status');
    try {
        const data = await api('vk_oauth_status');
        button.textContent = data.connected ? 'Переподключить VK' : 'Подключить VK';
        status.textContent = pendingVkRedirectMessage || (!data.configured
            ? 'Для OAuth задайте VK_CLIENT_ID и VK_REDIRECT_URI на сервере.'
            : data.connected
                ? `VK подключён${data.user_id ? ` · ID ${data.user_id}` : ''}.`
                : 'Подключите VK, чтобы подбирать паблики.');
        pendingVkRedirectMessage = '';
    } catch (error) {
        status.textContent = `Статус VK недоступен: ${error.message}`;
    } finally {
        button.disabled = false;
    }
}

/** После возврата со страницы VK ID адрес содержит ?vk=<результат>: показываем его и чистим адрес. */
export function consumeVkRedirectResult() {
    const result = new URLSearchParams(window.location.search).get('vk');
    if (!result) return false;
    const url = new URL(window.location.href);
    url.searchParams.delete('vk');
    url.hash = '';
    window.history.replaceState({}, '', url);
    pendingVkRedirectMessage = VK_REDIRECT_MESSAGES[result] || VK_REDIRECT_MESSAGES.error;
    $('vk-oauth-status').textContent = pendingVkRedirectMessage;
    return true;
}

function renderTelegram(telegram) {
    $('telegram-connected').hidden = !telegram;
    $('telegram-form').hidden = Boolean(telegram);
    if (!telegram) return;
    const channel = telegram.chat_username ? `@${telegram.chat_username}` : telegram.chat_title;
    $('telegram-summary').replaceChildren(
        el('strong', { textContent: `Подключён канал «${telegram.chat_title || channel}»` }),
        el('span', { className: 'account-muted', textContent: `${channel ? `${channel} · ` : ''}бот @${telegram.bot} · токен ${telegram.token} · с ${formatDate(telegram.connected_at)}` })
    );
}

/** Очередь отложенных постов и последние отправленные: что, когда и как ушло в канал. */
async function loadTelegramQueue() {
    const box = $('telegram-queue');
    let data;
    try {
        data = await api('telegram_queue');
    } catch {
        return;   // очередь — дополнение: без неё кабинет работает
    }
    const scheduled = data.scheduled || [];
    const published = data.published || [];
    box.hidden = !data.connected || (!scheduled.length && !published.length);
    if (box.hidden) return;
    const sections = [];
    if (scheduled.length) {
        sections.push(el('h4', { textContent: `Запланировано: ${scheduled.length}` }), ...scheduled.map(post => {
            const state = post.status === 'telegram' ? 'в «Отложенных» Telegram — уйдёт само' : post.status === 'failed' ? `не отправлено (${post.error})` : post.status === 'sending' ? 'отправляется…'
                : post.error ? `будет повтор ${formatDate(post.publish_at)}: ${post.error}` : 'ждёт времени';
            const cancel = el('button', { type: 'button', className: 'account-secondary', textContent: post.status === 'sending' ? '…' : 'Отменить', disabled: post.status === 'sending' });
            cancel.addEventListener('click', async () => {
                cancel.disabled = true;
                try { await api('telegram_cancel', { body: { id: post.id } }); log('Отложенный пост отменён.'); } catch (error) { log(`Не удалось отменить пост: ${error.message}`); }
                loadTelegramQueue();
            });
            return el('div', { className: 'queue-row' }, el('div', { className: 'queue-main' },
                el('strong', { textContent: formatDate(post.publish_at) }),
                el('span', { textContent: `${post.media} ${pluralRu(post.media, 'файл', 'файла', 'файлов')}${post.text ? ' · ' + post.text : ''}` }),
                el('span', { className: `account-muted${post.status === 'failed' || post.error ? ' queue-error' : ''}`, textContent: state })), cancel);
        }));
    }
    if (published.length) {
        sections.push(el('h4', { textContent: 'Последние отправленные' }), ...published.map(entry => el('div', { className: 'queue-row' }, el('div', { className: 'queue-main' },
            el('strong', { textContent: formatDate(entry.at) }),
            el('span', { textContent: `${entry.media} ${pluralRu(entry.media, 'файл', 'файла', 'файлов')}${entry.text ? ' · ' + entry.text : ''}` }),
            entry.status === 'ok'
                ? (entry.url ? el('a', { href: entry.url, target: '_blank', rel: 'noopener noreferrer', textContent: 'Открыть в Telegram ↗' }) : el('span', { className: 'account-muted', textContent: 'отправлено' }))
                : el('span', { className: 'account-muted queue-error', textContent: `не отправлено: ${entry.error}` })))));
    }
    box.replaceChildren(...sections);
}

/** Предупреждение, если сервер отдаёт служебные файлы напрямую (Apache не читает .htaccess), и готовая команда для исправления. */
function renderExposure(info) {
    const box = $('account-exposure');
    const exposed = info.exposed || [];
    box.hidden = exposed.length === 0;
    if (!exposed.length) return;
    box.replaceChildren(
        el('strong', { textContent: 'Защита данных не работает' }),
        el('p', { className: 'account-muted', textContent: `Apache не читает .htaccess, поэтому по HTTP отдаются служебные файлы: ${exposed.join(', ')}. Это каталог источников, история оценок и сам репозиторий (.git) — их может скачать любой, кто видит сайт в сети. Токены ботов на диске зашифрованы. Чтобы закрыть всё, выполните один раз в терминале:` }),
        el('code', { className: 'account-command', textContent: info.fix_command || 'sudo sh tools/apache-setup.sh' }));
}

// ---- Отложка средствами Telegram (личный аккаунт) ----

function renderTelegramUser(info, botConnected) {
    $('tgu-card').hidden = !botConnected;
    if (!botConnected) return;
    // Ключи приложения уже сохранены на сервере (их вводят один раз): поля и шаг про my.telegram.org не нужны
    const keysSaved = Boolean(info?.app_keys);
    $('tgu-keys').hidden = keysSaved;
    $('tgu-step-keys').hidden = keysSaved;
    $('tgu-api-id').required = !keysSaved;
    $('tgu-api-hash').required = !keysSaved;
    const connected = Boolean(info?.connected);
    const awaiting = !connected && Boolean(info?.awaiting_code);
    $('tgu-connected').hidden = !connected;
    $('tgu-start').hidden = connected || awaiting || !$('tgu-qr').hidden;
    $('tgu-confirm').hidden = !awaiting;
    if (connected) {
        $('tgu-summary').replaceChildren(
            el('strong', { textContent: `Аккаунт ${info.name || ''}${info.username ? ' @' + info.username : ''} подключён` }),
            el('span', { className: 'account-muted', textContent: `с ${formatDate(info.connected_at)} · отложенные посты ставятся в «Отложенные» канала` }),
            ...(info.warning ? [el('span', { className: 'queue-error', textContent: info.warning })] : []));
    } else if (awaiting && info.qr) {
        $('tgu-password-row').hidden = false;
        $('tgu-resend').hidden = true;
        $('tgu-code').closest('label').hidden = true;
        $('tgu-status').textContent = 'Вход по QR принят. В аккаунте включена двухэтапная защита: введите пароль.';
    } else if (awaiting) {
        $('tgu-code').closest('label').hidden = false;
        const where = {
            app: 'в приложение Telegram: ищите сообщение от «Telegram» (служебные уведомления) в чатах на телефоне или компьютере, где вы уже вошли',
            sms: 'по SMS', call: 'голосовым звонком', flashcall: 'звонком (последние цифры номера — код)', missedcall: 'звонком (последние цифры номера — код)'
        }[info.code_type] || 'способом, который выбрал Telegram';
        $('tgu-status').textContent = `Код отправлен на ${info.phone} ${where}.`;
        const resend = $('tgu-resend');
        resend.hidden = !info.next_type;
        resend.textContent = { sms: 'Отправить код по SMS', call: 'Позвонить с кодом', flashcall: 'Код звонком', missedcall: 'Код звонком' }[info.next_type] || 'Запросить код другим способом';
    }
}

/**
 * Телефон в международный вид: «8 (999) 123-45-67», «9991234567», «7 999…» → «+7 999 123-45-67». Номера других стран
 * (с «+» в начале) остаются как есть, только без лишних знаков. Пустое и непохожее на номер не трогаем.
 */
export function normalizePhone(value) {
    const raw = String(value || '').trim();
    const digits = raw.replace(/\D/g, '');
    if (!digits) return raw;
    if (raw.startsWith('+') && !digits.startsWith('7')) return `+${digits}`;
    let national = digits;
    if (digits.length === 11 && (digits[0] === '7' || digits[0] === '8')) national = digits.slice(1);
    else if (digits.length !== 10) return raw.startsWith('+') ? `+${digits}` : raw;   // непонятная длина: не угадываем
    return `+7 ${national.slice(0, 3)} ${national.slice(3, 6)}-${national.slice(6, 8)}-${national.slice(8)}`;
}

async function startTelegramUser(event) {
    event.preventDefault();
    const button = event.submitter;
    $('tgu-phone').value = normalizePhone($('tgu-phone').value);
    button.disabled = true;
    $('tgu-status').textContent = 'Запрашиваю код у Telegram…';
    try {
        const data = await api('telegram_user_start', { body: { api_id: $('tgu-api-id').value, api_hash: $('tgu-api-hash').value, phone: $('tgu-phone').value } });
        $('tgu-api-id').value = '';
        $('tgu-api-hash').value = '';
        $('tgu-password-row').hidden = true;
        renderTelegramUser(data.telegram_user, true);
    } catch (error) {
        $('tgu-status').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

let qrTimer = 0;

function stopQr() {
    clearInterval(qrTimer);
    qrTimer = 0;
    $('tgu-qr').hidden = true;
}

/** Вход по QR: показывает код, раз в две секунды спрашивает сервер, отсканировали ли его. */
async function startTelegramQr() {
    $('tgu-status').textContent = 'Готовлю QR-код…';
    try {
        await api('telegram_user_qr_start', { body: { api_id: $('tgu-api-id').value, api_hash: $('tgu-api-hash').value } });
    } catch (error) {
        $('tgu-status').textContent = error.message;
        return;
    }
    $('tgu-start').hidden = true;
    $('tgu-qr').hidden = false;
    $('tgu-qr-image').replaceChildren();
    $('tgu-status').textContent = 'Жду, пока вы отсканируете код в приложении Telegram…';
    clearInterval(qrTimer);
    let polling = false;   // следующий запрос не уходит, пока не вернулся предыдущий (проверка канала занимает время)
    qrTimer = setInterval(async () => {
        if (polling) return;
        polling = true;
        let data;
        try { data = await api('telegram_user_qr_status'); } catch (error) { return; } finally { polling = false; }
        if (data.status === 'busy') return;
        if (data.status === 'waiting') {
            if (data.svg) $('tgu-qr-image').replaceChildren(el('img', { src: `data:image/svg+xml;charset=utf-8,${encodeURIComponent(data.svg)}`, alt: 'QR-код', width: 220, height: 220 }));
            return;
        }
        stopQr();
        if (data.status === 'success') {
            $('tgu-status').textContent = '';
            renderTelegramUser(data.telegram_user, true);
            log('Telegram: аккаунт подключён по QR-коду, отложенные посты теперь ставятся в «Отложенные» канала.');
        } else if (data.status === 'needs_password') {
            loadAccount();
        } else {
            $('tgu-start').hidden = false;
            $('tgu-status').textContent = data.status === 'expired' ? 'QR-код устарел: запросите новый.'
                : data.status === 'none' ? 'Вход по QR прервался: запросите код ещё раз.' : (data.error || 'Не удалось войти по QR-коду.');
            log(`Telegram, вход по QR: ${data.status === 'error' ? data.error : data.status}`);
        }
    }, 2000);
}

async function resendTelegramUser() {
    const button = $('tgu-resend');
    button.disabled = true;
    $('tgu-status').textContent = 'Запрашиваю код другим способом…';
    try {
        const data = await api('telegram_user_resend', { body: null });
        renderTelegramUser(data.telegram_user, true);
    } catch (error) {
        $('tgu-status').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function confirmTelegramUser(event) {
    event.preventDefault();
    const button = event.submitter;
    button.disabled = true;
    $('tgu-status').textContent = 'Проверяю код и права в канале…';
    try {
        const data = await api('telegram_user_confirm', { body: { code: $('tgu-code').value, password: $('tgu-password').value } });
        if (data.status === 'needs_password') {
            $('tgu-password-row').hidden = false;
            $('tgu-password').focus();
            $('tgu-status').textContent = 'В аккаунте включена двухэтапная защита: введите пароль.';
            return;
        }
        $('tgu-code').value = '';
        $('tgu-password').value = '';
        $('tgu-status').textContent = '';
        renderTelegramUser(data.telegram_user, true);
        log('Telegram: аккаунт подключён, отложенные посты теперь ставятся в «Отложенные» канала.');
    } catch (error) {
        $('tgu-status').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function disconnectTelegramUser() {
    try {
        const data = await api('telegram_user_disconnect', { body: null });
        renderTelegramUser(data.telegram_user, true);
        $('tgu-status').textContent = 'Отключено. Чтобы завершить вход совсем, закройте сессию «Postmaker» в Telegram → Настройки → Устройства.';
        log('Аккаунт Telegram отключён: отложенные посты снова идут через очередь приложения.');
    } catch (error) {
        $('tgu-status').textContent = error.message;
    }
}

async function connectTelegram(event) {
    event.preventDefault();
    const button = $('telegram-connect');
    button.disabled = true;
    $('telegram-status').textContent = 'Проверяю бота и канал в Telegram…';
    try {
        const data = await api('telegram_connect', { body: { token: $('telegram-token').value, channel: $('telegram-channel').value } });
        $('telegram-token').value = '';
        $('telegram-status').textContent = '';
        renderTelegram(data.telegram);
        loadAccount();
        loadTelegramQueue();
        log(`Telegram: подключён канал «${data.telegram.chat_title}».`);
    } catch (error) {
        $('telegram-status').textContent = error.message;
    } finally {
        button.disabled = false;
    }
}

async function disconnectTelegram() {
    const button = $('telegram-disconnect');
    // Подтверждение прямо в кнопке: первое нажатие спрашивает, второе отключает
    if (button.dataset.armed !== '1') {
        button.dataset.armed = '1';
        button.textContent = 'Точно отключить?';
        setTimeout(() => { button.dataset.armed = ''; button.textContent = 'Отключить'; }, 4000);
        return;
    }
    button.disabled = true;
    try {
        await api('telegram_disconnect', { body: null });
        renderTelegram(null);
        log('Telegram отключён.');
    } catch (error) {
        $('telegram-status').textContent = error.message;
    } finally {
        button.disabled = false;
        button.dataset.armed = '';
        button.textContent = 'Отключить';
    }
}

// ---- Мои данные и куки ----

/** Куки, видимые скриптам (без HttpOnly): имя → значение. */
function scriptCookies() {
    return Object.fromEntries(document.cookie.split(';').map(part => part.trim()).filter(Boolean).map(part => {
        const index = part.indexOf('=');
        return [decodeURIComponent(part.slice(0, index)), decodeURIComponent(part.slice(index + 1))];
    }));
}

function renderCookies(serverCookies) {
    const visible = scriptCookies();
    const rows = serverCookies.map(cookie => [
        el('code', { textContent: cookie.name }),
        revealable(cookie.value),
        cookie.lifetime,
        cookie.http_only ? el('span', { className: 'privacy-badge', textContent: 'HttpOnly', title: 'Скрипты страницы её не видят — только сервер' }) : 'видна скриптам',
        cookie.purpose
    ]);
    // Куки, которые видит страница, но сервер не получил (например, поставленные для другого пути)
    for (const [name, value] of Object.entries(visible)) {
        if (serverCookies.some(cookie => cookie.name === name)) continue;
        rows.push([el('code', { textContent: name }), revealable(value), '—', 'видна скриптам', 'Есть в браузере, но сервер её не получает.']);
    }
    $('privacy-cookies').replaceChildren(table(['Кука', 'Значение', 'Срок', 'Доступ', 'Зачем'], rows, 'Браузер не отправляет этому сайту ни одной куки.'));
}

function renderStorage() {
    const rows = [];
    for (const [label, storage] of [['localStorage', window.localStorage], ['sessionStorage', window.sessionStorage]]) {
        let keys = [];
        try {
            keys = Object.keys(storage);
        } catch {
            continue; // хранилище запрещено настройками браузера
        }
        for (const key of keys) {
            const value = storage.getItem(key) ?? '';
            const remove = el('button', { type: 'button', className: 'account-secondary', textContent: 'Удалить' });
            remove.addEventListener('click', () => { storage.removeItem(key); renderStorage(); });
            rows.push([label, el('code', { textContent: key }), revealable(value), formatBytes(new Blob([value]).size), remove]);
        }
    }
    $('privacy-storage').replaceChildren(table(['Где', 'Ключ', 'Значение', 'Размер', ''], rows, 'Пусто: сайт ничего не хранит в localStorage и sessionStorage.'));
}

function renderServerData(info) {
    const total = info.files.reduce((sum, file) => sum + file.size, 0);
    $('privacy-server-note').textContent = `Папка вашего профиля: ${info.files.length} ${pluralRu(info.files.length, 'файл', 'файла', 'файлов')}, ${formatBytes(total)}.`
        + (info.session_keys.length ? ` В сессии PHP: ${info.session_keys.join(', ')}.` : ' Сессии PHP нет.')
        + ' Общие для всех данные (каталог источников, кэш картинок и анализов) к профилю не привязаны.';
    $('privacy-files').replaceChildren(table(['Файл', 'Размер', 'Изменён', 'Что внутри'],
        info.files.map(file => [el('code', { textContent: file.name }), formatBytes(file.size), formatDate(file.modified), file.about]),
        'Файлов нет.'));
}

// ---- Загрузка ----

async function loadAccount() {
    if (DEBUG_MODE) {
        $('account-profile-body').textContent = 'В демо-режиме личного кабинета нет: данные живут только в сессии демо.';
        return null;
    }
    try {
        const info = await api('account_info');
        $('account-profile-body').classList.remove('is-loading', 'status-msg');
        renderProfile(info);
        renderTelegram(info.telegram);
        renderTelegramUser(info.telegram_user, Boolean(info.telegram));
        renderExposure(info);
        if (info.telegram) loadTelegramQueue();
        renderCookies(info.cookies);
        renderStorage();
        renderServerData(info);
        return info;
    } catch (error) {
        $('account-profile-body').classList.remove('is-loading', 'status-msg');
        $('account-profile-body').textContent = `Не удалось загрузить кабинет: ${error.message}`;
        return null;
    }
}

export function initializeAccountTab() {
    document.querySelectorAll('.account-nav [data-account-section]').forEach(button => {
        button.addEventListener('click', () => openAccountSection(button.dataset.accountSection));
    });
    $('vk-oauth-button').addEventListener('click', () => window.location.assign(apiUrl('vk_oauth_start')));
    $('telegram-form').addEventListener('submit', connectTelegram);
    $('telegram-disconnect').addEventListener('click', disconnectTelegram);
    $('tgu-start').addEventListener('submit', startTelegramUser);
    $('tgu-phone').addEventListener('blur', () => { $('tgu-phone').value = normalizePhone($('tgu-phone').value); });   // приводим к +7 сразу, как только вышли из поля
    $('tgu-phone').addEventListener('paste', () => setTimeout(() => { $('tgu-phone').value = normalizePhone($('tgu-phone').value); }));
    $('tgu-confirm').addEventListener('submit', confirmTelegramUser);
    $('tgu-disconnect').addEventListener('click', disconnectTelegramUser);
    $('tgu-resend').addEventListener('click', resendTelegramUser);
    $('tgu-qr-button').addEventListener('click', startTelegramQr);
    $('tgu-qr-cancel').addEventListener('click', () => { stopQr(); $('tgu-start').hidden = false; $('tgu-status').textContent = ''; });
    $('tgu-restart').addEventListener('click', async () => {
        try { renderTelegramUser((await api('telegram_user_disconnect', { body: null })).telegram_user, true); $('tgu-status').textContent = ''; } catch (error) { $('tgu-status').textContent = error.message; }
    });
    onAccountSection('profile', loadAccount);
    onAccountSection('social', () => { loadVkOAuthStatus(); loadAccount(); });
    onAccountSection('privacy', loadAccount);
    onTabOpen('account-tab', () => openAccountSection(currentSection));
    if (DEBUG_MODE) document.querySelector('.account-nav [data-account-section="databases"]').hidden = true;
}
