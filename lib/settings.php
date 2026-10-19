<?php
// Настройки пользователя (settings.json): тема поиска контента и фильтры подбора.
//  - fresh_only — «Только новый контент»: посты за последние сутки (CONTENT_FRESH_MAX_AGE_DAYS; иначе CONTENT_MAX_AGE_DAYS,
//    у Двача DVACH_MAX_AGE_DAYS);
//  - active_sources_only — «Только актуальные источники»: только живые паблики, каналы и треды. Замороженные (редко
//    публикующие) каналы и паблики пропускаются, автоподбор не берёт неактивные, с Двача берутся только треды, в которые
//    писали за последние DVACH_THREAD_ALIVE_HOURS часов. Выключено — посты собираются и из неактивных;
//  - video_only — «Только с видео»: в подбор идут посты, в которых есть хотя бы одно видео (не обязательно все файлы).

function default_settings() {
    return ['topic' => DEFAULT_TOPIC, 'topic2' => '', 'fresh_only' => false, 'active_sources_only' => true, 'video_only' => false];
}

function normalize_topic($topic) {
    $topic = trim(preg_replace('/\s+/u', ' ', (string)$topic) ?? '');
    return mb_substr($topic, 0, TOPIC_MAX_LENGTH);
}

function load_settings() {
    $settings = array_merge(default_settings(), load_data(settings_file(), []));
    $settings['topic'] = normalize_topic($settings['topic']) ?: DEFAULT_TOPIC;
    $settings['topic2'] = normalize_topic($settings['topic2'] ?? '');
    if (topic_norm($settings['topic2']) === topic_norm($settings['topic'])) $settings['topic2'] = '';
    $settings['fresh_only'] = (bool)$settings['fresh_only'];
    $settings['active_sources_only'] = (bool)$settings['active_sources_only'];
    $settings['video_only'] = (bool)$settings['video_only'];
    return $settings;
}

/** Тема пользователя — каноническим названием из реестра тем («мемы и юмор» → «Мемы и юмор»), если она там есть. */
function current_topic() {
    $topic = load_settings()['topic'];
    $entry = function_exists('topic_lookup') ? topic_lookup($topic) : null;
    return $entry['title'] ?? $topic;
}

/** Вторая тема (пусто — её нет): контент ищется по обеим, то есть запись должна подходить под обе. Каноническое название. */
function current_topic2() {
    $topic = load_settings()['topic2'];
    $entry = $topic !== '' && function_exists('topic_lookup') ? topic_lookup($topic) : null;
    return $entry['title'] ?? $topic;
}

/**
 * Ключ поиска для оценок, пула и кэша: одна тема — её название, две — «первая + вторая» (см. topic_parts в lib/topics.php).
 * Источники при этом остаются от первой темы.
 */
function search_topic_key() {
    $second = current_topic2();
    return $second === '' ? current_topic() : current_topic() . ' + ' . $second;
}

/** Сохраняет вторую тему (пусто — убрать). Возвращает итоговые настройки. */
function save_second_topic($topic2) {
    $topic2 = normalize_topic($topic2);
    return update_data(settings_file(), default_settings(), function ($settings) use ($topic2) {
        $settings['topic2'] = $topic2;
        return $settings;
    });
}

/** Сохраняет тему поиска. Возвращает итоговые настройки. */
function save_topic($topic) {
    $topic = normalize_topic($topic);
    if ($topic === '') throw new InvalidArgumentException('Тема поиска не может быть пустой.');
    return update_data(settings_file(), default_settings(), function ($settings) use ($topic) {
        $settings['topic'] = $topic;
        return $settings;
    });
}

/** Фильтры подбора пользователя: ['fresh_only' => bool, 'active_sources_only' => bool, 'video_only' => bool]. */
function content_filters() {
    $settings = load_settings();
    return ['fresh_only' => $settings['fresh_only'], 'active_sources_only' => $settings['active_sources_only'], 'video_only' => $settings['video_only']];
}

/** Сохраняет фильтры подбора (только переданные). Возвращает итоговые фильтры. */
function save_content_filters(array $filters) {
    update_data(settings_file(), default_settings(), function ($settings) use ($filters) {
        $settings = array_merge(default_settings(), is_array($settings) ? $settings : []);
        foreach (['fresh_only', 'active_sources_only', 'video_only'] as $key) {
            if (array_key_exists($key, $filters)) $settings[$key] = filter_var($filters[$key], FILTER_VALIDATE_BOOLEAN);
        }
        return $settings;
    });
    return content_filters();
}

/** Сколько дней пост считается подходящим по свежести: с учётом «Только новый контент». */
function content_max_age_days($dvach = false) {
    if (content_filters()['fresh_only']) return CONTENT_FRESH_MAX_AGE_DAYS;
    return $dvach ? DVACH_MAX_AGE_DAYS : CONTENT_MAX_AGE_DAYS;
}
