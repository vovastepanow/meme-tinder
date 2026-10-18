<?php
// Общие функции источников: названия площадок, очистка текста, проверка идентификаторов, выбор адаптера.

const PLATFORM_DVACH = 'Двач';
const PLATFORM_TELEGRAM = 'Telegram';
const PLATFORM_VK = 'ВКонтакте';

/** Префиксы в поле source, по которым площадка определяется у сохранённых мемов. */
const SOURCE_NAME_PREFIXES = [
    PLATFORM_TELEGRAM => 'Telegram | ',
    PLATFORM_VK => 'ВКонтакте | '
];

/** Хосты, на которые разрешено ссылаться как на исходный пост. */
const TRUSTED_SOURCE_HOSTS = ['2ch.hk', 't.me', 'vk.com'];

const EMPTY_POST_TEXT = 'Пост без текста';

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

function is_trusted_source_url($url) {
    $parts = parse_url((string)$url);
    return is_array($parts)
        && ($parts['scheme'] ?? '') === 'https'
        && in_array(strtolower($parts['host'] ?? ''), TRUSTED_SOURCE_HOSTS, true);
}

function dvach_post_url($board, $thread_id, $post_id) {
    return 'https://2ch.hk/' . rawurlencode($board) . '/res/' . rawurlencode($thread_id) . '.html#' . rawurlencode($post_id);
}

/** Отображаемое имя источника: «Telegram | Название». */
function source_display_name($platform, $name) {
    return (SOURCE_NAME_PREFIXES[$platform] ?? '') . $name;
}

/** Площадка мема по полю source (у старых записей другого признака нет). */
function meme_platform($meme) {
    $source = (string)($meme['source'] ?? '');
    foreach (SOURCE_NAME_PREFIXES as $platform => $prefix) {
        if (str_starts_with($source, $prefix)) return $platform;
    }
    return PLATFORM_DVACH;
}

function build_source_preview_posts($source, $count = 4) {
    switch ($source['platform'] ?? '') {
        case PLATFORM_TELEGRAM: return build_telegram_preview_posts($source, $count);
        case PLATFORM_VK: return build_vk_preview_posts($source, $count);
        case PLATFORM_DVACH: return build_dvach_preview_posts($source, $count);
        default: return [];
    }
}
