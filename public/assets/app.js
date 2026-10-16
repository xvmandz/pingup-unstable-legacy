'use strict';
const $ = id => document.getElementById(id);
const csrf = document.querySelector('meta[name="csrf-token"]').content;
const roomParam = new URL(location.href).searchParams.get('room');
let roomId = roomParam, current = null, cursor = 0, joined = false, player = null, playerReady = false;
let video = null, anchor = 0, timer = null, lastGood = 0, latestTime = 0, busy = false, pollBusy = false, ytPromise = null, toastTimer;
let playerError = false, playerLoading = false, playerAttempted = false;
function toast(message) { $('toast').textContent = message; $('toast').hidden = false; clearTimeout(toastTimer); toastTimer = setTimeout(() => $('toast').hidden = true, 6000); }
function storedNick() { try { return localStorage.getItem('vmeste-nick') || ''; } catch { return ''; } }
$('nickname').value = storedNick();
if (roomParam) { $('join-button').firstChild.textContent = 'Войти в наш зал '; $('join-hint').textContent = 'Вас пригласили. Введите ник — и вы рядом.'; }
async function api(action, data = {}) {
    const started = performance.now();
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    try {
        const response = await fetch('api.php', {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','X-CSRF-Token':csrf}, body:JSON.stringify({action, room:roomId, after:cursor, ...data}), signal:controller.signal});
        const result = await response.json();
        if (!response.ok) { const e = new Error(result.error || 'Не удалось выполнить запрос.'); e.status = response.status; throw e; }
        result.rtt = Math.min((performance.now() - started) / 1000, 2);
        return result;
    } catch (e) {
        if (e.status) throw e;
        throw new Error(action === 'state' ? 'Связь прервалась. Переподключаемся…' : 'Не получен ответ сервера. Проверьте связь: команда могла выполниться.');
    } finally { clearTimeout(timeout); }
}
function accept(data) {
    if (!data.state || data.serverTime < latestTime) return;
    latestTime = data.serverTime; current = data; roomId = data.room; lastGood = performance.now(); anchor = performance.now();
    $('connection').textContent = 'Вы на связи'; $('connection').classList.remove('offline');
    $('member-count').textContent = `${data.members.length} в зале`;
    $('members').replaceChildren(...data.members.map(member => { const el = document.createElement('span'); el.className = 'member'; el.textContent = member.nick + (member.id === data.me.id ? ' · вы' : ''); return el; }));
    const messages = $('messages'); const nearBottom = messages.scrollHeight - messages.scrollTop - messages.clientHeight < 70;
    for (const m of data.messages) {
        if (Number(m.id) <= cursor) continue;
        $('chat-welcome')?.remove();
        const el = document.createElement('div'); el.className = 'message' + (m.member === data.me.id ? ' mine' : '');
        const meta = document.createElement('div'); meta.className = 'message-meta';
        const name = document.createElement('span'); name.textContent = m.nick;
        const time = document.createElement('time'); const date = new Date(m.created * 1000); time.dateTime = date.toISOString(); time.textContent = date.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'});
        const body = document.createElement('div'); body.className = 'message-body'; body.textContent = m.body;
        meta.append(name, time); el.append(meta, body); messages.append(el); cursor = Math.max(cursor, Number(m.id));
    }
    while (messages.children.length > 300) messages.firstChild.remove();
    if (nearBottom) messages.scrollTop = messages.scrollHeight;
    $('empty-player').hidden = Boolean(data.state.video);
    if (data.state.video) ensurePlayer();
    applyPlayback(); renderControls();
}
function targetPosition() {
    const s = current.state;
    return Math.max(0, s.position + (s.playing ? Math.max(0, current.serverTime - s.updated) + current.rtt / 2 + (performance.now() - anchor) / 1000 : 0));
}
function loadYoutube() {
    if (window.YT?.Player) return Promise.resolve();
    if (ytPromise) return ytPromise;
    ytPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        const timeout = setTimeout(() => { script.remove(); ytPromise = null; reject(new Error('YouTube не ответил. Проверьте доступ к YouTube и нажмите «Включить у меня».')); }, 12000);
        window.onYouTubeIframeAPIReady = () => { clearTimeout(timeout); resolve(); };
        script.src = 'https://www.youtube.com/iframe_api';
        script.onerror = () => { clearTimeout(timeout); script.remove(); ytPromise = null; reject(new Error('Не удалось загрузить YouTube. Проверьте блокировщик и доступ к сайту.')); };
        document.head.append(script);
    });
    return ytPromise;
}
async function ensurePlayer(retry = false) {
    if (player || playerLoading || (playerAttempted && !retry)) return;
    playerLoading = true; playerAttempted = true;
    try {
        await loadYoutube();
        player = new YT.Player('player', {host:'https://www.youtube-nocookie.com', width:'100%', height:'100%', playerVars:{controls:0, disablekb:1, playsinline:1, rel:0, origin:location.origin}, events:{
            onReady() { playerReady = true; playerLoading = false; applyPlayback(); },
            onError(e) { playerError = true; const errors = {2:'Некорректный идентификатор видео.',5:'Браузер не смог воспроизвести видео.',100:'Видео удалено или закрыто.',101:'Автор запретил встраивание этого видео.',150:'Автор запретил встраивание этого видео.',153:'YouTube отклонил проверку источника. Откройте зал на обычном HTTPS-домене.'}; $('player-status').textContent = errors[e.data] || 'Видео недоступно. Попробуйте другое.'; },
            onAutoplayBlocked() { $('player-status').textContent = 'Браузер ждёт вашего разрешения. Нажмите «Включить у меня».'; },
            onStateChange(e) { if (e.data === 1) $('player-status').textContent = 'Смотрим вместе · позиция сверяется с сервером каждую секунду'; }
        }});
    } catch (e) { playerLoading = false; $('player-status').textContent = e.message; }
}
function applyPlayback() {
    if (!playerReady || !current?.state.video) return;
    const s = current.state, desired = targetPosition();
    if (video !== s.video) {
        video = s.video; playerError = false;
        $('player-status').textContent = 'Видео добавлено. Нажмите ▶, чтобы начать вместе.';
        player.cueVideoById({videoId:video, startSeconds:desired});
    }
    if (playerError) return;
    const state = player.getPlayerState();
    if (state === -1 || state === 5) {
        if (s.playing) player.playVideo();
        if (Math.abs((player.getCurrentTime() || 0) - desired) > 1.5) player.seekTo(desired, true);
        return;
    }
    const duration = player.getDuration();
    if (duration && desired >= duration - 0.3) { if (state === 1) player.pauseVideo(); return; }
    if (Math.abs((player.getCurrentTime() || 0) - desired) > 1.5) player.seekTo(desired, true);
    if (s.playing && state !== 1 && state !== 3) player.playVideo();
    if (!s.playing && (state === 1 || state === 3)) player.pauseVideo();
}
function formatTime(seconds) { seconds = Math.floor(Math.max(0, seconds || 0)); const h = Math.floor(seconds/3600); const m = Math.floor(seconds/60)%60; return (h ? `${h}:${String(m).padStart(2,'0')}` : String(m)) + ':' + String(seconds%60).padStart(2,'0'); }
function renderControls() {
    const stale = !lastGood || performance.now() - lastGood > 7000;
    if (stale && joined) { $('connection').textContent = 'Переподключаемся…'; $('connection').classList.add('offline'); }
    const hasVideo = Boolean(current?.state.video);
    $('play').disabled = !hasVideo || stale || busy;
    $('seek').disabled = !hasVideo || !playerReady || !player?.getDuration() || stale || busy;
    $('enable-sound').disabled = !hasVideo;
    const duration = playerReady ? player.getDuration() : 0;
    const position = hasVideo ? targetPosition() : 0;
    const ended = duration > 0 && position >= duration - .3;
    $('play').textContent = current?.state.playing && !ended ? 'Ⅱ' : '▶';
    $('play').classList.toggle('is-playing', Boolean(current?.state.playing && !ended));
    $('play').setAttribute('aria-label', current?.state.playing && !ended ? 'Пауза для всех' : 'Воспроизвести для всех');
    $('position').textContent = formatTime(duration ? Math.min(position, duration) : position);
    $('duration').textContent = formatTime(duration);
    $('seek').max = duration || 100;
    if (document.activeElement !== $('seek')) $('seek').value = duration ? Math.min(position, duration) : 0;
}
async function poll() {
    if (!joined || pollBusy) return;
    pollBusy = true;
    try { accept(await api('state')); }
    catch (e) {
        $('connection').textContent = 'Переподключаемся…'; $('connection').classList.add('offline');
        if ([401,403,404].includes(e.status)) { joined = false; clearTimeout(timer); toast(e.message); $('lobby').hidden = false; $('room').hidden = true; if (playerReady) player.pauseVideo(); }
    } finally { pollBusy = false; if (joined) timer = setTimeout(poll, document.hidden ? 4000 : 1000); }
}
$('join-form').addEventListener('submit', async e => {
    e.preventDefault(); const button = $('join-button'); button.disabled = true;
    try {
        const nick = $('nickname').value.trim();
        const data = await api(roomId ? 'join' : 'create', {nick});
        try { localStorage.setItem('vmeste-nick', nick); } catch {}
        roomId = data.room; history.replaceState(null, '', `?room=${roomId}`);
        joined = true; $('lobby').hidden = true; $('room').hidden = false; accept(data); poll();
    } catch(e) { toast(e.message); } finally { button.disabled = false; }
});
async function control(op, extra = {}) {
    if (!current || busy) return false;
    busy = true; renderControls();
    try { accept(await api('control', {op, version:current.state.version, ...extra})); return true; }
    catch (e) { toast(e.message); if (e.status === 409) { try { accept(await api('state')); } catch {} } return false; }
    finally { busy = false; renderControls(); }
}
$('video-form').addEventListener('submit', async e => {
    e.preventDefault(); if (busy) return;
    const input = $('video-url'), value = input.value;
    const btn = e.currentTarget.querySelector('button'); btn.disabled = true;
    try { if (await control('load', {url:value.trim()}) && input.value === value) input.value = ''; } finally { btn.disabled = false; }
});
$('play').addEventListener('click', async () => {
    if (playerReady) { player.unMute(); if (!current.state.playing) player.playVideo(); }
    const duration = playerReady ? player.getDuration() : 0;
    if (duration && targetPosition() >= duration - .3) { await control('replay'); }
    else await control(current.state.playing ? 'pause' : 'play');
});
$('seek').addEventListener('change', () => control('seek', {position:Number($('seek').value)}));
$('enable-sound').addEventListener('click', () => { if (!playerReady) { ensurePlayer(true); return; } if (playerError) { video = null; playerError = false; applyPlayback(); } player.unMute(); player.setVolume(100); if (current.state.playing) player.playVideo(); $('player-status').textContent = 'Звук включён на этом устройстве. Кнопка ▶ запускает видео для всех.'; });
$('chat-form').addEventListener('submit', async e => {
    e.preventDefault(); const input = $('chat-text'), body = input.value.trim(); if (!body) return;
    const btn = e.currentTarget.querySelector('button'); if (btn.disabled) return; btn.disabled = true;
    try { accept(await api('message', {body})); if (input.value.trim() === body) input.value = ''; $('messages').scrollTop = $('messages').scrollHeight; }
    catch(e) { toast(e.message); } finally { btn.disabled = false; input.focus(); }
});
$('chat-text').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); $('chat-form').requestSubmit(); } });
$('invite').addEventListener('click', () => { $('invite-url').value = location.href; $('copy-status').textContent = ''; $('invite-dialog').showModal(); });
$('copy-link').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('invite-url').value); $('copy-status').textContent = 'Ссылка скопирована. Отправьте её лично ♡'; } catch { $('invite-url').select(); $('copy-status').textContent = 'Выделили ссылку — скопируйте её вручную.'; } });
$('leave').addEventListener('click', async () => { if (busy) return; busy = true; try { await api('leave'); joined = false; clearTimeout(timer); if (playerReady) player.pauseVideo(); location.assign(location.pathname); } catch(e) { toast(e.message); busy = false; } });
document.addEventListener('visibilitychange', () => { if (!document.hidden && joined) { clearTimeout(timer); poll(); } });
window.addEventListener('online', () => { if (joined) { clearTimeout(timer); poll(); } });
setInterval(renderControls, 500);
