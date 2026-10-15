// script.js
let currentMeme = null;
let currentMemeSlides = [];
let currentSlideIndex = 0;
let activeDiscoveredSource = null;
let databaseEditorOptions = [];
let databaseEditorReady = false;
let databaseEditingIndex = null;
let memePollTimer = null;
let memeActivityLines = [];
let parserActivityLines = [];
let consoleFollowsTail = true;
const DEBUG_MODE = new URLSearchParams(window.location.search).get('debug') === '1';
const IMAGE_VIEWER_MAX_ZOOM = 5;
const IMAGE_VIEWER_CLICK_TOLERANCE = 4;
const IMAGE_VIEWER_MIN_FILL = 0.7;
const IMAGE_VIEWER_VISIBLE_EDGE = 60;
const imageViewerState = { zoom: 1, x: 0, y: 0, drag: null };

function applyImageViewerTransform() {
    const image = document.getElementById('image-viewer-image');
    const { zoom, x, y } = imageViewerState;
    image.style.transform = `translate(${x}px, ${y}px) scale(${zoom})`;
    image.classList.toggle('is-zoomed', zoom > 1);
}

/** Small images are upscaled to fill at least IMAGE_VIEWER_MIN_FILL of the screen; large ones shrink to fit. */
function fitImageViewer() {
    const image = document.getElementById('image-viewer-image');
    if (!image.naturalWidth || !image.naturalHeight) return;
    const fit = Math.min((window.innerWidth - 48) / image.naturalWidth, (window.innerHeight - 72) / image.naturalHeight);
    const scale = Math.min(fit, Math.max(1, fit * IMAGE_VIEWER_MIN_FILL));
    image.style.width = `${Math.round(image.naturalWidth * scale)}px`;
    image.style.height = `${Math.round(image.naturalHeight * scale)}px`;
}

/** Lets the image move freely but keeps at least IMAGE_VIEWER_VISIBLE_EDGE pixels of it on screen. */
function clampImageViewerOffset() {
    const image = document.getElementById('image-viewer-image');
    const maxX = Math.max(0, (window.innerWidth + image.offsetWidth * imageViewerState.zoom) / 2 - IMAGE_VIEWER_VISIBLE_EDGE);
    const maxY = Math.max(0, (window.innerHeight + image.offsetHeight * imageViewerState.zoom) / 2 - IMAGE_VIEWER_VISIBLE_EDGE);
    imageViewerState.x = Math.max(-maxX, Math.min(maxX, imageViewerState.x));
    imageViewerState.y = Math.max(-maxY, Math.min(maxY, imageViewerState.y));
}

function zoomImageViewer(factor, clientX, clientY) {
    const previousZoom = imageViewerState.zoom;
    const zoom = Math.max(1, Math.min(IMAGE_VIEWER_MAX_ZOOM, previousZoom * factor));
    if (zoom === previousZoom) return;
    // Zoom around the cursor: the point under the pointer stays in place.
    const pointX = clientX - window.innerWidth / 2;
    const pointY = clientY - window.innerHeight / 2;
    const ratio = zoom / previousZoom;
    imageViewerState.x = pointX - (pointX - imageViewerState.x) * ratio;
    imageViewerState.y = pointY - (pointY - imageViewerState.y) * ratio;
    imageViewerState.zoom = zoom;
    clampImageViewerOffset();
    applyImageViewerTransform();
}

function openImageViewer(source, altText) {
    const viewer = document.getElementById('image-viewer');
    const image = document.getElementById('image-viewer-image');
    Object.assign(imageViewerState, { zoom: 1, x: 0, y: 0, drag: null });
    image.alt = altText || 'Просмотр изображения';
    image.style.width = '';
    image.style.height = '';
    image.onload = fitImageViewer;
    image.src = source;
    if (image.complete) fitImageViewer();
    applyImageViewerTransform();
    viewer.hidden = false;
    document.body.classList.add('image-viewer-open');
}

function closeImageViewer() {
    const viewer = document.getElementById('image-viewer');
    viewer.hidden = true;
    viewer.classList.remove('is-dragging');
    document.getElementById('image-viewer-image').removeAttribute('src');
    document.body.classList.remove('image-viewer-open');
    imageViewerState.drag = null;
}

function initializeImageViewer() {
    const viewer = document.getElementById('image-viewer');
    const image = document.getElementById('image-viewer-image');

    document.addEventListener('click', event => {
        const target = event.target.closest('img');
        if (!target || target === image || (!target.currentSrc && !target.src)) return;
        event.preventDefault();
        openImageViewer(target.currentSrc || target.src, target.alt);
    });
    document.getElementById('image-viewer-close').addEventListener('click', event => {
        event.stopPropagation();
        closeImageViewer();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !viewer.hidden) closeImageViewer();
    });
    window.addEventListener('resize', () => {
        if (!viewer.hidden) fitImageViewer();
    });
    viewer.addEventListener('wheel', event => {
        event.preventDefault();
        zoomImageViewer(event.deltaY < 0 ? 1.2 : 1 / 1.2, event.clientX, event.clientY);
    }, { passive: false });

    viewer.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.target.closest('button')) return;
        imageViewerState.drag = {
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            originX: imageViewerState.x,
            originY: imageViewerState.y,
            moved: false
        };
        viewer.setPointerCapture(event.pointerId);
    });
    viewer.addEventListener('pointermove', event => {
        const drag = imageViewerState.drag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;
        if (!drag.moved && Math.hypot(dx, dy) < IMAGE_VIEWER_CLICK_TOLERANCE) return;
        drag.moved = true;
        viewer.classList.add('is-dragging');
        imageViewerState.x = drag.originX + dx;
        imageViewerState.y = drag.originY + dy;
        clampImageViewerOffset();
        applyImageViewerTransform();
    });
    viewer.addEventListener('pointerup', event => {
        const drag = imageViewerState.drag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        imageViewerState.drag = null;
        viewer.classList.remove('is-dragging');
        if (!drag.moved) closeImageViewer();
    });
    viewer.addEventListener('pointercancel', () => {
        imageViewerState.drag = null;
        viewer.classList.remove('is-dragging');
    });
}

function initializeComplaintMenu() {
    const button = document.getElementById('complaint-button');
    const menu = document.getElementById('complaint-menu');
    const setOpen = open => {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
    };
    button.addEventListener('click', event => {
        event.stopPropagation();
        setOpen(menu.hidden);
    });
    menu.addEventListener('click', event => {
        const item = event.target.closest('[data-reason]');
        if (!item) return;
        setOpen(false);
        voteMeme('wrong', { reason: item.dataset.reason });
    });
    document.addEventListener('click', event => {
        if (!menu.hidden && !event.target.closest('.complaint-wrap')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') setOpen(false);
    });
}

function apiUrl(action) {
    const activityLabels = {
        refresh_memes: 'Запущен поиск мемов',
        analyze_image: 'Запущен анализ изображения',
        suggest_sources: 'Поиск нового источника',
        meme_vote: 'Сохранение оценки мема',
        source_vote: 'Сохранение решения по источнику',
        database_add: 'Добавление записи в базу',
        database_update: 'Обновление записи в базе',
        database_delete: 'Удаление записи из базы',
        database_read: 'Загрузка записей базы',
        database_options: 'Загрузка списка баз',
        debug_reset: 'Сброс демо-данных'
    };
    if (activityLabels[action]) appendAppLog(activityLabels[action]);
    return `index.php?action=${action}${DEBUG_MODE ? '&debug=1' : ''}`;
}

function resetMemeActivity() {
    parserActivityLines = [];
}

const AI_LOG_LINE_PATTERN = /^\[ИИ (→|←) ([^\]]+)\] ?([\s\S]*)$/;

/** Lines from record_ai_exchange() become chat-style bubbles: prompt and reply, collapsed to 4 lines. */
function renderAiConsoleEntry(entry, timestamp, direction, model, text) {
    const isRequest = direction === '→';
    entry.classList.add('ai-message', isRequest ? 'ai-request' : 'ai-response');
    entry.title = 'Клик — развернуть или свернуть';
    const meta = document.createElement('span');
    meta.className = 'ai-message-meta';
    meta.textContent = `${timestamp} · ${isRequest ? 'запрос →' : 'ответ ←'} ${model}`;
    const body = document.createElement('span');
    body.className = 'ai-message-text';
    body.textContent = text;
    entry.append(meta, body);
    entry.addEventListener('click', () => entry.classList.toggle('is-expanded'));
}

function appendConsoleLine(message) {
    const track = document.getElementById('meme-activity-track');
    const timestamp = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    const entry = document.createElement('p');
    entry.className = 'activity-console-line';
    const aiExchange = AI_LOG_LINE_PATTERN.exec(message);
    if (aiExchange) {
        renderAiConsoleEntry(entry, timestamp, ...aiExchange.slice(1));
    } else {
        entry.textContent = `${timestamp} ${message}`;
    }
    memeActivityLines.push(`${timestamp} ${message}`);
    track.appendChild(entry);

    while (memeActivityLines.length > 200) {
        memeActivityLines.shift();
        track.firstElementChild?.remove();
    }
    if (consoleFollowsTail) track.scrollTop = track.scrollHeight;
}

function appendAppLog(message) {
    appendConsoleLine(message);
}

function logAiExchanges(lines) {
    (Array.isArray(lines) ? lines : []).forEach(line => appendAppLog(line));
}

function logOcrDiagnostics(source, diagnostics = {}) {
    const boxes = (diagnostics.regions || []).map(region => `${region.left},${region.top} ${region.width}x${region.height}`);
    const rejected = (diagnostics.rejected || []).map(token => `${token.text}:${token.confidence}/${token.reason}`);
    appendAppLog(`OCR ${source}: ${diagnostics.status || 'unknown'}, image ${diagnostics.image_width ?? '?'}x${diagnostics.image_height ?? '?'}, PSM ${diagnostics.psm ?? '-'}, scale ${diagnostics.scale ?? '-'}, raw ${diagnostics.raw_word_count ?? 0}, accepted ${diagnostics.word_count ?? 0}/${diagnostics.line_count ?? 0} lines, confidence ${diagnostics.confidence ?? 0}, text="${diagnostics.text || ''}", boxes=${boxes.join(';') || '-'}`);
    if (rejected.length) appendAppLog(`OCR rejected ${source}: ${rejected.join(';')}`);
}

function appendMemeActivity(activity) {
    const incoming = Array.isArray(activity) ? activity.filter(line => typeof line === 'string' && line.trim()) : [];
    let overlap = 0;
    for (let size = Math.min(parserActivityLines.length, incoming.length); size > 0; size--) {
        const oldTail = parserActivityLines.slice(-size);
        if (oldTail.every((line, index) => line === incoming[index])) {
            overlap = size;
            break;
        }
    }

    const newLines = incoming.slice(overlap);
    parserActivityLines = incoming;
    if (!memeActivityLines.length && !incoming.length) newLines.push('Запуск парсера и загрузка источников...');
    newLines.forEach(appendConsoleLine);
}

function initializeActivityConsole() {
    const track = document.getElementById('meme-activity-track');
    const followButton = document.getElementById('activity-console-follow');
    const updateFollowState = () => {
        consoleFollowsTail = track.scrollHeight - track.scrollTop - track.clientHeight <= 24;
        followButton.hidden = consoleFollowsTail;
    };

    track.addEventListener('scroll', updateFollowState, { passive: true });
    followButton.addEventListener('click', () => {
        consoleFollowsTail = true;
        track.scrollTop = track.scrollHeight;
        followButton.hidden = true;
    });
    updateFollowState();
}

function setMemeLoadingMessage(message, activity = []) {
    const status = document.getElementById('meme-loading');
    if (message === 'Ищем посты...') {
        appendMemeActivity(activity);
        document.getElementById('activity-console-status').textContent = 'поиск';
        status.style.display = 'none';
        return;
    }
    status.textContent = message;
    status.style.display = 'block';
}

function hideMemeLoading() {
    document.getElementById('meme-loading').style.display = 'none';
    document.getElementById('activity-console-status').textContent = 'готово';
}

async function searchForMemes() {
    if (DEBUG_MODE) return;
    const button = document.getElementById('meme-search-button');
    button.disabled = true;
    clearTimeout(memePollTimer);
    document.getElementById('meme-empty').style.display = 'none';
    resetMemeActivity();
    setMemeLoadingMessage('Ищем посты...');
    try {
        const response = await fetch(apiUrl('refresh_memes'), { method: 'POST' });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        await loadCurrentMeme();
    } catch (error) {
        hideMemeLoading();
        document.getElementById('meme-error').textContent = `Не удалось запустить поиск: ${error.message}`;
        document.getElementById('meme-error').style.display = 'block';
    } finally {
        button.disabled = false;
    }
}

function normalizeMemeSlides(meme) {
    if (!meme) return [];

    if (Array.isArray(meme.slides) && meme.slides.length) {
        return meme.slides.map((slide, index) => ({
            image: slide.image || meme.images?.[index] || meme.image || '',
            text: slide.text || slide.caption || slide.comment || meme.text || ''
        })).filter(slide => slide.image);
    }

    if (Array.isArray(meme.images) && meme.images.length) {
        const captions = Array.isArray(meme.captions) ? meme.captions : [];
        return meme.images.map((image, index) => ({
            image,
            text: captions[index] || meme.text || ''
        }));
    }

    if (meme.image) {
        return [{ image: meme.image, text: meme.text || meme.ai_description || 'Текст к посту отсутствует' }];
    }

    return [];
}

function renderMemeSlide() {
    const slides = currentMemeSlides.length ? currentMemeSlides : [{ image: '', text: 'Нет изображения' }];
    const active = slides[currentSlideIndex] || slides[0];
    const img = document.getElementById('meme-image');
    const caption = document.getElementById('meme-slide-caption');
    const position = document.getElementById('meme-slide-position');
    const prevBtn = document.getElementById('carousel-prev');
    const nextBtn = document.getElementById('carousel-next');

    if (active && active.image) {
        img.src = active.image;
        img.style.display = 'block';
    } else {
        img.removeAttribute('src');
        img.style.display = 'none';
    }

    caption.textContent = active?.text || currentMeme?.text || 'Текст к посту отсутствует';

    const hasMultiple = slides.length > 1;
    prevBtn.disabled = !hasMultiple;
    nextBtn.disabled = !hasMultiple;
    if (!hasMultiple) {
        prevBtn.style.visibility = 'hidden';
        nextBtn.style.visibility = 'hidden';
    } else {
        nextBtn.style.visibility = 'visible';
        prevBtn.style.visibility = 'visible';
    }
    position.textContent = hasMultiple ? `${currentSlideIndex + 1} / ${slides.length}` : '';
}

function changeMemeSlide(delta) {
    if (!currentMemeSlides.length) return;
    currentSlideIndex = (currentSlideIndex + delta + currentMemeSlides.length) % currentMemeSlides.length;
    renderMemeSlide();
}

// Переключение между вкладками SPA без перезагрузки страницы
function switchTab(tabId, element) {
    appendAppLog(`Открыта вкладка: ${element.textContent.trim()}`);
    document.querySelectorAll('.view-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tabs .tab-btn').forEach(b => b.classList.remove('active'));
    
    document.getElementById(tabId).classList.add('active');
    element.classList.add('active');
    if (tabId === 'database-tab') loadDatabaseOptions();
    if (tabId === 'source-tab') loadVkOAuthStatus();
}

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

function setDatabaseStatus(message, isError = false) {
    const status = document.getElementById('database-status');
    status.textContent = message;
    status.classList.toggle('error-msg', isError);
}

async function loadDatabaseOptions() {
    if (databaseEditorReady) return;
    setDatabaseStatus('Загружаю базы данных...');
    try {
        const response = await fetch(apiUrl('database_options'));
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        databaseEditorOptions = data.databases || [];
        const databaseSelect = document.getElementById('database-select');
        databaseSelect.replaceChildren(...databaseEditorOptions.map(database => {
            const option = document.createElement('option');
            option.value = database.id;
            option.textContent = database.label;
            return option;
        }));
        databaseEditorReady = true;
        await loadDatabaseCollections();
    } catch (error) {
        setDatabaseStatus(`Не удалось загрузить базы: ${error.message}`, true);
    }
}

async function loadDatabaseCollections() {
    const database = databaseEditorOptions.find(item => item.id === document.getElementById('database-select').value);
    const collectionSelect = document.getElementById('database-collection-select');
    collectionSelect.replaceChildren(...(database?.collections || []).map(collection => {
        const option = document.createElement('option');
        option.value = collection.id;
        option.textContent = collection.label;
        return option;
    }));
    await loadDatabaseRecords();
}

function databaseRecordSummary(record) {
    if (typeof record === 'string') return record;
    if (record === null || typeof record !== 'object') return String(record);
    const preview = record.text || record.name || record.title || record.source || record.url || record.image || '';
    const funny = typeof record.is_funny === 'boolean' ? `Смешно: ${record.is_funny ? 'да' : 'нет'}` : '';
    const secondary = [record.rating !== undefined ? `${record.rating}%` : '', funny, record.source || '', record.why_funny || '', record.ai_description || '']
        .filter(Boolean).join(' · ');
    return [preview || JSON.stringify(record), secondary].filter(Boolean).join('\n');
}

function databaseRecordCountLabel(count) {
    const remainder100 = count % 100;
    const remainder10 = count % 10;
    const word = remainder100 >= 11 && remainder100 <= 14
        ? 'записей'
        : remainder10 === 1
            ? 'запись'
            : remainder10 >= 2 && remainder10 <= 4
                ? 'записи'
                : 'записей';
    return `${count} ${word}`;
}

function renderDatabaseRecords(items) {
    const container = document.getElementById('database-records');
    container.replaceChildren();
    if (!items.length) {
        const empty = document.createElement('div');
        empty.className = 'database-empty';
        empty.textContent = 'В этой коллекции пока нет записей.';
        container.appendChild(empty);
        return;
    }

    items.forEach((record, index) => {
        const row = document.createElement('article');
        row.className = 'database-record';
        const number = document.createElement('span');
        number.className = 'database-record-number';
        number.textContent = `#${index + 1}`;
        const summary = document.createElement('div');
        summary.className = 'database-record-summary';
        summary.textContent = databaseRecordSummary(record);
        const actions = document.createElement('div');
        actions.className = 'database-record-actions';

        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'database-edit-button';
        editButton.textContent = 'Редактировать';
        editButton.addEventListener('click', () => openDatabaseEditor(index, record));

        const deleteButton = document.createElement('button');
        deleteButton.type = 'button';
        deleteButton.className = 'database-delete-button';
        deleteButton.textContent = 'Удалить';
        deleteButton.addEventListener('click', () => deleteDatabaseRecord(index));

        actions.append(editButton);
        const sourceUrl = safeSourcePostUrl(record);
        if (sourceUrl) {
            const sourceLink = document.createElement('a');
            sourceLink.className = 'database-source-link';
            sourceLink.href = sourceUrl;
            sourceLink.target = '_blank';
            sourceLink.rel = 'noopener noreferrer';
            sourceLink.textContent = 'Пост ↗';
            actions.appendChild(sourceLink);
        }
        actions.append(deleteButton);
        row.append(number, summary, actions);
        container.appendChild(row);
    });
}

function safeSourcePostUrl(record) {
    if (!record || typeof record !== 'object' || typeof record.source_url !== 'string') return '';
    try {
        const url = new URL(record.source_url);
        return url.protocol === 'https:' && url.hostname === '2ch.hk' ? url.href : '';
    } catch (error) {
        return '';
    }
}

async function loadDatabaseRecords() {
    const database = document.getElementById('database-select').value;
    const collection = document.getElementById('database-collection-select').value;
    if (!database || !collection) return;
    setDatabaseStatus('Загружаю записи...');
    try {
        const query = `&database=${encodeURIComponent(database)}&collection=${encodeURIComponent(collection)}`;
        const response = await fetch(`${apiUrl('database_read')}${query}`);
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        renderDatabaseRecords(data.items || []);
        setDatabaseStatus(`${data.database_label} · ${data.collection_label} · ${databaseRecordCountLabel(data.items.length)}`);
    } catch (error) {
        renderDatabaseRecords([]);
        setDatabaseStatus(`Не удалось прочитать коллекцию: ${error.message}`, true);
    }
}

function openDatabaseEditor(index, record) {
    databaseEditingIndex = index;
    document.getElementById('database-dialog-title').textContent = 'Редактировать запись';
    document.getElementById('database-record-json').value = JSON.stringify(record, null, 2);
    document.getElementById('database-edit-dialog').showModal();
}

function databaseRecordTemplate(database, collection) {
    if (database === 'memes_pool') {
        return { image: '', text: '', ai_description: '', why_funny: '', is_funny: null, meme_probability: 0, rating: 0, source: '', board: '', thread_id: '', post_id: '', source_url: '' };
    }
    if (database === 'meme_history') {
        return {
            image: '',
            text: '',
            ai_short_description: '',
            rating_assigned: 0,
            source: '',
            timestamp: Math.floor(Date.now() / 1000)
        };
    }
    if (database === 'sources_db' && collection === '2ch_boards') return 'b';
    if (database === 'sources_db' && ['vk_publics', 'telegram_channels'].includes(collection)) {
        return { id: '', name: '' };
    }
    if (database === 'sources_db' && collection === 'source_votes') {
        return { source_id: '', platform: '', name: '', vote: 'approve', timestamp: Math.floor(Date.now() / 1000) };
    }
    return {};
}

function openDatabaseCreator() {
    databaseEditingIndex = null;
    document.getElementById('database-dialog-title').textContent = 'Добавить запись';
    document.getElementById('database-record-json').value = JSON.stringify(
        databaseRecordTemplate(
            document.getElementById('database-select').value,
            document.getElementById('database-collection-select').value
        ),
        null,
        2
    );
    document.getElementById('database-edit-dialog').showModal();
}

async function openEmptyMemeEditor() {
    const tabButton = document.querySelector('[data-tab-id="database-tab"]');
    if (DEBUG_MODE || !tabButton) return;
    switchTab('database-tab', tabButton);
    await loadDatabaseOptions();
    if (!databaseEditorReady) return;
    document.getElementById('database-select').value = 'memes_pool';
    await loadDatabaseCollections();
    openDatabaseCreator();
}

async function saveDatabaseRecord(event) {
    event.preventDefault();
    let record;
    try {
        record = JSON.parse(document.getElementById('database-record-json').value);
    } catch (error) {
        setDatabaseStatus(`Некорректный JSON: ${error.message}`, true);
        return;
    }

    try {
        const action = databaseEditingIndex === null ? 'database_add' : 'database_update';
        const payload = {
            database: document.getElementById('database-select').value,
            collection: document.getElementById('database-collection-select').value,
            record
        };
        if (databaseEditingIndex !== null) payload.index = databaseEditingIndex;
        const response = await fetch(apiUrl(action), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        document.getElementById('database-edit-dialog').close();
        databaseEditingIndex = null;
        await loadDatabaseRecords();
    } catch (error) {
        setDatabaseStatus(`Не удалось сохранить запись: ${error.message}`, true);
    }
}

async function deleteDatabaseRecord(index) {
    if (!window.confirm(`Удалить запись #${index + 1}? Это действие нельзя отменить.`)) return;
    try {
        const response = await fetch(apiUrl('database_delete'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                database: document.getElementById('database-select').value,
                collection: document.getElementById('database-collection-select').value,
                index
            })
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        await loadDatabaseRecords();
    } catch (error) {
        setDatabaseStatus(`Не удалось удалить запись: ${error.message}`, true);
    }
}

function showTestPreview(file) {
    const preview = document.getElementById('test-image');
    if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
    preview.dataset.objectUrl = file ? URL.createObjectURL(file) : '';
    if (file) {
        preview.src = preview.dataset.objectUrl;
        document.getElementById('image-url').value = '';
    } else {
        preview.removeAttribute('src');
    }
    document.getElementById('test-result').style.display = 'none';
}

function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[char]);
}

async function analyzeTestImage(event) {
    event.preventDefault();
    const fileInput = document.getElementById('image-file');
    const urlInput = document.getElementById('image-url');
    const file = fileInput.files[0];
    const imageUrl = urlInput.value.trim();
    const error = document.getElementById('test-error');

    error.style.display = 'none';
    error.textContent = '';
    document.getElementById('test-result').style.display = 'none';
    if (!file && !imageUrl) {
        error.textContent = 'Добавьте ссылку или выберите файл изображения.';
        error.style.display = 'block';
        return;
    }
    if (file && file.size > 10 * 1024 * 1024) {
        error.textContent = 'Файл больше 10 МБ. Выберите изображение поменьше.';
        error.style.display = 'block';
        return;
    }

    const button = document.getElementById('analyze-button');
    button.disabled = true;
    document.getElementById('test-loading').style.display = 'block';
    try {
        const body = new FormData();
        if (file) body.append('image_file', file);
        else body.append('image_url', imageUrl);

        const response = await fetch(apiUrl('analyze_image'), { method: 'POST', body });
        const data = await response.json();
        logAiExchanges(data.ai_log);
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);
        if (data.ocr_diagnostics) logOcrDiagnostics('image-analysis', data.ocr_diagnostics);

        if (!file) {
            const preview = document.getElementById('test-image');
            if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
            preview.dataset.objectUrl = '';
            preview.src = imageUrl;
        }
        document.getElementById('test-rating').textContent = `🔮 ${Number(data.rating)}%`;
        document.getElementById('test-model').textContent = `Оценка по истории лайков и дизлайков · ${data.model}`;
        document.getElementById('test-description').innerHTML = `<strong>ИИ описание:</strong> ${escapeHtml(data.description || 'Описание не получено')}`;
        document.getElementById('test-why-funny').textContent = data.why_funny ? `Почему смешно: ${data.why_funny}` : '';
        document.getElementById('test-is-funny').textContent = `Смешно? ${data.is_funny ? 'Да' : 'Нет'}`;
        const ocr = document.getElementById('test-ocr');
        ocr.textContent = data.ocr ? `Текст на изображении: ${data.ocr}` : 'Текст на изображении не распознан';
        document.getElementById('test-result').style.display = 'block';
    } catch (e) {
        error.textContent = `Не удалось оценить изображение: ${e.message}`;
        error.style.display = 'block';
    } finally {
        document.getElementById('test-loading').style.display = 'none';
        button.disabled = false;
    }
}

// Загрузка актуального отфильтрованного мема с PHP-бэкенда
async function loadCurrentMeme() {
    try {
        const response = await fetch(apiUrl('get_current_meme'));
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        const data = await response.json();

        if (data?.status === 'loading') {
            document.getElementById('meme-card-wrapper').style.display = 'none';
            document.getElementById('meme-empty').style.display = 'none';
            document.getElementById('meme-error').style.display = 'none';
            setMemeLoadingMessage(data.message || 'Ищем мемы...', data.activity || []);
            clearTimeout(memePollTimer);
            memePollTimer = setTimeout(() => {
                memePollTimer = null;
                loadCurrentMeme();
            }, 3000);
            currentMeme = null;
            return;
        }

        clearTimeout(memePollTimer);
        memePollTimer = null;
        if (!data || data.status === 'empty' || !data.image) {
            document.getElementById('meme-card-wrapper').style.display = 'none';
            hideMemeLoading();
            document.getElementById('meme-empty-message').textContent = data?.message || 'Пул мемов пуст.';
            document.getElementById('meme-empty').style.display = 'block';
            appendAppLog(data?.message || 'Пул мемов пуст.');
            currentMeme = null;
            currentMemeSlides = [];
            return;
        }

        currentMeme = data;
        appendAppLog(`Показан мем: ${data.source || 'источник не указан'}, рейтинг ${Number(data.rating || 0)}%.`);
        currentMemeSlides = normalizeMemeSlides(data);
        currentSlideIndex = 0;
        if (currentMemeSlides.length) {
            const firstSlide = currentMemeSlides[0];
            currentMeme.image = currentMeme.image || firstSlide.image;
            if (!currentMeme.text && firstSlide.text) currentMeme.text = firstSlide.text;
        }

        renderMemeSlide();
        document.getElementById('meme-rating').innerText = `🔮 ${Number(data.rating || 0)}%`;
        document.getElementById('meme-source').innerText = `📍 Источник: ${data.source || 'Неизвестно'}`;
        const sourceLink = document.getElementById('meme-source-link');
        if (safeSourcePostUrl(data)) {
            sourceLink.href = safeSourcePostUrl(data);
            sourceLink.hidden = false;
        } else {
            sourceLink.removeAttribute('href');
            sourceLink.hidden = true;
        }
        document.querySelector('#meme-why-funny span').textContent = data.why_funny || 'Нет объяснения от ИИ';
        const funnyBadge = document.getElementById('meme-is-funny');
        funnyBadge.textContent = `Смешно? ${data.is_funny === true ? 'Да' : data.is_funny === false ? 'Нет' : 'Не оценено'}`;
        funnyBadge.classList.toggle('is-funny', data.is_funny === true);
        funnyBadge.classList.toggle('not-funny', data.is_funny === false);
        const description = document.getElementById('meme-desc');
        const label = document.createElement('strong');
        label.textContent = 'ИИ Описание:';
        description.replaceChildren(label, ` ${data.ai_description || 'Анализ отсутствует'}`);
        hideMemeLoading();
        document.getElementById('meme-empty').style.display = 'none';
        document.getElementById('meme-error').style.display = 'none';
        document.getElementById('meme-card-wrapper').style.display = 'block';
        document.getElementById('meme-tab').style.opacity = '1';
    } catch (error) {
        console.error('Ошибка при получении данных мема:', error);
        appendAppLog(`Ошибка загрузки мема: ${error.message}`);
        hideMemeLoading();
        document.getElementById('meme-card-wrapper').style.display = 'none';
        document.getElementById('meme-error').textContent = `Не удалось загрузить мем: ${error.message}`;
        document.getElementById('meme-error').style.display = 'block';
    }
}

async function voteMeme(voteType, details = {}) {
    if (!currentMeme) return;
    document.getElementById('meme-tab').style.opacity = '0.3';
    try {
        const response = await fetch(apiUrl('meme_vote'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ vote: voteType, meme: currentMeme, ...details })
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        await loadCurrentMeme();
    } catch (error) {
        console.error('Ошибка при сохранении голоса:', error);
        document.getElementById('meme-tab').style.opacity = '1';
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
