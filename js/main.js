// main.js — точка входа: собирает вкладки и запускает интерфейс. Подключается как ES-модуль, сборка не нужна.
import { $, DEBUG_MODE, api, initializeActivityConsole, initializeTabs, log, show, switchTab, watchParser } from './core.js';
import { initializeImageViewer } from './image-viewer.js';
import { initializeContentTab, loadCurrentItem } from './content.js';
import { clearDiscoveredSource, consumeVkRedirectResult, initializeSourceTab } from './sources.js';
import { initializeDatabaseTab, openNewItemEditor } from './database.js';
import { initializeTestTab } from './test.js';

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
initializeSourceTab();
initializeDatabaseTab({ onReset: loadCurrentItem });
initializeTestTab();
initializeDebugMode();

log('Приложение запущено. Загружаю пул контента.');
if (consumeVkRedirectResult()) switchTab('source-tab');
loadCurrentItem();
watchParser();
