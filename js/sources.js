// sources.js — вкладка «Подбор источников»: поиск каналов и тредов, решения по ним и подключение VK.
import { $, api, apiUrl, el, hide, log, onTabOpen, setMessage, show } from './core.js';

const VK_REDIRECT_MESSAGES = {
    connected: 'VK подключён.',
    misconfigured: 'Настройте VK_CLIENT_ID и VK_REDIRECT_URI на сервере.',
    error: 'Не удалось подключить VK. Проверьте настройки OAuth и журнал PHP.'
};

let discoveredSource = null;
// Итог входа через VK ID показывается вместо обычного статуса при первой загрузке вкладки.
let pendingVkRedirectMessage = '';

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

function renderPostTile(post) {
    const image = (Array.isArray(post.images) && post.images[0]) || post.image;
    if (!image) return null;
    return el('div', { className: 'grid-item' },
        el('div', { className: 'img-wrap' }, el('img', { src: image, alt: 'Изображение из поста', loading: 'lazy' })),
        el('div', { className: 'grid-item-text', textContent: post.text || 'Пост без текста' })
    );
}

function showDiscoveredSource(source) {
    discoveredSource = source;
    $('inspect-source-name').textContent = `Источник: ${source.name}`;
    $('inspect-source-reason').textContent = source.reason || '';
    $('posts-grid').replaceChildren(...(source.posts || []).map(renderPostTile).filter(Boolean));
    show('source-inspection-zone');
}

export function clearDiscoveredSource() {
    discoveredSource = null;
    hide('source-inspection-zone');
}

async function findNewSource() {
    const button = $('source-search-button');
    button.disabled = true;
    clearDiscoveredSource();
    setMessage('source-error', '');
    show('source-loading');
    try {
        const data = await api('suggest_sources', { body: { platform: $('source-platform').value } });
        if (!data.discovered) throw new Error(data.message || 'Сервер не вернул источник');
        showDiscoveredSource(data.discovered);
    } catch (error) {
        console.error('Ошибка при поиске источников:', error);
        setMessage('source-error', `Не удалось загрузить источник: ${error.message}`);
    } finally {
        hide('source-loading');
        button.disabled = false;
    }
}

async function voteSource(vote) {
    if (!discoveredSource) return;
    const buttons = document.querySelectorAll('#source-inspection-zone [data-source-vote]');
    buttons.forEach(button => { button.disabled = true; });
    try {
        await api('source_vote', { body: { vote, source: discoveredSource } });
        log(`${vote === 'approve' ? 'Одобрен' : 'Отклонён'} источник: ${discoveredSource.name}`);
        clearDiscoveredSource();
    } catch (error) {
        console.error('Ошибка при сохранении решения по источнику:', error);
        setMessage('source-error', `Решение не сохранено: ${error.message}`);
    } finally {
        buttons.forEach(button => { button.disabled = false; });
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

export function initializeSourceTab() {
    $('source-search-button').addEventListener('click', findNewSource);
    $('vk-oauth-button').addEventListener('click', () => window.location.assign(apiUrl('vk_oauth_start')));
    document.querySelectorAll('#source-inspection-zone [data-source-vote]').forEach(button => {
        button.addEventListener('click', () => voteSource(button.dataset.sourceVote));
    });
    onTabOpen('source-tab', loadVkOAuthStatus);
}
