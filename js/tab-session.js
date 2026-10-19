// tab-session.js — привязка фоновых процессов к этой вкладке.
// При открытии вкладка занимает сессию: прежний парсер останавливается, запускается новый для этой вкладки.
// Пока вкладка жива, она присылает сигнал; при закрытии сообщает серверу, и процессы завершаются.
// Если приложение открыли в другой вкладке, эта становится неактивной (плашка с кнопкой «Работать здесь»).
import { DEBUG_MODE, TAB_ID, api, apiUrl, log, show } from './core.js';

const HEARTBEAT_MS = 5000;
let heartbeatTimer = null;
let inactive = false;

function setInactive(value) {
    if (inactive === value) return;
    inactive = value;
    document.body.classList.toggle('tab-inactive', value);
    show('tab-inactive', value);
    if (value) {
        log('Приложение открыто в другой вкладке: поиск и обновления идут там, эта вкладка неактивна.');
        clearInterval(heartbeatTimer);
    }
}

async function heartbeat() {
    try {
        const data = await api('tab_heartbeat', { body: { tab: TAB_ID } });
        if (!data.owner) setInactive(true);
    } catch {
        // Сервер временно недоступен: повторим при следующем сигнале.
    }
}

async function claimSession() {
    inactive = false;
    document.body.classList.remove('tab-inactive');
    show('tab-inactive', false);
    const data = await api('tab_open', { body: { tab: TAB_ID } });
    log(data.parser === 'started'
        ? 'Вкладка открыта: прежние процессы остановлены, поиск запущен заново.'
        : 'Вкладка открыта: прежние процессы остановлены.');
    clearInterval(heartbeatTimer);
    heartbeatTimer = setInterval(heartbeat, HEARTBEAT_MS);
}

/** Сообщает серверу, что вкладка закрыта. keepalive позволяет запросу дойти после закрытия страницы. */
function notifyClosed() {
    if (inactive) return;
    try {
        fetch(apiUrl('tab_close'), {
            method: 'POST',
            keepalive: true,
            headers: { 'Content-Type': 'application/json', 'X-Postmaker': '1' },
            body: JSON.stringify({ tab: TAB_ID })
        });
    } catch {
        // Не вышло — сервер остановит процессы сам по таймауту.
    }
}

/** Занимает сессию. Вызывать до первого запроса пула: он запускает парсер, привязанный к вкладке. */
export async function startTabSession() {
    if (DEBUG_MODE) return;
    document.getElementById('tab-inactive-reload')?.addEventListener('click', () => location.reload());
    window.addEventListener('pagehide', event => {
        // Страница в кэше «назад/вперёд» (persisted) может ожить — тогда pageshow займёт сессию снова.
        notifyClosed();
        clearInterval(heartbeatTimer);
    });
    window.addEventListener('pageshow', event => {
        if (event.persisted) claimSession().catch(() => {});
    });
    try {
        await claimSession();
    } catch (error) {
        log(`Не удалось привязать процессы к вкладке: ${error.message}`);
    }
}
