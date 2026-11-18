const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath&&process.env.MOTHERSHIP_VUE_GLOBAL_PATH?test:test.skip;
const blade=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/comments/index.blade.php'),'utf8');
const anchorsPath=path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/review-anchors.js');

// The shipped review strings (en_US), so labels read as on the page.
function reviewStrings(){
    const lang=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/lang/en_US/app.php'),'utf8');
    const block=lang.split(/'review'\s+=> \[/)[1].split(/\n\s*\],/)[0];
    const strings={};
    for(const [,key,value] of block.matchAll(/'([a-z0-9-]+)'\s+=> '((?:[^'\\]|\\.)*)'/g))strings[key]=value.replace(/\\'/g,"'");
    return strings;
}

// The Comments tab's templates and script with Blade resolved the way a full admin sees them: every permission
// held, routes as /fixture/<name> URLs keeping the placeholders the script replaces, and the payloads from window.
function compiled(){
    const unblade=text=>text
        .replace(/^\s*@if \(bouncer\(\)[^\n]*\n/gm,'')
        .replace(/^\s*@endif\s*\n/gm,'')
        .replace(/@lang\('[^']*\.([a-z0-9-]+)'\)/g,(_,key)=>key)
        .replace(/@\{\{/g,'{{');
    const templates=[...blade.matchAll(/<script\s+type="text\/x-template"\s+id="([^"]+)">([\s\S]*?)<\/script>/g)].map(([,id,body])=>({id,body:unblade(body)}));
    const script=unblade(blade.split('<script type="module">')[1].split('</script>')[0])
        .replace(/@json\(\(int\) \$id\)/g,'5')
        .replace(/@json\(\$reviewPayload\)/g,'JSON.parse(JSON.stringify(window.__payload))')
        .replace(/@json\(\$reviewMedia\)/g,'window.__media')
        .replace(/@json\(auth\(\)[^\n]*?\)\)\)?;/g,'1;')
        .replace(/@json\(bouncer\(\)->hasPermission\('[^']+'\)\)/g,'true')
        .replace(/@json\(trans\('dam::app\.admin\.dam\.asset\.comments\.review'\)\)/g,'window.__strings')
        .replace(/@json\(trans\('([^']+)'\)\)/g,(_,key)=>JSON.stringify(key))
        .replace(/@json\(route\('([^']+)'[^\n]*\)\)/g,(_,name)=>JSON.stringify(`/fixture/${name}?file=__FILE__&comment=__COMMENT__&request=__REQUEST__`))
        .replace(/"\{\{ route\('([^']+)'[^}]*\}\}"/g,(_,name)=>JSON.stringify(`/fixture/${name}`));
    return {templates,script};
}

// `plays` is the asset file id the server names as what plays the revision (its proxy or itself); null = nothing can.
const revision=(id,number,current,plays)=>({id,revision_number:number,is_current:current,is_representative:true,label:null,created_at:`2026-10-0${number}T10:00:00Z`,playback:plays===null?null:{asset_file_id:plays,mime:'video/mp4',is_proxy:plays!==id}});
const comment=(id,fileId,anchor,extra={})=>({id,admin_id:1,admin:{id:1,name:'Riley Morgan',image_url:null},comments:`Comment ${id}`,parent_id:null,dam_asset_id:5,asset_file_id:fileId,anchor,mentions:[],resolved_at:null,resolved_by:null,resolver:null,is_previous_revision:false,created_at:`2026-10-07T10:0${id}:00Z`,updated_at:`2026-10-07T10:0${id}:00Z`,children:[],...extra});
const round=(rev,comments)=>({key:'revision-'+rev.id,revision:rev,comments});

function serveVideo(route,file){
    const body=fs.readFileSync(path.join(__dirname,'../fixtures',file));
    const range=/bytes=(\d+)-(\d*)/.exec(route.request().headers().range||'');
    if(range){const start=Number(range[1]),end=range[2]?Math.min(Number(range[2]),body.length-1):body.length-1;return route.fulfill({status:206,body:body.subarray(start,end+1),contentType:'video/mp4',headers:{'Accept-Ranges':'bytes','Content-Range':`bytes ${start}-${end}/${body.length}`}});}
    return route.fulfill({body,contentType:'video/mp4',headers:{'Accept-Ranges':'bytes'}});
}

// Mounts the tab on https://review.test/ with `payload`; `window.server.payload` is what the next review read returns.
async function mount(browser,payload){
    const page=await browser.newPage();
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    page.served=[];
    await page.route('https://review.test/**',route=>{
        const pathname=new URL(route.request().url()).pathname;
        if(pathname.endsWith('.mp4')){
            page.served.push(pathname);
            // File 10 is red/blue; every other file id serves the green/yellow clip. Tests tell them apart by URL.
            return serveVideo(route,pathname==='/media/file-10.mp4'?'video-frame-colors.mp4':'video-frame-proxy-1080p.mp4');
        }
        return route.fulfill({contentType:'text/html',body:'<div id="app"><v-comments></v-comments></div>'});
    });
    await page.goto('https://review.test/');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_GLOBAL_PATH});
    await page.addScriptTag({path:anchorsPath});
    const {templates,script}=compiled();
    await page.evaluate(({templates,script,payload,strings})=>{
        window.__payload=payload;
        window.__strings=strings;
        window.__media={kind:'video',image_url:null,revision_url:'https://review.test/media/file-__FILE__.mp4'};
        window.server={payload:JSON.parse(JSON.stringify(payload))};
        window.calls=[];
        for(const {id,body} of templates){const el=document.createElement('script');el.type='text/x-template';el.id=id;el.textContent=body;document.body.append(el);}
        const app=Vue.createApp({});
        app.config.globalProperties.$emitter={emit(){}};
        app.config.globalProperties.$axios={
            get:async url=>({data:url.includes('review.show')?JSON.parse(JSON.stringify(window.server.payload)):{users:[],groups:[]}}),
            post:async(url,data)=>{window.calls.push({method:'post',url,data});return {data:{message:'Saved'}};},
            put:async(url,data)=>{window.calls.push({method:'put',url,data});return {data:{message:'Saved',watching:true}};},
            delete:async url=>({data:{message:'Deleted'}}),
        };
        new Function('app',script)(app);
        app.mount('#app');
    },{templates,script,payload,strings:reviewStrings()});
    page.errors=errors;
    return page;
}

const mediaState=page=>page.locator('[data-dam-review-media]').evaluate(el=>({src:el.currentSrc||el.getAttribute('src'),time:el.currentTime,ready:el.readyState}));

browserTest('a time code plays the revision its comment is about, then seeks once that revision has loaded',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const older=revision(10,1,false,10),current=revision(11,2,true,31);
        const page=await mount(browser,{current_revision:current,rounds:[
            round(current,[comment(2,11,{type:'time',start:0.5})]),
            round(older,[comment(1,10,{type:'time',start:1.5},{is_previous_revision:true})]),
        ],requests:[],watching:false,can_moderate:true});
        await page.waitForFunction(()=>document.querySelector('[data-dam-review-media]').readyState>=1);
        assert.match((await mediaState(page)).src,/\/media\/file-31\.mp4$/,'the current revision plays the proxy the server named');

        await page.locator('#dam-comment-1 [data-dam-review-timecode]').click();
        await page.waitForFunction(()=>{const el=document.querySelector('[data-dam-review-media]');return /file-10\.mp4$/.test(el.currentSrc)&&el.currentTime>=1.4;});
        const onOlder=await mediaState(page);
        assert.match(onOlder.src,/\/media\/file-10\.mp4$/,'the older comment plays the older revision');
        assert.ok(onOlder.time>=1.4&&onOlder.time<=2.01,`seeked to 1.5 s on the older revision, got ${onOlder.time}`);
        assert.equal((await page.locator('[data-dam-review-viewed]').textContent()).trim(),'Revision 1');

        await page.locator('#dam-comment-2 [data-dam-review-timecode]').click();
        await page.waitForFunction(()=>{const el=document.querySelector('[data-dam-review-media]');return /file-31\.mp4$/.test(el.currentSrc)&&el.currentTime>=0.45;});
        const onCurrent=await mediaState(page);
        assert.match(onCurrent.src,/\/media\/file-31\.mp4$/,'the current comment plays the current revision');
        assert.ok(onCurrent.time>=0.45&&onCurrent.time<=2.01,`seeked to 0.5 s on the current revision, got ${onCurrent.time}`);
        assert.deepEqual(page.errors,[]);
    }finally{await browser.close();}
});

browserTest('a comment stays on the revision on show when a newer one is uploaded, and showing the newer one plays and comments on it',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const shown=revision(10,1,true,10);
        const page=await mount(browser,{current_revision:shown,rounds:[round(shown,[comment(1,10,null)])],requests:[],watching:false,can_moderate:true});
        await page.waitForFunction(()=>document.querySelector('[data-dam-review-media]').readyState>=1);

        // Another user uploads revision 11, which plays through proxy 51; the next read (after resolving comment 1) sees it.
        await page.evaluate(()=>{
            const p=window.server.payload;
            p.rounds[0].revision.is_current=false;
            p.rounds[0].comments[0].resolved_at='2026-10-07T11:01:00Z';
            p.current_revision={id:11,revision_number:2,is_current:true,is_representative:true,label:null,created_at:'2026-10-07T11:00:00Z',playback:{asset_file_id:51,mime:'video/mp4',is_proxy:true}};
        });
        await page.locator('#dam-comment-1 [data-dam-review-resolve]').click();
        await page.waitForFunction(()=>window.calls.length===1&&document.querySelectorAll('[data-dam-review-round]').length===0);

        const box=page.locator('[data-dam-review-box]').first();
        const store=()=>page.evaluate(()=>window.calls.filter(call=>call.url.includes('comment.store')).map(call=>call.data));
        await box.locator('textarea').fill('General note on what I am looking at');
        await box.locator('.primary-button').click();
        await page.locator('[data-dam-review-add-time]').click();
        await box.locator('textarea').fill('Timed note on what I am looking at');
        await box.locator('.primary-button').click();
        await page.waitForFunction(()=>window.calls.filter(call=>call.url.includes('comment.store')).length===2);

        let posted=await store();
        assert.deepEqual(posted.map(data=>data.asset_file_id),[10,10],'both comments stay on the revision on show');
        assert.equal(posted[0].anchor,undefined);
        assert.equal(posted[1].anchor.type,'time');
        assert.equal((await page.locator('[data-dam-review-viewed]').textContent()).trim(),'Revision 1');
        assert.match((await mediaState(page)).src,/\/media\/file-10\.mp4$/,'still playing the revision on show');

        // Showing the newer revision plays what the refreshed read named for it, and comments land on it.
        await page.locator('[data-dam-review-show-current]').click();
        await page.waitForFunction(()=>/file-51\.mp4$/.test(document.querySelector('[data-dam-review-media]').currentSrc));
        assert.equal((await page.locator('[data-dam-review-viewed]').textContent()).trim(),'Revision 2');
        await box.locator('textarea').fill('Note on the new upload');
        await box.locator('.primary-button').click();
        await page.waitForFunction(()=>window.calls.filter(call=>call.url.includes('comment.store')).length===3);
        posted=await store();
        assert.equal(posted[2].asset_file_id,11,'the newer revision on show is what the comment names');
        assert.ok(!page.served.some(served=>/file-11\.mp4$/.test(served)),'the newer revision plays its proxy, not its original');
        assert.deepEqual(page.errors,[]);
    }finally{await browser.close();}
});

browserTest('an older revision that needs a proxy plays its own proxy, or says playback is unavailable instead of hanging',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        // Revision 5 is a ProRes master with no proxy of its own; revision 6 is one with proxy 41; 11 is current.
        const bare=revision(5,1,false,null),proxied=revision(6,2,false,41),current=revision(11,3,true,11);
        const page=await mount(browser,{current_revision:current,rounds:[
            round(proxied,[comment(2,6,{type:'time',start:1.5},{is_previous_revision:true})]),
            round(bare,[comment(1,5,{type:'time',start:1},{is_previous_revision:true})]),
        ],requests:[],watching:false,can_moderate:true});
        await page.waitForFunction(()=>document.querySelector('[data-dam-review-media]').readyState>=1);

        await page.locator('#dam-comment-1 [data-dam-review-timecode]').click();
        await page.locator('[data-dam-review-playback-unavailable]').waitFor();
        assert.equal((await page.locator('[data-dam-review-playback-unavailable]').textContent()).trim(),"Playback isn't available for this revision");
        assert.equal(await page.locator('[data-dam-review-media]').count(),0,'no player is left waiting for metadata');
        assert.equal((await page.locator('[data-dam-review-viewed]').textContent()).trim(),'Revision 1');
        assert.equal(await page.locator('[data-dam-review-add-time]').isDisabled(),true);

        // A time drafted on the current revision is dropped when another revision is shown.
        await page.locator('[data-dam-review-show-current]').click();
        await page.waitForFunction(()=>document.querySelector('[data-dam-review-media]')?.readyState>=1);
        await page.locator('[data-dam-review-add-time]').click();
        await page.locator('[data-dam-review-draft-chip]').waitFor();
        await page.locator('#dam-comment-2 [data-dam-review-timecode]').click();
        await page.waitForFunction(()=>{const el=document.querySelector('[data-dam-review-media]');return el&&/file-41\.mp4$/.test(el.currentSrc)&&el.currentTime>=1.4;});
        const onProxy=await mediaState(page);
        assert.match(onProxy.src,/\/media\/file-41\.mp4$/,'the older revision plays its own proxy');
        assert.ok(onProxy.time>=1.4&&onProxy.time<=2.01,`seeked to 1.5 s on the proxy, got ${onProxy.time}`);
        assert.equal((await page.locator('[data-dam-review-viewed]').textContent()).trim(),'Revision 2');
        assert.equal(await page.locator('[data-dam-review-draft-chip]').count(),0,'the draft time from the current revision is gone');
        assert.ok(!page.served.some(served=>/file-(5|6)\.mp4$/.test(served)),'no ProRes original was requested');
        assert.deepEqual(page.errors,[]);
    }finally{await browser.close();}
});
