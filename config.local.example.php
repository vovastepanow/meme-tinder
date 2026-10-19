<?php
// Скопируйте в config.local.php (не попадает в git) и заполните на сервере.
return [
    'VK_CLIENT_ID' => '',        // ID приложения из кабинета VK ID
    'VK_REDIRECT_URI' => '',     // как в настройках VK ID: https://ВАШ_ДОМЕН/index.php?action=vk_oauth_callback
    'VK_SERVICE_TOKEN' => '',    // необязательно
    'VK_OAUTH_SCOPE' => '',      // необязательно
];
