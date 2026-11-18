const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');
const base='packages/Mothership/DAM/src/';
const nav=require(path.join(root,base+'Resources/assets/js/library-navigation.js'));
// Explorer state is per account (F20): these fixtures act as admin 5.
nav.userId=5;
const userKey=key=>nav.explorerKey(key,5);

function blade(source){
    let result=source.replace(/@js\(/g,'@json(').replace(/@if \(!config\('dam.explorer.enabled'\)\)[\s\S]*?@endif/g,'');
    let pos;
    while((pos=result.indexOf('@json('))!==-1){
        let depth=1,index=pos+6,quote=null;
        for(;index<result.length&&depth;index++){
            const c=result[index];
            if(quote){if(c==='\\')index++;else if(c===quote)quote=null;}
            else if(c==='"'||c==="'")quote=c;else if(c==='(')depth++;else if(c===')')depth--;
        }
        result=result.slice(0,pos)+'null'+result.slice(index);
    }
    return result.replace(/@\{\{/g,'{{').replace(/\{\{\s*(?:route|config)[\s\S]*?\}\}/g,match=>'/'+(match.match(/(?:route|config)\(['"]([^'"]+)/)?.[1]||'fixture')+'/:id')
        .replace(/@lang\('[^']*'\)/g,'Translation').replace(/^\s*@(if|elseif|else|endif|php|endphp).*$/gm,'');
}
function modules(file){return [...source(file).matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map(match=>blade(match[1]));}
function component(file,name,storage={}){
    const components={};
    const localStorage={getItem:key=>storage[key]??null,setItem:(key,value)=>storage[key]=String(value),removeItem:key=>delete storage[key]};
    // The clipboard lives in sessionStorage (one browser tab); tests seed it through the returned `session` map.
    const session={};
    const sessionStorage={getItem:key=>session[key]??null,setItem:(key,value)=>session[key]=String(value),removeItem:key=>delete session[key]};
    const context={app:{component:(name,value)=>components[name]=value},historyRetirements:[],window:{MothershipLibraryNavigation:nav,dispatchEvent:event=>context.historyRetirements.push(event.type)},CustomEvent:Event,localStorage,sessionStorage,URL,URLSearchParams,location:{search:'',href:'http://mothership.test/admin/dam'},console,setTimeout,clearTimeout,AbortController,crypto:{randomUUID:()=>String(Math.random())}};
    vm.createContext(context);for(const code of modules(file))vm.runInContext(code,context);
    return {definition:components[name],context,session};
}
const explorerFile=base+'Resources/views/components/explorer/index.blade.php';
const tabFile=base+'Resources/views/components/explorer/tab.blade.php';
const treeFile=base+'Resources/views/components/tree/damdirectories.blade.php';

test('URL search treats spaces, Unicode and zero as explicit all-assets queries',()=>{
    for(const query of ['design assets','café 東京','0','']){
        const state=nav.locationState('?search='+encodeURIComponent(query));
        assert.equal(state.scope,'all');assert.equal(state.search,query);assert.equal(state.explicit,true);
    }
    assert.equal(nav.locationState('?search=leaf&directory_id=44').scope,'directory');
    assert.equal(nav.locationState('?filters%5Ball%5D%5B%5D=leaf').search,'leaf');
    assert.equal(nav.locationState('?media_type=unknown').mediaType,'all');
});

test('explicit header search overrides saved active folder and filters and leaves one view open',()=>{
    const {definition,context}=component(explorerFile,'v-dam-explorer',{
        [userKey('dam_explorer_tabs')]:JSON.stringify({tabs:[{directoryId:12,label:'Keep tab',search:'keep'},{directoryId:77,label:'Old tab',search:'old'}],activeIdx:1})
    });
    context.location.search='?scope=all&search=caf%C3%A9&media_type=svgs';
    const state={tabs:[],activeTabId:null,...definition.methods};
    state.restore(99);
    const active=state.tabs.find(tab=>tab.id===state.activeTabId);
    assert.equal(active.directoryId,null);assert.equal(active.scope,'all');assert.equal(active.search,'café');assert.equal(active.mediaType,'svgs');assert.equal(active.clearSavedFilters,true);
    assert.equal(state.tabs.length,1);
    const {definition:tab}=component(tabFile,'v-dam-tab',{[userKey('dam_explorer_active_dir')]:'77',[userKey('dam_explorer_filter_state')]:JSON.stringify([{index:'extension',value:['pdf']}])});
    const data=tab.data.call({...tab.methods,initialDirectoryId:null,initialScope:'all',initialSearch:'café',initialMediaType:'svgs',clearSavedFilters:true,initialPage:1,initialPerPage:50,initialViewMode:'grid',accessibleIds:[]});
    assert.equal(data.currentDirId,null);assert.equal(data.scope,'all');assert.equal(data.applied.filters.columns.length,0);
});

test('explorer request includes server media and explicit global scope without stale directory',async()=>{
    const {definition}=component(tabFile,'v-dam-tab');let request;
    const state={scope:'all',currentDirId:null,mediaType:'images',includeSubfolders:false,search:'0',page:2,perPage:50,sortBy:'name',sortOrder:'asc',applied:{filters:{columns:[]}},navHistory:[],navIdx:0,$emit(){},computeSelectionMode(){},trackSearchSettled(){},restoreSelection(){},sync(){},$axios:{get:async(url,options)=>{request=options.params;return {data:{directories:[],assets:[],breadcrumb:[],meta:{total_assets:51}}};}}};
    definition.methods.fetch.call(state);await new Promise(resolve=>setImmediate(resolve));
    assert.equal(request.scope,'all');assert.equal(request.directory_id,undefined);assert.equal(request.media_type,'images');assert.equal(request.page,2);assert.equal(request.search,'0');
    assert.equal(state.meta.total_assets,51);
});

test('media filter persists while navigating folders and back to All assets',()=>{
    const {definition}=component(tabFile,'v-dam-tab');let fetched=0;
    const state={...definition.methods,scope:'all',mediaType:'audio',currentDirId:null,navHistory:[{scope:'all',dirId:null,breadcrumb:[]}],navIdx:0,breadcrumb:[],clearSelection(){},fetch(){fetched++;},$emit(){},$emitter:{emit(){}}};
    state.goTo({id:21,name:'Music'});assert.equal(state.mediaType,'audio');assert.equal(state.scope,'directory');
    state.canGoBack=true;state.goBack();assert.equal(state.scope,'all');assert.equal(state.currentDirId,null);assert.equal(state.mediaType,'audio');assert.equal(fetched,2);
});

test('sidebar width clamps persisted and keyboard inputs',()=>{
    assert.equal(nav.sidebarWidth(null),280);assert.equal(nav.sidebarWidth('bad'),280);assert.equal(nav.sidebarWidth(9999),560);assert.equal(nav.sidebarWidth(100),220);assert.equal(nav.sidebarWidth(420),420);
});

test('tree reveal searches unrelated roots and preserves structural path identities',()=>{
    const {definition}=component(treeFile,'v-dam-tree-view');
    const folder={id:103,name:'Root',children:[]};
    const roots=[{id:1,children:[]},{id:100,children:[{id:102,is_structural_wrapper:false,children:[folder]}]}];
    const result=definition.methods.findPathToDirectory.call({formattedItems:roots},103);
    assert.deepEqual(Array.from(result,item=>item.id),[100,102,103]);
});

test('tree drag end defers cleanup and only rolls back a move no drop handler consumed',()=>{
    const {definition}=component(treeFile,'v-dam-tree-view');let deferred;let restorations=0;
    const state={dragStart:true,$nextTick:callback=>deferred=callback,restoreFolderTreeSnapshot:()=>restorations++};
    definition.methods.onDragEnd.call(state);
    assert.equal(restorations,0);
    deferred();assert.equal(restorations,1);

    state.dragStart=false;definition.methods.onDragEnd.call(state);deferred();assert.equal(restorations,1);
});

test('failed directory move restores the original tree before reloading',async()=>{
    const {definition}=component(treeFile,'v-dam-tree-view');let reloads=0;
    const original=[{id:1,children:[{id:2,children:[]}]}];
    const state={
        ...definition.methods,
        formattedItems:[{id:1,children:[]}],dragSnapshot:JSON.stringify(original),dragStart:false,
        $axios:{post:async()=>{throw {response:{data:{message:'Move failed'}}};}},
        $emitter:{emit(){}},loadDirectories:()=>reloads++,
    };
    state.addedItems({id:2,name:'Moved'},1,'directory');
    await new Promise(resolve=>setImmediate(resolve));
    assert.equal(JSON.stringify(state.formattedItems),JSON.stringify(original));assert.equal(reloads,1);assert.equal(state.dragSnapshot,null);
});

const browserTest=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip;
// What the real Iconvault page does: answer the dialog's "hello" with "ready", echoing the load's number.
const iconvaultAnswer=attempt=>'<script>addEventListener("message",event=>{if(event.data&&event.data.type==="mothership:iconvault:hello")event.source.postMessage({type:"mothership:iconvault:ready",attempt:'+attempt+'},"*")})</script>';
const iconvaultReady=iconvaultAnswer('event.data.attempt');
browserTest('Iconvault dialog says the catalog did not open, and offers the new-tab link, when the frame is refused, never answers, answers for an earlier load, or never loads',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        // refused: the browser blocks the frame, as it does when Iconvault's frame-ancestors list does not name
        // this site; the iframe's load event still fires. silent: some other page loads and never answers.
        // stale: the answer carries the previous load's number, as one left over from before a retry would.
        // stalled: the request never completes, so no load event fires; the page clock stands in for the wait.
        const replies={refused:{body:'<h1>Icon catalog fixture</h1>'+iconvaultReady,headers:{'content-security-policy':'frame-ancestors https://not-this-site.example'}},silent:{body:'<h1>Sign in</h1>'},stale:{body:'<h1>Icon catalog fixture</h1>'+iconvaultAnswer('event.data.attempt-1')}};
        for(const kind of ['refused','silent','stale','stalled']){
            const page=await browser.newPage({viewport:{width:390,height:844}});
            await page.route('https://icons.example.org/**',route=>replies[kind]?route.fulfill({contentType:'text/html; charset=utf-8',...replies[kind]}):undefined);
            if(kind==='stalled')await page.clock.install();
            await page.setContent('<a id="library" href="https://icons.example.org/" target="_blank">Library icons</a>');
            await page.addStyleTag({content:source(base+'Resources/views/components/library-navigation.blade.php').match(/<style>([\s\S]*?)<\/style>/)[1]});
            await page.addScriptTag({content:"window.MothershipIconvaultUrl='https://icons.example.org/'"});await page.addScriptTag({content:source(base+'Resources/assets/js/iconvault-dialog.js')});
            const status=page.locator('[data-iconvault-status]'),fallback=page.locator('[data-iconvault-fallback]');
            for(const again of [false,true]){
                if(again)await fallback.getByRole('button',{name:'Try again'}).click();else await page.locator('#library').click();
                assert.equal(await status.textContent(),'Loading Iconvault…',kind);assert.equal(await fallback.isHidden(),true,kind);
                // A "ready" from another origin or another window does not count as Iconvault having opened, even
                // with the right number (1 on the first open, 2 after "Try again").
                for(const forged of ['wrong-origin','wrong-source'])await page.evaluate(([forged,attempt])=>window.dispatchEvent(new MessageEvent('message',{origin:forged==='wrong-origin'?'https://example.com':'https://icons.example.org',source:forged==='wrong-source'?window:document.querySelector('iframe').contentWindow,data:{type:'mothership:iconvault:ready',attempt}})),[forged,again?2:1]);
                if(kind==='stalled'){assert.equal(await fallback.isHidden(),true,kind);await page.clock.fastForward(15000);}
                await fallback.waitFor({state:'visible',timeout:8000});
                assert.match(await status.textContent(),/did not open here/,kind);assert.equal(await page.locator('dialog iframe').isHidden(),true,kind);
            }
            const link=fallback.getByRole('link',{name:'Open Iconvault in a new tab'});
            assert.deepEqual(await link.evaluate(a=>[a.href,a.target,a.rel]),['https://icons.example.org/','_blank','noopener noreferrer'],kind);
            const box=await link.boundingBox();assert.ok(box.height>=44&&box.x>=0&&box.x+box.width<=390,kind);
            await page.close();
        }
    }finally{await browser.close();}
});
browserTest('Iconvault opens one real modal from both entry links, closes with Escape and restores focus',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage({viewport:{width:1280,height:900}});
        await page.route('https://icons.example.org/**',route=>route.fulfill({body:'<h1>Icon catalog fixture</h1>'+iconvaultReady,contentType:'text/html; charset=utf-8'}));
        await page.setContent('<a id="home" href="https://icons.example.org/" target="_blank">Home icons</a><a id="library" href="https://icons.example.org/" target="_blank">Library icons</a>');
        await page.addStyleTag({content:source(base+'Resources/views/components/library-navigation.blade.php').match(/<style>([\s\S]*?)<\/style>/)[1]});
        await page.addScriptTag({content:"window.MothershipIconvaultUrl='https://icons.example.org/'"});await page.addScriptTag({content:source(base+'Resources/assets/js/iconvault-dialog.js')});
        for(const id of ['home','library']){
            await page.locator('#'+id).click();await page.getByRole('dialog').waitFor();
            assert.equal(await page.locator('dialog').count(),1);assert.equal(await page.evaluate(()=>document.body.style.overflow),'hidden');
            await page.frameLocator('iframe').getByRole('heading',{name:'Icon catalog fixture'}).waitFor();
            await page.locator('[data-iconvault-status]').filter({hasText:'Iconvault loaded.'}).waitFor();
            assert.equal(await page.locator('[data-iconvault-fallback]').isHidden(),true);
            const box=await page.getByRole('dialog').boundingBox();assert.ok(box.width>1150&&box.height>800);
            await page.getByRole('button',{name:'Expand',exact:true}).click();
            assert.equal(await page.getByRole('button',{name:'Restore size'}).getAttribute('aria-pressed'),'true');
            await page.getByRole('button',{name:'Restore size'}).click();
            await page.keyboard.press('Escape');assert.equal(await page.locator('dialog').evaluate(el=>el.open),false);assert.equal(await page.evaluate(()=>document.activeElement.id),id);await page.waitForFunction(()=>document.body.style.overflow==='');
        }
    }finally{await browser.close();}
});

const vueTest=process.env.MOTHERSHIP_VUE_RUNTIME_PATH&&process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip;
const draggableVueTest=process.env.MOTHERSHIP_VUE_RUNTIME_PATH&&process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH&&process.env.MOTHERSHIP_SORTABLE_PATH&&process.env.MOTHERSHIP_VUEDRAGGABLE_PATH?test:test.skip;

draggableVueTest('real nested folder drags preserve the dragged folder identity for parent and Root targets',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage({viewport:{width:900,height:700}});
        await page.setContent('<style>.directoryItems{min-height:36px}.tree-container-details{padding:4px}.icon-dam-folder{display:inline-block;width:20px;height:20px}</style><main id="app"><button id="root-target" @dragover.prevent @drop="dropOnRoot">Root</button><v-dam-tree-item :item="tree" @on-drag-start="onDragStart" @on-drag-end="onDragEnd" @on-merge-items="onMergeItems"></v-dam-tree-item><output id="events">{{ events.join(",") }}</output></main>');
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
        await page.addScriptTag({path:process.env.MOTHERSHIP_SORTABLE_PATH});
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUEDRAGGABLE_PATH});
        await page.addScriptTag({content:source(base+'Resources/assets/js/library-navigation.js')});
        const template=blade(source(treeFile).match(/<script type="text\/x-template" id="v-item-template">([\s\S]*?)<\/script>/)[1]);
        await page.evaluate(template=>{
            const script=document.createElement('script');script.type='text/x-template';script.id='v-item-template';script.textContent=template;document.body.append(script);
            window.app=Vue.createApp({
                data:()=>({tree:{id:1,name:'Wrapper',is_structural_wrapper:true,has_children:true,_loaded:true,children:[{id:2,name:'Folder A',has_children:true,_loaded:true,children:[{id:3,name:'Nested',has_children:false,_loaded:true,children:[]}]},{id:4,name:'Folder B',has_children:true,_loaded:true,children:[]}]},active:null,events:[]}),
                methods:{
                    onDragStart(event,item){this.active=item;this.events.push('start:'+(item?.id??'none'));},
                    onDragEnd(){this.events.push('end');this.active=null;},
                    onMergeItems(event,directoryId){if(event.added)this.events.push('add:'+event.added.element.id+':'+directoryId);},
                    dropOnRoot(){this.events.push('root:'+(this.active?.id??'none'));},
                },
            });
            app.provide('damTreeShowAssets',false);
            app.config.globalProperties.$emitter={on(){},off(){},emit(){}};
            app.config.globalProperties.$axios={post:async()=>({data:{counts:{}}}),get:async()=>({data:{data:[],has_more:false}})};
            app.component('draggable',window.vuedraggable);
            app.component('v-asset-count-badge',{template:'<span></span>'});
        },template);
        for(const code of modules(treeFile).filter(code=>code.includes("app.component('v-dam-tree-item'")))await page.addScriptTag({content:code});
        await page.evaluate(()=>app.mount('#app'));
        await page.getByRole('button',{name:'Folder A',exact:true}).click();
        await page.getByRole('button',{name:'Folder B',exact:true}).click();
        const drag=async(sourceLocator,targetLocator)=>{
            await sourceLocator.dragTo(targetLocator);await page.waitForTimeout(100);
        };
        await drag(page.locator('[data-dir-id="3"] .icon-dam-folder'),page.locator('[data-dir-id="4"]').locator('xpath=..').locator('.directoryItems').first());
        assert.match(await page.locator('#events').textContent(),/(^|,)start:3(,|$)/);
        assert.match(await page.locator('#events').textContent(),/(^|,)add:3:4(,|$)/);
        await drag(page.locator('[data-dir-id="3"] .icon-dam-folder'),page.locator('#root-target'));
        assert.match(await page.locator('#events').textContent(),/(^|,)root:3(,|$)/);
        assert.equal((await page.locator('#events').textContent()).split(',').filter(event=>event==='end').length,2);
    }finally{await browser.close();}
});

draggableVueTest('real asset drag hover expands a collapsed folder',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage({viewport:{width:900,height:700}});
        await page.setContent('<style>.directoryItems,.itemsAssets{min-height:36px}.tree-container-details{padding:4px}.tree-container-assets-details,.icon-dam-folder{display:block;min-width:30px;min-height:24px}</style><main id="app"><v-dam-tree-item :item="tree" @on-drag-start="onDragStart" @on-drag-end="onDragEnd"></v-dam-tree-item></main>');
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});await page.addScriptTag({path:process.env.MOTHERSHIP_SORTABLE_PATH});await page.addScriptTag({path:process.env.MOTHERSHIP_VUEDRAGGABLE_PATH});await page.addScriptTag({content:source(base+'Resources/assets/js/library-navigation.js')});
        const itemTemplate=blade(source(treeFile).match(/<script type="text\/x-template" id="v-item-template">([\s\S]*?)<\/script>/)[1]);
        const assetTemplate=blade(source(treeFile).match(/<script type="text\/x-template" id="v-asset-item-template">([\s\S]*?)<\/script>/)[1]);
        await page.evaluate(({itemTemplate,assetTemplate})=>{
            for(const [id,template] of [['v-item-template',itemTemplate],['v-asset-item-template',assetTemplate]]){const script=document.createElement('script');script.type='text/x-template';script.id=id;script.textContent=template;document.body.append(script);}
            window.assetDragStarted=false;window.app=Vue.createApp({data:()=>({tree:{id:1,name:'Wrapper',is_structural_wrapper:true,has_children:true,_loaded:true,children:[{id:2,name:'Assets',has_children:false,_loaded:true,children:[],assets_count:1,assets:[{id:30,file_name:'asset.jpg',file_type:'image'}]},{id:4,name:'Collapsed target',has_children:true,_loaded:true,children:[]}]}}),methods:{onDragStart(){window.assetDragStarted=true;},onDragEnd(){}}});
            app.provide('damTreeShowAssets',true);app.config.globalProperties.$emitter={on(){},off(){},emit(){}};app.config.globalProperties.$axios={post:async()=>({data:{counts:{}}}),get:async url=>({data:{data:url.endsWith('/2')?[{id:30,file_name:'asset.jpg',file_type:'image'}]:[],has_more:false}})};app.component('draggable',window.vuedraggable);app.component('v-asset-count-badge',{template:'<span></span>'});
        },{itemTemplate,assetTemplate});
        for(const code of modules(treeFile).filter(code=>code.includes("app.component('v-asset-item'")||code.includes("app.component('v-dam-tree-item'")))await page.addScriptTag({content:code});
        await page.evaluate(()=>app.mount('#app'));await page.getByRole('button',{name:'Assets',exact:true}).click();
        const assetSource=page.locator('.tree-container-assets-details').first();const target=page.locator('[data-dir-id="4"]');const sourceBox=await assetSource.boundingBox();const targetBox=await target.boundingBox();
        await target.evaluate(element=>{window.targetDragEnters=0;element.addEventListener('dragenter',()=>window.targetDragEnters++);});
        await page.mouse.move(sourceBox.x+sourceBox.width/2,sourceBox.y+sourceBox.height/2);await page.mouse.down();await page.mouse.move(sourceBox.x+12,sourceBox.y+18,{steps:4});await page.mouse.move(targetBox.x+targetBox.width/2,targetBox.y+targetBox.height/2,{steps:12});await target.dispatchEvent('dragenter');await page.waitForTimeout(650);
        assert.equal(await page.evaluate(()=>window.assetDragStarted),true);assert.ok(await page.evaluate(()=>window.targetDragEnters)>0);
        assert.equal(await page.getByRole('button',{name:'Collapsed target',exact:true}).getAttribute('aria-expanded'),'true');await page.mouse.up();
    }finally{await browser.close();}
});
vueTest('actual Vue tree hides only annotated wrapper rows, lazily renders page two and keeps keyboard folder actions',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage();
        await page.setContent('<main id="app"><v-dam-tree-item :item="tree" @right-click-item="action"></v-dam-tree-item><output>{{ chosen }}</output></main>');
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
        const template=blade(source(treeFile).match(/<script type="text\/x-template" id="v-item-template">([\s\S]*?)<\/script>/)[1]);
        await page.evaluate(template=>{
            const script=document.createElement('script');script.type='text/x-template';script.id='v-item-template';script.textContent=template;document.body.append(script);
            window.app=Vue.createApp({data:()=>({tree:{id:1,name:'Root',is_structural_wrapper:true,has_children:true,children:[{id:2,name:'Imported Library',is_structural_wrapper:true,has_children:true,children:[]}]},chosen:''}),methods:{action(event,item){this.chosen=item.name;}}});
            app.provide('damTreeShowAssets',false);
            app.config.globalProperties.$emitter={on(){},off(){},emit(){}};
            app.config.globalProperties.$axios={post:async()=>({data:{data:{}}}),get:async(url,{params}={})=>({data:{data:Array.from({length:params?.offset?5:100},(_,index)=>({id:10+(params?.offset||0)+index,name:index===0&&!params?.offset?'Imported Library':'Long folder name '+((params?.offset||0)+index),children:[],has_children:false,is_structural_wrapper:false})),has_more:!params?.offset}})};
            app.component('draggable',{props:['list'],template:'<div><template v-for="element in list" :key="element.id"><slot name="item" :element="element"></slot></template></div>'});
            app.component('v-asset-count-badge',{template:'<span></span>'});
        },template);
        for(const code of modules(treeFile).filter(code=>code.includes("app.component('v-dam-tree-item'")))await page.addScriptTag({content:code});
        await page.evaluate(()=>app.mount('#app'));
        await page.getByRole('button',{name:'Actions for Imported Library',exact:true}).waitFor();
        assert.equal(await page.locator('[data-dir-id="1"]').count(),0);assert.equal(await page.locator('[data-dir-id="2"]').count(),0);assert.equal(await page.locator('[data-dir-id]').count(),100);
        await page.getByRole('button',{name:'Translation',exact:true}).click();assert.equal(await page.locator('[data-dir-id]').count(),105);
        await page.getByRole('button',{name:'Actions for Imported Library',exact:true}).focus();await page.keyboard.press('Enter');assert.equal(await page.locator('output').textContent(),'Imported Library');
    }finally{await browser.close();}
});


browserTest('actual header form expands on phone widths and submits Unicode through native Enter navigation',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage({viewport:{width:390,height:800}});
        await page.route('http://mothership.test/**',route=>route.fulfill({body:'<main>Search results</main>',contentType:'text/html; charset=utf-8'}));
        await page.goto('http://mothership.test/');
        const header=source('packages/Mothership/Admin/src/Resources/views/components/layouts/header/index.blade.php');
        const form=header.match(/<form action="[\s\S]*?data-asset-search[\s\S]*?<\/form>/)[0].replace(/\{\{ route[^}]*\}\}/g,'http://mothership.test/admin/dam').replace(/\{\{ request[\s\S]*?\}\}/g,'');
        const shared=source(base+'Resources/views/components/library-navigation.blade.php');
        await page.setContent('<header style="position:relative">'+form+'</header>');
        await page.addStyleTag({content:shared.match(/<style>([\s\S]*?)<\/style>/)[1]});
        await page.addScriptTag({content:shared.match(/<script>\s*(if \(!window.__mothershipHeaderSearch[\s\S]*?)<\/script>/)[1]});
        await page.getByRole('button',{name:'Search assets',exact:true}).click();
        const search=page.getByRole('searchbox',{name:'Search all assets'});
        assert.equal(await search.evaluate(el=>el===document.activeElement),true);
        await search.fill('café 東京');
        await Promise.all([page.waitForURL(url=>url.pathname==='/admin/dam'),page.keyboard.press('Enter')]);
        const url=new URL(page.url());assert.equal(url.searchParams.get('scope'),'all');assert.equal(url.searchParams.get('search'),'café 東京');
    }finally{await browser.close();}
});

vueTest('sidebar pointer and keyboard controls persist width without changing mobile width',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage({viewport:{width:1200,height:800}});
        await page.route('http://mothership.test/**',route=>route.fulfill({body:'<main id="app"></main>',contentType:'text/html; charset=utf-8'}));
        await page.goto('http://mothership.test/');
        await page.evaluate(()=>localStorage.setItem('dam_show_sidebar','true'));
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
        await page.addScriptTag({content:source(base+'Resources/assets/js/library-navigation.js')});
        await page.evaluate(()=>{window.definitions={};window.app={component:(name,definition)=>definitions[name]=definition};});
        const mainFile=base+'Resources/views/asset/index.blade.php';
        for(const code of modules(mainFile))await page.addScriptTag({content:code});
        const mainSource=source(mainFile);
        const binding=mainSource.match(/:style="(\{ width: isDesktopView[^"\n]+)/)[1];
        const separator=mainSource.match(/<div v-if="isDesktopView && showSidebar"[\s\S]*?<\/div>/)[0];
        await page.addStyleTag({content:source(base+'Resources/views/components/library-navigation.blade.php').match(/<style>([\s\S]*?)<\/style>/)[1]});
        await page.evaluate(({binding,separator})=>{
            const def=definitions['v-dam-main'];
            const instance=Vue.createApp({...def,template:`<div style="display:flex;height:400px"><aside data-test-sidebar :style="${binding}">Folders</aside>${separator}<section>Assets</section></div>`});instance.config.globalProperties.$emitter={on(){},off(){},emit(){}};instance.mount('#app');
        },{binding,separator});
        const handle=page.getByRole('separator',{name:'Directory sidebar width'});
        await handle.focus();await page.keyboard.press('ArrowRight');assert.equal(await handle.getAttribute('aria-valuenow'),'300');
        const box=await handle.boundingBox();await page.mouse.move(box.x+4,box.y+20);await page.mouse.down();await page.mouse.move(box.x+104,box.y+20);await page.mouse.up();
        assert.equal(await handle.getAttribute('aria-valuenow'),'400');assert.equal(await page.evaluate(()=>localStorage.getItem('dam_sidebar_width')),'400');
        await handle.focus();await page.keyboard.press('Home');assert.equal(await handle.getAttribute('aria-valuenow'),'220');
        await page.setViewportSize({width:390,height:800});await handle.waitFor({state:'detached'});assert.equal(await page.locator('[data-test-sidebar]').evaluate(el=>el.style.width),'');assert.equal(await page.evaluate(()=>localStorage.getItem('dam_sidebar_width')),'220');
    }finally{await browser.close();}
});


// Codex review of #158: from lg up the column may render narrower than its stored width (it gives way to the asset
// panel). A drag or a key press must move the column the user sees, not the stored number behind it.
vueTest('sidebar resizing starts from the rendered width when the column has given way',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage({viewport:{width:1200,height:800}});
        await page.route('http://mothership.test/**',route=>route.fulfill({body:'<main id="app"></main>',contentType:'text/html; charset=utf-8'}));
        await page.goto('http://mothership.test/');
        await page.evaluate(()=>localStorage.setItem('dam_show_sidebar','true'));
        await page.evaluate(()=>localStorage.setItem('dam_sidebar_width','560'));
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
        await page.addScriptTag({content:source(base+'Resources/assets/js/library-navigation.js')});
        await page.evaluate(()=>{window.definitions={};window.app={component:(name,definition)=>definitions[name]=definition};});
        const mainFile=base+'Resources/views/asset/index.blade.php';
        for(const code of modules(mainFile))await page.addScriptTag({content:code});
        const mainSource=source(mainFile);
        const binding=mainSource.match(/:style="(\{ width: isDesktopView[^"\n]+)/)[1];
        const separator=mainSource.match(/<div v-if="isDesktopView && showSidebar"[\s\S]*?<\/div>/)[0];
        await page.addStyleTag({content:source(base+'Resources/views/components/library-navigation.blade.php').match(/<style>([\s\S]*?)<\/style>/)[1]});
        // The stored 560px column renders at 300px, as it does at 1024px with the nav expanded.
        await page.evaluate(({binding,separator})=>{
            const def=definitions['v-dam-main'];
            const instance=Vue.createApp({...def,template:`<div style="display:flex;height:400px"><aside data-test-sidebar style="max-width:300px" :style="${binding}">Folders</aside>${separator}<section>Assets</section></div>`});instance.config.globalProperties.$emitter={on(){},off(){},emit(){}};instance.mount('#app');
        },{binding,separator});
        const handle=page.getByRole('separator',{name:'Directory sidebar width'});
        const rendered=()=>page.locator('[data-test-sidebar]').evaluate(el=>Math.round(el.getBoundingClientRect().width));
        assert.equal(await handle.getAttribute('aria-valuenow'),'560');assert.equal(await rendered(),300);
        const box=await handle.boundingBox();await page.mouse.move(box.x+4,box.y+20);await page.mouse.down();await page.mouse.move(box.x-36,box.y+20);await page.mouse.up();
        assert.equal(await rendered(),260,'a 40px drag left narrows the visible column by 40px');
        assert.equal(await handle.getAttribute('aria-valuenow'),'260');assert.equal(await page.evaluate(()=>localStorage.getItem('dam_sidebar_width')),'260');
        await page.evaluate(()=>localStorage.setItem('dam_sidebar_width','560'));
        await handle.focus();await page.keyboard.press('End');assert.equal(await rendered(),300);
        await page.keyboard.press('ArrowLeft');
        assert.equal(await rendered(),280,'one ArrowLeft narrows the visible column at once');assert.equal(await handle.getAttribute('aria-valuenow'),'280');
    }finally{await browser.close();}
});

browserTest('Iconvault iframe Escape handshake validates origin and exact frame source',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage();
        await page.route('https://icons.example.org/**',route=>route.fulfill({body:'<input aria-label="Find icons"><script>addEventListener("keydown",event=>{if(event.key==="Escape")parent.postMessage({type:"mothership:iconvault:close"},"*")})</script>'+iconvaultReady,contentType:'text/html; charset=utf-8'}));
        await page.setContent('<button id="opener">Browse icons</button>');await page.addScriptTag({content:"window.MothershipIconvaultUrl='https://icons.example.org/'"});await page.addScriptTag({content:source(base+'Resources/assets/js/iconvault-dialog.js')});
        await page.evaluate(()=>MothershipIconvault.open(document.querySelector('#opener')));
        for(const kind of ['wrong-origin','wrong-source','wrong-type']){
            await page.evaluate(kind=>window.dispatchEvent(new MessageEvent('message',{origin:kind==='wrong-origin'?'https://example.com':'https://icons.example.org',source:kind==='wrong-source'?window:document.querySelector('iframe').contentWindow,data:{type:kind==='wrong-type'?'other':'mothership:iconvault:close'}})),kind);
            assert.equal(await page.locator('dialog').evaluate(el=>el.open),true);
        }
        await page.frameLocator('iframe').getByRole('textbox',{name:'Find icons'}).focus();await page.keyboard.press('Escape');
        await page.waitForFunction(()=>!document.querySelector('dialog').open&&document.body.style.overflow==='');assert.equal(await page.evaluate(()=>document.activeElement.id),'opener');
    }finally{await browser.close();}
});


test('URL reload roundtrip preserves saved page, view and page size while fresh searches reset them',()=>{
    const storage={};const {definition,context}=component(explorerFile,'v-dam-explorer',storage);
    context.setTimeout=callback=>{callback();return 1;};
    context.document={querySelector:()=>null};context.history={state:null,replaceState:(state,title,url)=>{context.location.href=String(url);context.location.search=new URL(url).search;}};
    const tab={id:'saved',directoryId:null,scope:'all',search:'café',mediaType:'svgs',label:'All assets',page:4,viewMode:'list',perPage:100};
    const original={...definition.methods,tabs:[tab],activeTabId:tab.id};
    original.syncLocation(tab);original.persistTabs();
    assert.deepEqual(context.historyRetirements,['dam:preview-history-retired'],'URL listing writes retire preview ownership');
    const reload={...definition.methods,tabs:[],activeTabId:null};reload.restore();
    const restored=reload.tabs.find(item=>item.id===reload.activeTabId);
    assert.equal(restored.page,4);assert.equal(restored.viewMode,'list');assert.equal(restored.perPage,100);assert.equal(restored.clearSavedFilters,false);
    for(const query of ['?scope=all&search=changed','?scope=all&search=','?scope=all&search=caf%C3%A9&media_type=svgs&search_submit=1']){
        context.location.search=query;
        const next={...definition.methods,tabs:[],activeTabId:null};next.restore();
        const active=next.tabs.find(item=>item.id===next.activeTabId);
        assert.equal(active.search,new URLSearchParams(query).get('search'));assert.equal(active.page,1);assert.equal(active.viewMode,'grid');assert.equal(active.perPage,null);assert.equal(active.clearSavedFilters,true);
    }
});

test('restore keeps only the view that was active and normalizes its scope and media type',()=>{
    const tabs=[{directoryId:44,scope:'invalid',mediaType:'invalid',search:'old'},{directoryId:12,scope:'all',mediaType:'svgs'}];
    for(const [activeIdx,scope,directoryId,mediaType] of [[0,'directory',44,'all'],[1,'all',null,'svgs']]){
        const {definition}=component(explorerFile,'v-dam-explorer',{[userKey('dam_explorer_tabs')]:JSON.stringify({tabs,activeIdx})});
        const state={...definition.methods,tabs:[],activeTabId:null};state.restore();
        assert.equal(state.tabs.length,1);assert.equal(state.activeTabId,state.tabs[0].id);
        assert.equal(state.tabs[0].scope,scope);assert.equal(state.tabs[0].directoryId,directoryId);assert.equal(state.tabs[0].mediaType,mediaType);
    }
});

test('missing shared navigation helper does not crash Library data or saved explorer restore',()=>{
    const mainFile=base+'Resources/views/asset/index.blade.php';
    const main=component(mainFile,'v-dam-main');delete main.context.window.MothershipLibraryNavigation;
    assert.doesNotThrow(()=>main.definition.data.call({}));
    const upload=component(mainFile,'v-dam-upload');delete upload.context.window.MothershipLibraryNavigation;
    assert.doesNotThrow(()=>upload.definition.data.call({accessibleIds:[]}));
    const explorer=component(explorerFile,'v-dam-explorer',{[userKey('dam_explorer_tabs')]:JSON.stringify({tabs:[{directoryId:12,scope:'directory',mediaType:'audio',page:2}],activeIdx:0})});delete explorer.context.window.MothershipLibraryNavigation;
    const state={...explorer.definition.methods,tabs:[],activeTabId:null};assert.doesNotThrow(()=>state.restore());
    // Without the helper the account is unknown, so no saved state is read (F20): a fresh tab opens.
    assert.equal(state.tabs.length,1);assert.equal(state.tabs[0].mediaType,'all');assert.equal(state.tabs[0].page,1);
});

test('restore honours an explicit page for a matching saved view, a page-only url and a fresh tab (F13)',()=>{
    const saved=JSON.stringify({tabs:[{directoryId:12,scope:'directory',label:'Keep',page:3},{scope:'all',search:'campus',mediaType:'all',label:'All assets',page:1}],activeIdx:1});
    const cases=[
        ['?scope=all&search=campus&media_type=all&page=2',2],
        ['?scope=all&search=campus&media_type=all',1],
        ['?page=4',4],
        ['?page=bogus',1],
    ];
    // An explicit matching view without a page keeps the remembered page; a directory view names its own.
    const remembered=JSON.stringify({tabs:[{directoryId:12,scope:'directory',label:'Keep',page:3}],activeIdx:0});
    for(const [query,page] of [['?scope=directory&directory_id=12&search=&media_type=all',3],['?scope=directory&directory_id=12&search=&media_type=all&page=5',5]]){
        const {definition,context}=component(explorerFile,'v-dam-explorer',{[userKey('dam_explorer_tabs')]:remembered});
        context.location.search=query;
        const state={...definition.methods,tabs:[],activeTabId:null};state.restore();
        const active=state.tabs.find(tab=>tab.id===state.activeTabId);
        assert.equal(active.directoryId,12,query);assert.equal(active.page,page,query);
    }
    for(const [query,page] of cases){
        const {definition,context}=component(explorerFile,'v-dam-explorer',{[userKey('dam_explorer_tabs')]:saved});
        context.location.search=query;
        const state={...definition.methods,tabs:[],activeTabId:null};state.restore();
        const active=state.tabs.find(tab=>tab.id===state.activeTabId);
        assert.equal(active.search,'campus',query);assert.equal(active.scope,'all',query);assert.equal(active.page,page,query);
        assert.equal(state.tabs.length,1,query);
    }
    const {definition,context}=component(explorerFile,'v-dam-explorer',{});
    context.location.search='?page=2';
    const fresh={...definition.methods,tabs:[],activeTabId:null};fresh.restore();
    assert.equal(fresh.tabs.length,1);assert.equal(fresh.tabs[0].page,2);
});

test('another account never restores the previous account tabs, folder or search (F20)',()=>{
    const storage={[userKey('dam_explorer_tabs')]:JSON.stringify({tabs:[{directoryId:143,scope:'directory',label:'Full-Campus-Versions',search:'tree'}],activeIdx:0})};
    const {definition}=component(explorerFile,'v-dam-explorer',storage);
    nav.userId=6;
    try{
        const state={...definition.methods,tabs:[],activeTabId:null};state.restore();
        assert.equal(state.tabs.length,1);assert.equal(state.tabs[0].directoryId,null);assert.equal(state.tabs[0].search,'');
        const {definition:tab}=component(tabFile,'v-dam-tab',{[userKey('dam_explorer_active_dir')]:'143',[userKey('dam_explorer_filter_state')]:JSON.stringify([{index:'extension',value:['glb']}])});
        const data=tab.data.call({...tab.methods,initialDirectoryId:null,initialScope:'directory',initialSearch:'',initialMediaType:'all',clearSavedFilters:false,initialPage:1,initialPerPage:50,initialViewMode:'grid',accessibleIds:[]});
        assert.equal(data.currentDirId,null);assert.equal(data.applied.filters.columns.length,0);
    }finally{nav.userId=5;}
    const {definition:own}=component(tabFile,'v-dam-tab',{[userKey('dam_explorer_active_dir')]:'143',[userKey('dam_explorer_filter_state')]:JSON.stringify([{index:'extension',value:['glb']}])});
    const mine=own.data.call({...own.methods,initialDirectoryId:null,initialScope:'directory',initialSearch:'',initialMediaType:'all',clearSavedFilters:false,initialPage:1,initialPerPage:50,initialViewMode:'grid',accessibleIds:[]});
    assert.equal(mine.currentDirId,143);assert.equal(mine.applied.filters.columns.length,1);
});

const tabProps={initialDirectoryId:null,initialScope:'all',initialSearch:'',initialMediaType:'all',clearSavedFilters:false,initialPage:1,initialViewMode:'grid',accessibleIds:[]};

test('Back and Forward restore the full view: folder, search, media type and page',()=>{
    // Folder A with a search, a media filter and page 2, then folder B: A's url keeps all of it.
    const atA=nav.locationState('?scope=directory&directory_id=10&search=logo&media_type=images&page=2');
    const tabAtB={scope:'directory',directoryId:20,search:'',mediaType:'all',page:1};
    const back=nav.popStatePlan(atA,tabAtB);
    assert.equal(back.kind,'directory');assert.equal(back.directoryId,10);assert.equal(back.sameView,false);
    assert.deepEqual({...back.view},{search:'logo',mediaType:'images',page:2});
    // Forward to B again.
    const forward=nav.popStatePlan(nav.locationState('?scope=directory&directory_id=20&search=&media_type=all'),{scope:'directory',directoryId:10,search:'logo',mediaType:'images',page:2});
    assert.equal(forward.directoryId,20);assert.deepEqual({...forward.view},{search:'',mediaType:'all',page:1});
    // Same folder but a different search/page is not an early return.
    const sameFolder=nav.popStatePlan(atA,{scope:'directory',directoryId:10,search:'',mediaType:'all',page:1});
    assert.equal(sameFolder.sameView,true);assert.equal(sameFolder.view.page,2);
    // Exactly what the tab shows already: nothing to do.
    assert.equal(nav.popStatePlan(atA,{scope:'directory',directoryId:10,search:'logo',mediaType:'images',page:2}),null);
    assert.equal(nav.popStatePlan(nav.locationState(''),tabAtB),null);
});

test('the tab applies the restored search, media type and page before it fetches, never after a cleared fetch',()=>{
    const {definition}=component(tabFile,'v-dam-tab');const fetched=[];
    const state={...definition.methods,scope:'directory',currentDirId:20,search:'',searchInput:'',mediaType:'all',page:1,navHistory:[],navIdx:-1,breadcrumb:[],similarTo:null,similarName:'',applied:{filters:{columns:[]}},
        userKey:()=>null,clearSelection(){},organizeReset(){},$emit(){},$emitter:{emit(){}},fetch(){fetched.push({dir:this.currentDirId,search:this.search,media:this.mediaType,page:this.page});}};
    state.applyPopState(nav.popStatePlan(nav.locationState('?scope=directory&directory_id=10&search=logo&media_type=images&page=2'),{scope:'directory',directoryId:20,search:'',mediaType:'all',page:1}));
    assert.deepEqual(fetched,[{dir:10,search:'logo',media:'images',page:2}]);
    assert.equal(state.searchInput,'logo');
    // Same folder, different page only: one fetch, no folder change.
    fetched.length=0;
    state.applyPopState(nav.popStatePlan(nav.locationState('?scope=directory&directory_id=10&search=logo&media_type=images&page=3'),{scope:'directory',directoryId:10,search:'logo',mediaType:'images',page:2}));
    assert.deepEqual(fetched,[{dir:10,search:'logo',media:'images',page:3}]);
    // Back to All assets with its own view.
    fetched.length=0;
    state.applyPopState(nav.popStatePlan(nav.locationState('?scope=all&search=&media_type=audio'),{scope:'directory',directoryId:10,search:'logo',mediaType:'images',page:3}));
    assert.deepEqual(fetched,[{dir:null,search:'',media:'audio',page:1}]);
});

test('a tab that saved 50 rows keeps it when the remembered preference is 200; an unset tab takes the preference',()=>{
    const storage={dam_explorer_per_page:'200'};
    const {definition:tab}=component(tabFile,'v-dam-tab',storage);
    assert.equal(tab.data.call({...tab.methods,...tabProps,initialPerPage:50}).perPage,50);
    assert.equal(tab.data.call({...tab.methods,...tabProps,initialPerPage:null}).perPage,200);
    assert.equal(tab.data.call({...tab.methods,...tabProps,initialPerPage:100}).perPage,100);
    for(const [activeIdx,perPage] of [[0,50],[1,null]]){
        const {definition:explorer}=component(explorerFile,'v-dam-explorer',{[userKey('dam_explorer_tabs')]:JSON.stringify({tabs:[{directoryId:12,label:'Fifty',perPage:50},{directoryId:13,label:'Unset'}],activeIdx})});
        const state={tabs:[],activeTabId:null,...explorer.methods,$emitter:{emit(){}}};state.restore();
        assert.equal(state.tabs[0].perPage,perPage);
    }
});

test('the clipboard survives a reload in this browser tab and never crosses accounts',()=>{
    const {definition:tab,session}=component(tabFile,'v-dam-tab');
    session[userKey('dam_explorer_clipboard')]=JSON.stringify({type:'asset',id:41,name:'logo.png'});
    const data=tab.data.call({...tab.methods,...tabProps,initialPerPage:null});
    assert.equal(data.clipboard.id,41);assert.equal(data.clipboard.type,'asset');
    // A malformed or foreign-typed entry is ignored.
    session[userKey('dam_explorer_clipboard')]=JSON.stringify({type:'bogus',id:1});
    assert.equal(tab.data.call({...tab.methods,...tabProps,initialPerPage:null}).clipboard,null);
    // Another account on the same browser tab sees nothing.
    session[userKey('dam_explorer_clipboard')]=JSON.stringify({type:'directory',id:7,name:'Photos'});
    nav.userId=6;
    try{assert.equal(tab.data.call({...tab.methods,...tabProps,initialPerPage:null}).clipboard,null);}finally{nav.userId=5;}
    assert.equal(tab.data.call({...tab.methods,...tabProps,initialPerPage:null}).clipboard.name,'Photos');
});
