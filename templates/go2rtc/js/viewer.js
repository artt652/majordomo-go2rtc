/*
 * Просмотр камеры go2rtc (модуль go2rtc): снимки, а если получилось — живое видео WebRTC (WHEP через сервер).
 * Один для страницы «Камеры» в панели, гостевой страницы и других модулей (Lovelace, «Сообщения»).
 *
 *   var v = go2rtcViewer(document.getElementById('box'), {
 *       frame: '/api.php/module/go2rtc/frame?src=…&exp=…&sig=…',  // кадр (подписанная ссылка модуля)
 *       whep: '/api.php/module/go2rtc/whep?src=…&exp=…&sig=…',    // согласование WebRTC; пусто — только снимки
 *       live: true,          // сразу пробовать видео (false — только снимки, например в сетке)
 *       width: 640,          // ширина снимка (0 — как у камеры)
 *       interval: 2000,      // снимок раз в … мс
 *       wait: 6,             // ожидание первого кадра камеры, с (настройка модуля)
 *       onstate: function (state) {}  // 'snapshot' | 'live' | 'offline'
 *   });
 *   v.stop();  // остановить (снимки и видео)
 *
 * Снимок: следующий — когда загрузился прошлый (без мигания: новый кадр подменяет старый после загрузки);
 * «нет связи» — после 20 с неудач подряд. Видео: не началось за ожидание камеры + 4 с — остаются снимки.
 */
(function () {
	'use strict';

	function add(url, params) {
		return url + (url.indexOf('?') >= 0 ? '&' : '?') + params;
	}

	window.go2rtcViewer = function (box, opts) {
		opts = opts || {};
		var interval = opts.interval || 2000;
		var wait = opts.wait || 6;
		var stopped = false, loading = false, live = false, failSince = 0, state = '';
		var timer = null, pc = null;

		box.classList.add('go2rtc-view');
		var shot = document.createElement('img');
		shot.alt = '';
		var video = document.createElement('video');
		video.autoplay = true;
		video.muted = true;
		video.playsInline = true;
		video.setAttribute('playsinline', '');
		video.controls = !!opts.controls;
		video.style.display = 'none';
		box.appendChild(shot);
		box.appendChild(video);

		function setState(s) {
			if (s == state) return;
			state = s;
			box.classList.toggle('go2rtc-off', s == 'offline');
			box.classList.toggle('go2rtc-live', s == 'live');
			if (opts.onstate) opts.onstate(s);
		}

		function frame() {
			if (stopped || loading || live || !opts.frame) return;
			loading = true;
			var next = new Image();
			next.onload = function () {
				loading = false;
				if (stopped) return;
				failSince = 0;
				next.alt = '';
				shot.parentNode.replaceChild(next, shot);
				shot = next;
				if (!live) setState('snapshot');
			};
			next.onerror = function () {
				loading = false;
				if (!failSince) failSince = Date.now();
				if (Date.now() - failSince >= 20000) setState('offline');
			};
			next.src = add(opts.frame, (opts.width ? 'w=' + opts.width + '&' : '') + 't=' + Date.now());
		}

		function startLive() {
			if (!opts.whep || !window.RTCPeerConnection || pc) return;
			pc = new RTCPeerConnection({iceServers: []});
			pc.addTransceiver('video', {direction: 'recvonly'});
			pc.addTransceiver('audio', {direction: 'recvonly'});
			pc.ontrack = function (e) {
				if (!video.srcObject) video.srcObject = e.streams[0] || new MediaStream([e.track]);
			};
			video.addEventListener('playing', function () {
				if (stopped) return;
				live = true;
				shot.style.display = 'none';
				video.style.display = '';
				setState('live');
			});
			pc.createOffer().then(function (o) { return pc.setLocalDescription(o); }).then(function () {
				// кандидаты ICE: ждём сбора, но не дольше 2 с (0,3 с после первого кандидата)
				return new Promise(function (ok) {
					if (pc.iceGatheringState == 'complete') { ok(); return; }
					var to = setTimeout(ok, 2000), first = null;
					var done = function () { clearTimeout(to); clearTimeout(first); ok(); };
					pc.addEventListener('icecandidate', function (e) { if (e.candidate && !first) first = setTimeout(done, 300); });
					pc.addEventListener('icegatheringstatechange', function () { if (pc.iceGatheringState == 'complete') done(); });
				});
			}).then(function () {
				return fetch(opts.whep, {method: 'POST', body: pc.localDescription.sdp, headers: {'Content-Type': 'application/sdp'}, credentials: 'same-origin'});
			}).then(function (r) {
				if (!r.ok) throw new Error('whep');
				return r.text();
			}).then(function (sdp) {
				return pc.setRemoteDescription({type: 'answer', sdp: sdp});
			}).catch(function () {
				stopLive();
			});
			setTimeout(function () { if (!live) stopLive(); }, (wait + 4) * 1000);
		}

		function stopLive() {
			if (pc) { try { pc.close(); } catch (e) {} }
			pc = null;
			live = false;
			video.srcObject = null;
			video.style.display = 'none';
			shot.style.display = '';
		}

		frame();
		timer = setInterval(frame, interval);
		if (opts.live) startLive();

		return {
			live: startLive,
			snapshots: function () { stopLive(); frame(); },
			stop: function () {
				stopped = true;
				clearInterval(timer);
				stopLive();
			}
		};
	};
})();
