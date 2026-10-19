// tree.js — вкладка «🌳 Дерево»: единственное место, где выбирается и меняется тема поиска.
// Слева — дерево «общая → частные» (разметка — вложенные details, сворачивание без скриптов; линии — css/tree.css),
// справа — панель выбранной темы: описание, критерии отбора, свои источники, частные темы и действия над ней.
// Выбранная тема становится текущей кнопкой «Сделать текущей» или вводом названия; общую тему и профиль поправить здесь же.
import { $, DEBUG_MODE, api, el, hide, log, onTabOpen, setMessage, show, watchParser } from './core.js';
import { loadCurrentItem, refreshTopicLabel } from './content.js';

const ICONS = { broad: '🗂', narrow: '🔹' };
const SWITCH_CONFIRM = 'Сменить тему поиска? Неоценённые записи по старой теме будут убраны из пула, и поиск начнётся заново. История оценок сохранится.';

const state = { topics: [], current: null, selected: null, busy: false };

// ---- Дерево ----

/** Узлы по родителям: общие темы — корни, частные — внутри своей общей. Частная без общей остаётся корнем. */
function buildTree(topics) {
    const ids = new Set(topics.map(topic => topic.id));
    const childrenOf = new Map();
    const roots = [];
    for (const topic of topics) {
        const parentId = topic.parent?.id;
        if (parentId && ids.has(parentId)) {
            if (!childrenOf.has(parentId)) childrenOf.set(parentId, []);
            childrenOf.get(parentId).push(topic);
        } else {
            roots.push(topic);
        }
    }
    const byTitle = (a, b) => a.title.localeCompare(b.title, 'ru');
    roots.sort((a, b) => (b.scope === 'broad') - (a.scope === 'broad') || byTitle(a, b));
    for (const list of childrenOf.values()) list.sort(byTitle);
    return { roots, childrenOf };
}

function renderNode(topic, childrenOf) {
    const children = childrenOf.get(topic.id) || [];
    const isCurrent = state.current === topic.id;
    const isSelected = state.selected === topic.id;
    const label = el('span', { className: 'tree-label', title: topic.about || topic.title },
        el('span', { className: 'tree-icon', textContent: isCurrent ? '📍' : ICONS[topic.scope] || '🔹' }),
        el('span', { className: 'tree-name', textContent: topic.title }),
        el('span', { className: `tree-count${topic.own_sources ? '' : ' is-empty'}`, textContent: String(topic.own_sources || 0), title: 'Свои источники темы' }),
        ...(topic.uses_parent_sources ? [el('span', { className: 'tree-note', textContent: 'берёт источники общей' })] : []));
    label.addEventListener('click', () => select(topic.id));
    const classes = ['tree-node', `tree-${topic.scope}`, isCurrent && 'is-current', isSelected && 'is-selected'].filter(Boolean);
    const item = el('li', { className: classes.join(' ') });
    if (!children.length) {
        item.append(label);
        return item;
    }
    const details = el('details', { className: 'tree-branch', open: topic.scope === 'broad' || isSelected });
    details.append(el('summary', {}, label));
    details.append(el('ul', { className: 'tree-children' }, ...children.map(child => renderNode(child, childrenOf))));
    item.append(details);
    return item;
}

function renderTree() {
    const { roots, childrenOf } = buildTree(state.topics);
    $('tree-root').replaceChildren(el('ul', { className: 'tree' }, ...roots.map(root => renderNode(root, childrenOf))));
    const current = state.topics.find(topic => topic.id === state.current);
    $('tree-current-name').textContent = current ? current.title : '—';
    $('tree-status').textContent = state.topics.length ? '' : 'Тем пока нет: введите первую тему выше.';
}

function select(id) {
    state.selected = id;
    state.parentOpen = false;
    renderTree();
    renderDetail();
}

// ---- Панель выбранной темы ----

function row(label, value) {
    return el('div', { className: 'tree-row' }, el('dt', { textContent: label }), el('dd', {}, value));
}

/** Текст о своих источниках: сколько есть и откуда берутся, если своих мало. */
function sourcesText(topic) {
    if (topic.uses_parent_sources) return `${topic.own_sources || 0} своих. Пока берутся источники общей темы «${topic.parent.title}».`;
    if (!topic.own_sources) return 'Своих пока нет: автоподбор ищет их, как только тема становится текущей.';
    return `${topic.own_sources} своих — по ним идёт сбор.`;
}

function renderDetail() {
    const panel = $('tree-detail');
    const topic = state.topics.find(item => item.id === state.selected);
    if (!topic) {
        panel.replaceChildren(el('p', { className: 'tree-detail-empty', textContent: 'Выберите тему в дереве: здесь её описание, критерии отбора и действия.' }));
        return;
    }
    const isCurrent = topic.id === state.current;
    const title = el('h3', { className: 'tree-detail-title', textContent: topic.title });
    const kind = el('p', { className: 'tree-detail-kind' },
        topic.scope === 'broad' ? '🗂 общая тема' : `🔹 частная тема${topic.parent ? ` в «${topic.parent.title}»` : ''}`,
        isCurrent ? ' · 📍 сейчас выбрана' : '');
    const facts = el('dl', { className: 'tree-facts' });
    if (topic.about) facts.append(row('О теме', topic.about));
    if (topic.criteria) facts.append(row('Подходит', topic.criteria));
    if (topic.exclude) facts.append(row('Не подходит', topic.exclude));
    if (topic.question) facts.append(row('Вопрос к картинке', topic.question));
    if (topic.aliases?.length) facts.append(row('Ещё называют', topic.aliases.join(', ')));
    facts.append(row('Источники', sourcesText(topic)));
    if (topic.children?.length) facts.append(row('Частные темы', topic.children.join(', ')));
    if (!topic.profile) facts.append(row('Профиль', 'ещё не составлен: напишется при следующем запуске сборщика.'));

    const actions = el('div', { className: 'tree-actions' });
    if (!isCurrent) actions.append(el('button', { className: 'primary-button', type: 'button', textContent: 'Сделать текущей', onclick: () => choose(topic.title) }));
    actions.append(el('button', { className: 'secondary-button', type: 'button', textContent: 'Сменить общую тему', onclick: () => toggleParentEditor(topic) }));
    actions.append(el('button', { className: 'secondary-button', type: 'button', textContent: 'Пересоставить профиль', title: 'Критерии и план поиска напишутся заново', onclick: () => rebuildProfile(topic) }));

    panel.replaceChildren(title, kind, facts, actions, parentEditor(topic));
}

// ---- Смена общей темы ----

function parentEditor(topic) {
    const select = el('select', { className: 'tree-parent-select', ariaLabel: 'Общая тема' });
    const broad = state.topics.filter(item => item.scope === 'broad' && item.id !== topic.id);
    select.append(el('option', { value: '', textContent: '— это сама общая тема —' }), ...broad.map(item => el('option', { value: item.id, textContent: item.title })),
        el('option', { value: '__new', textContent: 'Новая общая тема…' }));
    select.value = topic.scope === 'broad' ? '' : (topic.parent?.id || '__new');
    const input = el('input', { className: 'tree-parent-new', type: 'text', maxLength: 40, placeholder: 'Название новой общей темы', autocomplete: 'off', hidden: select.value !== '__new' });
    select.addEventListener('change', () => { input.hidden = select.value !== '__new'; if (!input.hidden) input.focus(); });
    const save = el('button', { className: 'secondary-button', type: 'button', textContent: 'Готово' });
    const apply = async () => {
        const parent = select.value === '__new' ? input.value.trim() : select.value;
        if (select.value === '__new' && !parent) { input.focus(); return; }
        save.disabled = true;
        try {
            const data = await api('topic_parent', { body: { id: topic.id, parent } });
            log(data.topic_info.parent ? `Тема «${data.topic_info.title}» теперь внутри «${data.topic_info.parent.title}».` : `Тема «${data.topic_info.title}» теперь общая.`);
            await load();
        } catch (error) {
            setMessage('tree-status', `Общая тема не сохранена: ${error.message}`);
        } finally {
            save.disabled = false;
        }
    };
    save.addEventListener('click', apply);
    input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); apply(); } });
    const editor = el('div', { className: 'tree-parent-editor', id: 'tree-parent-editor', hidden: !state.parentOpen }, select, input, save);
    return editor;
}

function toggleParentEditor() {
    state.parentOpen = !state.parentOpen;
    const editor = $('tree-parent-editor');
    if (editor) editor.hidden = !state.parentOpen;
    if (!state.parentOpen) return;
    const select = editor?.querySelector('select');
    select?.focus();
}

// ---- Выбор темы ----

function setBusy(busy, message = '') {
    state.busy = busy;
    $('tree-save').disabled = busy;
    $('tree-input').disabled = busy;
    $('tree-root').classList.toggle('is-busy', busy);
    setMessage('tree-status', message);
}

/** Делает тему текущей. Ввод, который модель узнала как другую тему, показывает подсказку и даёт возразить. */
async function choose(text, forceNew = false) {
    const typed = text.trim();
    if (!typed || state.busy) return;
    const current = state.topics.find(topic => topic.id === state.current);
    if (!forceNew && current && current.title.toLowerCase() === typed.toLowerCase()) return;
    if (!window.confirm(SWITCH_CONFIRM)) return;
    hide('tree-notice');
    setBusy(true, 'Нейросеть определяет тему: название и общую тему (до минуты)…');
    try {
        const data = await api('save_topic', { body: { topic: typed, force_new: forceNew } });
        if (data.matched && data.typed && data.topic.toLowerCase() !== data.typed.toLowerCase()) {
            $('tree-notice-text').textContent = `«${data.typed}» — это тема «${data.topic}».`;
            $('tree-force-new').dataset.typed = data.typed;
            show('tree-notice');
        }
        $('tree-input').value = '';
        state.current = data.topic_info?.id ?? null;
        state.selected = state.current;
        log(`Тема поиска: «${data.topic}».`);
        if (data.created && data.topic_info?.parent) log(`Новая тема «${data.topic}» — частная тема внутри «${data.topic_info.parent.title}».`);
        if (data.discovery) log('У темы пока мало своих источников — сначала ищу новые (поиск в Telegram, VK, на Дваче). Ход поиска — в журнале.');
        watchParser();
        await load();
        await refreshTopicLabel();
        await loadCurrentItem();
    } catch (error) {
        setMessage('tree-status', `Тема не сохранена: ${error.message}`);
    } finally {
        setBusy(false);
    }
}

async function rebuildProfile(topic) {
    try {
        await api('topic_reprofile', { body: { id: topic.id } });
        log(`Профиль темы «${topic.title}» пересоставится при следующем запуске сборщика.`);
        await load();
    } catch (error) {
        setMessage('tree-status', `Профиль не сброшен: ${error.message}`);
    }
}

// ---- Загрузка ----

async function load() {
    if (DEBUG_MODE) {
        setMessage('tree-status', 'В демо-режиме дерево тем не показывается.');
        return;
    }
    try {
        const data = await api('topics');
        state.topics = data.topics || [];
        state.current = data.current ?? null;
        if (!state.selected || !state.topics.some(topic => topic.id === state.selected)) state.selected = state.current;
        $('tree-options').replaceChildren(...state.topics.map(topic => el('option', { value: topic.title, label: topic.path })));
        renderTree();
        renderDetail();
    } catch (error) {
        setMessage('tree-status', `Не удалось загрузить темы: ${error.message}`);
    }
}

export function initializeTreeTab() {
    $('tree-form').addEventListener('submit', event => { event.preventDefault(); choose($('tree-input').value); });
    $('tree-force-new').addEventListener('click', event => choose(event.currentTarget.dataset.typed || $('tree-input').value, true));
    $('tree-refresh').addEventListener('click', load);
    if (DEBUG_MODE) {
        hide('tree-form');
        hide('tree-refresh');
    }
    onTabOpen('tree-tab', load);
}
