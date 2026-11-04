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
 *       audio: true,         // запрашивать звук (false — только видео); начинается без звука — v.mute(false) включает
 *       muted: true,         // false — сразу со звуком (только после нажатия пользователя — иначе браузер не запустит)
 *       onmute: function (muted) {},       // звук выключили или включили (кнопкой, плеером или браузер сам)
 *       onstate: function (state, how) {}, // state: 'snapshot' | 'live' | 'offline'; how: 'direct' | 'whep' | 'php'
 *       poster: go2rtcViewer.capture(oldBox), // что показать до первого кадра (снимок прошлого просмотра), без чёрного экрана
 *       ui: true,            // панель как у карточки WebRTC Camera: тип потока и размер кадра в углу, шестерёнка — пауза, снимок,
 *                            // «картинка в картинке», микрофон, режим (авто, WebRTC, MSE, HLS, MJPEG, через сервер…),
 *                            // выбор потока и статистика. Только у живого видео. Тексты — opts.texts или go2rtcViewer.texts
 *       streams: [{title: 'SD', frame: …, whep: …, direct: {base, src}}, {title: 'HD', …}],  // потоки для переключателя (2 и больше)
 *       stream: 0,           // какой из них идёт сейчас
 *       onstream: function (i) {}          // поток выбрали в панели
 *   });
 *   v.stop();  // остановить снимки и видео
 *   v.mute(false); v.mute();  // включить звук; узнать, выключен ли
 *
 * Живое видео: сначала плеер go2rtc напрямую (браузер видит go2rtc, у его API нет пароля; по HTTPS к http-адресу
 * go2rtc браузер не пустит — тогда сразу дальше), не пошло за ожидание камеры + 4 с — WebRTC через сервер (WHEP),
 * не пошло и оно — видео через сервер (PHP читает go2rtc и отдаёт MP4, в Safari — HLS; адрес — как у кадра, с /mp4 и /hls
 * вместо /frame), не пошло и оно — остаются снимки. Нет direct и whep (настройка «Видео через сервер») — сразу через сервер. Снимок: следующий — когда загрузился прошлый (без мигания), «нет связи» —
 * после 20 с неудач подряд. Первым — последний сохранённый кадр (сразу, без ожидания камеры), следом — свежий. Файл без новых конструкций языка — в старом браузере работают хотя бы снимки.
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

	// Копия того, что сейчас видно в элементе (видео или снимок), — холстом: показать в новом просмотре до первого кадра.
	// Нечего копировать (пусто, кадр чужого сайта) — null
	function capture(el) {
		if (!el) return null;
		var src = null, w = 0, h = 0;
		var videos = el.querySelectorAll('video');
		for (var i = 0; i < videos.length && !src; i++) {
			var v = videos[i];
			if (v.videoWidth && v.readyState >= 2 && v.offsetParent !== null) { src = v; w = v.videoWidth; h = v.videoHeight; }
		}
		if (!src) {
			var imgs = el.querySelectorAll('img');
			for (var j = 0; j < imgs.length && !src; j++) {
				var im = imgs[j];
				if (im.naturalWidth && im.style.display != 'none') { src = im; w = im.naturalWidth; h = im.naturalHeight; }
			}
		}
		if (!src) return null;
		try {
			var c = document.createElement('canvas');
			c.width = w;
			c.height = h;
			c.getContext('2d').drawImage(src, 0, 0, w, h);
			c.toDataURL('image/jpeg', 0.1);   // проверка: холст не «испорчен» чужим кадром
			return c;
		} catch (e) {
			return null;
		}
	}

	window.go2rtcViewer = function (box, opts) {
		opts = opts || {};
		var interval = opts.interval || 2000;
		var wait = opts.wait || 6;
		var stopped = false, loading = false, live = '', failSince = 0, state = '', visible = true, first = true;
		var timer = null, pc = null, rtc = null, observer = null, liveTimer = null, streaming = false;
		var audio = opts.audio !== false && opts.audio !== 0;
		var muted = !audio || opts.muted !== false;
		// панель: выбранный режим ('' — авто), микрофон, поток, поколение подключения (отсекает запоздавшие ответы)
		var forced = '', micOn = false, wantLive = false, failed = false, gen = 0, mjTimer = null;
		var streams = (opts.streams && opts.streams.length > 1) ? opts.streams : null, curStream = opts.stream || 0;
		var ui = null, wsBytes = 0, wsFrames = 0, wsHooked = null, posterSeen = '', posterSize = null;

		box.classList.add('go2rtc-view');
		// снимок; до первого кадра — poster (холст от capture), если дали
		var shot = opts.poster || document.createElement('img');
		shot.alt = '';
		shot.style.cssText = 'width:100%;height:100%;object-fit:contain';
		var video = document.createElement('video');
		video.autoplay = true;
		video.muted = muted;
		video.playsInline = true;
		video.setAttribute('playsinline', '');
		video.controls = !!opts.controls;
		video.style.cssText = 'width:100%;height:100%;object-fit:contain;display:none';
		box.appendChild(shot);
		box.appendChild(video);
		// звук меняют и кнопки плеера, и сам плеер go2rtc (браузер не дал играть со звуком — выключает): следим за элементом
		function watchMute(el) {
			el.addEventListener('volumechange', function () {
				if (el.style.display == 'none' && el !== (rtc && rtc.video)) return;
				if (el.muted != muted) {
					muted = el.muted;
					if (opts.onmute) opts.onmute(muted);
				}
			});
		}
		watchMute(video);
		video.addEventListener('playing', function () { if (!live && pc) showLive('whep', video); });

		function setState(s, how) {
			if (s == state) return;
			state = s;
			box.classList.toggle('go2rtc-off', s == 'offline');
			box.classList.toggle('go2rtc-live', s == 'live');
			if (opts.onstate) opts.onstate(s, how || '');
			if (ui) ui.refresh();
		}

		function frame() {
			if (stopped || loading || live || !opts.frame || !visible) return;
			loading = true;
			var last = first;   // первый запрос — сохранённый кадр, без ожидания камеры
			first = false;
			var next = new Image();
			next.onload = function () {
				loading = false;
				if (stopped || live) return;
				next.alt = '';
				next.style.cssText = shot.style.cssText;
				shot.parentNode.replaceChild(next, shot);
				shot = next;
				if (last) {
					frame();     // и сразу свежий
					return;
				}
				failSince = 0;
				setState('snapshot');
			};
			next.onerror = function () {
				loading = false;
				if (last) {
					frame();
					return;
				}
				if (!failSince) failSince = Date.now();
				if (Date.now() - failSince >= 20000) setState('offline');
			};
			next.src = add(opts.frame, (opts.width ? 'w=' + opts.width + '&' : '') + (last ? 'last=1&' : '') + 't=' + Date.now());
		}

		function showLive(how, el) {
			if (stopped) return;
			clearTimeout(liveTimer);
			live = how;
			shot.style.display = 'none';
			el.style.display = '';
			failed = false;
			setState('live', how);
			if (ui) ui.refresh();
		}

		// 1) плеер go2rtc напрямую
		function startDirect() {
			var d = opts.direct, g = gen;
			if (!directOk()) return false;
			loadPlayer(opts.player).then(function () {
				if (stopped || live || g != gen) return;
				rtc = document.createElement('go2rtc-video');
				rtc.mode = /^(webrtc|webrtc\/tcp|mse|hls|mjpeg)$/.test(forced) ? forced : 'webrtc,mse,hls,mjpeg';
				rtc.media = audio ? (micOn ? 'video,audio,microphone' : 'video,audio') : 'video';
				rtc.background = true;   // видимость решает сама страница
				rtc.pcConfig = {bundlePolicy: 'max-bundle', iceServers: [], sdpSemantics: 'unified-plan'};
				rtc.style.display = 'none';
				rtc.style.width = '100%';
				rtc.style.height = '100%';
				box.appendChild(rtc);    // создаёт свой <video>
				if (rtc.video) {
					rtc.video.muted = muted;
					watchMute(rtc.video);
					rtc.video.controls = !!opts.controls;
					rtc.video.style.objectFit = 'contain';
					rtc.video.addEventListener('playing', function () { if (!live) showLive('direct', rtc); });
				}
				// MJPEG плеер go2rtc показывает кадрами в poster — события playing нет
				clearInterval(mjTimer);
				mjTimer = setInterval(function () {
					if (!live && rtc && rtc.video && rtc.video.poster) showLive('direct', rtc);
				}, 500);
				rtc.src = d.base.replace(/\/+$/, '') + '/api/ws?src=' + encodeURIComponent(d.src);
			}).catch(function () {
				if (g == gen) directFailed();
			});
			liveTimer = setTimeout(function () {
				if (!live && g == gen) directFailed();
			}, (wait + 4) * 1000);
			return true;
		}

		// плеер go2rtc напрямую можно: адрес задан, плеер есть, страница по HTTPS не ходит на http-адрес
		function directOk() {
			var d = opts.direct;
			return !!(d && d.base && opts.player && window.customElements && window.Promise
				&& !(location.protocol == 'https:' && /^http:/i.test(d.base)));
		}

		// прямое подключение не получилось: авто — дальше по цепочке; режим выбран вручную — снимки и пометка
		function directFailed() {
			stopDirect();
			if (forced) giveUp();
			else startWhep();
		}

		function giveUp() {
			stopLive();
			failed = true;
			setState('snapshot');
			if (ui) ui.refresh();
		}

		function stopDirect() {
			clearInterval(mjTimer);
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
			if (stopped || live || pc) return;
			if (!opts.whep || !window.RTCPeerConnection) {
				if (forced) giveUp();
				else startStream();
				return;
			}
			var g = gen;
			pc = new RTCPeerConnection({iceServers: []});
			pc.addTransceiver('video', {direction: 'recvonly'});
			if (audio) pc.addTransceiver('audio', {direction: 'recvonly'});
			pc.ontrack = function (e) {
				if (!video.srcObject) {
					video.srcObject = e.streams[0] || new MediaStream([e.track]);
					// со звуком браузер может не запустить без нажатия — тогда без звука (как плеер go2rtc)
					try {
						var pr = video.play();
						if (pr && pr.catch) pr.catch(function () {
							if (!video.muted) { video.muted = true; video.play().catch(function () {}); }
						});
					} catch (er) {}
				}
			};
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
				if (!live && g == gen) failWhep();
			});
			liveTimer = setTimeout(function () { if (!live && g == gen) failWhep(); }, (wait + 4) * 1000);
		}

		function stopWhep() {
			if (pc) { try { pc.close(); } catch (e) {} }
			pc = null;
			video.srcObject = null;
		}

		// 3) видео через сервер: PHP читает go2rtc и отдаёт поток по HTTP — работает там, где WebRTC недоступен
		// (порт WebRTC не открыт, вне дома). Safari (нет fMP4 по HTTP) — HLS; адреса — как у кадра: /frame → /mp4, /hls
		function streamUrl() {
			var f = opts.frame || '';
			if (!/\/frame([?\/])/.test(f)) return '';
			var hls = video.canPlayType && video.canPlayType('application/vnd.apple.mpegurl') && !/Chrome|Chromium|Android/.test(navigator.userAgent);
			if (hls) return f.indexOf('?') >= 0 ? f.replace(/\/frame\?/, '/hls?') : '';
			return f.replace(/\/frame([?\/])/, '/mp4$1') + (f.indexOf('?') >= 0 ? '&' : '?') + 'b=1';
		}

		function startStream() {
			var url = streamUrl();
			if (stopped || live || streaming || !url) return;
			streaming = true;
			video.addEventListener('playing', onStreamPlaying);
			video.addEventListener('error', onStreamError);
			video.src = url;
			try {
				var pr = video.play();
				if (pr && pr.catch) pr.catch(function () {
					if (!video.muted) { video.muted = true; video.play().catch(function () {}); }
				});
			} catch (er) {}
			liveTimer = setTimeout(function () {
				if (!live) {
					stopStream();
					if (forced) giveUp();
				}
			}, (wait + 8) * 1000);
		}

		function onStreamPlaying() { if (!live && streaming) showLive('php', video); }
		function onStreamError() {
			if (!streaming) return;
			var was = live == 'php';
			stopStream();
			if (was) { stopLive(); setState('snapshot'); }   // поток оборвался — снова снимки
			else if (forced) giveUp();
		}

		function stopStream() {
			if (!streaming) return;
			streaming = false;
			video.removeEventListener('playing', onStreamPlaying);
			video.removeEventListener('error', onStreamError);
			video.removeAttribute('src');
			try { video.load(); } catch (e) {}
		}

		// WebRTC через сервер не получился (нет или не пошёл) — видео через сервер
		function failWhep() {
			stopWhep();
			if (forced) giveUp();
			else startStream();
		}

		function startLive() {
			if (live || stopped) return;
			wantLive = true;
			if (forced == 'snapshots') { if (ui) ui.refresh(); return; }
			if (forced == 'whep') startWhep();
			else if (forced == 'php') startStream();
			else if (!startDirect()) {
				if (forced) giveUp();
				else startWhep();
			}
			if (ui) ui.refresh();
		}

		function stopLive() {
			gen++;
			clearTimeout(liveTimer);
			stopDirect();
			stopWhep();
			stopStream();
			live = '';
			video.style.display = 'none';
			shot.style.display = '';
		}

		// ---- панель (opts.ui): тип потока, режимы, потоки, пауза, снимок, PiP, микрофон, статистика ----

		function mediaEl() { return (live == 'direct' && rtc && rtc.video) ? rtc.video : video; }
		function activePc() {
			if (live == 'whep') return pc;
			return (live == 'direct' && rtc && rtc.pc && rtc.pcState === 1) ? rtc.pc : null;
		}
		// что реально играет: webrtc, mse, hls, mjpeg (плеер go2rtc), whep (через сервер), php (через сервер, MP4/HLS)
		function kind() {
			if (!live) return '';
			if (live != 'direct') return live;
			var v = rtc && rtc.video;
			if (!v) return 'direct';
			if (rtc.pcState === 1 && rtc.pc) return 'webrtc';
			if (/^data:application\/vnd\.apple/.test(v.src || '')) return 'hls';
			if (v.poster && !v.videoWidth) return 'mjpeg';   // плеер go2rtc показывает MJPEG кадрами в poster
			if (v.srcObject || /^blob:/.test(v.src || '')) return 'mse';
			return 'direct';
		}

		var UI_TEXTS = {auto: 'Auto', webrtc: 'WebRTC', tcp: 'WebRTC (TCP)', mse: 'MSE', hls: 'HLS', mjpeg: 'MJPEG',
			whep: 'WebRTC via server', php: 'Via server (PHP)', snapshots: 'Snapshots', mode: 'Mode', stream: 'Stream',
			pause: 'Pause', play: 'Play', shot: 'Save frame', pip: 'Picture in picture', mic: 'Microphone', stats: 'Statistics',
			type: 'Type', size: 'Frame', fps: 'Frames/s', rate: 'Bitrate', rtt: 'Ping', loss: 'Lost packets', delay: 'Delay',
			connecting: 'connecting…', failed: 'no video in this mode', settings: 'Settings', unsupported: 'not supported'};
		function tx(k) {
			return (opts.texts && opts.texts[k]) || (window.go2rtcViewer && window.go2rtcViewer.texts && window.go2rtcViewer.texts[k]) || UI_TEXTS[k] || k;
		}

		function freeze() {
			// последний кадр видео — вместо устаревшего снимка, пока подключается другое (без чёрного экрана)
			var c = capture(box);
			if (c && shot) {
				c.style.cssText = shot.style.cssText;
				if (shot.parentNode) shot.parentNode.replaceChild(c, shot);
				shot = c;
			}
		}

		function restart() {
			freeze();
			stopLive();
			setState('snapshot');
			startLive();
		}

		function setMode(m) {
			if (stopped) return;
			forced = m;
			failed = false;
			if (m != 'webrtc' && m != 'webrtc/tcp') micOn = false;
			if (m == 'snapshots') {
				freeze();
				stopLive();
				setState('snapshot');
				if (ui) ui.refresh();
				return;
			}
			restart();
		}

		function setStream(i) {
			if (stopped || !streams || !streams[i] || i == curStream) return;
			var s = streams[i];
			curStream = i;
			opts.frame = s.frame;
			opts.whep = s.whep;
			opts.direct = s.direct;
			first = true;
			if (opts.onstream) opts.onstream(i);
			if (wantLive) restart();
			else if (ui) ui.refresh();
		}

		function setMic(on) {
			micOn = !!on;
			if (micOn && forced != 'webrtc' && forced != 'webrtc/tcp') forced = 'webrtc';
			restart();
		}

		// можно ли этот режим здесь
		function modeOk(m) {
			if (m == '') return true;
			if (m == 'snapshots') return !!opts.frame;
			if (m == 'whep') return !!(opts.whep && window.RTCPeerConnection);
			if (m == 'php') return !!streamUrl();
			if (!directOk()) return false;
			if (m == 'webrtc' || m == 'webrtc/tcp') return !!window.RTCPeerConnection;
			if (m == 'mse') return !!(window.MediaSource || window.ManagedMediaSource);
			if (m == 'hls') return !!(video.canPlayType && video.canPlayType('application/vnd.apple.mpegurl'));
			return m == 'mjpeg';
		}

		// статистика: тип, кадр, кадров/с, скорость, задержка, пинг и потери (WebRTC)
		var st = {t: 0, bytes: 0, frames: 0, kind: ''};
		function hookWs() {
			if (live == 'direct' && rtc && rtc.ws && rtc.ws !== wsHooked) {
				wsHooked = rtc.ws;
				rtc.ws.addEventListener('message', function (ev) {
					if (typeof ev.data != 'string') { wsBytes += ev.data.byteLength || 0; wsFrames++; }
				});
			}
		}
		function sample(cb) {
			var el = mediaEl(), k = kind(), now = Date.now();
			var m = {kind: k, w: el.videoWidth || 0, h: el.videoHeight || 0};
			var dt = (st.kind == k && st.t) ? (now - st.t) / 1000 : 0;
			var frames = 0, bytes = null, p = activePc();
			try { if (el.getVideoPlaybackQuality) frames = el.getVideoPlaybackQuality().totalVideoFrames; } catch (e) {}
			if (k == 'mjpeg') {
				frames = wsFrames;
				// размер кадра MJPEG — по самой картинке
				if (el.poster && el.poster !== posterSeen) {
					posterSeen = el.poster;
					var pi = new Image();
					pi.onload = function () { posterSize = [pi.naturalWidth, pi.naturalHeight]; };
					pi.src = el.poster;
				}
				if (posterSize) { m.w = posterSize[0]; m.h = posterSize[1]; }
			}
			if (dt && frames >= st.frames) m.fps = (frames - st.frames) / dt;
			if (k != 'webrtc' && k != 'mjpeg' && el.buffered && el.buffered.length) {
				var dl = el.buffered.end(el.buffered.length - 1) - el.currentTime;
				if (dl >= 0 && dl < 60) m.delay = dl;
			}
			if (!p) {
				hookWs();
				if (live == 'direct' && rtc && rtc.ws) bytes = wsBytes;
				if (bytes !== null && dt && bytes >= st.bytes) m.rate = (bytes - st.bytes) * 8 / dt / 1000;
				st = {t: now, bytes: bytes || 0, frames: frames, kind: k};
				cb(m);
				return;
			}
			p.getStats().then(function (rep) {
				rep.forEach(function (r) {
					if (r.type == 'inbound-rtp' && (r.kind == 'video' || r.mediaType == 'video')) {
						bytes = r.bytesReceived;
						if (r.framesPerSecond) m.fps = r.framesPerSecond;
						if (r.packetsLost !== undefined) m.loss = r.packetsLost;
						if (!m.w && r.frameWidth) { m.w = r.frameWidth; m.h = r.frameHeight; }
					} else if (r.type == 'candidate-pair' && r.nominated && r.state == 'succeeded' && r.currentRoundTripTime !== undefined) {
						m.rtt = r.currentRoundTripTime * 1000;
					}
				});
				if (bytes !== null && dt && bytes >= st.bytes) m.rate = (bytes - st.bytes) * 8 / dt / 1000;
				st = {t: now, bytes: bytes || 0, frames: frames, kind: k};
				cb(m);
			}, function () { st = {t: now, bytes: 0, frames: frames, kind: k}; cb(m); });
		}

		function typeName(k) {
			return {webrtc: tx('webrtc'), mse: tx('mse'), hls: tx('hls'), mjpeg: tx('mjpeg'), whep: tx('whep'), php: tx('php')}[k] || k;
		}

		function buildUi() {
			if (!document.getElementById('g2v-css')) {
				var css = document.createElement('style');
				css.id = 'g2v-css';
				css.textContent = '.g2v-badge{position:absolute;left:22px;top:6px;z-index:5;background:rgba(0,0,0,.55);color:#fff;font:11px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;'
					+ 'padding:0 7px;border-radius:9px;pointer-events:none;white-space:nowrap;max-width:calc(100% - 64px);overflow:hidden;text-overflow:ellipsis}'
					+ '.g2v-gear{position:absolute;right:6px;top:6px;z-index:6;width:26px;height:26px;border:0;border-radius:13px;background:rgba(0,0,0,.55);color:#fff;'
					+ 'font:15px/26px sans-serif;padding:0;cursor:pointer;text-align:center}'
					+ '.g2v-panel{position:fixed;z-index:2147483000;width:250px;max-width:calc(100vw - 16px);max-height:calc(100vh - 16px);overflow:auto;box-sizing:border-box;'
					+ 'background:rgba(18,20,24,.96);color:#eee;font:12px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;padding:8px 10px;border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.5);text-align:left}'
					+ '.g2v-row{display:flex;flex-wrap:wrap;gap:4px;margin:0 0 8px}'
					+ '.g2v-h{color:#9aa;font-size:11px;margin:0 0 3px}'
					+ '.g2v-b{border:1px solid #556;background:#2a2e36;color:#eee;border-radius:5px;padding:2px 8px;font:12px/1.5 inherit;font-family:inherit;cursor:pointer;margin:0}'
					+ '.g2v-b.on{background:#2f7be0;border-color:#2f7be0}'
					+ '.g2v-st{color:#bcc;font-variant-numeric:tabular-nums;white-space:pre-line;margin:0;padding:0;border:0;background:none;font-family:inherit;font-size:12px}'
					+ '.g2v-msg{color:#f90;margin:0 0 6px}';
				(document.head || document.documentElement).appendChild(css);
			}
			if (getComputedStyle(box).position == 'static') box.style.position = 'relative';
			function el(tag, cls, text) {
				var e = document.createElement(tag);
				if (cls) e.className = cls;
				if (text !== undefined) e.textContent = text;
				return e;
			}
			function btn(text, title, fn) {
				var b = el('button', 'g2v-b', text);
				b.type = 'button';
				if (title) b.title = title;
				b.addEventListener('click', function (e) { e.stopPropagation(); fn(b); });
				return b;
			}
			var badge = el('div', 'g2v-badge'), gear = el('button', 'g2v-gear', '⚙');
			gear.type = 'button';
			gear.title = tx('settings');
			box.appendChild(badge);
			box.appendChild(gear);
			var panel = el('div', 'g2v-panel'), open = false, timer = null;
			panel.style.display = 'none';
			['click', 'mousedown', 'touchstart', 'dblclick'].forEach(function (n) { panel.addEventListener(n, function (e) { e.stopPropagation(); }); });
			var msg = el('p', 'g2v-msg'), stats = el('div', 'g2v-st');
			msg.style.display = 'none';
			var actions = el('div', 'g2v-row'), pauseB = null, shotB, pipB = null, micB = null;
			pauseB = btn('⏸', tx('pause'), function () {
				var m = mediaEl();
				if (m.paused) m.play().catch(function () {}); else m.pause();
				ui.refresh();
			});
			shotB = btn('⬇', tx('shot'), function () {
				try {
					var a = document.createElement('a'), m = mediaEl();
					if (kind() == 'mjpeg' && m.poster) {
						a.href = m.poster;
					} else {
						if (!m.videoWidth) return;
						var c = document.createElement('canvas');
						c.width = m.videoWidth;
						c.height = m.videoHeight;
						c.getContext('2d').drawImage(m, 0, 0);
						a.href = c.toDataURL('image/jpeg', 0.92);
					}
					var d = new Date(), z = function (n) { return (n < 10 ? '0' : '') + n; };
					a.download = ((opts.direct && opts.direct.src) || 'camera') + '-' + d.getFullYear() + z(d.getMonth() + 1) + z(d.getDate()) + '-' + z(d.getHours()) + z(d.getMinutes()) + z(d.getSeconds()) + '.jpg';
					document.body.appendChild(a);
					a.click();
					document.body.removeChild(a);
				} catch (e) {
					flash(tx('unsupported'));
				}
			});
			actions.appendChild(pauseB);
			actions.appendChild(shotB);
			if (document.pictureInPictureEnabled) {
				pipB = btn('⧉', tx('pip'), function () {
					try {
						if (document.pictureInPictureElement) document.exitPictureInPicture();
						else mediaEl().requestPictureInPicture().catch(function () { flash(tx('unsupported')); });
					} catch (e) { flash(tx('unsupported')); }
				});
				actions.appendChild(pipB);
			}
			// микрофон: двусторонний звук по WebRTC (камера должна принимать звук); только по HTTPS или с localhost
			if (audio && window.isSecureContext && navigator.mediaDevices && modeOk('webrtc')) {
				micB = btn('🎤', tx('mic'), function () { setMic(!micOn); });
				actions.appendChild(micB);
			}
			panel.appendChild(msg);
			panel.appendChild(actions);
			var streamBtns = [], modeBtns = [];
			if (streams) {
				panel.appendChild(el('div', 'g2v-h', tx('stream')));
				var sr = el('div', 'g2v-row');
				streams.forEach(function (s, i) {
					var b = btn(s.title || String(i + 1), '', function () { setStream(i); });
					streamBtns.push(b);
					sr.appendChild(b);
				});
				panel.appendChild(sr);
			}
			panel.appendChild(el('div', 'g2v-h', tx('mode')));
			var mr = el('div', 'g2v-row');
			[['', 'auto'], ['webrtc', 'webrtc'], ['webrtc/tcp', 'tcp'], ['mse', 'mse'], ['hls', 'hls'], ['mjpeg', 'mjpeg'],
				['whep', 'whep'], ['php', 'php'], ['snapshots', 'snapshots']].forEach(function (x) {
				var b = btn(tx(x[1]), '', function () { setMode(x[0]); });
				b.setAttribute('data-mode', x[0]);
				modeBtns.push(b);
				mr.appendChild(b);
			});
			panel.appendChild(mr);
			panel.appendChild(el('div', 'g2v-h', tx('stats')));
			panel.appendChild(stats);
			document.body.appendChild(panel);

			function flash(t) {
				msg.textContent = t;
				msg.style.display = '';
				setTimeout(function () { msg.style.display = 'none'; }, 3000);
			}
			function place() {
				var r = box.getBoundingClientRect(), w = Math.min(250, window.innerWidth - 16);
				panel.style.maxHeight = (window.innerHeight - 16) + 'px';
				if (r.height >= 300 && r.width >= 300) {
					// крупный просмотр — у шестерёнки
					panel.style.left = Math.max(8, Math.min(r.right - w - 6, window.innerWidth - w - 8)) + 'px';
					panel.style.top = Math.max(8, Math.min(r.top + 38, window.innerHeight - 120)) + 'px';
					panel.style.transform = '';
				} else {
					// плитка — по центру экрана
					panel.style.left = '50%';
					panel.style.top = '50%';
					panel.style.transform = 'translate(-50%,-50%)';
				}
			}
			function fmt(m) {
				var l = [];
				l.push(tx('type') + ': ' + (m.kind ? typeName(m.kind) : '—'));
				l.push(tx('size') + ': ' + (m.w ? m.w + '×' + m.h : '—'));
				if (m.fps !== undefined) l.push(tx('fps') + ': ' + Math.round(m.fps));
				if (m.rate !== undefined) l.push(tx('rate') + ': ' + (m.rate >= 1000 ? (m.rate / 1000).toFixed(1) + ' Mbit/s' : Math.round(m.rate) + ' kbit/s'));
				if (m.delay !== undefined) l.push(tx('delay') + ': ' + m.delay.toFixed(1) + ' s');
				if (m.rtt !== undefined) l.push(tx('rtt') + ': ' + Math.round(m.rtt) + ' ms');
				if (m.loss !== undefined) l.push(tx('loss') + ': ' + m.loss);
				return l.join('\n');
			}
			function tick() {
				if (stopped || !ui) return;
				sample(function (m) {
					if (!ui) return;
					var k = m.kind;
					badge.textContent = failed ? tx('failed')
						: forced == 'snapshots' ? tx('snapshots')
						: !k ? tx('connecting')
						: typeName(k) + (m.w ? ' · ' + m.w + '×' + m.h : '');
					if (open) stats.textContent = fmt(m);
				});
			}
			function show(on) {
				open = on;
				panel.style.display = on ? '' : 'none';
				if (on) { place(); tick(); }
			}
			gear.addEventListener('click', function (e) { e.stopPropagation(); show(!open); });
			['mousedown', 'touchstart', 'dblclick'].forEach(function (n) { gear.addEventListener(n, function (e) { e.stopPropagation(); }); });
			var outside = function (e) {
				if (open && !panel.contains(e.target) && e.target !== gear) {
					show(false);
					e.stopPropagation();   // нажатие мимо панели только закрывает её (плитка не переключается)
					e.preventDefault();
				}
			};
			var esc = function (e) { if (e.key == 'Escape' && open) show(false); };
			document.addEventListener('click', outside, true);
			document.addEventListener('keydown', esc);
			window.addEventListener('resize', function () { if (open) place(); });
			timer = setInterval(tick, 1000);

			return {
				refresh: function () {
					var vis = wantLive && !stopped;
					badge.style.display = gear.style.display = vis ? '' : 'none';
					if (!vis && open) show(false);
					var m = mediaEl(), k = kind();
					pauseB.textContent = (live && m.paused) ? '▶' : '⏸';
					pauseB.title = (live && m.paused) ? tx('play') : tx('pause');
					pauseB.style.display = (k == 'mjpeg') ? 'none' : '';
					if (micB) micB.classList.toggle('on', micOn);
					streamBtns.forEach(function (b, i) { b.classList.toggle('on', i == curStream); });
					modeBtns.forEach(function (b) {
						var mm = b.getAttribute('data-mode');
						b.style.display = modeOk(mm) ? '' : 'none';
						b.classList.toggle('on', mm == forced);
					});
					tick();
				},
				destroy: function () {
					clearInterval(timer);
					document.removeEventListener('click', outside, true);
					document.removeEventListener('keydown', esc);
					if (panel.parentNode) panel.parentNode.removeChild(panel);
				}
			};
		}
		if (opts.ui) ui = buildUi();

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
			snapshots: function () { wantLive = false; stopLive(); frame(); if (ui) ui.refresh(); },
			// режим ('' авто, webrtc, webrtc/tcp, mse, hls, mjpeg, whep, php, snapshots) и поток (номер из opts.streams) — как в панели
			mode: setMode,
			stream: setStream,
			// звук: mute(false) — включить (вызывать из нажатия пользователя), mute(true) — выключить, mute() — выключен ли
			mute: function (m) {
				if (m === undefined) return muted;
				var was = muted;
				muted = !audio || !!m;
				video.muted = muted;
				if (rtc && rtc.video) rtc.video.muted = muted;
				if (!muted) {
					try { var pr = (rtc && rtc.video && live == 'direct' ? rtc.video : video).play(); if (pr && pr.catch) pr.catch(function () {}); } catch (e) {}
				}
				if (was != muted && opts.onmute) opts.onmute(muted);
				return muted;
			},
			stop: function () {
				stopped = true;
				clearInterval(timer);
				if (observer) observer.disconnect();
				stopLive();
				if (ui) { ui.destroy(); ui = null; }
			}
		};
	};

	window.go2rtcViewer.capture = capture;

	// Просмотр на весь экран (вставки в сцены): живое видео поверх страницы, закрыть — × или Esc.
	// opts — как у go2rtcViewer, плюс title и texts {live, snapshot, offline, close, sound_on, sound_off}; кнопка звука — если audio
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
		var sound = null;
		if (opts.audio !== false && opts.audio !== 0) {
			sound = document.createElement('button');
			sound.type = 'button';
			sound.style.cssText = close.style.cssText + ';font-size:20px';
			head.appendChild(sound);
		}
		head.appendChild(close);
		var box = document.createElement('div');
		box.style.cssText = 'flex:1;min-height:0;display:flex;align-items:center;justify-content:center;position:relative';
		layer.appendChild(head);
		layer.appendChild(box);
		document.body.appendChild(layer);
		var o = {};
		for (var k in opts) o[k] = opts[k];
		o.live = true;
		o.ui = true;
		o.controls = true;
		o.onstate = function (s) { state.textContent = texts[s] || ''; };
		o.onmute = function () { if (showSound) showSound(); };
		var showSound = null;
		var v = window.go2rtcViewer(box, o);
		if (sound) {
			showSound = function () {
				var m = v.mute();
				sound.textContent = m ? '\uD83D\uDD07' : '\uD83D\uDD0A';
				sound.title = m ? (texts.sound_on || '') : (texts.sound_off || '');
			};
			sound.addEventListener('click', function () { v.mute(!v.mute()); });
			showSound();
		}
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
