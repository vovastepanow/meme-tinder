// memes.js — Вкладка «Оценка мемов»: пул, карусель слайдов, голоса и жалобы.
// script.js
let currentMeme = null;

let currentMemeSlides = [];

let currentSlideIndex = 0;

let memePollTimer = null;

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

    // Видео-мем показываем одним слайдом: постер (картинка) и файл видео.
    if (meme.video) {
        return [{
            image: meme.image || meme.images?.[0] || '',
            video: meme.video,
            text: meme.text || meme.ai_description || 'Текст к посту отсутствует'
        }];
    }

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
    const video = document.getElementById('meme-video');
    const caption = document.getElementById('meme-slide-caption');
    const position = document.getElementById('meme-slide-position');
    const prevBtn = document.getElementById('carousel-prev');
    const nextBtn = document.getElementById('carousel-next');

    if (active && active.video) {
        video.poster = active.image || '';
        if (video.getAttribute('src') !== active.video) video.src = active.video;
        video.style.display = 'block';
        img.removeAttribute('src');
        img.style.display = 'none';
    } else {
        video.pause();
        video.removeAttribute('src');
        video.load();
        video.style.display = 'none';
        if (active && active.image) {
            img.src = active.image;
            img.style.display = 'block';
        } else {
            img.removeAttribute('src');
            img.style.display = 'none';
        }
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
        appendAppLog(`Показан ${data.video ? 'видео-мем' : 'мем'}: ${data.source || 'источник не указан'}, рейтинг ${Number(data.rating || 0)}%.`);
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
