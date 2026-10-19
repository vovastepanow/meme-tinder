// datetime-picker.js — небольшое модальное окно выбора даты и времени: календарь, часы и минуты, быстрые варианты.
// pickDateTime({ value: Date | null, host: элемент }) → Promise: Date — выбрано время, null — «Сразу», undefined — окно закрыто без выбора.
import { el } from './core.js';

const MONTHS = ['Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
const WEEKDAYS = ['Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс'];
const MIN_LEAD_MS = 60 * 1000;   // как на сервере: пост нужно назначить минимум на минуту вперёд
const pad = number => String(number).padStart(2, '0');
const sameDay = (a, b) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();

/** «Сегодня, 20:30», «Завтра, 10:00» или «25 октября, 10:00». */
export function formatWhen(date) {
    const time = `${pad(date.getHours())}:${pad(date.getMinutes())}`;
    const today = new Date();
    if (sameDay(date, today)) return `Сегодня, ${time}`;
    if (sameDay(date, new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1))) return `Завтра, ${time}`;
    return `${date.getDate()} ${MONTHS[date.getMonth()].toLowerCase().replace(/[ьй]$/, 'я').replace(/т$/, 'та')}, ${time}`;
}

export function pickDateTime({ value = null, host = document.body } = {}) {
    return new Promise(resolve => {
        const now = new Date();
        const start = value && value > now ? value : new Date(Math.ceil((now.getTime() + 60 * 60 * 1000) / 300000) * 300000);
        const selected = new Date(start);
        const view = new Date(start.getFullYear(), start.getMonth(), 1);
        const previousFocus = document.activeElement;

        const title = el('span', { className: 'dtp-title' });
        const grid = el('div', { className: 'dtp-grid' });
        const hours = el('select', { className: 'dtp-select', ariaLabel: 'Часы' });
        const minutes = el('select', { className: 'dtp-select', ariaLabel: 'Минуты' });
        for (let h = 0; h < 24; h++) hours.append(el('option', { value: h, textContent: pad(h) }));
        for (let m = 0; m < 60; m++) minutes.append(el('option', { value: m, textContent: pad(m) }));
        const summary = el('div', { className: 'dtp-summary' });
        const done = el('button', { type: 'button', className: 'dtp-done', textContent: 'Готово' });
        const root = el('div', { className: 'dtp-overlay', role: 'dialog', ariaModal: 'true', ariaLabel: 'Выбор даты и времени' });

        const isFuture = () => selected.getTime() >= Date.now() + MIN_LEAD_MS;
        const close = result => {
            document.removeEventListener('keydown', onKey, true);
            root.remove();
            if (previousFocus?.focus) previousFocus.focus();
            resolve(result);
        };
        const onKey = event => {
            if (event.key !== 'Escape') return;
            event.preventDefault();
            event.stopPropagation();
            close(undefined);
        };

        const renderCalendar = () => {
            title.textContent = `${MONTHS[view.getMonth()]} ${view.getFullYear()}`;
            const offset = (new Date(view.getFullYear(), view.getMonth(), 1).getDay() + 6) % 7;   // неделя с понедельника
            const days = new Date(view.getFullYear(), view.getMonth() + 1, 0).getDate();
            const cells = WEEKDAYS.map(name => el('span', { className: 'dtp-weekday', textContent: name }));
            for (let i = 0; i < offset; i++) cells.push(el('span'));
            const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            for (let day = 1; day <= days; day++) {
                const date = new Date(view.getFullYear(), view.getMonth(), day);
                const button = el('button', { type: 'button', className: 'dtp-day', textContent: String(day), disabled: date < today });
                if (sameDay(date, today)) button.classList.add('is-today');
                if (sameDay(date, selected)) button.classList.add('is-selected');
                button.addEventListener('click', () => {
                    selected.setFullYear(date.getFullYear(), date.getMonth(), date.getDate());
                    refresh();
                });
                cells.push(button);
            }
            grid.replaceChildren(...cells);
        };
        const refresh = () => {
            renderCalendar();
            hours.value = String(selected.getHours());
            minutes.value = String(selected.getMinutes());
            const ok = isFuture();
            summary.textContent = ok ? formatWhen(selected) : 'Выберите время хотя бы на минуту вперёд';
            summary.classList.toggle('is-error', !ok);
            done.disabled = !ok;
        };
        const setMonth = delta => { view.setMonth(view.getMonth() + delta); renderCalendar(); };
        const choose = date => { selected.setTime(date.getTime()); view.setFullYear(date.getFullYear(), date.getMonth(), 1); refresh(); };
        const at = (daysAhead, hour) => new Date(now.getFullYear(), now.getMonth(), now.getDate() + daysAhead, hour, 0);

        const presets = [
            ['Через час', new Date(Math.ceil((now.getTime() + 3600000) / 300000) * 300000)],
            ['Сегодня 20:00', at(0, 20)],
            ['Завтра 10:00', at(1, 10)],
            ['Завтра 20:00', at(1, 20)]
        ].filter(([, date]) => date.getTime() >= now.getTime() + MIN_LEAD_MS);

        hours.addEventListener('change', () => { selected.setHours(Number(hours.value)); refresh(); });
        minutes.addEventListener('change', () => { selected.setMinutes(Number(minutes.value)); refresh(); });
        done.addEventListener('click', () => { if (isFuture()) close(new Date(selected)); });
        const prev = el('button', { type: 'button', className: 'dtp-nav', textContent: '‹', ariaLabel: 'Предыдущий месяц' });
        const next = el('button', { type: 'button', className: 'dtp-nav', textContent: '›', ariaLabel: 'Следующий месяц' });
        prev.addEventListener('click', () => setMonth(-1));
        next.addEventListener('click', () => setMonth(1));
        const nowButton = el('button', { type: 'button', className: 'dtp-now', textContent: 'Сразу' });
        nowButton.addEventListener('click', () => close(null));
        const cancel = el('button', { type: 'button', className: 'dtp-cancel', textContent: 'Отмена' });
        cancel.addEventListener('click', () => close(undefined));

        const window_ = el('div', { className: 'dtp-window' },
            el('div', { className: 'dtp-head' }, prev, title, next),
            grid,
            ...(presets.length ? [el('div', { className: 'dtp-presets' }, ...presets.map(([label, date]) => {
                const chip = el('button', { type: 'button', className: 'dtp-chip', textContent: label });
                chip.addEventListener('click', () => choose(date));
                return chip;
            }))] : []),
            el('div', { className: 'dtp-time' }, el('span', { textContent: 'Время' }), hours, el('b', { textContent: ':' }), minutes),
            summary,
            el('div', { className: 'dtp-actions' }, nowButton, cancel, done));
        root.append(window_);
        root.addEventListener('click', event => {
            event.stopPropagation();   // окно лежит внутри панели публикации: клик не должен её закрывать
            if (event.target === root) close(undefined);
        });
        document.addEventListener('keydown', onKey, true);
        host.append(root);
        refresh();
        done.focus();
    });
}
