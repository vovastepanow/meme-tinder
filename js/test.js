// test.js — Вкладка «Тест ИИ»: оценка загруженного изображения или ссылки.
function showTestPreview(file) {
    const preview = document.getElementById('test-image');
    if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
    preview.dataset.objectUrl = file ? URL.createObjectURL(file) : '';
    if (file) {
        preview.src = preview.dataset.objectUrl;
        document.getElementById('image-url').value = '';
    } else {
        preview.removeAttribute('src');
    }
    document.getElementById('test-result').style.display = 'none';
}

async function analyzeTestImage(event) {
    event.preventDefault();
    const fileInput = document.getElementById('image-file');
    const urlInput = document.getElementById('image-url');
    const file = fileInput.files[0];
    const imageUrl = urlInput.value.trim();
    const error = document.getElementById('test-error');

    error.style.display = 'none';
    error.textContent = '';
    document.getElementById('test-result').style.display = 'none';
    if (!file && !imageUrl) {
        error.textContent = 'Добавьте ссылку или выберите файл изображения.';
        error.style.display = 'block';
        return;
    }
    if (file && file.size > 10 * 1024 * 1024) {
        error.textContent = 'Файл больше 10 МБ. Выберите изображение поменьше.';
        error.style.display = 'block';
        return;
    }

    const button = document.getElementById('analyze-button');
    button.disabled = true;
    document.getElementById('test-loading').style.display = 'block';
    try {
        const body = new FormData();
        if (file) body.append('image_file', file);
        else body.append('image_url', imageUrl);

        const response = await fetch(apiUrl('analyze_image'), { method: 'POST', body });
        const data = await response.json();
        logAiExchanges(data.ai_log);
        if (!response.ok) throw new Error(data.message || `HTTP ${response.status}`);

        if (!file) {
            const preview = document.getElementById('test-image');
            if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
            preview.dataset.objectUrl = '';
            preview.src = imageUrl;
        }
        document.getElementById('test-rating').textContent = `🔮 ${Number(data.rating)}%`;
        document.getElementById('test-model').textContent = `Оценка по истории лайков и дизлайков · ${data.model}`;
        document.getElementById('test-description').innerHTML = `<strong>ИИ описание:</strong> ${escapeHtml(data.description || 'Описание не получено')}`;
        document.getElementById('test-why-funny').textContent = data.why_funny ? `Почему смешно: ${data.why_funny}` : '';
        document.getElementById('test-is-funny').textContent = `Смешно? ${data.is_funny ? 'Да' : 'Нет'}`;
        document.getElementById('test-result').style.display = 'block';
    } catch (e) {
        error.textContent = `Не удалось оценить изображение: ${e.message}`;
        error.style.display = 'block';
    } finally {
        document.getElementById('test-loading').style.display = 'none';
        button.disabled = false;
    }
}
