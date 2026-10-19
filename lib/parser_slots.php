<?php
// Очередь сборщиков: слот — это блокировка файла, она держится всё время работы процесса и сама снимается при его завершении.

function parser_wait_for_slot() {
    users_ensure_root();
    $handles = [];
    $waited_since = time();
    $last_notice = 0;
    while (true) {
        for ($slot = 0; $slot < MAX_PARALLEL_PARSERS; $slot++) {
            $handle = @fopen(USERS_DIR . '/.slot-' . $slot . '.lock', 'c');
            if ($handle && flock($handle, LOCK_EX | LOCK_NB)) {
                $GLOBALS['parser_slot_handle'] = $handle;
                if (time() - $waited_since > 3) echo "Очередь дошла, начинаю.\n";
                return;
            }
            if ($handle) fclose($handle);
        }
        if (!parser_session_alive()) exit(PARSER_SESSION_ENDED . "\n");
        if (time() - $last_notice >= 20) {
            $last_notice = time();
            echo "Все сборщики заняты другими пользователями — жду очереди (" . (time() - $waited_since) . " с)...\n";
        }
        sleep(2);
    }
}
