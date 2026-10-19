// sources.js — вкладка «Подбор источников». Кандидат показывается карточкой в том же формате, что запись на вкладке
// «Подбор»: мозаика из 6 свежих медиафайлов, сводка, панель оценок справа (💩 не нравится, ✋ отложить, 🤷 не знаю, 🗿 нравится).
// Решение сохраняется, и сразу показывается следующий кандидат. Подключение VK — в account.js.
import { $, DEBUG_MODE, api, el, hide, log, onParserStateChange, onTabOpen, pluralRu, safeSourcePostUrl, setMessage, show, watchParser } from './core.js';
import { openViewerGallery, registerViewerGallery } from './image-viewer.js';
import { initializeTopicSources, loadTopicSources } from './topic-sources.js';

const COUNT_FORMAT = new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 });
const REASON_LABELS = { off_topic: 'не по теме', dead: 'мёртвый', other: 'другое' };

// source — текущий кандидат; skipped — id отложенных в этой сессии (сервер их больше не присылает)
const state = { source: null, skipped: [], loading: false, voting: false, discovering: false, mosaicToken: null };
const SOURCE_POLL_MS = 10000;   // пока карточки нет, раз в это время спрашиваем, не нашёл ли подбор что-нибудь новое
let pollTimer = null;

// ---- Сводка ----

function formatSourceAgo(timestamp) {
    if (!timestamp) return '';
    const minutes = Math.max(0, Math.round((Date.now() / 1000 - timestamp) / 60));
    if (minutes < 60) return minutes < 1 ? 'только что' : `${minutes} мин назад`;
    const hours = Math.round(minutes / 60);
    return hours < 48 ? `${hours} ч назад` : `${Math.round(hours / 24)} дн назад`;
}

function statChip(label, value, title = '', className = '') {
    return el('span', { className: `eng-chip ${className}`.trim(), title }, `${label} `, el('b', { textContent: value }));
}

/** Аудитория и активность источника: подписчики, доля просмотров, частота и свежесть постов, размер треда. */
function renderStats(stats = {}) {
    const chips = [];
    if (stats.subscribers > 0) chips.push(statChip('подписчики', COUNT_FORMAT.format(stats.subscribers)));
    if (stats.view_rate != null) {
        const percent = Math.round(stats.view_rate * 100);
        chips.push(statChip('просмотры', `${percent}% подписчиков`, 'Какая доля подписчиков видит пост: медиана просмотров свежих постов ÷ подписчики'));
        if (stats.view_rate >= 0.25) chips.push(el('span', { className: 'eng-chip eng-up', textContent: '↑ живая аудитория' }));
        else if (stats.view_rate < 0.05) chips.push(el('span', { className: 'eng-chip eng-down', textContent: '↓ посты почти не смотрят' }));
    }
    if (stats.thread_posts != null) chips.push(statChip('в треде', `${COUNT_FORMAT.format(stats.thread_posts)} ${pluralRu(stats.thread_posts, 'пост', 'поста', 'постов')}`));
    if (stats.thread_files) chips.push(statChip('файлов в треде', COUNT_FORMAT.format(stats.thread_files), 'Мозаика — подборка файлов со всего треда, а не только из шапки'));
    if (stats.posts_per_week != null) chips.push(statChip('постов в неделю', `~${Math.round(stats.posts_per_week)}`));
    if (stats.last_post) chips.push(statChip('последний пост', formatSourceAgo(stats.last_post)));
    $('source-stats').replaceChildren(...chips);
    $('source-stats').hidden = chips.length === 0;
}

// ---- Карточка ----

const MOSAIC_TILES = 6;      // сколько медиафайлов источника показываем
const MOSAIC_PARALLEL_VIDEOS = 3; // сколько видео мозаики качаются одновременно: играют сами все, но остальные начинают, когда освободится место
const MOSAIC_VIDEO_START_TIMEOUT = 10000; // видео, которое так и не началось за это время, уступает очередь следующему
// Значок «это видео, а не гифка»: маленькая камера в углу (статичная разметка, пользовательских данных в ней нет)
const VIDEO_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="6" width="13" height="12" rx="3" fill="currentColor"/><path d="M16.5 10.2 21 7.6v8.8l-4.5-2.6z" fill="currentColor"/></svg>';
const MOSAIC_FALLBACK_SIZE = { width: 420, height: 380 };

/**
 * Шаблоны мозаики для n плиток: сетка cols × rows и плитки [колонка, строка, ширина, высота] в клетках сетки.
 * Заполняют контейнер целиком, зазор между плитками — 2 px (CSS .source-grid). Для каждого набора картинок
 * выбирается шаблон, плитки которого по пропорциям ближе всего к самим картинкам: так обрезается меньше всего.
 */
const MOSAIC_TEMPLATES = {
    1: [{ cols: 1, rows: 1, tiles: [[1, 1, 1, 1]] }],
    2: [{ cols: 2, rows: 1, tiles: [[1, 1, 1, 1], [2, 1, 1, 1]] }, { cols: 1, rows: 2, tiles: [[1, 1, 1, 1], [1, 2, 1, 1]] }],
    3: [
        { cols: 3, rows: 1, tiles: [[1, 1, 1, 1], [2, 1, 1, 1], [3, 1, 1, 1]] },
        { cols: 1, rows: 3, tiles: [[1, 1, 1, 1], [1, 2, 1, 1], [1, 3, 1, 1]] },
        { cols: 2, rows: 2, tiles: [[1, 1, 1, 2], [2, 1, 1, 1], [2, 2, 1, 1]] },
        { cols: 2, rows: 2, tiles: [[1, 1, 2, 1], [1, 2, 1, 1], [2, 2, 1, 1]] }
    ],
    4: [
        { cols: 2, rows: 2, tiles: [[1, 1, 1, 1], [2, 1, 1, 1], [1, 2, 1, 1], [2, 2, 1, 1]] },
        { cols: 4, rows: 1, tiles: [[1, 1, 1, 1], [2, 1, 1, 1], [3, 1, 1, 1], [4, 1, 1, 1]] },
        { cols: 2, rows: 3, tiles: [[1, 1, 1, 3], [2, 1, 1, 1], [2, 2, 1, 1], [2, 3, 1, 1]] },
        { cols: 3, rows: 2, tiles: [[1, 1, 3, 1], [1, 2, 1, 1], [2, 2, 1, 1], [3, 2, 1, 1]] }
    ],
    5: [
        { cols: 3, rows: 3, tiles: [[1, 1, 2, 2], [3, 1, 1, 1], [3, 2, 1, 1], [1, 3, 1, 1], [2, 3, 2, 1]] },
        { cols: 6, rows: 2, tiles: [[1, 1, 2, 1], [3, 1, 2, 1], [5, 1, 2, 1], [1, 2, 3, 1], [4, 2, 3, 1]] },
        { cols: 6, rows: 2, tiles: [[1, 1, 3, 1], [4, 1, 3, 1], [1, 2, 2, 1], [3, 2, 2, 1], [5, 2, 2, 1]] }
    ],
    6: [
        { cols: 3, rows: 2, tiles: [[1, 1, 1, 1], [2, 1, 1, 1], [3, 1, 1, 1], [1, 2, 1, 1], [2, 2, 1, 1], [3, 2, 1, 1]] },
        { cols: 2, rows: 3, tiles: [[1, 1, 1, 1], [2, 1, 1, 1], [1, 2, 1, 1], [2, 2, 1, 1], [1, 3, 1, 1], [2, 3, 1, 1]] },
        { cols: 3, rows: 3, tiles: [[1, 1, 2, 2], [3, 1, 1, 1], [3, 2, 1, 1], [1, 3, 1, 1], [2, 3, 1, 1], [3, 3, 1, 1]] }
    ]
};

/** Файлы источника для мозаики: по одной картинке каждого поста, потом вторые картинки постов и так далее, до MOSAIC_TILES. */
function mosaicItems(source) {
    const posts = (source.posts || []).map(post => {
        const images = Array.isArray(post.images) && post.images.length ? post.images : [post.image];
        return { images: images.filter(Boolean), video: post.video || '', unavailable: Boolean(post.video_unavailable), text: post.text || '', url: safeSourcePostUrl({ source_url: post.url }) };
    }).filter(post => post.images.length);
    const items = [];
    for (let round = 0; items.length < MOSAIC_TILES; round++) {
        let added = false;
        for (const post of posts) {
            if (round >= post.images.length || items.length >= MOSAIC_TILES) continue;
            // У видео в мозаике только обложка; у постов с несколькими картинками видео нет, поэтому видео — в первом раунде
            items.push({ src: post.images[round], video: round === 0 ? post.video : '', unavailable: round === 0 && post.unavailable, text: post.text, url: post.url });
            added = true;
        }
        if (!added) break;
    }
    return items;
}

/**
 * Лучшая раскладка для картинок: перебирает шаблоны, в каждом раздаёт картинки плиткам (самые большие плитки — картинкам
 * с самой близкой пропорцией) и берёт шаблон с минимальной суммой «насколько плитка не похожа на картинку».
 * aspects — пропорции (ширина ÷ высота) в том порядке, в каком картинки лежат в сетке. Возвращает {template, assignment},
 * где assignment[i] — индекс картинки для плитки i шаблона.
 */
function chooseMosaic(aspects, width, height) {
    let best = null;
    for (const template of MOSAIC_TEMPLATES[aspects.length] || []) {
        const sizes = template.tiles.map(([, , cs, rs]) => (cs / template.cols * width) / (rs / template.rows * height));
        const bySize = template.tiles.map((tile, index) => index).sort((a, b) => template.tiles[b][2] * template.tiles[b][3] - template.tiles[a][2] * template.tiles[a][3]);
        const free = new Set(aspects.keys());
        const assignment = new Array(template.tiles.length);
        let error = 0;
        for (const tileIndex of bySize) {
            let pick = null;
            for (const imageIndex of free) {
                const miss = Math.abs(Math.log(sizes[tileIndex] / aspects[imageIndex]));
                if (!pick || miss < pick.miss) pick = { imageIndex, miss };
            }
            free.delete(pick.imageIndex);
            assignment[tileIndex] = pick.imageIndex;
            error += pick.miss * (template.tiles[tileIndex][2] * template.tiles[tileIndex][3]);   // ошибка на большой плитке заметнее
        }
        if (!best || error < best.error) best = { template, assignment, error };
    }
    return best;
}

/** Расставляет плитки по выбранной раскладке. Вызывается, когда картинки загрузились и известны их пропорции. */
function layoutMosaic(grid, tiles) {
    const live = tiles.filter(tile => !tile.failed);
    grid.replaceChildren(...live.map(tile => tile.node));
    if (!live.length) {   // ни одна картинка не загрузилась (источник удалил файлы или нет связи)
        show('source-media-fallback');
        return;
    }
    const aspects = live.map(tile => (tile.img.naturalWidth && tile.img.naturalHeight ? tile.img.naturalWidth / tile.img.naturalHeight : 1));
    const width = grid.clientWidth || MOSAIC_FALLBACK_SIZE.width;
    const height = grid.clientHeight || MOSAIC_FALLBACK_SIZE.height;
    const { template, assignment } = chooseMosaic(aspects, width, height);
    grid.style.gridTemplateColumns = `repeat(${template.cols}, minmax(0, 1fr))`;
    grid.style.gridTemplateRows = `repeat(${template.rows}, minmax(0, 1fr))`;
    template.tiles.forEach(([column, row, colSpan, rowSpan], tileIndex) => {
        const node = live[assignment[tileIndex]].node;
        node.style.gridColumn = `${column} / span ${colSpan}`;
        node.style.gridRow = `${row} / span ${rowSpan}`;
    });
}

/** Видео в плитке: играет само, без звука и по кругу поверх обложки; не открылось — остаётся обложка. */
function autoplayTileVideo(item, node, queue, token) {
    const video = el('video', { className: 'tile-video', poster: item.src, muted: true, loop: true, playsInline: true, preload: 'auto', hidden: true });
    video.muted = true;
    let released = false;
    const release = () => { if (!released) { released = true; queue.active--; queue.next(); } };
    video.addEventListener('playing', () => { video.hidden = false; release(); }, { once: true });
    video.addEventListener('error', () => { video.remove(); release(); }, { once: true });
    // Видео не качается, пока не подошла очередь: так в мозаике играют все ролики, но не все сразу загружаются
    queue.waiting.push(() => {
        if (state.mosaicToken !== token) return;   // уже показан другой источник: старые ролики не качаем
        queue.active++;
        video.src = item.video;
        video.autoplay = true;
        video.play?.().catch(() => { /* автозапуск запрещён — останется обложка */ });
        setTimeout(release, MOSAIC_VIDEO_START_TIMEOUT);
    });
    video.addEventListener('click', () => {
        const gallery = sourceGallery(video);
        if (gallery) openViewerGallery(gallery.items, gallery.index);
    });
    return video;
}

/** Все файлы мозаики по порядку постов — галерея просмотрщика (левая кнопка мыши — предыдущий, правая — следующий). */
function sourceGallery(target) {
    const nodes = [...$('source-grid').children].filter(node => !node.dataset.external);   // «большие» видео в галерею не входят
    const index = nodes.indexOf(target.closest('.source-tile'));
    if (index < 0) return null;
    return {
        index,
        items: nodes.map(node => ({
            kind: node.dataset.video ? 'video' : 'image',
            src: node.dataset.video || node.dataset.src,
            poster: node.dataset.src,
            sourceUrl: node.dataset.url || '',
            alt: 'Пост источника'
        }))
    };
}

/** Строит мозаику источника. Картинки подгружаются, потом раскладка пересчитывается по их настоящим пропорциям. */
function renderMosaic(source) {
    const grid = $('source-grid');
    hide('source-media-fallback');
    const items = mosaicItems(source);
    grid.replaceChildren();
    if (!items.length) {
        show('source-media-fallback');
        return;
    }
    let settled = 0;
    const token = state.mosaicToken = Symbol('mosaic');
    const queue = { active: 0, waiting: [], next() { while (this.active < MOSAIC_PARALLEL_VIDEOS && this.waiting.length) this.waiting.shift()(); } };
    const tiles = items.map(item => {
        const img = el('img', { src: item.src, alt: 'Пост источника', loading: 'eager', decoding: 'async' });
        img.dataset.sourceUrl = item.url || '';
        if (item.text && item.text !== 'Пост без текста') img.title = item.text.slice(0, 200);
        const node = el('div', { className: 'source-tile is-loading' }, img);   // кольцо загрузки, пока картинка не пришла
        Object.assign(node.dataset, { src: item.src, video: item.video, url: item.url || '' });
        if (item.unavailable && item.url) {
            // Большое видео: Telegram не отдаёт файл в веб-версии, есть только обложка. Значок видео со стрелкой, клик открывает пост в Telegram
            node.dataset.external = item.url;
            img.dataset.external = item.url;
            img.classList.add('is-video-thumb');
            img.title = 'Видео слишком большое: Telegram не отдаёт его в веб-версии. Клик откроет пост в Telegram.';
            const mark = el('span', { className: 'video-mark is-external', title: img.title });
            mark.innerHTML = VIDEO_ICON;
            node.append(mark);
        } else {
            img.dataset.gallery = 'source';   // клик открывает галерею источника в просмотрщике (image-viewer.js)
        }
        if (item.video) {
            img.dataset.video = item.video;
            img.classList.add('is-video-thumb');
            const mark = el('span', { className: 'video-mark', title: 'Видео' });
            mark.innerHTML = VIDEO_ICON;
            node.append(mark);
            node.append(autoplayTileVideo(item, node, queue, token));
        }
        const tile = { img, node, failed: false };
        const done = failed => {
            if (state.mosaicToken !== token) return;   // уже показан другой источник
            tile.failed = failed;
            if (++settled === tiles.length) layoutMosaic(grid, tiles);
        };
        img.addEventListener('load', () => { node.classList.remove('is-loading'); done(false); }, { once: true });
        img.addEventListener('error', () => { node.classList.remove('is-loading'); done(true); }, { once: true });
        return tile;
    });
    grid.replaceChildren(...tiles.map(tile => tile.node));
    queue.next();
    layoutMosaic(grid, tiles);   // пока картинки грузятся — раскладка по умолчанию, потом уточняется
    // Если какая-то картинка не отвечает, через 4 с раскладываем то, что есть
    setTimeout(() => { if (state.mosaicToken === token && settled < tiles.length) layoutMosaic(grid, tiles); }, 4000);
}

function renderSource(source) {
    state.source = source;
    renderMosaic(source);
    const stats = source.stats || {};
    // Название источника (для треда Двача — его тема) — ссылка на тред, если адрес надёжный
    const url = safeSourcePostUrl({ source_url: stats.url });
    $('source-name').replaceChildren('Источник: ', url
        ? el('a', { className: 'source-name-link', href: url, target: '_blank', rel: 'noopener noreferrer', textContent: source.name })
        : source.name);
    $('source-platform-label').textContent = source.platform + (source.suggested ? ' · найден автоподбором' : '');
    $('source-score').textContent = source.score != null ? `${source.score}%` : '';
    $('source-score').hidden = source.score == null;
    renderStats(stats);
    const link = $('source-link');
    if (url) link.href = url; else link.removeAttribute('href');
    link.hidden = !url;
    link.textContent = source.platform === 'Двач' ? 'Открыть тред ↗' : 'Открыть источник ↗';
    $('source-why').textContent = source.reason || 'Кандидат из каталога источников.';
    $('source-desc').replaceChildren(el('strong', { textContent: 'Описание: ' }), stats.description || '');
    $('source-desc').hidden = !stats.description;
    clearTimeout(pollTimer);
    showArea('card');
    log(`Показан источник: ${source.name}${source.score != null ? `, оценка подбора ${source.score}%` : ''}.`);
}

function showArea(area, message = '') {
    show('source-card-wrapper', area === 'card');
    show('source-empty', area === 'empty');
    setMessage('source-error', area === 'error' ? message : '');
    if (area === 'empty') {
        $('source-empty-message').textContent = (message || 'Новых источников пока нет.')
            + (state.discovering ? ' Подбор идёт: найденные источники появятся здесь по одному.' : '');
        schedulePoll();
    }
    hide('source-loading');
}

/** Пока подбор идёт, а карточки нет, тихо проверяет готовые находки и показывает первую, как только она появится. */
function schedulePoll() {
    clearTimeout(pollTimer);
    if (!state.discovering) return;
    pollTimer = setTimeout(async () => {
        if (!$('source-tab').classList.contains('active') || state.source || state.loading) return;
        await loadNextSource({ quiet: true });
        if (!state.source) schedulePoll();
    }, SOURCE_POLL_MS);
}

export function clearDiscoveredSource() {
    state.source = null;
    state.skipped = [];
    hide('source-card-wrapper');
}

/** Загружает следующего кандидата и показывает его; нет кандидатов — пустое состояние с подсказкой. */
async function loadNextSource({ quiet = false } = {}) {
    if (state.loading) return;
    state.loading = true;
    if (!quiet) {
        state.source = null;
        hide('source-card-wrapper');
        hide('source-empty');
        setMessage('source-error', '');
        show('source-loading');
    }
    try {
        const body = { platform: $('source-platform').value, skip: state.skipped };
        if (quiet) body.suggested_only = true;   // лёгкий запрос: только готовые находки подбора
        const data = await api('suggest_sources', { body });
        if (data.discovered) renderSource(data.discovered);
        else if (!quiet) showArea('empty', data.message);
    } catch (error) {
        if (!quiet) {
            console.error('Ошибка при загрузке источника:', error);
            showArea('empty', `Не удалось загрузить источник: ${error.message}`);
        }
    } finally {
        state.loading = false;
    }
}

// ---- Решения ----

/** Минимум полей источника для сервера: превью и сводка ему не нужны (он берёт их сам). */
function voteSubject(source) {
    return Object.fromEntries(['source_id', 'platform', 'name', 'id', 'board', 'thread_id', 'suggested', 'score']
        .filter(key => source[key] !== undefined).map(key => [key, source[key]]));
}

async function vote(type, reason = '') {
    const source = state.source;
    if (!source || state.voting) return;
    if (type === 'postpone') {
        state.skipped.push(source.source_id);
        log(`Отложен источник: ${source.name}`);
        loadNextSource();
        return;
    }
    state.voting = true;
    const buttons = document.querySelectorAll('#source-tab .controls button');
    buttons.forEach(button => { button.disabled = true; });
    try {
        await api('source_vote', { body: { vote: type, reason, source: voteSubject(source) } });
        if (type === 'approve' || type === 'reject') loadTopicSources();   // одобренный появляется среди источников темы, отклонённый — уходит
        const verdict = { approve: 'Одобрен', reject: 'Отклонён', maybe: 'Без решения' }[type];
        log(`${verdict} источник: ${source.name}${reason ? ` (${REASON_LABELS[reason]})` : ''}`);
        state.voting = false;
        await loadNextSource();
    } catch (error) {
        console.error('Ошибка при сохранении решения по источнику:', error);
        setMessage('source-error', `Решение не сохранено: ${error.message}`);
    } finally {
        state.voting = false;
        buttons.forEach(button => { button.disabled = false; });
    }
}

function initializeComplaintMenu() {
    const button = $('source-complaint-button');
    const menu = $('source-complaint-menu');
    const setOpen = open => {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', String(open));
    };
    button.addEventListener('click', event => {
        event.stopPropagation();
        setOpen(menu.hidden);
    });
    menu.addEventListener('click', event => {
        const item = event.target.closest('[data-source-reason]');
        if (!item) return;
        setOpen(false);
        vote('reject', item.dataset.sourceReason);
    });
    document.addEventListener('click', event => {
        if (!menu.hidden && !event.target.closest('#source-tab .complaint-wrap')) setOpen(false);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') setOpen(false);
    });
}

// ---- Автоподбор источников по теме ----

function formatAgo(timestamp) {
    if (!timestamp) return 'ещё не выполнялся';
    const minutes = Math.round((Date.now() / 1000 - timestamp) / 60);
    if (minutes < 1) return 'только что';
    if (minutes < 60) return `${minutes} мин назад`;
    const hours = Math.round(minutes / 60);
    return hours < 48 ? `${hours} ч назад` : `${Math.round(hours / 24)} дн назад`;
}

async function loadDiscoveryStatus() {
    if (DEBUG_MODE) {
        $('discovery-status').textContent = 'В демо-режиме автоподбор отключён.';
        $('discovery-button').disabled = true;
        return;
    }
    try {
        const data = await api('discovery_status');
        const sameTopic = (data.last_topic || '').toLowerCase() === (data.topic || '').toLowerCase();
        const parts = [
            `Тема «${data.topic}».`,
            sameTopic && data.last_run ? `Последний подбор: ${formatAgo(data.last_run)}.` : 'По этой теме подбора ещё не было.',
            `В каталоге найденных: ${data.auto_added}, ждут вашего решения: ${data.suggestions}.`
        ];
        if (data.frozen) parts.push(`Заморожено из-за редких постов: ${data.frozen} (перепроверяются раз в несколько дней).`);
        if (data.requested) parts.push('Подбор заказан — начнётся при запуске парсера.');
        state.discovering = Boolean(data.requested || data.running);
        if (!data.vk_connected) parts.push('Подключите VK, чтобы искать и сообщества ВКонтакте.');
        $('discovery-status').textContent = parts.join(' ');
    } catch (error) {
        $('discovery-status').textContent = `Сведения недоступны: ${error.message}`;
    }
}

async function startDiscovery() {
    const button = $('discovery-button');
    button.disabled = true;
    try {
        const data = await api('discover_sources', { body: null });
        log(data.message);
        state.discovering = true;
        if (!state.source) showArea('empty', 'Новых источников пока нет.');
        watchParser();
    } catch (error) {
        setMessage('source-error', `Автоподбор не запущен: ${error.message}`);
    } finally {
        button.disabled = false;
        loadDiscoveryStatus();
    }
}

export function initializeSourceTab() {
    registerViewerGallery('source', sourceGallery);
    initializeTopicSources();
    document.querySelectorAll('#source-tab [data-source-vote]').forEach(button => {
        button.addEventListener('click', () => vote(button.dataset.sourceVote));
    });
    initializeComplaintMenu();
    $('source-platform').addEventListener('change', () => { state.skipped = []; loadNextSource(); });
    $('source-empty-retry').addEventListener('click', () => { state.skipped = []; loadNextSource(); });
    $('source-empty-discover').addEventListener('click', startDiscovery);
    $('discovery-button').addEventListener('click', startDiscovery);
    onTabOpen('source-tab', () => {
        loadDiscoveryStatus();
        if (!state.source && !state.loading) loadNextSource();
    });
    // Парсер закончил работу — подбор мог найти новые источники.
    onParserStateChange(running => {
        if (running) return;
        state.discovering = false;
        if (!$('source-tab').classList.contains('active')) return;
        loadDiscoveryStatus();
        if (!state.source && !state.loading) loadNextSource();
    });
}
