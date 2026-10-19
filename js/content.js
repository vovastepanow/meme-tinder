// content.js — вкладка «Подбор»: тема поиска, текущая запись из пула, карусель, видео, голоса и жалобы.
import { $, DEBUG_MODE, api, el, hide, log, onParserStateChange, pluralRu, safeSourcePostUrl, setMessage, show, watchParser } from './core.js';
import { openImageViewer, openVideoViewer, openViewerGallery, registerViewerGallery, registerViewerPoster } from './image-viewer.js';
import { openPostEditor } from './post-editor.js';
import { formatWhen, pickDateTime } from './datetime-picker.js';
import { openAccountSection } from './account.js';

const POLL_INTERVAL_MS = 3000;
const NO_TEXT = 'Текст к посту отсутствует';

const state = {
    item: null,
    slides: [],
    slideIndex: 0,
    pollTimer: null,
    voting: false,
    preloaded: [],
    telegram: null   // подключённый канал {title, username} или null (узнаётся при открытии панели 🚀)
};

function stopPolling() {
    clearTimeout(state.pollTimer);
    state.pollTimer = null;
}

/** Одна из взаимоисключающих областей вкладки: карточка, «пул пуст», ошибка или ничего (идёт поиск). */
function showArea(area, message = '') {
    show('item-card-wrapper', area === 'card');
    show('item-empty', area === 'empty');
    setMessage('item-error', area === 'error' ? message : '');
    setMessage('item-searching', area === 'searching' ? 'Ищем контент по теме… Ход поиска и размышления модели — в журнале.' : '');
    if (area === 'empty') $('item-empty-message').textContent = message || 'Пул контента пуст.';
    hide('item-loading');
}

// ---- Слайды и медиа ----

/**
 * Слайды записи: видео — один слайд, иначе по слайду на картинку. У каждого слайда основной адрес
 * (копия из кэша сервера) и запасной (исходная ссылка) — на случай, если основной не загрузится.
 */
function itemSlides(item) {
    if (!item) return [];
    const text = item.text || item.description || NO_TEXT;
    if (Array.isArray(item.gallery) && item.gallery.length > 1) {
        return item.gallery.map((file, index) => {
            const image = file.display_image || file.image;   // сервер уже подставил копию из кэша и для главного файла записи
            const video = file.video ? (file.display_video || file.video) : '';
            return { image, fallbackImage: file.image, video, fallbackVideo: file.video || '', text };
        });
    }
    if (item.video || item.display_video) {
        return [{
            image: item.display_image || item.image || '',
            fallbackImage: item.image || '',
            video: item.display_video || item.video,
            fallbackVideo: item.video || '',
            text
        }];
    }
    const images = Array.isArray(item.images) && item.images.length ? item.images : [item.image].filter(Boolean);
    const captions = Array.isArray(item.captions) ? item.captions : [];
    return images.map((image, index) => ({
        image: index === 0 ? (item.display_image || image) : image,
        fallbackImage: image,
        text: captions[index] || item.text || ''
    }));
}

/** Картинка с запасным адресом: если копия из кэша не открылась, пробуем исходную ссылку, потом показываем заглушку. */
function setImageWithFallback(image, slide) {
    const wrap = image.parentElement;
    wrap.classList.add('is-loading');   // кольцо загрузки, пока картинка не пришла
    image.onload = () => wrap.classList.remove('is-loading');
    image.onerror = () => {
        if (slide.fallbackImage && image.getAttribute('src') !== slide.fallbackImage) {
            image.src = slide.fallbackImage;
            return;
        }
        image.onerror = null;
        wrap.classList.remove('is-loading');
        image.hidden = true;
        if (!$('item-video').hidden) return;   // обложка не нужна, пока играет само видео
        show('item-media-fallback');
        log(`Картинка не загрузилась: ${slide.fallbackImage || slide.image}`);
    };
    image.src = slide.image;
}

const VIDEO_ERROR_NAMES = { 1: 'загрузка прервана', 2: 'сетевая ошибка', 3: 'файл не раскодировался', 4: 'формат не поддерживается или файл недоступен' };

/**
 * Возобновляет видео в карточке, если браузер его не запустил или поставил на паузу: страница открылась в фоновой вкладке,
 * автозапуск запрещён расширением или настройкой сайта. Срабатывает при возврате на вкладку и на первое нажатие или клавишу.
 */
function resumeCardVideo() {
    const video = $('item-video');
    if (!video.hidden && video.paused && video.getAttribute('src')) video.play().catch(() => {});
}

const COUNT_FORMAT = new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 });
const ENGAGEMENT_MAX_REACTIONS = 8;   // больше эмодзи в строку не выводим: остальные сворачиваются в «+N»

/** Длительность треда: часы, а после двух суток — дни. */
function formatThreadSpan(hours) {
    return hours < 48 ? `${Math.max(1, Math.round(hours))} ч` : `${Math.round(hours / 24)} дн`;
}

function engagementChip(label, value, title = '') {
    return el('span', { className: 'eng-chip', title }, `${label} `, el('b', { textContent: value }));
}

/** Пометка «выше/ниже обычного» для числа по сравнению с базой (соседние посты канала, треды доски); пусто, если разница мала. */
function engagementTrend(value, base, what) {
    if (!base || base <= 0) return null;
    const ratio = value / base;
    if (ratio >= 1.5) return el('span', { className: 'eng-chip eng-up', textContent: '↑ выше обычного', title: `${what}: ${COUNT_FORMAT.format(value)}, обычно ~${COUNT_FORMAT.format(Math.round(base))}` });
    if (ratio <= 0.6) return el('span', { className: 'eng-chip eng-down', textContent: '↓ ниже обычного', title: `${what}: ${COUNT_FORMAT.format(value)}, обычно ~${COUNT_FORMAT.format(Math.round(base))}` });
    return null;
}

/**
 * Активность поста у источника (поле engagement, см. lib/engagement.php): в Telegram — просмотры и реакции по эмодзи,
 * на Дваче — ответы на пост и размер треда. Показывается то, что удалось собрать; нет данных — блок скрыт.
 */
function renderEngagement(e) {
    const box = $('item-engagement');
    const chips = [];
    if (e?.source === 'telegram' && Number.isFinite(e.views)) {
        chips.push(engagementChip('просмотры', COUNT_FORMAT.format(e.views), `Обычно у постов этого канала: ~${COUNT_FORMAT.format(Math.round(e.neighbors_median_views ?? 0))}`));
        const trend = engagementTrend(e.views, e.neighbors_median_views, 'Просмотры');
        if (trend) chips.push(trend);
        const reactions = Object.entries(e.reactions || {}).filter(([, count]) => count > 0).sort((a, b) => b[1] - a[1]);
        for (const [emoji, count] of reactions.slice(0, ENGAGEMENT_MAX_REACTIONS)) {
            chips.push(el('span', { className: 'eng-chip eng-reaction', title: `Реакций ${emoji}: ${count}` }, el('span', { className: 'eng-emoji', textContent: emoji }), el('b', { textContent: COUNT_FORMAT.format(count) })));
        }
        if (reactions.length > ENGAGEMENT_MAX_REACTIONS) chips.push(el('span', { className: 'eng-chip', textContent: `+${reactions.length - ENGAGEMENT_MAX_REACTIONS}` }));
        if (!reactions.length) chips.push(el('span', { className: 'eng-chip', textContent: 'реакций нет' }));
    } else if (e?.source === 'dvach' && Number.isFinite(e.thread_posts)) {
        // У ОП-поста ответов почти столько же, сколько постов в треде, поэтому для него показывается только тред
        if (!e.is_op) chips.push(engagementChip('ответы', COUNT_FORMAT.format(e.replies ?? 0), 'Сколько постов в треде ответили на этот пост (>>номер)'));
        chips.push(engagementChip('тред', `${COUNT_FORMAT.format(e.thread_posts)} ${pluralRu(e.thread_posts, 'пост', 'поста', 'постов')}${e.thread_hours ? ` за ${formatThreadSpan(e.thread_hours)}` : ''}`));
        const trend = engagementTrend(e.thread_posts, e.board_median_thread_posts, 'Постов в треде');
        if (trend) chips.push(trend);
    }
    box.replaceChildren(...chips);
    box.hidden = chips.length === 0;
}

/** Видео слайда в карточке: сначала копия из кэша, при ошибке исходная ссылка, потом обложка. */
function renderCardVideo(slide) {
    const video = $('item-video');
    video.onerror = null;
    if (!slide.video) {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.hidden = true;
        return;
    }
    video.onerror = () => {
        const reason = VIDEO_ERROR_NAMES[video.error?.code] || 'неизвестная ошибка';
        if (slide.fallbackVideo && video.getAttribute('src') !== slide.fallbackVideo) {
            log(`Видео в карточке не открылось (${reason}), пробую исходную ссылку.`);
            video.src = slide.fallbackVideo;
            return;
        }
        log(`Видео в карточке не воспроизводится (${reason}): ${slide.fallbackVideo || slide.video}`);
        video.onerror = null;
        video.parentElement.classList.remove('is-loading');
        video.hidden = true;                 // файл не открылся: остаётся обложка, по клику плеер сам попробует ещё раз
        $('item-image').hidden = !slide.image;
    };
    const wrap = video.parentElement;
    wrap.classList.add('is-loading');        // кольцо загрузки, пока видео не готово к показу
    video.addEventListener('loadeddata', () => wrap.classList.remove('is-loading'), { once: true });
    video.poster = slide.image || '';
    video.muted = true;                      // автозапуск разрешён браузерами только без звука
    video.hidden = false;
    video.src = slide.video;
    video.play().catch(error => {
        // Браузер не дал запустить видео само: возобновится при возврате на вкладку или первом нажатии (resumeCardVideo)
        log(`Браузер не запустил видео сам (${error.name}). Оно начнётся после первого клика или нажатия клавиши; если нет — проверьте разрешение автовоспроизведения для этого сайта и расширения, блокирующие видео.`);
    });
}

function renderSlide() {
    const slide = state.slides[state.slideIndex] || { image: '', text: 'Нет изображения' };
    const image = $('item-image');
    hide('item-media-fallback');

    // Видео в карточке играет само (без звука, по кругу); клик открывает плеер в просмотрщике.
    // Обложка остаётся запасным вариантом: если файл не загрузился, показывается она.
    const isVideo = Boolean(slide.video);
    renderCardVideo(slide);
    image.classList.toggle('is-video-thumb', isVideo);
    if (isVideo) {
        image.dataset.video = slide.video;
        image.dataset.videoFallback = slide.fallbackVideo || '';
    } else {
        delete image.dataset.video;
        delete image.dataset.videoFallback;
    }
    image.alt = isVideo ? 'Видео: нажмите, чтобы смотреть' : 'Запись';
    image.dataset.gallery = 'card';
    $('item-video-mark').hidden = !isVideo;   // значок «это видео, а не гифка»
    // Для просмотрщика: запасной адрес картинки и ссылка на пост для сообщения об ошибке.
    image.dataset.fallback = slide.fallbackImage || '';
    image.dataset.sourceUrl = safeSourcePostUrl(state.item);
    image.hidden = !slide.image || (isVideo && !$('item-video').hidden);
    if (slide.image) setImageWithFallback(image, slide);
    else image.removeAttribute('src');

    $('item-slide-caption').textContent = slide.text || state.item?.text || NO_TEXT;
    const hasMultiple = state.slides.length > 1;
    for (const button of [$('carousel-prev'), $('carousel-next')]) {
        button.disabled = !hasMultiple;
        button.hidden = !hasMultiple;   // у одиночной картинки стрелок нет, а место под них не резервируется
    }
    $('item-slide-position').textContent = hasMultiple ? `${state.slideIndex + 1} / ${state.slides.length}` : '';
}

function changeSlide(delta) {
    if (!state.slides.length) return;
    state.slideIndex = (state.slideIndex + delta + state.slides.length) % state.slides.length;
    renderSlide();
}

/** Все медиафайлы записи как галерея просмотрщика (левая кнопка мыши — предыдущий, правая — следующий). */
function cardGallery() {
    const sourceUrl = safeSourcePostUrl(state.item);
    return {
        index: state.slideIndex,
        items: state.slides.map(slide => ({
            kind: slide.video ? 'video' : 'image',
            src: slide.video || slide.image,
            fallback: slide.video ? slide.fallbackVideo : slide.fallbackImage,
            poster: slide.image,
            sourceUrl,
            alt: 'Запись'
        })),
        onPost: onPostFor(state.item),
        postCheck: state.item?.post_check || null
    };
}

/** Действие ракеты в просмотрщике: открыть редактор поста для этой записи (запись запоминается на момент открытия). */
function onPostFor(item) {
    return item ? () => postItemToTelegram(item).catch(error => log(`Не удалось открыть редактор: ${error.message}`)) : null;
}

/** Открывает текущий слайд на весь экран: видео — плеером со звуком, картинку — с зумом. */
function expandCurrentSlide() {
    if (!state.slides[state.slideIndex]) return;
    const gallery = cardGallery();
    openViewerGallery(gallery.items, gallery.index, gallery.onPost, gallery.postCheck);
}

/** Браузер заранее скачивает картинки следующих записей, чтобы они открывались сразу. */
function preloadUpcoming(urls) {
    state.preloaded = (Array.isArray(urls) ? urls : []).map(url => {
        const image = new Image();
        image.decoding = 'async';
        image.src = url;
        return image;
    });
}

// ---- Карточка ----

/** «5 мин назад», «3 ч назад», «2 дн назад» или дата, если давно. */
function formatPostedAt(timestamp) {
    const seconds = Number(timestamp);
    if (!seconds) return '';
    const minutes = Math.max(0, Math.round((Date.now() / 1000 - seconds) / 60));
    if (minutes < 60) return `${minutes || 1} мин назад`;
    if (minutes < 48 * 60) return `${Math.round(minutes / 60)} ч назад`;
    if (minutes < 14 * 24 * 60) return `${Math.round(minutes / 1440)} дн назад`;
    return new Date(seconds * 1000).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' });
}

function renderCard(item) {
    state.item = item;
    state.slides = itemSlides(item);
    state.slideIndex = 0;
    renderSlide();
    applyRocketCheck($('item-rocket'), item.post_check);
    preloadUpcoming(item.preload);

    const relevance = Number(item.relevance ?? 0);
    // Прогноза нет, когда персональный подбор выключен (PERSONAL_TASTE_ENABLED в config.php)
    const rating = item.rating == null ? null : Number(item.rating);
    $('item-rating').textContent = rating === null ? '' : `${rating}%`;
    $('item-rating').hidden = rating === null;
    $('item-relevance').textContent = `По теме: ${relevance}%`;
    $('item-source').textContent = `Источник: ${item.source || 'неизвестно'}`;
    const posted = formatPostedAt(item.posted_at);
    $('item-date').textContent = posted ? `Опубликовано: ${posted}` : '';
    $('item-date').hidden = !posted;
    renderEngagement(item.engagement);

    const link = $('item-source-link');
    const url = safeSourcePostUrl(item);
    if (url) link.href = url;
    else link.removeAttribute('href');
    link.hidden = !url;

    $('item-why-text').textContent = item.why_relevant || 'Нет объяснения от ИИ';
    $('item-desc').replaceChildren(el('strong', { textContent: 'Описание ИИ:' }), ` ${item.description || 'Анализ отсутствует'}`);

    showArea('card');
    const shownSource = item.source || 'источник не указан';
    log(`Показана запись: ${url ? `[${shownSource}](${url})` : shownSource}, по теме ${relevance}%${rating === null ? '' : `, прогноз ${rating}%`}.`);
}

/** Загружает первую запись пула. Пока парсер ищет контент, повторяет запрос (журнал парсера ведёт консоль). */
export async function loadCurrentItem() {
    stopPolling();
    try {
        const data = await api('get_current_item');

        if (data.status === 'loading') {
            state.item = null;
            showArea('searching');
            watchParser();
            state.pollTimer = setTimeout(loadCurrentItem, POLL_INTERVAL_MS);
            return;
        }
        if (data.status === 'empty' || !data.image) {
            state.item = null;
            state.slides = [];
            showArea('empty', data.message);
            log(data.message || 'Пул контента пуст.');
            return;
        }
        renderCard(data);
    } catch (error) {
        console.error('Ошибка при получении записи:', error);
        log(`Ошибка загрузки записи: ${error.message}`);
        showArea('error', `Не удалось загрузить запись: ${error.message}`);
    }
}

async function searchForContent() {
    if (DEBUG_MODE) return;
    const button = $('item-search-button');
    button.disabled = true;
    stopPolling();
    showArea('searching');
    try {
        await api('refresh_content', { body: null });
        watchParser();
        await loadCurrentItem();
    } catch (error) {
        showArea('error', `Не удалось запустить поиск: ${error.message}`);
    } finally {
        button.disabled = false;
    }
}

/** Голос за текущую запись. На сервер уходит только адрес картинки: данные записи сервер берёт из пула сам. */
async function vote(type, details = {}) {
    if (!state.item || state.voting) return;
    state.voting = true;
    const tab = $('content-tab');
    tab.classList.add('is-busy');
    try {
        await api('vote', { body: { vote: type, image: state.item.image, ...details } });
        await loadCurrentItem();
    } catch (error) {
        console.error('Ошибка при сохранении голоса:', error);
        log(`Голос не сохранён: ${error.message}`);
        setMessage('item-error', `Не удалось сохранить оценку: ${error.message}`);
    } finally {
        state.voting = false;
        tab.classList.remove('is-busy');
    }
}

// ---- Тема поиска: выбор на вкладке «Подбор»; вторая тема — поле, которое активируется кликом ----
const topicState = { topics: [] };

/** Тема, вторая тема, фильтры и подсказки: то, что сервер хранит для пользователя. */
export async function refreshTopicLabel() {
    try {
        const data = await api('settings');
        $('topic-input').value = data.topic || '';
        $('topic-input').dataset.saved = data.topic || '';
        setSecondTopic(data.topic2 || '');
        if (data.filters) {
            $('filter-fresh').checked = Boolean(data.filters.fresh_only);
            $('filter-active').checked = data.filters.active_sources_only !== false;
            $('filter-video').checked = Boolean(data.filters.video_only);
        }
    } catch (error) {
        log(`Не удалось загрузить тему поиска: ${error.message}`);
    }
    loadTopicOptions();
}

/** Темы для подсказок в поле ввода: общие и внутри них частные. */
async function loadTopicOptions() {
    try {
        const data = await api('topics');
        topicState.topics = data.topics || [];
        $('topic-options').replaceChildren(...topicState.topics.map(topic => el('option', { value: topic.title, label: topic.path })));
    } catch (error) {
        topicState.topics = [];
    }
}

async function submitTopic(topic, forceNew = false) {
    const button = $('topic-save');
    const input = $('topic-input');
    button.disabled = true;
    input.disabled = true;
    hide('topic-notice');
    setMessage('item-searching', 'Нейросеть определяет тему: название и общую тему (до минуты)…');
    try {
        const data = await api('save_topic', { body: { topic, force_new: forceNew } });
        setMessage('item-searching', '');
        input.value = data.topic;
        input.dataset.saved = data.topic;
        if (data.matched && data.typed) {
            // Ввод узнан как уже существующая тема — показываем, во что он превратился, и даём возразить
            $('topic-notice-text').textContent = `«${data.typed}» — это тема «${data.topic}».`;
            $('topic-force-new').dataset.typed = data.typed;
            show('topic-notice');
        } else if (data.created && data.topic_info?.parent) {
            log(`Новая тема «${data.topic}» — частная тема внутри «${data.topic_info.parent.title}».`);
        }
        log(`Тема поиска: «${data.topic}».`);
        if (data.discovery) log('У темы пока мало своих источников — сначала ищу новые (поиск в Telegram, VK, на Дваче). Ход поиска — в журнале.');
        loadTopicOptions();
        watchParser();
        await loadCurrentItem();
    } catch (error) {
        setMessage('item-searching', '');
        setMessage('item-error', `Тема не сохранена: ${error.message}`);
    } finally {
        button.disabled = false;
        input.disabled = false;
    }
}

async function saveTopic(event) {
    event.preventDefault();
    const input = $('topic-input');
    const topic = input.value.trim();
    if (!topic || topic === input.dataset.saved) return;
    const message = 'Сменить тему поиска? Неоценённые записи по старой теме будут убраны из пула, и поиск начнётся заново. История оценок сохранится.';
    if (!window.confirm(message)) return;
    await submitTopic(topic);
}

// Вторая тема: поле неактивно (только чтение, вид подсказки), клик активирует его, клик в другом месте деактивирует
// и сохраняет изменение. Enter тоже сохраняет, Escape — отменяет.

function setSecondTopic(value) {
    const input = $('topic2-input');
    input.value = value;
    input.dataset.saved = value;
}

function activateSecondTopic() {
    const input = $('topic2-input');
    if (!input.readOnly) return;
    input.readOnly = false;
    $('topic2-field').classList.add('is-active');
    input.focus();
    input.setSelectionRange(input.value.length, input.value.length);
}

async function deactivateSecondTopic(cancel = false) {
    const field = $('topic2-field');
    const input = $('topic2-input');
    if (!field.classList.contains('is-active')) return;
    input.readOnly = true;
    field.classList.remove('is-active');
    const value = cancel ? input.dataset.saved : input.value.trim();
    input.value = value;
    if (value === input.dataset.saved) return;
    field.classList.add('is-busy');
    try {
        const data = await api('save_second_topic', { body: { topic2: value } });
        setSecondTopic(data.topic2 || '');
        log(data.topic2 ? `Вторая тема: «${data.topic2}» — контент ищется по обеим.` : 'Вторая тема убрана.');
        watchParser();
        await loadCurrentItem();
    } catch (error) {
        setSecondTopic(input.dataset.saved);
        setMessage('item-error', `Вторая тема не сохранена: ${error.message}`);
    } finally {
        field.classList.remove('is-busy');
    }
}

function initializeSecondTopic() {
    const field = $('topic2-field');
    const input = $('topic2-input');
    field.addEventListener('click', activateSecondTopic);
    document.addEventListener('click', event => { if (!field.contains(event.target)) deactivateSecondTopic(); });
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter') { event.preventDefault(); deactivateSecondTopic(); }
        else if (event.key === 'Escape') { event.preventDefault(); deactivateSecondTopic(true); }
    });
}

async function saveFilters() {
    const boxes = [$('filter-fresh'), $('filter-active'), $('filter-video')];
    const filters = { fresh_only: boxes[0].checked, active_sources_only: boxes[1].checked, video_only: boxes[2].checked };
    boxes.forEach(box => { box.disabled = true; });
    try {
        const data = await api('save_filters', { body: filters });
        boxes[0].checked = Boolean(data.filters?.fresh_only ?? filters.fresh_only);
        boxes[1].checked = Boolean(data.filters?.active_sources_only ?? filters.active_sources_only);
        boxes[2].checked = Boolean(data.filters?.video_only ?? filters.video_only);
        log(`Фильтры подбора: ${boxes[0].checked ? 'только новый контент (за сутки)' : 'контент за последний месяц'}, ${boxes[1].checked ? 'только живые паблики, каналы и треды' : 'все источники, включая неактивные'}${boxes[2].checked ? ', только с видео' : ''}.`);
        watchParser();
        await loadCurrentItem();
    } catch (error) {
        setMessage('item-error', `Фильтры не сохранены: ${error.message}`);
    } finally {
        boxes.forEach(box => { box.disabled = false; });
    }
}

function initializeTopicControls() {
    $('topic-form').addEventListener('submit', saveTopic);
    $('topic-force-new').addEventListener('click', event => submitTopic(event.currentTarget.dataset.typed || $('topic-input').value.trim(), true));
    $('filter-fresh').addEventListener('change', saveFilters);
    $('filter-active').addEventListener('change', saveFilters);
    $('filter-video').addEventListener('change', saveFilters);
    initializeSecondTopic();
}

function initializeComplaintMenu() {
    const button = $('complaint-button');
    const menu = $('complaint-menu');
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
        vote('wrong', { reason: item.dataset.reason });
    });
    document.addEventListener('click', event => {
        if (!menu.hidden && !event.target.closest('.complaint-wrap')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') setOpen(false);
    });
}

/** Медиа записи для редактора поста: видео (с обложкой) или все картинки. */
function postEditorMedia(item) {
    const bad = new Set(item.post_check?.bad || []);   // файлы, которые Telegram точно не примет, в редактор не попадают
    return itemSlides(item).filter((slide, index) => !bad.has(index)).map((slide, index) => ({
        kind: slide.video ? 'video' : 'image',
        src: slide.video || slide.image,
        thumb: slide.image,
        name: (slide.video || slide.fallbackImage || slide.image || '').split('/').pop().split('?')[0] || `медиа ${index + 1}`
    }));
}

/** Текст оригинального поста (пустой, если у записи его нет). */
function postOriginalText(item) {
    const text = String(item.text || '').trim();
    return text && text !== NO_TEXT && text !== 'Пост без текста' ? text : '';
}

/** Подпись пункта «Telegram» в панели: подключённый канал или подсказка, что бот не подключён. */
async function refreshTelegramTarget() {
    if (DEBUG_MODE) return;
    const option = document.querySelector('#post-target option[value="tg:main"]');
    try {
        const data = await api('telegram_queue');
        state.telegram = data.connected ? data.channel : null;
    } catch {
        // сервер временно недоступен — остаётся прежняя подпись
    }
    option.textContent = state.telegram ? `Канал «${state.telegram.title || '@' + state.telegram.username}»` : 'Мой канал в Telegram (не подключён)';
}

/**
 * Отправляет пост из редактора в Telegram-канал: сразу или, если задано время, в очередь (её отправляет cron раз в минуту).
 * Файлы, выбранные в редакторе, уходят загрузкой, остальные медиа — адресом (кэш сайта или ссылка). Ошибки показывает редактор.
 */
async function publishToTelegram(post) {
    const form = new FormData();
    form.append('text', post.text);
    if (post.scheduledTs) form.append('scheduled_ts', String(post.scheduledTs));
    const files = [];
    const media = post.media.map(item => {
        if (item.file) {
            files.push(item.file);
            return { kind: item.kind, name: item.name, upload: files.length - 1 };
        }
        return { kind: item.kind, src: item.src, name: item.name };
    });
    form.append('media', JSON.stringify(media));
    files.forEach(file => form.append('files[]', file, file.name));
    const result = await api('telegram_publish', { body: form });
    if (result.scheduled) {
        if (result.native_error) log(`Не удалось поставить пост в отложенные Telegram (${result.native_error}). Он в очереди приложения и уйдёт, пока сервер включён.`);
        log(result.native
            ? `🚀 Пост запланирован на ${post.scheduledAt}: он в «Отложенных» канала в Telegram и уйдёт сам.`
            : `🚀 Пост запланирован на ${post.scheduledAt}: в очереди приложения, список — в «Личном кабинете» → «Соцсети».`);
    }
    else log(`🚀 Пост опубликован${result.url ? `: [открыть в Telegram](${result.url})` : ''}.`);
}

/**
 * Значок ракеты по результату предварительной проверки (item.post_check, lib/telegram_publish.php): blocked — ракета перечёркнута,
 * warn — жёлтая точка (часть файлов не пройдёт или размер у границы лимита). Причины — в подсказке и в журнале при нажатии.
 */
export function applyRocketCheck(button, check, idleTitle = 'Запостить') {
    const status = check?.status || 'ok';
    const problems = [...(check?.problems || []), ...(check?.warnings || [])];
    button.classList.toggle('rocket-blocked', status === 'blocked');
    button.classList.toggle('rocket-warn', status !== 'blocked' && problems.length > 0);
    button.title = problems.length ? `${status === 'blocked' ? 'Запостить нельзя' : 'Есть замечания'}: ${problems.join(' · ')}` : idleTitle;
}

/** Сообщает в журнал, что мешает публикации (если проверка нашла замечания). */
function logPostCheck(check) {
    const problems = [...(check?.problems || []), ...(check?.warnings || [])];
    if (!problems.length) return;
    log(`🚀 ${check.status === 'blocked' ? 'Этот пост нельзя опубликовать как есть' : 'Замечания к публикации'}: ${problems.join('; ')}.`);
}

/** Публикация записи из вкладки «Предложка»: открывает редактор поста сразу для подключённого Telegram-канала. */
export async function postItemToTelegram(item) {
    logPostCheck(item.post_check);
    await refreshTelegramTarget();
    if (!state.telegram) {
        log('Telegram не подключён: бот и канал подключаются в «Личном кабинете» → «Соцсети».');
        openAccountSection('social');
        return;
    }
    openPostEditor({
        key: item.image || item.display_image || '',
        target: { value: 'tg:main', label: `Канал «${state.telegram.title || '@' + state.telegram.username}»` },
        scheduledAt: '',
        scheduledTs: 0,
        media: postEditorMedia(item),
        original: postOriginalText(item),
        description: item.description || '',
        onSubmit: post => publishToTelegram(post)
    });
}

/** Ракета в просмотрщике без записи (источники, отдельные картинки): публикует открытый файл. */
function postViewerMedia({ kind, src, poster }) {
    const item = kind === 'video' ? { image: poster || src, display_image: poster || src, video: src, display_video: src, text: '' } : { image: src, display_image: src, text: '' };
    return postItemToTelegram(item).catch(error => log(`Не удалось открыть редактор: ${error.message}`));
}

/** Те же слайды, что у карточки подбора, — для просмотрщика на других вкладках. */
export function recordGallery(item, index = 0) {
    const sourceUrl = safeSourcePostUrl(item);
    return {
        index,
        items: itemSlides(item).map(slide => ({
            kind: slide.video ? 'video' : 'image',
            src: slide.video || slide.image,
            fallback: slide.video ? slide.fallbackVideo : slide.fallbackImage,
            poster: slide.image,
            sourceUrl,
            alt: 'Запись'
        })),
        onPost: onPostFor(item),
        postCheck: item.post_check || null
    };
}

function initializePostPanel() {
    const panel = $('post-panel');
    const rocket = $('item-rocket');
    const whenButton = $('post-when');
    let scheduledDate = null;   // null — публиковать сразу; иначе выбранное в окне время (местное)
    const setOpen = open => {
        panel.hidden = !open;
        rocket.setAttribute('aria-expanded', String(open));
        if (open) {
            refreshTelegramTarget();
            if (scheduledDate && scheduledDate.getTime() < Date.now() + 60 * 1000) scheduledDate = null;   // выбранное время уже прошло
            whenButton.textContent = scheduledDate ? formatWhen(scheduledDate) : 'Сразу';
        }
    };
    rocket.addEventListener('click', () => setOpen(panel.hidden));
    whenButton.addEventListener('click', async () => {
        const chosen = await pickDateTime({ value: scheduledDate });
        if (chosen === undefined) return;
        scheduledDate = chosen;
        whenButton.textContent = chosen ? formatWhen(chosen) : 'Сразу';
    });
    document.addEventListener('click', event => {
        if (!panel.hidden && !event.target.closest('.rocket-wrap')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) setOpen(false);
    });
    // «Запостить» открывает редактор поста с медиа и текстом текущей записи; отправка — publishToTelegram (VK пока не готов).
    panel.addEventListener('submit', event => {
        event.preventDefault();
        const target = $('post-target');
        if (!target.value) { target.focus(); return; }
        if (!state.item) return;
        logPostCheck(state.item.post_check);
        const scheduledAt = scheduledDate ? formatWhen(scheduledDate) : '';
        const scheduledTs = scheduledDate ? Math.floor(scheduledDate.getTime() / 1000) : 0;
        const item = state.item;
        if (target.value === 'tg:main' && !state.telegram) {
            log('Telegram не подключён: бот и канал подключаются в «Личном кабинете» → «Соцсети».');
            setOpen(false);
            openAccountSection('social');
            return;
        }
        setOpen(false);
        openPostEditor({
            key: item.image || item.display_image || '',
            target: { value: target.value, label: target.selectedOptions[0].textContent },
            scheduledAt,
            scheduledTs,
            media: postEditorMedia(item),
            original: postOriginalText(item),
            description: item.description || '',
            onSubmit: post => {
                if (post.target.startsWith('tg:')) return publishToTelegram(post);
                throw new Error('Отправка во ВКонтакте пока не готова: сейчас публикуются только посты в Telegram-канал.');
            }
        });
    });
}

export function initializeContentTab({ onAddItem }) {
    initializeComplaintMenu();
    $('carousel-prev').addEventListener('click', () => changeSlide(-1));
    $('carousel-next').addEventListener('click', () => changeSlide(1));
    $('item-video').addEventListener('click', expandCurrentSlide);
    registerViewerPoster(postViewerMedia);
    registerViewerGallery('card', cardGallery);   // клик по картинке карточки открывает галерею поста (image-viewer.js)
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') resumeCardVideo(); });
    for (const type of ['pointerdown', 'keydown']) document.addEventListener(type, resumeCardVideo, { passive: true });
    initializePostPanel();
    document.querySelectorAll('#content-tab [data-vote]').forEach(button => {
        button.addEventListener('click', () => vote(button.dataset.vote));
    });
    $('item-search-button').addEventListener('click', searchForContent);
    $('item-add-button').addEventListener('click', onAddItem);
    initializeTopicControls();
    // Парсер закончил работу, а карточки всё ещё нет — проверим пул.
    onParserStateChange(running => {
        if (!running && !state.item) loadCurrentItem();
    });
    if (DEBUG_MODE) {
        hide('item-search-button');
        hide('item-add-button');
    }
    refreshTopicLabel();
}
