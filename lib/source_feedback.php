<?php
// Обратная связь по источникам: лайки и дизлайки всех пользователей показывают, какие каналы, паблики и доски
// дают контент по теме на самом деле. Рейтинг источника = доля лайков среди оценок (байесовское сглаживание),
// он смешивается с долей постов, прошедших быструю оценку. Стабильно плохие источники отключаются сами.

const SOURCE_FEEDBACK_MIN_VOTES = 3;      // с этого числа оценок мнение пользователей влияет на порядок
const SOURCE_FEEDBACK_BAN_VOTES = 8;      // с этого числа оценок источник с плохой долей лайков отключается
const SOURCE_FEEDBACK_BAN_SCORE = 0.2;

/**
 * Оценки пользователей по источникам для темы: [ключ источника => ['likes', 'dislikes', 'score', 'votes']].
 * Читает истории всех пользователей; учитываются записи по этой теме и по равнозначным ей.
 * Жалоба «ошибка подбора» считается дизлайком, «Не знаю» не учитывается.
 */
function source_feedback_stats($topic) {
    $stats = [];
    if (!is_dir(USERS_DIR)) return $stats;
    foreach (user_ids() as $uid) {
        $history = load_data(USERS_DIR . '/' . $uid . '/history.json', []);
        foreach (['liked' => 1, 'disliked' => 0, 'complaints' => 0] as $collection => $positive) {
            foreach ($history[$collection] ?? [] as $record) {
                if (!is_array($record) || empty($record['source_key'])) continue;
                if ($collection === 'complaints' && ($record['complaint_reason'] ?? '') !== 'match_error') continue;
                if (!topics_are_equivalent((string)($record['topic'] ?? ''), $topic)) continue;
                $key = (string)$record['source_key'];
                $stats[$key]['likes'] = ($stats[$key]['likes'] ?? 0) + $positive;
                $stats[$key]['dislikes'] = ($stats[$key]['dislikes'] ?? 0) + (1 - $positive);
            }
        }
    }
    foreach ($stats as $key => $row) {
        $stats[$key]['votes'] = $row['likes'] + $row['dislikes'];
        $stats[$key]['score'] = ($row['likes'] + 1) / ($row['likes'] + $row['dislikes'] + 2);
    }
    return $stats;
}

/**
 * Итоговый рейтинг источников для очереди кандидатов: доля прошедших быструю оценку, а при достаточном числе
 * оценок пользователей — среднее с их долей лайков. Источник с плохой долей лайков получает 0 (отключён).
 */
function combined_source_rates(array $pass_rates, $topic) {
    $rates = $pass_rates;
    foreach (source_feedback_stats($topic) as $key => $row) {
        if ($row['votes'] >= SOURCE_FEEDBACK_BAN_VOTES && $row['score'] < SOURCE_FEEDBACK_BAN_SCORE) {
            $rates[$key] = 0.0;
        } elseif ($row['votes'] >= SOURCE_FEEDBACK_MIN_VOTES) {
            $rates[$key] = isset($pass_rates[$key]) ? ($pass_rates[$key] + $row['score']) / 2 : $row['score'];
        }
    }
    return $rates;
}

/** Темы, которые ищут пользователи, от самых частых: для ночного подбора источников. */
function popular_topics($limit = 5) {
    $counts = [];
    foreach (is_dir(USERS_DIR) ? user_ids() : [] as $uid) {
        $settings = load_data(USERS_DIR . '/' . $uid . '/settings.json', []);
        $topic = normalize_topic($settings['topic'] ?? '');
        if ($topic === '') continue;
        $key = mb_strtolower($topic);
        $counts[$key] = ['topic' => $topic, 'users' => ($counts[$key]['users'] ?? 0) + 1];
    }
    uasort($counts, function ($a, $b) { return $b['users'] <=> $a['users']; });
    return array_slice(array_column($counts, 'topic'), 0, $limit);
}
