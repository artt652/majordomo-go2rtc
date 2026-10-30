<?php
/**
 * Камеры (go2rtc) — модуль MajorDoMo.
 *
 * Панель управления: «Камеры» (просмотр; у каждой камеры — название, порядок, второй поток, привязка к объекту),
 * «Настройки» (go2rtc), «Права» (правила «Контроля доступа» ядра на каждую камеру), «Ссылки» (временный доступ,
 * гостевые ссылки, WebTorrent). Вставка в сцены и страницы — usual() ([#module name="go2rtc" cam="…"#]). Функции для других модулей и сценариев — go2rtc_lib.inc.php (константа GO2RTC_API).
 *
 * Входы api.php/module/go2rtc/… (ядро вызывает api()):
 *   frame?src=…&exp=…&sig=…  — кадр камеры по подписанной ссылке (go2rtcSignedUrl); &w= — ширина;
 *   whep?src=…&exp=…&sig=…   — согласование WebRTC (POST, SDP) по подписанной ссылке;
 *   guest/<id>               — гостевая страница просмотра (временная ссылка без входа);
 *   frame/<id>, whep/<id>, check/<id> — кадр, WebRTC и проверка для гостевой страницы.
 * Сам api.php доступ не проверяет — модуль пускает только по подписи или действующей гостевой ссылке.
 */

include_once(DIR_MODULES . 'go2rtc/go2rtc_lib.inc.php');

class go2rtc extends module
{
    // параметры вставки [#module name="go2rtc" cam="…" mode="…" width="…" height="…" caption="…" muted="0"#]
    var $cam;
    var $mode;
    var $width;
    var $height;
    var $caption;
    var $muted;
    var $ajax;

    function __construct()
    {
        $this->name = 'go2rtc';
        $this->title = go2rtcLang('GO2RTC_TITLE');
        $this->module_category = '<#LANG_SECTION_DEVICES#>';
        $this->checkInstalled();
    }

    // Параметры модуля для ссылок и форм панели управления: без них parseLinks() не передаёт в pd состояние панели,
    // и форма, отправленная из панели, попадает на стартовую страницу
    function saveParams($data = 1)
    {
        return parent::saveParams(array());
    }

    function run()
    {
        go2rtcLoadLanguage();
        $out = array();
        if ($this->action == 'admin') {
            $this->admin($out);
        } else {
            $this->usual($out);
        }
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    function admin(&$out)
    {
        $this->getConfig();
        // поле, добавленное после первой версии таблицы (установка модуля заново добавляет его сама)
        if (!SQLSelectOne("SHOW COLUMNS FROM go2rtc_cameras LIKE 'AUDIO'") || !SQLSelectOne("SHOW COLUMNS FROM go2rtc_cameras LIKE 'SOURCE_HQ'")) {
            $this->dbInstall('');
        }
        $h = function ($text) {
            return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        };
        $out['TAB'] = '';
        if (gr('go2rtc_save')) {
            foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_USER', 'GO2RTC_RTSP_PASSWORD') as $key) {
                $this->config[$key] = trim((string)gr(strtolower($key)));
            }
            foreach (array('GO2RTC_URL', 'GO2RTC_DEVICE_URL') as $key) {
                if ($this->config[$key] != '' && !preg_match('#^https?://#i', $this->config[$key])) {
                    $this->config[$key] = 'http://' . $this->config[$key];
                }
            }
            $this->config['GO2RTC_RTSP_PORT'] = gr('go2rtc_rtsp_port', 'int') ? (string)gr('go2rtc_rtsp_port', 'int') : '';
            $this->config['GO2RTC_WEBTORRENT'] = gr('go2rtc_webtorrent', 'int') ? '1' : '0';
            $this->config['GO2RTC_WT_MINUTES'] = gr('go2rtc_wt_minutes', 'int') ? (string)max(1, min(1440, gr('go2rtc_wt_minutes', 'int'))) : '';
            $this->config['GO2RTC_WAIT'] = gr('go2rtc_wait', 'int') ? (string)max(2, min(25, gr('go2rtc_wait', 'int'))) : '';
            // встроенный go2rtc: режим, порты, доступ к API из сети, ffmpeg
            $this->config['GO2RTC_MODE'] = (gr('go2rtc_mode') == 'local') ? 'local' : '';
            foreach (array('GO2RTC_API_PORT', 'GO2RTC_WEBRTC_PORT') as $key) {
                $port = gr(strtolower($key), 'int');
                $this->config[$key] = ($port >= 1024 && $port <= 65535) ? (string)$port : '';
            }
            $this->config['GO2RTC_API_LAN'] = gr('go2rtc_api_lan', 'int') ? '1' : '0';
            $this->config['GO2RTC_FFMPEG'] = trim((string)gr('go2rtc_ffmpeg'));
            // файл данных go2rtc (сопряжения, токены, потоки из его веб-интерфейса) и пароль API из сети
            $this->config['GO2RTC_FILE'] = gr('go2rtc_file', 'int') ? '1' : '0';
            $path = trim((string)gr('go2rtc_file_path'));
            if ($path != '' && !go2rtcLocalFilePathOk($path)) {
                $out['LOCAL_FILE_PATH_BAD'] = 1;
                $path = isset($this->config['GO2RTC_FILE_PATH']) ? $this->config['GO2RTC_FILE_PATH'] : '';
            }
            $this->config['GO2RTC_FILE_PATH'] = $path;
            $this->config['GO2RTC_API_AUTH'] = gr('go2rtc_api_auth', 'int') ? '1' : '0';
            $user = trim((string)gr('go2rtc_api_local_user'));
            $this->config['GO2RTC_API_LOCAL_USER'] = ($user != '') ? $user : 'admin';
            $password = (string)gr('go2rtc_api_local_password');
            if ($password != '') {
                $this->config['GO2RTC_API_LOCAL_PASSWORD'] = $password;
            }
            $this->saveConfig();
            @unlink(ROOT . 'cms/cached/go2rtc_streams.json');
            $out['GO2RTC_SAVED'] = 1;
            $out['TAB'] = 'tab_settings';
        }
        // встроенный go2rtc: установка и управление (запуск и остановку выполняет цикл cycle_go2rtc)
        if (gr('local_install')) {
            $installed = false;
            $out['LOCAL_LOG'] = $h(go2rtcLocalInstall($installed));
            $out['LOCAL_INSTALLED_NOW'] = $installed ? 1 : 0;
            if ($installed) {
                go2rtcLocalControl('restart');
            }
            $out['TAB'] = 'tab_settings';
        }
        // импорт конфига go2rtc в файл данных встроенного: загрузка, путь на сервере или внешний go2rtc из настроек
        if (gr('local_import')) {
            $text = '';
            $error = '';
            if (gr('local_import') == 'external') {
                $text = go2rtcLocalExternalConfig($error);
            } elseif (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                $text = (string)file_get_contents($_FILES['import_file']['tmp_name']);
            } elseif (trim((string)gr('import_path')) != '') {
                $path = trim((string)gr('import_path'));
                $text = is_file($path) && is_readable($path) ? (string)file_get_contents($path) : '';
                if ($text == '') {
                    $error = go2rtcLang('GO2RTC_LOCAL_IMPORT_NO_FILE') . ': ' . $path;
                }
            } else {
                $error = go2rtcLang('GO2RTC_LOCAL_IMPORT_NO_FILE');
            }
            if ($error == '' && go2rtcLocalImport($text, $error)) {
                $this->config = go2rtcModuleConfig();
                $out['LOCAL_IMPORTED'] = 1;
            } else {
                $out['LOCAL_IMPORT_ERROR'] = $h($error);
            }
            $out['TAB'] = 'tab_settings';
        }
        foreach (array('start', 'stop', 'restart') as $action) {
            if (gr('local_' . $action)) {
                go2rtcLocalControl($action);
                $out['LOCAL_ACTION'] = $action;
                $out['TAB'] = 'tab_settings';
            }
        }
        // камеры без правила «Контроля доступа»: только администратору или всем
        if (gr('default_access_save')) {
            $this->config['GO2RTC_DEFAULT_ACCESS'] = (gr('default_access') == 'all') ? 'all' : 'admin';
            $this->saveConfig();
            $out['ACCESS_SAVED'] = 1;
            $out['TAB'] = 'tab_access';
        }
        // поделиться камерой из панели: гостевая ссылка на N минут или ссылка WebTorrent
        $share_cam = (string)gr('share_cam');
        if ($share_cam != '' && go2rtcHas($share_cam) && (gr('guest_create') || gr('wt_create'))) {
            if (gr('guest_create')) {
                global $session;
                $by_title = (is_object($session) && !empty($session->data['SITE_USERNAME'])) ? $session->data['SITE_USERNAME'] : 'admin';
                $id = go2rtcLinkCreate($share_cam, 'guest', max(1, min(43200, gr('share_minutes', 'int'))), array(), 'admin', $by_title);
                $out['NEW_LINK'] = $h(go2rtcGuestUrl($id));
            } else {
                $share = go2rtcShare($share_cam);
                $out['NEW_LINK'] = !empty($share['url']) ? $h($share['url']) : '';
                $out['NEW_LINK_FAILED'] = empty($share['url']) ? 1 : 0;
            }
            $out['NEW_LINK_CAM'] = $h(go2rtcCameraTitle($share_cam));
            $out['TAB'] = 'tab_links';
        }
        // закрыть временный доступ, гостевую ссылку или ссылку WebTorrent
        if (gr('link_close') != '') {
            go2rtcLinkClose((string)gr('link_close'));
            $out['LINK_CLOSED'] = 1;
            $out['TAB'] = 'tab_links';
        }
        if (gr('share_close') != '') {
            go2rtcUnshare((string)gr('share_close'));
            $out['LINK_CLOSED'] = 1;
            $out['TAB'] = 'tab_links';
        }
        if (gr('tab') != '' && preg_match('/^tab_\w+$/', (string)gr('tab'))) {
            $out['TAB'] = (string)gr('tab');
        }
        go2rtcCamerasSync();
        go2rtcRulesMigrate();
        // камера: название, порядок, второй поток, привязка к объекту
        $camera = gr('camera_id', 'int') ? go2rtcCameraGet(gr('camera_id', 'int')) : array();
        if (!empty($camera['ID']) || (gr('camera_id') == 'new' && go2rtcLocal())) {
            if (empty($camera['ID'])) {
                $camera = array('ID' => 0, 'NAME' => '', 'TITLE' => '', 'PRIORITY' => 0, 'HIDDEN' => 0, 'AUDIO' => 1, 'HQ_NAME' => '',
                    'LINKED_OBJECT' => '', 'ONLINE_PROPERTY' => '', 'VIEWERS_PROPERTY' => '', 'SHOW_PROPERTY' => '', 'STATE_METHOD' => '',
                    'ONLINE' => 0, 'CHECKED' => 0, 'LOCAL' => 1, 'SOURCE' => '', 'SOURCE_USER' => '', 'SOURCE_PASSWORD' => '', 'SOURCE_HQ' => '');
            }
            if ($this->adminCamera($camera, $out, $h) === 'deleted') {
                $out['CAMERA_DELETED'] = 1;
                $out['TAB'] = 'tab_cameras';
            } else {
                return;
            }
        }

        foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_PORT', 'GO2RTC_RTSP_USER',
            'GO2RTC_RTSP_PASSWORD', 'GO2RTC_WT_MINUTES', 'GO2RTC_WAIT') as $key) {
            $out[$key] = $h(isset($this->config[$key]) ? $this->config[$key] : '');
        }
        $out['GO2RTC_WEBTORRENT'] = !empty($this->config['GO2RTC_WEBTORRENT']) ? 1 : 0;
        // встроенный go2rtc
        $out['LOCAL'] = go2rtcLocal() ? 1 : 0;
        foreach (array('GO2RTC_API_PORT', 'GO2RTC_WEBRTC_PORT', 'GO2RTC_FFMPEG') as $key) {
            $out[$key] = $h(isset($this->config[$key]) ? $this->config[$key] : '');
        }
        $out['GO2RTC_API_LAN'] = (!isset($this->config['GO2RTC_API_LAN']) || $this->config['GO2RTC_API_LAN'] !== '0') ? 1 : 0;
        $ls = go2rtcLocalSettings();
        $out['GO2RTC_FILE'] = $ls['file'] ? 1 : 0;
        $out['GO2RTC_FILE_PATH'] = $h(isset($this->config['GO2RTC_FILE_PATH']) ? $this->config['GO2RTC_FILE_PATH'] : '');
        $out['GO2RTC_FILE_DEFAULT'] = $h(go2rtcLocalDir() . '/go2rtc.ini');
        $out['GO2RTC_API_AUTH'] = $ls['api_auth'] ? 1 : 0;
        $out['GO2RTC_API_LOCAL_USER'] = $h($ls['api_user']);
        $out['GO2RTC_API_LOCAL_PASSWORD'] = $h($ls['api_password']);
        if ($out['LOCAL']) {
            if ($ls['api_auth'] && $ls['api_password'] == '') {
                $out['GO2RTC_API_LOCAL_PASSWORD'] = $h(go2rtcLocalApiPassword());
            }
            $out['LOCAL_OPEN_FILE'] = go2rtcLocalApiOpenFile() ? 1 : 0;
            $out['LOCAL_LOCKED'] = go2rtcLocalApiLocked() ? 1 : 0;
            if ($ls['api_lan']) {
                $ip = function_exists('getLocalIp') ? getLocalIp() : '';
                $out['LOCAL_WEB_UI'] = $h('http://' . ($ip ? $ip : $_SERVER['SERVER_NAME']) . ':' . $ls['api_port'] . '/');
            }
            if ($ls['file']) {
                clearstatcache();
                $out['LOCAL_FILE'] = $h($ls['file_path']);
                $out['LOCAL_FILE_SIZE'] = is_file($ls['file_path']) ? (int)filesize($ls['file_path']) : 0;
                $out['LOCAL_FILE_STREAMS'] = $h(implode(', ', go2rtcLocalFileStreams()));
            }
            $out['LOCAL_HAS_EXTERNAL'] = !empty($this->config['GO2RTC_URL']) ? 1 : 0;
        }
        $st = go2rtcLocalStatus();
        $out['LOCAL_VERSION'] = $h(GO2RTC_LOCAL_VERSION);
        $out['LOCAL_INSTALLED'] = $h($st['installed']);
        $out['LOCAL_ASSET'] = $h($st['asset']);
        $out['LOCAL_PLATFORM_ERROR'] = $h($st['platform_error']);
        $out['LOCAL_RUNNING'] = $h($st['running']);
        $out['LOCAL_PID'] = (int)$st['pid'];
        $out['LOCAL_WANTED'] = $st['wanted'] ? 1 : 0;
        $out['LOCAL_ERROR'] = $h($st['error']);
        $out['LOCAL_UPDATE'] = $st['update'] ? 1 : 0;
        $out['LOCAL_FFMPEG'] = $h($st['ffmpeg']);
        $out['LOCAL_FFMPEG_VERSION'] = $h($st['ffmpeg_version']);
        $out['LOCAL_CYCLE'] = ($st['cycle'] && time() - $st['cycle'] < 120) ? 1 : 0;
        $out['LOCAL_LOG_TAIL'] = $out['LOCAL'] ? $h(go2rtcLocalLog(30)) : '';
        $out['WEBTORRENT'] = $out['GO2RTC_WEBTORRENT'];
        $out['ENABLED'] = go2rtcEnabled() ? 1 : 0;
        $out['DIRECT'] = $h(go2rtcDirectBase());
        $cfg = go2rtcConfig();
        $out['WAIT'] = (int)$cfg['wait'];
        $out['CAMERAS'] = array();
        $out['GRID'] = array();
        if (go2rtcEnabled()) {
            $streams = go2rtcStreams();
            $out['GO2RTC_STATUS'] = $h(sprintf(go2rtcLang('GO2RTC_STREAMS'), count($streams)));
            $out['GO2RTC_DEVICE_AUTO'] = $h(go2rtcDeviceBase());
            // просмотр в панели: подписанные ссылки на 12 часов (страница открыта долго); камеры — по порядку из таблицы
            $exp = time() + 12 * 3600;
            $titles = array();
            $out['HIDDEN_CAMERAS'] = array();
            foreach (go2rtcCameraList(true) as $row) {
                $titles[$row['NAME']] = $row;
                if ((int)$row['HIDDEN']) {
                    $out['HIDDEN_CAMERAS'][] = array('ID' => (int)$row['ID'], 'TITLE' => $h($row['TITLE']));
                }
            }
            $streams = array_values(array_filter(array_keys($titles), 'go2rtcHas'));
            foreach ($streams as $name) {
                $row = $titles[$name];
                $who = go2rtcRuleText($row['ID']);
                $hq = go2rtcCameraHq($name);
                $grid = array('ID' => (int)$row['ID'], 'NAME' => $h($name), 'TITLE' => $h($row['TITLE']), 'HIDDEN' => (int)$row['HIDDEN'],
                    'FRAME' => $h(go2rtcSignedUrl('frame', $name, $exp)), 'FRAME_HQ' => $h(go2rtcSignedUrl('frame', $hq, $exp)),
                    'WHEP' => $h(go2rtcSignedUrl('whep', $hq, $exp)), 'WHEP_MAIN' => $h(go2rtcSignedUrl('whep', $name, $exp)), 'AUDIO' => isset($row['AUDIO']) ? (int)$row['AUDIO'] : 1, 'HQ' => $h($hq), 'LINKED' => $h($row['LINKED_OBJECT']),
                    'WHO' => $h($who), 'RULE' => go2rtcRuleDefined($row['ID']) ? 1 : 0);
                if (!(int)$row['HIDDEN']) {
                    $out['GRID'][] = $grid;
                }
                $out['CAMERAS'][] = array('ID' => (int)$row['ID'], 'TITLE' => $h($row['TITLE']), 'NAME' => $h($name), 'WHO' => $h($who),
                    'RULE' => go2rtcRuleDefined($row['ID']) ? 1 : 0);
            }
        }
        $out['HAS_CAMERAS'] = count($out['CAMERAS']) ? 1 : 0;
        $out['DEFAULT_ACCESS'] = go2rtcDefaultAccess();
        $out['HAS_RULES'] = SQLSelectOne("SHOW TABLES LIKE 'security_rules'") ? 1 : 0;
        $out['HAS_HIDDEN'] = !empty($out['HIDDEN_CAMERAS']) ? 1 : 0;
        if (!$out['ENABLED'] && $out['TAB'] == '') {
            $out['TAB'] = 'tab_settings';
        }

        // действующие ссылки: временный доступ участникам чата, гостевые, WebTorrent
        $out['LINKS'] = array();
        $fmt = function ($time) {
            return date('Y-m-d', $time) == date('Y-m-d') ? date('H:i', $time) : date('d.m H:i', $time);
        };
        foreach (go2rtcLinks() as $id => $link) {
            $out['LINKS'][] = array('ID' => $id, 'CAM' => $h(go2rtcCameraTitle($link['cam'])),
                'KIND' => $h(go2rtcLang($link['kind'] == 'guest' ? 'GO2RTC_LINK_GUEST' : 'GO2RTC_LINK_CHAT')),
                'BY' => $h($link['by_title']), 'UNTIL' => $fmt($link['expires']), 'SHARE' => 0,
                'URL' => ($link['kind'] == 'guest') ? $h(go2rtcGuestUrl($id)) : '');
        }
        foreach (go2rtcShares() as $name => $expires) {
            $out['LINKS'][] = array('ID' => $h($name), 'CAM' => $h(go2rtcCameraTitle($name)), 'KIND' => 'WebTorrent', 'BY' => '', 'UNTIL' => $fmt($expires), 'SHARE' => 1, 'URL' => '');
        }
        $out['HAS_LINKS'] = count($out['LINKS']) ? 1 : 0;
    }

    // Настройки камеры: название, порядок, скрыть, поток высокого качества, привязка к объекту
    function adminCamera($camera, &$out, $h)
    {
        $id = (int)$camera['ID'];
        $is_local = !empty($camera['LOCAL']);
        // камера встроенного go2rtc: удалить (поток из go2rtc, запись, привязку свойства)
        if ($is_local && $id && gr('camera_delete')) {
            if ($camera['LINKED_OBJECT'] != '' && $camera['SHOW_PROPERTY'] != '') {
                removeLinkedProperty($camera['LINKED_OBJECT'], $camera['SHOW_PROPERTY'], $this->name);
            }
            go2rtcLocalDeleteStream($camera['NAME']);
            go2rtcLocalDeleteStream($camera['NAME'] . '_hq');
            SQLExec("DELETE FROM go2rtc_cameras WHERE ID=" . $id);
            DebMes('go2rtc: camera ' . $camera['NAME'] . ' deleted', 'go2rtc');
            return 'deleted';
        }
        // камера встроенного go2rtc: источник (адрес, логин, пароль, второй поток); «Проверить» — без сохранения
        if ($is_local && (gr('camera_save') || gr('source_test'))) {
            if (!$id) {
                $camera['NAME'] = trim((string)gr('name'));
            }
            $camera['SOURCE'] = trim((string)gr('source'));
            $camera['SOURCE_HQ'] = trim((string)gr('source_hq'));
            $camera['SOURCE_USER'] = trim((string)gr('source_user'));
            if ((string)gr('source_password') !== '' || $camera['SOURCE_USER'] === '') {
                $camera['SOURCE_PASSWORD'] = $camera['SOURCE_USER'] === '' ? '' : (string)gr('source_password'); // пусто — прежний
            }
            $error = '';
            if (!$id && !preg_match('/^[A-Za-z0-9][A-Za-z0-9_\-]{0,63}$/', $camera['NAME'])) {
                $error = go2rtcLang('GO2RTC_LOCAL_NAME_BAD');
            } elseif (!$id && (SQLSelectOne("SELECT ID FROM go2rtc_cameras WHERE NAME='" . DBSafe($camera['NAME']) . "'") || substr($camera['NAME'], -3) == '_hq')) {
                $error = go2rtcLang('GO2RTC_LOCAL_NAME_TAKEN');
            } elseif ($camera['SOURCE'] == '') {
                $error = go2rtcLang('GO2RTC_LOCAL_SOURCE_EMPTY');
            }
            if (gr('source_test')) {
                $test = ($error == '' || $camera['SOURCE'] != '') ? go2rtcLocalTest(go2rtcLocalSource($camera)) : array('ok' => false, 'error' => $error);
                $out['TEST_DONE'] = 1;
                $out['TEST_OK'] = $test['ok'] ? 1 : 0;
                $out['TEST_IMAGE'] = $test['ok'] ? base64_encode($test['jpeg']) : '';
                $out['TEST_ERROR'] = $test['ok'] ? '' : $h($test['error']);
            } elseif ($error != '') {
                $out['CAMERA_ERROR'] = $h($error);
                $local_invalid = true;
            } else {
                $camera['HQ_NAME'] = $camera['SOURCE_HQ'] != '' ? $camera['NAME'] . '_hq' : '';
                if (!$id) {
                    $max = SQLSelectOne("SELECT MAX(PRIORITY) AS P FROM go2rtc_cameras");
                    $camera['PRIORITY'] = (!empty($max['P']) ? (int)$max['P'] : 0) + 10;
                    $camera['TITLE'] = trim((string)gr('title')) != '' ? trim((string)gr('title')) : $camera['NAME'];
                    $camera['ADDED'] = time();
                    unset($camera['ID']);
                    $camera['ID'] = $id = SQLInsert('go2rtc_cameras', $camera);
                    DebMes('go2rtc: camera ' . $camera['NAME'] . ' added', 'go2rtc');
                } else {
                    SQLUpdate('go2rtc_cameras', $camera);
                }
                // в работающем go2rtc: обычный источник — сразу через API; exec:/echo:/с пробелами — цикл перезапустит go2rtc
                $restart = false;
                foreach (array($camera['NAME'] => go2rtcLocalSource($camera), $camera['NAME'] . '_hq' => go2rtcLocalSource($camera, true)) as $name => $src) {
                    if ($src == '') {
                        go2rtcLocalDeleteStream($name);
                    } elseif (go2rtcLocalConfigOnly($src)) {
                        $restart = true;
                    } elseif (go2rtcLocalPing()) {
                        $put = go2rtcLocalPut($name, $src);
                        if ($put != '') {
                            $out['CAMERA_ERROR'] = $h($put);
                        }
                    }
                }
                $out['CAMERA_RESTART'] = $restart ? (go2rtcLocalSettings()['file'] ? 2 : 1) : 0;
                @unlink(ROOT . 'cms/cached/go2rtc_streams.json');
            }
        }
        if (gr('camera_save') && $id && empty($local_invalid) && !gr('source_test')) {
            $old_object = $camera['LINKED_OBJECT'];
            $old_show = $camera['SHOW_PROPERTY'];
            $clean = function ($value) {
                return preg_replace('/[^\w\-.]/u', '', trim((string)$value));
            };
            $camera['TITLE'] = trim((string)gr('title')) != '' ? mb_substr(trim((string)gr('title')), 0, 255, 'UTF-8') : $camera['NAME'];
            $camera['PRIORITY'] = gr('priority', 'int');
            $camera['HIDDEN'] = gr('hidden', 'int') ? 1 : 0;
            $camera['AUDIO'] = gr('audio', 'int') ? 1 : 0;
            $hq = trim((string)gr('hq_name'));
            if (!$is_local) {
                $camera['HQ_NAME'] = ($hq != '' && $hq != $camera['NAME'] && go2rtcHas($hq)) ? $hq : '';
            }
            foreach (array('LINKED_OBJECT', 'ONLINE_PROPERTY', 'VIEWERS_PROPERTY', 'SHOW_PROPERTY', 'STATE_METHOD') as $field) {
                $camera[$field] = $clean(gr(strtolower($field)));
            }
            SQLUpdate('go2rtc_cameras', $camera);
            // «показать на терминале» — свойство объекта, изменение которого модуль ловит (propertySetHandle)
            if ($old_object != '' && $old_show != '' && ($old_object != $camera['LINKED_OBJECT'] || $old_show != $camera['SHOW_PROPERTY'])) {
                removeLinkedProperty($old_object, $old_show, $this->name);
            }
            if ($camera['LINKED_OBJECT'] != '' && $camera['SHOW_PROPERTY'] != '') {
                addLinkedProperty($camera['LINKED_OBJECT'], $camera['SHOW_PROPERTY'], $this->name);
            }
            go2rtcCameraLinkObject($camera);
            $out['CAMERA_SAVED'] = 1;
        }
        // создать устройство «Камера» (модуль «Устройства»): объект SCameras, привязанный к этой камере
        if (gr('device_create') && $camera['LINKED_OBJECT'] == '' && is_file(DIR_MODULES . 'devices/devices.class.php')) {
            include_once(DIR_MODULES . 'devices/devices.class.php');
            $devices = new devices();
            $devices->addDevice('camera', array('TITLE' => $camera['TITLE'], 'TABLE' => 'go2rtc_cameras', 'TABLE_ID' => $id));
            $camera = go2rtcCameraGet($id);
            if ($camera['LINKED_OBJECT'] != '') {
                if ($camera['SHOW_PROPERTY'] != '') {
                    addLinkedProperty($camera['LINKED_OBJECT'], $camera['SHOW_PROPERTY'], $this->name);
                }
                go2rtcCameraLinkObject($camera);
                DebMes('go2rtc: device ' . $camera['LINKED_OBJECT'] . ' created for ' . $camera['NAME'], 'go2rtc');
                $out['DEVICE_CREATED'] = 1;
            }
        }
        $out['CAMERA_AUDIO'] = isset($camera['AUDIO']) ? (int)$camera['AUDIO'] : 1;
        $out['CAMERA_LOCAL'] = $is_local ? 1 : 0;
        $out['CAMERA_NEW'] = $id ? 0 : 1;
        foreach (array('SOURCE', 'SOURCE_USER', 'SOURCE_HQ') as $field) {
            $out['CAMERA_' . $field] = $h(isset($camera[$field]) ? $camera[$field] : '');
        }
        $out['CAMERA_HAS_PASSWORD'] = !empty($camera['SOURCE_PASSWORD']) ? 1 : 0;
        $out['CAMERA_CONFIG_ONLY'] = ($is_local && go2rtcLocalConfigOnly((string)$camera['SOURCE'])) ? 1 : 0;
        foreach (array('ID', 'NAME', 'TITLE', 'PRIORITY', 'HIDDEN', 'HQ_NAME', 'LINKED_OBJECT', 'ONLINE_PROPERTY', 'VIEWERS_PROPERTY', 'SHOW_PROPERTY', 'STATE_METHOD') as $field) {
            $out['CAMERA_' . $field] = $h($camera[$field]);
        }
        $out['CAMERA_ONLINE'] = (int)$camera['ONLINE'];
        $out['CAMERA_CHECKED'] = !empty($camera['CHECKED']) ? date('d.m H:i', (int)$camera['CHECKED']) : '';
        $out['CAMERA_DEVICE'] = ($camera['LINKED_OBJECT'] != '' && in_array('SCameras', go2rtcObjectClasses($camera['LINKED_OBJECT']), true)) ? 1 : 0;
        $out['CAN_CREATE_DEVICE'] = ($camera['LINKED_OBJECT'] == '' && is_file(DIR_MODULES . 'devices/devices.class.php')) ? 1 : 0;
        $out['HQ_OPTIONS'] = array();
        foreach (go2rtcStreams() as $name) {
            if ($name != $camera['NAME']) {
                $out['HQ_OPTIONS'][] = array('NAME' => $h($name), 'SELECTED' => ($name == $camera['HQ_NAME']) ? 1 : 0);
            }
        }
        $exp = time() + 12 * 3600;
        $out['CAMERA_FRAME'] = $h(go2rtcSignedUrl('frame', $camera['NAME'], $exp));
        $out['CAMERA_WHEP'] = $h(go2rtcSignedUrl('whep', $camera['NAME'], $exp));
        $out['WAIT'] = (int)go2rtcConfig()['wait'];
        $out['DIRECT'] = $h(go2rtcDirectBase());
        // код вставки в сцену (подпись — название камеры, переименование подхватится само); скобки — сущностями, иначе шаблонизатор панели выполнит его прямо здесь
        foreach (array('snapshot' => 'EMBED_SNAPSHOT', 'live' => 'EMBED_LIVE') as $mode => $key) {
            // имя потока; с кавычками, «#», скобками — номер камеры (вставка понимает оба)
            $cam_attr = preg_match('/["#\[\]]/', $camera['NAME']) ? (string)(int)$camera['ID'] : $camera['NAME'];
            $code = '[#module name="go2rtc" cam="' . $cam_attr . '" mode="' . $mode . '" width="100%"#]';
            $out[$key] = str_replace(array('[', ']'), array('&#91;', '&#93;'), $h($code));
        }
    }

    // Вставка камеры в сцену, меню или шаблон: [#module name="go2rtc" cam="door"#]
    //   cam    — имя потока go2rtc (или ID камеры модуля);
    //   mode   — snapshot (по умолчанию: снимки, нажатие — видео на весь экран) | live (сразу видео в блоке);
    //   width, height — размер блока (CSS, по умолчанию 100% и 16:9); caption — подпись (по умолчанию — название камеры);
    //   muted  — 1 (по умолчанию: без звука, включается кнопкой) | 0 (сразу со звуком, если у камеры он есть).
    // Видит тот, кому камера разрешена (правило «Контроля доступа», вход по сессии ядра). Ссылки — на 24 часа,
    // открытая сутками сцена (планшет на стене) берёт новые сама: /ajax/go2rtc.html?op=renew&cam=…
    function usual(&$out)
    {
        go2rtcLoadLanguage();
        $cam = (string)($this->cam != '' ? $this->cam : gr('cam'));
        if (preg_match('/^\d+$/', $cam)) {
            $row = go2rtcCameraGet((int)$cam);
            $cam = !empty($row['NAME']) ? $row['NAME'] : $cam;
        }
        $allowed = go2rtcEnabled() && $cam != '' && go2rtcAllowedNow($cam);
        if (($this->ajax || gr('ajax')) && gr('op') == 'renew') {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode($allowed ? $this->embedLinks($cam) : array('ok' => 0));
            exit;
        }
        if (!$allowed) {
            $out['EMBED'] = '';
            return;
        }
        static $n = 0;
        $n++;
        $h = function ($text) {
            return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        };
        $id = 'go2rtc_embed_' . $n . '_' . substr(md5($cam . mt_rand()), 0, 6);
        $mode = ($this->mode == 'live' || gr('mode') == 'live') ? 'live' : 'snapshot';
        $width = preg_match('/^\d+(\.\d+)?(px|%|em|rem|vw|vh)?$/', (string)$this->width) ? (string)$this->width : '100%';
        if (preg_match('/^\d+$/', $width)) {
            $width .= 'px';
        }
        $height = preg_match('/^\d+(\.\d+)?(px|%|em|rem|vw|vh)?$/', (string)$this->height) ? (string)$this->height : '';
        if (preg_match('/^\d+$/', $height)) {
            $height .= 'px';
        }
        $title = ($this->caption != '') ? (string)$this->caption : go2rtcCameraTitle($cam);
        // muted="0" — сразу со звуком (видео в блоке; окно на весь экран — после нажатия, там браузер разрешит всегда);
        // без нажатия браузер может не дать — тогда без звука
        $muted = !in_array(strtolower(trim((string)$this->muted)), array('0', 'false', 'no', 'off'), true);
        $links = $this->embedLinks($cam);
        $texts = array('live' => go2rtcLang('GO2RTC_CAM_LIVE'), 'snapshot' => go2rtcLang('GO2RTC_CAM_SNAPSHOT'),
            'offline' => go2rtcLang('GO2RTC_CAM_OFFLINE'), 'close' => go2rtcLang('GO2RTC_CLOSE'),
            'sound_on' => go2rtcLang('GO2RTC_SOUND_ON'), 'sound_off' => go2rtcLang('GO2RTC_SOUND_OFF'));
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $html = '';
        if ($n == 1) {
            $html .= '<script src="' . $h(ROOTHTML . 'templates/go2rtc/js/viewer.js') . '"></script>'
                . '<style>.go2rtc-embed{position:relative;background:#111;border-radius:6px;overflow:hidden;cursor:pointer}'
                . '.go2rtc-embed .go2rtc-view{display:flex;align-items:center;justify-content:center;width:100%;height:100%}'
                . '.go2rtc-embed .go2rtc-off img{opacity:.35}.go2rtc-embed .go2rtc-off::after{content:attr(data-off);position:absolute;color:#ddd}'
                . '.go2rtc-embed-title{position:absolute;left:0;right:0;bottom:0;padding:4px 8px;color:#fff;font-size:13px;'
                . 'background:linear-gradient(transparent,rgba(0,0,0,.6));pointer-events:none}'
                . '.go2rtc-embed-sound{position:absolute;right:6px;top:6px;z-index:1;width:32px;height:32px;border:0;border-radius:16px;'
                . 'background:rgba(0,0,0,.5);color:#fff;font-size:16px;line-height:1;cursor:pointer;padding:0}</style>';
        }
        $html .= '<div class="go2rtc-embed" id="' . $id . '" style="width:' . $h($width) . ';' . ($height != '' ? 'height:' . $h($height) : 'aspect-ratio:16/9') . '">'
            . '<div class="go2rtc-embed-box" style="width:100%;height:100%" data-off="' . $h($texts['offline']) . '"></div>'
            . ($title != '' ? '<div class="go2rtc-embed-title">' . $h($title) . '</div>' : '')
            . (($mode == 'live' && $links['audio']) ? '<button type="button" class="go2rtc-embed-sound" title="' . $h($texts[$muted ? 'sound_on' : 'sound_off']) . '">' . ($muted ? '🔇' : '🔊') . '</button>' : '')
            . '</div>'
            . '<script>(function(){var el=document.getElementById(' . json_encode($id) . '),L=' . json_encode($links, $flags) . ',t=' . json_encode($texts, $flags)
            . ',mode=' . json_encode($mode) . ',title=' . json_encode($title, $flags) . ',renew=' . json_encode(ROOTHTML . 'ajax/go2rtc.html?op=renew&cam=' . rawurlencode($cam), $flags) . ',v=null,muted=' . ($muted ? 'true' : 'false') . ',startMuted=muted,snd=el.querySelector(".go2rtc-embed-sound");'
            // звук (видео в блоке): кнопка 🔇/🔊; при обновлении ссылок — как был
            . 'function icon(m){muted=m;if(snd){snd.textContent=m?"\uD83D\uDD07":"\uD83D\uDD0A";snd.title=m?t.sound_on:t.sound_off;}}'
            . 'if(snd)snd.addEventListener("click",function(e){e.stopPropagation();if(v)v.mute(!v.mute());});'
            . 'function start(){var box=el.querySelector(".go2rtc-embed-box"),p=go2rtcViewer.capture(box);if(v)v.stop();box.innerHTML="";'
            . 'v=go2rtcViewer(box,{poster:p,audio:L.audio,muted:muted,onmute:icon,frame:L.frame,whep:L.whep,direct:L.direct?{base:L.direct,src:L.hq}:null,player:L.player,wait:L.wait,live:mode=="live",width:mode=="live"?1280:640,interval:3000,lazy:true});}'
            . 'start();if(mode!="live")el.addEventListener("click",function(){go2rtcViewer.open({title:title,poster:go2rtcViewer.capture(el),audio:L.audio,muted:startMuted,frame:L.frame_hq,whep:L.whep,direct:L.direct?{base:L.direct,src:L.hq}:null,player:L.player,wait:L.wait,width:1280,texts:t});});'
            // ссылки на 24 часа: каждые 6 часов — новые (с той же сессией и проверкой прав); просмотр — заново с ними, без чёрного экрана
            . 'setInterval(function(){fetch(renew,{credentials:"same-origin",cache:"no-store"}).then(function(r){return r.json();}).then(function(j){if(j&&j.frame){L=j;start();}}).catch(function(){});},6*3600*1000);'
            . '})();</script>';
        $out['EMBED'] = $html;
    }

    // Ссылки для вставки: кадр и WebRTC (подписанные, на 24 часа), второй поток, прямой адрес go2rtc
    function embedLinks($cam)
    {
        $exp = time() + 24 * 3600;
        $hq = go2rtcCameraHq($cam);
        $cfg = go2rtcConfig();
        return array('ok' => 1, 'frame' => go2rtcSignedUrl('frame', $cam, $exp), 'frame_hq' => go2rtcSignedUrl('frame', $hq, $exp),
            'whep' => go2rtcSignedUrl('whep', $hq, $exp), 'hq' => $hq, 'direct' => go2rtcDirectBase(),
            'player' => ROOTHTML . 'templates/go2rtc/js/video-rtc.js', 'wait' => (int)$cfg['wait'], 'exp' => $exp, 'audio' => go2rtcCameraAudio($cam));
    }

    // Входы api.php/module/go2rtc/… — см. начало файла
    function api($params)
    {
        $request = isset($params['request']) && is_array($params['request']) ? array_values($params['request']) : array();
        $op = isset($request[0]) ? strtolower((string)$request[0]) : '';
        $id = isset($request[1]) ? strtolower((string)$request[1]) : '';
        if (session_id() != '') {
            session_write_close();
        }
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        // камера: по гостевой ссылке (id в пути) или по подписи
        $cam = '';
        $link = array();
        if ($id != '') {
            $link = go2rtcEnabled() ? go2rtcLinkGet($id) : array();
            if (!empty($link['cam']) && $link['kind'] == 'guest' && go2rtcHas($link['cam'])) {
                $cam = $link['cam'];
            }
        } else {
            $src = isset($params['src']) ? (string)$params['src'] : '';
            if ($src != '' && go2rtcSignOk($src, isset($params['exp']) ? (int)$params['exp'] : 0, isset($params['sig']) ? (string)$params['sig'] : '')
                && go2rtcHas($src)) {
                $cam = $src;
            }
        }
        if ($op == 'frame') {
            $width = min(1280, max(0, isset($params['w']) ? (int)$params['w'] : 0));
            // last=1 — последний сохранённый кадр (первый показ без ожидания камеры), нет его — свежий
            $jpeg = ($cam != '' && !empty($params['last'])) ? go2rtcFrameLast($cam, $width) : '';
            if ($jpeg == '' && $cam != '') {
                $jpeg = go2rtcFrame($cam, $width);
            }
            if ($jpeg == '') {
                header('HTTP/1.1 404 Not Found');
                exit;
            }
            header('Content-Type: image/jpeg');
            echo $jpeg;
            exit;
        }
        if ($op == 'whep') {
            if ($cam == '' || $_SERVER['REQUEST_METHOD'] != 'POST') {
                header('HTTP/1.1 404 Not Found');
                exit;
            }
            go2rtcWhepProxy($cam);
        }
        if ($op == 'check') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('ok' => ($cam != '') ? 1 : 0));
            exit;
        }
        if ($op == 'guest') {
            $this->guestPage($cam, $link, $id);
        }
        if ($op == 'embed' && $id == '') {
            $this->embedPage($cam, isset($params['exp']) ? (int)$params['exp'] : 0, isset($params['mode']) && $params['mode'] == 'snapshot' ? 'snapshot' : 'live');
        }
        header('HTTP/1.1 404 Not Found');
        exit;
    }

    // Гостевая страница: одна камера без входа — снимки и WebRTC через сервер, до срока ссылки
    function guestPage($cam, $link, $id)
    {
        go2rtcLoadLanguage();
        header('X-Robots-Tag: noindex, nofollow');
        header('Content-Type: text/html; charset=utf-8');
        $h = function ($text) {
            return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        };
        $style = 'html,body{margin:0;height:100%;background:#000;color:#eee;font:14px -apple-system,Segoe UI,Roboto,sans-serif}'
            . 'body{display:flex;flex-direction:column}header{padding:10px 16px;display:flex;gap:12px;align-items:baseline;flex-wrap:wrap}'
            . 'header b{font-size:16px}header span{color:#9aa}main{flex:1;min-height:0;position:relative;display:flex;align-items:center;justify-content:center}'
            . 'main img,main video{width:100%;height:100%;object-fit:contain}main.go2rtc-off::after{content:attr(data-off);position:absolute;color:#ccc}'
            . 'main.go2rtc-off img{opacity:.35}.gone{display:flex;align-items:center;justify-content:center;height:100vh;color:#ccc;font-size:16px}';
        if ($cam == '') {
            header('HTTP/1.1 404 Not Found');
            echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
                . '<title>' . $h(go2rtcLang('GO2RTC_LINK_GONE')) . '</title><style>' . $style . '</style></head>'
                . '<body><div class="gone">' . $h(go2rtcLang('GO2RTC_LINK_GONE')) . '</div></body></html>';
            exit;
        }
        DebMes('go2rtc: guest link ' . substr($id, 0, 6) . '… to ' . $cam . ' opened from '
            . (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '-'), 'go2rtc');
        $cfg = go2rtcConfig();
        $base = ROOTHTML . 'api.php/module/go2rtc/';
        $until = date('H:i', $link['expires']) . ((date('Y-m-d', $link['expires']) != date('Y-m-d')) ? ' ' . date('d.m', $link['expires']) : '');
        $texts = array('live' => go2rtcLang('GO2RTC_CAM_LIVE'), 'snapshot' => go2rtcLang('GO2RTC_CAM_SNAPSHOT'),
            'offline' => go2rtcLang('GO2RTC_CAM_OFFLINE'), 'gone' => go2rtcLang('GO2RTC_LINK_GONE'));
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer"><title>' . $h($cam) . '</title><style>' . $style . '</style>'
            . '<script src="' . $h(ROOTHTML . 'templates/go2rtc/js/viewer.js') . '"></script></head><body>'
            . '<header><b>📹 ' . $h($cam) . '</b><span id="state"></span><span>' . $h(str_replace('%s', $until, go2rtcLang('GO2RTC_LINK_READY'))) . '</span></header>'
            . '<main id="view" data-off="' . $h($texts['offline']) . '"></main>'
            . '<script>(function(){var base=' . json_encode($base, $flags) . ',id=' . json_encode($id, $flags) . ',t=' . json_encode($texts, $flags)
            . ',expires=' . (int)$link['expires'] . ',state=document.getElementById("state");'
            . 'var v=go2rtcViewer(document.getElementById("view"),{frame:base+"frame/"+id,whep:base+"whep/"+id,live:true,width:1280,controls:true,audio:' . go2rtcCameraAudio($cam) . ',wait:' . (int)$cfg['wait'] . ','
            . 'onstate:function(s){state.textContent=s=="live"?t.live:(s=="offline"?t.offline:t.snapshot);}});state.textContent=t.snapshot;'
            . 'function gone(){v.stop();document.body.innerHTML="";var d=document.createElement("div");d.className="gone";d.textContent=t.gone;document.body.appendChild(d);}'
            . 'setInterval(function(){if(Date.now()>expires*1000)gone();},5000);'
            . 'setInterval(function(){fetch(base+"check/"+id,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){if(!j.ok)gone();}).catch(function(){});},30000);'
            . '})();</script></body></html>';
        exit;
    }

    // Страница одной камеры для внешних панелей (iframe: Lovelace, другие системы) по подписанной ссылке
    // api.php/module/go2rtc/embed?src=…&exp=…&sig=… (go2rtcEmbedUrl): видео (или снимки — &mode=snapshot) на всю страницу
    function embedPage($cam, $exp, $mode)
    {
        go2rtcLoadLanguage();
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex, nofollow');
        if ($cam == '') {
            header('HTTP/1.1 404 Not Found');
            echo '<!doctype html><html><body style="margin:0;background:#000"></body></html>';
            exit;
        }
        $cfg = go2rtcConfig();
        $hq = go2rtcCameraHq($cam);
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
        $opts = array('frame' => go2rtcSignedUrl('frame', $mode == 'live' ? $hq : $cam, $exp), 'whep' => go2rtcSignedUrl('whep', $hq, $exp),
            'direct' => go2rtcDirectBase() != '' ? array('base' => go2rtcDirectBase(), 'src' => $hq) : null,
            'player' => ROOTHTML . 'templates/go2rtc/js/video-rtc.js', 'wait' => (int)$cfg['wait'], 'live' => $mode == 'live',
            'width' => 1280, 'interval' => 3000, 'audio' => go2rtcCameraAudio($cam), 'controls' => $mode == 'live');
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer"><title>' . htmlspecialchars(go2rtcCameraTitle($cam), ENT_QUOTES, 'UTF-8') . '</title>'
            . '<style>html,body{margin:0;height:100%;background:#000}#v{width:100%;height:100%}.go2rtc-off img{opacity:.35}</style>'
            . '<script src="' . htmlspecialchars(ROOTHTML . 'templates/go2rtc/js/viewer.js', ENT_QUOTES, 'UTF-8') . '"></script></head>'
            . '<body><div id="v"></div><script>go2rtcViewer(document.getElementById("v"),' . json_encode($opts, $flags) . ');</script></body></html>';
        exit;
    }

    function processSubscription($event, $details = '')
    {
        if ($event == 'MINUTELY') {
            go2rtcCleanup();
            go2rtcCamerasSync();
            // состояние камер для привязанных объектов — заданием (MINUTELY не ждёт камер)
            $linked = SQLSelectOne("SELECT ID FROM go2rtc_cameras WHERE MISSING=0 AND LINKED_OBJECT!='' LIMIT 1");
            if (!empty($linked['ID'])) {
                setTimeOut('go2rtc_check', "include_once(DIR_MODULES . 'go2rtc/go2rtc_lib.inc.php'); go2rtcCamerasCheck();", 0);
            }
        }
    }

    // Свойство привязанного объекта изменилось (addLinkedProperty): «показать на терминале»
    function propertySetHandle($object, $property, $value)
    {
        $cameras = SQLSelect("SELECT * FROM go2rtc_cameras WHERE LINKED_OBJECT LIKE '" . DBSafe($object) . "' AND SHOW_PROPERTY LIKE '" . DBSafe($property) . "'");
        foreach ($cameras as $camera) {
            go2rtcCameraShowProperty($camera, $value);
        }
    }

    function install($parent_name = '')
    {
        subscribeToEvent($this->name, 'MINUTELY');
        parent::install($parent_name);
        // настройки камер из «Сообщений» (или из полной версии «Терминалов») — когда запись модуля уже есть
        $this->migrateSettings();
        go2rtcSecret();
        go2rtcRulesMigrate();
    }

    function uninstall()
    {
        unsubscribeFromEvent($this->name, 'MINUTELY');
        if (function_exists('go2rtcLocal') && go2rtcLocal()) {
            go2rtcLocalStop();
        }
        SQLDropTable('go2rtc_links');
        SQLDropTable('go2rtc_cameras');
        parent::uninstall();
    }

    function dbInstall($data = '')
    {
        $data = <<<EOD
 go2rtc_links: ID int(10) unsigned NOT NULL auto_increment
 go2rtc_links: LINK_ID varchar(32) NOT NULL DEFAULT ''
 go2rtc_links: CAM varchar(100) NOT NULL DEFAULT ''
 go2rtc_links: KIND char(10) NOT NULL DEFAULT ''
 go2rtc_links: MEMBERS text
 go2rtc_links: BY_KEY varchar(100) NOT NULL DEFAULT ''
 go2rtc_links: BY_TITLE varchar(255) NOT NULL DEFAULT ''
 go2rtc_links: CREATED int(10) NOT NULL DEFAULT '0'
 go2rtc_links: EXPIRES int(10) NOT NULL DEFAULT '0'
 go2rtc_links: INDEX (LINK_ID)
 go2rtc_links: INDEX (CAM)

 go2rtc_cameras: ID int(10) unsigned NOT NULL auto_increment
 go2rtc_cameras: NAME varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: TITLE varchar(255) NOT NULL DEFAULT ''
 go2rtc_cameras: PRIORITY int(10) NOT NULL DEFAULT '0'
 go2rtc_cameras: HIDDEN int(3) NOT NULL DEFAULT '0'
 go2rtc_cameras: AUDIO int(3) NOT NULL DEFAULT '1'
 go2rtc_cameras: MISSING int(3) NOT NULL DEFAULT '0'
 go2rtc_cameras: HQ_NAME varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: LINKED_OBJECT varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: LINKED_PROPERTY varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: LINKED_METHOD varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: ONLINE_PROPERTY varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: VIEWERS_PROPERTY varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: SHOW_PROPERTY varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: STATE_METHOD varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: ONLINE int(3) NOT NULL DEFAULT '0'
 go2rtc_cameras: VIEWERS int(10) NOT NULL DEFAULT '0'
 go2rtc_cameras: CHECKED int(10) NOT NULL DEFAULT '0'
 go2rtc_cameras: ADDED int(10) NOT NULL DEFAULT '0'
 go2rtc_cameras: LOCAL int(3) NOT NULL DEFAULT '0'
 go2rtc_cameras: SOURCE text
 go2rtc_cameras: SOURCE_USER varchar(100) NOT NULL DEFAULT ''
 go2rtc_cameras: SOURCE_PASSWORD varchar(255) NOT NULL DEFAULT ''
 go2rtc_cameras: SOURCE_HQ text
 go2rtc_cameras: INDEX (NAME)
 go2rtc_cameras: INDEX (LINKED_OBJECT)
EOD;
        parent::dbInstall($data);
    }

    // Однократный перенос настроек камер: из модуля «Сообщения» (app_messages), иначе из полной версии «Терминалов».
    // Переносится то, чего ещё нет в своей конфигурации; действующие ссылки (временный доступ, WebTorrent) — в таблицу
    function migrateSettings()
    {
        $this->getConfig();
        if (!empty($this->config['MIGRATED'])) {
            return;
        }
        $moved = array();
        foreach (array('app_messages', 'terminals') as $source) {
            $rec = SQLSelectOne("SELECT DATA FROM project_modules WHERE NAME='" . DBSafe($source) . "'");
            $old = !empty($rec['DATA']) ? @unserialize($rec['DATA']) : array();
            if (!is_array($old) || empty($old['GO2RTC_URL'])) {
                continue;
            }
            foreach ($old as $key => $value) {
                if (preg_match('/^(GO2RTC_(URL|USER|PASSWORD|DEVICE_URL|RTSP_PORT|RTSP_USER|RTSP_PASSWORD|WEBTORRENT|WT_MINUTES|WAIT)|CAMERA_ACCESS)$/', (string)$key)
                    && !isset($this->config[$key])) {
                    $this->config[$key] = $value;
                    $moved[] = $key;
                }
            }
            $links = (isset($old['GO2RTC_LINKS']) && is_array($old['GO2RTC_LINKS'])) ? $old['GO2RTC_LINKS'] : array();
            foreach ($links as $id => $link) {
                if (is_array($link) && !empty($link['cam']) && (int)$link['expires'] > time() && preg_match('/^[0-9a-f]{32}$/', (string)$id)) {
                    $row = array('LINK_ID' => (string)$id, 'CAM' => (string)$link['cam'], 'KIND' => ($link['kind'] == 'guest') ? 'guest' : 'chat',
                        'MEMBERS' => json_encode(isset($link['members']) ? array_values((array)$link['members']) : array()),
                        'BY_KEY' => isset($link['by']) ? (string)$link['by'] : '', 'BY_TITLE' => isset($link['by_title']) ? (string)$link['by_title'] : '',
                        'CREATED' => isset($link['created']) ? (int)$link['created'] : time(), 'EXPIRES' => (int)$link['expires']);
                    SQLInsert('go2rtc_links', $row);
                    $moved[] = 'link ' . substr($id, 0, 6);
                }
            }
            break;
        }
        $this->config['MIGRATED'] = 1;
        $this->saveConfig();
        if (count($moved)) {
            DebMes('Migrated camera settings: ' . implode(', ', $moved), 'go2rtc');
        }
    }
}
