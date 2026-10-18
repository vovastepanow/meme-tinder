// sources.js — Вкладка «Подбор источников»: поиск пабликов, решения и подключение VK.
let activeDiscoveredSource = null;

function startVkOAuth() {
    window.location.assign(apiUrl('vk_oauth_start'));
}

async function loadVkOAuthStatus() {
    const button = document.getElementById('vk-oauth-button');
    const status = document.getElementById('vk-oauth-status');
    try {
        const response = await fetch(apiUrl('vk_oauth_status'));
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        button.disabled = false;
        button.textContent = data.connected ? 'Переподключить VK' : 'Подключить VK';
        status.textContent = !data.configured
            ? 'Для OAuth задайте VK_CLIENT_ID и VK_REDIRECT_URI на сервере.'
            : data.connected
                ? `VK подключён${data.user_id ? ` · ID ${data.user_id}` : ''}.`
                : 'Подключите VK, чтобы подбирать паблики.';
    } catch (error) {
        button.disabled = false;
        status.textContent = `Статус VK недоступен: ${error.message}`;
    }
}

async function findNewSources() {
    document.getElementById('source-loading').style.display = 'block';
    document.getElementById('source-inspection-zone').style.display = 'none';
    document.getElementById('source-error').style.display = 'none';
    try {
        const response = await fetch(apiUrl('suggest_sources'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ platform: document.getElementById('source-platform').value })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        if (!data.discovered) throw new Error(data.message || 'Сервер не вернул источник');

        activeDiscoveredSource = data.discovered;
        document.getElementById('inspect-source-name').textContent = `Источник: ${activeDiscoveredSource.name}`;
        document.getElementById('inspect-source-reason').textContent = activeDiscoveredSource.reason;
        const grid = document.getElementById('posts-grid');
        grid.replaceChildren();

        (activeDiscoveredSource.posts || []).forEach(post => {
            const images = Array.isArray(post.images) && post.images.length ? post.images : [post.image];
            if (!images[0]) return;
            const item = document.createElement('div');
            item.className = 'grid-item';
            const imageWrap = document.createElement('div');
            imageWrap.className = 'img-wrap';
            const image = document.createElement('img');
            image.src = images[0];
            image.alt = 'Изображение из поста';
            image.loading = 'lazy';
            imageWrap.appendChild(image);
            const text = document.createElement('div');
            text.className = 'grid-item-text';
            text.textContent = post.text || 'Пост без текста';
            item.append(imageWrap, text);
            grid.appendChild(item);
        });

        document.getElementById('source-inspection-zone').style.display = 'flex';
    } catch (error) {
        console.error('Ошибка при поиске источников:', error);
        document.getElementById('source-error').textContent = `Не удалось загрузить источник: ${error.message}`;
        document.getElementById('source-error').style.display = 'block';
    } finally {
        document.getElementById('source-loading').style.display = 'none';
    }
}

// Обработка клика по кнопкам одобрения/отклонения найденного паблика
async function voteSource(voteType) {
    if (!activeDiscoveredSource) return;

    try {
        const response = await fetch(apiUrl('source_vote'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ vote: voteType, source: activeDiscoveredSource })
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        document.getElementById('source-inspection-zone').style.display = 'none';
        activeDiscoveredSource = null;
    } catch (e) {
        console.error('Ошибка при сохранении решения по источнику:', e);
    }
}
