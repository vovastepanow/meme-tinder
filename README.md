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

- `index.php` — JSON API: таблица маршрутов `?action=...` (метод, обработчик), ответы 404/405 и перехват ошибок.
- `bootstrap.php` — общие модули для API и парсера.
- `handlers/` — обработчики API: `memes.php`, `sources.php`, `database.php`.
- `lib/` — серверная логика API: пул и история (`memes.php`), демо-режим, каталог источников, запуск парсера, редактор баз, VK OAuth, ответы.
- `cron_parser.php` — точка входа фонового сборщика (только CLI), запускается из `index.php` через nohup.
- `parser/` — сборщик (`collector.php`), Двач и Telegram для парсера, видео (ffmpeg), кандидаты и запись в пул.
- `sources/` — адаптеры источников, общие для подбора и парсера: `common.php`, `dvach.php`, `telegram.php`, `vk.php`.
- `meme_analysis.php` — промпт и разбор ответа vision-модели, пороги отбора.
- `http_client.php` — HTTP и вызовы Ollama с логом размышлений.
- `project_storage.php` — чтение, атомарная запись и изменение JSON под блокировкой (`update_data`).
- `image_input.php` — проверка изображений и безопасная загрузка по ссылке.
- `index.html`, `js/`, `css/` — интерфейс на ES-модулях без сборки: `main.js` (точка входа), `core.js` (API, журнал, вкладки), `memes.js`, `sources.js`, `database.js`, `test.js`, `image-viewer.js`.

## Безопасность и согласованность данных

- `.htaccess` закрывает доступ к JSON-базам, журналам, служебным PHP-файлам и каталогам `lib/`, `handlers/`, `parser/`, `sources/`. Нужен `AllowOverride All` для каталога сайта.
- Все POST-запросы API требуют заголовок `X-Meme-Tinder: 1` (его ставит `js/core.js`) — защита от CSRF.
- Голос за мем передаёт только адрес картинки; данные для истории сервер берёт из пула.
- Пул, история и каталог источников изменяются только через `update_data()` / `with_file_lock()`, поэтому голоса, редактор баз и парсер не затирают изменения друг друга.
- Редактор баз передаёт прежнее значение записи; если запись успела измениться, сервер отвечает 409.

## Журнал и отладка

Журнал парсера пишется в `.meme-tinder-vision2-<hash>.log` (рядом с `index.php`). Его показывает консоль на сайте. Демо-режим: `index.html?debug=1`.
