// test.js — вкладка «Тест ИИ»: оценка загруженного файла или изображения по ссылке.
import { $, api, el, hide, setMessage, show } from './core.js';

const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

let previewObjectUrl = '';

/** Показывает превью: файл (через object URL), ссылку или ничего. */
function setPreview(source) {
    if (previewObjectUrl) URL.revokeObjectURL(previewObjectUrl);
    previewObjectUrl = source instanceof File ? URL.createObjectURL(source) : '';
    const preview = $('test-image');
    const url = previewObjectUrl || (typeof source === 'string' ? source : '');
    if (url) preview.src = url;
    else preview.removeAttribute('src');
}

function onFileChosen(event) {
    const file = event.target.files[0] || null;
    if (file) $('image-url').value = '';
    setPreview(file);
    hide('test-result');
}

function onUrlInput() {
    if (!$('image-url').value) return;
    $('image-file').value = '';
    setPreview(null);
}

function renderResult(data) {
    $('test-rating').textContent = `${Number(data.rating)}%`;
    $('test-relevance').textContent = `По теме: ${Number(data.relevance)}%`;
    $('test-model').textContent = `Тема «${data.topic}» · прогноз по вашим оценкам · ${data.model}`;
    $('test-description').replaceChildren(el('strong', { textContent: 'Описание ИИ:' }), ` ${data.description || 'Описание не получено'}`);
    $('test-why').textContent = data.why_relevant ? `Почему по теме: ${data.why_relevant}` : '';
    show('test-result');
}

async function analyzeTestImage(event) {
    event.preventDefault();
    const file = $('image-file').files[0];
    const imageUrl = $('image-url').value.trim();

    setMessage('test-error', '');
    hide('test-result');
    if (!file && !imageUrl) {
        setMessage('test-error', 'Добавьте ссылку или выберите файл изображения.');
        return;
    }
    if (file && file.size > MAX_IMAGE_BYTES) {
        setMessage('test-error', 'Файл больше 10 МБ. Выберите изображение поменьше.');
        return;
    }

    const button = $('analyze-button');
    button.disabled = true;
    show('test-loading');
    try {
        const body = new FormData();
        if (file) body.append('image_file', file);
        else body.append('image_url', imageUrl);
        const data = await api('analyze_image', { body });
        if (!file) setPreview(imageUrl);
        renderResult(data);
    } catch (error) {
        setMessage('test-error', `Не удалось оценить изображение: ${error.message}`);
    } finally {
        hide('test-loading');
        button.disabled = false;
    }
}

export function initializeTestTab() {
    $('image-file').addEventListener('change', onFileChosen);
    $('image-url').addEventListener('input', onUrlInput);
    $('analyze-form').addEventListener('submit', analyzeTestImage);
}
