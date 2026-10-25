<?php
/*
 * Камеры через go2rtc (https://github.com/AlexxIT/go2rtc): потоки, снимки, показ на терминалах, права,
 * временный доступ и гостевые ссылки, WebTorrent. Функции для других модулей («Сообщения», Lovelace) и сценариев.
 *
 * Своих камер у MajorDoMo нет — потоки заводятся в go2rtc, модуль берёт из него только имена (GET /api/streams).
 * Адреса камер (в go2rtc — с паролями) дальше сервера не уходят. В go2rtc всегда передаётся только имя
 * потока из этого списка: параметр src в go2rtc принимает и ссылку (создаёт поток на лету), поэтому текст
 * от пользователя туда не попадает.
 *
 * Настройки — конфигурация модуля go2rtc (панель управления → «Камеры (go2rtc)»):
 *   GO2RTC_URL, GO2RTC_USER, GO2RTC_PASSWORD — API для сервера (пусто — камер нет). С той же машины
 *     go2rtc пароль не спрашивает (кроме local_auth: true);
 *   GO2RTC_DEVICE_URL — адрес go2rtc для устройств (пусто — адрес сервера в сети и порт из GO2RTC_URL);
 *   GO2RTC_RTSP_PORT, GO2RTC_RTSP_USER, GO2RTC_RTSP_PASSWORD — RTSP-сервер go2rtc (свой пароль, только просмотр);
 *   GO2RTC_WEBTORRENT, GO2RTC_WT_MINUTES — внешний просмотр через WebTorrent и срок ссылки;
 *   GO2RTC_WAIT — сколько ждать первый кадр камеры, с (снимок, начало просмотра; пусто — 6);
 *   GO2RTC_DEFAULT_ACCESS — камеры без правила «Контроля доступа»: admin | all; SECRET — ключ подписи ссылок.
 * Права на камеры — «Контроль доступа» ядра (security_rules, тип go2rtc); камеры — таблица go2rtc_cameras.
 * Временный доступ, гостевые ссылки и ссылки WebTorrent — таблица go2rtc_links.
 *
 * Кадр и WebRTC для браузеров и устройств — подписанные ссылки api.php/module/go2rtc/frame|whep?src=…&exp=…&sig=…
 * (go2rtcSignedUrl): права проверяет тот, кто выдаёт ссылку; модуль — подпись и срок. Пароль API go2rtc остаётся
 * на сервере: видео WebRTC идёт с go2rtc напрямую, через сервер — только согласование.
 */

if (!defined('GO2RTC_API')) {
    define('GO2RTC_API', 1);
}

// Конфигурация модуля go2rtc (как module::getConfig(), без объекта модуля)
function go2rtcModuleConfig()
{
    $rec = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='go2rtc'");
    $config = !empty($rec['DATA']) ? @unserialize($rec['DATA']) : array();
    return is_array($config) ? $config : array();
}

function go2rtcModuleConfigSave($config)
{
    $rec = SQLSelectOne("SELECT * FROM project_modules WHERE NAME='go2rtc'");
    if (!empty($rec['ID'])) {
        $rec['DATA'] = serialize($config);
        SQLUpdate('project_modules', $rec);
    }
}

// Переводы модуля: languages/go2rtc_<язык>.php и go2rtc_default.php (английский)
function go2rtcLoadLanguage()
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;
    if (defined('SETTINGS_SITE_LANGUAGE') && SETTINGS_SITE_LANGUAGE != '' && file_exists(ROOT . 'languages/go2rtc_' . SETTINGS_SITE_LANGUAGE . '.php')) {
        include_once(ROOT . 'languages/go2rtc_' . SETTINGS_SITE_LANGUAGE . '.php');
    }
    if (file_exists(ROOT . 'languages/go2rtc_default.php')) {
        include_once(ROOT . 'languages/go2rtc_default.php');
    }
}

function go2rtcLang($key)
{
    go2rtcLoadLanguage();
    return defined('LANG_' . $key) ? constant('LANG_' . $key) : $key;
}

// --- Связь с модулем «Терминалы» (показ камеры на устройстве): только если его функции есть ---

function go2rtcAddon($terminal_rec)
{
    if (is_file(DIR_MODULES . 'terminals/terminals.class.php')) {
        include_once(DIR_MODULES . 'terminals/terminals.class.php');
    }
    if (function_exists('terminalsQueueAddon')) {
        return terminalsQueueAddon($terminal_rec);
    }
    $type = isset($terminal_rec['TTS_TYPE']) ? preg_replace('/[^\w]/', '', (string)$terminal_rec['TTS_TYPE']) : '';
    $file = DIR_MODULES . 'terminals/tts/' . $type . '.addon.php';
    if ($type == '' || !is_file($file)) {
        return null;
    }
    include_once DIR_MODULES . 'terminals/tts_addon.class.php';
    include_once $file;
    return class_exists($type) ? new $type($terminal_rec) : null;
}

function go2rtcReachable($terminal_rec, $tts)
{
    return function_exists('terminalsReachable') ? terminalsReachable($terminal_rec, $tts) : true;
}

function go2rtcUsesQueue($tts)
{
    return function_exists('terminalsQueueUsed') && function_exists('terminalsQueueCall') && terminalsQueueUsed($tts);
}

function go2rtcDeviceLabel($terminal_rec, $tts)
{
    if (function_exists('terminalsDeviceLabel')) {
        return terminalsDeviceLabel($terminal_rec, $tts);
    }
    return (isset($terminal_rec['HOST']) && trim((string)$terminal_rec['HOST']) != '') ? trim((string)$terminal_rec['HOST']) : '-';
}

// Текст для журнала без паролей и подписей в ссылках
function go2rtcLogSafe($text)
{
    $text = preg_replace('~(\b[a-z][a-z0-9+.-]*://[^/:@\s"\']*):[^/@\s"\']*@~i', '$1:***@', (string)$text);
    return preg_replace('/([?&#](?:token|sig|key|password|pass|pwd)=)[^&\s"\']+/i', '$1***', $text);
}

// Настройки go2rtc (с умолчаниями)
function go2rtcConfig()
{
    $config = go2rtcModuleConfig();
    $get = function ($key, $default = '') use ($config) {
        return (isset($config[$key]) && $config[$key] !== '') ? (string)$config[$key] : $default;
    };
    return array(
        'url' => rtrim($get('GO2RTC_URL'), '/'),
        'user' => $get('GO2RTC_USER'),
        'password' => $get('GO2RTC_PASSWORD'),
        'device_url' => rtrim($get('GO2RTC_DEVICE_URL'), '/'),
        'rtsp_port' => (int)$get('GO2RTC_RTSP_PORT', '8554'),
        'rtsp_user' => $get('GO2RTC_RTSP_USER'),
        'rtsp_password' => $get('GO2RTC_RTSP_PASSWORD'),
        'webtorrent' => $get('GO2RTC_WEBTORRENT') == '1',
        'wt_minutes' => max(1, min(1440, (int)$get('GO2RTC_WT_MINUTES', '10'))),
        // удалённые и облачные камеры просыпаются дольше; не больше 25 с — запрос страницы укладывается в 30 с PHP
        'wait' => max(2, min(25, (int)$get('GO2RTC_WAIT', '6'))),
    );
}

// Камеры настроены (задан адрес go2rtc)
function go2rtcEnabled()
{
    $cfg = go2rtcConfig();
    return $cfg['url'] != '';
}

// Запрос к API go2rtc с сервера. Возвращает тело ответа; $code — HTTP-код (0 — нет связи), $type — Content-Type
function go2rtcRequest($method, $path, $query = array(), $body = null, $content_type = '', &$code = 0, $timeout = 5, &$type = '')
{
    $cfg = go2rtcConfig();
    $code = 0;
    $type = '';
    if ($cfg['url'] == '' || !function_exists('curl_init')) {
        return '';
    }
    $url = $cfg['url'] . '/' . ltrim($path, '/') . (count($query) ? '?' . http_build_query($query) : '');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    if ($cfg['user'] != '') {
        curl_setopt($ch, CURLOPT_USERPWD, $cfg['user'] . ':' . $cfg['password']);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    if ($content_type != '') {
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: ' . $content_type));
    }
    $result = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return is_string($result) ? $result : '';
}

// Имена потоков go2rtc (по алфавиту); go2rtc не ответил — пустой список. Список запоминается на минуту
// (cms/cached/go2rtc_streams.json): страницы обновляются часто, а выключенный go2rtc иначе задерживал бы каждое обновление
function go2rtcStreams()
{
    static $names = null;
    if ($names !== null) {
        return $names;
    }
    $cache_file = ROOT . 'cms/cached/go2rtc_streams.json';
    $cfg = go2rtcConfig();
    $cache = is_file($cache_file) ? json_decode((string)@file_get_contents($cache_file), true) : null;
    if (is_array($cache) && isset($cache['time'], $cache['url'], $cache['names']) && $cache['url'] === $cfg['url']
        && $cache['time'] > time() - 60 && is_array($cache['names'])) {
        $names = $cache['names'];
        return $names;
    }
    $names = array();
    $data = json_decode(go2rtcRequest('GET', 'api/streams', array(), null, '', $code), true);
    if ($code == 200 && is_array($data)) {
        foreach (array_keys($data) as $name) {
            // любое имя go2rtc (пробелы, скобки, кириллица — в ссылках оно кодируется), кроме управляющих
            // символов; до 100 символов (столько хранят ссылки и переписка «Сообщений»)
            if (is_string($name) && preg_match('/^[^\x00-\x1f\x7f]{1,100}$/u', $name)) {
                $names[] = $name;
            }
        }
        natcasesort($names);
        $names = array_values($names);
    } elseif ($code != 200) {
        DebMes('go2rtc: streams list failed (HTTP ' . $code . ')', 'go2rtc');
    }
    @file_put_contents($cache_file, json_encode(array('time' => time(), 'url' => $cfg['url'], 'names' => $names)));
    return $names;
}

function go2rtcHas($name)
{
    return is_string($name) && $name != '' && in_array($name, go2rtcStreams(), true);
}

// Адрес go2rtc для устройств: настройка или адрес сервера в сети с портом API
function go2rtcDeviceBase()
{
    $cfg = go2rtcConfig();
    if ($cfg['device_url'] != '') {
        return $cfg['device_url'];
    }
    $parts = parse_url($cfg['url']);
    $host = isset($parts['host']) ? $parts['host'] : '';
    if ($host == '' || $host == '127.0.0.1' || $host == 'localhost' || $host == '::1') {
        $ip = function_exists('getLocalIp') ? getLocalIp() : '';
        $host = $ip ? $ip : $host;
    }
    return (isset($parts['scheme']) ? $parts['scheme'] : 'http') . '://' . $host . (isset($parts['port']) ? ':' . (int)$parts['port'] : ':1984');
}

// Ссылка на поток для устройства: rtsp, mp4, hls, mjpeg, frame (снимок), webrtc (согласование через сервер,
// подписанная ссылка на час)
function go2rtcUrl($name, $format = 'rtsp')
{
    if (!go2rtcHas($name)) {
        return '';
    }
    $cfg = go2rtcConfig();
    $base = go2rtcDeviceBase();
    $src = rawurlencode($name);
    if ($format == 'rtsp') {
        $host = (string)parse_url($base, PHP_URL_HOST);
        $auth = ($cfg['rtsp_user'] != '') ? rawurlencode($cfg['rtsp_user']) . ':' . rawurlencode($cfg['rtsp_password']) . '@' : '';
        return 'rtsp://' . $auth . $host . ':' . $cfg['rtsp_port'] . '/' . $src;
    }
    if ($format == 'mp4') {
        // fMP4 по HTTP: плееры без RTSP (VLC без live555, Chromecast, DLNA)
        return $base . '/api/stream.mp4?src=' . $src;
    }
    if ($format == 'hls') {
        return $base . '/api/stream.m3u8?src=' . $src . '&mp4';
    }
    if ($format == 'mjpeg') {
        return $base . '/api/stream.mjpeg?src=' . $src;
    }
    if ($format == 'frame') {
        return $base . '/api/frame.jpeg?src=' . $src;
    }
    if ($format == 'webrtc') {
        // согласование WebRTC — через сервер (пароль API устройству не нужен), видео — с go2rtc напрямую
        return go2rtcSignedUrl('whep', $name, time() + 3600, true);
    }
    return '';
}

// Секрет модуля для подписи ссылок (создаётся при первом обращении)
function go2rtcSecret()
{
    $config = go2rtcModuleConfig();
    if (empty($config['SECRET']) || strlen((string)$config['SECRET']) < 32) {
        $config['SECRET'] = function_exists('random_bytes') ? bin2hex(random_bytes(32)) : sha1(uniqid('', true) . mt_rand()) . md5(mt_rand() . microtime());
        go2rtcModuleConfigSave($config);
    }
    return (string)$config['SECRET'];
}

// Подпись: поток и срок — ссылка на кадр или WebRTC этой камеры действует до $exp
function go2rtcSign($name, $exp)
{
    return hash_hmac('sha256', (string)$name . '|' . (int)$exp, go2rtcSecret());
}

function go2rtcSignOk($name, $exp, $sig)
{
    return (int)$exp > time() && is_string($sig) && hash_equals(go2rtcSign($name, (int)$exp), $sig);
}

// Адрес сервера MajorDoMo для устройств: BASE_URL, а 127.0.0.1 / localhost в нём — адрес сервера в сети
function go2rtcServerBase()
{
    $server = defined('BASE_URL') ? BASE_URL : '';
    $ip = function_exists('getLocalIp') ? getLocalIp() : '';
    if ($ip && preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?#i', $server, $m)) {
        $server = 'http://' . $ip . (isset($m[2]) ? $m[2] : '');
    }
    return rtrim($server, '/');
}

// Подписанная ссылка модуля на кадр ('frame') или согласование WebRTC ('whep') камеры до $exp.
// Права проверяет тот, кто ссылку выдаёт (страница «Сообщения», панель, Lovelace); модуль — только подпись и срок.
// $absolute — с адресом сервера (для устройств), иначе от корня сайта (для страницы в браузере)
function go2rtcSignedUrl($op, $name, $exp, $absolute = false)
{
    $path = ROOTHTML . 'api.php/module/go2rtc/' . (($op == 'whep') ? 'whep' : 'frame')
        . '?src=' . rawurlencode($name) . '&exp=' . (int)$exp . '&sig=' . go2rtcSign($name, (int)$exp);
    return ($absolute ? go2rtcServerBase() : '') . $path;
}

// Снимок потока (JPEG) или пустая строка; $width — ширина (0 — как у камеры). cache — не дёргать камеру чаще раза в 5 с
function go2rtcFrame($name, $width = 0)
{
    if (!go2rtcHas($name) || go2rtcDown($name)) {
        return '';
    }
    $query = array('src' => $name, 'cache' => '5s');
    if ($width > 0) {
        $query['width'] = (int)$width;
    }
    // камера просыпается (go2rtc подключается к ней) обычно за 1–3 с, удалённая — дольше; не ответила
    // за GO2RTC_WAIT (по умолчанию 6 с) — считаем недоступной
    $cfg = go2rtcConfig();
    $jpeg = go2rtcRequest('GET', 'api/frame.jpeg', $query, null, '', $code, $cfg['wait'], $type);
    $ok = ($code == 200 && strpos($type, 'image/jpeg') === 0 && $jpeg != '');
    go2rtcDown($name, !$ok);
    if ($ok) {
        // последний кадр — для мгновенного показа при открытии (go2rtcFrameLast); на диск не чаще раза в 10 минут (SD-карты)
        $file = go2rtcFrameLastFile($name, $width);
        if (!is_file($file) || filemtime($file) < time() - 600) {
            if (!is_dir(dirname($file))) {
                @mkdir(dirname($file), 0777, true);
            }
            @file_put_contents($file, $jpeg);
        }
    }
    return $ok ? $jpeg : '';
}

// Последний полученный кадр камеры (сохраняет go2rtcFrame) — сразу, без обращения к камере: просмотрщик показывает его
// первым, пока камера просыпается, вместо чёрного экрана. Нет — ''.
function go2rtcFrameLast($name, $width = 0)
{
    $file = go2rtcFrameLastFile($name, $width);
    return is_file($file) ? (string)@file_get_contents($file) : '';
}

function go2rtcFrameLastFile($name, $width = 0)
{
    return ROOT . 'cms/cached/go2rtc/last_' . md5($name) . '_' . (int)$width . '.jpg';
}

// Недоступная камера: снимки не получаются 20 с подряд (с первой неудачи, без удачных между ними) — следующие
// 30 с к ней не обращаемся (ответ сразу), чтобы страница и показ не ждали её каждый раз. Короткая задержка —
// ещё не недоступна: медленная камера иногда отдаёт кадр дольше ожидания, просыпается после простоя.
// $set === true — неудача, false — снимок получился (сброс).
// cms/cached/go2rtc_down.json: имя => время отметки; «?имя» => время первой неудачи
function go2rtcDown($name, $set = null)
{
    $file = ROOT . 'cms/cached/go2rtc_down.json';
    $down = is_file($file) ? json_decode((string)@file_get_contents($file), true) : array();
    $down = is_array($down) ? $down : array();
    if ($set === false) {
        if (isset($down[$name]) || isset($down['?' . $name])) {
            unset($down[$name], $down['?' . $name]);
            @file_put_contents($file, json_encode($down));
        }
        return false;
    }
    if ($set === true) {
        $marked = false;
        if (!isset($down['?' . $name]) || $down['?' . $name] <= time() - 120) {
            $down['?' . $name] = time();
        } elseif ($down['?' . $name] <= time() - 20) {
            unset($down['?' . $name]);
            $down[$name] = time();
            $marked = true;
        }
        foreach ($down as $key => $time) {
            if ($time < time() - (($key[0] == '?') ? 120 : 30)) {
                unset($down[$key]);
            }
        }
        @file_put_contents($file, json_encode($down));
        DebMes('go2rtc: camera ' . ($marked ? 'not responding (skipped for 30 s): ' : 'slow (no frame in time): ') . $name, 'go2rtc');
        return $marked;
    }
    return isset($down[$name]) && $down[$name] > time() - 30;
}

// Снимок потока — файлом в $dir (от веб-корня; «Сообщения» передают свой каталог вложений); путь от веб-корня или ''
function go2rtcSnapshot($name, $dir = 'cms/cached/go2rtc')
{
    $jpeg = go2rtcFrame($name);
    $dir = trim(str_replace('\\', '/', (string)$dir), '/');
    if ($jpeg == '' || $dir == '' || strpos($dir, '..') !== false) {
        return '';
    }
    if (!is_dir(ROOT . $dir)) {
        @mkdir(ROOT . $dir, 0777, true);
    }
    $file = $dir . '/' . md5(uniqid('', true) . mt_rand()) . '.jpg';
    return @file_put_contents(ROOT . $file, $jpeg) ? $file : '';
}

// Показать поток на терминале на $duration секунд. PiPup — окно видео (RTSP или WebRTC — настройка «Камеры»
// в озвучке pipup), повторный вызов продлевает то же окно; mediaplayer — плеером через очередь (RTSP или HTTP MP4 — поле «Камеры» в озвучке),
// после показа — остановка или возврат музыки; остальные — снимок и подпись. Возвращает, показано ли
function go2rtcShow($terminal_rec, $name, $duration = 60, $title = '', $level = 0)
{
    if (!go2rtcHas($name) || empty($terminal_rec['ID'])) {
        return false;
    }
    $duration = max(5, min(3600, (int)$duration));
    $title = ($title != '') ? $title : go2rtcLang('GO2RTC_CAMERA_TITLE') . ': ' . go2rtcCameraTitle($name);
    $tts = ($terminal_rec['CANTTS'] && $terminal_rec['TTS_TYPE'] != '') ? go2rtcAddon($terminal_rec) : null;
    if (is_object($tts) && !go2rtcReachable($terminal_rec, $tts)) {
        return false;
    }
    if (is_object($tts) && method_exists($tts, 'cameraFormat')) {
        // pipup: окно потока сразу, поверх (потоки в очередь не ставятся — окно продлевается по тому же id)
        $format = $tts->cameraFormat();
        $url = go2rtcUrl($name, $format);
        $poster = go2rtcUrl($name, 'frame');
        // в журнал — ссылки, которые получает устройство (пароль, ключ и подпись — ***)
        DebMes('go2rtc: show ' . $name . ' (' . $format . ') on ' . $terminal_rec['TITLE'] . ' (' . go2rtcDeviceLabel($terminal_rec, $tts) . '): '
            . go2rtcLogSafe($url) . ', poster ' . go2rtcLogSafe($poster), 'go2rtc');
        return (bool)$tts->sendVideo($url, '', $title, ($format == 'webrtc') ? 'whep' : 'video', array(
            'duration' => $duration, 'id' => 'cam_' . $name, 'muted' => false, 'liveBadge' => true,
            'poster' => $poster));
    }
    if (is_object($tts) && method_exists($tts, 'sendVideo') && method_exists($tts, 'canShowVideo') && $tts->canShowVideo()) {
        // плеер с экраном: через очередь, держит терминал на время показа (формат — поле «Камеры» mediaplayer)
        $format = method_exists($tts, 'streamFormat') ? $tts->streamFormat() : 'rtsp';
        $url = go2rtcUrl($name, $format);
        DebMes('go2rtc: show ' . $name . ' (' . $format . ') on ' . $terminal_rec['TITLE'] . ' (' . go2rtcDeviceLabel($terminal_rec, $tts) . '): '
            . go2rtcLogSafe($url), 'go2rtc');
        $args = array($url, '', $title, 'stream', array('duration' => $duration));
        if (!go2rtcUsesQueue($tts)) {
            return (bool)call_user_func_array(array($tts, 'sendVideo'), $args);
        }
        return (bool)terminalsQueueCall($terminal_rec, 'sendVideo', $args, array('level' => $level, 'busy' => $duration));
    }
    return false;
}


// --- Временный доступ, гостевые ссылки, WebTorrent (таблица go2rtc_links) ---
// KIND: chat — участники чата (MEMBERS: ключи участников — имена терминалов, user:ID) смотрят камеру на странице
// «Сообщения» до EXPIRES, без отметки в правах; guest — гостевая ссылка: страница просмотра без входа
// (api.php/module/go2rtc/guest/<id>: снимки и WebRTC через сервер); webtorrent — внешний просмотр через WebTorrent
// (одна ссылка на поток). Закрыть — автор (BY_KEY) или администратор. Устаревшие убирает MINUTELY

// Строка таблицы — в массив, как его ждут вызывающие модули
function go2rtcLinkRow($row)
{
    $members = json_decode((string)$row['MEMBERS'], true);
    return array('id' => $row['LINK_ID'], 'cam' => $row['CAM'], 'kind' => $row['KIND'], 'members' => is_array($members) ? $members : array(),
        'by' => $row['BY_KEY'], 'by_title' => $row['BY_TITLE'], 'created' => (int)$row['CREATED'], 'expires' => (int)$row['EXPIRES']);
}

// Действующие ссылки (кроме WebTorrent): id => ссылка
function go2rtcLinks()
{
    $links = array();
    foreach (SQLSelect("SELECT * FROM go2rtc_links WHERE KIND IN ('chat','guest') AND EXPIRES>" . time() . " ORDER BY ID") as $row) {
        $links[$row['LINK_ID']] = go2rtcLinkRow($row);
    }
    return $links;
}

// Действующая ссылка или пустой массив
function go2rtcLinkGet($id)
{
    $id = strtolower((string)$id);
    if (!preg_match('/^[0-9a-f]{32}$/', $id)) {
        return array();
    }
    $row = SQLSelectOne("SELECT * FROM go2rtc_links WHERE LINK_ID='" . DBSafe($id) . "' AND KIND IN ('chat','guest') AND EXPIRES>" . time());
    return !empty($row['ID']) ? go2rtcLinkRow($row) : array();
}

// Создать ссылку на $minutes минут (1 мин — 30 дней): $kind — chat | guest; $members — участники (для chat);
// $by, $by_title — кто создал. Возвращает id
function go2rtcLinkCreate($cam, $kind, $minutes, $members, $by, $by_title)
{
    $id = function_exists('random_bytes') ? bin2hex(random_bytes(16)) : md5(uniqid('', true) . mt_rand());
    $minutes = max(1, min(43200, (int)$minutes));
    $rec = array('LINK_ID' => $id, 'CAM' => (string)$cam, 'KIND' => ($kind == 'guest') ? 'guest' : 'chat',
        'MEMBERS' => json_encode(array_values(array_unique(array_map('strval', (array)$members)))), 'BY_KEY' => (string)$by,
        'BY_TITLE' => (string)$by_title, 'CREATED' => time(), 'EXPIRES' => time() + $minutes * 60);
    SQLInsert('go2rtc_links', $rec);
    DebMes('go2rtc: ' . (($kind == 'guest') ? 'guest link' : 'chat access') . ' ' . substr($id, 0, 6) . '… to ' . $cam
        . ' by ' . $by_title . ' for ' . $minutes . ' min', 'go2rtc');
    return $id;
}

// Закрыть ссылку (перестаёт действовать сразу)
function go2rtcLinkClose($id)
{
    $link = go2rtcLinkGet($id);
    if (empty($link['cam'])) {
        return false;
    }
    SQLExec("DELETE FROM go2rtc_links WHERE LINK_ID='" . DBSafe($link['id']) . "'");
    DebMes('go2rtc: link ' . substr($link['id'], 0, 6) . '… closed', 'go2rtc');
    return true;
}

// Камеры, открытые этим участникам (ключи терминала и пользователя) по ссылкам «участникам чата»: поток => до какого времени
function go2rtcLinkCameras($me_keys)
{
    $result = array();
    foreach (go2rtcLinks() as $link) {
        if ($link['kind'] == 'chat' && count(array_intersect((array)$me_keys, (array)$link['members']))
            && (!isset($result[$link['cam']]) || $result[$link['cam']] < (int)$link['expires'])) {
            $result[$link['cam']] = (int)$link['expires'];
        }
    }
    return $result;
}

// Адрес гостевой страницы: по адресу текущего запроса (как его видит браузер), иначе BASE_URL
function go2rtcGuestUrl($id)
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) == 'https');
    $base = !empty($_SERVER['HTTP_HOST']) ? ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] : (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '');
    return $base . ROOTHTML . 'api.php/module/go2rtc/guest/' . strtolower((string)$id);
}

// Внешний просмотр через WebTorrent: ссылка на поток (создаётся или продлевается). Пустой массив — выключено
// или go2rtc не ответил. Ссылка живёт GO2RTC_WT_MINUTES после последнего запроса (чистка — MINUTELY)
function go2rtcShare($name)
{
    $cfg = go2rtcConfig();
    if (!$cfg['webtorrent'] || !go2rtcHas($name)) {
        return array();
    }
    $data = json_decode(go2rtcRequest('GET', 'api/webtorrent', array('src' => $name), null, '', $code), true);
    if ($code != 200 || empty($data['share'])) {
        $data = json_decode(go2rtcRequest('POST', 'api/webtorrent', array('src' => $name), '', '', $code), true);
    }
    if (empty($data['share']) || empty($data['pwd'])) {
        DebMes('go2rtc: webtorrent share failed for ' . $name . ' (HTTP ' . $code . ')', 'go2rtc');
        return array();
    }
    $expires = time() + $cfg['wt_minutes'] * 60;
    $row = SQLSelectOne("SELECT * FROM go2rtc_links WHERE KIND='webtorrent' AND CAM='" . DBSafe($name) . "'");
    if (!empty($row['ID'])) {
        $row['EXPIRES'] = $expires;
        SQLUpdate('go2rtc_links', $row);
    } else {
        $row = array('LINK_ID' => md5('webtorrent|' . $name), 'CAM' => $name, 'KIND' => 'webtorrent', 'MEMBERS' => '[]',
            'BY_KEY' => '', 'BY_TITLE' => '', 'CREATED' => time(), 'EXPIRES' => $expires);
        SQLInsert('go2rtc_links', $row);
    }
    return array(
        'url' => 'https://go2rtc.org/webtorrent/#share=' . rawurlencode($data['share']) . '&pwd=' . rawurlencode($data['pwd']) . '&media=video+audio',
        'expires' => $expires,
    );
}

// Закрыть ссылку WebTorrent на поток (ссылка перестаёт работать у всех)
function go2rtcUnshare($name)
{
    go2rtcRequest('DELETE', 'api/webtorrent', array('src' => $name), null, '', $code);
    SQLExec("DELETE FROM go2rtc_links WHERE KIND='webtorrent' AND CAM='" . DBSafe($name) . "'");
    return $code == 200 || $code == 404;
}

// Действующие ссылки WebTorrent: имя потока => до какого времени
function go2rtcShares()
{
    $result = array();
    foreach (SQLSelect("SELECT CAM, EXPIRES FROM go2rtc_links WHERE KIND='webtorrent' AND EXPIRES>" . time()) as $row) {
        $result[$row['CAM']] = (int)$row['EXPIRES'];
    }
    return $result;
}

// MINUTELY: закрыть ссылки WebTorrent, срок которых вышел, убрать устаревший временный доступ
function go2rtcCleanup()
{
    foreach (SQLSelect("SELECT CAM FROM go2rtc_links WHERE KIND='webtorrent' AND EXPIRES<=" . time()) as $row) {
        go2rtcUnshare($row['CAM']);
    }
    SQLExec("DELETE FROM go2rtc_links WHERE KIND IN ('chat','guest') AND EXPIRES<=" . time());
}

// WebRTC: согласование (WHEP) через сервер — предложение SDP, ответ go2rtc; выводит ответ и завершает запрос.
// Само видео идёт с go2rtc напрямую; пароль API go2rtc остаётся на сервере
function go2rtcWhepProxy($name)
{
    $type_in = isset($_SERVER['CONTENT_TYPE']) ? (string)$_SERVER['CONTENT_TYPE'] : 'application/sdp';
    $type_in = (stripos($type_in, 'json') !== false) ? 'application/json' : 'application/sdp';
    // камера недавно не ответила — сразу отказ (браузер покажет снимок «нет связи», PHP не ждёт)
    if (go2rtcDown($name)) {
        header('HTTP/1.1 504 Gateway Timeout');
        exit;
    }
    // ответ go2rtc — после подключения к камере: ожидание камеры (GO2RTC_WAIT) и 2 с
    $go2rtc = go2rtcConfig();
    $answer = go2rtcRequest('POST', 'api/webrtc', array('src' => $name), (string)file_get_contents('php://input'),
        $type_in, $code, $go2rtc['wait'] + 2, $type_out);
    header('HTTP/1.1 ' . (($code >= 200 && $code < 300) ? $code . ' OK' : '502 Bad Gateway'));
    header('Content-Type: ' . (($type_out != '') ? $type_out : $type_in));
    echo $answer;
    exit;
}

// --- Права на камеры: «Контроль доступа» ядра (security_rules), тип объекта go2rtc, ID — камера из go2rtc_cameras ---
// Правило камеры — как у любого объекта MajorDoMo: пользователи, терминалы (с «кроме»), время, условия по свойствам.
// Камера без правила — по настройке модуля GO2RTC_DEFAULT_ACCESS: 'admin' — только администратору (по умолчанию),
// 'all' — всем. Администратор (вход в панель управления или пользователь с правами администратора) видит все камеры.
// Сценарии (go2rtcShow, «Сообщения» — messagesSend с 'stream') правами не ограничены

// Камеры без правила: 'admin' или 'all'
function go2rtcDefaultAccess()
{
    $config = go2rtcModuleConfig();
    return (isset($config['GO2RTC_DEFAULT_ACCESS']) && $config['GO2RTC_DEFAULT_ACCESS'] == 'all') ? 'all' : 'admin';
}

// Есть ли у камеры правило «Контроля доступа»
function go2rtcRuleDefined($camera_id)
{
    if (!(int)$camera_id || !function_exists('checkAccessDefined') || !SQLSelectOne("SHOW TABLES LIKE 'security_rules'")) {
        return false;
    }
    return checkAccessDefined('go2rtc', (int)$camera_id);
}

// Тот, кто сейчас в сессии ядра, — администратор: вход в панель или пользователь-администратор с паролем
function go2rtcSessionAdmin()
{
    global $session;
    if (is_object($session) && !empty($session->data['AUTHORIZED'])) {
        return true;
    }
    $user_id = (is_object($session) && !empty($session->data['SITE_USER_ID'])) ? (int)$session->data['SITE_USER_ID'] : 0;
    if (!$user_id) {
        return false;
    }
    // пользователь без пароля входит на сайт сам (ядро) — это не вход администратора
    $user = SQLSelectOne("SELECT IS_ADMIN, PASSWORD FROM users WHERE ID=" . $user_id);
    $password = isset($user['PASSWORD']) ? (string)$user['PASSWORD'] : '';
    return !empty($user['IS_ADMIN']) && $password != '' && $password != hash('sha512', '');
}

// Видна ли камера тому, кто сейчас в сессии ядра (страница модуля, вставка в сцену; «Сообщения» кладут в сессию
// проверенный по ключу терминал и пользователя на время запроса)
function go2rtcAllowedNow($name, $admin_check = true)
{
    if (!go2rtcHas($name)) {
        return false;
    }
    if ($admin_check && go2rtcSessionAdmin()) {
        return true;
    }
    $camera = go2rtcCameraByName($name);
    if (empty($camera['ID'])) {
        go2rtcCamerasSync();
        $camera = go2rtcCameraByName($name);
    }
    if (!empty($camera['ID']) && go2rtcRuleDefined($camera['ID'])) {
        return function_exists('checkAccess') && checkAccess('go2rtc', (int)$camera['ID']);
    }
    return go2rtcDefaultAccess() == 'all';
}

// Видна ли камера терминалу (имя), пользователю (ID) или администратору — для другого участника (например, получателя
// «Показать на…»): правило ядра считается для него — на время проверки он в сессии, потом всё как было
function go2rtcAllowed($name, $terminal_name, $user_id, $is_admin)
{
    global $session;
    if ($is_admin) {
        return go2rtcHas($name);
    }
    if (!is_object($session)) {
        $session = new stdClass();
        $session->data = array();
    }
    $keys = array('TERMINAL', 'TERMINAL_ID', 'SITE_USER_ID', 'SITE_USERNAME');
    $saved = array();
    foreach ($keys as $key) {
        $saved[$key] = array_key_exists($key, $session->data) ? $session->data[$key] : null;
        unset($session->data[$key]);
    }
    if ($terminal_name != '') {
        $session->data['TERMINAL'] = (string)$terminal_name;
    }
    $user = array();
    if ((int)$user_id) {
        $user = SQLSelectOne("SELECT ID, USERNAME, IS_ADMIN FROM users WHERE ID=" . (int)$user_id);
        if (!empty($user['ID'])) {
            $session->data['SITE_USER_ID'] = (int)$user['ID'];
            $session->data['SITE_USERNAME'] = $user['USERNAME'];
        }
    }
    $result = !empty($user['IS_ADMIN']) ? go2rtcHas($name) : go2rtcAllowedNow($name, false);
    foreach ($keys as $key) {
        if ($saved[$key] === null) {
            unset($session->data[$key]);
        } else {
            $session->data[$key] = $saved[$key];
        }
    }
    return $result;
}

// Камеры, видимые терминалу, пользователю или администратору (по порядку из таблицы)
function go2rtcCamerasFor($terminal_name, $user_id, $is_admin)
{
    $result = array();
    $names = array();
    foreach (go2rtcCameraList() as $row) {
        $names[] = $row['NAME'];
    }
    foreach (array_unique(array_merge($names, go2rtcStreams())) as $name) {
        if (go2rtcAllowed($name, $terminal_name, $user_id, $is_admin)) {
            $result[] = $name;
        }
    }
    return $result;
}

// Правило камеры словами (для списка в панели): «все», «пользователи: …», «кроме терминалов: …», «по времени», «по условию»
function go2rtcRuleText($camera_id)
{
    if (!go2rtcRuleDefined($camera_id)) {
        return go2rtcLang(go2rtcDefaultAccess() == 'all' ? 'GO2RTC_RULE_NONE_ALL' : 'GO2RTC_RULE_NONE_ADMIN');
    }
    $rule = SQLSelectOne("SELECT * FROM security_rules WHERE OBJECT_TYPE='go2rtc' AND OBJECT_ID=" . (int)$camera_id);
    $parts = array();
    if (!empty($rule['USERS'])) {
        $names = array();
        foreach (SQLSelect("SELECT NAME, USERNAME FROM users WHERE ID IN (" . implode(',', array_map('intval', explode(',', $rule['USERS']))) . ")") as $u) {
            $names[] = $u['NAME'] != '' ? $u['NAME'] : $u['USERNAME'];
        }
        $parts[] = go2rtcLang(!empty($rule['USERS_EXCEPT']) ? 'GO2RTC_RULE_USERS_EXCEPT' : 'GO2RTC_RULE_USERS') . ': ' . implode(', ', $names);
    }
    if (!empty($rule['TERMINALS'])) {
        $names = array();
        foreach (SQLSelect("SELECT TITLE FROM terminals WHERE ID IN (" . implode(',', array_map('intval', explode(',', $rule['TERMINALS']))) . ")") as $t) {
            $names[] = $t['TITLE'];
        }
        $parts[] = go2rtcLang(!empty($rule['TERMINALS_EXCEPT']) ? 'GO2RTC_RULE_TERMINALS_EXCEPT' : 'GO2RTC_RULE_TERMINALS') . ': ' . implode(', ', $names);
    }
    if (!empty($rule['TIMES'])) {
        $parts[] = go2rtcLang(!empty($rule['TIMES_EXCEPT']) ? 'GO2RTC_RULE_TIMES_EXCEPT' : 'GO2RTC_RULE_TIMES') . ' ' . $rule['TIMES'];
    }
    if (!empty($rule['CONDITION_ACTIVE'])) {
        $parts[] = go2rtcLang('GO2RTC_RULE_CONDITION');
    }
    return count($parts) ? implode('; ', $parts) : go2rtcLang('GO2RTC_RULE_ALL');
}

// Однократный перенос прежних прав модуля (CAMERA_ACCESS: всем / терминалы / пользователи — любой из них) в правила ядра.
// В правиле ядра пользователи и терминалы должны совпасть оба, поэтому камера, где отмечены и те, и другие,
// получает правило только по пользователям — терминалы задаются вручную (запись в журнал)
function go2rtcRulesMigrate()
{
    $config = go2rtcModuleConfig();
    if (!empty($config['RULES_MIGRATED']) || !SQLSelectOne("SHOW TABLES LIKE 'security_rules'")) {
        return;
    }
    $access = (isset($config['CAMERA_ACCESS']) && is_array($config['CAMERA_ACCESS'])) ? $config['CAMERA_ACCESS'] : array();
    if (count($access)) {
        go2rtcCamerasSync();
    }
    foreach ($access as $name => $item) {
        $camera = go2rtcCameraByName($name);
        if (empty($camera['ID']) || !is_array($item) || go2rtcRuleDefined($camera['ID'])) {
            continue;
        }
        $rule = array('OBJECT_TYPE' => 'go2rtc', 'OBJECT_ID' => (int)$camera['ID'], 'TERMINALS' => '', 'USERS' => '', 'TIMES' => '');
        $users = !empty($item['users']) ? array_map('intval', (array)$item['users']) : array();
        $terminals = array();
        foreach (!empty($item['terminals']) ? (array)$item['terminals'] : array() as $terminal_name) {
            $t = SQLSelectOne("SELECT ID FROM terminals WHERE NAME='" . DBSafe((string)$terminal_name) . "'");
            if (!empty($t['ID'])) {
                $terminals[] = (int)$t['ID'];
            }
        }
        if (empty($item['all'])) {
            if (count($users)) {
                $rule['USERS'] = implode(',', $users);
                if (count($terminals)) {
                    DebMes('go2rtc: rights of ' . $name . ': users and terminals were both allowed — only users moved to the access rule, set terminals manually', 'go2rtc');
                }
            } elseif (count($terminals)) {
                $rule['TERMINALS'] = implode(',', $terminals);
            } else {
                continue;
            }
        }
        SQLInsert('security_rules', $rule);
    }
    $config['RULES_MIGRATED'] = 1;
    go2rtcModuleConfigSave($config);
}

// --- Камеры модуля (таблица go2rtc_cameras): название, порядок, привязка к объектам ---
// Строки появляются из списка go2rtc (go2rtcCamerasSync); поток, пропавший из go2rtc, помечается MISSING, но строка
// (название, привязки) остаётся — вернётся поток, вернётся и камера. Числовой ID нужен «Контролю доступа» ядра.

// Пополнить таблицу из списка go2rtc. Возвращает число новых камер
function go2rtcCamerasSync()
{
    if (!go2rtcEnabled()) {
        return 0;
    }
    $streams = go2rtcStreams();
    if (!count($streams)) {
        // go2rtc не ответил — пометки «нет в go2rtc» не трогаем
        return 0;
    }
    $rows = array();
    foreach (SQLSelect("SELECT ID, NAME, MISSING FROM go2rtc_cameras") as $row) {
        $rows[$row['NAME']] = $row;
    }
    $max = SQLSelectOne("SELECT MAX(PRIORITY) AS P FROM go2rtc_cameras");
    $priority = !empty($max['P']) ? (int)$max['P'] : 0;
    $added = 0;
    foreach ($streams as $name) {
        if (!isset($rows[$name])) {
            $priority += 10;
            $rec = array('NAME' => $name, 'TITLE' => $name, 'PRIORITY' => $priority, 'HIDDEN' => 0, 'MISSING' => 0, 'ADDED' => time());
            SQLInsert('go2rtc_cameras', $rec);
            $added++;
        } elseif ((int)$rows[$name]['MISSING']) {
            SQLExec("UPDATE go2rtc_cameras SET MISSING=0 WHERE ID=" . (int)$rows[$name]['ID']);
        }
    }
    foreach ($rows as $name => $row) {
        if (!in_array($name, $streams, true) && !(int)$row['MISSING']) {
            SQLExec("UPDATE go2rtc_cameras SET MISSING=1 WHERE ID=" . (int)$row['ID']);
        }
    }
    if ($added) {
        DebMes("go2rtc: $added new camera(s)", 'go2rtc');
    }
    return $added;
}

// Камеры по порядку (без пропавших из go2rtc; $hidden — и скрытые)
function go2rtcCameraList($hidden = false)
{
    return SQLSelect("SELECT * FROM go2rtc_cameras WHERE MISSING=0" . ($hidden ? '' : ' AND HIDDEN=0') . " ORDER BY PRIORITY, TITLE");
}

function go2rtcCameraGet($id)
{
    $row = SQLSelectOne("SELECT * FROM go2rtc_cameras WHERE ID=" . (int)$id);
    return !empty($row['ID']) ? $row : array();
}

function go2rtcCameraByName($name)
{
    $row = SQLSelectOne("SELECT * FROM go2rtc_cameras WHERE NAME='" . DBSafe((string)$name) . "'");
    return !empty($row['ID']) ? $row : array();
}

// Название камеры для показа (из таблицы, иначе имя потока)
function go2rtcCameraTitle($name)
{
    $row = go2rtcCameraByName($name);
    return (!empty($row['TITLE'])) ? $row['TITLE'] : (string)$name;
}

// Поток высокого качества камеры (поле HQ_NAME, если такой поток есть в go2rtc), иначе сама камера
function go2rtcCameraHq($name)
{
    $row = go2rtcCameraByName($name);
    return (!empty($row['HQ_NAME']) && go2rtcHas($row['HQ_NAME'])) ? $row['HQ_NAME'] : (string)$name;
}

// Класс объекта MajorDoMo и всех его родителей (для «это камера SCameras?»)
function go2rtcObjectClasses($object)
{
    $classes = array();
    $obj = SQLSelectOne("SELECT CLASS_ID FROM objects WHERE TITLE='" . DBSafe((string)$object) . "'");
    $class_id = !empty($obj['CLASS_ID']) ? (int)$obj['CLASS_ID'] : 0;
    for ($i = 0; $class_id && $i < 20; $i++) {
        $class = SQLSelectOne("SELECT TITLE, PARENT_ID FROM classes WHERE ID=" . $class_id);
        if (empty($class['TITLE'])) {
            break;
        }
        $classes[] = $class['TITLE'];
        $class_id = (int)$class['PARENT_ID'];
    }
    return $classes;
}

// Привязанный объект — камера ядра (класс SCameras: устройство «Камера»): записать в него ссылки go2rtc.
// streamURL / streamURL_HQ — RTSP go2rtc (пароль RTSP go2rtc, только просмотр), snapshotURL — кадр с API go2rtc
// для сервера (снимки и история снимков ядра — takeSnapshot). Пустой объект или другой класс — ничего
function go2rtcCameraLinkObject($camera)
{
    if (empty($camera['LINKED_OBJECT']) || !go2rtcEnabled() || !in_array('SCameras', go2rtcObjectClasses($camera['LINKED_OBJECT']), true)) {
        return false;
    }
    $cfg = go2rtcConfig();
    $object = $camera['LINKED_OBJECT'];
    $set = function ($property, $value) use ($object) {
        if ((string)getGlobal($object . '.' . $property) !== (string)$value) {
            setGlobal($object . '.' . $property, $value, array('go2rtc' => '0'));
        }
    };
    // кадр для сервера: адрес API (на этой машине — без пароля; иначе логин API в ссылке, видна только в свойствах объекта).
    // Сначала кадр: превью устройства (метод updatePreview, его вызывает изменение streamURL) берёт snapshotURL, если он есть,
    // иначе — кадр из RTSP через ffmpeg (модуль thumb)
    $auth = ($cfg['user'] != '') ? rawurlencode($cfg['user']) . ':' . rawurlencode($cfg['password']) . '@' : '';
    $set('snapshotURL', preg_replace('#^(https?://)#i', '$1' . $auth, $cfg['url']) . '/api/frame.jpeg?src=' . rawurlencode($camera['NAME']));
    $set('streamURL', go2rtcUrl($camera['NAME'], 'rtsp'));
    $set('streamURL_HQ', !empty($camera['HQ_NAME']) && go2rtcHas($camera['HQ_NAME']) ? go2rtcUrl($camera['HQ_NAME'], 'rtsp') : '');
    // превью пересобрать и тогда, когда адреса потоков не менялись (устройство, созданное раньше, — с превью из RTSP)
    callMethod($object . '.updatePreview');
    return true;
}

// Состояние камер для привязанных объектов (задание, его ставит MINUTELY — сам MINUTELY не ждёт камер):
// «на связи» — кадр получен (кадр не старше минуты из кеша go2rtc, ожидание до 3 с); «смотрят» — число зрителей в go2rtc.
// Камера-устройство (SCameras) на связи — метод keepAlive (свойство alive ядра); свои свойства и метод — из настроек камеры
function go2rtcCamerasCheck()
{
    if (!go2rtcEnabled()) {
        return;
    }
    $cameras = SQLSelect("SELECT * FROM go2rtc_cameras WHERE MISSING=0 AND LINKED_OBJECT!=''");
    if (!count($cameras)) {
        return;
    }
    // зрители: один запрос на все потоки
    $streams = json_decode(go2rtcRequest('GET', 'api/streams', array(), null, '', $code), true);
    $streams = is_array($streams) ? $streams : array();
    foreach ($cameras as $camera) {
        $name = $camera['NAME'];
        $viewers = (isset($streams[$name]['consumers']) && is_array($streams[$name]['consumers'])) ? count($streams[$name]['consumers']) : 0;
        $fcode = 0;
        $ftype = '';
        $jpeg = go2rtcDown($name) ? '' : go2rtcRequest('GET', 'api/frame.jpeg', array('src' => $name, 'cache' => '60s'), null, '', $fcode, 3, $ftype);
        $online = ($fcode == 200 && strpos((string)$ftype, 'image/jpeg') === 0 && $jpeg != '') ? 1 : 0;
        $object = $camera['LINKED_OBJECT'];
        if ($camera['ONLINE_PROPERTY'] != '') {
            setGlobal($object . '.' . $camera['ONLINE_PROPERTY'], $online, array('go2rtc' => '0'));
        }
        if ($camera['VIEWERS_PROPERTY'] != '') {
            setGlobal($object . '.' . $camera['VIEWERS_PROPERTY'], $viewers, array('go2rtc' => '0'));
        }
        if ($online && in_array('SCameras', go2rtcObjectClasses($object), true)) {
            callMethod($object . '.keepAlive');
        }
        if ((int)$camera['ONLINE'] != $online) {
            DebMes('go2rtc: camera ' . $name . ' is ' . ($online ? 'online' : 'offline'), 'go2rtc');
            if ($camera['STATE_METHOD'] != '') {
                callMethod($object . '.' . $camera['STATE_METHOD'], array('online' => $online, 'camera' => $name, 'viewers' => $viewers));
            }
        }
        SQLExec("UPDATE go2rtc_cameras SET ONLINE=$online, VIEWERS=" . (int)$viewers . ", CHECKED=" . time() . " WHERE ID=" . (int)$camera['ID']);
    }
}

// Свойство «показать на терминале» привязанного объекта изменилось (propertySetHandle модуля):
// значение — терминал (имя или название), «терминал:секунды» или пусто (ничего)
function go2rtcCameraShowProperty($camera, $value)
{
    $value = trim((string)$value);
    if ($value == '') {
        return false;
    }
    $duration = 60;
    if (preg_match('/^(.+):(\d+)$/', $value, $m)) {
        $value = trim($m[1]);
        $duration = (int)$m[2];
    }
    $terminal = array();
    if (function_exists('terminalsFind')) {
        $terminal = terminalsFind($value);
    } else {
        $list = getTerminalsByName($value, 1);
        $terminal = !empty($list[0]['ID']) ? $list[0] : SQLSelectOne("SELECT * FROM terminals WHERE TITLE='" . DBSafe($value) . "'");
    }
    if (empty($terminal['ID'])) {
        DebMes('go2rtc: show ' . $camera['NAME'] . ': terminal not found: ' . $value, 'go2rtc');
        return false;
    }
    return go2rtcShow($terminal, $camera['NAME'], $duration, go2rtcLang('GO2RTC_CAMERA_TITLE') . ': ' . $camera['TITLE']);
}

// Адрес go2rtc для прямого просмотра в браузере (плеер go2rtc, viewer.js): только если у API нет пароля —
// пароль API в браузер не отдаётся. Браузер, который go2rtc не видит (вне дома, страница по HTTPS), переходит
// на WebRTC через сервер сам. go2rtc пускает вебсокет с чужой страницы только с api: origin: "*" в go2rtc.yaml
function go2rtcDirectBase()
{
    $cfg = go2rtcConfig();
    if ($cfg['url'] == '' || $cfg['user'] != '' || $cfg['password'] != '') {
        return '';
    }
    return go2rtcDeviceBase();
}

// Страница одной камеры для внешних панелей (iframe) — подписанная ссылка до $exp (права проверяет тот, кто её выдаёт):
// $mode — live (видео) | snapshot (снимки); $absolute — с адресом сервера
function go2rtcEmbedUrl($name, $exp, $mode = 'live', $absolute = false)
{
    return ($absolute ? go2rtcServerBase() : '') . ROOTHTML . 'api.php/module/go2rtc/embed?src=' . rawurlencode($name) . '&exp=' . (int)$exp
        . '&sig=' . go2rtcSign($name, (int)$exp) . ($mode == 'snapshot' ? '&mode=snapshot' : '');
}
