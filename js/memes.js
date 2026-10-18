// memes.js — вкладка «Оценка мемов»: текущий мем из пула, карусель слайдов, голоса и жалобы.
import { $, DEBUG_MODE, api, appendParserActivity, el, hide, log, resetParserActivity, safeSourcePostUrl, setConsoleStatus, setMessage, show } from './core.js';

const POLL_INTERVAL_MS = 3000;
const NO_TEXT = 'Текст к посту отсутствует';

const state = {
    meme: null,
    slides: [],
    slideIndex: 0,
    pollTimer: null,
    voting: false
};

function stopPolling() {
    clearTimeout(state.pollTimer);
    state.pollTimer = null;
}

/** Одна из взаимоисключающих областей вкладки: карточка, «пул пуст», ошибка или ничего (идёт поиск). */
function showMemeArea(area, message = '') {
    show('meme-card-wrapper', area === 'card');
    show('meme-empty', area === 'empty');
    setMessage('meme-error', area === 'error' ? message : '');
    if (area === 'empty') $('meme-empty-message').textContent = message || 'Пул мемов пуст.';
    hide('meme-loading');
    setConsoleStatus(area === 'searching' ? 'поиск' : 'готово');
}

/** Слайды мема: видео — один слайд с постером, иначе по одному слайду на картинку. */
function memeSlides(meme) {
    if (!meme) return [];
    if (meme.video) {
        return [{ image: meme.image || meme.images?.[0] || '', video: meme.video, text: meme.text || meme.ai_description || NO_TEXT }];
    }
    if (Array.isArray(meme.images) && meme.images.length) {
        const captions = Array.isArray(meme.captions) ? meme.captions : [];
        return meme.images.filter(Boolean).map((image, index) => ({ image, text: captions[index] || meme.text || '' }));
    }
    return meme.image ? [{ image: meme.image, text: meme.text || meme.ai_description || NO_TEXT }] : [];
}

function renderSlide() {
    const slide = state.slides[state.slideIndex] || { image: '', text: 'Нет изображения' };
    const image = $('meme-image');
    const video = $('meme-video');

    if (slide.video) {
        video.poster = slide.image || '';
        if (video.getAttribute('src') !== slide.video) video.src = slide.video;
        video.hidden = false;
        image.removeAttribute('src');
        image.hidden = true;
    } else {
        if (video.getAttribute('src')) {
            video.pause();
            video.removeAttribute('src');
            video.load();
        }
        video.hidden = true;
        if (slide.image) image.src = slide.image;
        else image.removeAttribute('src');
        image.hidden = !slide.image;
    }

    $('meme-slide-caption').textContent = slide.text || state.meme?.text || NO_TEXT;
    const hasMultiple = state.slides.length > 1;
    for (const button of [$('carousel-prev'), $('carousel-next')]) {
        button.disabled = !hasMultiple;
        button.style.visibility = hasMultiple ? 'visible' : 'hidden';
    }
    $('meme-slide-position').textContent = hasMultiple ? `${state.slideIndex + 1} / ${state.slides.length}` : '';
}

function changeSlide(delta) {
    if (!state.slides.length) return;
    state.slideIndex = (state.slideIndex + delta + state.slides.length) % state.slides.length;
    renderSlide();
}

function renderMemeCard(meme) {
    state.meme = meme;
    state.slides = memeSlides(meme);
    state.slideIndex = 0;
    renderSlide();

    const rating = Number(meme.rating || 0);
    $('meme-rating').textContent = `🔮 ${rating}%`;
    $('meme-source').textContent = `📍 Источник: ${meme.source || 'Неизвестно'}`;

    const link = $('meme-source-link');
    const url = safeSourcePostUrl(meme);
    if (url) link.href = url;
    else link.removeAttribute('href');
    link.hidden = !url;

    $('meme-why-funny-text').textContent = meme.why_funny || 'Нет объяснения от ИИ';
    const funnyBadge = $('meme-is-funny');
    funnyBadge.textContent = `Смешно? ${meme.is_funny === true ? 'Да' : meme.is_funny === false ? 'Нет' : 'Не оценено'}`;
    funnyBadge.classList.toggle('is-funny', meme.is_funny === true);
    funnyBadge.classList.toggle('not-funny', meme.is_funny === false);
    $('meme-desc').replaceChildren(el('strong', { textContent: 'ИИ Описание:' }), ` ${meme.ai_description || 'Анализ отсутствует'}`);

    showMemeArea('card');
    log(`Показан ${meme.video ? 'видео-мем' : 'мем'}: ${meme.source || 'источник не указан'}, рейтинг ${rating}%.`);
}

/** Загружает первый мем пула. Пока парсер ищет мемы, опрашивает сервер и выводит его журнал. */
export async function loadCurrentMeme() {
    stopPolling();
    try {
        const data = await api('get_current_meme');

        if (data.status === 'loading') {
            state.meme = null;
            showMemeArea('searching');
            appendParserActivity(data.activity);
            state.pollTimer = setTimeout(loadCurrentMeme, POLL_INTERVAL_MS);
            return;
        }
        if (data.status === 'empty' || !data.image) {
            state.meme = null;
            state.slides = [];
            showMemeArea('empty', data.message);
            log(data.message || 'Пул мемов пуст.');
            return;
        }
        renderMemeCard(data);
    } catch (error) {
        console.error('Ошибка при получении мема:', error);
        log(`Ошибка загрузки мема: ${error.message}`);
        showMemeArea('error', `Не удалось загрузить мем: ${error.message}`);
    }
}

async function searchForMemes() {
    if (DEBUG_MODE) return;
    const button = $('meme-search-button');
    button.disabled = true;
    stopPolling();
    resetParserActivity();
    showMemeArea('searching');
    try {
        await api('refresh_memes', { body: null });
        await loadCurrentMeme();
    } catch (error) {
        showMemeArea('error', `Не удалось запустить поиск: ${error.message}`);
    } finally {
        button.disabled = false;
    }
}

/** Голос за текущий мем. На сервер уходит только адрес картинки: данные мема сервер берёт из пула сам. */
async function voteMeme(vote, details = {}) {
    if (!state.meme || state.voting) return;
    state.voting = true;
    const tab = $('meme-tab');
    tab.classList.add('is-busy');
    try {
        await api('meme_vote', { body: { vote, image: state.meme.image, ...details } });
        await loadCurrentMeme();
    } catch (error) {
        console.error('Ошибка при сохранении голоса:', error);
        log(`Голос не сохранён: ${error.message}`);
        setMessage('meme-error', `Не удалось сохранить оценку: ${error.message}`);
    } finally {
        state.voting = false;
        tab.classList.remove('is-busy');
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
        voteMeme('wrong', { reason: item.dataset.reason });
    });
    document.addEventListener('click', event => {
        if (!menu.hidden && !event.target.closest('.complaint-wrap')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') setOpen(false);
    });
}

export function initializeMemeTab({ onAddMeme }) {
    initializeComplaintMenu();
    $('carousel-prev').addEventListener('click', () => changeSlide(-1));
    $('carousel-next').addEventListener('click', () => changeSlide(1));
    document.querySelectorAll('#meme-tab [data-vote]').forEach(button => {
        button.addEventListener('click', () => voteMeme(button.dataset.vote));
    });
    $('meme-search-button').addEventListener('click', searchForMemes);
    $('meme-add-button').addEventListener('click', onAddMeme);
    if (DEBUG_MODE) {
        hide('meme-search-button');
        hide('meme-add-button');
    }
}
