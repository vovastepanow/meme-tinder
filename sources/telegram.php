<?php
// Telegram: публичные страницы каналов t.me/s/. Используется подбором источников и парсером.

function fetch_telegram_channel_page($channel) {
    if (!is_valid_telegram_channel($channel)) return false;
    return http_get('https://t.me/s/' . rawurlencode($channel), [
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ContentTinder/1.0)'
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
    $html = fetch_telegram_channel_page(ltrim((string)($source['id'] ?? ''), '@'));
    return $html ? telegram_posts_from_html($html, $source, $count) : [];
}

/** Время публикации поста из его разметки (<time datetime="...">), unix-время или 0. */
function telegram_post_time($content) {
    if (!preg_match('~<time[^>]*\sdatetime="([^"]+)"~i', $content, $match)) return 0;
    $time = strtotime(telegram_html_attribute($match[1]));
    return $time === false ? 0 : $time;
}

/** Время всех постов на странице канала (в том числе без картинок) — для оценки активности. */
function telegram_post_times($html) {
    preg_match_all('/data-post="[^"]+"(.*?)(?=data-post="[^"]+"|$)/s', (string)$html, $blocks);
    return array_values(array_filter(array_map('telegram_post_time', $blocks[1] ?? [])));
}

/**
 * Посты из уже загруженной страницы канала (см. telegram_channel_posts), самые свежие первыми:
 * страница t.me/s/ показывает посты от старых к новым, поэтому блоки перебираются с конца.
 */
function telegram_posts_from_html($html, $source, $count) {
    $channel = ltrim((string)($source['id'] ?? ''), '@');

    preg_match_all('/data-post="([^"]+)"(.*?)(?=data-post="[^"]+"|$)/s', $html, $blocks, PREG_SET_ORDER);
    $posts = [];
    foreach (array_reverse($blocks) as $block) {
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
            'posted_at' => telegram_post_time($content),
            'url' => 'https://t.me/' . rawurlencode($channel) . '/' . rawurlencode($post_id)
        ];
    }
    return $posts;
}

function build_telegram_preview_posts($source, $count = 4) {
    return telegram_channel_posts($source, $count);
}

// ---- Автоподбор: сведения о канале и ссылки на другие каналы ----

/** Служебные пути t.me, которые не являются каналами. */
const TELEGRAM_RESERVED_PATHS = ['joinchat', 'addlist', 'addstickers', 'addemoji', 'addtheme', 'setlanguage', 'share', 'proxy', 'socks', 'boost', 'contact', 'login', 'premium', 'username', 'telegram', 'durov', 'iv'];

/** Число подписчиков из вида «12.3K» / «1.2M» / «845». */
function telegram_parse_counter($text) {
    if (!preg_match('/([\d.,]+)\s*([KkMm]?)/', str_replace(' ', '', (string)$text), $match)) return 0;
    $value = (float)str_replace(',', '.', $match[1]);
    $multiplier = ['k' => 1000, 'm' => 1000000][strtolower($match[2])] ?? 1;
    return (int)round($value * $multiplier);
}

/**
 * Сведения о публичном канале по странице t.me/s/<канал>: название, описание, подписчики и посты.
 * Возвращает null, если канала нет, он закрыт или это не канал (у таких страниц нет ленты постов).
 */
function telegram_channel_info($channel, $post_count = 6) {
    $channel = ltrim(trim((string)$channel), '@');
    $html = fetch_telegram_channel_page($channel);
    if (!$html || strpos($html, 'data-post="') === false) return null;

    $title = preg_match('~class="tgme_channel_info_header_title"[^>]*>(.*?)</div>~si', $html, $m) ? clean_html_text($m[1]) : '';
    $description = preg_match('~class="tgme_channel_info_description"[^>]*>(.*?)</div>~si', $html, $m) ? clean_html_text($m[1]) : '';
    $subscribers = 0;
    if (preg_match('~<span class="counter_value">([^<]+)</span>\s*<span class="counter_type">subscribers?</span>~i', $html, $m)) {
        $subscribers = telegram_parse_counter($m[1]);
    }
    return [
        'id' => $channel,
        'title' => $title ?: $channel,
        'description' => mb_substr($description, 0, 500),
        'subscribers' => $subscribers,
        'posts' => telegram_posts_from_html($html, ['id' => $channel, 'name' => source_display_name(PLATFORM_TELEGRAM, $title ?: $channel)], $post_count),
        'post_times' => telegram_post_times($html),
        'linked' => telegram_linked_channels($html, $channel)
    ];
}

/** Каналы, на которые ссылаются посты страницы: репосты («Forwarded from») и ссылки t.me/<канал>. */
function telegram_linked_channels($html, $own_channel = '') {
    preg_match_all('~https?://t\.me/(?:s/)?([A-Za-z][A-Za-z0-9_]{4,31})(?=[/?"#\'<\s]|$)~', (string)$html, $matches);
    $found = [];
    foreach ($matches[1] ?? [] as $handle) {
        $key = strtolower($handle);
        if ($key === strtolower($own_channel) || in_array($key, TELEGRAM_RESERVED_PATHS, true) || str_ends_with($key, 'bot')) continue;
        $found[$key] = $handle;
    }
    return array_values($found);
}
