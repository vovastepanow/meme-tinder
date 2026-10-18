<?php
// Telegram: публичные страницы каналов t.me/s/. Используется подбором источников и парсером.

function fetch_telegram_channel_page($channel) {
    if (!is_valid_telegram_channel($channel)) return false;
    return http_get('https://t.me/s/' . rawurlencode($channel), [
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MemeTinder/1.0)'
    ]);
}

function telegram_html_attribute($value) {
    return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** Картинки поста: фоновые изображения виджета и прямые ссылки на CDN Telegram. */
function telegram_post_images($content) {
    $urls = [];
    preg_match_all('~background-image:\s*url\([\'"]?(https://[^\'")\s]+)~i', $content, $matches);
    foreach (($matches[1] ?? []) as $url) $urls[] = telegram_html_attribute($url);
    preg_match_all('~href="(https://[^"]*(?:cdn-telegram\.org|telegram-cdn\.org)[^"]+\.(?:jpe?g|png|webp)(?:\?[^"]*)?)"~i', $content, $matches);
    foreach (($matches[1] ?? []) as $url) $urls[] = telegram_html_attribute($url);
    return array_values(array_unique($urls));
}

/**
 * Посты публичного канала с картинкой или видео (не больше $count).
 * У видео-поста 'video' — адрес файла, а 'image' — его постер; видео без постера пропускается.
 */
function telegram_channel_posts($source, $count) {
    $channel = ltrim((string)($source['id'] ?? ''), '@');
    $html = fetch_telegram_channel_page($channel);
    if (!$html) return [];

    preg_match_all('/data-post="([^"]+)"(.*?)(?=data-post="[^"]+"|$)/s', $html, $blocks, PREG_SET_ORDER);
    $posts = [];
    foreach ($blocks as $block) {
        if (count($posts) >= $count) break;
        $post_id = explode('/', $block[1], 2)[1] ?? '';
        if (!preg_match('/^[0-9]+$/', $post_id)) continue;
        $content = $block[2] ?? '';

        $text = '';
        if (preg_match('~<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>~si', $content, $text_match)) {
            $text = clean_html_text($text_match[1]);
        }

        $images = telegram_post_images($content);
        $video = preg_match('~<video[^>]*?\ssrc="([^"]+)"~i', $content, $video_match) ? telegram_html_attribute($video_match[1]) : '';
        if ($video !== '') {
            $poster = preg_match('~<video[^>]*?\sposter="([^"]+)"~i', $content, $poster_match) ? telegram_html_attribute($poster_match[1]) : '';
            if ($poster === '') $poster = $images[0] ?? '';
            if ($poster === '') continue; // без постера нечего показать и оценить
            $images = [$poster];
        }
        if (!$images) continue;

        $posts[] = [
            'image' => $images[0],
            'images' => array_slice($images, 0, 4),
            'video' => $video,
            'text' => $text ?: EMPTY_POST_TEXT,
            'source' => $source['name'] ?? source_display_name(PLATFORM_TELEGRAM, $channel),
            'post_id' => $post_id,
            'url' => 'https://t.me/' . rawurlencode($channel) . '/' . rawurlencode($post_id)
        ];
    }
    return $posts;
}

function build_telegram_preview_posts($source, $count = 4) {
    return telegram_channel_posts($source, $count);
}
