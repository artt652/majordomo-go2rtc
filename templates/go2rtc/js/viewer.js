/*
 * Просмотр камеры go2rtc (модуль go2rtc): снимки, а если получилось — живое видео.
 * Один для страницы «Камеры» в панели, гостевой страницы, вставок в сцены и других модулей.
 *
 *   var v = go2rtcViewer(document.getElementById('box'), {
 *       frame: '/api.php/module/go2rtc/frame?src=…&exp=…&sig=…',  // кадр (подписанная ссылка модуля)
 *       whep: '/api.php/module/go2rtc/whep?src=…&exp=…&sig=…',    // WebRTC через сервер; пусто — без него
 *       direct: {base: 'http://192.168.1.10:1984', src: 'door'},  // плеер go2rtc напрямую (video-rtc.js:
 *                                                                // WebRTC, MSE, HLS, MJPEG); пусто — без него
 *       player: '/templates/go2rtc/js/video-rtc.js',             // где лежит плеер go2rtc
 *       live: true,          // сразу живое видео (false — только снимки, например в плитках)
 *       width: 640,          // ширина снимка (0 — как у камеры)
 *       interval: 2000,      // снимок раз в … мс
 *       wait: 6,             // ожидание первого кадра камеры, с (настройка модуля)
 *       lazy: true,          // снимки — только пока элемент виден на экране
 *       controls: false,     // кнопки видео
 *       onstate: function (state, how) {}  // state: 'snapshot' | 'live' | 'offline'; how: 'direct' | 'whep'
 *   });
 *   v.stop();  // остановить снимки и видео
 *
 * Живое видео: сначала плеер go2rtc напрямую (браузер видит go2rtc, у его API нет пароля; по HTTPS к http-адресу
 * go2rtc браузер не пустит — тогда сразу дальше), не пошло за ожидание камеры + 4 с — WebRTC через сервер (WHEP),
 * не пошло и оно — остаются снимки. Снимок: следующий — когда загрузился прошлый (без мигания), «нет связи» —
 * после 20 с неудач подряд. Файл без новых конструкций языка — в старом браузере работают хотя бы снимки.
 */
(function () {
	'use strict';

	function add(url, params) {
		return url + (url.indexOf('?') >= 0 ? '&' : '?') + params;
	}

	// плеер go2rtc (ES-модуль) — один раз на страницу; import() — через Function, чтобы старый браузер
	// мог разобрать этот файл
	var playerLoading = null;
	function loadPlayer(url) {
		if (!playerLoading) {
			playerLoading = new Promise(function (ok, fail) {
				var imp;
				try { imp = new Function('u', 'return import(u)'); } catch (e) { fail(e); return; }
				imp(url).then(function (m) {
					if (!window.customElements.get('go2rtc-video')) {
						window.customElements.define('go2rtc-video', m.VideoRTC);
					}
					ok();
				}, fail);
			});
		}
		return playerLoading;
	}

	window.go2rtcViewer = function (box, opts) {
		opts = opts || {};
		var interval = opts.interval || 2000;
		var wait = opts.wait || 6;
		var stopped = false, loading = false, live = '', failSince = 0, state = '', visible = true;
		var timer = null, pc = null, rtc = null, observer = null, liveTimer = null;

		box.classList.add('go2rtc-view');
		var shot = document.createElement('img');
		shot.alt = '';
		shot.style.cssText = 'width:100%;height:100%;object-fit:contain';
		var video = document.createElement('video');
		video.autoplay = true;
		video.muted = true;
		video.playsInline = true;
		video.setAttribute('playsinline', '');
		video.controls = !!opts.controls;
		video.style.cssText = 'width:100%;height:100%;object-fit:contain;display:none';
		box.appendChild(shot);
		box.appendChild(video);

		function setState(s, how) {
			if (s == state) return;
			state = s;
			box.classList.toggle('go2rtc-off', s == 'offline');
			box.classList.toggle('go2rtc-live', s == 'live');
			if (opts.onstate) opts.onstate(s, how || '');
		}

		function frame() {
			if (stopped || loading || live || !opts.frame || !visible) return;
			loading = true;
			var next = new Image();
			next.onload = function () {
				loading = false;
				if (stopped || live) return;
				failSince = 0;
				next.alt = '';
				next.style.cssText = shot.style.cssText;
				shot.parentNode.replaceChild(next, shot);
				shot = next;
				setState('snapshot');
			};
			next.onerror = function () {
				loading = false;
				if (!failSince) failSince = Date.now();
				if (Date.now() - failSince >= 20000) setState('offline');
			};
			next.src = add(opts.frame, (opts.width ? 'w=' + opts.width + '&' : '') + 't=' + Date.now());
		}

		function showLive(how, el) {
			if (stopped) return;
			clearTimeout(liveTimer);
			live = how;
			shot.style.display = 'none';
			el.style.display = '';
			setState('live', how);
		}

		// 1) плеер go2rtc напрямую
		function startDirect() {
			var d = opts.direct;
			if (!d || !d.base || !opts.player || !window.customElements || !window.Promise
				|| (location.protocol == 'https:' && /^http:/i.test(d.base))) {
				return false;
			}
			loadPlayer(opts.player).then(function () {
				if (stopped || live) return;
				rtc = document.createElement('go2rtc-video');
				rtc.mode = 'webrtc,mse,hls,mjpeg';
				rtc.background = true;   // видимость решает сама страница
				rtc.pcConfig = {bundlePolicy: 'max-bundle', iceServers: [], sdpSemantics: 'unified-plan'};
				rtc.style.display = 'none';
				rtc.style.width = '100%';
				rtc.style.height = '100%';
				box.appendChild(rtc);    // создаёт свой <video>
				if (rtc.video) {
					rtc.video.muted = true;
					rtc.video.controls = !!opts.controls;
					rtc.video.style.objectFit = 'contain';
					rtc.video.addEventListener('playing', function () { if (!live) showLive('direct', rtc); });
				}
				rtc.src = d.base.replace(/\/+$/, '') + '/api/ws?src=' + encodeURIComponent(d.src);
			}).catch(function () {
				stopDirect();
				startWhep();
			});
			liveTimer = setTimeout(function () {
				if (!live) {
					stopDirect();
					startWhep();
				}
			}, (wait + 4) * 1000);
			return true;
		}

		function stopDirect() {
			if (rtc) {
				// background: плеер сам не отключается при удалении — закрыть соединение и не переподключаться
				try {
					clearTimeout(rtc.reconnectTID);
					rtc.reconnectTID = 0;
					rtc.ondisconnect();
				} catch (e) {}
				if (rtc.parentNode) rtc.parentNode.removeChild(rtc);
			}
			rtc = null;
		}

		// 2) WebRTC через сервер (WHEP: согласование — модуль, видео — с go2rtc напрямую)
		function startWhep() {
			if (stopped || live || !opts.whep || !window.RTCPeerConnection || pc) return;
			pc = new RTCPeerConnection({iceServers: []});
			pc.addTransceiver('video', {direction: 'recvonly'});
			pc.addTransceiver('audio', {direction: 'recvonly'});
			pc.ontrack = function (e) {
				if (!video.srcObject) video.srcObject = e.streams[0] || new MediaStream([e.track]);
			};
			video.addEventListener('playing', function () { if (!live && pc) showLive('whep', video); });
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
				stopWhep();
			});
			liveTimer = setTimeout(function () { if (!live) stopWhep(); }, (wait + 4) * 1000);
		}

		function stopWhep() {
			if (pc) { try { pc.close(); } catch (e) {} }
			pc = null;
			video.srcObject = null;
		}

		function startLive() {
			if (live || stopped) return;
			if (!startDirect()) startWhep();
		}

		function stopLive() {
			clearTimeout(liveTimer);
			stopDirect();
			stopWhep();
			live = '';
			video.style.display = 'none';
			shot.style.display = '';
		}

		// снимки — только пока элемент на экране (плитки внизу длинной страницы не грузят сеть)
		if (opts.lazy && window.IntersectionObserver) {
			observer = new IntersectionObserver(function (entries) {
				visible = entries[entries.length - 1].isIntersecting;
				if (visible) frame();
			});
			observer.observe(box);
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
				if (observer) observer.disconnect();
				stopLive();
			}
		};
	};

	// Просмотр на весь экран (вставки в сцены): живое видео поверх страницы, закрыть — × или Esc.
	// opts — как у go2rtcViewer, плюс title и texts {live, snapshot, offline, close}
	window.go2rtcViewer.open = function (opts) {
		var texts = opts.texts || {};
		var layer = document.createElement('div');
		layer.style.cssText = 'position:fixed;left:0;top:0;right:0;bottom:0;background:rgba(0,0,0,.92);z-index:100000;display:flex;flex-direction:column';
		var head = document.createElement('div');
		head.style.cssText = 'display:flex;align-items:center;gap:12px;padding:10px 16px;color:#eee;font:14px -apple-system,Segoe UI,Roboto,sans-serif';
		var title = document.createElement('b');
		title.style.fontSize = '16px';
		title.textContent = opts.title || '';
		var state = document.createElement('span');
		state.style.cssText = 'color:#9aa;flex:1';
		state.textContent = texts.snapshot || '';
		var close = document.createElement('button');
		close.type = 'button';
		close.textContent = '\u00d7';
		close.setAttribute('aria-label', texts.close || 'Close');
		close.style.cssText = 'background:none;border:0;color:#eee;font-size:28px;line-height:1;cursor:pointer';
		head.appendChild(title);
		head.appendChild(state);
		head.appendChild(close);
		var box = document.createElement('div');
		box.style.cssText = 'flex:1;min-height:0;display:flex;align-items:center;justify-content:center;position:relative';
		layer.appendChild(head);
		layer.appendChild(box);
		document.body.appendChild(layer);
		var o = {};
		for (var k in opts) o[k] = opts[k];
		o.live = true;
		o.controls = true;
		o.onstate = function (s) { state.textContent = texts[s] || ''; };
		var v = window.go2rtcViewer(box, o);
		var done = function () {
			v.stop();
			document.removeEventListener('keydown', key);
			if (layer.parentNode) layer.parentNode.removeChild(layer);
		};
		var key = function (e) { if (e.key == 'Escape') done(); };
		close.addEventListener('click', done);
		document.addEventListener('keydown', key);
		return {close: done};
	};
})();
