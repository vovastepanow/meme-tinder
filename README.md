# Meme Tinder

Сайт, который собирает мемы из Двача, Telegram и ВКонтакте и оценивает их одной локальной vision-моделью через Ollama.

## Требования

- PHP 8.1+ с curl и json, веб-сервер (Apache2) с PHP.
- Ollama на том же сервере (по умолчанию `http://localhost:11434`, модель `qwen3.5-vision:latest`).
- Для видео: `ffmpeg` и `ffprobe` в PATH. Без них видео оцениваются по превью.

## Настройка

1. Скопируйте `config.local.example.php` в `config.local.php` и заполните VK-значения (файл не попадает в git).
2. Для входа через VK создайте приложение VK ID. В Redirect URI укажите
   `https://ВАШ_ДОМЕН/index.php?action=vk_oauth_callback` (VK принимает только HTTPS).
3. Данные (`memes_pool.json`, `meme_history.json`, `sources_db.json`) создаются приложением сами. В git их нет.

## Структура

- `index.php` — JSON API: только маршрутизация по `?action=...`.
- `handlers/` — обработчики API: `memes.php`, `sources.php`, `database.php`.
- `lib/` — серверная логика API: демо-режим, каталог источников, запуск парсера, редактор баз, VK OAuth, ответы.
- `cron_parser.php` — фоновый сборщик, запускается из `index.php` через nohup.
- `parser/` — сбор для парсера: Двач, Telegram, видео (ffmpeg), кандидаты и пул.
- `sources/` — адаптеры источников для вкладки подбора и для парсера: `common.php`, `dvach.php`, `telegram.php`, `vk.php`.
- `meme_analysis.php` — промпт и разбор ответа vision-модели, пороги отбора.
- `http_client.php` — HTTP и вызовы Ollama с логом размышлений.
- `project_storage.php`, `image_input.php` — чтение и атомарная запись JSON, проверка изображений.
- `index.html`, `js/`, `css/` — интерфейс: `core.js` (общее и журнал), `memes.js`, `sources.js`, `database.js`, `test.js`, `image-viewer.js`, `main.js`.

Скрипты в `js/` подключаются обычными тегами `<script>` в порядке из `index.html`, поэтому обработчики в `onclick` работают без сборки.

## Журнал и отладка

Журнал парсера пишется в `.meme-tinder-vision2-<hash>.log` (рядом с `index.php`). Его показывает консоль на сайте. Демо-режим: `index.html?debug=1`.
