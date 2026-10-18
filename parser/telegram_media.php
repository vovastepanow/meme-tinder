<?php
// Telegram для фонового парсера: посты с картинками и видео публичных каналов.
// Разбор страницы канала общий с подбором источников — см. telegram_channel_posts() в sources/telegram.php.

function telegram_media_posts($source, $count = TELEGRAM_POSTS_PER_CHANNEL) {
    return telegram_channel_posts($source, $count);
}
