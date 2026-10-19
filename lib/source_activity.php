<?php
// Свежесть контента и активность источников: когда был последний пост, сколько постов в неделю,
// заморозка редко обновляемых каналов и пабликов и их периодическая перепроверка.
//
// Поля записи каталога (telegram_channels / vk_publics):
//   activity     — ['last_post' => unix, 'per_week' => число, 'checked_at' => unix]
//   frozen_until — до какого времени источник заморожен (парсер его пропускает); нет поля — активен.

const DAY_SECONDS = 86400;

/** Пост слишком старый, чтобы предлагать его пользователю. Посты без даты не отбрасываются. */
function is_post_outdated($posted_at, $now = null, $max_days = CONTENT_MAX_AGE_DAYS) {
    $posted_at = (int)$posted_at;
    return $posted_at > 0 && ($now ?? time()) - $posted_at > $max_days * DAY_SECONDS;
}

/**
 * Активность по времени постов: последний пост и средняя частота (постов в неделю) за период
 * от самого раннего поста до текущего момента — так затишье в конце тоже снижает частоту.
 * Возвращает null, если дат нет совсем.
 */
function source_activity(array $post_times, $now = null) {
    $now = $now ?? time();
    $times = array_values(array_filter(array_map('intval', $post_times), function ($time) use ($now) {
        return $time > 0 && $time <= $now + DAY_SECONDS;
    }));
    if (!$times) return null;
    $span_days = max(1.0, ($now - min($times)) / DAY_SECONDS);
    return [
        'last_post' => max($times),
        'per_week' => round(count($times) / $span_days * 7, 1),
        'checked_at' => $now
    ];
}

/** Причина считать источник неактивным или null, если он активен. */
function source_inactivity_reason($activity, $now = null) {
    if ($activity === null) return 'нет постов с датой';
    $now = $now ?? time();
    $days = (int)floor(($now - $activity['last_post']) / DAY_SECONDS);
    if ($days > SOURCE_STALE_DAYS) return "последний пост {$days} дн назад";
    if ($activity['per_week'] < SOURCE_MIN_POSTS_PER_WEEK) return "редко публикует: {$activity['per_week']} постов в неделю";
    return null;
}

function describe_source_activity($activity, $now = null) {
    if ($activity === null) return 'дат постов нет';
    $now = $now ?? time();
    $hours = (int)floor(($now - $activity['last_post']) / 3600);
    $last = $hours < 1 ? 'меньше часа назад' : ($hours < 48 ? "{$hours} ч назад" : (int)floor($hours / 24) . ' дн назад');
    return "последний пост {$last}, ~{$activity['per_week']} в неделю";
}

/** Заморожен ли источник сейчас (время перепроверки ещё не наступило). */
function is_source_frozen(array $entry, $now = null) {
    return (int)($entry['frozen_until'] ?? 0) > ($now ?? time());
}

/**
 * Обновляет запись каталога по свежей активности: замораживает неактивный источник на
 * SOURCE_FROZEN_RECHECK_DAYS дней или размораживает ожившего. $extra_reason — дополнительная причина заморозки
 * (мёртвая аудитория, см. source_dead_audience_reason). Возвращает [запись, событие],
 * где событие — 'frozen', 'still_frozen', 'revived' или null.
 */
function apply_source_activity(array $entry, $activity, $now = null, $extra_reason = null) {
    $now = $now ?? time();
    $was_frozen = isset($entry['frozen_until']);
    if ($activity !== null) $entry['activity'] = $activity;
    $reason = $extra_reason ?? source_inactivity_reason($activity, $now);   // extra_reason — конкретнее: канала нет, мёртвая аудитория
    if ($reason !== null) {
        $entry['frozen_until'] = $now + SOURCE_FROZEN_RECHECK_DAYS * DAY_SECONDS;
        $entry['frozen_reason'] = $reason;
        return [$entry, $was_frozen ? 'still_frozen' : 'frozen'];
    }
    unset($entry['frozen_until'], $entry['frozen_reason']);
    return [$entry, $was_frozen ? 'revived' : null];
}

/** Записывает обновлённые записи каталога (ключ — "telegram_channels:id" / "vk_publics:id") одной операцией под блокировкой. */
function save_catalog_entry_updates(array $updates) {
    if (!$updates) return;
    update_data(SOURCES_FILE, create_default_source_catalog(), function ($db) use ($updates) {
        $db = normalize_source_db($db);
        foreach (['telegram_channels', 'vk_publics'] as $key) {
            foreach ($db[$key] as $index => $entry) {
                $update_key = $key . ':' . strtolower((string)($entry['id'] ?? ''));
                if (isset($updates[$update_key])) $db[$key][$index] = $updates[$update_key];
            }
        }
        return $db;
    });
}
