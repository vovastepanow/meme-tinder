<?php
// Общие функции источников: очистка текста, проверка идентификаторов, выбор адаптера по платформе.

function clean_html_text($raw) {
    $text = html_entity_decode(strip_tags((string)$raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
}

function is_valid_dvach_board($board) {
    return preg_match('/^[a-z0-9_-]{1,30}$/i', (string)$board) === 1;
}

function is_valid_dvach_id($id) {
    return preg_match('/^[0-9]{1,20}$/', (string)$id) === 1;
}

function is_valid_telegram_channel($channel) {
    return preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', (string)$channel) === 1;
}

function is_valid_vk_domain($domain) {
    return preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string)$domain) === 1;
}

function dvach_post_url($board, $thread_id, $post_id) {
    return 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.html#' . rawurlencode($post_id);
}

function build_source_preview_posts($source, $count = 4) {
    $platform = $source['platform'] ?? '';
    if ($platform === 'Telegram') return build_telegram_preview_posts($source, $count);
    if ($platform === 'ВКонтакте') return build_vk_preview_posts($source, $count);
    if ($platform === 'Двач') return build_dvach_preview_posts($source, $count);
    return [];
}
