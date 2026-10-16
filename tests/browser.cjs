// Optional E2E checks. The deterministic YouTube adapter tests our integration,
// not YouTube availability, playback rights, or browser autoplay policy.
const {chromium, expect} = require('playwright/test');
const assert = require('node:assert/strict');
const path = require('node:path');
const fs = require('node:fs');
const base = process.env.BASE_URL || 'http://127.0.0.1:8080';
const adapter = `
window.YT={Player:class {
 constructor(id, config){this.state=5;this.position=0;this.started=performance.now();this.video=null;this.config=config;window.testPlayer=this;setTimeout(()=>config.events.onReady(),30);}
 cueVideoById(o){this.video=o.videoId;this.position=o.startSeconds;this.state=5;}
 getCurrentTime(){return this.position+(this.state===1?(performance.now()-this.started)/1000:0);}
 getDuration(){return this.video?180:0;}
 getPlayerState(){return this.state;}
 seekTo(p){this.position=p;this.started=performance.now();}
 playVideo(){if(this.state!==1){this.started=performance.now();this.state=1;this.config.events.onStateChange({data:1});}}
 pauseVideo(){this.position=this.getCurrentTime();this.state=2;}
 unMute(){} setVolume(){}
}};
window.onYouTubeIframeAPIReady();`;
async function run() {
    const browser = await chromium.launch({headless:true, executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', args:['--no-sandbox','--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--autoplay-policy=no-user-gesture-required']});
    const errors=[]; let pages=[];
    try {
        const a=await browser.newContext({viewport:{width:1440,height:1000},permissions:['microphone']});
        const b=await browser.newContext({viewport:{width:390,height:844},permissions:['microphone']});
        for (const c of [a,b]) {
            await c.route('https://www.youtube.com/iframe_api', r=>r.fulfill({contentType:'application/javascript',body:adapter}));
            await c.route('https://media.example.test/clip.webm',r=>{
                const bytes=fs.readFileSync(path.join(__dirname,'fixtures/clip.webm'));
                const range=r.request().headers().range?.match(/bytes=(\d+)-(\d*)/);
                if(range){const start=Number(range[1]),end=range[2]?Math.min(Number(range[2]),bytes.length-1):bytes.length-1;return r.fulfill({status:206,body:bytes.subarray(start,end+1),headers:{'Content-Type':'video/webm','Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${bytes.length}`}});}
                return r.fulfill({body:bytes,headers:{'Content-Type':'video/webm','Accept-Ranges':'bytes'}});
            });
            c.on('page',p=>{p.on('pageerror',e=>errors.push(e.message));if(process.env.DEBUG_VOICE)p.on('console',msg=>console.log(msg.text()));});
        }
        const pa=await a.newPage(), pb=await b.newPage(); pages=[pa,pb];
        await pa.goto(base);
        await pa.locator('#nickname').fill('Саша');
        await pa.locator('#join-button').click();
        await expect(pa.locator('#room')).toBeVisible();
        const roomUrl=pa.url();
        await pb.goto(roomUrl);
        await pb.locator('#nickname').fill('Маша');
        await pb.locator('#join-button').click();
        await expect(pa.locator('#member-count')).toHaveText('2 в зале');
        await expect(pb.locator('.chat-panel')).toBeHidden();
        await pb.locator('#chat-toggle').click();
        await expect(pb.locator('.chat-panel')).toBeVisible();
        await pb.locator('#chat-close').click();
        await expect(pb.locator('.chat-panel')).toBeHidden();
        await pa.locator('#chat-text').fill('<img src=x onerror=alert(1)> Привет!');
        await pa.locator('#chat-form button').click();
        await expect(pb.locator('.message-body')).toHaveText('<img src=x onerror=alert(1)> Привет!');
        assert.equal(await pb.locator('.message-body img').count(),0);
        await pa.locator('#video-url').fill('https://youtu.be/dQw4w9WgXcQ');
        await pa.locator('#load-now').click();
        await expect.poll(()=>pb.evaluate(()=>window.testPlayer?.video)).toBe('dQw4w9WgXcQ');
        await pa.locator('#play').click();
        await expect.poll(()=>pb.evaluate(()=>window.testPlayer.state)).toBe(1);
        await pb.locator('#play').click();
        await expect.poll(()=>pa.evaluate(()=>window.testPlayer.state)).toBe(2);
        await pa.locator('#seek').evaluate(el=>{el.value=64;el.dispatchEvent(new Event('change',{bubbles:true}));});
        await expect.poll(()=>pb.evaluate(()=>Math.round(window.testPlayer.getCurrentTime()))).toBe(64);
        await b.setOffline(true);
        await expect(pb.locator('#connection')).toContainText('Переподключаемся');
        await pa.locator('#seek').evaluate(el=>{el.value=90;el.dispatchEvent(new Event('change',{bubbles:true}));});
        await b.setOffline(false);
        await expect.poll(()=>pb.evaluate(()=>Math.round(window.testPlayer.getCurrentTime())),{timeout:12000}).toBe(90);
        await expect(pb.locator('#connection')).toHaveText('Вы на связи');
        assert.equal(await pb.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Mobile page must not overflow');
        await pb.reload();
        await pb.locator('#join-button').click();
        await expect.poll(()=>pb.evaluate(()=>Math.round(window.testPlayer?.getCurrentTime())),{timeout:10000}).toBe(90);
        await expect(pb.locator('.message-body')).toHaveCount(1);
        // Queue, memories, readiness, reactions and chooser permissions.
        await pa.locator('#bookmark-note').fill('Наш момент <script>');
        await pa.locator('#bookmark-form button').click();
        await expect(pb.locator('#bookmark-list')).toContainText('Наш момент <script>');
        await pa.locator('[data-reaction="❤️"]').click();
        await expect(pb.locator('.flying-reaction')).toContainText('❤️');
        await pa.locator('#play').click();
        await expect.poll(()=>pb.evaluate(()=>window.testPlayer.state)).toBe(1);
        await pb.locator('#wait-button').click();
        await expect(pa.locator('#waiting-banner')).toBeVisible();
        await expect.poll(()=>pa.evaluate(()=>window.testPlayer.state)).toBe(2);
        await pa.locator('#ready-button').click();
        await expect(pa.locator('#waiting-banner')).toBeVisible();
        await pb.locator('#ready-button').click();
        await expect(pa.locator('#waiting-banner')).toBeHidden();
        await expect.poll(()=>pa.evaluate(()=>window.testPlayer.state)).toBe(1);
        await pa.locator('#pass-choice').click();
        await expect(pb.locator('#chooser-label')).toContainText('Маша');
        await expect(pa.locator('#load-now')).toBeDisabled();
        await pb.locator('#choose-person').selectOption('');
        await pb.locator('#pass-choice').click();
        await expect(pa.locator('#load-now')).toBeEnabled();
        await pa.locator('#video-url').fill('https://youtu.be/abcdefghijk');
        await pa.locator('#video-title').fill('На потом');
        await pa.locator('#queue-add').click();
        await expect(pb.locator('#queue-list')).toContainText('На потом');
        await pa.locator('#queue-next').click();
        await expect.poll(()=>pb.evaluate(()=>window.testPlayer.video)).toBe('abcdefghijk');
        await expect(pb.locator('#history-list')).toContainText('На потом');
        await pa.locator('#cinema-mode').click();
        await expect(pa.locator('body')).toHaveClass('cinema');
        await pa.locator('#chat-toggle').click();
        await expect(pa.locator('.chat-panel')).toBeHidden();
        await pa.locator('#chat-toggle').click();
        await expect(pa.locator('.chat-panel')).toBeVisible();
        await pa.locator('#cinema-mode').click();
        // Real native video playback, not an HTMLMediaElement mock.
        await pa.locator('#video-url').fill('https://media.example.test/clip.webm');
        await pa.locator('#video-title').fill('Наш файл');
        await pa.locator('#load-now').click();
        await expect.poll(()=>pb.locator('#direct-video').evaluate(v=>v.readyState)).toBeGreaterThanOrEqual(1);
        await pa.locator('#play').click();
        await expect.poll(()=>pb.locator('#direct-video').evaluate(v=>v.currentTime)).toBeGreaterThan(.2);
        await pb.locator('#play').click();
        await expect.poll(()=>pa.locator('#direct-video').evaluate(v=>v.paused)).toBe(true);
        await pa.locator('#seek').evaluate(el=>{el.value=4;el.dispatchEvent(new Event('change',{bubbles:true}));});
        await expect.poll(()=>pb.locator('#direct-video').evaluate(v=>Math.round(v.currentTime))).toBe(4);
        // Real peer connection with synthetic microphone input on one machine.
        await pa.locator('#voice-start').click();
        await expect(pa.locator('#voice-start')).toHaveText('Завершить голос');
        await pb.locator('#voice-start').click();
        await expect.poll(()=>pa.evaluate(()=>voice.pc?.signalingState),{timeout:15000}).toBe('stable');
        await expect.poll(()=>pb.evaluate(()=>voice.pc?.remoteDescription?.type),{timeout:15000}).toBeTruthy();
        if(process.env.VOICE_TRANSPORT_EXPECTED_BLOCKED==='1') {
            await expect.poll(()=>pa.evaluate(()=>voice.pc?.remoteDescription?.type),{timeout:15000}).toBeTruthy();
            await expect(pa.locator('#voice-status')).toContainText('Голос не соединился',{timeout:22000});
            assert.notEqual(await pa.evaluate(()=>voice.pc?.connectionState),'connected');
            console.log('VOICE LIMITATION: SDP offer/answer exchanged; actual audio transport blocked by managed Chromium WebRtcIPHandling policy. Timeout UI verified.');
        } else {
            await expect.poll(()=>pa.evaluate(()=>voice.pc?.connectionState),{timeout:30000}).toBe('connected');
            await expect.poll(()=>pb.evaluate(()=>voice.pc?.connectionState),{timeout:30000}).toBe('connected');
            assert.equal(await pb.locator('#remote-audio').evaluate(a=>a.srcObject?.getAudioTracks().length),1);
        }
        await pa.locator('#voice-mute').click();
        assert.equal(await pa.evaluate(()=>voice.stream.getAudioTracks()[0].enabled),false);
        await pa.locator('#voice-start').click();
        assert.equal(await pa.evaluate(()=>voice.stream),null);
        // Queue auto-advance is versioned and executes once across both clients.
        await pa.locator('#video-url').fill('https://youtu.be/dQw4w9WgXcQ');
        await pa.locator('#video-title').fill('После файла');
        await pa.locator('#queue-add').click();
        await expect(pb.locator('#queue-list')).toContainText('После файла');
        await pa.locator('#seek').evaluate(el=>{el.value=10.5;el.dispatchEvent(new Event('change',{bubbles:true}));});
        await expect.poll(()=>pb.locator('#direct-video').evaluate(v=>v.currentTime)).toBeGreaterThan(10);
        await pa.locator('#play').click();
        await expect.poll(()=>pb.locator('#direct-video').isHidden(),{timeout:12000}).toBe(true);
        await expect.poll(()=>pb.evaluate(()=>window.testPlayer.video)).toBe('dQw4w9WgXcQ');
        await expect(pb.locator('#queue-list .feature-item')).toHaveCount(0);
        assert.equal(await pb.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false,'Mobile features must not overflow');
        await pa.locator('#invite').click();
        await expect(pa.locator('#invite-url')).toHaveValue(roomUrl);
        await pa.locator('.dialog-close').click();
        await pb.locator('#leave').click();
        await expect(pb.locator('#lobby')).toBeVisible();
        assert.equal(errors.length,0,JSON.stringify(errors));
        console.log('PASS: two browsers, chat XSS rendering, play/pause/seek, reconnect, reload persistence, mobile layout, queue/auto-advance, bookmarks/history, reactions, readiness, chooser, cinema, real native WebM playback, WebRTC signaling and synthetic microphone controls (audio transport outcome reported separately), invitation, leave; no JS errors. YouTube adapter simulated.');
    } catch(e) { for(const p of pages) console.error(await p.evaluate(()=>({state:current?.state,position:$('direct-video').currentTime,duration:$('direct-video').duration,ready:$('direct-video').readyState,seek:$('seek').value,toast:$('toast').textContent,voice:$('voice-status').textContent,rtc:{peer:voice.peer,remote:voice.remote,signalCursor,signals:current?.signals.map(x=>[x.id,x.sender,x.payload.type]),local:voice.pc?.localDescription?.type,remoteDescription:voice.pc?.remoteDescription?.type,signalingState:voice.pc?.signalingState,ice:voice.pc?.iceConnectionState,candidates:voice.candidates.length}}))); throw e; } finally { await browser.close(); }
}
run().catch(e=>{console.error(e);process.exit(1)});
