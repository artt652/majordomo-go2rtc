<?php
/*
	Language file for the go2rtc module: English
*/

$dictionary = array(
	'GO2RTC_API_LOGIN'          => 'go2rtc API login',
	'GO2RTC_API_LOGIN_HELP'     => 'Only if go2rtc is on another machine or local_auth is on. Used by the server only — never sent to devices or pages.',
	'GO2RTC_CAMERA'             => 'Camera',
	'GO2RTC_CAMERA_ACCESS_HELP' => 'The administrator (control panel, or a MajorDoMo user with admin rights) sees all cameras. Others see only the cameras marked here; a camera without marks is visible to the administrator only. Scenarios are not limited.',
	'GO2RTC_CAMERA_ADMIN_ONLY'  => 'administrator only',
	'GO2RTC_CAMERA_EVERYONE'    => 'Everyone',
	'GO2RTC_CAMERA_TERMINALS'   => 'Terminals',
	'GO2RTC_CAMERA_TITLE'       => 'Camera',
	'GO2RTC_CAMERA_USERS'       => 'Users',
	'GO2RTC_CAM_LIVE'           => 'Live',
	'GO2RTC_CAM_OFFLINE'        => 'No connection to the camera',
	'GO2RTC_CAM_SNAPSHOT'       => 'Snapshot, refreshed every few seconds',
	'GO2RTC_CLOSE'              => 'Close',
	'GO2RTC_DEVICE_URL'         => 'go2rtc address for devices',
	'GO2RTC_DEVICE_URL_HELP'    => 'How terminals reach go2rtc in the home network. Empty — the server address with the API port (shown in the field).',
	'GO2RTC_HELP'               => 'MajorDoMo has no cameras of its own: streams are set up in go2rtc, and the Messages page and terminals take them from there by name. Camera addresses and passwords stay in go2rtc.',
	'GO2RTC_LINKS_EMPTY'        => 'No active links.',
	'GO2RTC_LINKS_HELP'         => 'Temporary access for chat members and guest links (created on the Messages page), WebTorrent links. A closed link stops working at once.',
	'GO2RTC_LINK_BY'            => 'Created by',
	'GO2RTC_LINK_CHAT'          => 'chat members',
	'GO2RTC_LINK_CLOSE'         => 'Close',
	'GO2RTC_LINK_CLOSED'        => 'Link closed',
	'GO2RTC_LINK_CLOSE_CONFIRM' => 'Close the link? It will stop working at once.',
	'GO2RTC_LINK_GONE'          => 'This link no longer works',
	'GO2RTC_LINK_GUEST'         => 'guest link',
	'GO2RTC_LINK_KIND'          => 'Kind',
	'GO2RTC_LINK_READY'         => 'Link works until %s',
	'GO2RTC_LINK_UNTIL'         => 'Until',
	'GO2RTC_LOGIN'              => 'login',
	'GO2RTC_NOT_SET'            => 'Cameras are not set up: enter the go2rtc address on the Settings tab.',
	'GO2RTC_NO_CAMERAS'         => 'go2rtc returned no streams (or does not respond).',
	'GO2RTC_PASSWORD'           => 'password',
	'GO2RTC_PORT'               => 'Port',
	'GO2RTC_RTSP_HELP'          => 'RTSP server of go2rtc: port and its own login (view only) — terminals (VLC, Kodi, PiPup) watch cameras over RTSP.',
	'GO2RTC_SAVE'               => 'Save',
	'GO2RTC_STREAMS'            => 'Streams in go2rtc: %d (0 — no connection or no streams)',
	'GO2RTC_TAB_ACCESS'         => 'Access',
	'GO2RTC_TAB_CAMERAS'        => 'Cameras',
	'GO2RTC_TAB_LINKS'          => 'Links',
	'GO2RTC_TAB_SETTINGS'       => 'Settings',
	'GO2RTC_TITLE'              => 'Cameras (go2rtc)',
	'GO2RTC_URL'                => 'go2rtc address for the server',
	'GO2RTC_URL_HELP'           => 'Empty — no cameras. On the same machine as MajorDoMo — http://127.0.0.1:1984 (go2rtc does not ask localhost for a password unless local_auth is on).',
	'GO2RTC_WAIT'               => 'Wait for the first camera frame, seconds',
	'GO2RTC_WAIT_HELP'          => 'How long to wait for a camera to answer: snapshot, start of live view. Remote and cloud cameras may wake up in 10–20 s. Empty — 6 s. A camera that did not answer in time is considered unavailable for 30 s.',
	'GO2RTC_WEBTORRENT'         => 'Outside viewing via WebTorrent',
	'GO2RTC_WEBTORRENT_HELP'    => 'A temporary link to watch a camera outside home without opening go2rtc to the internet (go2rtc.org player, public tracker for the connection, video goes directly). The link is access: it is closed after the time below.',
	'GO2RTC_WT_MINUTES'         => 'Outside link lifetime, minutes',
);

foreach ($dictionary as $key => $value) {
	if (!defined('LANG_' . $key)) {
		define('LANG_' . $key, $value);
	}
}
