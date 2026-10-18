<?php
// Настройки приложения (settings.json): тема поиска контента.

function default_settings() {
    return ['topic' => DEFAULT_TOPIC];
}

function normalize_topic($topic) {
    $topic = trim(preg_replace('/\s+/u', ' ', (string)$topic) ?? '');
    return mb_substr($topic, 0, TOPIC_MAX_LENGTH);
}

function load_settings() {
    $settings = array_merge(default_settings(), load_data(SETTINGS_FILE, []));
    $settings['topic'] = normalize_topic($settings['topic']) ?: DEFAULT_TOPIC;
    return $settings;
}

function current_topic() {
    return load_settings()['topic'];
}

/** Сохраняет тему поиска. Возвращает итоговые настройки. */
function save_topic($topic) {
    $topic = normalize_topic($topic);
    if ($topic === '') throw new InvalidArgumentException('Тема поиска не может быть пустой.');
    return update_data(SETTINGS_FILE, default_settings(), function ($settings) use ($topic) {
        $settings['topic'] = $topic;
        return $settings;
    });
}
