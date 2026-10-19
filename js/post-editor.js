// post-editor.js — модальный редактор поста: медиа (порядок, удаление, добавление), текст, итоговая сводка.
// Открывается из панели 🚀 по кнопке «Запостить». Окно собирает готовый пост и передаёт его в onSubmit (сама отправка — в content.js);
// пока onSubmit работает, окно показывает «Отправляю…», при ошибке остаётся открытым с её текстом. Черновик правок помнится, пока страница открыта.
import { el, log, pluralRu } from './core.js';
import { formatWhen, pickDateTime } from './datetime-picker.js';

// Лимиты текста: у Telegram подпись к медиа — 1024 символа, пост без медиа — 4096; у VK — 16384.
const TEXT_LIMITS = { tg: { withMedia: 1024, textOnly: 4096 }, vk: { withMedia: 16384, textOnly: 16384 } };
const MAX_MEDIA = 10;

const drafts = new Map();   // ключ записи → { media, text }
let ui = null;              // собранное окно
let session = null;         // состояние открытого редактора

function textLimit(targetValue, mediaCount) {
    const limits = TEXT_LIMITS[String(targetValue).split(':')[0]] || TEXT_LIMITS.tg;
    return mediaCount ? limits.withMedia : limits.textOnly;
}

function buildUi() {
    const title = el('h2', { id: 'post-editor-title', textContent: 'Новый пост' });
    const chips = el('div', { className: 'pe-chips' });
    const closeButton = el('button', { type: 'button', className: 'pe-close', ariaLabel: 'Закрыть', textContent: '✕' });

    const mediaCount = el('span', { className: 'pe-count' });
    const grid = el('div', { className: 'pe-grid' });
    const fileInput = el('input', { type: 'file', accept: 'image/*,video/*', multiple: true, hidden: true });
    const urlInput = el('input', { type: 'url', className: 'pe-input', placeholder: 'Ссылка на медиа: https://…' });
    const urlAdd = el('button', { type: 'button', className: 'pe-btn pe-btn-ghost', textContent: 'Добавить по ссылке' });
    const mediaNote = el('p', { className: 'pe-note' });

    const textArea = el('textarea', { className: 'pe-text', rows: 8, placeholder: 'Текст поста…', spellcheck: true });
    const counter = el('span', { className: 'pe-counter' });
    const insertOriginal = el('button', { type: 'button', className: 'pe-chip-btn', textContent: 'Текст оригинала' });
    const insertDescription = el('button', { type: 'button', className: 'pe-chip-btn', textContent: 'Описание ИИ' });
    const clearText = el('button', { type: 'button', className: 'pe-chip-btn', textContent: 'Очистить' });

    const summary = el('span', { className: 'pe-summary' });
    const resetButton = el('button', { type: 'button', className: 'pe-btn pe-btn-ghost', textContent: 'Сбросить правки' });
    const cancelButton = el('button', { type: 'button', className: 'pe-btn', textContent: 'Отмена' });
    const submitButton = el('button', { type: 'submit', className: 'pe-btn pe-btn-primary', textContent: 'Запостить' });
    const warning = el('p', { className: 'pe-warning', role: 'alert', hidden: true });

    const form = el('form', { className: 'pe-window', role: 'dialog', ariaModal: 'true', ariaLabelledby: 'post-editor-title' },
        el('header', { className: 'pe-header' }, el('div', { className: 'pe-heading' }, el('span', { className: 'pe-icon', textContent: '🚀' }, ), title), closeButton),
        chips,
        el('div', { className: 'pe-body' },
            el('section', { className: 'pe-section pe-media-section' },
                el('div', { className: 'pe-section-head' }, el('h3', { textContent: 'Медиа' }), mediaCount),
                grid,
                el('div', { className: 'pe-url-row' }, urlInput, urlAdd),
                mediaNote,
                fileInput),
            el('section', { className: 'pe-section pe-text-section' },
                el('div', { className: 'pe-section-head' }, el('h3', { textContent: 'Текст' }), counter),
                textArea,
                el('div', { className: 'pe-chip-row' }, insertOriginal, insertDescription, clearText))),
        warning,
        el('footer', { className: 'pe-footer' }, summary, el('div', { className: 'pe-actions' }, resetButton, cancelButton, submitButton)));
    const overlay = el('div', { className: 'pe-overlay', hidden: true }, form);
    document.body.append(overlay);
    return { overlay, form, title, chips, grid, mediaCount, mediaNote, fileInput, urlInput, urlAdd, textArea, counter,
        insertOriginal, insertDescription, clearText, summary, resetButton, cancelButton, closeButton, submitButton, warning };
}

function chip(label, value) {
    return el('span', { className: 'pe-chip' }, el('span', { className: 'pe-chip-label', textContent: label }), el('strong', { textContent: value }));
}

function mediaCard(item, index, total) {
    const preview = item.kind === 'video'
        ? el('video', { src: item.src, poster: item.thumb || '', muted: true, preload: 'metadata', playsInline: true })
        : el('img', { src: item.thumb || item.src, alt: '', draggable: false });
    const card = el('div', { className: 'pe-card', draggable: true },
        preview,
        el('span', { className: 'pe-order', textContent: String(index + 1) }),
        item.kind === 'video' ? el('span', { className: 'pe-play', textContent: '▶' }) : '',
        el('div', { className: 'pe-card-tools' },
            el('button', { type: 'button', className: 'pe-tool', ariaLabel: 'Сдвинуть влево', textContent: '‹', disabled: index === 0, onclick: () => moveMedia(index, index - 1) }),
            el('button', { type: 'button', className: 'pe-tool pe-tool-danger', ariaLabel: 'Убрать из поста', textContent: '✕', onclick: () => removeMedia(index) }),
            el('button', { type: 'button', className: 'pe-tool', ariaLabel: 'Сдвинуть вправо', textContent: '›', disabled: index === total - 1, onclick: () => moveMedia(index, index + 1) })));
    card.addEventListener('dragstart', event => { session.dragFrom = index; card.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move'; });
    card.addEventListener('dragend', () => { session.dragFrom = null; card.classList.remove('is-dragging'); });
    card.addEventListener('dragover', event => { if (session.dragFrom !== null) { event.preventDefault(); card.classList.add('is-over'); } });
    card.addEventListener('dragleave', () => card.classList.remove('is-over'));
    card.addEventListener('drop', event => {
        event.preventDefault();
        const from = session.dragFrom;
        session.dragFrom = null;
        if (from !== null && from !== index) moveMedia(from, index);
    });
    return card;
}

function moveMedia(from, to) {
    const list = session.media;
    if (to < 0 || to >= list.length) return;
    list.splice(to, 0, list.splice(from, 1)[0]);
    render();
}

function removeMedia(index) {
    session.media.splice(index, 1);
    render();
}

function addMedia(items) {
    for (const item of items) {
        if (session.media.length >= MAX_MEDIA) {
            ui.mediaNote.textContent = `В пост помещается не больше ${MAX_MEDIA} медиа.`;
            break;
        }
        session.media.push(item);
    }
    render();
}

function kindFromUrl(url) {
    return /\.(mp4|webm|mov|m4v)(\?|#|$)/i.test(url) ? 'video' : 'image';
}

/** Перерисовывает медиа, счётчик и сводку; сохраняет черновик. */
function render() {
    const { media, options } = session;
    ui.grid.replaceChildren(...media.map((item, index) => mediaCard(item, index, media.length)));
    if (!media.length) ui.grid.append(el('div', { className: 'pe-empty', textContent: 'Медиа нет — пост уйдёт только с текстом' }));
    const addTile = el('button', { type: 'button', className: 'pe-add', ariaLabel: 'Добавить файл', onclick: () => ui.fileInput.click() },
        el('span', { className: 'pe-add-plus', textContent: '+' }), el('span', { textContent: 'Файл' }));
    ui.grid.append(addTile);
    ui.mediaCount.textContent = `${media.length} / ${MAX_MEDIA}`;
    if (media.length < MAX_MEDIA && ui.mediaNote.textContent.startsWith('В пост помещается')) ui.mediaNote.textContent = '';

    const text = ui.textArea.value;
    const limit = textLimit(options.target.value, media.length);
    ui.counter.textContent = `${text.length} / ${limit}`;
    ui.counter.classList.toggle('is-over', text.length > limit);

    const empty = !media.length && text.trim() === '';
    const over = text.length > limit;
    ui.submitButton.disabled = empty || over;
    ui.warning.hidden = !(empty || over);
    ui.warning.textContent = empty ? 'Добавьте медиа или текст.' : `Текст длиннее лимита: ${text.length} из ${limit}. Сократите его или уберите медиа.`;
    ui.summary.textContent = `${media.length} ${pluralRu(media.length, 'медиа', 'медиа', 'медиа')} · ${text.length} ${pluralRu(text.length, 'символ', 'символа', 'символов')}`;
    ui.insertOriginal.disabled = !options.original;
    ui.insertDescription.disabled = !options.description;
    drafts.set(options.key, { media: media.map(item => ({ ...item })), text });
}

function close() {
    if (!session) return;
    ui.overlay.hidden = true;
    document.body.classList.remove('post-editor-open');
    session = null;
}

function ensureUi() {
    if (ui) return;
    ui = buildUi();
    ui.closeButton.addEventListener('click', close);
    ui.cancelButton.addEventListener('click', close);
    ui.overlay.addEventListener('mousedown', event => { if (event.target === ui.overlay) close(); });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && session) { event.preventDefault(); close(); }
    });
    ui.textArea.addEventListener('input', () => session && render());
    ui.insertOriginal.addEventListener('click', () => { ui.textArea.value = session.options.original; render(); ui.textArea.focus(); });
    ui.insertDescription.addEventListener('click', () => { ui.textArea.value = session.options.description; render(); ui.textArea.focus(); });
    ui.clearText.addEventListener('click', () => { ui.textArea.value = ''; render(); ui.textArea.focus(); });
    ui.resetButton.addEventListener('click', () => {
        drafts.delete(session.options.key);
        session.media = session.options.media.map(item => ({ ...item }));
        ui.textArea.value = session.options.original || session.options.description || '';
        ui.mediaNote.textContent = '';
        render();
    });
    ui.fileInput.addEventListener('change', () => {
        const files = [...ui.fileInput.files];
        ui.fileInput.value = '';
        addMedia(files.filter(file => /^(image|video)\//.test(file.type)).map(file => {
            const objectUrl = URL.createObjectURL(file);
            return { kind: file.type.startsWith('video') ? 'video' : 'image', src: objectUrl, thumb: file.type.startsWith('video') ? '' : objectUrl, name: file.name, file, objectUrl };
        }));
    });
    const addByUrl = () => {
        const value = ui.urlInput.value.trim();
        if (!value) return;
        let url;
        try { url = new URL(value); } catch { ui.mediaNote.textContent = 'Это не ссылка.'; return; }
        if (url.protocol !== 'https:' && url.protocol !== 'http:') { ui.mediaNote.textContent = 'Нужна ссылка http или https.'; return; }
        ui.urlInput.value = '';
        ui.mediaNote.textContent = '';
        addMedia([{ kind: kindFromUrl(url.href), src: url.href, thumb: kindFromUrl(url.href) === 'image' ? url.href : '', name: url.pathname.split('/').pop() || url.hostname }]);
    };
    ui.urlAdd.addEventListener('click', addByUrl);
    ui.urlInput.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); addByUrl(); } });
    ui.form.addEventListener('submit', async event => {
        event.preventDefault();
        if (!session || ui.submitButton.disabled) return;
        const { options, media } = session;
        // file — файл, выбранный в редакторе: на сервер он уходит загрузкой, остальные медиа — по адресу (кэш сайта или ссылка)
        const post = { target: options.target.value, targetLabel: options.target.label, scheduledAt: options.scheduledAt, scheduledTs: options.scheduledTs || 0,
            media: media.map(({ kind, src, name, file }) => ({ kind, src, name, file })), text: ui.textArea.value.trim() };
        const idleLabel = ui.submitButton.textContent;
        ui.submitButton.disabled = true;
        ui.cancelButton.disabled = true;
        ui.submitButton.textContent = 'Отправляю…';
        ui.warning.hidden = true;
        try {
            await options.onSubmit(post);
            drafts.delete(options.key);
            close();
        } catch (error) {
            if (!session) return;   // окно уже закрыли
            ui.warning.hidden = false;
            ui.warning.textContent = error.message || String(error);
        } finally {
            ui.submitButton.textContent = idleLabel;
            ui.cancelButton.disabled = false;
            if (session) ui.submitButton.disabled = false;
        }
    });
}

/** Подписи «Куда / Когда», заголовок и кнопка отправки по текущему времени публикации. «Когда» — кнопка с тем же окном выбора, что в панели 🚀. */
function renderWhen() {
    const { options } = session;
    const scheduled = Boolean(options.scheduledTs);
    const when = el('button', { type: 'button', className: 'pe-chip pe-chip-when', title: 'Выбрать дату и время публикации' },
        el('span', { className: 'pe-chip-label', textContent: 'Когда' }),
        el('strong', { textContent: scheduled ? formatWhen(new Date(options.scheduledTs * 1000)) : 'сразу' }));
    when.addEventListener('click', async () => {
        const chosen = await pickDateTime({ value: scheduled ? new Date(options.scheduledTs * 1000) : null });
        if (chosen === undefined || !session) return;
        options.scheduledTs = chosen ? Math.floor(chosen.getTime() / 1000) : 0;
        options.scheduledAt = chosen ? formatWhen(chosen) : '';
        renderWhen();
    });
    ui.chips.replaceChildren(chip('Куда', options.target.label), when);
    ui.title.textContent = scheduled ? 'Отложенный пост' : 'Новый пост';
    ui.submitButton.textContent = scheduled ? 'Запланировать' : 'Запостить';
}

/**
 * Открывает редактор. options: { key, target: {value, label}, scheduledAt: 'ГГГГ-ММ-ДД ЧЧ:ММ' | '', scheduledTs: unix-время | 0, media: [{kind, src, thumb, name}],
 * original, description, onSubmit(post) → Promise (ошибка показывается в окне) }. Если для key есть черновик, показывается он.
 */
export function openPostEditor(options) {
    ensureUi();
    const draft = drafts.get(options.key);
    session = { options, media: (draft ? draft.media : options.media).map(item => ({ ...item })), dragFrom: null };
    renderWhen();
    ui.textArea.value = draft ? draft.text : (options.original || options.description || '');
    ui.mediaNote.textContent = '';
    ui.urlInput.value = '';
    ui.overlay.hidden = false;
    document.body.classList.add('post-editor-open');
    render();
    ui.textArea.focus();
    ui.textArea.setSelectionRange(ui.textArea.value.length, ui.textArea.value.length);
}
