<?php
/*
	Language file for the go2rtc module: Russian
*/

$dictionary = array(
	'GO2RTC_API_LOGIN'          => 'Логин API go2rtc',
	'GO2RTC_API_LOGIN_HELP'     => 'Только если go2rtc на другой машине или включён local_auth. Нужен только серверу — устройствам и страницам не передаётся.',
	'GO2RTC_CAMERA'             => 'Камера',
	'GO2RTC_CAMERA_ACCESS_HELP' => 'Администратор (панель управления или пользователь MajorDoMo с правами администратора) видит все камеры. Остальные — только отмеченные здесь; камера без отметок видна только администратору. Сценарии не ограничены.',
	'GO2RTC_CAMERA_ADMIN_ONLY'  => 'только администратор',
	'GO2RTC_CAMERA_EVERYONE'    => 'Всем',
	'GO2RTC_CAMERA_TERMINALS'   => 'Терминалы',
	'GO2RTC_CAMERA_TITLE'       => 'Камера',
	'GO2RTC_CAMERA_USERS'       => 'Пользователи',
	'GO2RTC_CAM_LIVE'           => 'Прямой эфир',
	'GO2RTC_CAM_OFFLINE'        => 'Нет связи с камерой',
	'GO2RTC_CAM_SNAPSHOT'       => 'Снимок, обновляется раз в несколько секунд',
	'GO2RTC_CLOSE'              => 'Закрыть',
	'GO2RTC_DEVICE_URL'         => 'Адрес go2rtc для устройств',
	'GO2RTC_DEVICE_URL_HELP'    => 'Как терминалы видят go2rtc в домашней сети. Пусто — адрес сервера с портом API (подсказка в поле).',
	'GO2RTC_HELP'               => 'Своих камер у MajorDoMo нет: потоки заводятся в go2rtc, а «Сообщения» и терминалы берут их оттуда по имени. Адреса и пароли камер остаются в go2rtc.',
	'GO2RTC_LINKS_EMPTY'        => 'Действующих ссылок нет.',
	'GO2RTC_LINKS_HELP'         => 'Временный доступ участникам чата и гостевые ссылки (создаются на странице «Сообщения»), ссылки WebTorrent. Закрытая ссылка перестаёт работать сразу.',
	'GO2RTC_LINK_BY'            => 'Кто выдал',
	'GO2RTC_LINK_CHAT'          => 'участникам чата',
	'GO2RTC_LINK_CLOSE'         => 'Закрыть',
	'GO2RTC_LINK_CLOSED'        => 'Ссылка закрыта',
	'GO2RTC_LINK_CLOSE_CONFIRM' => 'Закрыть ссылку? Она сразу перестанет работать.',
	'GO2RTC_LINK_GONE'          => 'Ссылка больше не действует',
	'GO2RTC_LINK_GUEST'         => 'гостевая ссылка',
	'GO2RTC_LINK_KIND'          => 'Вид',
	'GO2RTC_LINK_READY'         => 'Ссылка работает до %s',
	'GO2RTC_LINK_UNTIL'         => 'До',
	'GO2RTC_LOGIN'              => 'логин',
	'GO2RTC_NOT_SET'            => 'Камеры не настроены: укажите адрес go2rtc на вкладке «Настройки».',
	'GO2RTC_NO_CAMERAS'         => 'go2rtc не отдал ни одного потока (или не отвечает).',
	'GO2RTC_PASSWORD'           => 'пароль',
	'GO2RTC_PORT'               => 'Порт',
	'GO2RTC_RTSP_HELP'          => 'RTSP-сервер go2rtc: порт и его собственный логин (только просмотр) — терминалы (VLC, Kodi, PiPup) смотрят камеры по RTSP.',
	'GO2RTC_SAVE'               => 'Сохранить',
	'GO2RTC_STREAMS'            => 'Потоков в go2rtc: %d (0 — нет связи или потоков нет)',
	'GO2RTC_TAB_ACCESS'         => 'Права',
	'GO2RTC_TAB_CAMERAS'        => 'Камеры',
	'GO2RTC_TAB_LINKS'          => 'Ссылки',
	'GO2RTC_TAB_SETTINGS'       => 'Настройки',
	'GO2RTC_TITLE'              => 'Камеры (go2rtc)',
	'GO2RTC_URL'                => 'Адрес go2rtc для сервера',
	'GO2RTC_URL_HELP'           => 'Пусто — камер нет. На той же машине, что MajorDoMo, — http://127.0.0.1:1984 (с самой машины go2rtc пароль не спрашивает, если не включён local_auth).',
	'GO2RTC_WAIT'               => 'Ожидание первого кадра камеры, с',
	'GO2RTC_WAIT_HELP'          => 'Сколько ждать ответа камеры: снимок, начало живого просмотра. Удалённые и облачные камеры могут просыпаться 10–20 с. Пусто — 6 с. Не ответившая за это время камера 30 с считается недоступной.',
	'GO2RTC_WEBTORRENT'         => 'Внешний просмотр через WebTorrent',
	'GO2RTC_WEBTORRENT_HELP'    => 'Временная ссылка, чтобы смотреть камеру вне дома, не открывая go2rtc в интернет (плеер go2rtc.org, публичный трекер для соединения, видео идёт напрямую). Ссылка — это доступ: закрывается через срок ниже.',
	'GO2RTC_WT_MINUTES'         => 'Срок внешней ссылки, минут',
);

foreach ($dictionary as $key => $value) {
	if (!defined('LANG_' . $key)) {
		define('LANG_' . $key, $value);
	}
}
