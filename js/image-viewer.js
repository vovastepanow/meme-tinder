// image-viewer.js — полноэкранный просмотр: картинки (зум колесом и перетаскивание) и видео (плеер со звуком).
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

/** Маленькие картинки увеличиваются минимум до IMAGE_VIEWER_MIN_FILL экрана, большие уменьшаются до размера окна. */
function fitImageViewer() {
    const image = document.getElementById('image-viewer-image');
    if (!image.naturalWidth || !image.naturalHeight) return;
    const fit = Math.min((window.innerWidth - 48) / image.naturalWidth, (window.innerHeight - 72) / image.naturalHeight);
    const scale = Math.min(fit, Math.max(1, fit * IMAGE_VIEWER_MIN_FILL));
    image.style.width = `${Math.round(image.naturalWidth * scale)}px`;
    image.style.height = `${Math.round(image.naturalHeight * scale)}px`;
}

/** Картинку можно двигать свободно, но на экране всегда остаётся хотя бы IMAGE_VIEWER_VISIBLE_EDGE пикселей. */
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
    // Зум вокруг курсора: точка под указателем остаётся на месте.
    const pointX = clientX - window.innerWidth / 2;
    const pointY = clientY - window.innerHeight / 2;
    const ratio = zoom / previousZoom;
    imageViewerState.x = pointX - (pointX - imageViewerState.x) * ratio;
    imageViewerState.y = pointY - (pointY - imageViewerState.y) * ratio;
    imageViewerState.zoom = zoom;
    clampImageViewerOffset();
    applyImageViewerTransform();
}

function openViewer() {
    document.getElementById('image-viewer').hidden = false;
    document.body.classList.add('image-viewer-open');
}

export function openImageViewer(source, altText) {
    const image = document.getElementById('image-viewer-image');
    document.getElementById('image-viewer').classList.remove('is-video');
    document.getElementById('image-viewer-video').hidden = true;
    document.getElementById('image-viewer-hint').textContent = 'Колесо — зум до 5×, перетаскивание — сдвиг, клик — закрыть';
    Object.assign(imageViewerState, { zoom: 1, x: 0, y: 0, drag: null });
    image.hidden = false;
    image.alt = altText || 'Просмотр изображения';
    image.style.width = '';
    image.style.height = '';
    image.onload = fitImageViewer;
    image.src = source;
    if (image.complete) fitImageViewer();
    applyImageViewerTransform();
    openViewer();
}

/**
 * Видео открывается со звуком и стандартными контролами. $fallback — запасной адрес
 * (исходная ссылка), если основной (копия из кэша) не загрузится.
 */
export function openVideoViewer(source, poster = '', fallback = '') {
    const video = document.getElementById('image-viewer-video');
    document.getElementById('image-viewer').classList.add('is-video');
    document.getElementById('image-viewer-image').hidden = true;
    document.getElementById('image-viewer-hint').textContent = 'Esc или клик по фону — закрыть';
    video.hidden = false;
    video.poster = poster;
    video.onerror = () => {
        if (fallback && video.getAttribute('src') !== fallback) {
            video.src = fallback;
            video.play().catch(() => {});
        }
    };
    video.src = source;
    video.muted = false;
    openViewer();
    video.play().catch(() => { /* автозапуск запрещён — пользователь нажмёт «play» сам */ });
}

function closeImageViewer() {
    const viewer = document.getElementById('image-viewer');
    const video = document.getElementById('image-viewer-video');
    viewer.hidden = true;
    viewer.classList.remove('is-dragging', 'is-video');
    document.getElementById('image-viewer-image').removeAttribute('src');
    video.pause();
    video.removeAttribute('src');
    video.load();
    document.body.classList.remove('image-viewer-open');
    imageViewerState.drag = null;
}

export function initializeImageViewer() {
    const viewer = document.getElementById('image-viewer');
    const image = document.getElementById('image-viewer-image');

    document.addEventListener('click', event => {
        const target = event.target.closest('img');
        if (!target || target === image || (!target.currentSrc && !target.src)) return;
        event.preventDefault();
        // Миниатюра видео (data-video) открывает плеер, обычная картинка — просмотр с зумом.
        if (target.dataset.video) openVideoViewer(target.dataset.video, target.currentSrc || target.src, target.dataset.videoFallback || '');
        else openImageViewer(target.currentSrc || target.src, target.alt);
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
        if (viewer.classList.contains('is-video')) return;
        event.preventDefault();
        zoomImageViewer(event.deltaY < 0 ? 1.2 : 1 / 1.2, event.clientX, event.clientY);
    }, { passive: false });

    viewer.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.target.closest('button, video')) return;
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
