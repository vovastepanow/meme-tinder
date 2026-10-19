<?php
// Привязка фоновых процессов к открытой вкладке приложения.
//
// Вкладка при открытии «занимает» сессию (tab_open): предыдущий парсер останавливается, запускается новый,
// привязанный к этой вкладке. Пока вкладка жива, она раз в TAB_HEARTBEAT_SECONDS присылает сигнал (tab_heartbeat).
// Закрытие вкладки сообщает tab_close; если сигналов нет дольше TAB_SESSION_TTL (браузер закрыт аварийно,
// пропал интернет), парсер сам останавливается. Если приложение открыли в другой вкладке, эта вкладка
// становится неактивной: сессию ведёт последняя открытая.

const TAB_SESSION_TTL = 150;        // секунд без сигнала до остановки (фоновые вкладки браузер «замедляет» до раза в минуту)
const TAB_HEARTBEAT_SECONDS = 5;

function tab_session_file() {
    return parser_runtime_file('.session');
}

function is_valid_tab_id($tab) {
    return is_string($tab) && preg_match('/^[a-f0-9-]{8,64}$/', $tab) === 1;
}

function tab_session_state() {
    $state = json_decode((string)@file_get_contents(tab_session_file()), true);
    return is_array($state) ? $state : [];
}

function tab_session_is_fresh(array $state) {
    return !empty($state['tab']) && time() - (int)($state['seen'] ?? 0) <= TAB_SESSION_TTL;
}

/** Идентификатор вкладки, к которой нужно привязать запускаемый парсер ('' — привязки нет). */
function tab_session_current_tab() {
    $state = tab_session_state();
    return tab_session_is_fresh($state) ? (string)$state['tab'] : '';
}

/** Вкладка открыта: занимает сессию, старые процессы останавливаются, парсер запускается заново. Возвращает статус запуска. */
function tab_session_open($tab) {
    update_data(tab_session_file(), [], function () use ($tab) {
        return ['tab' => $tab, 'seen' => time(), 'opened_at' => time()];
    });
    return restart_content_parser();
}

/**
 * Сигнал живой вкладки. Возвращает ['owner' => bool, 'parser' => статус|null].
 * Если сессия молчала дольше TTL (вкладка «спала») — парсер перезапускается; если сессию давно никто не ведёт,
 * вкладка забирает её себе.
 */
function tab_session_heartbeat($tab) {
    $outcome = 'other';
    update_data(tab_session_file(), [], function ($state) use ($tab, &$outcome) {
        $state = is_array($state) ? $state : [];
        $stale = !tab_session_is_fresh($state);
        if (($state['tab'] ?? '') === $tab) {
            $outcome = $stale ? 'resumed' : 'owner';
        } elseif ($stale) {
            $outcome = 'claimed';
        } else {
            return null; // сессию ведёт другая вкладка
        }
        return ['tab' => $tab, 'seen' => time(), 'opened_at' => (int)($state['opened_at'] ?? time())];
    });
    if ($outcome === 'other') return ['owner' => false, 'parser' => null];
    $parser = null;
    if ($outcome === 'claimed') $parser = restart_content_parser();
    elseif ($outcome === 'resumed') $parser = start_content_parser_if_needed(true);
    return ['owner' => true, 'parser' => $parser];
}

/** Вкладка закрыта: если она вела сессию, парсер останавливается. */
function tab_session_close($tab) {
    $owned = false;
    update_data(tab_session_file(), [], function ($state) use ($tab, &$owned) {
        if (!is_array($state) || ($state['tab'] ?? '') !== $tab) return null;
        $owned = true;
        $state['seen'] = 0;
        return $state;
    });
    if ($owned) stop_content_parser();
    return $owned;
}

// ---- Сторона парсера ----

/** Привязывает работающий процесс парсера к вкладке (идентификатор приходит аргументом командной строки). */
function parser_bind_tab($tab = null) {
    static $bound = '';
    if ($tab !== null) $bound = is_valid_tab_id($tab) ? $tab : '';
    return $bound;
}

/**
 * Жива ли вкладка, к которой привязан парсер. Без привязки (ручной запуск из консоли) всегда true.
 * Проверка читает маленький файл не чаще раза в 2 секунды.
 */
function parser_session_alive() {
    $tab = parser_bind_tab();
    if ($tab === '') return true;
    static $checked_at = 0.0, $alive = true;
    $now = microtime(true);
    if ($now - $checked_at < 2.0) return $alive;
    $checked_at = $now;
    $state = tab_session_state();
    $alive = ($state['tab'] ?? '') === $tab && tab_session_is_fresh($state);
    return $alive;
}

const PARSER_SESSION_ENDED = 'Окно приложения закрыто или открыто заново — работа остановлена.';
