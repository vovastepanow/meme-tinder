// model-panel.js — отладочная панель под журналом: что загружено в Ollama и на видеокарте, загрузка и выгрузка моделей.
import { $, api, el, log } from './core.js';

const REFRESH_OPEN_MS = 4000;     // пока панель раскрыта
const REFRESH_CLOSED_MS = 20000;  // свёрнутая показывает только строку-сводку
const ROLE_LABELS = { prescreen: 'быстрая', analysis: 'основная' };

let busy = false;
let timer = null;
let lastStatus = null;

function untilText(expiresAt) {
    const seconds = Math.round((new Date(expiresAt) - Date.now()) / 1000);
    if (!Number.isFinite(seconds) || seconds <= 0) return '';
    return seconds >= 120 ? `выгрузится через ${Math.round(seconds / 60)} мин` : `выгрузится через ${seconds} с`;
}

function roleOf(name, roles) {
    const role = Object.keys(roles).find(key => roles[key] === name || `${roles[key]}:latest` === name);
    return role ? ROLE_LABELS[role] : '';
}

function render(status) {
    lastStatus = status;
    if (!status.ollama) {
        $('model-summary').textContent = 'Ollama недоступна';
        $('model-gpu').textContent = 'Служба Ollama не отвечает. Перезапуск службы: sudo systemctl restart ollama';
        $('model-loaded').replaceChildren();
        return;
    }
    const loaded = status.loaded;
    $('model-summary').textContent = loaded.length
        ? loaded.map(model => `${model.name} · ${model.gpu_percent}% GPU`).join(', ')
        : 'ничего не загружено';
    const gpu = status.gpu;
    $('model-gpu').textContent = [
        `Ollama ${status.version}`,
        gpu ? `видеопамять ${gpu.used_mb} / ${gpu.total_mb} МБ, загрузка GPU ${gpu.util}%`
            : `модели в видеопамяти: ${loaded.reduce((sum, model) => sum + model.vram_mb, 0)} МБ`,
        status.parser_running ? 'парсер работает' : 'парсер остановлен'
    ].join(' · ');
    $('model-loaded').replaceChildren(...loaded.map(model => {
        const role = roleOf(model.name, status.roles);
        const details = [`${model.size_mb} МБ`, `${100 - model.gpu_percent}%/${model.gpu_percent}% CPU/GPU`, `контекст ${model.context}`, untilText(model.expires_at)];
        return el('li', {},
            el('span', { className: 'model-loaded-name', textContent: model.name + (role ? ` (${role})` : '') }),
            el('span', { className: 'model-loaded-details', textContent: details.filter(Boolean).join(' · ') }),
            el('button', { type: 'button', textContent: 'Выгрузить', onclick: () => control({ op: 'unload', model: model.name }) })
        );
    }));
    // В подписях кнопок загрузки — имена моделей, чтобы было видно, что именно загрузится
    for (const button of document.querySelectorAll('#model-panel [data-role]')) {
        button.title = status.roles[button.dataset.role] || '';
    }
}

async function refresh() {
    clearTimeout(timer);
    try {
        render(await api('model_status'));
    } catch (error) {
        $('model-summary').textContent = `нет ответа: ${error.message}`;
    }
    timer = setTimeout(refresh, $('model-panel').open ? REFRESH_OPEN_MS : REFRESH_CLOSED_MS);
}

async function control(body) {
    if (busy) return;
    if (lastStatus?.parser_running && !confirmInPanel(body)) return;
    busy = true;
    const buttons = document.querySelectorAll('#model-panel button');
    buttons.forEach(button => { button.disabled = true; });
    $('model-panel-note').textContent = body.op === 'load' || body.op === 'restart'
        ? 'Загружаю модель — до минуты…' : 'Выгружаю…';
    try {
        const result = await api('model_control', { body });
        $('model-panel-note').textContent = result.message;
        log(`Модель: ${result.message}`);
    } catch (error) {
        $('model-panel-note').textContent = `Ошибка: ${error.message}`;
        log(`Модель: ошибка — ${error.message}`);
    } finally {
        busy = false;
        buttons.forEach(button => { button.disabled = false; });
        refresh();
    }
}

/**
 * Пока работает парсер, ручная выгрузка оборвёт его текущий запрос к модели: первое нажатие только предупреждает,
 * повторное в течение 5 секунд выполняет действие (диалоги confirm() тут не используются).
 */
let armedOp = '';
let armedAt = 0;
function confirmInPanel(body) {
    const key = JSON.stringify(body);
    if (armedOp === key && Date.now() - armedAt < 5000) {
        armedOp = '';
        return true;
    }
    armedOp = key;
    armedAt = Date.now();
    $('model-panel-note').textContent = 'Парсер сейчас работает: его текущий запрос к модели оборвётся. Нажмите ещё раз, чтобы подтвердить.';
    return false;
}

export function initializeModelPanel() {
    $('model-panel').addEventListener('toggle', refresh);
    for (const button of document.querySelectorAll('#model-panel [data-model-op]')) {
        button.addEventListener('click', () => control({ op: button.dataset.modelOp, role: button.dataset.role }));
    }
    refresh();
}
