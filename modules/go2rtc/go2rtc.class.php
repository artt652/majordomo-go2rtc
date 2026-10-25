<?php
/**
 * Камеры (go2rtc) — модуль MajorDoMo.
 *
 * Панель управления: «Камеры» (просмотр), «Настройки» (go2rtc), «Права» (кому какие камеры), «Ссылки» (временный доступ,
 * гостевые ссылки, WebTorrent). Функции для других модулей и сценариев — go2rtc_lib.inc.php (константа GO2RTC_API).
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
        }
        $out['ACTION'] = $this->action;
        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . '/' . $this->name . '.html', $this->data, $this);
        $this->result = $p->result;
    }

    function admin(&$out)
    {
        $this->getConfig();
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
            $this->saveConfig();
            @unlink(ROOT . 'cms/cached/go2rtc_streams.json');
            $out['GO2RTC_SAVED'] = 1;
            $out['TAB'] = 'tab_settings';
        }
        // права на камеры: камера => все / терминалы / пользователи (без записи — только администратору)
        if (gr('access_save')) {
            $posted = gr('access');
            $posted = is_array($posted) ? $posted : array();
            $access = array();
            foreach (go2rtcStreams() as $name) {
                $key = md5($name);
                $rule = isset($posted[$key]) && is_array($posted[$key]) ? $posted[$key] : array();
                $item = array(
                    'all' => !empty($rule['all']) ? 1 : 0,
                    'terminals' => array_values(array_filter(isset($rule['terminals']) ? (array)$rule['terminals'] : array(), 'is_string')),
                    'users' => array_values(array_unique(array_map('intval', isset($rule['users']) ? (array)$rule['users'] : array()))),
                );
                if ($item['all'] || count($item['terminals']) || count($item['users'])) {
                    $access[$name] = $item;
                }
            }
            go2rtcAccessSave($access);
            $this->getConfig();
            $out['ACCESS_SAVED'] = 1;
            $out['TAB'] = 'tab_access';
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

        foreach (array('GO2RTC_URL', 'GO2RTC_USER', 'GO2RTC_PASSWORD', 'GO2RTC_DEVICE_URL', 'GO2RTC_RTSP_PORT', 'GO2RTC_RTSP_USER',
            'GO2RTC_RTSP_PASSWORD', 'GO2RTC_WT_MINUTES', 'GO2RTC_WAIT') as $key) {
            $out[$key] = $h(isset($this->config[$key]) ? $this->config[$key] : '');
        }
        $out['GO2RTC_WEBTORRENT'] = !empty($this->config['GO2RTC_WEBTORRENT']) ? 1 : 0;
        $out['ENABLED'] = go2rtcEnabled() ? 1 : 0;
        $cfg = go2rtcConfig();
        $out['WAIT'] = (int)$cfg['wait'];
        $out['CAMERAS'] = array();
        $out['GRID'] = array();
        if (go2rtcEnabled()) {
            $streams = go2rtcStreams();
            $out['GO2RTC_STATUS'] = $h(sprintf(go2rtcLang('GO2RTC_STREAMS'), count($streams)));
            $out['GO2RTC_DEVICE_AUTO'] = $h(go2rtcDeviceBase());
            $access = go2rtcAccess();
            $all_terms = SQLSelect("SELECT NAME, TITLE FROM terminals ORDER BY TITLE");
            $all_users = SQLSelect("SELECT ID, NAME, USERNAME FROM users ORDER BY NAME");
            // просмотр в панели: подписанные ссылки на 12 часов (страница открыта долго)
            $exp = time() + 12 * 3600;
            foreach ($streams as $name) {
                $rule = isset($access[$name]) ? $access[$name] : array();
                $who = array();
                if (!empty($rule['all'])) {
                    $who[] = go2rtcLang('GO2RTC_CAMERA_EVERYONE');
                } else {
                    foreach ($all_terms as $t) {
                        if (!empty($rule['terminals']) && in_array($t['NAME'], (array)$rule['terminals'], true)) {
                            $who[] = $t['TITLE'];
                        }
                    }
                    foreach ($all_users as $u) {
                        if (!empty($rule['users']) && in_array((int)$u['ID'], array_map('intval', (array)$rule['users']), true)) {
                            $who[] = $u['NAME'] != '' ? $u['NAME'] : $u['USERNAME'];
                        }
                    }
                }
                $out['GRID'][] = array('NAME' => $h($name), 'FRAME' => $h(go2rtcSignedUrl('frame', $name, $exp)),
                    'WHEP' => $h(go2rtcSignedUrl('whep', $name, $exp)),
                    'WHO' => $h(count($who) ? implode(', ', $who) : go2rtcLang('GO2RTC_CAMERA_ADMIN_ONLY')));
                $camera = array('NAME' => $h($name), 'KEY' => md5($name), 'ALL' => !empty($rule['all']) ? 1 : 0,
                    'TERMINALS' => array(), 'USERS' => array());
                foreach ($all_terms as $t) {
                    $camera['TERMINALS'][] = array('KEY' => $camera['KEY'], 'NAME' => $h($t['NAME']), 'TITLE' => $h($t['TITLE']),
                        'ON' => (!empty($rule['terminals']) && in_array($t['NAME'], (array)$rule['terminals'], true)) ? 1 : 0);
                }
                foreach ($all_users as $u) {
                    $camera['USERS'][] = array('KEY' => $camera['KEY'], 'ID' => (int)$u['ID'], 'TITLE' => $h($u['NAME'] != '' ? $u['NAME'] : $u['USERNAME']),
                        'ON' => (!empty($rule['users']) && in_array((int)$u['ID'], array_map('intval', (array)$rule['users']), true)) ? 1 : 0);
                }
                $out['CAMERAS'][] = $camera;
            }
        }
        $out['HAS_CAMERAS'] = count($out['CAMERAS']) ? 1 : 0;
        if (!$out['ENABLED'] && $out['TAB'] == '') {
            $out['TAB'] = 'tab_settings';
        }

        // действующие ссылки: временный доступ участникам чата, гостевые, WebTorrent
        $out['LINKS'] = array();
        $fmt = function ($time) {
            return date('Y-m-d', $time) == date('Y-m-d') ? date('H:i', $time) : date('d.m H:i', $time);
        };
        foreach (go2rtcLinks() as $id => $link) {
            $out['LINKS'][] = array('ID' => $id, 'CAM' => $h($link['cam']),
                'KIND' => $h(go2rtcLang($link['kind'] == 'guest' ? 'GO2RTC_LINK_GUEST' : 'GO2RTC_LINK_CHAT')),
                'BY' => $h($link['by_title']), 'UNTIL' => $fmt($link['expires']), 'SHARE' => 0);
        }
        foreach (go2rtcShares() as $name => $expires) {
            $out['LINKS'][] = array('ID' => $h($name), 'CAM' => $h($name), 'KIND' => 'WebTorrent', 'BY' => '', 'UNTIL' => $fmt($expires), 'SHARE' => 1);
        }
        $out['HAS_LINKS'] = count($out['LINKS']) ? 1 : 0;
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
            $jpeg = ($cam != '') ? go2rtcFrame($cam, min(1280, max(0, isset($params['w']) ? (int)$params['w'] : 0))) : '';
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
            . 'var v=go2rtcViewer(document.getElementById("view"),{frame:base+"frame/"+id,whep:base+"whep/"+id,live:true,width:1280,controls:true,wait:' . (int)$cfg['wait'] . ','
            . 'onstate:function(s){state.textContent=s=="live"?t.live:(s=="offline"?t.offline:t.snapshot);}});state.textContent=t.snapshot;'
            . 'function gone(){v.stop();document.body.innerHTML="";var d=document.createElement("div");d.className="gone";d.textContent=t.gone;document.body.appendChild(d);}'
            . 'setInterval(function(){if(Date.now()>expires*1000)gone();},5000);'
            . 'setInterval(function(){fetch(base+"check/"+id,{cache:"no-store"}).then(function(r){return r.json();}).then(function(j){if(!j.ok)gone();}).catch(function(){});},30000);'
            . '})();</script></body></html>';
        exit;
    }

    function processSubscription($event, $details = '')
    {
        if ($event == 'MINUTELY') {
            go2rtcCleanup();
        }
    }

    function install($parent_name = '')
    {
        subscribeToEvent($this->name, 'MINUTELY');
        parent::install($parent_name);
        // настройки камер из «Сообщений» (или из полной версии «Терминалов») — когда запись модуля уже есть
        $this->migrateSettings();
        go2rtcSecret();
    }

    function uninstall()
    {
        unsubscribeFromEvent($this->name, 'MINUTELY');
        SQLDropTable('go2rtc_links');
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
