const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {pathToFileURL}=require('node:url');
const {launchBrowser}=require('../launch-browser.cjs');
const root=path.resolve(__dirname,'../../..'),dam=path.join(root,'packages/Mothership/DAM');
const views=path.join(dam,'src/Resources/views/asset'),build=path.join(root,'public/themes/dam/build');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const read=file=>fs.readFileSync(path.join(views,file),'utf8');
// Both real consumers of window._damImageViewer, mounted from their own Blade template and component script:
// the edit page's detail modal and the grid preview modal. The image viewer mixin is the real one; the video,
// audio, PDF, panorama and info-panel mixins are stubbed because none of them owns raster viewing. The detail
// modal's editor test also loads the built DAM bundle, so the editor it mounts (PhotoEdit) is the shipped one.
const consumers={'preview-modal.blade.php':'v-asset-preview-modal-template','grid-preview-modal.blade.php':'v-dam-grid-preview-modal-template'};
// The old Blade editor's state: the background tool (#89) and the crop/adjust/filter/rotate tools (#91).
const retired=['bgSubTab','bgCurrentPlatformModels','scheduleBgPreview','fetchBgPreview','editTool','editApplying','editError','editPreviewFilter','editPreviewTransform','cropBox','cropHandle','cropPixelW','initCropBox','cropMouseDown','cropStartDraw','cropMouseMove','cropMouseUp','onEditToolSelect','applyEdit'];
const partials=new Set(['preview-modal.card','preview-modal.viewer-modal','preview-modal.editor-modal','preview-modal.image.image-viewer']);
const fixture={id:7,file_name:'Sample.png',display_name:'Sample.png',extension:'png',extension_upper:'PNG',file_type:'image',asset_kind:'image',mime_type:'image/png',path:'assets/sample.png',width:3200,height:2000,mediaUrl:'/image.svg',playbackUrl:'/image.svg',playbackMime:'image/svg+xml',playbackKind:'image',playbackLabel:null,previewPath:'/image.svg',typeColor:'',fileSize:'1 KB',downloadUrl:'',editUrl:'',filesUrl:'',canEditOriginal:true,canEditThumbnail:false,thumbnailEditUrl:null,customThumbnailUrl:'/assets/7/custom-thumbnail'};
// `/image.svg` is the previewed raster, larger than the viewer so actual size zooms in; `/source.svg` is the editor's base.
const images={'/image.svg':[3200,2000],'/source.svg':[600,400]};
const types={'.css':'text/css','.js':'text/javascript','.svg':'image/svg+xml','.woff':'font/woff','.woff2':'font/woff2'};
const mixinSource=read('preview-modal/image/image-viewer-script.blade.php');
// Blade the browser never sees: kept partials are inlined and the rest dropped, and a translation becomes the
// last segment of its key, so every toolbar button keeps a distinct title. `@js` of a string is single-quoted in
// Blade's real output, so it sits safely inside a double-quoted attribute like `:title="a ? @js(...) : @js(...)"`. Partials are inlined before any
// echo is rewritten, so an escaped `@{{ … }}` is unescaped exactly once.
const inline=source=>source.replace(/@include\('dam::asset\.([^']+)'(?:,[^\n]*)?\)/g,(_,partial)=>partials.has(partial)?inline(read(partial.replaceAll('.','/')+'.blade.php')):'');
function template(source,hasPreviewNavigation,editorPermitted=true){
    // The one Blade @if the real per-consumer include arg controls: keep the inner markup for
    // the consumer that passes hasPreviewNavigation=true (grid-preview-modal.blade.php), drop it
    // (Blade never ships it to that consumer's client) for the one that doesn't. Every other @if
    // in these templates (none today) is stripped by the generic pass below, same as before.
    const withPreviewNav=inline(source).replace(/@if\(\$hasPreviewNavigation \?\? false\)([\s\S]*?)@endif/g,(_,inner)=>hasPreviewNavigation?inner:'');
    // The Edit image button sits behind `@if (bouncer()->hasPermission('dam.asset.editor'))`: the harness renders the
    // permitted branch by default (the editor tests need the button) and the denied branch when asked.
    const withEditor=withPreviewNav.replace(/@if \(bouncer\(\)->hasPermission\('dam\.asset\.editor'\)\)([\s\S]*?)@endif/g,(_,inner)=>editorPermitted?inner:'');
    return withEditor.replace(/@if\b[\s\S]*?@endif/g,'').replace(/@js\(trans\('([^']+)'\)\)/g,(_,key)=>`'${key.split('.').pop()}'`).replace(/@json\(trans\('[^']+'\)\)/g,'{}').replace(/@lang\('([^']+)'\)/g,(_,key)=>key.split('.').pop())
        .replace(/(?<!@)\{\{\s*trans\('([^']+)'\)\s*\}\}/g,(_,key)=>key.split('.').pop()).replace(/(?<!@)\{\{[\s\S]*?\}\}/g,'Label').replace(/@\{\{/g,'{{');
}
function component(source){
    const body=source.slice(source.lastIndexOf('<script type="module">')+22).replace(/<\/script>[\s\S]*$/,'');
    let output='',offset=0;
    for(;;){
        const match=/@(js|json)\(/.exec(body.slice(offset));if(!match)return (output+body.slice(offset)).replace(/\{\{[\s\S]*?\}\}/g,'token');
        const start=offset+match.index;output+=body.slice(offset,start);let depth=1,quote=null,escape=false,end=start+match[0].length;
        for(;end<body.length&&depth;end++){
            const ch=body[end];if(escape){escape=false;continue;}if(ch==='\\'){escape=true;continue;}if(quote){if(ch===quote)quote=null;continue;}if(ch==='\''||ch==='"'){quote=ch;continue;}if(ch==='(')depth++;if(ch===')')depth--;
        }
        const expression=body.slice(start,end);
        output+=expression.includes('$initialPreviewData')?JSON.stringify(fixture):expression.includes("request()->has('file')")?'false':expression.includes('__ASSET__')?'"/editor/__ASSET__"':expression.includes('directoryAncestors')?'[]':expression.includes('Labels')?'{}':expression.includes('panoramaPreview')?'null':'"Label"';offset=end;
    }
}
// Reads whether THIS consumer's own `@include('dam::asset.preview-modal.viewer-modal', [...])`
// call actually passes hasPreviewNavigation=true, rather than assuming it from the entry filename —
// a future consumer wiring regression (e.g. preview-modal.blade.php opting in by accident, or
// grid-preview-modal.blade.php losing the flag) changes what this include line says and must
// change what this test exercises, not silently keep testing the old assumption.
function includesPreviewNavigation(source){
    const include=source.match(/@include\('dam::asset\.preview-modal\.viewer-modal'\s*(?:,\s*(\[[^\]]*\]))?\)/);
    assert.ok(include,'preview-modal.viewer-modal is no longer included this way; update this parser');
    return Boolean(include[1]&&/['"]hasPreviewNavigation['"]\s*=>\s*true/.test(include[1]));
}
function sources(entry,{editorPermitted=true}={}){
    const source=read(entry);
    return {markup:template(new RegExp(`id="${consumers[entry]}"\\s*>([\\s\\S]*?)</script>`).exec(source)[1],includesPreviewNavigation(source),editorPermitted),script:component(source)};
}
async function withBrowser(fn){
    const browser=await launchBrowser({playwrightPath});
    try{await fn(browser);}finally{await browser.close();}
}
async function mount(browser,entry,{editor=false}={}){
    const manifest=JSON.parse(fs.readFileSync(path.join(build,'manifest.json'),'utf8'));
    const css=manifest['src/Resources/assets/css/app.css'].file;
    const page=await browser.newPage({viewport:{width:1280,height:900}});
    // Any page error, blocking dialog, or template binding that no longer resolves fails the test.
    page.problems=[];
    page.on('pageerror',error=>page.problems.push(error.message));
    page.on('dialog',dialog=>{page.problems.push(`dialog: ${dialog.message()}`);dialog.dismiss();});
    page.on('console',message=>{if(/was accessed during render but is not defined|Unhandled error/.test(message.text()))page.problems.push(message.text());});
    await page.route('http://modal.test/**',route=>{
        const pathname=new URL(route.request().url()).pathname;
        if(pathname==='/')return route.fulfill({contentType:'text/html',body:`<link rel="stylesheet" href="/bundle/${css}"><div id="app"></div>`});
        if(images[pathname]){const [w,h]=images[pathname];return route.fulfill({contentType:'image/svg+xml',body:`<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><rect width="${w}" height="${h}" fill="#ddd6fe"/><circle cx="${w/2}" cy="${h/2}" r="${h/4}" fill="#7c3aed"/></svg>`});}
        const relative=pathname.startsWith('/bundle/')?pathname.slice(8):pathname.startsWith('/themes/dam/build/')?pathname.slice(18):null;
        const file=relative&&path.join(build,relative);
        if(!file||!file.startsWith(build+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
        return route.fulfill({contentType:types[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
    });
    await page.goto('http://modal.test/');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH||path.join(dam,'node_modules/vue/dist/vue.global.js')});
    // The details page's record of a stacked file on show (the preview component spreads it).
    await page.addScriptTag({content:read('stack-shown-script.blade.php').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/<\/?script>/g,'')});
    // grid-preview-modal.blade.php's previewNeighbours computed reads this; preview-modal.blade.php
    // (v-asset-preview-modal) has no such computed and never touches it, guarded by `?.` in the shared
    // viewer-modal.blade.php partial both consumers include.
    await page.addScriptTag({content:fs.readFileSync(path.join(dam,'src/Resources/assets/js/preview-navigation.js'),'utf8')});
    if(editor){
        await page.addScriptTag({type:'module',url:`http://modal.test/bundle/${manifest['src/Resources/assets/js/app.js'].file}`});
        await page.waitForFunction(()=>window.loadDamImageEditor);
    }
    const {createDocument}=await import(pathToFileURL(path.join(dam,'src/Resources/assets/js/image/document.mjs')).href);
    await page.evaluate(editorDocument=>{
        const noop=()=>{},stub=names=>({data:{},computed:{},methods:Object.fromEntries(names.map(name=>[name,noop]))});
        window._damVideoPlayer=stub(['videoMounted','videoBeforeUnmount','videoResetState','videoInitEl','videoStopOnClose','videoShowControls']);
        window._damAudioPlayer=stub(['audioResetState','audioInitEl','audioStopOnClose']);
        window._damPdfViewer=stub(['pdfInit','pdfDestroy']);
        window._damPanoViewer=stub(['panoMounted','panoBeforeUnmount','panoResetState','panoramaInvalidate','panoInit','panoDestroy','schedulePanoramaPoll']);
        window._damPreviewFocus={data:{},computed:{},methods:{rememberPreviewOpener:noop,focusPreview:noop,trapPreviewTab:noop,startPreviewFocusGuard:noop,stopPreviewFocusGuard:noop,restorePreviewFocus:noop}};
        window._damInfoPanel={data:{isInfoPanelOpen:false},computed:{},methods:{loadInfoPanel:noop,resetInfoPanel:noop,closePreviewWithInfoReset(){this.closePreview();},toggleInfoPanel(){this.isInfoPanelOpen=!this.isInfoPanelOpen;}}};
        window.MothershipFavorites={};window.MothershipFavoriteState={selection:()=>({select:noop})};
        window.app={component:(name,definition)=>{window.definition=definition;}};
        window.editorRequests=[];
        window.axios={defaults:{headers:{common:{}}},get:async url=>{window.editorRequests.push(url);return {data:{document:editorDocument,drafts:[],revision:'revision',original_base:'source',resources:{source:'/source.svg'}}};},post:async()=>({data:{}}),delete:async()=>({data:{}})};
        // Every window mousemove/mouseup listener change, in order, with its listener.
        window.pointerListeners=[];
        for(const op of ['addEventListener','removeEventListener']){
            const original=window[op];
            window[op]=function(type,listener,options){if(type==='mousemove'||type==='mouseup')window.pointerListeners.push({op,type,listener});return original.call(this,type,listener,options);};
        }
    },createDocument({base:'source',width:600,height:400}));
    const {markup,script}=sources(entry);
    // The real frame sizing helper, taken from the same <script> the page includes (preview-modal.blade.php @includes it).
    await page.addScriptTag({content:read('preview-modal/frame-fit-script.blade.php').match(/<script>([\s\S]*?)<\/script>/)[1]});
    await page.addScriptTag({content:mixinSource.replace(/<\/?script[^>]*>/g,'').replace(/\{\{[\s\S]*?\}\}/g,'Label')});
    // The real shared stack-switch mixin both previews spread in.
    await page.addScriptTag({content:read('preview-modal/attachments/strip-script.blade.php').match(/<script>([\s\S]*?)<\/script>/)[1].replace("@json(trans('dam::app.admin.dam.asset.files.roles'))",'{}')});
    await page.addScriptTag({content:script});
    await page.evaluate(({markup,fixture})=>{
        const definition=window.definition,data=definition.data;
        window.modalApp=Vue.createApp({...definition,template:markup,data(){return {...data.call(this),previewData:{...fixture},favorite:false};},methods:{toggleFavorite(){},...definition.methods}});
        window.modalApp.config.globalProperties.$emitter={on(){},off(){},emit(){}};
        window.modal=window.modalApp.mount('#app');
    },{markup,fixture});
    assert.deepEqual(page.problems,[],'the production consumer must mount with all required viewer dependencies');
    return page;
}
// `+type:method` for an added window listener, `-type:method` for a removed one, naming the component method.
const pointerChanges=page=>page.evaluate(()=>{
    const modal=window.modal,name=listener=>Object.keys(modal.$options.methods).find(key=>modal[key]===listener)||'anonymous';
    return window.pointerListeners.splice(0).map(({op,type,listener})=>`${op==='addEventListener'?'+':'-'}${type}:${name(listener)}`);
});
// Pans by a synthetic window drag from (0,0) to (60,30); only a live imgOnMouseMove listener moves the image.
const windowDrag=page=>page.evaluate(()=>{
    const modal=window.modal;Object.assign(modal,{imgIsDragging:true,imgDragStartX:0,imgDragStartY:0,imgPanStartX:0,imgPanStartY:0,imgPanX:0,imgPanY:0});
    window.dispatchEvent(new MouseEvent('mousemove',{clientX:60,clientY:30}));window.dispatchEvent(new MouseEvent('mouseup'));
    return {pan:[modal.imgPanX,modal.imgPanY],dragging:modal.imgIsDragging};
});
// Wraps the real bundle's loader, counted, and holdable so a close or unmount can land before the lazy import resolves;
// each held load waits in editorProbe.pending until the test resolves or rejects it, in any order.
const probeEditor=page=>page.evaluate(()=>{
    const load=window.loadDamImageEditor,probe=window.editorProbe={loads:0,mounts:[],unmounts:0,hold:false,pending:[]};
    window.loadDamImageEditor=async()=>{
        probe.loads++;const module=await load();
        if(probe.hold)await new Promise((resolve,reject)=>{probe.pending.push({resolve,reject});});
        return {...module,mountEditor(host,props){
            probe.mounts.push({intoHost:host===window.modal.$refs.imageEditorHost,assetId:props.assetId,fileName:props.fileName,endpoint:props.endpoint});
            const unmount=module.mountEditor(host,props);return ()=>{probe.unmounts++;unmount();};
        }};
    };
});
const editorCounts=page=>page.evaluate(()=>{const probe=window.editorProbe;return {loads:probe.loads,mounts:probe.mounts.length,unmounts:probe.unmounts};});
for(const {type,title,property} of [{type:'pixelate',title:'Intensity',property:'intensity'},{type:'spotlight',title:'Dim amount',property:'opacity'}]){
    for(const matchingSelection of [false,true]){
        browserTest(`keeps PhotoEdit contextual ${type} slider connected during a real drag (${matchingSelection?'matching selection':'tool defaults'})`,{timeout:120000},()=>withBrowser(async browser=>{
            const page=await mount(browser,'preview-modal.blade.php',{editor:true});
            await page.locator('button[aria-label="edit-image"]').click();
            await page.locator('.pe-size-label').filter({hasText:'600 × 400'}).waitFor();
            await page.waitForFunction(()=>document.querySelector('.pe-stage canvas')?.getBoundingClientRect().width>300);
            await page.evaluate(type=>document.querySelector('.pe-root').__photoedit.setTool(type),type);
            const draw=async()=>{
                const points=await page.evaluate(()=>{const api=document.querySelector('.pe-root').__photoedit;return [api.sourceToClient(100,100),api.sourceToClient(260,200)];});
                await page.mouse.move(points[0].x,points[0].y);await page.mouse.down();await page.mouse.move(points[1].x,points[1].y,{steps:5});await page.mouse.up();
            };
            if(matchingSelection)await draw();
            await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.markSaved());
            assert.equal(await page.locator('.pe-top [data-action].pe-primary').count(),0);
            const input=page.locator(`.pe-top input[type="range"][title="${title}"]`);
            await input.waitFor();
            const before=await page.evaluate(({title,property})=>{
                const node=document.querySelector(`.pe-top input[type="range"][title="${title}"]`),api=document.querySelector('.pe-root').__photoedit;
                const rect=selector=>{const r=document.querySelector(selector).getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};};
                window.sliderProbe={node,events:[],changes:0,document:api.getDocument(),panel:rect('.pe-root'),stage:rect('.pe-stage'),canvas:rect('.pe-stage canvas')};
                node.addEventListener('input',()=>window.sliderProbe.events.push({value:Number(node.value),connected:node.isConnected}));
                node.addEventListener('change',()=>window.sliderProbe.changes++);
                return {value:Number(node.value),min:Number(node.min),max:Number(node.max),property:api.getDocument().objects.at(-1)?.[property]};
            },{title,property});
            const box=await input.boundingBox(),left=box.x+8,width=box.width-16,y=box.y+box.height/2;
            const start=left+width*(before.value-before.min)/(before.max-before.min),target=left+width*.87;
            await page.mouse.move(start,y);await page.mouse.down();
            await page.mouse.move(target,y,{steps:12});await page.mouse.up();
            const result=await page.evaluate(({title,property})=>{
                const probe=window.sliderProbe,api=document.querySelector('.pe-root').__photoedit;
                const rect=selector=>{const r=document.querySelector(selector).getBoundingClientRect();return {x:r.x,y:r.y,width:r.width,height:r.height};};
                return {events:probe.events,changes:probe.changes,connected:probe.node.isConnected,value:Number(document.querySelector(`.pe-top input[type="range"][title="${title}"]`).value),
                    panel:rect('.pe-root'),stage:rect('.pe-stage'),canvas:rect('.pe-stage canvas'),beforePanel:probe.panel,beforeStage:probe.stage,beforeCanvas:probe.canvas,
                    document:api.getDocument(),beforeDocument:probe.document,property:api.getDocument().objects.at(-1)?.[property]};
            },{title,property});
            console.log(JSON.stringify({event:'photoedit.slider.drag',type,matchingSelection,inputs:result.events.length,changes:result.changes,connectedDuringInput:result.events.every(event=>event.connected),connectedAfterChange:result.connected,
                panelMoved:JSON.stringify(result.panel)!==JSON.stringify(result.beforePanel),stageMoved:JSON.stringify(result.stage)!==JSON.stringify(result.beforeStage),canvasMoved:JSON.stringify(result.canvas)!==JSON.stringify(result.beforeCanvas),value:result.value}));
            assert.ok(result.events.length>=3,'native range must continue receiving input through the drag');
            assert.ok(result.events.every(event=>event.connected),'input handler removed the native slider during drag');
            assert.equal(result.changes,1,'native change must complete the gesture once');
            assert.equal(await page.locator('.pe-top [data-action].pe-primary').count(),matchingSelection?1:0,'completed gesture must refresh saved/dirty action state');
            assert.deepEqual(result.panel,result.beforePanel);assert.deepEqual(result.stage,result.beforeStage);assert.deepEqual(result.canvas,result.beforeCanvas);
            assert.ok(Math.abs(result.value-(before.min+(before.max-before.min)*.87))<=3,'drag must reach its final range position');
            if(matchingSelection){
                assert.equal(result.property,result.value);
                await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.undo());
                assert.equal(await page.evaluate(property=>document.querySelector('.pe-root').__photoedit.getDocument().objects.at(-1)[property],property),before.property);
                await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.redo());
                assert.equal(await page.evaluate(property=>document.querySelector('.pe-root').__photoedit.getDocument().objects.at(-1)[property],property),result.value);
                const position={x:result.document.objects.at(-1).x,y:result.document.objects.at(-1).y};
                await input.focus();await page.keyboard.press('ArrowRight');await page.keyboard.press('ArrowRight');
                assert.equal(Number(await input.inputValue()),result.value+2,'consecutive keyboard range steps must advance');
                const keyboardObject=await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.getDocument().objects.at(-1));
                assert.equal(keyboardObject[property],result.value+2,'keyboard range steps must edit the selection');
                assert.deepEqual({x:keyboardObject.x,y:keyboardObject.y},position,'range arrows must not nudge the annotation');
                assert.equal(await input.evaluate(node=>node===document.activeElement),true,'range must retain keyboard focus');
            }else{
                assert.deepEqual(result.document,result.beforeDocument,'tool preferences must not edit the document');
                await input.focus();await page.keyboard.press('ArrowRight');await page.keyboard.press('ArrowRight');
                assert.equal(Number(await input.inputValue()),result.value+2,'consecutive keyboard default steps must advance');
                assert.equal(await input.evaluate(node=>node===document.activeElement),true,'default range must retain keyboard focus');
                assert.deepEqual(await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.getDocument()),result.beforeDocument,'keyboard tool preferences must not edit the document');
                await draw();
                assert.equal(await page.evaluate(property=>document.querySelector('.pe-root').__photoedit.getDocument().objects.at(-1)[property],property),result.value+2);
            }
            // Intentional canvas panning still works, without changing annotations.
            await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.zoomTo(2));
            const panBefore=await page.evaluate(()=>{const api=document.querySelector('.pe-root').__photoedit;return {point:api.sourceToClient(300,200),document:api.getDocument()};});
            await page.mouse.move(panBefore.point.x,panBefore.point.y);await page.mouse.down({button:'middle'});
            await page.mouse.move(panBefore.point.x+35,panBefore.point.y+20,{steps:5});await page.mouse.up({button:'middle'});
            const panAfter=await page.evaluate(()=>{const api=document.querySelector('.pe-root').__photoedit;return {point:api.sourceToClient(300,200),document:api.getDocument()};});
            assert.notDeepEqual(panAfter.point,panBefore.point,'intentional canvas pan must remain available');
            assert.deepEqual(panAfter.document,panBefore.document,'panning must not edit annotations');
            if(matchingSelection){
                await page.locator('.pe-root').focus();await page.keyboard.press('ArrowRight');
                const nudged=await page.evaluate(()=>document.querySelector('.pe-root').__photoedit.getDocument().objects.at(-1));
                assert.equal(nudged.x,panBefore.document.objects.at(-1).x+.5,'canvas keyboard nudge must remain one screen pixel at 2× zoom');
                assert.equal(nudged.y,panBefore.document.objects.at(-1).y);
            }
            assert.deepEqual(page.problems,[]);
        }));
    }
}
for(const entry of Object.keys(consumers)){
    browserTest(`${entry} carries no retired editor state`,()=>withBrowser(async browser=>{
        const {markup,script}=sources(entry);
        for(const name of retired)for(const [label,text] of Object.entries({mixin:mixinSource,template:markup,component:script}))assert.doesNotMatch(text,new RegExp(`\\b${name}\\b`),`${label} still mentions ${name}`);
        assert.doesNotMatch(markup+mixinSource,/edit-bg/);
        const page=await mount(browser,entry);
        // The mixin is only the raster viewer now, and nothing retired is reachable on the mounted consumer.
        assert.deepEqual(await page.evaluate(()=>Object.values(window._damImageViewer).flatMap(Object.keys).filter(key=>!key.startsWith('img'))),[]);
        assert.deepEqual(await page.evaluate(names=>names.filter(name=>name in window.modal),retired),[]);
        await page.evaluate(()=>window.modal.openPreview());
        await page.locator('[data-dam-preview-backdrop] img').waitFor();
        assert.doesNotMatch(await page.locator('#app').evaluate(el=>el.outerHTML),/edit-bg|bgSubTab|cropBox|editTool/);
        assert.deepEqual(page.problems,[]);
    }));
    browserTest(`${entry} adds and removes exactly the image viewer's window pointer listeners`,()=>withBrowser(async browser=>{
        const page=await mount(browser,entry);
        // imgMounted adds exactly the viewer's two window listeners and imgBeforeUnmount removes the same two.
        assert.deepEqual(await pointerChanges(page),['+mousemove:imgOnMouseMove','+mouseup:imgOnMouseUp']);
        assert.deepEqual(await windowDrag(page),{pan:[60,30],dragging:false});
        await page.evaluate(()=>window.modalApp.unmount());
        assert.deepEqual(await pointerChanges(page),['-mousemove:imgOnMouseMove','-mouseup:imgOnMouseUp']);
        assert.deepEqual(await windowDrag(page),{pan:[0,0],dragging:true});
        assert.deepEqual(page.problems,[]);
    }));
    browserTest(`${entry} zooms within bounds, rotates, fits, shows actual size, resets, wheels and drags a raster`,()=>withBrowser(async browser=>{
        const page=await mount(browser,entry);
        await page.evaluate(()=>window.modal.openPreview());
        const viewer=page.locator('[data-dam-preview-backdrop]'),img=viewer.locator('img');
        await page.waitForFunction(()=>window.modal.$refs.previewImg?.complete&&window.modal.$refs.previewImg.naturalWidth===3200);
        const view=()=>page.evaluate(()=>{const modal=window.modal,el=modal.$refs.previewImg;return {zoom:modal.imgZoom,rotation:modal.imgRotation,pan:[modal.imgPanX,modal.imgPanY],label:el.parentElement.querySelector('.font-mono').textContent.trim(),transform:el.style.transform};});
        const click=title=>viewer.locator(`button[title="${title}"]`).click();
        const set=state=>page.evaluate(state=>{Object.assign(window.modal,state);},state);
        const center=async()=>{const box=await img.boundingBox();return [Math.round(box.x+box.width/2),Math.round(box.y+box.height/2)];};
        assert.deepEqual(await view(),{zoom:1,rotation:0,pan:[0,0],label:'100%',transform:'translate(0px, 0px) scale(1) rotate(0deg)'});
        // Zoom buttons step by 25% and clamp to 10%–1000%.
        await click('zoom-in');
        assert.deepEqual(await view(),{zoom:1.25,rotation:0,pan:[0,0],label:'125%',transform:'translate(0px, 0px) scale(1.25) rotate(0deg)'});
        await set({imgZoom:9.9});await click('zoom-in');await click('zoom-in');
        assert.equal((await view()).label,'1000%');
        await set({imgZoom:0.2});await click('zoom-out');await click('zoom-out');
        assert.equal((await view()).label,'10%');
        // Rotation turns in 90° steps and wraps below zero.
        await set({imgZoom:1});await click('rotate-right');
        assert.equal((await view()).rotation,90);
        await click('rotate-left');await click('rotate-left');
        assert.deepEqual(await view(),{zoom:1,rotation:270,pan:[0,0],label:'100%',transform:'translate(0px, 0px) scale(1) rotate(270deg)'});
        // The wheel zooms by 10% per notch around the pointer and clamps to the same bounds.
        const [x,y]=await center();await page.mouse.move(x,y);
        await page.mouse.wheel(0,-100);await page.waitForFunction(()=>window.modal.imgZoom===1.1);
        await page.mouse.wheel(0,100);await page.waitForFunction(()=>window.modal.imgZoom===0.99);
        await set({imgZoom:9.5});await page.mouse.wheel(0,-100);await page.waitForFunction(()=>window.modal.imgZoom===10);
        await set({imgZoom:0.105});await page.mouse.wheel(0,100);await page.waitForFunction(()=>window.modal.imgZoom===0.1);
        // A left-button drag pans by the pointer delta through the window listeners; other buttons never drag.
        await set({imgZoom:2});
        const [dx,dy]=await center();await page.mouse.move(dx,dy);
        await page.mouse.down({button:'right'});
        assert.equal(await page.evaluate(()=>window.modal.imgIsDragging),false);
        await page.mouse.up({button:'right'});
        await page.mouse.down();
        assert.deepEqual(await page.evaluate(()=>[window.modal.imgIsDragging,window.modal.$refs.previewImg.parentElement.classList.contains('cursor-grabbing')]),[true,true]);
        await page.mouse.move(dx+40,dy+25,{steps:4});await page.mouse.up();
        assert.deepEqual(await view(),{zoom:2,rotation:270,pan:[40,25],label:'200%',transform:'translate(40px, 25px) scale(2) rotate(270deg)'});
        assert.equal(await page.evaluate(()=>window.modal.imgIsDragging),false);
        // Fit recentres at 100% and keeps the rotation.
        await click('fit-to-screen');
        assert.deepEqual(await view(),{zoom:1,rotation:270,pan:[0,0],label:'100%',transform:'translate(0px, 0px) scale(1) rotate(270deg)'});
        // Actual size zooms to one image pixel per screen pixel and recentres.
        const actual=await page.evaluate(()=>{const el=window.modal.$refs.previewImg;return Number(Math.max(0.1,Math.min(10,el.naturalWidth/el.offsetWidth)).toFixed(3));});
        assert.ok(actual>1,`actual-size zoom ${actual}`);
        await set({imgPanX:15,imgPanY:-5});await click('actual-size');
        assert.deepEqual((({zoom,pan})=>({zoom,pan}))(await view()),{zoom:actual,pan:[0,0]});
        await click('reset-all');
        assert.deepEqual(await view(),{zoom:1,rotation:0,pan:[0,0],label:'100%',transform:'translate(0px, 0px) scale(1) rotate(0deg)'});
        assert.deepEqual(page.problems,[]);
    }));
}
// Library-result controls belong only to the grid consumer. Shared file controls have their own scope;
// a result boundary keeps the labeled Library button visible and disabled rather than removing it.
browserTest('viewer-modal Library controls belong to the grid consumer and remain visible but disabled at boundaries',()=>withBrowser(async browser=>{
    for(const entry of Object.keys(consumers)){
        const page=await mount(browser,entry);
        await page.evaluate(()=>window.modal.openPreview());
        await page.locator('[data-dam-preview-backdrop] img').waitFor();
        // Neither consumer starts with a known neighbour (no siblings passed, no server ids on the fixture).
        const navigable=includesPreviewNavigation(read(entry));
        assert.equal(await page.locator('[data-dam-preview-prev]').count(),navigable?1:0,`${entry}: Library controls must follow the consumer contract`);
        assert.equal(await page.locator('[data-dam-preview-next]').count(),navigable?1:0,`${entry}: Library controls must follow the consumer contract`);
        if(navigable){
            assert.equal(await page.getByRole('button',{name:'Previous Library result',exact:true}).isDisabled(),true);
            assert.equal(await page.getByRole('button',{name:'Next Library result',exact:true}).isDisabled(),true);
        }
        assert.equal(await page.locator('[data-dam-preview-position]').count(),0,`${entry}: position badge rendered with no known neighbour`);
        assert.deepEqual(page.problems,[]);
        if(includesPreviewNavigation(read(entry))){
            // Spy on the real methods before any state change triggers the render that creates the
            // buttons: Vue's compiled `@click="navigatePrev"` binds whatever `_ctx.navigatePrev` was
            // at that render, so swapping it in afterwards would miss the already-mounted listener.
            await page.evaluate(()=>{window.__navCalls=[];window.modal.navigatePrev=()=>window.__navCalls.push('prev');window.modal.navigateNext=()=>window.__navCalls.push('next');});
            // Only this consumer can ever show the controls: setting the server-computed neighbour ids
            // (the fallback path, no siblings) makes both buttons and the position-less state appear.
            await page.evaluate(()=>{window.modal.previewData.previousAssetId=5;window.modal.previewData.nextAssetId=9;});
            await page.waitForFunction(()=>document.querySelector('[data-dam-preview-prev]')&&document.querySelector('[data-dam-preview-next]'));
            // This harness's translation stub collapses trans() to the key's last segment (see `template`).
            assert.equal(await page.getByRole('button',{name:'Previous Library result',exact:true}).isEnabled(),true);
            assert.equal(await page.getByRole('button',{name:'Next Library result',exact:true}).isEnabled(),true);
            // No siblings were passed, so there is no position to report even though both ends are known.
            assert.equal(await page.locator('[data-dam-preview-position]').count(),0);
            await page.locator('[data-dam-preview-prev]').click();
            await page.locator('[data-dam-preview-next]').click();
            assert.deepEqual(await page.evaluate(()=>window.__navCalls),['prev','next']);
            // An ordered visible list (siblings) reports a position; the middle item shows both buttons.
            await page.evaluate(()=>{window.modal.previewSiblings=[3,7,11];window.modal.previewData.id=7;});
            await page.waitForFunction(()=>document.querySelector('[data-dam-preview-position]')?.textContent.trim()==='2 of 3');
            // At the first item, Previous stays discoverable but disabled; Next remains usable (no wrap).
            await page.evaluate(()=>{window.modal.previewData.id=3;});
            await page.waitForFunction(()=>document.querySelector('[data-dam-preview-prev]')?.disabled&&!document.querySelector('[data-dam-preview-next]')?.disabled);
            assert.deepEqual(page.problems,[]);
        }
    }
}));
test('the Edit image button renders only when the user holds dam.asset.editor',()=>{
    // Only preview-modal.blade.php includes the card that holds the button.
    const entry='preview-modal.blade.php';
    assert.match(sources(entry).markup,/aria-label="edit-image"/,`${entry} must render Edit image when permitted`);
    assert.doesNotMatch(sources(entry,{editorPermitted:false}).markup,/edit-image/,`${entry} must not render Edit image when denied`);
});
browserTest('preview-modal.blade.php mounts the image editor on isEditOpen and unmounts it when the editor closes',{timeout:120000},()=>withBrowser(async browser=>{
    const page=await mount(browser,'preview-modal.blade.php',{editor:true});
    await probeEditor(page);
    const heading=page.getByRole('dialog',{name:'Image editor',exact:true}),edit=page.locator('button[aria-label="edit-image"]');
    const state=()=>page.evaluate(()=>{const modal=window.modal,probe=window.editorProbe;return {loads:probe.loads,mounts:probe.mounts.length,unmounts:probe.unmounts,open:modal.isEditOpen,host:Boolean(modal.$refs.imageEditorHost),handle:typeof modal._unmountImageEditor==='function'};});
    assert.equal(await heading.count(),0);
    await edit.click();
    await heading.waitFor();
    await page.locator('.pe-size-label').filter({hasText:'600 × 400'}).waitFor();
    assert.deepEqual(await page.evaluate(()=>window.editorProbe.mounts),[{intoHost:true,assetId:7,fileName:'Sample.png',endpoint:'/editor/__ASSET__'}]);
    // The editor is mounted for imageEditorHost and puts its one dialog in a viewport overlay on <body>.
    assert.equal(await page.locator('[role="dialog"][aria-label="Image editor"]').count(),1);
    assert.ok((await page.evaluate(()=>window.editorRequests)).some(url=>url.startsWith('/editor/7')));
    assert.deepEqual(await state(),{loads:1,mounts:1,unmounts:0,open:true,host:true,handle:true});
    // The editor's own close runs onClose, which clears isEditOpen and unmounts the editor.
    await heading.getByRole('button',{name:'Close',exact:true}).click();
    await heading.waitFor({state:'detached'});
    assert.deepEqual(await state(),{loads:1,mounts:1,unmounts:1,open:false,host:false,handle:false});
    // The parent clearing isEditOpen (asset navigation does) unmounts it the same way.
    await edit.click();
    await heading.waitFor();
    await page.evaluate(()=>{window.modal.isEditOpen=false;});
    await heading.waitFor({state:'detached'});
    assert.deepEqual(await state(),{loads:2,mounts:2,unmounts:2,open:false,host:false,handle:false});
    // A close that lands before the bundle resolves leaves no stray editor behind.
    await page.evaluate(()=>{window.editorProbe.hold=true;});
    await edit.click();
    await page.waitForFunction(()=>window.editorProbe.pending.length===1);
    await page.evaluate(async()=>{window.modal.isEditOpen=false;await Vue.nextTick();window.editorProbe.pending[0].resolve();await new Promise(resolve=>setTimeout(resolve,100));});
    assert.equal(await heading.count(),0);
    assert.deepEqual(await state(),{loads:3,mounts:2,unmounts:2,open:false,host:false,handle:false});
    assert.deepEqual(page.problems,[]);
}));
// The editor's overlay lives on <body> outside the modal, so it outlives the modal unless the modal unmounts it; admin
// SPA navigation (window.app.unmount()) tears the modal down while the editor can be open or still loading.
browserTest('preview-modal.blade.php unmounts an open image editor when the modal itself unmounts',{timeout:120000},()=>withBrowser(async browser=>{
    const page=await mount(browser,'preview-modal.blade.php',{editor:true});
    await probeEditor(page);
    const dialog=page.getByRole('dialog',{name:'Image editor',exact:true});
    await page.locator('button[aria-label="edit-image"]').click();
    await dialog.waitFor();
    await page.locator('.pe-size-label').filter({hasText:'600 × 400'}).waitFor();
    assert.deepEqual(await editorCounts(page),{loads:1,mounts:1,unmounts:0});
    await page.evaluate(()=>window.modalApp.unmount());
    assert.equal(await dialog.count(),0);
    assert.deepEqual(await editorCounts(page),{loads:1,mounts:1,unmounts:1});
    assert.deepEqual(page.problems,[]);
}));
browserTest('preview-modal.blade.php mounts no image editor when the bundle resolves after the modal unmounts',{timeout:120000},()=>withBrowser(async browser=>{
    const page=await mount(browser,'preview-modal.blade.php',{editor:true});
    await probeEditor(page);
    await page.evaluate(()=>{window.editorProbe.hold=true;});
    await page.locator('button[aria-label="edit-image"]').click();
    await page.waitForFunction(()=>window.editorProbe.pending.length===1);
    await page.evaluate(async()=>{window.modalApp.unmount();window.editorProbe.pending[0].resolve();await new Promise(resolve=>setTimeout(resolve,100));});
    assert.equal(await page.getByRole('dialog',{name:'Image editor',exact:true}).count(),0);
    assert.deepEqual(await editorCounts(page),{loads:1,mounts:0,unmounts:0});
    assert.deepEqual(page.problems,[]);
}));
browserTest('preview-modal.blade.php raises no editor error when the bundle fails after the modal unmounts',{timeout:120000},()=>withBrowser(async browser=>{
    const page=await mount(browser,'preview-modal.blade.php',{editor:true});
    await probeEditor(page);
    await page.evaluate(()=>{window.editorProbe.hold=true;});
    await page.locator('button[aria-label="edit-image"]').click();
    await page.waitForFunction(()=>window.editorProbe.pending.length===1);
    await page.evaluate(async()=>{window.modalApp.unmount();window.editorProbe.pending[0].reject(new Error('bundle failed'));await new Promise(resolve=>setTimeout(resolve,100));});
    assert.deepEqual(await editorCounts(page),{loads:1,mounts:0,unmounts:0});
    // The load-failure alert would surface here as a dialog problem.
    assert.deepEqual(page.problems,[]);
}));
// Asset navigation closes the editor and Edit reopens it, so a slow first load can be superseded by a newer one.
browserTest('preview-modal.blade.php lets only the latest editor load mount or fail',{timeout:120000},()=>withBrowser(async browser=>{
    const page=await mount(browser,'preview-modal.blade.php',{editor:true});
    await probeEditor(page);
    const heading=page.getByRole('dialog',{name:'Image editor',exact:true}),edit=page.locator('button[aria-label="edit-image"]'),dialog=page.getByRole('dialog',{name:'Image editor',exact:true});
    const pending=count=>page.waitForFunction(count=>window.editorProbe.pending.length===count,count);
    const settle=(index,outcome)=>page.evaluate(async({index,outcome})=>{const load=window.editorProbe.pending[index];if(outcome==='resolve')load.resolve();else load.reject(new Error('stale load failed'));await new Promise(resolve=>setTimeout(resolve,100));},{index,outcome});
    const reopen=async count=>{await page.evaluate(async()=>{window.modal.isEditOpen=false;await Vue.nextTick();});await edit.click();await pending(count);};
    await page.evaluate(()=>{window.editorProbe.hold=true;});
    // Load 0 resolves after a close and reopen started load 1: only load 1 mounts, so no editor is orphaned.
    await edit.click();await pending(1);await reopen(2);
    await settle(0,'resolve');
    assert.deepEqual(await editorCounts(page),{loads:2,mounts:0,unmounts:0});
    await settle(1,'resolve');await heading.waitFor();
    assert.equal(await dialog.count(),1);
    assert.deepEqual(await editorCounts(page),{loads:2,mounts:1,unmounts:0});
    // Load 2 fails after load 3 has mounted: the newer editor stays open and nothing alerts.
    await reopen(3);await reopen(4);
    await settle(3,'resolve');await heading.waitFor();
    await settle(2,'reject');
    assert.deepEqual([await page.evaluate(()=>window.modal.isEditOpen),await dialog.count()],[true,1]);
    assert.deepEqual(await editorCounts(page),{loads:4,mounts:2,unmounts:1});
    await page.evaluate(()=>{window.modal.isEditOpen=false;});
    await heading.waitFor({state:'detached'});
    assert.deepEqual(await editorCounts(page),{loads:4,mounts:2,unmounts:2});
    assert.equal(await dialog.count(),0);
    assert.deepEqual(page.problems,[]);
    // Load 5 is the latest and fails: it closes the editor and alerts once; the superseded load 4 stays inert.
    await edit.click();await pending(5);await reopen(6);
    await settle(5,'reject');
    assert.equal(await page.evaluate(()=>window.modal.isEditOpen),false);
    await settle(4,'resolve');
    assert.deepEqual(await editorCounts(page),{loads:6,mounts:2,unmounts:2});
    assert.equal(await dialog.count(),0);
    assert.deepEqual(page.problems,['dialog: Label']);
}));

// The Details page offers one edit entry per asset: the original for PNG/JPEG/WebP, the thumbnail for a non-photo asset with a
// real one, nothing otherwise (GIF, SVG, a placeholder). The thumbnail edit never mounts the original-image editor.
browserTest('preview-modal.blade.php offers Edit image, Edit thumbnail or nothing, and the thumbnail edit mounts the thumbnail editor',{timeout:120000},()=>withBrowser(async browser=>{
    const page=await mount(browser,'preview-modal.blade.php',{editor:true});
    await probeEditor(page);
    await page.evaluate(()=>{
        const load=window.loadDamImageEditor;window.thumbMounts=[];window.thumbUnmounts=0;
        window.loadDamImageEditor=async()=>{const module=await load();return {...module,mountEditor(host,props){window.originalMounts=(window.originalMounts||0)+1;return module.mountEditor(host,props);},mountThumbnailEditor(host,props){window.thumbMounts.push({assetId:props.assetId,thumbnailUrl:props.thumbnailUrl,endpoint:props.endpoint});const unmount=module.mountThumbnailEditor(host,props);return ()=>{window.thumbUnmounts++;unmount();};}};};
        window.axios.get=async url=>url==='/thumb?v=1'?{data:new Blob([],{type:'image/svg+xml'})}:{data:{}};
    });
    const edit=page.locator('button[aria-label="edit-image"]'),thumb=page.locator('[data-dam-edit-thumbnail]');
    const offers=()=>Promise.all([edit.count(),thumb.count()]);
    assert.deepEqual(await offers(),[1,0]);
    await page.evaluate(()=>Object.assign(window.modal.previewData,{canEditOriginal:false,canEditThumbnail:false,thumbnailEditUrl:null}));
    await page.waitForFunction(()=>!document.querySelector('button[aria-label="edit-image"]'));
    assert.deepEqual(await offers(),[0,0]);
    await page.evaluate(()=>Object.assign(window.modal.previewData,{canEditThumbnail:true,thumbnailEditUrl:'/thumb?v=1'}));
    await thumb.waitFor();
    assert.deepEqual(await offers(),[0,1]);
    await thumb.click();
    await page.getByRole('dialog',{name:'Edit thumbnail',exact:true}).waitFor();
    assert.deepEqual(await page.evaluate(()=>({thumb:window.thumbMounts,original:window.originalMounts||0})),{thumb:[{assetId:7,thumbnailUrl:'/thumb?v=1',endpoint:'/assets/7/custom-thumbnail'}],original:0});
    assert.equal(await page.evaluate(()=>window.editorRequests.some(url=>url.startsWith('/editor/'))),false);
    await page.evaluate(()=>{window.modal.isEditOpen=false;});
    await page.getByRole('dialog',{name:'Edit thumbnail',exact:true}).waitFor({state:'detached'});
    assert.equal(await page.evaluate(()=>window.thumbUnmounts),1);
    assert.deepEqual(page.problems,[]);
}));
