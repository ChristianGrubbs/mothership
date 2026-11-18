const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const views=path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/video');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
// `/fixture.mp4` is the ordinary 1080p red→blue stream; `/master.mp4` is the 4K red→blue master;
// `/proxy.mp4` is the 1080p preview proxy, green→yellow with the master's timing, so a frame drawn from
// the proxy can never pass for master pixels — not even upscaled into a 4K canvas.
// `/stalled.mp4` is held unanswered (a master whose load hangs); its routes collect on `page.stalledRoutes`.
// `/late-404.mp4` answers 404 only after 1.5 s (a master that errors once playback has moved on).
// Any other `.mp4` path is a 404, which is how a master the browser cannot read is simulated.
const media={'/fixture.mp4':'video-frame-colors.mp4','/master.mp4':'video-frame-colors-4k.mp4','/proxy.mp4':'video-frame-proxy-1080p.mp4','/alpha-vp9.webm':'video-frame-alpha-vp9-1440p.webm','/alpha-vp8.webm':'video-frame-alpha-vp8-1440p.webm','/alpha-1fps.webm':'video-frame-alpha-vp9-1fps.webm'};
// `/alpha-vp9.webm` and `/alpha-vp8.webm` are real alpha-bearing 1440p masters: fully transparent for 0-1 s, opaque red for 1-2 s.
function serve(route,file){
    const body=fs.readFileSync(path.join(__dirname,'../fixtures',file));
    const range=/bytes=(\d+)-(\d*)/.exec(route.request().headers().range||'');
    if(range){const start=Number(range[1]),end=range[2]?Math.min(Number(range[2]),body.length-1):body.length-1;return route.fulfill({status:206,body:body.subarray(start,end+1),contentType:file.endsWith('.webm')?'video/webm':'video/mp4',headers:{'Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${body.length}`}});}
    return route.fulfill({body,contentType:file.endsWith('.webm')?'video/webm':'video/mp4',headers:{'Accept-Ranges':'bytes'}});
}
// `previewData` is merged over the default asset; `data` overrides initial player state (e.g. videoQuality).
async function setup(browser,{previewData={},data={}}={}){
    const page=await browser.newPage();
    page.stalledRoutes=[];
    await page.route('https://media.test/**',route=>{
        const pathname=new URL(route.request().url()).pathname;
        if(media[pathname])return serve(route,media[pathname]);
        if(pathname==='/stalled.mp4'){page.stalledRoutes.push(route);return;}
        if(pathname==='/late-404.mp4')return new Promise(resolve=>setTimeout(resolve,1500)).then(()=>route.fulfill({status:404,contentType:'text/plain',body:'Not found'})).catch(()=>{});
        if(pathname.endsWith('.mp4'))return route.fulfill({status:404,contentType:'text/plain',body:'Not found'});
        return route.fulfill({contentType:'text/html',body:'<style>[data-dam-flat-video-player]{position:relative;width:640px;height:360px}video{width:160px;height:90px}svg{width:16px;height:16px}</style><div id="app"></div>'});
    });
    await page.goto('https://media.test/');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.addScriptTag({content:fs.readFileSync(path.join(views,'video-player-script.blade.php'),'utf8').replace(/<\/?script[^>]*>/g,'')});
    // Blade drops {{-- comments --}} server-side; left in, Vue would compile them as interpolations.
    const template=fs.readFileSync(path.join(views,'video-player.blade.php'),'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/@js\(trans\('[^']+'\)\)/g,'\'Control\'').replace(/@lang\('[^']+'\)/g,'Control').replace(/@\{\{/g,'{{');
    await page.evaluate(({template,previewData,data})=>{
        window.app=Vue.createApp({template,data:()=>({...window._damVideoPlayer.data,...data,previewData:{id:1,mediaUrl:'/fixture.mp4',mime_type:'video/mp4',file_name:'Sample.mp4',...previewData}}),computed:window._damVideoPlayer.computed,methods:{...window._damVideoPlayer.methods,_formatTime:s=>String(s||0)},mounted(){this.videoMounted();},beforeUnmount(){this.videoBeforeUnmount();}});
        window.player=window.app.mount('#app');
    },{template,previewData,data});
    await page.waitForFunction(()=>document.querySelector('video').readyState>=2);
    await page.locator('video').evaluate(async el=>{el.pause();await new Promise(resolve=>{el.addEventListener('seeked',resolve,{once:true});el.currentTime=.5;});});
    return page;
}
async function pixels(page,bytes,probes=[]){
    return page.evaluate(async ({bytes,probes})=>{
        const bitmap=await createImageBitmap(new Blob([new Uint8Array(bytes)],{type:'image/png'}));
        const canvas=document.createElement('canvas');canvas.width=bitmap.width;canvas.height=bitmap.height;
        const ctx=canvas.getContext('2d');ctx.drawImage(bitmap,0,0);
        const result={width:bitmap.width,height:bitmap.height,pixel:Array.from(ctx.getImageData(50,50,1,1).data),probed:probes.map(([x,y])=>Array.from(ctx.getImageData(x,y,1,1).data))};bitmap.close();return result;
    },{bytes:Array.from(bytes),probes});
}
browserTest('download captures native pixels at click time without changing playback',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await setup(browser);
        assert.equal(await page.getByRole('button',{name:'Download frame',exact:true}).count(),1);
        const downloadEvent=page.waitForEvent('download');
        await page.getByRole('button',{name:'Download frame',exact:true}).click({noWaitAfter:true});
        const download=await downloadEvent;
        assert.equal(download.suggestedFilename(),'Sample-frame-0-500s.png');
        const result=await pixels(page,fs.readFileSync(await download.path()));
        assert.equal(result.width,1920);assert.equal(result.height,1080);
        assert.ok(result.pixel[0]>245&&result.pixel[2]<10);
        assert.deepEqual(await page.locator('video').evaluate(el=>({paused:el.paused,time:el.currentTime})),{paused:true,time:.5});
    }finally{await browser.close();}
});
browserTest('copy supplies a PNG promise during the gesture and reports clipboard denial',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await setup(browser);
        await page.evaluate(()=>{
            window.ClipboardItem=class{constructor(data){window.pngWasPromise=data['image/png'] instanceof Promise;this.data=data;}};
            navigator.clipboard.write=async items=>{window.clipboardGesture=navigator.userActivation.isActive;const blob=await items[0].data['image/png'];window.copiedBytes=Array.from(new Uint8Array(await blob.arrayBuffer()));};
        });
        assert.equal(await page.getByRole('button',{name:'Copy frame',exact:true}).count(),1);
        await page.getByRole('button',{name:'Copy frame',exact:true}).click();
        await page.waitForFunction(()=>Boolean(window.copiedBytes));
        assert.equal(await page.evaluate(()=>window.pngWasPromise&&window.clipboardGesture),true);
        const result=await pixels(page,await page.evaluate(()=>window.copiedBytes));
        assert.equal(result.width,1920);assert.equal(result.height,1080);assert.ok(result.pixel[0]>245);
        await page.evaluate(()=>{navigator.clipboard.write=()=>Promise.reject(new DOMException('Denied','NotAllowedError'));});
        await page.getByRole('button',{name:'Copy frame',exact:true}).click();
        await page.waitForFunction(()=>document.querySelector('[data-dam-frame-status]')?.textContent.includes('Download frame'));
        assert.doesNotMatch(await page.locator('[data-dam-frame-status]').textContent(),/copied/i);
    }finally{await browser.close();}
});
browserTest('capture freezes the selected blue frame before asynchronous encoding',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await setup(browser);
        await page.locator('video').evaluate(async el=>{await new Promise(resolve=>{el.addEventListener('seeked',resolve,{once:true});el.currentTime=1.5;});});
        await page.evaluate(()=>{
            const encode=HTMLCanvasElement.prototype.toBlob;
            HTMLCanvasElement.prototype.toBlob=function(callback,type){window.finishEncode=()=>encode.call(this,callback,type);};
        });
        const event=page.waitForEvent('download');
        await page.evaluate(()=>{window.player.videoControlsVisible=false;document.activeElement.blur();});
        await page.keyboard.press('Tab');
        assert.equal(await page.getByRole('button',{name:'Copy frame',exact:true}).evaluate(el=>el===document.activeElement),true);
        assert.equal(await page.evaluate(()=>window.player.videoControlsVisible),true);
        await page.keyboard.press('Tab');
        await page.keyboard.press('Space');
        await page.locator('video').evaluate(async el=>{await new Promise(resolve=>{el.addEventListener('seeked',resolve,{once:true});el.currentTime=.5;});});
        await page.evaluate(()=>window.finishEncode());
        const download=await event;
        assert.equal(download.suggestedFilename(),'Sample-frame-1-500s.png');
        const result=await pixels(page,fs.readFileSync(await download.path()));
        assert.ok(result.pixel[2]>245&&result.pixel[0]<10);
    }finally{await browser.close();}
});
browserTest('close cancels delayed export and seeking never exports an invalid frame',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await setup(browser);let downloads=0;page.on('download',()=>downloads++);
        assert.equal(await page.getByRole('button',{name:'Download frame',exact:true}).count(),1);
        await page.evaluate(()=>{window.originalToBlob=HTMLCanvasElement.prototype.toBlob;HTMLCanvasElement.prototype.toBlob=function(callback,type){window.finishEncode=()=>window.originalToBlob.call(this,callback,type);};});
        await page.getByRole('button',{name:'Download frame',exact:true}).click();
        await page.evaluate(()=>{window.player.videoStopOnClose();window.player.previewData.id=2;window.finishEncode();});
        await page.waitForTimeout(200);
        assert.equal(downloads,0);
        await page.evaluate(()=>{HTMLCanvasElement.prototype.toBlob=window.originalToBlob;Object.defineProperty(window.player.$refs.videoEl,'seeking',{configurable:true,value:true});});
        await page.getByRole('button',{name:'Download frame',exact:true}).click();
        assert.match(await page.locator('[data-dam-frame-status]').textContent(),/wait|ready|seek/i);
        assert.equal(downloads,0);
    }finally{await browser.close();}
});

// Master-resolution capture while a 1080p preview proxy plays (ADR 0003). The hidden master is a second
// <video> on the page, so these tests address the visible player explicitly.
// The proxy plays green→yellow and the master is red→blue, so at the 0.5 s click time a master-path
// capture must be RED and a proxy-fallback capture GREEN; dimensions and status alone prove nothing.
const proxy={playbackIsProxy:true,playbackUrl:'/proxy.mp4',originalUrl:'/master.mp4'};
const previewSuffix=' — preview resolution; the original could not be read.';
// Chrome decodes the CRF-40 4K master's red as about (255,24,0) and the proxy's green as (0,216,0), yellow
// near (254,254,0). Each color is ~190+ away from the others on a deciding channel, so these bounds still
// separate master red/blue from proxy green/yellow cleanly.
const isRed=p=>p[0]>245&&p[1]<60&&p[2]<60;
const isBlue=p=>p[2]>245&&p[0]<60&&p[1]<60;
const isGreen=p=>p[1]>180&&p[0]<60&&p[2]<60;
const frameState=page=>page.evaluate(()=>({busy:window.player.videoFrameBusy,status:window.player.videoFrameStatus,statusShown:!!document.querySelector('[data-dam-frame-status]')}));
const player=page=>page.locator('[data-dam-flat-video-player] video');
async function withBrowser(fn){
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{await fn(browser);}finally{await browser.close();}
}
async function seekPlayer(page,time){
    await player(page).evaluate(async(el,time)=>{await new Promise(resolve=>{el.addEventListener('seeked',resolve,{once:true});el.currentTime=time;});},time);
}
async function frameStatus(page){
    await page.waitForFunction(()=>/^(Frame (copied|downloaded)|Could not)/.test(document.querySelector('[data-dam-frame-status]')?.textContent||''));
    return page.locator('[data-dam-frame-status]').textContent();
}
async function downloadFrame(page,probes=[]){
    const event=page.waitForEvent('download');
    await page.getByRole('button',{name:'Download frame',exact:true}).click({noWaitAfter:true});
    const download=await event;
    return {name:download.suggestedFilename(),...await pixels(page,fs.readFileSync(await download.path()),probes),status:await frameStatus(page)};
}
async function stubClipboard(page){
    await page.evaluate(()=>{
        // Any `seeked` not from the visible player is the hidden master's.
        window.masterSeeked=false;
        document.addEventListener('seeked',event=>{if(event.target!==window.player.$refs.videoEl)window.masterSeeked=true;},true);
        window.ClipboardItem=class{constructor(data){window.pngWasPromise=data['image/png'] instanceof Promise;window.itemBeforeMasterSeek=!window.masterSeeked;this.data=data;}};
        navigator.clipboard.write=async items=>{window.clipboardGesture=navigator.userActivation.isActive;const blob=await items[0].data['image/png'];window.copiedBytes=Array.from(new Uint8Array(await blob.arrayBuffer()));};
    });
}
async function copyFrame(page,probes=[]){
    await page.evaluate(()=>{window.copiedBytes=null;window.pngWasPromise=null;window.clipboardGesture=null;window.itemBeforeMasterSeek=null;window.masterSeeked=false;});
    await page.getByRole('button',{name:'Copy frame',exact:true}).click();
    await page.waitForFunction(()=>Boolean(window.copiedBytes));
    const flags=await page.evaluate(()=>({promise:window.pngWasPromise,gesture:window.clipboardGesture,beforeMasterSeek:window.itemBeforeMasterSeek}));
    return {...flags,...await pixels(page,await page.evaluate(()=>window.copiedBytes),probes),status:await frameStatus(page)};
}
const videoCount=page=>page.evaluate(()=>document.querySelectorAll('video').length);
browserTest('proxy playback downloads the click-time frame from the 4K master without touching the player',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:proxy});
    assert.deepEqual(await player(page).evaluate(el=>({src:new URL(el.currentSrc).pathname,width:el.videoWidth})),{src:'/proxy.mp4',width:1920});
    const red=await downloadFrame(page);
    assert.equal(red.name,'Sample-frame-0-500s.png');
    assert.equal(red.width,3840);assert.equal(red.height,2160);
    // Red, not the proxy's green: the pixels came from the master, not an upscaled proxy.
    assert.ok(isRed(red.pixel),`master red pixel ${red.pixel}`);
    assert.equal(red.status,'Frame downloaded (3840 × 2160).');
    assert.deepEqual(await player(page).evaluate(el=>({paused:el.paused,time:el.currentTime,src:new URL(el.currentSrc).pathname})),{paused:true,time:.5,src:'/proxy.mp4'});
    // The reused hidden master follows the click time: a blue click-time frame comes back blue (not the proxy's yellow), still at 4K.
    await seekPlayer(page,1.5);
    const blue=await downloadFrame(page);
    assert.equal(blue.name,'Sample-frame-1-500s.png');
    assert.equal(blue.width,3840);assert.equal(blue.height,2160);
    assert.ok(isBlue(blue.pixel),`master blue pixel ${blue.pixel}`);
    assert.equal(blue.status,'Frame downloaded (3840 × 2160).');
    assert.equal(await videoCount(page),2);
}));
browserTest('proxy playback copies a 4K master PNG from a promise handed over during the gesture',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:proxy});
    await stubClipboard(page);
    const copy=await copyFrame(page);
    assert.deepEqual({promise:copy.promise,gesture:copy.gesture,beforeMasterSeek:copy.beforeMasterSeek},{promise:true,gesture:true,beforeMasterSeek:true});
    assert.equal(copy.width,3840);assert.equal(copy.height,2160);
    assert.ok(isRed(copy.pixel),`master red pixel ${copy.pixel}`);
    assert.equal(copy.status,'Frame copied (3840 × 2160).');
    assert.deepEqual(await player(page).evaluate(el=>({paused:el.paused,time:el.currentTime})),{paused:true,time:.5});
}));
// Seeked-with-readyState-2 can still precede the first presentable master frame: the draw is then transparent.
// The browser presents no frame while the draws are blank, so the stub also holds back the master's
// requestVideoFrameCallback until the blank draws are used up (never, for a master that does not present).
const blankMasterDraws=(page,count)=>page.evaluate(n=>{
    window.blankDraws=n;window.masterDraws=0;window.heldFrameCallbacks=[];
    const flush=()=>{if(window.blankDraws<=0)window.heldFrameCallbacks.splice(0).forEach(({callback,element})=>callback(0,{mediaTime:element.currentTime}));};
    const register=HTMLVideoElement.prototype.requestVideoFrameCallback;
    HTMLVideoElement.prototype.requestVideoFrameCallback=function(callback){
        if(this.videoWidth!==3840)return register.call(this,callback);
        window.heldFrameCallbacks.push({callback,element:this});setTimeout(flush);return 0;
    };
    const draw=CanvasRenderingContext2D.prototype.drawImage;
    CanvasRenderingContext2D.prototype.drawImage=function(source,...args){
        if(source instanceof HTMLVideoElement&&source.videoWidth===3840){window.masterDraws++;if(window.blankDraws>0){window.blankDraws--;setTimeout(flush);return;}}
        return draw.call(this,source,...args);
    };
},count);
browserTest('a master whose first draws are transparent is redrawn until the real frame arrives',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:proxy});
    await blankMasterDraws(page,3);
    const download=await downloadFrame(page);
    assert.equal(download.width,3840);assert.equal(download.height,2160);
    assert.ok(isRed(download.pixel),`master red pixel ${download.pixel}`);
    assert.equal(download.status,'Frame downloaded (3840 × 2160).');
    assert.equal(await page.evaluate(()=>window.masterDraws),4);
}));
for(const [label,at] of [['inside the first 64-row strip',[10,10]],['in a later 64-row strip',[10,2000]]]){
    browserTest(`a sparse master (one visible pixel ${label}, away from every sample point) is exported with that pixel, not discarded as blank`,()=>withBrowser(async browser=>{
        const page=await setup(browser,{previewData:proxy});
        await page.evaluate(([x,y])=>{
            window.masterDraws=0;
            const draw=CanvasRenderingContext2D.prototype.drawImage;
            CanvasRenderingContext2D.prototype.drawImage=function(source,...args){
                if(source instanceof HTMLVideoElement&&source.videoWidth===3840){window.masterDraws++;this.fillStyle='#ff0000';this.fillRect(x,y,1,1);return;}
                return draw.call(this,source,...args);
            };
        },at);
        const download=await downloadFrame(page,[at,[500,500]]);
        assert.equal(download.width,3840);assert.equal(download.height,2160);
        assert.equal(download.status,'Frame downloaded (3840 × 2160).');
        assert.deepEqual(download.probed[0],[255,0,0,255],'the sparse red pixel is in the exported PNG');
        assert.equal(download.probed[1][3],0,'the rest of the exported PNG is transparent, so it is the master and not the proxy snapshot');
        assert.equal(await page.evaluate(()=>window.masterDraws),1,'no retry for a frame that has visible content');
    }));
}
browserTest('a master that never presents a frame exports the click-time proxy snapshot, not a blank image',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:proxy});
    await blankMasterDraws(page,1000);
    const download=await downloadFrame(page);
    assert.equal(download.width,1920);assert.equal(download.height,1080);
    assert.ok(isGreen(download.pixel),`proxy green pixel ${download.pixel}`);
    assert.ok(download.pixel[3]===255,`opaque pixel ${download.pixel}`);
    assert.match(download.status,/Frame downloaded \(1920 × 1080\)/);
}));
browserTest('proxy playback with no readable original captures the proxy and says it is preview resolution',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:null}});
    const download=await downloadFrame(page);
    assert.equal(download.width,1920);assert.equal(download.height,1080);
    assert.ok(isGreen(download.pixel),`proxy green pixel ${download.pixel}`);
    assert.match(download.status,/preview resolution/);
    assert.equal(download.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    await stubClipboard(page);
    const copy=await copyFrame(page);
    assert.equal(copy.promise,true);assert.equal(copy.gesture,true);
    assert.equal(copy.width,1920);assert.equal(copy.height,1080);
    assert.ok(isGreen(copy.pixel),`proxy green pixel ${copy.pixel}`);
    assert.equal(copy.status,'Frame copied (1920 × 1080).'+previewSuffix);
    assert.equal(await videoCount(page),1);
}));
browserTest('an unreadable master falls back to the proxy frame with the preview-resolution warning',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/missing.mp4'}});
    const started=Date.now();
    const download=await downloadFrame(page);
    const elapsed=Date.now()-started;
    assert.equal(download.width,1920);assert.equal(download.height,1080);
    assert.ok(isGreen(download.pixel),`proxy green pixel ${download.pixel}`);
    assert.equal(download.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    // The 404 settles through the error listener, not the 8 s timeout.
    assert.ok(elapsed<8000,`fallback took ${elapsed} ms`);
}));
browserTest('a stalled master times out to the proxy frame, leaves no live listeners, and frees the capture lock',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/stalled.mp4'}});
    await page.evaluate(()=>{window.masterSeeks=0;document.addEventListener('seeked',event=>{if(event.target!==window.player.$refs.videoEl)window.masterSeeks++;},true);});
    const started=Date.now();
    const fallback=await downloadFrame(page);
    const elapsed=Date.now()-started;
    // The master never answered, so only the resolver's 8 s timeout can have settled this capture.
    assert.ok(elapsed>=7900,`fallback after ${elapsed} ms`);
    assert.ok(page.stalledRoutes.length>=1,'the master request was held');
    assert.equal(fallback.name,'Sample-frame-0-500s.png');
    assert.equal(fallback.width,1920);assert.equal(fallback.height,1080);
    assert.ok(isGreen(fallback.pixel),`proxy green pixel ${fallback.pixel}`);
    assert.equal(fallback.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    assert.equal((await frameState(page)).busy,false);
    // Cleanup rules: a timed-out master is kept for reuse (only videoCancelFrame tears it down), unloaded and never seeked.
    assert.deepEqual(await page.evaluate(()=>{const el=window.player._videoMasterEl;return el&&{src:el.dataset.damSrc,readyState:el.readyState,connected:el.isConnected,seeks:window.masterSeeks};}),{src:'/stalled.mp4',readyState:0,connected:true,seeks:0});
    // The timed-out resolver removed its loadedmetadata listener: metadata arriving late must not seek the master.
    for(const route of page.stalledRoutes.splice(0))await serve(route,'video-frame-colors-4k.mp4');
    await page.waitForFunction(()=>window.player._videoMasterEl.readyState>=1,null,{timeout:10000});
    await page.waitForTimeout(300);
    assert.equal(await page.evaluate(()=>window.masterSeeks),0);
    // The lock was released: the next capture reuses the now-loaded master and gets master pixels.
    const master=await downloadFrame(page);
    assert.equal(master.width,3840);assert.equal(master.height,2160);
    assert.ok(isRed(master.pixel),`master red pixel ${master.pixel}`);
    assert.equal(master.status,'Frame downloaded (3840 × 2160).');
    assert.equal(await videoCount(page),2);
    await page.evaluate(()=>window.player.videoStopOnClose());
    assert.deepEqual(await page.evaluate(()=>({master:window.player._videoMasterEl,videos:document.querySelectorAll('video').length})),{master:null,videos:1});
}));
browserTest('closing the player while the master is still loading cancels the capture and frees the lock',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/stalled.mp4'}});
    let downloads=0;page.on('download',()=>downloads++);
    await page.getByRole('button',{name:'Download frame',exact:true}).click({noWaitAfter:true});
    const clicked=Date.now();
    // The capture is waiting on a master whose request is on the wire and unanswered.
    await page.waitForFunction(()=>window.player._videoMasterEl?.isConnected);
    const deadline=Date.now()+5000;
    while(!page.stalledRoutes.length&&Date.now()<deadline)await page.waitForTimeout(50);
    assert.ok(page.stalledRoutes.length>=1,'the master request was held');
    assert.deepEqual(await frameState(page),{busy:true,status:'Capturing frame…',statusShown:true});
    assert.equal(await page.evaluate(()=>window.player._videoMasterEl.readyState),0);
    await page.evaluate(()=>window.player.videoStopOnClose());
    assert.deepEqual(await frameState(page),{busy:false,status:'',statusShown:false});
    assert.deepEqual(await page.evaluate(()=>({master:window.player._videoMasterEl,videos:document.querySelectorAll('video').length})),{master:null,videos:1});
    // Wait out the resolver's 8 s timeout: the abandoned capture must settle silently, with no export.
    await page.waitForTimeout(Math.max(0,9500-(Date.now()-clicked)));
    assert.equal(downloads,0);
    assert.deepEqual(await frameState(page),{busy:false,status:'',statusShown:false});
    // A later capture against a readable master succeeds at master fidelity.
    await page.evaluate(()=>{window.player.previewData.originalUrl='/master.mp4';});
    const master=await downloadFrame(page);
    assert.equal(master.width,3840);assert.equal(master.height,2160);
    assert.ok(isRed(master.pixel),`master red pixel ${master.pixel}`);
    assert.equal(master.status,'Frame downloaded (3840 × 2160).');
    assert.equal(downloads,1);
}));
// A master fallback exports the frame the user clicked on, not whatever the still-playing proxy shows once the
// master finally errors or times out. The proxy plays from 0.75 s (green) across the 1 s boundary into yellow
// while the master loads; half-speed playback keeps the click comfortably inside the green second.
async function captureWhilePlaying(page,action){
    await page.evaluate(()=>{
        if(!window.captureTimesHooked){
            window.captureTimesHooked=true;
            // Visible-player time at the button click (capture phase, before its handler; the download's own
            // synthetic anchor click is ignored) and at PNG encoding.
            document.addEventListener('click',event=>{if(event.target.closest('button'))window.clickAt=window.player.$refs.videoEl.currentTime;},true);
            const encode=HTMLCanvasElement.prototype.toBlob;
            HTMLCanvasElement.prototype.toBlob=function(...args){window.encodeAt=window.player.$refs.videoEl.currentTime;return encode.apply(this,args);};
        }
        window.clickAt=null;window.encodeAt=null;
    });
    await seekPlayer(page,.75);
    await player(page).evaluate(async el=>{el.muted=true;el.playbackRate=.5;await el.play();});
    const started=Date.now();
    const result=action==='copy'?await copyFrame(page):await downloadFrame(page);
    const elapsed=Date.now()-started;
    await player(page).evaluate(el=>el.pause());
    return {...result,elapsed,...await page.evaluate(()=>({clickAt:window.clickAt,encodeAt:window.encodeAt}))};
}
function assertClickTimeFallback(capture){
    // Clicked inside the green second; encoded after playback reached yellow — only a click-time snapshot is green.
    assert.ok(capture.clickAt>=.7&&capture.clickAt<1,`clicked at ${capture.clickAt}`);
    assert.ok(capture.encodeAt>=1,`fallback encoded at ${capture.encodeAt}`);
    assert.equal(capture.width,1920);assert.equal(capture.height,1080);
    assert.ok(isGreen(capture.pixel),`click-time proxy green pixel ${capture.pixel}`);
}
browserTest('a master that errors after playback moves on exports the click-time proxy frame',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/late-404.mp4'}});
    const download=await captureWhilePlaying(page,'download');
    assertClickTimeFallback(download);
    assert.match(download.name,/^Sample-frame-0-[7-9]\d\ds\.png$/);
    assert.equal(download.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    // Settled by the late 404, not the 8 s timeout.
    assert.ok(download.elapsed<8000,`fallback took ${download.elapsed} ms`);
    await stubClipboard(page);
    const copy=await captureWhilePlaying(page,'copy');
    assert.deepEqual({promise:copy.promise,gesture:copy.gesture},{promise:true,gesture:true});
    assertClickTimeFallback(copy);
    assert.equal(copy.status,'Frame copied (1920 × 1080).'+previewSuffix);
    assert.ok(copy.elapsed<8000,`fallback took ${copy.elapsed} ms`);
}));
browserTest('a master that times out after playback moves on exports the click-time proxy frame',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/stalled.mp4'}});
    const download=await captureWhilePlaying(page,'download');
    assert.ok(download.elapsed>=7900,`fallback after ${download.elapsed} ms`);
    assertClickTimeFallback(download);
    assert.match(download.name,/^Sample-frame-0-[7-9]\d\ds\.png$/);
    assert.equal(download.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    await stubClipboard(page);
    const copy=await captureWhilePlaying(page,'copy');
    assert.ok(copy.elapsed>=7900,`fallback after ${copy.elapsed} ms`);
    assert.deepEqual({promise:copy.promise,gesture:copy.gesture},{promise:true,gesture:true});
    assertClickTimeFallback(copy);
    assert.equal(copy.status,'Frame copied (1920 × 1080).'+previewSuffix);
}));
browserTest('non-proxy and Original-selected captures are full resolution with no warning',()=>withBrowser(async browser=>{
    const plain=await downloadFrame(await setup(browser));
    assert.equal(plain.width,1920);assert.equal(plain.height,1080);
    assert.equal(plain.status,'Frame downloaded (1920 × 1080).');
    const page=await setup(browser,{previewData:proxy,data:{videoQuality:'original'}});
    assert.deepEqual(await player(page).evaluate(el=>({src:new URL(el.currentSrc).pathname,width:el.videoWidth})),{src:'/master.mp4',width:3840});
    const original=await downloadFrame(page);
    assert.equal(original.width,3840);assert.equal(original.height,2160);
    assert.ok(isRed(original.pixel),`master red pixel ${original.pixel}`);
    assert.equal(original.status,'Frame downloaded (3840 × 2160).');
    // Original is already the visible source, so no hidden master is created.
    assert.equal(await videoCount(page),1);
}));
browserTest('switching to Original while paused keeps the position and the pause on the master',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:proxy});
    await page.evaluate(()=>window.player.setVideoQuality('original'));
    await page.waitForFunction(()=>{const el=window.player.$refs.videoEl;return el.currentSrc.endsWith('/master.mp4')&&el.readyState>=2&&!el.seeking&&Math.abs(el.currentTime-.5)<=.05;},null,{timeout:10000});
    const state=await player(page).evaluate(el=>({src:new URL(el.currentSrc).pathname,paused:el.paused,time:el.currentTime,width:el.videoWidth}));
    assert.equal(state.src,'/master.mp4');assert.equal(state.width,3840);assert.equal(state.paused,true);
    assert.ok(Math.abs(state.time-.5)<=.05,`currentTime ${state.time}`);
    // The template's autoplay attribute must not restart a paused video after the source swap.
    await page.waitForTimeout(500);
    assert.deepEqual(await player(page).evaluate(el=>({paused:el.paused,time:el.currentTime})),{paused:true,time:state.time});
}));
browserTest('switching to Original while playing resumes playback from the same position',()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:proxy});
    const at=await player(page).evaluate(async el=>{
        el.muted=true;el.loop=true;
        el.addEventListener('playing',()=>{if(el.currentSrc.endsWith('/master.mp4')&&window.masterPlayingAt==null)window.masterPlayingAt=el.currentTime;});
        await el.play();return el.currentTime;
    });
    await page.evaluate(()=>window.player.setVideoQuality('original'));
    await page.waitForFunction(()=>window.masterPlayingAt!=null,null,{timeout:10000});
    const resumedAt=await page.evaluate(()=>window.masterPlayingAt);
    assert.ok(resumedAt>=at-.05&&resumedAt<=at+.5,`resumed at ${resumedAt}, switched at ${at}`);
    const first=await player(page).evaluate(el=>({src:new URL(el.currentSrc).pathname,paused:el.paused,time:el.currentTime}));
    assert.equal(first.src,'/master.mp4');assert.equal(first.paused,false);
    await page.waitForTimeout(300);
    const later=await player(page).evaluate(el=>({paused:el.paused,time:el.currentTime}));
    assert.equal(later.paused,false);assert.notEqual(later.time,first.time);
}));

// Real alpha-bearing WebM masters (VP8 and VP9): a fully transparent decoded frame is the real content, not a
// failed draw. The browser reports presenting it (requestVideoFrameCallback), so the capture keeps the master's
// dimensions and transparency instead of exporting the opaque, lower-resolution proxy snapshot.
const alphaProbes=[[1280,720],[5,5],[2550,1430]];
const isTransparent=probed=>probed.every(pixel=>pixel[3]===0);
for(const codec of ['vp9','vp8']){
    const alpha={...proxy,originalUrl:`/alpha-${codec}.webm`};
    browserTest(`${codec} alpha master: a legitimately transparent frame is downloaded and copied at master size, then an opaque later frame`,{timeout:60000},()=>withBrowser(async browser=>{
        const page=await setup(browser,{previewData:alpha});
        await stubClipboard(page);
        await page.evaluate(()=>{
            window.alphaDraws=0;
            const draw=CanvasRenderingContext2D.prototype.drawImage;
            CanvasRenderingContext2D.prototype.drawImage=function(source,...args){if(source instanceof HTMLVideoElement&&source.videoWidth===2560)window.alphaDraws++;return draw.call(this,source,...args);};
        });
        const download=await downloadFrame(page,alphaProbes);
        const downloadDraws=await page.evaluate(()=>window.alphaDraws);
        assert.ok(downloadDraws<20,`the loop ended on presentation evidence, not by exhausting 20 redraws (${downloadDraws} draws)`);
        assert.equal(download.width,2560);assert.equal(download.height,1440);
        assert.ok(isTransparent(download.probed),`all alpha 0: ${JSON.stringify(download.probed)}`);
        assert.equal(download.status,'Frame downloaded (2560 × 1440).');
        // Copy at the same timestamp: the master has no new frame to present, yet the evidence still holds.
        const copy=await copyFrame(page,alphaProbes);
        assert.ok(isTransparent(copy.probed),`copied PNG is transparent too: ${JSON.stringify(copy.probed)}`);
        const copyDraws=await page.evaluate(()=>window.alphaDraws)-downloadDraws;
        assert.ok(copyDraws>=1&&copyDraws<20,`the copy ended on inherited evidence (${copyDraws} draws)`);
        assert.deepEqual({promise:copy.promise,gesture:copy.gesture},{promise:true,gesture:true});
        assert.equal(copy.width,2560);assert.equal(copy.height,1440);
        assert.equal(copy.status,'Frame copied (2560 × 1440).');
        // A later opaque frame of the same file proves the transparent one was decoded, not missing.
        await seekPlayer(page,1.5);
        const opaque=await downloadFrame(page,[[1280,720]]);
        assert.equal(opaque.width,2560);assert.equal(opaque.height,1440);
        assert.ok(opaque.probed[0][3]===255&&opaque.probed[0][0]>240&&opaque.probed[0][1]<60,`master red ${opaque.probed[0]}`);
    }));
    browserTest(`${codec} alpha master that presents no frame still falls back to the proxy snapshot with the preview-resolution warning`,{timeout:60000},()=>withBrowser(async browser=>{
        const page=await setup(browser,{previewData:alpha});
        await page.evaluate(()=>{
            const register=HTMLVideoElement.prototype.requestVideoFrameCallback;
            HTMLVideoElement.prototype.requestVideoFrameCallback=function(callback){return this.videoWidth===2560?0:register.call(this,callback);};
        });
        const download=await downloadFrame(page);
        assert.equal(download.width,1920);assert.equal(download.height,1080);
        assert.ok(isGreen(download.pixel),`proxy green ${download.pixel}`);
        assert.equal(download.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    }));
}
browserTest('closing the player during an alpha master capture that has not presented a frame stays silent and frees the lock',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/alpha-vp9.webm'}});
    await page.evaluate(()=>{
        const register=HTMLVideoElement.prototype.requestVideoFrameCallback;
        HTMLVideoElement.prototype.requestVideoFrameCallback=function(callback){return this.videoWidth===2560?0:register.call(this,callback);};
    });
    const downloads=[];page.on('download',download=>downloads.push(download));
    await page.getByRole('button',{name:'Download frame',exact:true}).click({noWaitAfter:true});
    await new Promise(resolve=>setTimeout(resolve,200));
    await page.evaluate(()=>window.player.videoCancelFrame());
    await new Promise(resolve=>setTimeout(resolve,1500));
    assert.equal(downloads.length,0,'no download after cancellation');
    assert.equal((await frameState(page)).busy,false,'capture lock is free');
}));
browserTest('alpha master frame reports that are after the target, negative or malformed are not presentation evidence: the proxy snapshot is exported',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/alpha-vp9.webm'}});
    await page.evaluate(()=>{
        const register=HTMLVideoElement.prototype.requestVideoFrameCallback;
        // Reports that cannot be the frame current at 0.5 s: a frame stamped after the target, a negative stamp, and malformed ones.
        const reports=[{mediaTime:.9},{mediaTime:-1},{},{mediaTime:'0.5'},{mediaTime:NaN},{mediaTime:Infinity}];
        HTMLVideoElement.prototype.requestVideoFrameCallback=function(callback){
            if(this.videoWidth!==2560)return register.call(this,callback);
            const report=reports.shift();
            if(report)setTimeout(()=>callback(0,report),20);
            return 0;
        };
    });
    const download=await downloadFrame(page);
    assert.equal(download.width,1920);assert.equal(download.height,1080);
    assert.ok(isGreen(download.pixel),`proxy green ${download.pixel}`);
    assert.equal(download.status,'Frame downloaded (1920 × 1080).'+previewSuffix);
    assert.equal(await page.evaluate(()=>window.player._videoMasterEl._damPresentation.presented),false);
}));
browserTest('a 1 fps alpha master (current frame at 0.5 s is stamped 0.0) is exported transparent at master size, not replaced by the proxy',{timeout:60000},()=>withBrowser(async browser=>{
    const page=await setup(browser,{previewData:{...proxy,originalUrl:'/alpha-1fps.webm'}});
    await page.evaluate(()=>{
        window.alphaDraws=0;window.reportedMediaTimes=[];
        const draw=CanvasRenderingContext2D.prototype.drawImage;
        CanvasRenderingContext2D.prototype.drawImage=function(source,...args){if(source instanceof HTMLVideoElement&&source.videoWidth===2560)window.alphaDraws++;return draw.call(this,source,...args);};
        const register=HTMLVideoElement.prototype.requestVideoFrameCallback;
        HTMLVideoElement.prototype.requestVideoFrameCallback=function(callback){
            return register.call(this,(now,metadata)=>{if(this.videoWidth===2560)window.reportedMediaTimes.push(metadata.mediaTime);callback(now,metadata);});
        };
    });
    const download=await downloadFrame(page,alphaProbes);
    assert.equal(download.width,2560);assert.equal(download.height,1440);
    assert.ok(isTransparent(download.probed),`all alpha 0: ${JSON.stringify(download.probed)}`);
    assert.equal(download.status,'Frame downloaded (2560 × 1440).');
    assert.ok(await page.evaluate(()=>window.alphaDraws)<20,'the loop ended on presentation evidence, not by exhausting 20 redraws');
    assert.deepEqual(await page.evaluate(()=>window.reportedMediaTimes),[0],'the browser reported the 0.0 frame as current at 0.5 s');
}));
browserTest('fullscreen takes the player with its controls, and falls back to the native video player where elements cannot go fullscreen (iPhone)',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await setup(browser);
        await page.evaluate(()=>player.videoToggleFullscreen());
        await page.waitForFunction(()=>player.videoIsFullscreen);
        assert.equal(await page.evaluate(()=>document.fullscreenElement?.hasAttribute('data-dam-flat-video-player')),true);
        await page.evaluate(()=>player.videoToggleFullscreen());
        await page.waitForFunction(()=>!player.videoIsFullscreen&&!document.fullscreenElement);
        // iPhone Safari (iOS 27.1 Duo simulator, probed): no requestFullscreen or webkitRequestFullscreen on elements,
        // only the video's own webkitEnterFullscreen.
        const native=await page.evaluate(()=>{
            delete Element.prototype.requestFullscreen;delete Element.prototype.webkitRequestFullscreen;
            let calls=0;HTMLVideoElement.prototype.webkitEnterFullscreen=function(){calls++;};
            player.videoToggleFullscreen();return calls;
        });
        assert.equal(native,1);
    }finally{await browser.close();}
});
