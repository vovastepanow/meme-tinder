<?php
// Качество источника по аудитории: сколько подписчиков, какая доля из них видит посты, не мёртв ли канал или тред.
// Используется автоподбором (lib/source_discovery.php), перепроверкой каталога в парсере (parser/collector.php)
// и списком тредов Двача на вкладке «Источники» (handlers/sources.php). Пороги и веса — в config.php.

/** Значение кривой по опорным точкам [[x, y], ...] (x по возрастанию): между точками линейно, за краями — крайние значения. */
function quality_curve($x, array $points) {
    if ($x <= $points[0][0]) return (float)$points[0][1];
    for ($i = 1; $i < count($points); $i++) {
        if ($x <= $points[$i][0]) {
            [$x0, $y0] = $points[$i - 1];
            [$x1, $y1] = $points[$i];
            return $y0 + ($y1 - $y0) * ($x - $x0) / ($x1 - $x0);
        }
    }
    return (float)end($points)[1];
}

/** Оценка размера аудитории 0..100 по числу подписчиков (логарифмическая шкала). Нет данных — null. */
function source_audience_score($subscribers) {
    $subscribers = (int)$subscribers;
    if ($subscribers <= 0) return null;
    return round(quality_curve(log10($subscribers), [[2.5, 0], [3.0, 30], [4.0, 65], [5.0, 90], [6.0, 100]]), 1);
}

/**
 * Доля подписчиков, которые видят пост: медиана просмотров свежих постов ÷ подписчики. $views — просмотры постов,
 * самые новые первыми; два самых новых пропускаются (просмотры у них ещё набираются). null, если данных мало
 * или подписчиков слишком мало для осмысленной доли.
 */
function source_view_rate($subscribers, array $views) {
    $subscribers = (int)$subscribers;
    $views = array_values(array_filter($views, fn($v) => $v !== null && $v >= 0));
    if ($subscribers < SOURCE_MIN_SUBSCRIBERS_FOR_RATE) return null;
    if (count($views) >= 7) $views = array_slice($views, 2);
    $views = array_slice($views, 0, 12);
    if (count($views) < 4) return null;
    sort($views);
    $n = count($views);
    $median = $n % 2 ? $views[intdiv($n, 2)] : ($views[$n / 2 - 1] + $views[$n / 2]) / 2;
    return round($median / $subscribers, 4);
}

/** Оценка доли просмотров 0..100. У очень больших каналов доля естественно ниже, поэтому шкала для них мягче. */
function source_view_rate_score($view_rate, $subscribers = 0) {
    if ($view_rate === null) return null;
    $scale = (int)$subscribers >= 100000 ? 0.6 : 1.0;
    $points = [[0.015, 0], [0.05, 20], [0.10, 45], [0.25, 80], [0.45, 100]];
    return round(quality_curve($view_rate, array_map(fn($p) => [$p[0] * $scale, $p[1]], $points)), 1);
}

/** Причина считать аудиторию мёртвой (подписчики есть, а постов почти никто не видит) или null. */
function source_dead_audience_reason($subscribers, $view_rate) {
    if ($view_rate === null || $view_rate >= SOURCE_DEAD_VIEW_RATE) return null;
    return sprintf('мёртвая аудитория: посты видят %.1f%% из %s подписчиков', $view_rate * 100, format_subscribers((int)$subscribers));
}

/**
 * Итоговая оценка источника: «по теме» + аудитория + доля просмотров. Часть без данных получает нейтральную оценку
 * (SOURCE_UNKNOWN_*). Источник не по теме (ниже SOURCE_MIN_CONTENT_SCORE) отклоняется: его оценка ниже порога предложений.
 * Возвращает ['score', 'content', 'audience', 'view_rate_score'] (части без данных — null, в расчёте нейтральные значения).
 */
function source_quality_score($content, $subscribers, $view_rate) {
    $audience = source_audience_score($subscribers);
    $rate_score = source_view_rate_score($view_rate, $subscribers);
    $score = (float)$content * SOURCE_WEIGHT_CONTENT
        + ($audience ?? SOURCE_UNKNOWN_AUDIENCE_SCORE) * SOURCE_WEIGHT_AUDIENCE
        + ($rate_score ?? SOURCE_UNKNOWN_VIEW_RATE_SCORE) * SOURCE_WEIGHT_VIEW_RATE;
    // Не по теме: аудитория не спасает, и в предложения источник не попадает (оценка остаётся ниже порога предложений)
    if ($content < SOURCE_MIN_CONTENT_SCORE) $score = min((float)$content, SOURCE_SUGGEST_SCORE - 1);
    return ['score' => (int)round($score), 'content' => (int)round($content), 'audience' => $audience, 'view_rate_score' => $rate_score];
}

/** Строка для журнала и предложений: «12K подписчиков (оценка 66), просмотры 31% (оценка 75)». */
function describe_source_audience($subscribers, $view_rate, array $quality = []) {
    $parts = [];
    if ((int)$subscribers > 0) {
        $parts[] = format_subscribers((int)$subscribers) . ' подписчиков' . (isset($quality['audience']) ? ' (оценка ' . round($quality['audience']) . ')' : '');
    }
    if ($view_rate !== null) {
        $parts[] = 'просмотры ' . round($view_rate * 100) . '% подписчиков' . (isset($quality['view_rate_score']) ? ' (оценка ' . round($quality['view_rate_score']) . ')' : '');
    }
    return $parts ? implode(', ', $parts) : 'аудитория неизвестна';
}

/**
 * Мёртв ли тред Двача по строке каталога доски: закрыт, пустой или давно не было новых постов.
 * Срок тот же, что для каналов (SOURCE_STALE_DAYS). Возвращает причину или null.
 */
/** Живой тред для «Только актуальные источники»: не пустой и в него писали за последние DVACH_THREAD_ALIVE_HOURS часов. */
function dvach_thread_is_alive(array $thread, $now = null) {
    $last = (int)($thread['lasthit'] ?? 0);
    return dvach_thread_dead_reason($thread, $now) === null && $last > 0 && ($now ?? time()) - $last <= DVACH_THREAD_ALIVE_HOURS * 3600;
}

function dvach_thread_dead_reason(array $thread, $now = null) {
    $now = $now ?? time();
    if (!empty($thread['closed'])) return 'тред закрыт';
    $posts = (int)($thread['posts_count'] ?? 0);
    if ($posts < DVACH_THREAD_MIN_POSTS) return "в треде всего {$posts} постов";
    $last = (int)($thread['lasthit'] ?? 0);
    if ($last > 0) {
        $days = (int)floor(($now - $last) / 86400);
        if ($days > SOURCE_STALE_DAYS) return "в тред не писали {$days} дн";
    }
    return null;
}
