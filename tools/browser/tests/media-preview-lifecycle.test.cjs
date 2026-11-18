const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {launchBrowser}=require('../launch-browser.cjs');
const root=path.resolve(__dirname,'../../..'),dam=path.join(root,'packages/Mothership/DAM');
const views=path.join(dam,'src/Resources/views/asset');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const read=file=>fs.readFileSync(path.join(views,file),'utf8');
function bladeCalls(source,fixture){
    return replaceCalls(source,fixture);
}
function replaceCalls(source,fixture){
    let output='',offset=0;
    for(;;){
        const match=/@(js|json)\(/.exec(source.slice(offset));if(!match)return output+source.slice(offset);
        const start=offset+match.index;output+=source.slice(offset,start);let depth=1,quote=null,escape=false,end=start+match[0].length;
        for(;end<source.length&&depth;end++){
            const ch=source[end];if(escape){escape=false;continue;}if(ch==='\\'){escape=true;continue;}if(quote){if(ch===quote)quote=null;continue;}if(ch==='\''||ch==='"'){quote=ch;continue;}if(ch==='(')depth++;if(ch===')')depth--;
        }
        const expression=source.slice(start,end);
        output+=expression.includes('$initialPreviewData')?JSON.stringify(fixture):expression.includes("request()->has('file')")?'false':expression.includes('directoryAncestors')?'[]':expression.includes('panoramaPreview')?'null':expression.includes('Labels')?'{}':'"Label"';offset=end;
    }
}
function html(source){
    return source.replace(/@include\('dam::asset\.([^']+)'(?:,[^\n]*)?\)/g,(_,partial)=>{
        if(['preview-modal.card','preview-modal.viewer-modal','preview-modal.audio.audio-player','preview-modal.files.pdf-viewer'].includes(partial))return html(read(partial.replaceAll('.','/')+'.blade.php'));
        return '';
    // This test only exercises the audio/PDF lifecycle, not the prev/next nav @if(...)@endif that
    // viewer-modal.blade.php gates on $hasPreviewNavigation — dropped here like any other server-only
    // conditional the fixture never sets, same as this harness already drops @pushOnce('styles').
    }).replace(/@if\b[\s\S]*?@endif/g,'').replace(/@js\(trans\('([^']+)'\)\)/g,(_,key)=>JSON.stringify(key.split('.').pop())).replace(/@json\(trans\('[^']+'\)\)/g,'{}').replace(/@pushOnce\('styles'\)[\s\S]*?@endPushOnce/g,'').replace(/@lang\('[^']+'\)/g,'Label').replace(/(?<!@)\{\{[\s\S]*?\}\}/g,'Label').replace(/@\{\{/g,'{{');
}
async function parentPage(browser,entry,type='audio'){
    const page=await browser.newPage();
    page.problems=[];
    page.on('pageerror',error=>page.problems.push(error.message));
    page.on('console',message=>{if(/was accessed during render but is not defined|Unhandled error/.test(message.text()))page.problems.push(message.text());});
    // Valid PCM bytes keep browser codec/load errors separate from the explicit Play rejection below.
    // Serving HTML as /one raced NotAllowedError with a media-load error on Linux Chromium.
    const wav=Buffer.alloc(44+960);
    wav.write('RIFF',0);wav.writeUInt32LE(wav.length-8,4);wav.write('WAVEfmt ',8);wav.writeUInt32LE(16,16);
    wav.writeUInt16LE(1,20);wav.writeUInt16LE(1,22);wav.writeUInt32LE(48000,24);wav.writeUInt32LE(96000,28);
    wav.writeUInt16LE(2,32);wav.writeUInt16LE(16,34);wav.write('data',36);wav.writeUInt32LE(960,40);
    await page.route('http://preview.test/**',route=>{
        const pathname=new URL(route.request().url()).pathname;
        if(type==='audio'&&['/one','/two'].includes(pathname))return route.fulfill({contentType:'audio/wav',body:wav});
        return route.fulfill({contentType:'text/html',body:'<div id="app"></div>'});
    });
    await page.goto('http://preview.test/');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH||path.join(dam,'node_modules/vue/dist/vue.global.js')});
    await page.addScriptTag({path:path.join(dam,'src/Resources/assets/js/preview-navigation.js')});
    await page.addScriptTag({path:path.join(dam,'src/Resources/assets/js/details-navigation.js')});
    // The details page's record of a stacked file on show (the preview component spreads it).
    await page.addScriptTag({content:read('stack-shown-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/<\/?script>/g,'')});
    const fixture={id:1,file_name:type==='audio'?'Audio':'PDF',extension:type==='audio'?'wav':'pdf',file_type:type==='audio'?'audio':'document',mediaUrl:'/one',path:'assets/one',mime_type:'audio/wav',playbackKind:null};
    await page.evaluate(()=>{
        const noop=()=>{},methods=Object.fromEntries(['imgMounted','imgBeforeUnmount','imgResetState','videoMounted','videoBeforeUnmount','videoResetState','videoInitEl','videoStopOnClose','panoMounted','panoBeforeUnmount','panoResetState','panoramaInvalidate','panoInit','panoDestroy','schedulePanoramaPoll'].map(name=>[name,noop]));
        for(const key of ['_damImageViewer','_damVideoPlayer','_damPanoViewer'])window[key]={data:{},computed:{},methods};
        window._damPreviewFrameFit={start:()=>({stop:noop})}; // the frame sizing script has its own geometry test (dam-asset-preview-fit)
        window._damPreviewFocus={data:{},computed:{},methods:{rememberPreviewOpener:noop,focusPreview:noop,trapPreviewTab:noop,startPreviewFocusGuard:noop,stopPreviewFocusGuard:noop,restorePreviewFocus:noop}};
        window._damInfoPanel={data:{isInfoPanelOpen:false},computed:{},methods:{loadInfoPanel:noop,resetInfoPanel:noop,closePreviewWithInfoReset(){this.closePreview();},toggleInfoPanel(){this.isInfoPanelOpen=!this.isInfoPanelOpen;}}};
        window.axios={defaults:{headers:{common:{}}}};
        window.MothershipFavorites={};window.MothershipFavoriteState={selection:()=>({select:noop})};
        window.MothershipNameTail=name=>({head:name,tail:''});
        window.app={component:(name,definition)=>window.definition=definition};
        window.pdfProbe={mounts:[],destroyed:0,releases:[]};
        window.loadDamPdfViewer=()=>new Promise(resolve=>window.pdfProbe.releases.push(()=>resolve({mountPdfViewer(host,{url}){window.pdfProbe.mounts.push(url);return{destroy(){window.pdfProbe.destroyed++;}};}})));
        window.audioPlayCalls=0;
        HTMLMediaElement.prototype.play=function(){window.audioPlayCalls++;return Promise.reject(new DOMException('Blocked','NotAllowedError'));};
    });
    for(const helper of ['audio/audio-player-script.blade.php','files/pdf-viewer-script.blade.php'])await page.addScriptTag({content:read('preview-modal/'+helper).replace(/<\/?script[^>]*>/g,'')});
    const source=read(entry),script=source.slice(source.lastIndexOf('<script type="module">')+22).replace(/<\/script>[\s\S]*$/,'');
    await page.addScriptTag({content:read('preview-modal/attachments/strip-script.blade.php').match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))",'{}')});
    await page.addScriptTag({content:bladeCalls(script,fixture).replace(/\{\{[^}]+\}\}/g,'fixture')});
    const template=entry==='preview-modal.blade.php'?html(read('preview-modal/card.blade.php')+read('preview-modal/viewer-modal.blade.php')):html(read('preview-modal/viewer-modal.blade.php'));
    await page.evaluate(({template,fixture})=>{
        const definition=window.definition,original=definition.data;
        window.previewApp=Vue.createApp({...definition,template,data(){return{...original.call(this),previewData:fixture,favorite:false};},methods:{...definition.methods,toggleFavorite(){}}});
        window.previewApp.config.globalProperties.$emitter={on(){},off(){},emit(){}};
        window.player=window.previewApp.mount('#app');
    },{template,fixture});
    return page;
}
for(const entry of ['preview-modal.blade.php','grid-preview-modal.blade.php']){
    browserTest(`${entry} owns one silent-until-requested audio player, rejected playback and rapid close`,async()=>{
        const browser=await launchBrowser({playwrightPath});
        try{
            const page=await parentPage(browser,entry);
            await page.evaluate(()=>window.player.openPreview());
            assert.equal(await page.evaluate(()=>window.audioPlayCalls),0,'opening audio must not autoplay');
            await page.getByRole('button',{name:'Play audio',exact:true}).click();
            await page.waitForFunction(()=>window.player.audioError==='Playback was blocked. Press Play to try again.');
            assert.equal(await page.evaluate(()=>window.audioPlayCalls),1,'rejected playback must come from the explicit Play control');
            assert.equal(await page.locator('audio').count(),1);
            assert.equal(await page.evaluate(()=>window.player.audioIsPlaying),false);
            await page.evaluate(()=>window.player.closePreview());
            assert.equal(await page.locator('audio').count(),entry==='preview-modal.blade.php'?1:0);
            await page.evaluate(()=>{window.player.openPreview();window.player.closePreview();});
            await page.evaluate(()=>Vue.nextTick());
            assert.equal(await page.evaluate(()=>window.player.audioIsPlaying),false);
            await page.evaluate(()=>window.previewApp.unmount());
            assert.equal(await page.locator('audio').count(),0);
            assert.deepEqual(page.problems,[]);
        }finally{await browser.close();}
    });
    browserTest(`${entry} cancels lazy PDF initialization on source change and unmount`,async()=>{
        const browser=await launchBrowser({playwrightPath});
        try{
            const page=await parentPage(browser,entry,'pdf');
            if(entry==='preview-modal.blade.php')assert.equal(await page.locator('[data-dam-pdf-viewer]').count(),0);
            await page.evaluate(()=>window.player.openPreview());
            await page.waitForFunction(()=>window.pdfProbe.releases.length>0);
            await page.evaluate(()=>{window.player.previewData={...window.player.previewData,mediaUrl:'/two'};});
            await page.evaluate(()=>Vue.nextTick());
            await page.evaluate(()=>{for(const release of window.pdfProbe.releases)release();});
            await page.waitForFunction(()=>window.pdfProbe.mounts.length===1);
            assert.deepEqual(await page.evaluate(()=>window.pdfProbe.mounts),['/two']);
            assert.equal(await page.evaluate(()=>window.player.isInfoPanelOpen),false);
            await page.evaluate(()=>window.previewApp.unmount());
            assert.equal(await page.evaluate(()=>window.pdfProbe.destroyed),1);
            assert.deepEqual(page.problems,[]);
        }finally{await browser.close();}
    });
}
