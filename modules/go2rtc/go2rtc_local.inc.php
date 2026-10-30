<?php
/*
 * Встроенный go2rtc (ветка alpha): модуль сам ставит go2rtc на эту машину, держит его запущенным (цикл
 * scripts/cycle_go2rtc.php) и заводит в нём камеры из своего списка.
 *
 * Режим — настройка GO2RTC_MODE: '' — внешний go2rtc, как раньше (адрес API, камеры берутся из go2rtc);
 * 'local' — встроенный. Остальные настройки встроенного:
 *   GO2RTC_API_PORT (1984), GO2RTC_RTSP_PORT (8554, общая с внешним режимом), GO2RTC_WEBRTC_PORT (8555);
 *   GO2RTC_API_LAN — '1': API слушает сеть (прямой просмотр в браузере, ссылки HTTP для устройств), иначе
 *     только 127.0.0.1;
 *   GO2RTC_FFMPEG — путь к ffmpeg (пусто — ffmpeg из PATH);
 *   GO2RTC_FILE — '1': данные go2rtc в файле (сопряжения HomeKit, облачные входы, потоки из веб-интерфейса go2rtc,
 *     preload) — файл идёт первым конфигом, строка модуля вторым (go2rtc объединяет, модуль побеждает);
 *     GO2RTC_FILE_PATH — свой путь (пусто — cms/go2rtc/go2rtc.yaml.php). Файлы в папке сайта (данные, копии, журнал) —
 *     .php с первой строкой GO2RTC_LOCAL_GUARD: для go2rtc это комментарий YAML, а веб-сервер по ссылке выполнит
 *     её как PHP и отдаст «# » — не зависит от настроек веб-сервера (.htaccess и правила nginx есть не везде, в Docker);
 *   GO2RTC_API_AUTH — '1' (по умолчанию выключено, включает пользователь): API из сети — с логином и паролем (GO2RTC_API_LOCAL_USER,
 *     GO2RTC_API_LOCAL_PASSWORD — создаются сами); с самой машины модуль ходит без пароля (local_auth: false).
 *     Без пароля и с файлом — /api/config открыт сети: прочитать файл и записать в него exec: может любой.
 *
 * Файлов конфига go2rtc нет: конфиг передаётся строкой (-config '{…}'), поэтому по прямой ссылке утекать
 * нечему, а /api/config отвечает 410. Камеры с обычными источниками (rtsp, http, onvif, ffmpeg без пробелов)
 * заводятся через API (PUT /api/streams) на лету — в /api/streams go2rtc 1.9.15 прячет логин и пароль.
 * exec:, echo:, expr: и источники с пробелами go2rtc через API не принимает — они идут в конфиг при запуске:
 * цикл замечает, что конфиг изменился, и перезапускает go2rtc.
 *
 * Бинарник: официальный релиз AlexxIT/go2rtc (Linux, Windows) или сборка artt652/go2rtc-android (Termux) —
 * закреплённая версия, каждый файл сверяется по sha256. Каталог — cms/go2rtc (только программа, журнал и
 * служебные файлы; доступ по ссылке закрыт .htaccess).
 */

define('GO2RTC_LOCAL_VERSION', 'v1.9.15');
define('GO2RTC_LOCAL_GUARD', '# <?php exit; ?>');
define('GO2RTC_ANDROID_RELEASE', 'v1.9.15-android4');

// Файлы релизов и их sha256 (закреплены — новая версия go2rtc приходит с обновлением модуля)
function go2rtcLocalAssets()
{
    return array(
        'go2rtc_linux_amd64' => '20b46c6910734cb05ecefb234066f36868ff19b42ba1418a7a32e13a3e035712',
        'go2rtc_linux_i386' => '3a4d74b7d6ba2a04c5c7e7c7ee6208f9060fd17dfcce5a0975d44b6621a85c1e',
        'go2rtc_linux_arm64' => 'af416f22b21a24ab83b8d78642190b4932ae695b4e386853f11d1b0319781763',
        'go2rtc_linux_arm' => 'cbffe4075a004e2015fd9d7e324800ab7968b52f49e9ce803361879d5e246028',
        'go2rtc_linux_armv6' => '7dc73f47548fbe472fb6e90b01abe6c1eb50e0073b22bfc4bcf34feaabc6c88d',
        'go2rtc_win64.zip' => '5b448fc90f15c925406d8da0984301985e385050222c1c2bbdf0a76fe4b1e9cb',
        'go2rtc_win32.zip' => '98f639d9b3c1bf36242e5c745d87fb6875dfbe8c78bb3d7086c77445e486896b',
        'go2rtc_win_arm64.zip' => 'fc5f7397d740899fb1d5f865821eb8c88b3e3951175ce60dde2dce4d1b858c7c',
        'go2rtc_android_arm64' => '9289de50061d0420e48ae6d050b0beeb8d17359fa0f09e71445d6152403984d0',
        'go2rtc_android_arm' => 'cf8c93a250f0e7d1478b3e9045c4c7935d9122881bde619207ee99f9bcc03300',
        'go2rtc_android_amd64' => 'c5c5e3847c2516e6dc0120300a7f93db53a6cb951ab29043b4117eee5867d2bc',
    );
}

function go2rtcLocal()
{
    $config = go2rtcModuleConfig();
    return isset($config['GO2RTC_MODE']) && $config['GO2RTC_MODE'] == 'local';
}

// Настройки встроенного go2rtc
function go2rtcLocalSettings()
{
    $config = go2rtcModuleConfig();
    $get = function ($key, $default = '') use ($config) {
        return (isset($config[$key]) && $config[$key] !== '') ? (string)$config[$key] : $default;
    };
    $port = function ($value, $default) {
        $value = (int)$value;
        return ($value >= 1024 && $value <= 65535) ? $value : $default;
    };
    return array(
        'api_port' => $port($get('GO2RTC_API_PORT'), 1984),
        'rtsp_port' => $port($get('GO2RTC_RTSP_PORT'), 8554),
        'webrtc_port' => $port($get('GO2RTC_WEBRTC_PORT'), 8555),
        'api_lan' => $get('GO2RTC_API_LAN', '1') == '1',
        'ffmpeg' => $get('GO2RTC_FFMPEG'),
        'rtsp_user' => $get('GO2RTC_RTSP_USER'),
        'rtsp_password' => $get('GO2RTC_RTSP_PASSWORD'),
        'file' => $get('GO2RTC_FILE') == '1',
        'file_path' => $get('GO2RTC_FILE_PATH', go2rtcLocalDir() . '/go2rtc.yaml.php'),
        'api_auth' => $get('GO2RTC_API_AUTH') == '1',
        'api_user' => $get('GO2RTC_API_LOCAL_USER', 'admin'),
        'api_password' => $get('GO2RTC_API_LOCAL_PASSWORD'),
    );
}

// Пароль API встроенного go2rtc для доступа из сети: создать один раз
function go2rtcLocalApiPassword()
{
    $config = go2rtcModuleConfig();
    if (empty($config['GO2RTC_API_LOCAL_PASSWORD'])) {
        $config['GO2RTC_API_LOCAL_PASSWORD'] = substr(md5(uniqid('', true) . mt_rand()), 0, 16);
        if (empty($config['GO2RTC_API_LOCAL_USER'])) {
            $config['GO2RTC_API_LOCAL_USER'] = 'admin';
        }
        go2rtcModuleConfigSave($config);
    }
    return $config['GO2RTC_API_LOCAL_PASSWORD'];
}

// API из сети с паролем (сопряжения и редактор go2rtc — по логину; прямой просмотр в браузере тогда недоступен)
function go2rtcLocalApiLocked()
{
    $s = go2rtcLocalSettings();
    return $s['api_lan'] && $s['api_auth'];
}

// Опасное сочетание: файл данных + API в сети без пароля (/api/config открыт сети)
function go2rtcLocalApiOpenFile()
{
    $s = go2rtcLocalSettings();
    return $s['file'] && $s['api_lan'] && !$s['api_auth'];
}

function go2rtcLocalDir()
{
    return ROOT . 'cms/go2rtc';
}

function go2rtcLocalWindows()
{
    return strtoupper(substr(PHP_OS, 0, 3)) == 'WIN';
}

function go2rtcLocalAndroid()
{
    return !go2rtcLocalWindows() && (is_file('/system/bin/linker64') || is_file('/system/bin/linker'));
}

// Какой файл ставить на эту машину: array(asset, url, sha256, exe) или array('error' => …)
function go2rtcLocalPlatform()
{
    $assets = go2rtcLocalAssets();
    $machine = strtolower(php_uname('m'));
    $bits64 = PHP_INT_SIZE == 8;
    $asset = '';
    if (go2rtcLocalWindows()) {
        if (strpos($machine, 'arm') !== false || strpos($machine, 'aarch64') !== false) {
            $asset = 'go2rtc_win_arm64.zip';
        } else {
            $asset = $bits64 ? 'go2rtc_win64.zip' : 'go2rtc_win32.zip';
        }
    } elseif (go2rtcLocalAndroid()) {
        // по разрядности самого PHP: на ТВ-приставке процессор 64-битный, а приложение может быть 32-битным
        if (strpos($machine, 'x86') !== false || strpos($machine, 'amd64') !== false) {
            $asset = 'go2rtc_android_amd64';
        } else {
            $asset = $bits64 ? 'go2rtc_android_arm64' : 'go2rtc_android_arm';
        }
    } elseif (strtoupper(substr(PHP_OS, 0, 5)) == 'LINUX') {
        if ($machine == 'x86_64' || $machine == 'amd64') {
            $asset = $bits64 ? 'go2rtc_linux_amd64' : 'go2rtc_linux_i386';
        } elseif (preg_match('/^i[3-6]86$/', $machine)) {
            $asset = 'go2rtc_linux_i386';
        } elseif ($machine == 'aarch64' || $machine == 'arm64') {
            $asset = $bits64 ? 'go2rtc_linux_arm64' : 'go2rtc_linux_arm';
        } elseif (strpos($machine, 'armv6') === 0) {
            $asset = 'go2rtc_linux_armv6';
        } elseif (strpos($machine, 'arm') === 0) {
            $asset = 'go2rtc_linux_arm';
        }
    }
    if ($asset == '' || !isset($assets[$asset])) {
        return array('error' => 'unsupported platform: ' . PHP_OS . ' ' . $machine . ' (' . (PHP_INT_SIZE * 8) . ' bit)');
    }
    $url = (strpos($asset, 'go2rtc_android_') === 0)
        ? 'https://github.com/artt652/go2rtc-android/releases/download/' . GO2RTC_ANDROID_RELEASE . '/' . $asset
        : 'https://github.com/AlexxIT/go2rtc/releases/download/' . GO2RTC_LOCAL_VERSION . '/' . $asset;
    return array('asset' => $asset, 'url' => $url, 'sha256' => $assets[$asset], 'exe' => go2rtcLocalWindows() ? 'go2rtc.exe' : 'go2rtc');
}

// Служебные файлы в каталоге: state.json (что установлено), run.json (с каким конфигом запущен), control (команда циклу)
function go2rtcLocalState($name, $set = null)
{
    $file = go2rtcLocalDir() . '/' . $name . '.json';
    if ($set !== null) {
        @file_put_contents($file, json_encode($set));
        return $set;
    }
    $data = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
    return is_array($data) ? $data : array();
}

function go2rtcLocalBin()
{
    $state = go2rtcLocalState('state');
    return !empty($state['exe']) ? go2rtcLocalDir() . '/' . $state['exe'] : '';
}

// Каталог: создать и закрыть от доступа по ссылке
function go2rtcLocalPrepareDir()
{
    $dir = go2rtcLocalDir();
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return is_dir($dir) && is_writable($dir);
}

// Скачать URL в файл (curl или потоки PHP); true — получилось
function go2rtcLocalDownload($url, $file, &$error = '')
{
    $error = '';
    $out = @fopen($file, 'wb');
    if (!$out) {
        $error = 'cannot write ' . $file;
        return false;
    }
    $ok = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(CURLOPT_FILE => $out, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 600, CURLOPT_FAILONERROR => true, CURLOPT_USERAGENT => 'MajorDoMo-go2rtc'));
        $ok = curl_exec($ch) !== false;
        if (!$ok) {
            $error = curl_error($ch);
        }
        curl_close($ch);
    } else {
        $ctx = stream_context_create(array('http' => array('timeout' => 600, 'follow_location' => 1, 'user_agent' => 'MajorDoMo-go2rtc')));
        $in = @fopen($url, 'rb', false, $ctx);
        if ($in) {
            $ok = stream_copy_to_stream($in, $out) > 0;
            fclose($in);
        } else {
            $error = 'cannot open ' . $url;
        }
    }
    fclose($out);
    if (!$ok) {
        @unlink($file);
    }
    return $ok;
}

// Запуск программы и вывод (stdout+stderr); $code — код выхода
function go2rtcLocalExec($cmd, &$code = 0)
{
    $output = array();
    $code = 0;
    @exec($cmd . ' 2>&1', $output, $code);
    return implode("\n", $output);
}

// Аргумент командной строки: escapeshellarg для Unix; для Windows — правила разбора CommandLineToArgv
function go2rtcLocalArg($value)
{
    if (!go2rtcLocalWindows()) {
        return escapeshellarg($value);
    }
    $result = '"';
    $slashes = 0;
    $len = strlen($value);
    for ($i = 0; $i < $len; $i++) {
        $c = $value[$i];
        if ($c == '\\') {
            $slashes++;
            continue;
        }
        if ($c == '"') {
            $result .= str_repeat('\\', $slashes * 2 + 1) . '"';
        } else {
            $result .= str_repeat('\\', $slashes) . $c;
        }
        $slashes = 0;
    }
    return $result . str_repeat('\\', $slashes * 2) . '"';
}

// Поставить go2rtc: скачать, сверить sha256, распаковать, проверить запуск. Возвращает журнал; $ok — получилось
function go2rtcLocalInstall(&$ok = false)
{
    $ok = false;
    $log = array();
    $platform = go2rtcLocalPlatform();
    if (!empty($platform['error'])) {
        return $platform['error'];
    }
    if (!go2rtcLocalPrepareDir()) {
        return 'cannot write ' . go2rtcLocalDir();
    }
    $dir = go2rtcLocalDir();
    $tmp = $dir . '/' . $platform['asset'] . '.download';
    $log[] = 'go2rtc ' . GO2RTC_LOCAL_VERSION . ': ' . $platform['asset'];
    $log[] = $platform['url'];
    $error = '';
    if (!go2rtcLocalDownload($platform['url'], $tmp, $error)) {
        $log[] = 'download failed: ' . $error;
        return implode("\n", $log);
    }
    $sha = hash_file('sha256', $tmp);
    if ($sha !== $platform['sha256']) {
        @unlink($tmp);
        $log[] = 'sha256 mismatch: ' . $sha;
        return implode("\n", $log);
    }
    $log[] = 'sha256 OK';
    $new = $dir . '/' . $platform['exe'] . '.new';
    if (substr($platform['asset'], -4) == '.zip') {
        if (!class_exists('ZipArchive')) {
            @unlink($tmp);
            $log[] = 'ZipArchive (php zip) is required';
            return implode("\n", $log);
        }
        $zip = new ZipArchive();
        $data = ($zip->open($tmp) === true) ? $zip->getFromName($platform['exe']) : false;
        if (is_object($zip)) {
            @$zip->close();
        }
        @unlink($tmp);
        if ($data === false || !@file_put_contents($new, $data)) {
            $log[] = 'unzip failed';
            return implode("\n", $log);
        }
    } elseif (!@rename($tmp, $new)) {
        @unlink($tmp);
        $log[] = 'cannot move ' . $tmp;
        return implode("\n", $log);
    }
    @chmod($new, 0755);
    // проверка запуска; Android: если напрямую нельзя (приложение под Android 10+) — через системный загрузчик
    $launch = '';
    $version = go2rtcLocalExec(go2rtcLocalArg($new) . ' -version', $code);
    if ($code != 0 && go2rtcLocalAndroid()) {
        $linker = (PHP_INT_SIZE == 8) ? '/system/bin/linker64' : '/system/bin/linker';
        $version = go2rtcLocalExec($linker . ' ' . go2rtcLocalArg($new) . ' -version', $code);
        if ($code == 0) {
            $launch = $linker;
            $log[] = 'started through ' . $linker;
        }
    }
    if ($code != 0 || stripos($version, 'go2rtc') === false) {
        @unlink($new);
        $log[] = 'test run failed (' . $code . '): ' . trim($version);
        return implode("\n", $log);
    }
    $log[] = trim($version);
    // заменить прежний файл: работающий go2rtc остановит цикл, он же запустит новый (state.json изменился)
    $exe = $dir . '/' . $platform['exe'];
    if (is_file($exe) && !@unlink($exe)) {
        // Windows: работающий файл не удалить — переименовать
        @rename($exe, $exe . '.old');
    }
    if (!@rename($new, $exe)) {
        $log[] = 'cannot replace ' . $exe;
        return implode("\n", $log);
    }
    go2rtcLocalState('state', array('version' => GO2RTC_LOCAL_VERSION, 'asset' => $platform['asset'], 'exe' => $platform['exe'],
        'launch' => $launch, 'installed' => time()));
    $ok = true;
    $log[] = 'installed: ' . $exe;
    DebMes('go2rtc: installed ' . $platform['asset'] . ' ' . GO2RTC_LOCAL_VERSION, 'go2rtc');
    return implode("\n", $log);
}

// ffmpeg: путь из настройки или из PATH; возвращает array(path, version) (version '' — не найден)
function go2rtcLocalFfmpeg()
{
    $s = go2rtcLocalSettings();
    $bin = $s['ffmpeg'] != '' ? $s['ffmpeg'] : (go2rtcLocalWindows() ? 'ffmpeg.exe' : 'ffmpeg');
    $out = go2rtcLocalExec(go2rtcLocalArg($bin) . ' -version', $code);
    $version = ($code == 0 && preg_match('/^ffmpeg version (\S+)/m', $out, $m)) ? $m[1] : '';
    return array($bin, $version);
}

// Адрес источника камеры: логин и пароль — в адрес (только для отправки в go2rtc, в базе — отдельно)
function go2rtcLocalSource($camera, $hq = false)
{
    $src = trim((string)($hq ? $camera['SOURCE_HQ'] : $camera['SOURCE']));
    if ($src == '' || (string)$camera['SOURCE_USER'] === '' || !preg_match('#^([a-z][a-z0-9+.\-]*://)([^/@]*@)?(.*)$#i', $src, $m)) {
        return $src;
    }
    if ($m[2] != '') {
        return $src; // логин уже в адресе
    }
    $auth = rawurlencode($camera['SOURCE_USER']) . ((string)$camera['SOURCE_PASSWORD'] !== '' ? ':' . rawurlencode($camera['SOURCE_PASSWORD']) : '');
    return $m[1] . $auth . '@' . $m[3];
}

// Источник, который go2rtc через API не примет: exec:, echo:, expr: или с пробелами — только в конфиг при запуске.
// С файлом данных — все камеры модуля в строке конфига: PUT записал бы их (с паролями) в файл go2rtc
function go2rtcLocalConfigOnly($src)
{
    $s = go2rtcLocalSettings();
    return $s['file'] || go2rtcLocalApiRejects($src);
}

// go2rtc через API такой источник не примет (exec:, echo:, expr:, пробелы)
function go2rtcLocalApiRejects($src)
{
    return (bool)preg_match('/^(exec|echo|expr):/i', $src) || (bool)preg_match('/\s/', $src);
}

// Потоки встроенного go2rtc из камер модуля: array(имя => источник)
function go2rtcLocalStreams()
{
    $streams = array();
    foreach (SQLSelect("SELECT * FROM go2rtc_cameras WHERE LOCAL=1 ORDER BY PRIORITY, ID") as $camera) {
        $src = go2rtcLocalSource($camera);
        if ($src != '') {
            $streams[$camera['NAME']] = $src;
        }
        $hq = go2rtcLocalSource($camera, true);
        if ($hq != '') {
            $streams[$camera['NAME'] . '_hq'] = $hq;
        }
    }
    return $streams;
}

// Значение для конфига в стиле YAML flow: строка в одинарных кавычках (без двойных — проще для командной строки)
function go2rtcLocalYaml($value)
{
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value)) {
        return (string)$value;
    }
    if (is_array($value)) {
        $list = (array_keys($value) === range(0, count($value) - 1));
        $items = array();
        foreach ($value as $k => $v) {
            $items[] = $list ? go2rtcLocalYaml($v) : go2rtcLocalYaml((string)$k) . ': ' . go2rtcLocalYaml($v);
        }
        return $list ? '[' . implode(', ', $items) . ']' : '{' . implode(', ', $items) . '}';
    }
    return "'" . str_replace("'", "''", (string)$value) . "'";
}

// Конфиг go2rtc строкой (без файла): порты, ffmpeg, потоки, которые через API не завести
function go2rtcLocalConfig()
{
    $s = go2rtcLocalSettings();
    $ffmpeg = $s['ffmpeg'] != '' ? $s['ffmpeg'] : (go2rtcLocalWindows() ? 'ffmpeg.exe' : 'ffmpeg');
    // значения модуля задаются явно — перекрывают то же самое из файла данных (строка идёт вторым конфигом)
    $rtsp = array('listen' => ':' . $s['rtsp_port'], 'username' => $s['rtsp_user'], 'password' => $s['rtsp_user'] != '' ? $s['rtsp_password'] : '');
    $api = array('listen' => ($s['api_lan'] ? '' : '127.0.0.1') . ':' . $s['api_port'], 'origin' => '*',
        'username' => '', 'password' => '', 'local_auth' => false);
    if (go2rtcLocalApiLocked()) {
        $api['username'] = $s['api_user'];
        $api['password'] = $s['api_password'] != '' ? $s['api_password'] : go2rtcLocalApiPassword();
    }
    $config = array(
        'log' => array('level' => 'info'),
        'api' => $api,
        'rtsp' => $rtsp,
        'webrtc' => array('listen' => ':' . $s['webrtc_port']),
        'ffmpeg' => array('bin' => $ffmpeg),
    );
    $streams = array();
    foreach (go2rtcLocalStreams() as $name => $src) {
        if (go2rtcLocalConfigOnly($src)) {
            $streams[$name] = $src;
        }
    }
    if (count($streams)) {
        $config['streams'] = $streams;
    }
    return go2rtcLocalYaml($config);
}

// Команда запуска go2rtc
function go2rtcLocalCommand()
{
    $state = go2rtcLocalState('state');
    $bin = go2rtcLocalBin();
    if ($bin == '' || !is_file($bin)) {
        return '';
    }
    $s = go2rtcLocalSettings();
    $file = '';
    if ($s['file']) {
        // файл данных первым: в него go2rtc пишет сам (PatchConfig пишет в первый файловый конфиг)
        $old = go2rtcLocalDir() . '/go2rtc.ini'; // путь по умолчанию первой версии: .ini отдают не все веб-серверы закрытым
        if (!is_file($s['file_path']) && is_file($old) && $s['file_path'] == go2rtcLocalDir() . '/go2rtc.yaml.php') {
            @file_put_contents($s['file_path'], go2rtcLocalGuard((string)@file_get_contents($old))) && @unlink($old);
        }
        if (!is_file($s['file_path'])) {
            go2rtcLocalPrepareDir();
            @file_put_contents($s['file_path'], (go2rtcLocalInSite($s['file_path']) ? GO2RTC_LOCAL_GUARD . "\n" : '')
                . "# go2rtc: data saved by go2rtc itself (HomeKit pairings, cloud logins, streams from its web UI)\n");
        }
        go2rtcLocalGuardFile($s['file_path']);
        $file = ' -config ' . go2rtcLocalArg($s['file_path']);
    }
    $cmd = (!empty($state['launch']) ? $state['launch'] . ' ' : '') . go2rtcLocalArg($bin) . $file . ' -config ' . go2rtcLocalArg(go2rtcLocalConfig());
    return $cmd;
}

// go2rtc отвечает на своём порту: версия или ''
function go2rtcLocalPing($port = 0)
{
    $s = go2rtcLocalSettings();
    $port = $port ? $port : $s['api_port'];
    $ctx = stream_context_create(array('http' => array('timeout' => 2, 'ignore_errors' => true)));
    $body = @file_get_contents('http://127.0.0.1:' . (int)$port . '/api', false, $ctx);
    $data = $body ? json_decode($body, true) : null;
    return (is_array($data) && !empty($data['version'])) ? (string)$data['version'] : '';
}

function go2rtcLocalPidAlive($pid)
{
    $pid = (int)$pid;
    if ($pid <= 0) {
        return false;
    }
    if (go2rtcLocalWindows()) {
        $out = go2rtcLocalExec('tasklist /FI "PID eq ' . $pid . '" /NH');
        return strpos($out, (string)$pid) !== false;
    }
    if (function_exists('posix_kill')) {
        return @posix_kill($pid, 0);
    }
    return is_dir('/proc/' . $pid);
}

// Порт занят (кем-то): true
function go2rtcLocalPortBusy($port, $udp = false)
{
    $fp = @fsockopen(($udp ? 'udp://' : '') . '127.0.0.1', (int)$port, $errno, $errstr, 1);
    if ($fp) {
        fclose($fp);
        return !$udp; // UDP «соединяется» всегда — по нему судить нельзя
    }
    return false;
}

// Запустить go2rtc (из цикла). Возвращает '' или текст ошибки
function go2rtcLocalStart()
{
    $cmd = go2rtcLocalCommand();
    if ($cmd == '') {
        return 'go2rtc is not installed';
    }
    $s = go2rtcLocalSettings();
    if (go2rtcLocalPing()) {
        return '';
    }
    foreach (array($s['api_port'], $s['rtsp_port'], $s['webrtc_port']) as $port) {
        if (go2rtcLocalPortBusy($port)) {
            return 'port ' . $port . ' is busy';
        }
    }
    $dir = go2rtcLocalDir();
    $log = go2rtcLocalLogFile();
    if (is_file($log) && filesize($log) > 2 * 1024 * 1024) {
        @rename($log, $dir . '/go2rtc.1.log.php');
    }
    if (!is_file($log)) {
        @file_put_contents($log, GO2RTC_LOCAL_GUARD . "\n");
    }
    @unlink($dir . '/go2rtc.log'); // журнал прежних версий (.log отдают не все веб-серверы закрытым)
    @unlink($dir . '/go2rtc.log.1');
    foreach ((array)glob($dir . '/go2rtc.ini.*.bak') as $bak) {
        // копии импорта первой версии — тоже в .php с заглушкой
        if (@file_put_contents(substr($bak, 0, -4) . '.bak.php', go2rtcLocalGuard((string)@file_get_contents($bak))) !== false) {
            @unlink($bak);
        }
    }
    $spec = array(0 => array('file', go2rtcLocalWindows() ? 'NUL' : '/dev/null', 'r'), 1 => array('file', $log, 'a'), 2 => array('file', $log, 'a'));
    $env = null;
    if (getenv('LD_LIBRARY_PATH') !== false && strpos((string)getenv('LD_LIBRARY_PATH'), '$') !== false) {
        // KSWEB и подобные передают неразвёрнутый "$LD_LIBRARY_PATH" — загрузчик ругается на каждый запуск
        putenv('LD_LIBRARY_PATH');
    }
    $options = go2rtcLocalWindows() ? array('bypass_shell' => true) : array();
    $proc = @proc_open(go2rtcLocalWindows() ? $cmd : 'exec ' . $cmd, $spec, $pipes, $dir, $env, $options);
    if (!is_resource($proc)) {
        return 'cannot start ' . go2rtcLocalBin();
    }
    $status = proc_get_status($proc);
    $GLOBALS['go2rtc_local_proc'] = $proc; // не закрывать: proc_close ждёт завершения
    go2rtcLocalState('run', array('pid' => (int)$status['pid'], 'hash' => md5($cmd), 'started' => time()));
    for ($i = 0; $i < 20; $i++) {
        usleep(250000);
        if (go2rtcLocalPing()) {
            DebMes('go2rtc: started, pid ' . (int)$status['pid'], 'go2rtc');
            return '';
        }
        $st = proc_get_status($proc);
        if (!$st['running']) {
            return 'go2rtc exited (' . (int)$st['exitcode'] . '), see ' . $log;
        }
    }
    return 'go2rtc does not answer on port ' . $s['api_port'] . ', see ' . $log;
}

// Остановить go2rtc: просьба через API, затем завершение процесса
function go2rtcLocalStop()
{
    $s = go2rtcLocalSettings();
    $run = go2rtcLocalState('run');
    $ctx = stream_context_create(array('http' => array('method' => 'POST', 'timeout' => 2, 'ignore_errors' => true)));
    @file_get_contents('http://127.0.0.1:' . $s['api_port'] . '/api/exit?code=0', false, $ctx);
    $pid = !empty($run['pid']) ? (int)$run['pid'] : 0;
    for ($i = 0; $i < 20 && $pid && go2rtcLocalPidAlive($pid); $i++) {
        usleep(250000);
    }
    if ($pid && go2rtcLocalPidAlive($pid)) {
        if (go2rtcLocalWindows()) {
            go2rtcLocalExec('taskkill /F /PID ' . $pid);
        } elseif (function_exists('posix_kill')) {
            @posix_kill($pid, 15);
        } else {
            go2rtcLocalExec('kill ' . $pid);
        }
    }
    if (!empty($GLOBALS['go2rtc_local_proc']) && is_resource($GLOBALS['go2rtc_local_proc'])) {
        @proc_close($GLOBALS['go2rtc_local_proc']);
        $GLOBALS['go2rtc_local_proc'] = null;
    }
    go2rtcLocalState('run', array());
    DebMes('go2rtc: stopped', 'go2rtc');
}

// Команда циклу из панели: start | stop | restart (выполнит в течение нескольких секунд)
function go2rtcLocalControl($action = null)
{
    $file = go2rtcLocalDir() . '/control';
    if ($action === null) {
        $value = is_file($file) ? trim((string)@file_get_contents($file)) : '';
        @unlink($file);
        return $value;
    }
    go2rtcLocalPrepareDir();
    @file_put_contents($file, $action);
    return $action;
}

// Желаемое состояние: работать или нет (кнопки «Запустить» / «Остановить»)
function go2rtcLocalWanted($set = null)
{
    $state = go2rtcLocalState('state');
    if ($set === null) {
        return !isset($state['wanted']) || $state['wanted'];
    }
    $state['wanted'] = (bool)$set;
    go2rtcLocalState('state', $state);
    return $state['wanted'];
}

// Завести камеры модуля в работающем go2rtc через API (недостающие). Возвращает число заведённых
function go2rtcLocalRegister($force = false)
{
    $s = go2rtcLocalSettings();
    $have = json_decode((string)go2rtcRequest('GET', 'api/streams', array(), null, '', $code), true);
    if ($code != 200 || !is_array($have)) {
        return 0;
    }
    $added = 0;
    foreach (go2rtcLocalStreams() as $name => $src) {
        if (go2rtcLocalConfigOnly($src)) {
            continue; // в конфиге при запуске
        }
        if (isset($have[$name]) && !$force) {
            continue;
        }
        if (go2rtcLocalPut($name, $src) == '') {
            $added++;
        }
    }
    if ($added) {
        @unlink(ROOT . 'cms/cached/go2rtc_streams.json');
    }
    return $added;
}

// Завести (заменить) поток через API. '' или текст ошибки
function go2rtcLocalPut($name, $src)
{
    go2rtcRequest('DELETE', 'api/streams', array('src' => $name), null, '', $code);
    $answer = go2rtcRequest('PUT', 'api/streams', array('name' => $name, 'src' => $src), null, '', $code);
    // go2rtc без файла конфига отвечает 400 «config file disabled», но поток создаёт
    if ($code == 200 || stripos((string)$answer, 'config file disabled') !== false) {
        return '';
    }
    return trim((string)$answer) != '' ? trim((string)$answer) : 'HTTP ' . $code;
}

function go2rtcLocalDeleteStream($name)
{
    go2rtcRequest('DELETE', 'api/streams', array('src' => $name), null, '', $code);
    @unlink(ROOT . 'cms/cached/go2rtc_streams.json');
}

// Проверка источника: временный поток и кадр. array('ok' => bool, 'jpeg' => данные, 'error' => текст)
function go2rtcLocalTest($src)
{
    if ($src == '') {
        return array('ok' => false, 'error' => 'empty source');
    }
    if (go2rtcLocalApiRejects($src)) {
        return array('ok' => false, 'error' => go2rtcLang('GO2RTC_LOCAL_TEST_CONFIG'));
    }
    if (!go2rtcLocalPing()) {
        return array('ok' => false, 'error' => go2rtcLang('GO2RTC_LOCAL_NOT_RUNNING'));
    }
    $name = '__test_' . substr(md5(mt_rand() . microtime()), 0, 8);
    // ошибку источника go2rtc пишет в журнал (на кадр отвечает пустым 200) — запомнить, откуда читать
    $log = go2rtcLocalLogFile();
    clearstatcache();
    $log_from = is_file($log) ? filesize($log) : 0;
    $error = go2rtcLocalPut($name, $src);
    if ($error != '') {
        return array('ok' => false, 'error' => $error);
    }
    $cfg = go2rtcConfig();
    $jpeg = go2rtcRequest('GET', 'api/frame.jpeg', array('src' => $name), null, '', $code, max(8, $cfg['wait'] + 4), $type);
    go2rtcLocalDeleteStream($name);
    if ($code == 200 && strpos((string)$type, 'image/jpeg') === 0 && $jpeg != '') {
        return array('ok' => true, 'jpeg' => $jpeg);
    }
    $text = trim(strip_tags((string)$jpeg));
    clearstatcache();
    if (is_file($log) && filesize($log) > $log_from) {
        $fp = @fopen($log, 'rb');
        if ($fp) {
            fseek($fp, $log_from);
            $new = (string)fread($fp, 65536);
            fclose($fp);
            $errors = array();
            foreach (explode("\n", $new) as $line) {
                if (preg_match('/ (ERR|WRN) .*?error="(.*?)"( |$)/', $line, $m)) {
                    $errors[] = stripcslashes($m[2]);
                }
            }
            if (count($errors)) {
                $text = implode("\n", array_unique($errors));
            }
        }
    }
    return array('ok' => false, 'error' => $text != '' ? mb_substr($text, 0, 1200, 'UTF-8') : 'HTTP ' . $code);
}

// Шаг цикла (раз в несколько секунд): команды из панели, запуск, перезапуск при смене конфига, регистрация камер
function go2rtcLocalTick(&$last_register)
{
    $control = go2rtcLocalControl();
    if (!go2rtcLocal()) {
        if (go2rtcLocalPing() && !empty(go2rtcLocalState('run'))) {
            go2rtcLocalStop(); // режим сменили на внешний — свой go2rtc остановить
        }
        return;
    }
    if ($control == 'stop') {
        go2rtcLocalWanted(false);
        go2rtcLocalStop();
        return;
    }
    if ($control == 'start' || $control == 'restart') {
        go2rtcLocalWanted(true);
    }
    if (!go2rtcLocalWanted() || go2rtcLocalBin() == '' || !is_file(go2rtcLocalBin())) {
        return;
    }
    $cmd = go2rtcLocalCommand();
    $run = go2rtcLocalState('run');
    $alive = go2rtcLocalPing() != '';
    if ($alive && ($control == 'restart' || (!empty($run['hash']) && $run['hash'] !== md5($cmd)))) {
        DebMes('go2rtc: restart (' . ($control == 'restart' ? 'requested' : 'config changed') . ')', 'go2rtc');
        go2rtcLocalStop();
        $alive = false;
    }
    if (!$alive) {
        $error = go2rtcLocalStart();
        $state = go2rtcLocalState('state');
        $state['error'] = $error;
        go2rtcLocalState('state', $state);
        if ($error != '') {
            DebMes('go2rtc: ' . $error, 'go2rtc');
            return;
        }
        $last_register = 0;
    }
    if (time() - $last_register >= 30) {
        $last_register = time();
        go2rtcLocalRegister();
    }
}

// Импорт своего конфига go2rtc в файл данных встроенного (прежний — в .bak). $text — содержимое YAML.
// Порты, API, RTSP-пароль и ffmpeg.bin из файла перекроет модуль; потоки, пресеты ffmpeg, homekit, токены — остаются
function go2rtcLocalImport($text, &$error = '')
{
    $error = '';
    $text = (string)$text;
    if (trim($text) == '' || strlen($text) > 1024 * 1024 || strpos($text, ':') === false) {
        $error = go2rtcLang('GO2RTC_LOCAL_IMPORT_BAD');
        return false;
    }
    $s = go2rtcLocalSettings();
    go2rtcLocalPrepareDir();
    if (is_file($s['file_path'])) {
        // копия рядом; расширение — прежнее (go2rtc.yaml.php → go2rtc.yaml.<дата>.bak.php — тоже не читается по ссылке)
        $ext = preg_match('/\.php$/i', $s['file_path']) ? '.php' : '';
        @copy($s['file_path'], substr($s['file_path'], 0, strlen($s['file_path']) - strlen($ext)) . '.' . date('Ymd-His') . '.bak' . $ext);
    }
    if (go2rtcLocalInSite($s['file_path'])) {
        $text = go2rtcLocalGuard($text);
    }
    if (@file_put_contents($s['file_path'], $text) === false) {
        $error = 'cannot write ' . $s['file_path'];
        return false;
    }
    $config = go2rtcModuleConfig();
    $config['GO2RTC_FILE'] = '1';
    go2rtcModuleConfigSave($config);
    DebMes('go2rtc: config imported into ' . $s['file_path'] . ' (' . strlen($text) . ' bytes)', 'go2rtc');
    go2rtcLocalControl('restart');
    return true;
}

// Файл внутри папки сайта (его может отдать веб-сервер)
function go2rtcLocalInSite($path)
{
    $root = str_replace('\\', '/', rtrim(ROOT, '/\\')) . '/';
    return stripos(str_replace('\\', '/', (string)$path), $root) === 0;
}

// Первая строка-заглушка для файлов в папке сайта: в YAML — комментарий, по ссылке — PHP, который сразу выходит
function go2rtcLocalGuard($text)
{
    $text = (string)$text;
    return (strpos($text, GO2RTC_LOCAL_GUARD) === 0) ? $text : GO2RTC_LOCAL_GUARD . "\n" . $text;
}

// Заглушка в начале файла данных (её могли стереть в редакторе go2rtc) — перед запуском go2rtc
function go2rtcLocalGuardFile($path)
{
    if (!go2rtcLocalInSite($path) || !is_file($path)) {
        return;
    }
    $text = (string)@file_get_contents($path);
    if (strpos($text, GO2RTC_LOCAL_GUARD) !== 0) {
        @file_put_contents($path, go2rtcLocalGuard($text));
    }
}

// Свой путь к файлу данных: абсолютный; внутри сайта — только cms/go2rtc/*.php (с заглушкой по ссылке не читается)
function go2rtcLocalFilePathOk($path)
{
    $path = str_replace('\\', '/', (string)$path);
    if (!preg_match('#^(/|[a-z]:/)#i', $path) || strpos($path, '..') !== false) {
        return false;
    }
    if (go2rtcLocalInSite($path) && (stripos($path, str_replace('\\', '/', go2rtcLocalDir()) . '/') !== 0 || !preg_match('/\.php$/i', $path))) {
        return false;
    }
    return true;
}

// Конфиг внешнего go2rtc (адрес и логин из настроек внешнего режима) — для импорта
function go2rtcLocalExternalConfig(&$error = '')
{
    $config = go2rtcModuleConfig();
    $url = isset($config['GO2RTC_URL']) ? rtrim((string)$config['GO2RTC_URL'], '/') : '';
    $error = '';
    if ($url == '') {
        $error = go2rtcLang('GO2RTC_LOCAL_IMPORT_NO_URL');
        return '';
    }
    $headers = "User-Agent: MajorDoMo-go2rtc\r\n";
    if (!empty($config['GO2RTC_USER'])) {
        $headers .= 'Authorization: Basic ' . base64_encode($config['GO2RTC_USER'] . ':' . (isset($config['GO2RTC_PASSWORD']) ? $config['GO2RTC_PASSWORD'] : '')) . "\r\n";
    }
    $ctx = stream_context_create(array('http' => array('timeout' => 5, 'ignore_errors' => true, 'header' => $headers)));
    $body = @file_get_contents($url . '/api/config', false, $ctx);
    $code = (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) ? (int)$m[1] : 0;
    if ($code != 200 || trim((string)$body) == '') {
        $error = sprintf(go2rtcLang('GO2RTC_LOCAL_IMPORT_FAILED'), $url, $code);
        return '';
    }
    return (string)$body;
}

// Потоки из файла данных (заведены в go2rtc им самим или через его веб-интерфейс): имена
function go2rtcLocalFileStreams()
{
    $s = go2rtcLocalSettings();
    if (!$s['file'] || !is_file($s['file_path'])) {
        return array();
    }
    // ключи первого уровня внутри streams: (go2rtc пишет файл блочным YAML)
    $names = array();
    $in = false;
    $indent = 0;
    foreach (preg_split('/\r?\n/', (string)@file_get_contents($s['file_path'])) as $line) {
        if (preg_match('/^streams:\s*$/', $line)) {
            $in = true;
            continue;
        }
        if ($in && preg_match('/^\S/', $line)) {
            $in = false;
        }
        if ($in && preg_match('/^(\s+)([^\s#\-][^:]*):(\s|$)/', $line, $m) && (!$indent || strlen($m[1]) == $indent)) {
            $indent = strlen($m[1]);
            $names[] = trim($m[2], " '\"");
        }
    }
    return $names;
}

// Состояние для панели
function go2rtcLocalStatus()
{
    $state = go2rtcLocalState('state');
    $run = go2rtcLocalState('run');
    list($ffmpeg, $ffmpeg_version) = go2rtcLocalFfmpeg();
    $platform = go2rtcLocalPlatform();
    return array(
        'installed' => !empty($state['version']) && go2rtcLocalBin() != '' && is_file(go2rtcLocalBin()) ? $state['version'] : '',
        'asset' => !empty($platform['asset']) ? $platform['asset'] : '',
        'platform_error' => !empty($platform['error']) ? $platform['error'] : '',
        'running' => go2rtcLocalPing(),
        'pid' => !empty($run['pid']) ? (int)$run['pid'] : 0,
        'started' => !empty($run['started']) ? (int)$run['started'] : 0,
        'wanted' => go2rtcLocalWanted(),
        'error' => !empty($state['error']) ? $state['error'] : '',
        'ffmpeg' => $ffmpeg,
        'ffmpeg_version' => $ffmpeg_version,
        'cycle' => (int)getGlobal('ThisComputer.cycle_go2rtcRun'),
        'update' => !empty($state['version']) && $state['version'] !== GO2RTC_LOCAL_VERSION,
        'file' => go2rtcLocalSettings()['file'],
    );
}

// Журнал go2rtc: .php с заглушкой — по ссылке не читается
function go2rtcLocalLogFile()
{
    return go2rtcLocalDir() . '/go2rtc.log.php';
}

// Последние строки журнала go2rtc
function go2rtcLocalLog($lines = 40)
{
    $file = go2rtcLocalLogFile();
    if (!is_file($file)) {
        return '';
    }
    $size = filesize($file);
    $fp = @fopen($file, 'rb');
    if (!$fp) {
        return '';
    }
    fseek($fp, max(0, $size - 16384));
    $text = (string)fread($fp, 16384);
    fclose($fp);
    $rows = explode("\n", trim(str_replace(GO2RTC_LOCAL_GUARD, '', $text)));
    return implode("\n", array_slice($rows, -$lines));
}
