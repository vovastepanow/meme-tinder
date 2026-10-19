// main.js — точка входа: собирает вкладки и запускает интерфейс. Подключается как ES-модуль, сборка не нужна.
import { $, DEBUG_MODE, api, initializeActivityConsole, initializeTabs, log, show, watchParser } from './core.js';
import { initializeImageViewer } from './image-viewer.js';
import { startTabSession } from './tab-session.js';
import { initializeContentTab, loadCurrentItem } from './content.js';
import { initializeProposalsTab } from './proposals.js';
import { clearDiscoveredSource, initializeSourceTab } from './sources.js';
import { consumeVkRedirectResult, initializeAccountTab, openAccountSection } from './account.js';
import { initializeDatabaseTab, openNewItemEditor } from './database.js';
import { initializeTestTab } from './test.js';
import { initializeModelPanel } from './model-panel.js';
import { initializeTreeTab } from './tree.js';
import { initializeControlTab } from './control.js';

async function resetDebugDemo() {
    try {
        await api('debug_reset', { body: null });
        clearDiscoveredSource();
        await loadCurrentItem();
    } catch (error) {
        log(`Ошибка сброса демо: ${error.message}`);
    }
}

function initializeDebugMode() {
    if (!DEBUG_MODE) return;
    show('debug-banner');
    $('debug-reset-button').addEventListener('click', resetDebugDemo);
}

initializeActivityConsole();
initializeImageViewer();
initializeTabs();
initializeContentTab({ onAddItem: openNewItemEditor });
initializeProposalsTab();
initializeSourceTab();
initializeAccountTab();
initializeDatabaseTab({ onReset: loadCurrentItem });
initializeTestTab();
initializeModelPanel();
initializeTreeTab();
initializeControlTab();
initializeDebugMode();

log('Приложение запущено. Загружаю пул контента.');
if (consumeVkRedirectResult()) openAccountSection('social');
await startTabSession(); // сначала занимаем сессию: запрос пула запускает парсер, привязанный к этой вкладке
const firstItem = loadCurrentItem();
watchParser();
// Кольцо загрузки на весь экран держится, пока не готова первая вкладка (или не стало ясно, что загрузка не удалась)
await firstItem.catch(() => {});
const appLoading = document.getElementById('app-loading');
appLoading?.classList.add('is-done');
setTimeout(() => appLoading?.remove(), 400);
