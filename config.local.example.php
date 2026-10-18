<?php

// Скопируйте этот файл в config.local.php и заполните значения на сервере.
// config.local.php не попадает в git, поэтому секреты не уйдут в репозиторий.
return [
    // ID приложения из кабинета VK ID.
    'VK_CLIENT_ID' => '',
    // Должен совпадать с Redirect URI в настройках приложения VK ID, например:
    // 'https://ВАШ_ДОМЕН/index.php?action=vk_oauth_callback'
    'VK_REDIRECT_URI' => '',
    // Необязательно.
    'VK_SERVICE_TOKEN' => '',
    // Необязательно: права, которые просит приложение.
    'VK_OAUTH_SCOPE' => '',
];
