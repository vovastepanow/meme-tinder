<?php
// Демо-данные для режима ?debug=1 (без Ollama и без записи в файлы проекта).
// ---- Demo mode and default data ----

function debug_image($title, $background, $accent) {
    $title = htmlspecialchars($title, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 480"><rect width="640" height="480" fill="' . $background . '"/><circle cx="510" cy="90" r="145" fill="' . $accent . '"/><path d="M0 370 Q160 280 320 370 T640 340 V480 H0Z" fill="#171717"/><text x="42" y="190" fill="#fff" font-family="sans-serif" font-size="42" font-weight="700">' . $title . '</text><text x="44" y="245" fill="#fff" font-family="sans-serif" font-size="22">ТЕСТОВЫЙ МЕМ</text></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

function debug_initial_state() {
    return [
        'pool' => [
            ['image' => debug_image('ПОНЕДЕЛЬНИК', '#176b87', '#e95d3f'), 'text' => 'План на утро: стать продуктивным. Факт: уже обед.', 'ai_description' => 'Человек строит амбициозные планы, пока время незаметно уходит.', 'rating' => 91, 'source' => 'Демо-сообщество'],
            ['image' => debug_image('ОЖИДАНИЕ / РЕАЛЬНОСТЬ', '#58734b', '#f1c453'), 'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.', 'ai_description' => 'Шутка о привычке отвлекаться в интернете.', 'rating' => 76, 'source' => 'Локальный тест']
        ],
        'history' => empty_history(),
        'source_index' => 0,
        'source_votes' => []
    ];
}

function create_demo_pool() {
    return [
        [
            'image' => 'https://images.unsplash.com/photo-1517849845537-4d257902454a?auto=format&fit=crop&w=1200&q=80',
            'text' => 'План на утро: стать продуктивным. Факт: уже обед.',
            'ai_description' => 'Шутка про оптимистичные обещания и реальную жизнь, где время уходит быстрее, чем кажется.',
            'rating' => 92,
            'source' => 'Демо-сообщество'
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1493246507139-91e8fad9978e?auto=format&fit=crop&w=1200&q=80',
            'text' => 'Открыл одну вкладку. Через час их уже двадцать семь.',
            'ai_description' => 'Мем про бесконечную прокрутку и неспособность удержать фокус.',
            'rating' => 84,
            'source' => 'Локальный тест'
        ],
        [
            'image' => 'https://images.unsplash.com/photo-1521572267360-ee0c2909d518?auto=format&fit=crop&w=1200&q=80',
            'text' => 'Когда код работает с первого раза, а потом ты всё равно проверяешь его ещё раз.',
            'ai_description' => 'Реалистичная ирония про разработчика, который не может доверять счастью без перепроверки.',
            'rating' => 88,
            'source' => 'Демо-мемы'
        ]
    ];
}
