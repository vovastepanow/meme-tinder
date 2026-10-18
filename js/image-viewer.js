// image-viewer.js — полноэкранный просмотр картинок и видео.
// Колесо мыши увеличивает и уменьшает (вокруг курсора), перетаскивание за медиа или фон двигает его,
// клик по фону или Esc закрывает. У видео — обычный плеер со звуком; если файл не грузится ни из кэша,
// ни по исходной ссылке, показывается сообщение со ссылкой на исходный пост.

const ZOOM_LIMITS = { image: [0.2, 5], video: [0.25, 4] };
const ZOOM_STEP = 1.15;
const CLICK_TOLERANCE = 4;     // смещение указателя, после которого нажатие считается перетаскиванием
const MIN_FILL = 0.7;          // маленькие картинки увеличиваются минимум до 70% экрана
const VISIBLE_EDGE = 60;       // сколько пикселей медиа всегда остаётся на экране при сдвиге

const state = { kind: 'image', zoom: 1, x: 0, y: 0, drag: null, sourceUrl: '' };

const $ = id => document.getElementById(id);
const viewer = () => $('image-viewer');
const activeMedia = () => (state.kind === 'video' ? $('image-viewer-video') : $('image-viewer-image'));

function applyTransform() {
    const media = activeMedia();
    media.style.transform = `translate(${state.x}px, ${state.y}px) scale(${state.zoom})`;
    media.classList.toggle('is-zoomed', state.zoom !== 1);
    $('image-viewer-zoom').textContent = `${Math.round(state.zoom * 100)}%`;
    $('image-viewer-zoom').hidden = state.zoom === 1;
}

/** Картинка вписывается в окно; маленькая увеличивается минимум до MIN_FILL экрана. */
function fitImage() {
    const image = $('image-viewer-image');
    if (!image.naturalWidth || !image.naturalHeight) return;
    const fit = Math.min((window.innerWidth - 48) / image.naturalWidth, (window.innerHeight - 72) / image.naturalHeight);
    const scale = Math.min(fit, Math.max(1, fit * MIN_FILL));
    image.style.width = `${Math.round(image.naturalWidth * scale)}px`;
    image.style.height = `${Math.round(image.naturalHeight * scale)}px`;
}

/** Медиа можно двигать свободно, но на экране всегда остаётся хотя бы VISIBLE_EDGE пикселей. */
function clampOffset() {
    const media = activeMedia();
    const maxX = Math.max(0, (window.innerWidth + media.offsetWidth * state.zoom) / 2 - VISIBLE_EDGE);
    const maxY = Math.max(0, (window.innerHeight + media.offsetHeight * state.zoom) / 2 - VISIBLE_EDGE);
    state.x = Math.max(-maxX, Math.min(maxX, state.x));
    state.y = Math.max(-maxY, Math.min(maxY, state.y));
}

/** Зум вокруг курсора (точка под указателем остаётся на месте), в пределах ZOOM_LIMITS. */
function zoomAt(factor, clientX, clientY) {
    const [min, max] = ZOOM_LIMITS[state.kind];
    const previous = state.zoom;
    let zoom = Math.max(min, Math.min(max, previous * factor));
    if (Math.abs(zoom - 1) < 0.04) zoom = 1; // «прилипание» к исходному размеру
    if (zoom === previous) return;
    const pointX = clientX - window.innerWidth / 2;
    const pointY = clientY - window.innerHeight / 2;
    const ratio = zoom / previous;
    state.x = pointX - (pointX - state.x) * ratio;
    state.y = pointY - (pointY - state.y) * ratio;
    state.zoom = zoom;
    if (zoom === 1 && state.kind === 'video') state.x = state.y = 0;
    clampOffset();
    applyTransform();
}

function resetView(kind) {
    Object.assign(state, { kind, zoom: 1, x: 0, y: 0, drag: null });
    for (const media of [$('image-viewer-image'), $('image-viewer-video')]) {
        media.style.transform = '';
        media.classList.remove('is-zoomed');
    }
    $('image-viewer-zoom').hidden = true;
    hideStatus();
}

// ---- Сообщения поверх медиа: загрузка и ошибка ----

function showStatus(text, withSourceLink = false) {
    $('image-viewer-status-text').textContent = text;
    const link = $('image-viewer-source');
    link.hidden = !(withSourceLink && state.sourceUrl);
    if (state.sourceUrl) link.href = state.sourceUrl;
    $('image-viewer-status').hidden = false;
}

function hideStatus() {
    $('image-viewer-status').hidden = true;
}

function openViewer(kind, hint) {
    viewer().classList.toggle('is-video', kind === 'video');
    $('image-viewer-hint').textContent = hint;
    viewer().hidden = false;
    document.body.classList.add('image-viewer-open');
}

/**
 * Картинка. $fallback — запасной адрес (исходная ссылка), если основной (копия из кэша) не загрузится;
 * $sourceUrl — ссылка на исходный пост для сообщения об ошибке.
 */
export function openImageViewer(source, altText, { fallback = '', sourceUrl = '' } = {}) {
    resetView('image');
    state.sourceUrl = sourceUrl;
    const image = $('image-viewer-image');
    $('image-viewer-video').hidden = true;
    image.hidden = false;
    image.alt = altText || 'Просмотр изображения';
    image.style.width = '';
    image.style.height = '';
    image.onload = () => {
        hideStatus();
        fitImage();
    };
    image.onerror = () => {
        if (fallback && image.getAttribute('src') !== fallback) {
            image.src = fallback;
            return;
        }
        image.onerror = null;
        showStatus('Картинка недоступна: источник удалил файл или ссылка устарела.', true);
    };
    image.src = source;
    if (image.complete && image.naturalWidth) fitImage();
    applyTransform();
    openViewer('image', 'Колесо — увеличить или уменьшить, перетаскивание — сдвиг, клик по фону — закрыть');
}

/** Видео со звуком и стандартными контролами; при ошибке пробует запасной адрес, потом показывает сообщение. */
export function openVideoViewer(source, poster = '', fallback = '', sourceUrl = '') {
    resetView('video');
    state.sourceUrl = sourceUrl;
    const video = $('image-viewer-video');
    $('image-viewer-image').hidden = true;
    video.hidden = false;
    video.poster = poster;
    showStatus('Загрузка видео…');
    video.onloadeddata = hideStatus;
    video.onerror = () => {
        if (fallback && video.getAttribute('src') !== fallback) {
            showStatus('Сохранённая копия не открылась, пробую исходную ссылку…');
            video.src = fallback;
            video.play().catch(() => {});
            return;
        }
        video.onerror = null;
        const unsupported = video.error && video.error.code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED;
        showStatus(unsupported
            ? 'Видео не воспроизводится: файл недоступен или браузер не поддерживает его формат.'
            : 'Видео не загрузилось: источник удалил файл или ссылка устарела.', true);
    };
    video.src = source;
    video.muted = false;
    applyTransform();
    openViewer('video', 'Колесо — увеличить или уменьшить, Esc или клик по фону — закрыть');
    video.play().catch(() => { /* автозапуск запрещён — пользователь нажмёт «play» сам */ });
}

function closeViewer() {
    const video = $('image-viewer-video');
    viewer().hidden = true;
    viewer().classList.remove('is-dragging', 'is-video');
    $('image-viewer-image').removeAttribute('src');
    video.onerror = null;
    video.pause();
    video.removeAttribute('src');
    video.load();
    hideStatus();
    document.body.classList.remove('image-viewer-open');
    state.drag = null;
}

export function initializeImageViewer() {
    const root = viewer();

    // Клик по любой картинке страницы открывает просмотр; миниатюра видео (data-video) — плеер.
    document.addEventListener('click', event => {
        const target = event.target.closest('img');
        if (!target || target.closest('#image-viewer') || (!target.currentSrc && !target.src)) return;
        event.preventDefault();
        const sourceUrl = target.dataset.sourceUrl || '';
        if (target.dataset.video) {
            openVideoViewer(target.dataset.video, target.currentSrc || target.src, target.dataset.videoFallback || '', sourceUrl);
        } else {
            openImageViewer(target.currentSrc || target.src, target.alt, { fallback: target.dataset.fallback || '', sourceUrl });
        }
    });
    $('image-viewer-close').addEventListener('click', event => {
        event.stopPropagation();
        closeViewer();
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !root.hidden) closeViewer();
    });
    window.addEventListener('resize', () => {
        if (!root.hidden && state.kind === 'image') fitImage();
    });
    root.addEventListener('wheel', event => {
        event.preventDefault();
        zoomAt(event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP, event.clientX, event.clientY);
    }, { passive: false });

    // Перетаскивание: картинку — за любое место, видео — за фон (по самому видео работают его контролы).
    root.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.target.closest('button, a, video, .image-viewer-status')) return;
        state.drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, originX: state.x, originY: state.y, moved: false };
        root.setPointerCapture(event.pointerId);
    });
    root.addEventListener('pointermove', event => {
        const drag = state.drag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;
        if (!drag.moved && Math.hypot(dx, dy) < CLICK_TOLERANCE) return;
        drag.moved = true;
        root.classList.add('is-dragging');
        state.x = drag.originX + dx;
        state.y = drag.originY + dy;
        clampOffset();
        applyTransform();
    });
    root.addEventListener('pointerup', event => {
        const drag = state.drag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        state.drag = null;
        root.classList.remove('is-dragging');
        // Клик без перетаскивания: по фону — закрыть; по картинке — тоже закрыть (как раньше).
        if (!drag.moved) closeViewer();
    });
    root.addEventListener('pointercancel', () => {
        state.drag = null;
        root.classList.remove('is-dragging');
    });
}
