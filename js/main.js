// main.js — точка входа: собирает вкладки и запускает интерфейс. Подключается как ES-модуль, сборка не нужна.
import { $, DEBUG_MODE, api, initializeActivityConsole, initializeTabs, log, show, switchTab } from './core.js';
import { initializeImageViewer } from './image-viewer.js';
import { initializeMemeTab, loadCurrentMeme } from './memes.js';
import { clearDiscoveredSource, consumeVkRedirectResult, initializeSourceTab } from './sources.js';
import { initializeDatabaseTab, openNewMemeEditor } from './database.js';
import { initializeTestTab } from './test.js';

async function resetDebugDemo() {
    try {
        await api('debug_reset', { body: null });
        clearDiscoveredSource();
        await loadCurrentMeme();
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
initializeMemeTab({ onAddMeme: openNewMemeEditor });
initializeSourceTab();
initializeDatabaseTab();
initializeTestTab();
initializeDebugMode();

log('Приложение запущено. Загружаю пул мемов.');
if (consumeVkRedirectResult()) switchTab('source-tab');
loadCurrentMeme();
