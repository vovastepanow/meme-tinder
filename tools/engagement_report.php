<?php
// Отчёт: угадывают ли просмотры, реакции и ответы (поле engagement, см. lib/engagement.php) голоса пользователей.
// Читает историю оценок всех пользователей (или файлов из аргументов), ничего не скачивает. Для каждой задачи считает AUC
// по каждому признаку и сравнивает с оценкой «по теме» самой модели: 0.5 — угадывание, 1.0 — идеально.
// php tools/engagement_report.php [history.json ...]
if (PHP_SAPI !== 'cli') exit('Только из командной строки.');
require_once __DIR__ . '/../bootstrap.php';

const REPORT_MIN_PER_CLASS = 15;   // меньше примеров в любом классе — выводы были бы шумом

const LAUGH = ['😁', '🤣', '😂', '😆'];
const LOVE = ['❤', '🔥', '💯', '🥰', '😍', '🤩', '👍', '👏', '🎉', '⚡', '🏆'];
const NEGATIVE = ['👎', '💩', '🤮', '🤡', '🥱', '😴', '😢', '😭', '😡'];

function report_auc(array $scores, array $labels) {
    $positive = []; $negative = [];
    foreach ($scores as $i => $score) {
        if ($score === null) continue;
        if ($labels[$i]) $positive[] = $score; else $negative[] = $score;
    }
    $sum = 0; $pairs = 0;
    foreach ($positive as $p) foreach ($negative as $n) { $sum += $p > $n ? 1 : ($p == $n ? 0.5 : 0); $pairs++; }
    return $pairs ? $sum / $pairs : null;
}

/** Сумма реакций из набора эмодзи. Эмодзи со склейкой (❤‍🔥) определяется по первому символу. */
function report_reactions(array $reactions, array $set) {
    $sum = 0;
    foreach ($reactions as $emoji => $count) if (in_array(mb_substr($emoji, 0, 1), $set, true)) $sum += $count;
    return $sum;
}

/** Числовые признаки записи по её снимку активности. Ключи — названия признаков. */
function report_features(array $e) {
    if (($e['source'] ?? '') === 'telegram') {
        $views = (int)($e['views'] ?? 0);
        $total = array_sum($e['reactions'] ?? []);
        $median_views = $e['neighbors_median_views'] ?? null;
        $median_rate = $e['neighbors_median_reaction_rate'] ?? null;
        return [
            'views_rel' => $median_views === null ? null : log(($views + 1) / ($median_views + 1)),
            'react_rate_rel' => $median_rate === null ? null : log(($total / max(1, $views) + 0.002) / ($median_rate + 0.002)),
            'react_total' => log($total + 1),
            'laugh_share' => $total ? report_reactions($e['reactions'], LAUGH) / $total : 0,
            'love_share' => $total ? report_reactions($e['reactions'], LOVE) / $total : 0,
            'has_negative' => report_reactions($e['reactions'] ?? [], NEGATIVE) > 0 ? 1 : 0,
            'age_hours' => $e['age_hours'] ?? null
        ];
    }
    if (($e['source'] ?? '') === 'dvach') {
        $posts = (int)($e['thread_posts'] ?? 0);
        $hours = (float)($e['thread_hours'] ?? 0);
        $features = [
            'thread_size_rel' => empty($e['board_median_thread_posts']) ? null : log(($posts + 1) / ($e['board_median_thread_posts'] + 1)),
            'thread_velocity_rel' => ($hours > 0 && isset($e['board_median_velocity'])) ? log(($posts / $hours + 0.1) / ($e['board_median_velocity'] + 0.1)) : null,
            'thread_files_share' => $posts ? ($e['thread_files'] ?? 0) / $posts : null,
        ];
        if (empty($e['is_op'])) {
            // У ОП-поста ответов почти столько же, сколько постов в треде: считаем только для остальных
            $features += [
                'replies' => log(($e['replies'] ?? 0) + 1),
                'has_reply' => ($e['replies'] ?? 0) > 0 ? 1 : 0,
                'reply_share' => ($e['posts_after'] ?? 0) > 0 ? ($e['replies'] ?? 0) / $e['posts_after'] : 0,
                'position' => $e['index'] ?? null
            ];
        }
        return $features;
    }
    return [];
}

// ---- Записи ----
$files = array_slice($argv, 1) ?: array_map(fn($uid) => history_file($uid), user_ids());
$records = [];
$total = 0;
foreach ($files as $file) {
    $history = load_data($file, []);
    foreach (['liked', 'disliked', 'undecided', 'complaints'] as $collection) {
        foreach ($history[$collection] ?? [] as $record) {
            $total++;
            if (!is_array($record['engagement'] ?? null) || !empty($record['engagement']['backfilled'])) continue;   // дособранные позже — не берём
            if ($collection === 'complaints' && ($record['complaint_reason'] ?? '') !== 'match_error') continue;
            $records[] = ['label' => $collection, 'engagement' => $record['engagement'], 'relevance' => $record['relevance'] ?? null,
                'features' => report_features($record['engagement'])];
        }
    }
}
printf("Оценок всего: %d, со снимком активности: %d\n", $total, count($records));
if (!$records) exit("Снимки появляются у записей, найденных парсером после включения сбора. Подождите, пока накопятся оценки.\n");

$tasks = [
    'Годно: лайк против дизлайка' => [fn($r) => $r['label'] === 'liked', fn($r) => in_array($r['label'], ['liked', 'disliked'], true), false],
    'Годно: лайк против дизлайков и жалоб' => [fn($r) => $r['label'] === 'liked', fn($r) => $r['label'] !== 'undecided', false],
    'Не по теме: жалоба «ошибка подбора» против остального' => [fn($r) => $r['label'] === 'complaints', fn($r) => true, true],
];
foreach (['telegram' => 'Telegram', 'dvach' => 'Двач'] as $source => $name) {
    $of_source = array_values(array_filter($records, fn($r) => ($r['engagement']['source'] ?? '') === $source));
    printf("\n################ %s: %d записей с оценкой ################\n", $name, count($of_source));
    foreach ($tasks as $title => [$positive, $include, $invert]) {
        $rows = array_values(array_filter($of_source, $include));
        $labels = array_map(fn($r) => $positive($r) ? 1 : 0, $rows);
        $yes = array_sum($labels); $no = count($labels) - $yes;
        if ($yes < REPORT_MIN_PER_CLASS || $no < REPORT_MIN_PER_CLASS) {
            printf("\n== %s: мало данных (положительных %d, остальных %d; нужно по %d)\n", $title, $yes, $no, REPORT_MIN_PER_CLASS);
            continue;
        }
        printf("\n== %s (положительных %d, остальных %d)\n", $title, $yes, $no);
        $model = array_map(fn($r) => $r['relevance'] === null ? null : ($invert ? 100 - $r['relevance'] : $r['relevance']), $rows);
        printf("  %-22s AUC %.2f   ← оценка модели «по теме», для сравнения\n", 'relevance', report_auc($model, $labels));
        $names = array_unique(array_merge(...array_map(fn($r) => array_keys($r['features']), $rows)));
        foreach ($names as $feature) {
            $a = report_auc(array_map(fn($r) => $r['features'][$feature] ?? null, $rows), $labels);
            if ($a === null) continue;
            printf("  %-22s AUC %.2f%s\n", $feature, $a, abs($a - 0.5) >= 0.1 ? ($a > 0.5 ? '  ↑ выше — чаще положительное' : '  ↓ выше — реже положительное') : '');
        }
    }
}
echo "\nAUC: 0.5 — угадывание. Пока в классе меньше ~100 примеров, различия до ±0.1 — шум. Признак полезен, если стабильно\nдержится дальше 0.6 (или 0.4) и не слабее relevance.\n";
