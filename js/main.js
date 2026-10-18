// main.js — Точка входа: запуск интерфейса после загрузки страницы.
async function resetDebugDemo() {
    if (!DEBUG_MODE) return;
    const response = await fetch(apiUrl('debug_reset'), { method: 'POST' });
    if (!response.ok) {
        console.error(`Ошибка сброса демо: HTTP ${response.status}`);
        return;
    }
    activeDiscoveredSource = null;
    document.getElementById('source-inspection-zone').style.display = 'none';
    await loadCurrentMeme();
}

// Автоматический запуск первой загрузки контента при открытии страницы
document.addEventListener('DOMContentLoaded', () => {
    initializeImageViewer();
    initializeComplaintMenu();
    initializeActivityConsole();
    appendAppLog('Приложение запущено. Загружаю пул мемов.');
    if (DEBUG_MODE) document.getElementById('debug-banner').style.display = 'flex';
    if (DEBUG_MODE) document.querySelector('[data-tab-id="database-tab"]').hidden = true;
    if (DEBUG_MODE) document.querySelector('.meme-add-button').hidden = true;
    if (DEBUG_MODE) document.getElementById('meme-search-button').hidden = true;
    document.getElementById('carousel-prev').addEventListener('click', () => changeMemeSlide(-1));
    document.getElementById('carousel-next').addEventListener('click', () => changeMemeSlide(1));
    document.getElementById('image-file').addEventListener('change', event => showTestPreview(event.target.files[0]));
    document.getElementById('image-url').addEventListener('input', () => {
        if (document.getElementById('image-url').value) {
            document.getElementById('image-file').value = '';
            showTestPreview(null);
        }
    });
    document.getElementById('analyze-form').addEventListener('submit', analyzeTestImage);
    document.getElementById('meme-search-button').addEventListener('click', searchForMemes);
    document.getElementById('database-select').addEventListener('change', loadDatabaseCollections);
    document.getElementById('database-collection-select').addEventListener('change', loadDatabaseRecords);
    document.getElementById('database-refresh').addEventListener('click', loadDatabaseRecords);
    document.getElementById('database-add').addEventListener('click', openDatabaseCreator);
    document.getElementById('database-edit-form').addEventListener('submit', saveDatabaseRecord);
    document.getElementById('database-cancel').addEventListener('click', () => document.getElementById('database-edit-dialog').close());
    const vkResult = new URLSearchParams(window.location.search).get('vk');
    if (vkResult) {
        const sourceTabButton = document.querySelector('.tabs .tab-btn[onclick*="source-tab"]');
        if (sourceTabButton) switchTab('source-tab', sourceTabButton);
        const status = document.getElementById('vk-oauth-status');
        if (vkResult === 'connected') status.textContent = 'VK подключён.';
        else if (vkResult === 'misconfigured') status.textContent = 'Настройте VK_CLIENT_ID и VK_REDIRECT_URI на сервере.';
        else status.textContent = 'Не удалось подключить VK. Проверьте настройки OAuth и журнал PHP.';
        window.history.replaceState({}, '', window.location.pathname);
    }
    loadCurrentMeme();
});
