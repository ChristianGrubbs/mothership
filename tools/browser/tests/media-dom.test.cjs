const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const dam=path.join(root,'packages/Mothership/DAM');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const view=(file)=>fs.readFileSync(path.join(dam,'src/Resources/views/asset/preview-modal',file),'utf8');
function template(source){return source.replace(/@pushOnce\('styles'\)[\s\S]*?@endPushOnce/g,'').replace(/@lang\('[^']+'\)/g,'Control').replace(/@\{\{/g,'{{');}
function wave(){
    const rate=22050,frames=rate*4,buffer=Buffer.alloc(44+frames*2);
    buffer.write('RIFF');buffer.writeUInt32LE(buffer.length-8,4);buffer.write('WAVEfmt ',8);buffer.writeUInt32LE(16,16);buffer.writeUInt16LE(1,20);buffer.writeUInt16LE(1,22);buffer.writeUInt32LE(rate,24);buffer.writeUInt32LE(rate*2,28);buffer.writeUInt16LE(2,32);buffer.writeUInt16LE(16,34);buffer.write('data',36);buffer.writeUInt32LE(frames*2,40);
    for(let i=0;i<frames;i++)buffer.writeInt16LE(Math.round(Math.sin(i*440*2*Math.PI/rate)*3000),44+i*2);
    return buffer;
}
async function audioPage(browser){
    const page=await browser.newPage();
    await page.route('http://media.test/**',route=>{
        if(route.request().url().endsWith('.wav')){
            const body=wave(),match=/bytes=(\d+)-(\d*)/.exec(route.request().headers().range||'');
            if(match){const start=Number(match[1]),end=match[2]?Math.min(Number(match[2]),body.length-1):body.length-1;return route.fulfill({status:206,body:body.subarray(start,end+1),contentType:'audio/wav',headers:{'Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${body.length}`}});}
            return route.fulfill({body,contentType:'audio/wav',headers:{'Accept-Ranges':'bytes'}});
        }
        if(route.request().url().endsWith('/broken'))return route.fulfill({body:'unsupported fixture',contentType:'audio/wav'});
        return route.fulfill({body:'<div id="app"></div>',contentType:'text/html'});
    });
    await page.goto('http://media.test/');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH||path.join(dam,'node_modules/vue/dist/vue.global.js')});
    await page.addScriptTag({content:view('audio/audio-player-script.blade.php').replace(/<\/?script[^>]*>/g,'')});
    await page.evaluate(html=>{
        window.audioApp=Vue.createApp({template:html,data:()=>({...window._damAudioPlayer.data,previewData:{mediaUrl:'/fixture.wav',mime_type:'audio/wav',file_name:'Tone',placeholderSvg:''}}),computed:window._damAudioPlayer.computed,methods:{...window._damAudioPlayer.methods,_formatTime:s=>String(Math.floor(s||0))},mounted(){this.audioInitEl();},beforeUnmount(){this.audioStopOnClose();}});
        window.player=window.audioApp.mount('#app');
    },template(view('audio/audio-player.blade.php')));
    return page;
}
browserTest('audio follows real playback, seeking, mute, ended and teardown',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await audioPage(browser);
        await page.waitForFunction(()=>window.player.audioDuration>3.9);
        await page.evaluate(()=>window.player.audioTogglePlay());
        await page.waitForFunction(()=>window.player.audioIsPlaying&&window.player.audioCurrentTime>0.1);
        await page.evaluate(()=>{window.player.audioSkip(2);window.player.audioOnVolume({target:{value:'0.35'}});window.player.audioToggleMute();});
        const media=await page.locator('audio').evaluate(el=>({time:el.currentTime,volume:el.volume,muted:el.muted}));
        assert.ok(media.time>2);assert.equal(media.volume,0.35);assert.equal(media.muted,true);
        await page.evaluate(()=>{window.player.audioSkip(10);});
        await page.waitForFunction(()=>window.player.audioEnded&&!window.player.audioIsPlaying);
        await page.evaluate(()=>window.player.audioTogglePlay());
        await page.waitForFunction(()=>window.player.audioIsPlaying);
        await page.evaluate(()=>{window.oldAudio=window.player.$refs.audioEl;window.audioApp.unmount();});
        assert.equal(await page.evaluate(()=>window.oldAudio.paused),true);
        assert.equal(await page.evaluate(()=>window.oldAudio.getAttribute('src')),null);
    }finally{await browser.close();}
});
browserTest('audio surfaces rejected play and malformed source without stale playing state',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await audioPage(browser);
        await page.waitForFunction(()=>window.player.audioDuration>0);
        await page.evaluate(()=>{window.player.$refs.audioEl.play=()=>Promise.reject(new DOMException('Playback blocked','NotAllowedError'));});
        await page.evaluate(()=>window.player.audioTogglePlay());
        assert.equal(await page.evaluate(()=>window.player.audioIsPlaying),false);
        assert.match(await page.locator('[data-dam-audio-error]').textContent(),/play|blocked/i);
        await page.evaluate(()=>{delete window.player.$refs.audioEl.play;window.player.audioStopOnClose();window.player.previewData.mediaUrl='/broken';window.player.audioInitEl();});
        await page.waitForFunction(()=>Boolean(window.player.audioError));
        assert.equal(await page.evaluate(()=>window.player.audioIsPlaying),false);
        assert.match(await page.locator('[data-dam-audio-error]').textContent(),/format|audio|play/i);
    }finally{await browser.close();}
});
browserTest('an outstanding play cannot resume state after close or a source change',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await audioPage(browser);
        await page.waitForFunction(()=>window.player.audioDuration>0);
        await page.evaluate(()=>{window.player.$refs.audioEl.play=()=>new Promise(resolve=>window.releasePlay=resolve);window.player.audioTogglePlay();window.player.audioStopOnClose();window.player.previewData.mediaUrl='/next.wav';window.player.audioInitEl();window.releasePlay();});
        await page.evaluate(()=>Promise.resolve());
        assert.equal(await page.evaluate(()=>window.player.audioIsPlaying),false);
        assert.equal(await page.evaluate(()=>window.player.audioIsLoading),false);
    }finally{await browser.close();}
});
