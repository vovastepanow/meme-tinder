// proposals.js — вкладка «Предложка»: записи, которые вы лайкнули или отметили «не знаю». Отсюда их можно открыть, запостить или убрать.
import { $, api, el, log, onTabOpen, safeSourcePostUrl } from './core.js';
import { openViewerGallery } from './image-viewer.js';
import { applyRocketCheck, postItemToTelegram, recordGallery } from './content.js';

const VOTE_LABELS = { liked: '🗿 Нравится', undecided: '🤷 Не знаю' };
const VIDEO_MARK = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="13" height="12" rx="3" fill="currentColor"/><path d="M16.5 10.2 21 7.6v8.8l-4.5-2.6z" fill="currentColor"/></svg>';

const state = { items: [], filter: 'all', refreshed: false };

// Видео в карточках играют только пока видны на экране: иначе десятки роликов качаются разом и вкладка долго грузится
const videoObserver = new IntersectionObserver(entries => {
    for (const entry of entries) {
        const video = entry.target;
        if (entry.isIntersecting) {
            if (!video.getAttribute('src')) video.src = video.dataset.src;
            video.play().catch(() => {});
        } else {
            video.pause();
        }
    }
}, { rootMargin: '100px' });

function shortText(item) {
    const text = String(item.text || '').trim();
    if (!text || text === 'Текст к посту отсутствует' || text === 'Пост без текста') return '';
    return text.length > 220 ? text.slice(0, 217) + '…' : text;
}

const MAX_TILES = 6;   // сколько файлов поста показывается плитками; остальные — «+N» на последней плитке и в просмотрщике

/** Плитка одного файла: картинка (или обложка видео), у видео — играющий ролик поверх; клик открывает просмотрщик на этом файле. */
function renderMediaTile(slide, onOpen, extra = 0) {
    const isVideo = slide.kind === 'video';
    const tile = el('button', { className: 'proposal-tile is-loading', type: 'button', title: 'Открыть' });   // кольцо загрузки, пока картинка не пришла
    const image = el('img', { src: slide.poster || slide.src || '', alt: '', loading: 'lazy', decoding: 'async' });   // alt пустой: пока картинка грузится, ни значка, ни подписи, только кольцо загрузки
    const settled = () => tile.classList.remove('is-loading');
    image.addEventListener('load', settled, { once: true });
    image.addEventListener('error', () => {
        const backup = slide.fallback && slide.kind !== 'video' ? slide.fallback : '';
        if (backup && image.getAttribute('src') !== backup) { image.src = backup; return; }   // копия из кэша не открылась — пробуем исходную ссылку
        image.hidden = true;   // файл недоступен совсем: вместо значка «битой картинки» спокойная пометка
        settled();
        tile.classList.add('is-broken');
    });
    tile.append(image);
    if (isVideo) {   // ролик играет в плитке без звука, по кругу, пока плитка видна на экране
        const video = el('video', { poster: slide.poster || '', muted: true, loop: true, playsInline: true, preload: 'metadata' });
        video.muted = true;
        video.dataset.src = slide.src;
        video.dataset.fallback = slide.fallback || '';
        video.addEventListener('playing', settled, { once: true });
        video.addEventListener('error', () => {
            const backup = video.dataset.fallback;
            if (backup && video.getAttribute('src') !== backup) { video.src = backup; video.play().catch(() => {}); return; }   // копия из кэша не открылась — пробуем исходную ссылку
            videoObserver.unobserve(video);
            video.remove();   // не открылось совсем: остаётся обложка
        });
        tile.append(video);
        videoObserver.observe(video);
        tile.append(el('span', { className: 'video-mark', innerHTML: VIDEO_MARK }));
    }
    if (extra > 0) tile.append(el('span', { className: 'proposal-more', textContent: `+${extra}` }));
    tile.addEventListener('click', onOpen);
    return tile;
}

function renderTile(item) {
    const slides = recordGallery(item).items;
    const shown = slides.slice(0, MAX_TILES);
    const open = index => {
        const gallery = recordGallery(item);
        openViewerGallery(gallery.items, index, gallery.onPost, gallery.postCheck);
    };
    // Раскладка плиток зависит от их числа (mosaic-1 … mosaic-6, см. css/proposals.css): всё помещается в один блок, как в карточке источника
    const media = el('div', { className: `proposal-media mosaic-${Math.max(1, shown.length)}` },
        ...shown.map((slide, index) => renderMediaTile(slide, () => open(index), index === shown.length - 1 ? slides.length - shown.length : 0)));
    if (slides.length > 1) media.append(el('span', { className: 'proposal-count', textContent: `${slides.length} ${slides.length < 5 ? 'файла' : 'файлов'}` }));

    const link = safeSourcePostUrl(item);
    const meta = el('div', { className: 'proposal-meta' },
        el('span', { className: `proposal-vote vote-${item.vote}`, textContent: VOTE_LABELS[item.vote] || '' }),
        link ? el('a', { href: link, target: '_blank', rel: 'noopener noreferrer', textContent: item.source || 'Источник' })
             : el('span', { textContent: item.source || '' }));
    const post = el('button', { className: 'proposal-action', type: 'button', textContent: '🚀 Запостить' });
    applyRocketCheck(post, item.post_check, '🚀 Запостить в Telegram');
    post.addEventListener('click', () => postItemToTelegram(item).catch(error => log(`Не удалось открыть редактор: ${error.message}`)));
    const hide = el('button', { className: 'proposal-action proposal-hide', type: 'button', textContent: '✕ Убрать', title: 'Убрать из предложки (оценка в истории останется)' });
    hide.addEventListener('click', async () => {
        hide.disabled = true;
        try {
            await api('proposal_hide', { body: { image: item.image } });
            state.items = state.items.filter(other => other !== item);
            render();
        } catch (error) {
            hide.disabled = false;
            log(`Не удалось убрать запись: ${error.message}`);
        }
    });
    const text = shortText(item);
    return el('article', { className: 'proposal-card' }, media, meta,
        ...(text ? [el('p', { className: 'proposal-text', textContent: text })] : []),
        el('div', { className: 'proposal-actions' }, post, hide));
}

function render() {
    const visible = state.items.filter(item => state.filter === 'all' || item.vote === state.filter);
    $('proposals-count').textContent = state.items.length ? `${visible.length} из ${state.items.length}` : '';
    $('proposals-status').textContent = visible.length ? '' : (state.items.length
        ? 'В этом разделе пусто.'
        : 'Пока пусто: сюда попадают записи, которые вы отметили «Нравится» или «Не знаю» на вкладке «Подбор».');
    $('proposals-grid').querySelectorAll('video').forEach(video => videoObserver.unobserve(video));
    $('proposals-grid').replaceChildren(...visible.map(renderTile));
    document.querySelectorAll('[data-proposal-filter]').forEach(button => {
        button.classList.toggle('active', button.dataset.proposalFilter === state.filter);
    });
}

async function load() {
    try {
        const data = await api('proposals');
        state.items = Array.isArray(data.items) ? data.items : [];
        render();
        if (data.refreshing && !state.refreshed) {   // сервер достраивает старые записи в фоне: один раз обновляем список через несколько секунд
            state.refreshed = true;
            setTimeout(load, 8000);
        }
    } catch (error) {
        $('proposals-status').textContent = `Не удалось загрузить предложку: ${error.message}`;
    }
}

export function initializeProposalsTab() {
    document.querySelectorAll('[data-proposal-filter]').forEach(button => {
        button.addEventListener('click', () => { state.filter = button.dataset.proposalFilter; render(); });
    });
    $('proposals-refresh').addEventListener('click', load);
    onTabOpen('proposals-tab', load);
}
