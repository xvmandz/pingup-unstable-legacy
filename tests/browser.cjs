// Optional E2E checks. The deterministic YouTube adapter tests our integration,
// not YouTube availability, playback rights, or browser autoplay policy.
const {chromium, expect} = require('playwright/test');
const assert = require('node:assert/strict');
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
    const browser = await chromium.launch({headless:true, executablePath:process.env.CHROMIUM_PATH || '/usr/bin/chromium', args:['--no-sandbox']});
    const errors=[];
    try {
        const a=await browser.newContext({viewport:{width:1440,height:1000}});
        const b=await browser.newContext({viewport:{width:390,height:844}});
        for (const c of [a,b]) {
            await c.route('https://www.youtube.com/iframe_api', r=>r.fulfill({contentType:'application/javascript',body:adapter}));
            c.on('page',p=>p.on('pageerror',e=>errors.push(e.message)));
        }
        const pa=await a.newPage(), pb=await b.newPage();
        await pa.goto(base);
        await pa.locator('#nickname').fill('Саша');
        await pa.locator('#join-button').click();
        await expect(pa.locator('#room')).toBeVisible();
        const roomUrl=pa.url();
        await pb.goto(roomUrl);
        await pb.locator('#nickname').fill('Маша');
        await pb.locator('#join-button').click();
        await expect(pa.locator('#member-count')).toHaveText('2 в зале');
        await pa.locator('#chat-text').fill('<img src=x onerror=alert(1)> Привет!');
        await pa.locator('#chat-form button').click();
        await expect(pb.locator('.message-body')).toHaveText('<img src=x onerror=alert(1)> Привет!');
        assert.equal(await pb.locator('.message-body img').count(),0);
        await pa.locator('#video-url').fill('https://youtu.be/dQw4w9WgXcQ');
        await pa.locator('#video-form button').click();
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
        await pa.locator('#invite').click();
        await expect(pa.locator('#invite-url')).toHaveValue(roomUrl);
        await pa.locator('.dialog-close').click();
        await pb.locator('#leave').click();
        await expect(pb.locator('#lobby')).toBeVisible();
        assert.equal(errors.length,0,JSON.stringify(errors));
        console.log('PASS: two browsers, chat XSS rendering, play/pause/seek, reconnect, reload persistence, mobile layout, invitation, leave; no JS errors. YouTube adapter simulated.');
    } finally { await browser.close(); }
}
run().catch(e=>{console.error(e);process.exit(1)});
