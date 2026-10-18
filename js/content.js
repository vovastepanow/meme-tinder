// content.js — вкладка «Оценка контента»: тема поиска, текущая запись из пула, карусель, видео, голоса и жалобы.
import { $, DEBUG_MODE, api, el, hide, log, onParserStateChange, safeSourcePostUrl, setMessage, show, watchParser } from './core.js';
import { openImageViewer, openVideoViewer } from './image-viewer.js';

const POLL_INTERVAL_MS = 3000;
const NO_TEXT = 'Текст к посту отсутствует';

const state = {
    item: null,
    slides: [],
    slideIndex: 0,
    pollTimer: null,
    voting: false,
    preloaded: []
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
    image.onerror = () => {
        if (slide.fallbackImage && image.getAttribute('src') !== slide.fallbackImage) {
            image.src = slide.fallbackImage;
            return;
        }
        image.onerror = null;
        image.hidden = true;
        show('item-media-fallback');
        log(`Картинка не загрузилась: ${slide.fallbackImage || slide.image}`);
    };
    image.src = slide.image;
}

function setVideoWithFallback(video, slide) {
    video.onerror = () => {
        if (slide.fallbackVideo && video.getAttribute('src') !== slide.fallbackVideo) {
            video.src = slide.fallbackVideo;
            return;
        }
        video.onerror = null;
        log(`Видео не загрузилось: ${slide.fallbackVideo || slide.video}`);
    };
    video.poster = slide.image || '';
    video.muted = false;
    if (video.getAttribute('src') !== slide.video) video.src = slide.video;
}

function stopCardVideo() {
    const video = $('item-video');
    if (!video.getAttribute('src')) return;
    video.pause();
    video.removeAttribute('src');
    video.load();
}

function renderSlide() {
    const slide = state.slides[state.slideIndex] || { image: '', text: 'Нет изображения' };
    const image = $('item-image');
    const video = $('item-video');
    hide('item-media-fallback');

    if (slide.video) {
        image.removeAttribute('src');
        image.hidden = true;
        video.hidden = false;
        setVideoWithFallback(video, slide);
    } else {
        stopCardVideo();
        video.hidden = true;
        image.hidden = !slide.image;
        if (slide.image) setImageWithFallback(image, slide);
        else image.removeAttribute('src');
    }

    $('item-slide-caption').textContent = slide.text || state.item?.text || NO_TEXT;
    const hasMultiple = state.slides.length > 1;
    for (const button of [$('carousel-prev'), $('carousel-next')]) {
        button.disabled = !hasMultiple;
        button.style.visibility = hasMultiple ? 'visible' : 'hidden';
    }
    $('item-slide-position').textContent = hasMultiple ? `${state.slideIndex + 1} / ${state.slides.length}` : '';
}

function changeSlide(delta) {
    if (!state.slides.length) return;
    state.slideIndex = (state.slideIndex + delta + state.slides.length) % state.slides.length;
    renderSlide();
}

/** Открывает текущий слайд на весь экран: видео — плеером со звуком (с того же момента), картинку — с зумом. */
function expandCurrentSlide() {
    const slide = state.slides[state.slideIndex];
    if (!slide) return;
    if (slide.video) {
        const video = $('item-video');
        const source = video.currentSrc || video.getAttribute('src') || slide.video;
        const position = video.currentTime || 0;
        video.pause();
        openVideoViewer(source, slide.image, position);
    } else {
        const image = $('item-image');
        openImageViewer(image.currentSrc || slide.image, 'Запись');
    }
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

function renderCard(item) {
    state.item = item;
    state.slides = itemSlides(item);
    state.slideIndex = 0;
    renderSlide();
    preloadUpcoming(item.preload);

    const relevance = Number(item.relevance ?? 0);
    const rating = Number(item.rating ?? 0);
    $('item-rating').textContent = `${rating}%`;
    $('item-rating').title = 'Прогноз: насколько вам понравится';
    $('item-relevance').textContent = `По теме: ${relevance}%`;
    $('item-source').textContent = `Источник: ${item.source || 'неизвестно'}`;

    const link = $('item-source-link');
    const url = safeSourcePostUrl(item);
    if (url) link.href = url;
    else link.removeAttribute('href');
    link.hidden = !url;

    $('item-why-text').textContent = item.why_relevant || 'Нет объяснения от ИИ';
    $('item-desc').replaceChildren(el('strong', { textContent: 'Описание ИИ:' }), ` ${item.description || 'Анализ отсутствует'}`);

    showArea('card');
    log(`Показана запись: ${item.source || 'источник не указан'}, по теме ${relevance}%, прогноз ${rating}%.`);
}

/** Загружает первую запись пула. Пока парсер ищет контент, повторяет запрос (журнал парсера ведёт консоль). */
export async function loadCurrentItem() {
    stopPolling();
    try {
        const data = await api('get_current_item');

        if (data.status === 'loading') {
            state.item = null;
            stopCardVideo();
            showArea('searching');
            watchParser();
            state.pollTimer = setTimeout(loadCurrentItem, POLL_INTERVAL_MS);
            return;
        }
        if (data.status === 'empty' || !data.image) {
            state.item = null;
            state.slides = [];
            stopCardVideo();
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
    stopCardVideo();
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

// ---- Тема поиска ----

async function loadTopic() {
    try {
        const data = await api('settings');
        $('topic-input').value = data.topic || '';
        $('topic-input').dataset.saved = data.topic || '';
    } catch (error) {
        log(`Не удалось загрузить тему поиска: ${error.message}`);
    }
}

async function saveTopic(event) {
    event.preventDefault();
    const input = $('topic-input');
    const topic = input.value.trim();
    if (!topic || topic === input.dataset.saved) return;
    const message = 'Сменить тему поиска? Неоценённые записи по старой теме будут убраны из пула, и поиск начнётся заново. История оценок сохранится.';
    if (!window.confirm(message)) return;
    const button = $('topic-save');
    button.disabled = true;
    try {
        const data = await api('save_topic', { body: { topic } });
        input.value = data.topic;
        input.dataset.saved = data.topic;
        log(`Тема поиска: «${data.topic}».`);
        watchParser();
        await loadCurrentItem();
    } catch (error) {
        setMessage('item-error', `Тема не сохранена: ${error.message}`);
    } finally {
        button.disabled = false;
    }
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

export function initializeContentTab({ onAddItem }) {
    initializeComplaintMenu();
    $('carousel-prev').addEventListener('click', () => changeSlide(-1));
    $('carousel-next').addEventListener('click', () => changeSlide(1));
    $('item-expand').addEventListener('click', expandCurrentSlide);
    document.querySelectorAll('#content-tab [data-vote]').forEach(button => {
        button.addEventListener('click', () => vote(button.dataset.vote));
    });
    $('item-search-button').addEventListener('click', searchForContent);
    $('item-add-button').addEventListener('click', onAddItem);
    $('topic-form').addEventListener('submit', saveTopic);
    // Парсер закончил работу, а карточки всё ещё нет — проверим пул.
    onParserStateChange(running => {
        if (!running && !state.item) loadCurrentItem();
    });
    if (DEBUG_MODE) {
        hide('item-search-button');
        hide('item-add-button');
    }
    loadTopic();
}
