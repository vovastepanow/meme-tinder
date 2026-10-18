<?php
// Telegram: публичные страницы каналов t.me/s/.

function fetch_telegram_channel_page($channel) {
    if (!is_valid_telegram_channel($channel)) return false;
    return http_get('https://t.me/s/' . rawurlencode($channel), [
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; MemeTinder/1.0)'
    ]);
}

function build_telegram_preview_posts($source, $count = 4) {
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
        $images = [];
        preg_match_all('~background-image:\s*url\([\'"]?(https://[^\'")\s]+)~i', $content, $image_matches);
        foreach (($image_matches[1] ?? []) as $url) {
            $images[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if (!$images) {
            preg_match_all('~href="(https://[^\"]*(?:cdn-telegram\.org|telegram-cdn\.org)[^\"]+\.(?:jpe?g|png|webp)(?:\?[^\"]*)?)"~i', $content, $image_matches);
            foreach (($image_matches[1] ?? []) as $url) {
                $images[] = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $images = array_values(array_unique($images));
        if (!$images) continue;
        $text = '';
        if (preg_match('~<div class="tgme_widget_message_text[^\"]*"[^>]*>(.*?)</div>~si', $content, $text_match)) {
            $text = clean_html_text($text_match[1]);
        }
        $posts[] = [
            'image' => $images[0],
            'images' => array_slice($images, 0, 4),
            'text' => $text ?: 'Пост без текста',
            'source' => $source['name'] ?? ('Telegram | ' . $channel),
            'post_id' => $post_id,
            'url' => 'https://t.me/' . rawurlencode($channel) . '/' . rawurlencode($post_id)
        ];
    }
    return $posts;
}
