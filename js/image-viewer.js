// image-viewer.js — единый просмотрщик картинок и видео: маленькое окно, которое облегает медиа.
// Окно состоит из шапки (имя файла — ссылка на файл, ссылка на пост, закрытие) и самого изображения или видео,
// и двигается целиком: тянуть можно за шапку, за картинку и за рамку вокруг видео. Колесо мыши масштабирует
// медиа, а окно подстраивается под его размер (точка под курсором остаётся на месте); клавиша 0 возвращает
// исходный размер. Закрывается кнопкой ×, Esc, кликом по затемнённому фону и (если файл в посте один) кликом по окну;
// в галерее из нескольких файлов левая кнопка мыши — предыдущий файл, правая — следующий. У видео — свои кнопки (video-controls.js);
// если файл не грузится ни из кэша, ни по исходной ссылке, показывается сообщение со ссылкой на исходный пост.
import { initializeVideoControls } from './video-controls.js';

const DEFAULT_VIDEO_VOLUME = 0.33; // видео открывается тише максимума
const ZOOM_LIMITS = { image: [0.15, 8], video: [0.3, 4] };
const ZOOM_STEP = 1.15;
const CLICK_TOLERANCE = 4;     // смещение указателя, после которого нажатие считается перетаскиванием
const MIN_FILL = 1;           // медиа вписывается в окно полностью: маленькие картинки увеличиваются до размера окна
const VISIBLE_EDGE = 60;       // сколько пикселей окна всегда остаётся на экране при сдвиге
const SCREEN_MARGIN = 14;      // поля вокруг окна при открытии: просмотрщик занимает почти весь экран
const MIN_WINDOW_WIDTH = 90;   // окно облегает даже совсем маленькую картинку; имя файла в шапке при этом сокращается
const DEFAULT_MEDIA = { width: 640, height: 360 }; // пока размеры медиа неизвестны (идёт загрузка)

const state = { kind: 'image', zoom: 1, x: 0, y: 0, base: { ...DEFAULT_MEDIA }, drag: null, sourceUrl: '', moved: false, justDragged: false, gallery: null };

/**
 * Галерея — все медиафайлы одного поста: {items: [{kind: 'image'|'video', src, fallback, poster, sourceUrl, alt}], index}.
 * Пока в галерее больше одного файла, в просмотрщике левая кнопка мыши открывает предыдущий файл, правая — следующий
 * (по кругу); закрыть можно кнопкой × (или 🚀 — она публикует пост, если галерея это умеет), Esc или кликом по затемнённому фону. С одним файлом левый клик закрывает окно.
 * Страница отдаёт галерею по имени: элемент с data-gallery="имя" и провайдер, зарегистрированный через registerViewerGallery.
 */
const galleryProviders = {};

/** provider(элемент) → {items, index} или null. */
export function registerViewerGallery(name, provider) {
    galleryProviders[name] = provider;
}

const $ = id => document.getElementById(id);
const root = () => $('image-viewer');
const frame = () => $('image-viewer-window');
const activeMedia = () => (state.kind === 'video' ? $('image-viewer-video') : $('image-viewer-image'));

/** Имя файла для шапки: последняя часть пути адреса (для data: и пустых адресов — общее название). */
export function mediaFileName(url, kind = 'image') {
    const fallbackName = kind === 'video' ? 'Видео' : 'Изображение';
    if (!url || url.startsWith('data:') || url.startsWith('blob:')) return fallbackName;
    try {
        const parsed = new URL(url, window.location.href);
        const name = decodeURIComponent(parsed.pathname.split('/').filter(Boolean).pop() || '');
        return name || parsed.hostname || fallbackName;
    } catch {
        return fallbackName;
    }
}

/** Шапка: имя файла — ссылка на файл. Предпочитаем исходную ссылку (у копии из кэша имя — хэш). */
function setHeader(kind, primary, original) {
    const fileUrl = original && !original.startsWith('data:') ? original : primary;
    const link = $('image-viewer-file');
    link.textContent = mediaFileName(fileUrl, kind);
    const linkable = fileUrl && !fileUrl.startsWith('data:');
    link.removeAttribute('title');
    if (linkable) link.href = fileUrl;
    else link.removeAttribute('href');
    const many = (state.gallery?.items.length || 0) > 1;
    $('image-viewer-position').hidden = !many;
    if (many) $('image-viewer-position').textContent = `${state.gallery.index + 1} / ${state.gallery.items.length}`;
    const post = $('image-viewer-post');
    post.hidden = !state.sourceUrl;
    if (state.sourceUrl) post.href = state.sourceUrl;
    else post.removeAttribute('href');
}

// ---- Размер и положение окна ----

/** Размер медиа в окне при масштабе 100%: вписывается в экран, маленькое увеличивается минимум до MIN_FILL. */
function fitSize(width, height) {
    const header = $('image-viewer-header').offsetHeight || 40;
    const room = {
        width: Math.max(120, window.innerWidth - SCREEN_MARGIN * 2 - 12),
        height: Math.max(120, window.innerHeight - SCREEN_MARGIN * 2 - header - 12)
    };
    const fit = Math.min(room.width / width, room.height / height);
    const scale = Math.min(fit, Math.max(1, fit * MIN_FILL));
    return { width: Math.round(width * scale), height: Math.round(height * scale) };
}

/** Применяет масштаб: размер медиа = базовый размер × zoom, окно подстраивается под него. */
function applyLayout() {
    const media = activeMedia();
    const width = Math.round(state.base.width * state.zoom);
    const height = Math.round(state.base.height * state.zoom);
    media.style.width = `${width}px`;
    media.style.height = `${height}px`;
    frame().style.width = `${Math.max(MIN_WINDOW_WIDTH, width + 12)}px`; // +12 — рамка вокруг медиа
    frame().style.transform = `translate(${Math.round(state.x)}px, ${Math.round(state.y)}px)`;
}

/** Окно можно двигать свободно, но на экране всегда остаётся хотя бы VISIBLE_EDGE пикселей. */
function clampPosition() {
    const box = frame();
    state.x = Math.max(VISIBLE_EDGE - box.offsetWidth, Math.min(window.innerWidth - VISIBLE_EDGE, state.x));
    state.y = Math.max(VISIBLE_EDGE - box.offsetHeight, Math.min(window.innerHeight - VISIBLE_EDGE, state.y));
}

function centerWindow() {
    const box = frame();
    state.x = (window.innerWidth - box.offsetWidth) / 2;
    state.y = (window.innerHeight - box.offsetHeight) / 2;
}

/** Размеры медиа стали известны (картинка или видео загрузились): окно облегает их и встаёт по центру. */
function setMediaSize(width, height) {
    if (!width || !height) return;
    state.base = fitSize(width, height);
    state.zoom = 1;
    applyLayout();
    centerWindow();
    applyLayout();
}

/** Масштаб вокруг курсора: точка медиа под указателем остаётся на месте, окно растёт или сжимается вместе с медиа. */
function zoomAt(factor, clientX, clientY) {
    const [min, max] = ZOOM_LIMITS[state.kind];
    const previous = state.zoom;
    let zoom = Math.max(min, Math.min(max, previous * factor));
    if (Math.abs(zoom - 1) < 0.04) zoom = 1; // «прилипание» к исходному размеру
    if (zoom === previous) return;
    const media = activeMedia();
    const rect = media.getBoundingClientRect();
    const frameTop = state.y;
    const mediaTop = rect.top;
    const ratio = zoom / previous;
    // Левый и верхний край медиа сдвигаются так, чтобы точка под курсором не уехала.
    const newLeft = clientX - (clientX - rect.left) * ratio;
    const newTop = clientY - (clientY - mediaTop) * ratio;
    state.x += newLeft - rect.left;
    state.y = frameTop + (newTop - mediaTop);
    state.zoom = zoom;
    applyLayout();
    clampPosition();
    applyLayout();
}

function resetZoom() {
    state.zoom = 1;
    applyLayout();
    centerWindow();
    applyLayout();
}

/**
 * Останавливает видео просмотрщика. Без этого при переходе к другому файлу галереи скрытое видео продолжает играть:
 * картинка на экране, а звук прежнего ролика идёт дальше.
 */
function stopViewerVideo() {
    const video = $('image-viewer-video');
    video.onerror = null;
    video.onloadedmetadata = null;
    video.onloadeddata = null;
    video.pause();
    video.removeAttribute('src');
    video.load();
}

function resetView(kind) {
    stopViewerVideo();
    Object.assign(state, { kind, zoom: 1, base: { ...DEFAULT_MEDIA }, drag: null, moved: false, justDragged: false });
    for (const media of [$('image-viewer-image'), $('image-viewer-video')]) {
        media.style.width = '';
        media.style.height = '';
    }
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

function openViewer(kind) {
    root().classList.toggle('is-video', kind === 'video');
    root().hidden = false;
    document.body.classList.add('image-viewer-open');
    applyLayout();
    centerWindow();
    applyLayout();
}

/**
 * Картинка. $fallback — запасной адрес (исходная ссылка), если основной (копия из кэша) не загрузится;
 * $sourceUrl — ссылка на исходный пост (в шапке и в сообщении об ошибке).
 */
export function openImageViewer(source, altText, { fallback = '', sourceUrl = '', gallery = null } = {}) {
    state.gallery = gallery;
    syncRocket();
    resetView('image');
    state.sourceUrl = sourceUrl;
    setHeader('image', source, fallback);
    const image = $('image-viewer-image');
    $('image-viewer-video').hidden = true;
    image.hidden = false;
    image.alt = altText || 'Просмотр изображения';
    const stage = $('image-viewer-stage');
    stage.classList.add('is-loading');   // кольцо загрузки, пока картинка не пришла
    image.onload = () => {
        stage.classList.remove('is-loading');
        hideStatus();
        setMediaSize(image.naturalWidth, image.naturalHeight);
    };
    image.onerror = () => {
        if (fallback && image.getAttribute('src') !== fallback) {
            image.src = fallback;
            return;
        }
        image.onerror = null;
        stage.classList.remove('is-loading');
        showStatus('Картинка недоступна: источник удалил файл или ссылка устарела.', true);
    };
    openViewer('image');
    image.src = source;
    if (image.complete && image.naturalWidth) setMediaSize(image.naturalWidth, image.naturalHeight);
}

/** Видео со звуком и своими кнопками (video-controls.js); при ошибке пробует запасной адрес, потом показывает сообщение. */
export function openVideoViewer(source, poster = '', fallback = '', sourceUrl = '', gallery = null) {
    state.gallery = gallery;
    syncRocket();
    resetView('video');
    state.sourceUrl = sourceUrl;
    setHeader('video', source, fallback);
    const video = $('image-viewer-video');
    $('image-viewer-image').hidden = true;
    video.hidden = false;
    video.poster = poster;
    showStatus('Загрузка видео…');
    video.onloadedmetadata = () => setMediaSize(video.videoWidth, video.videoHeight);
    video.onloadeddata = () => { $('image-viewer-stage').classList.remove('is-loading'); hideStatus(); };
    $('image-viewer-stage').classList.add('is-loading');   // кольцо загрузки, пока видео не готово
    video.onerror = () => {
        if (fallback && video.getAttribute('src') !== fallback) {
            showStatus('Сохранённая копия не открылась, пробую исходную ссылку…');
            video.src = fallback;
            video.play().catch(() => {});
            return;
        }
        video.onerror = null;
        $('image-viewer-stage').classList.remove('is-loading');
        const unsupported = video.error && video.error.code === MediaError.MEDIA_ERR_SRC_NOT_SUPPORTED;
        showStatus(unsupported
            ? 'Видео не воспроизводится: файл недоступен или браузер не поддерживает его формат.'
            : 'Видео не загрузилось: источник удалил файл или ссылка устарела.', true);
    };
    video.src = source;
    video.muted = false;
    video.volume = DEFAULT_VIDEO_VOLUME;
    openViewer('video');
    video.play().catch(() => { /* автозапуск запрещён — пользователь нажмёт «play» сам */ });
}

/** Показывает файл галереи под номером index. */
function showGalleryItem(gallery, index) {
    const item = gallery.items[index];
    const current = { items: gallery.items, index, onPost: gallery.onPost, postCheck: gallery.postCheck };
    if (item.kind === 'video') openVideoViewer(item.src, item.poster || '', item.fallback || '', item.sourceUrl || '', current);
    else openImageViewer(item.src, item.alt, { fallback: item.fallback || '', sourceUrl: item.sourceUrl || '', gallery: current });
}

/** Открывает галерею с файла index. */
export function openViewerGallery(items, index = 0, onPost = null, postCheck = null) {
    if (!items?.length) return;
    showGalleryItem({ items, onPost, postCheck }, Math.min(Math.max(0, index), items.length - 1));
}

/** Переход по галерее на delta файлов (по кругу). false, если в галерее меньше двух файлов. */
function stepGallery(delta) {
    const gallery = state.gallery;
    if (!gallery || gallery.items.length < 2) return false;
    showGalleryItem(gallery, (gallery.index + delta + gallery.items.length) % gallery.items.length);
    return true;
}

/** Публикация открытого файла, если у галереи нет своего действия (onPost публикует весь пост). Задаётся из content.js. */
let mediaPoster = null;
export function registerViewerPoster(poster) {
    mediaPoster = poster;
}

/** Ракета в шапке одинакова во всех просмотрщиках: публикует пост галереи, а без него — открытый файл. */
function postFromViewer() {
    const gallery = state.gallery;
    if (typeof gallery?.onPost === 'function') return gallery.onPost;
    if (!mediaPoster) return null;
    const item = gallery?.items?.[gallery.index];
    const media = item
        ? { kind: item.kind, src: item.src, poster: item.poster || '', sourceUrl: item.sourceUrl || '' }
        : state.kind === 'video'
            ? { kind: 'video', src: $('image-viewer-video').getAttribute('src') || '', poster: '', sourceUrl: state.sourceUrl }
            : { kind: 'image', src: $('image-viewer-image').getAttribute('src') || '', poster: '', sourceUrl: state.sourceUrl };
    return media.src ? () => mediaPoster(media) : null;
}

/** Ракета в шапке показывает результат предварительной проверки поста (gallery.postCheck): перечёркнута или с жёлтой точкой. */
function syncRocket() {
    const button = $('image-viewer-rocket');
    const check = state.gallery?.postCheck;
    const problems = [...(check?.problems || []), ...(check?.warnings || [])];
    button.classList.toggle('rocket-blocked', check?.status === 'blocked');
    button.classList.toggle('rocket-warn', check?.status !== 'blocked' && problems.length > 0);
    button.title = problems.length ? `${check.status === 'blocked' ? 'Запостить нельзя' : 'Есть замечания'}: ${problems.join(' · ')}` : 'Запостить в Telegram (закрыть окно — Esc или клик по фону)';
}

function closeViewer() {
    $('image-viewer-stage')?.classList.remove('is-loading');
    if (document.fullscreenElement) document.exitFullscreen().catch(() => {});
    root().hidden = true;
    state.gallery = null;
    syncRocket();
    root().classList.remove('is-dragging', 'is-video');
    $('image-viewer-image').removeAttribute('src');
    stopViewerVideo();
    hideStatus();
    document.body.classList.remove('image-viewer-open');
    state.drag = null;
}

export function initializeImageViewer() {
    const overlay = root();
    initializeVideoControls();
    const box = frame();

    // Клик по любой картинке страницы открывает просмотр; миниатюра видео (data-video) — плеер.
    document.addEventListener('click', event => {
        const target = event.target.closest('img');
        if (!target || target.closest('#image-viewer') || (!target.currentSrc && !target.src)) return;
        event.preventDefault();
        if (target.dataset.external) {   // видео, которое Telegram не отдаёт: вместо просмотрщика — сам пост в Telegram
            window.open(target.dataset.external, '_blank', 'noopener,noreferrer');
            return;
        }
        const provided = target.dataset.gallery ? galleryProviders[target.dataset.gallery]?.(target) : null;
        if (provided?.items?.length) {
            openViewerGallery(provided.items, provided.index, provided.onPost, provided.postCheck);
            return;
        }
        const sourceUrl = target.dataset.sourceUrl || '';
        if (target.dataset.video) {
            openVideoViewer(target.dataset.video, target.currentSrc || target.src, target.dataset.videoFallback || '', sourceUrl);
        } else {
            openImageViewer(target.currentSrc || target.src, target.alt, { fallback: target.dataset.fallback || '', sourceUrl });
        }
    });
    document.addEventListener('keydown', event => {
        if (overlay.hidden) return;
        if (event.key === 'Escape') closeViewer();
        else if ((event.key === '+' || event.key === '=') && !event.target.closest('video')) zoomAt(ZOOM_STEP, window.innerWidth / 2, window.innerHeight / 2);
        else if (event.key === '-' && !event.target.closest('video')) zoomAt(1 / ZOOM_STEP, window.innerWidth / 2, window.innerHeight / 2);
        else if (event.key === '0') resetZoom();
    });
    window.addEventListener('resize', () => {
        if (overlay.hidden) return;
        clampPosition();
        applyLayout();
    });
    overlay.addEventListener('wheel', event => {
        event.preventDefault();
        zoomAt(event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP, event.clientX, event.clientY);
    }, { passive: false });
    // Перетаскивание окна: за шапку, картинку, рамку и сам кадр видео (внизу видео остаётся полоса его кнопок).
    box.addEventListener('pointerdown', event => {
        if (event.button !== 0 || event.target.closest('button, video, .image-viewer-status, .video-controls')) return; // за ссылки тоже можно тянуть
        state.drag = { pointerId: event.pointerId, startX: event.clientX, startY: event.clientY, originX: state.x, originY: state.y, moved: false };
    });
    box.addEventListener('pointermove', event => {
        const drag = state.drag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;
        if (!drag.moved && Math.hypot(dx, dy) < CLICK_TOLERANCE) return;
        drag.moved = true;
        box.setPointerCapture(event.pointerId); // захват только при настоящем перетаскивании: обычные клики по ссылкам не страдают
        overlay.classList.add('is-dragging');
        state.x = drag.originX + dx;
        state.y = drag.originY + dy;
        clampPosition();
        applyLayout();
    });
    // Ссылки браузер пытается перетащить как закладку — это сорвало бы перетаскивание окна.
    box.addEventListener('dragstart', event => event.preventDefault());
    // Клик по окну закрывает его, по самому видео — тоже; ссылки (файл, пост) и панель управления плеера работают как обычно.
    // После перетаскивания окно не закрывается, а переход по ссылке, за которую тянули, отменяется.
    box.addEventListener('click', event => {
        const dragged = state.justDragged;
        state.justDragged = false;
        if (event.target.closest('a')) {
            if (dragged) event.preventDefault();
            return;
        }
        if (dragged) return;
        if (event.target.closest('video, .video-controls')) return; // кнопки плеера окно не закрывают
        if (event.target.closest('#image-viewer-rocket')) {   // окно закрывается, поверх открывается редактор поста
            const post = postFromViewer();
            closeViewer();
            if (post) post();
            return;
        }
        if (stepGallery(-1)) return;                                  // в галерее левый клик — предыдущий файл
        closeViewer();
    });
    // Правая кнопка — следующий файл галереи (меню браузера в галерее не нужно); без галереи, на ссылках и плеере меню обычное
    box.addEventListener('contextmenu', event => {
        if (event.target.closest('a, .video-controls') || !state.gallery || state.gallery.items.length < 2) return;
        event.preventDefault();
        stepGallery(1);
    });
    const endDrag = event => {
        const drag = state.drag;
        if (!drag || drag.pointerId !== event.pointerId) return;
        state.justDragged = drag.moved;
        state.drag = null;
        overlay.classList.remove('is-dragging');
    };
    box.addEventListener('pointerup', endDrag);
    box.addEventListener('pointercancel', endDrag);

    // Клик по затемнённому фону (вне окна) закрывает просмотр.
    overlay.addEventListener('click', event => {
        if (event.target === overlay) closeViewer();
    });
}
