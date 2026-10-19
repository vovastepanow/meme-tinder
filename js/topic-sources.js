// topic-sources.js — вкладка «Источники»: уже добавленные источники текущей темы карточками в стиле «Предложки».
// В карточке — картинки последних постов (их запоминает парсер), аватар, аудитория, активность и действия:
// открыть источник или убрать его из темы (у других тем он остаётся, автоподбор его не вернёт).
import { $, DEBUG_MODE, api, el, log, onParserStateChange, onTabOpen } from './core.js';
import { openViewerGallery } from './image-viewer.js';

const COUNT_FORMAT = new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 });
const PLATFORM_SHORT = { Telegram: 'Telegram', 'ВКонтакте': 'VK', 'Двач': 'Двач' };

const state = { sources: [], topic: '', topicInfo: null, filter: 'all', loading: false };

function chip(label, value, title = '') {
    return el('span', { className: 'eng-chip', title }, `${label} `, el('b', { textContent: value }));
}

/** Аватар канала или первая буква названия. */
function avatar(source) {
    const letter = (source.name || source.id || '?').replace(/^[^\p{L}\p{N}]+/u, '').charAt(0).toUpperCase() || '?';
    const box = el('span', { className: 'source-avatar', textContent: letter });
    box.setAttribute('aria-hidden', 'true');
    if (source.avatar) {
        // Без loading=lazy: картинка ещё не в документе (заменит букву, когда загрузится), а «ленивая» вне документа не грузится
        const image = el('img', { src: source.avatar, alt: '', decoding: 'async', referrerPolicy: 'no-referrer' });
        image.addEventListener('load', () => box.replaceChildren(image), { once: true });
    }
    return box;
}

/** Мозаика из картинок последних постов; картинка не открылась — плитка убирается, не осталось ни одной — обложка с аватаром. */
function renderMedia(source) {
    const cover = () => el('div', { className: 'source-cover' }, avatar(source));
    if (!source.preview.length) return cover();
    const slides = source.preview.map(src => ({ kind: 'image', src, poster: src, sourceUrl: source.url, alt: source.name }));
    const media = el('div', { className: `proposal-media mosaic-${slides.length}` });
    const relayout = () => {
        const left = media.querySelectorAll('.proposal-tile').length;
        if (!left) media.replaceWith(cover());
        else media.className = `proposal-media mosaic-${left}`;
    };
    slides.forEach((slide, index) => {
        const tile = el('button', { className: 'proposal-tile is-loading', type: 'button', title: 'Открыть' });
        const image = el('img', { src: slide.src, alt: '', loading: 'lazy', decoding: 'async', referrerPolicy: 'no-referrer' });
        image.addEventListener('load', () => tile.classList.remove('is-loading'), { once: true });
        image.addEventListener('error', () => { tile.remove(); relayout(); }, { once: true });   // ссылка Telegram устарела
        tile.append(image);
        tile.addEventListener('click', () => openViewerGallery(slides, index));
        media.append(tile);
    });
    return media;
}

function renderCard(source) {
    const link = el('a', { href: source.url, target: '_blank', rel: 'noopener noreferrer', textContent: source.handle });
    const head = el('div', { className: 'source-head' }, avatar(source),
        el('div', { className: 'source-head-text' }, el('strong', { textContent: source.name, title: source.name }), link),
        el('span', { className: 'source-platform-tag', textContent: PLATFORM_SHORT[source.platform] || source.platform }));

    const chips = [];
    if (source.subscribers > 0) chips.push(chip('подписчики', COUNT_FORMAT.format(source.subscribers)));
    if (source.view_rate != null) chips.push(chip('просмотры', `${Math.round(source.view_rate * 100)}%`, 'Какая доля подписчиков видит пост'));
    if (source.score != null) chips.push(chip('оценка', `${source.score}%`, 'Оценка автоподбора: по теме, аудитория, доля просмотров'));

    const status = source.platform === 'Двач'
        ? el('span', { className: 'source-status', textContent: source.category ? `доска имиджборда · ${source.category}` : 'доска имиджборда' })
        : el('span', { className: `source-status${source.frozen ? ' is-frozen' : ''}`,
            textContent: source.frozen ? `заморожен: ${source.frozen_reason || 'не активен'}` : (source.activity || 'активен') });

    const notes = [];
    if (!source.own) notes.push(source.topics.length ? `из общей темы: ${source.topics.join(', ')}` : 'источник для всех тем');
    if (source.auto) notes.push('найден автоподбором');

    const card = el('article', { className: `proposal-card source-tile-card${source.frozen ? ' is-frozen' : ''}` },
        renderMedia(source), head,
        ...(chips.length ? [el('div', { className: 'source-chips' }, ...chips)] : []),
        status,
        ...(notes.length ? [el('p', { className: 'source-note', textContent: notes.join(' · ') })] : []));

    const open = el('a', { className: 'proposal-action', href: source.url, target: '_blank', rel: 'noopener noreferrer', textContent: 'Открыть ↗' });
    const actions = el('div', { className: 'proposal-actions' }, open);
    if (!DEBUG_MODE && source.own) {
        const remove = el('button', { className: 'proposal-action proposal-hide', type: 'button', textContent: '✕ Убрать', title: `Убрать из темы «${state.topic}»` });
        remove.addEventListener('click', () => removeSource(source, card));
        actions.append(remove);
    }
    card.append(actions);
    return card;
}

function render() {
    const visible = state.sources.filter(source => state.filter === 'all' || source.platform === state.filter);
    $('topic-sources-title').textContent = state.topic ? `Источники темы «${state.topic}»` : 'Источники темы';
    $('topic-sources-count').textContent = state.sources.length ? `${visible.length} из ${state.sources.length}` : '';
    $('topic-sources-status').textContent = visible.length ? '' : (state.sources.length
        ? 'На этой площадке источников темы нет.'
        : 'У темы пока нет источников: нажмите «Подобрать источники» или одобряйте предложенные выше.');
    $('topic-sources-grid').replaceChildren(...visible.map(renderCard));
    document.querySelectorAll('[data-topic-source-filter]').forEach(button => {
        button.classList.toggle('active', button.dataset.topicSourceFilter === state.filter);
    });
}

async function removeSource(source, card) {
    if (!window.confirm(`Убрать «${source.name}» из темы «${state.topic}»? Посты из него перестанут попадать в подбор по этой теме.`)) return;
    card.classList.add('is-removing');
    try {
        await api('remove_topic_source', { body: { platform: source.platform, id: source.id, name: source.name } });
        state.sources = state.sources.filter(other => other !== source);
        log(`Источник «${source.name}» убран из темы «${state.topic}».`);
        render();
    } catch (error) {
        card.classList.remove('is-removing');
        log(`Не удалось убрать источник: ${error.message}`);
    }
}

export async function loadTopicSources() {
    if (state.loading) return;
    state.loading = true;
    try {
        const data = await api('topic_sources');
        state.sources = Array.isArray(data.sources) ? data.sources : [];
        state.topic = data.topic || '';
        state.topicInfo = data.topic_info || null;
        render();
    } catch (error) {
        $('topic-sources-status').textContent = `Не удалось загрузить источники: ${error.message}`;
    } finally {
        state.loading = false;
    }
}

export function initializeTopicSources() {
    document.querySelectorAll('[data-topic-source-filter]').forEach(button => {
        button.addEventListener('click', () => { state.filter = button.dataset.topicSourceFilter; render(); });
    });
    $('topic-sources-refresh').addEventListener('click', loadTopicSources);
    onTabOpen('source-tab', loadTopicSources);
    // Парсер закончил: автоподбор мог добавить источники, сбор — обновить аудиторию и картинки
    onParserStateChange(running => {
        if (!running && $('source-tab').classList.contains('active')) loadTopicSources();
    });
}
