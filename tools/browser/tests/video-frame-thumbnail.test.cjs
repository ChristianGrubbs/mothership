const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const views=path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/video');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
// "Set this frame as thumbnail" from the video's context menu and overflow menu. `/fixture.mp4` is the
// 1080p red→blue stream shared with video-frame.test.cjs, so a 0.5 s capture is red and a 1024-wide
// JPEG proves the thumbnail was fitted, not exported at source size.
function serve(route,file){
    const body=fs.readFileSync(path.join(__dirname,'../fixtures',file));
    const range=/bytes=(\d+)-(\d*)/.exec(route.request().headers().range||'');
    if(range){const start=Number(range[1]),end=range[2]?Math.min(Number(range[2]),body.length-1):body.length-1;return route.fulfill({status:206,body:body.subarray(start,end+1),contentType:'video/mp4',headers:{'Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${body.length}`}});}
    return route.fulfill({body,contentType:'video/mp4',headers:{'Accept-Ranges':'bytes'}});
}
// The page stands in for the preview modal: `$axios.post` records every upload and `$emitter` records
// every event, the two seams the real modal supplies through Vue global properties.
async function setup(browser,{previewData={}}={}){
    const page=await browser.newPage();
    await page.route('https://media.test/**',route=>{
        const pathname=new URL(route.request().url()).pathname;
        if(pathname==='/fixture.mp4')return serve(route,'video-frame-colors.mp4');
        return route.fulfill({contentType:'text/html',body:'<style>[data-dam-flat-video-player]{position:relative;width:640px;height:360px}video{width:160px;height:90px}svg{width:16px;height:16px}</style><div id="app"></div>'});
    });
    await page.goto('https://media.test/');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.addScriptTag({content:fs.readFileSync(path.join(views,'video-player-script.blade.php'),'utf8').replace(/<\/?script[^>]*>/g,'')});
    // Blade drops {{-- comments --}} server-side; left in, Vue would compile them as interpolations.
    const template=fs.readFileSync(path.join(views,'video-player.blade.php'),'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/@js\(trans\('[^']+'\)\)/g,'\'Control\'').replace(/@lang\('[^']+'\)/g,'Control').replace(/@\{\{/g,'{{');
    await page.evaluate(({template,previewData})=>{
        window.posts=[];window.events=[];
        window.uploadResponse=()=>({data:{thumbnail_url:'/thumb?v=rev-1'}});
        window.app=Vue.createApp({template,data:()=>({...window._damVideoPlayer.data,previewData:{id:1,mediaUrl:'/fixture.mp4',mime_type:'video/mp4',file_name:'Sample.mp4',customThumbnailUrl:'/assets/1/custom-thumbnail',canSetThumbnail:true,...previewData}}),computed:window._damVideoPlayer.computed,methods:{...window._damVideoPlayer.methods,_formatTime:s=>String(s||0)},mounted(){this.videoMounted();},beforeUnmount(){this.videoBeforeUnmount();}});
        window.app.config.globalProperties.$emitter={emit:(name,payload)=>window.events.push({name,payload}),on(){},off(){}};
        window.app.config.globalProperties.$axios={post:async(url,form)=>{
            const file=form.get('thumbnail');
            const record={url,name:file?.name||null,type:file?.type||null,size:file?.size||0,bytes:file?Array.from(new Uint8Array(await file.arrayBuffer())):[]};
            window.posts.push(record);
            return window.uploadResponse(record);
        }};
        window.player=window.app.mount('#app');
    },{template,previewData});
    await page.waitForFunction(()=>document.querySelector('video').readyState>=2);
    await page.locator('video').evaluate(async el=>{el.pause();await new Promise(resolve=>{el.addEventListener('seeked',resolve,{once:true});el.currentTime=.5;});});
    return page;
}
async function decode(page,record){
    return page.evaluate(async({bytes,type})=>{
        const bitmap=await createImageBitmap(new Blob([new Uint8Array(bytes)],{type}));
        const canvas=document.createElement('canvas');canvas.width=bitmap.width;canvas.height=bitmap.height;
        const ctx=canvas.getContext('2d');ctx.drawImage(bitmap,0,0);
        const result={width:bitmap.width,height:bitmap.height,pixel:Array.from(ctx.getImageData(50,50,1,1).data)};bitmap.close();return result;
    },record);
}
async function withBrowser(fn){
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{await fn(browser);}finally{await browser.close();}
}
const menu=page=>page.locator('[data-dam-video-ctx-menu]');
const setThumbnail=scope=>scope.locator('[data-dam-set-thumbnail]');
const events=(page,name)=>page.evaluate(name=>window.events.filter(e=>e.name===name).map(e=>e.payload),name);

browserTest('right-click on the video opens the frame menu and suppresses only the native menu on the video',async()=>{
    await withBrowser(async browser=>{
        const page=await setup(browser);
        assert.equal(await menu(page).count(),0);
        await page.locator('video').click({button:'right'});
        assert.equal(await menu(page).count(),1);
        assert.equal(await setThumbnail(menu(page)).count(),1);
        assert.equal(await menu(page).locator('[data-dam-download-frame]').count(),1);
        const prevented=await page.evaluate(()=>{
            const fire=el=>{const event=new MouseEvent('contextmenu',{bubbles:true,cancelable:true,clientX:20,clientY:20});el.dispatchEvent(event);return event.defaultPrevented;};
            return {video:fire(document.querySelector('video')),player:fire(document.querySelector('[data-dam-flat-video-player]'))};
        });
        assert.deepEqual(prevented,{video:true,player:false});
        // Escape closes it, like the other player menus.
        await page.keyboard.press('Escape');
        assert.equal(await menu(page).count(),0);
    });
});

browserTest('set this frame as thumbnail posts a fitted JPEG for the current asset and announces the new thumbnail',async()=>{
    await withBrowser(async browser=>{
        const page=await setup(browser);
        // ‹ › navigation replaces previewData wholesale; the menu must follow the asset now on screen.
        await page.evaluate(()=>{window.player.previewData={...window.player.previewData,id:2,file_name:'Second.mp4',customThumbnailUrl:'/assets/2/custom-thumbnail'};});
        await page.locator('video').click({button:'right'});
        await setThumbnail(menu(page)).click();
        await page.waitForFunction(()=>window.posts.length===1&&!window.player.videoFrameBusy);
        const [post]=await page.evaluate(()=>window.posts);
        assert.equal(post.url,'/assets/2/custom-thumbnail');
        assert.equal(post.type,'image/jpeg');
        assert.equal(post.name,'Second-frame-0-500s.jpg');
        const image=await decode(page,post);
        assert.equal(image.width,1024);assert.equal(image.height,576);
        assert.ok(image.pixel[0]>220&&image.pixel[2]<40,`expected the red frame, got ${image.pixel}`);
        assert.deepEqual(await events(page,'dam:thumbnail-updated'),[{id:2,url:'/thumb?v=rev-1'}]);
        const flashes=await events(page,'add-flash');
        assert.equal(flashes.length,1);assert.equal(flashes[0].type,'success');assert.match(flashes[0].message,/thumbnail updated/i);
        assert.equal(await menu(page).count(),0);
        assert.deepEqual(await page.locator('video').evaluate(el=>({paused:el.paused,time:el.currentTime})),{paused:true,time:.5});
    });
});

browserTest('the overflow menu offers the same action, disabled while a capture is in flight, and reports upload failure',async()=>{
    await withBrowser(async browser=>{
        const page=await setup(browser);
        await page.evaluate(()=>{window.player.videoMenuOpen=true;});
        const item=setThumbnail(page.locator('[data-dam-video-overflow-menu]'));
        assert.equal(await item.count(),1);
        assert.equal(await item.isDisabled(),false);
        await page.evaluate(()=>{
            const encode=HTMLCanvasElement.prototype.toBlob;
            HTMLCanvasElement.prototype.toBlob=function(callback,type,quality){window.finishEncode=()=>encode.call(this,callback,type,quality);};
            window.uploadResponse=()=>{const error=new Error('Request failed');error.response={status:422,data:{message:'The thumbnail failed to upload.'}};throw error;};
        });
        await item.click();
        await page.waitForFunction(()=>window.player.videoFrameBusy);
        await page.evaluate(()=>{window.player.videoMenuOpen=true;});
        assert.equal(await setThumbnail(page.locator('[data-dam-video-overflow-menu]')).isDisabled(),true);
        await page.evaluate(()=>window.finishEncode());
        await page.waitForFunction(()=>window.posts.length===1&&!window.player.videoFrameBusy);
        assert.deepEqual(await events(page,'dam:thumbnail-updated'),[]);
        const flashes=await events(page,'add-flash');
        assert.equal(flashes.length,1);assert.equal(flashes[0].type,'error');assert.equal(flashes[0].message,'The thumbnail failed to upload.');
    });
});

browserTest('viewers without edit rights see download frame but no thumbnail action',async()=>{
    await withBrowser(async browser=>{
        const page=await setup(browser,{previewData:{canSetThumbnail:false}});
        await page.locator('video').click({button:'right'});
        assert.equal(await menu(page).locator('[data-dam-download-frame]').count(),1);
        assert.equal(await setThumbnail(page).count(),0);
        await page.evaluate(()=>{window.player.videoMenuOpen=true;});
        assert.equal(await setThumbnail(page).count(),0);
    });
});
