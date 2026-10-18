<?php
// Ссылки на исходные посты.
function source_post_url($meme) {
    $existing_url = parse_url($meme['source_url'] ?? '');
    if (($existing_url['scheme'] ?? '') === 'https' && in_array(strtolower($existing_url['host'] ?? ''), ['2ch.hk', 't.me', 'vk.com'], true)) {
        return $meme['source_url'];
    }

    $board = (string)($meme['board'] ?? '');
    if ($board === '' && preg_match('~Двач\s+/([a-z0-9_-]+)/~iu', (string)($meme['source'] ?? ''), $match)) {
        $board = $match[1];
    }
    $thread_id = (string)($meme['thread_id'] ?? '');
    $post_id = (string)($meme['post_id'] ?? '');
    if (!is_valid_dvach_board($board) || !is_valid_dvach_id($thread_id) || !is_valid_dvach_id($post_id)) {
        return '';
    }
    return dvach_post_url($board, $thread_id, $post_id);
}
