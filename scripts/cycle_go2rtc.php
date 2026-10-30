<?php
/*
 * Цикл модуля «Камеры (go2rtc)»: держит запущенным встроенный go2rtc (режим «Встроенный» в настройках модуля).
 * Раз в 5 с: команды из панели (запустить, остановить, перезапустить), запуск, если go2rtc не отвечает,
 * перезапуск, если изменился конфиг (порты, ffmpeg, камеры exec:/echo:), раз в 30 с — регистрация камер модуля.
 * Во внешнем режиме ничего не делает (и останавливает свой go2rtc, если тот остался запущенным).
 */

chdir(dirname(__FILE__) . '/../');

include_once './config.php';
include_once './lib/loader.php';
include_once './lib/threads.php';

set_time_limit(0);

include_once './load_settings.php';
include_once DIR_MODULES . 'go2rtc/go2rtc_lib.inc.php';

$cycle = str_replace('.php', '', basename(__FILE__));
setGlobal($cycle . 'Run', time(), 1);
echo date('H:i:s') . ' running ' . basename(__FILE__) . PHP_EOL;

$checked = 0;
$last_register = 0;
while (1) {
    if (time() - $checked >= 5) {
        $checked = time();
        setGlobal($cycle . 'Run', time(), 1);
        if (function_exists('go2rtcLocalTick')) {
            go2rtcLocalTick($last_register);
        }
    }
    if (file_exists('./reboot') || isset($_GET['onetime'])) {
        // go2rtc не останавливаем: при перезапуске MajorDoMo новый цикл найдёт его работающим
        exit;
    }
    sleep(1);
}
