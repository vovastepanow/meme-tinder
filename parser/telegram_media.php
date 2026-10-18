<?php
// Telegram для фонового парсера: посты с картинками и видео публичных каналов.

function telegram_background_images($content) {
    preg_match_all('~background-image:\s*url\([\'"]?(https://[^\'")\s]+)~i', $content, $matches);
    $urls = [];
    foreach (($matches[1] ?? []) as $url) {
        $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    preg_match_all('~href="(https://[^"]*(?:cdn-telegram\.org|telegram-cdn\.org)[^"]+\.(?:jpe?g|png|webp)(?:\?[^"]*)?)"~i', $content, $matches);
    foreach (($matches[1] ?? []) as $url) {
        $urls[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return array_values(array_unique($urls));
}

/** Public channel posts with a picture or a video. A video post gets 'video' (file URL) and a poster as 'image'. */
function telegram_media_posts($source, $count = TELEGRAM_POSTS_PER_CHANNEL) {
    $channel = ltrim((string)($source['id'] ?? ''), '@');
    $html = fetch_telegram_channel_page($channel);
    if (!$html) return [];

    preg_match_all('/data-post="([^"]+)"(.*?)(?=data-post="[^"]+"|$)/s', $html, $blocks, PREG_SET_ORDER);
    $posts = [];
    foreach ($blocks as $block) {
        if (count($posts) >= $count) break;
        $parts = explode('/', $block[1], 2);
        $post_id = $parts[1] ?? '';
        if (!preg_match('/^[0-9]+$/', $post_id)) continue;
        $content = $block[2] ?? '';

        $text = '';
        if (preg_match('~<div class="tgme_widget_message_text[^"]*"[^>]*>(.*?)</div>~si', $content, $text_match)) {
            $text = clean_html_text($text_match[1]);
        }

        $images = telegram_background_images($content);
        $video = '';
        if (preg_match('~<video[^>]*?\ssrc="([^"]+)"~i', $content, $video_match)) {
            $video = html_entity_decode($video_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($video !== '') {
            $poster = '';
            if (preg_match('~<video[^>]*?\sposter="([^"]+)"~i', $content, $poster_match)) {
                $poster = html_entity_decode($poster_match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            if ($poster === '' && $images) $poster = $images[0];
            if ($poster === '') continue; // without a poster there is nothing to show or vote on
            $images = [$poster];
        }
        if (!$images) continue;

        $posts[] = [
            'image' => $images[0],
            'images' => array_slice($images, 0, 4),
            'video' => $video,
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'] ?? ('Telegram | ' . $channel),
            'post_id' => $post_id,
            'url' => 'https://t.me/' . rawurlencode($channel) . '/' . rawurlencode($post_id)
        ];
    }
    return $posts;
}
