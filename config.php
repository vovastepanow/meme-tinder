<?php

const POOL_FILE = __DIR__ . '/memes_pool.json';
const HISTORY_FILE = __DIR__ . '/meme_history.json';
const SOURCES_FILE = __DIR__ . '/sources_db.json';
const PARSER_SCRIPT = __DIR__ . '/cron_parser.php';

const OLLAMA_GENERATE_URL = 'http://localhost:11434/api/generate';
const TEXT_MODEL = 'qwen2.5:3b';
const VISION_MODEL = 'moondream';

const HISTORY_COLLECTIONS = ['liked_memes', 'disliked_memes', 'undecided_memes', 'wrong_topic_memes'];
const MAX_IMAGE_BYTES = 10 * 1024 * 1024;

function empty_history() {
    return array_fill_keys(HISTORY_COLLECTIONS, []);
}

function parser_runtime_file($suffix) {
    return __DIR__ . '/.meme-tinder-' . substr(hash('sha256', __DIR__), 0, 16) . $suffix;
}
