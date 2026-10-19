<?php
// Демо-режим ?debug=1: состояние живёт в PHP-сессии, Ollama не вызывается, файлы проекта не меняются.

const DEBUG_SESSION_KEY = 'postmaker_debug';

/** Состояние демо-режима в сессии (по ссылке). Создаётся при первом обращении. */
function &debug_state() {
    ensure_session_started();
    if (!isset($_SESSION[DEBUG_SESSION_KEY]) || !is_array($_SESSION[DEBUG_SESSION_KEY])) {
        $_SESSION[DEBUG_SESSION_KEY] = debug_initial_state();
    }
    return $_SESSION[DEBUG_SESSION_KEY];
}

function debug_reset_state() {
    ensure_session_started();
    $_SESSION[DEBUG_SESSION_KEY] = debug_initial_state();
}

function debug_image($title, $background, $accent) {
    $title = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><rect width="640" height="480" fill="' . $background . '"/><circle cx="510" cy="90" r="145" fill="' . $accent . '"/><path d="M0 370 Q160 280 320 370 T640 340 V480 H0Z" fill="#111"/><text x="42" y="190" fill="#fff" font-family="sans-serif" font-size="42" font-weight="700">' . $title . '</text><text x="44" y="245" fill="#fff" font-family="sans-serif" font-size="22">ТЕСТОВАЯ ЗАПИСЬ</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function debug_initial_state() {
    return [
        'pool' => [
            ['image' => debug_image('ПОНЕДЕЛЬНИК', '#2b2b2f', '#5a5a62'), 'text' => 'План на утро: стать продуктивным. Факт: уже обед.', 'description' => 'Человек строит амбициозные планы, пока время незаметно уходит.', 'why_relevant' => 'Узнаваемая шутка про планы и реальность.', 'relevance' => 88, 'rating' => 91, 'topic' => DEFAULT_TOPIC, 'source' => 'Демо-сообщество'],
            ['image' => debug_image('ОЖИДАНИЕ / РЕАЛЬНОСТЬ', '#3a3a40', '#77777f'), 'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.', 'description' => 'Шутка о привычке отвлекаться в интернете.', 'why_relevant' => 'Юмор про бытовую ситуацию.', 'relevance' => 72, 'rating' => 76, 'topic' => DEFAULT_TOPIC, 'source' => 'Локальный тест']
        ],
        'history' => empty_history(),
        'source_votes' => [],
        'topic' => DEFAULT_TOPIC
    ];
}
