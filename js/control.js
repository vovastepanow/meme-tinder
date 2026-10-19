// control.js — панель управления приложением: искать контент, обновить базу источников, остановить приложение.
// Кнопки — в закреплённой панели справа (index.html, #control-dock), видны на всех вкладках. Ход и итоги — в журнале.
import { $, DEBUG_MODE, TAB_ID, api, hide, log, watchParser } from './core.js';
import { loadCurrentItem } from './content.js';

const STOP_CONFIRM = 'Остановить приложение? Будут остановлены все процессы: сборщики контента (в том числе других пользователей), помощник Telegram и модели. Чтобы снова работать, обновите страницу.';

function setBusy(button, busy) {
    document.querySelectorAll('#control-dock .btn').forEach(other => { other.disabled = busy && other !== button; });
    button.classList.toggle('is-busy', busy);
}

async function runAction(button, action, done, body = null) {
    setBusy(button, true);
    try {
        done(await api(action, { body }));
    } catch (error) {
        log(`Ошибка: ${error.message}`);
    } finally {
        setBusy(button, false);
    }
}

async function searchContent() {
    await runAction($('control-search'), 'control_search', data => {
        log(data.message || 'Запущен поиск контента.');
        watchParser();
        loadCurrentItem();
    });
}

async function updateSources() {
    await runAction($('control-sources'), 'control_sources', data => {
        log(data.message || 'Запущено обновление базы источников.');
        watchParser();
    });
}

async function stopApp() {
    if (!window.confirm(STOP_CONFIRM)) return;
    // Сервер привязывает остановку к вкладке (сессия вкладки закрывается вместе с процессами), поэтому передаём её id
    await runAction($('control-stop'), 'app_stop', data => {
        log(`Приложение остановлено: процессов завершено ${data.stopped}. Обновите страницу, чтобы снова работать.`);
    }, { tab: TAB_ID });
}

export function initializeControlTab() {
    $('control-search').addEventListener('click', searchContent);
    $('control-sources').addEventListener('click', updateSources);
    $('control-stop').addEventListener('click', stopApp);
    if (DEBUG_MODE) {
        hide('control-dock');
    }
}
