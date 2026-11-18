const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const cardFile='packages/Mothership/DAM/src/Resources/views/components/explorer/asset-card.blade.php';

/**
 * Renders the card's Blade source the way the server would for a user holding `permissions`:
 * the `@if (...)` / `@elseif` / `@else` chains over bouncer()->hasPermission(...) are evaluated (they are never
 * nested in this file), so the root element carries exactly one @click, as it does on the real page.
 */
function blade(source,permissions){
    const out=[];let emitting=true,matched=false,inside=false;
    // A condition combines permission checks with ||, && and parentheses, nothing else.
    const holds=condition=>{
        const expression=condition.replace(/bouncer\(\)->hasPermission\('([^']+)'\)/g,(_,key)=>String(permissions.includes(key)));
        assert.match(expression,/^[\s()|&!]*(?:(?:true|false)[\s()|&!]*)+$/,`unsupported card condition: ${condition}`);
        return Function(`return (${expression});`)();
    };
    for(const line of source.split('\n')){
        const branch=line.match(/^\s*@(if|elseif)\s*\((.*bouncer\(\)->hasPermission.*)\)\s*$/);
        if(branch){
            assert.equal(inside,branch[1]==='elseif','permission chains in the card are not nested');
            if(branch[1]==='if'){inside=true;matched=false;}
            const any=holds(branch[2]);
            emitting=!matched&&any;matched=matched||emitting;continue;
        }
        if(/^\s*@else\s*$/.test(line)){assert.ok(inside);emitting=!matched;matched=true;continue;}
        if(/^\s*@endif\s*$/.test(line)){assert.ok(inside);inside=false;emitting=true;continue;}
        if(emitting)out.push(line);
    }
    // A permission echo such as `{{ bouncer()->hasPermission('key') ? '1' : '0' }}` renders its chosen literal.
    // A Blade comment ({{-- ... --}}) is dropped by the server; left in, Vue reads it as an interpolation and fails to compile.
    return out.join('\n').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/(?<!@)\{\{\s*bouncer\(\)->hasPermission\('([^']+)'\)\s*\?\s*'([^']*)'\s*:\s*'([^']*)'\s*\}\}/g,(_,key,yes,no)=>permissions.includes(key)?yes:no).replace(/(?<!@)\{\{\s*mothership_asset\([\s\S]*?\}\}/g,'/fixture.svg').replace(/@js\(trans\('[^']*'\)\)/g,'"Translation"').replace(/@lang\('[^']*'\)/g,'Translation').replace(/\{\{\s*__\('[^']*'\)\s*\}\}/g,'Translation').replace(/@\{\{/g,'{{').replace(/@@/g,'@');
}
function card(permissions){
    const source=fs.readFileSync(path.join(root,cardFile),'utf8');
    const template=blade(source.match(/<script type="text\/x-template" id="v-dam-explorer-asset-card-template">([\s\S]*?)<\/script>/)[1],permissions);
    const script=blade(source.match(/<script type="module">([\s\S]*?)<\/script>/)[1],permissions);
    assert.match(script,/app\.component\('v-dam-explorer-asset-card'/);
    return {template,script};
}

/** Mounts the real card template and component in Chromium; every emitted open event is reported to Node. */
async function mount(browser,{asset,permissions}){
    const page=await browser.newPage();const emitted=[];
    await page.exposeFunction('reportEmit',(mode,id)=>emitted.push([mode,id]));
    await page.route('http://card.test/**',route=>route.fulfill({contentType:'text/html',body:'<div id="app"><v-dam-explorer-asset-card :asset="asset" tab-id="tab-1" @edit="id=>report(\'edit\',id)" @preview="id=>report(\'preview\',id)" @open-stack="payload=>report(\'open-stack\',payload.asset.id)"></v-dam-explorer-asset-card></div>'}));
    await page.goto('http://card.test/explorer');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.evaluate(({template,script,asset})=>{
        const el=document.createElement('script');el.type='text/x-template';el.id='v-dam-explorer-asset-card-template';el.textContent=template;document.body.append(el);
        window.MothershipFavorites={has:async()=>false,toggle:async()=>true};
        const app=Vue.createApp({data:()=>({asset}),methods:{report:(mode,id)=>window.reportEmit(mode,id)}});
        app.config.globalProperties.$emitter={on(){},off(){},emit(){}};
        app.directive('dam-thumbnail',{});app.directive('dam-custom-thumbnail-drop',{});
        new Function('app',script)(app);app.mount('#app');
    },{...card(permissions),asset});
    await page.locator('[data-dam-asset-open]').waitFor();
    return {page,emitted};
}
const ordinary={id:41,file_name:'poster.png',file_type:'image',extension:'png',path:'/fixture.png'};
// The card's file-name line: an inert descendant, so a click there only opens the asset by bubbling to the root
// handler. The overlay buttons emit edit/preview themselves and would let a broken root binding pass unnoticed.
const body=(page,name)=>page.locator('[data-dam-asset-open] p',{hasText:name});
const browserTest=process.env.MOTHERSHIP_VUE_RUNTIME_PATH&&process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip;
const launch=()=>require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});

browserTest('clicking an ordinary explorer card emits the permitted open mode immediately and stays on the page',async()=>{
    const browser=await launch();
    try{
        for(const [permissions,mode] of [[['dam.asset.edit','dam.asset.view'],'preview'],[['dam.asset.view'],'preview'],[['dam.asset.edit'],'edit']]){
            const {page,emitted}=await mount(browser,{asset:ordinary,permissions});
            await body(page,'poster.png').click();
            // The emit happens inside the click handler itself: no timer, request or navigation sits in front of it.
            // One round trip lets the exposed-function call land in Node before the assertion.
            await page.evaluate(()=>null);
            assert.deepEqual(emitted,[[mode,41]]);
            assert.equal(page.url(),'http://card.test/explorer');
            await page.close();
        }
    }finally{await browser.close();}
});

browserTest('a card rendered for a user with neither view nor edit permission has no open binding',async()=>{
    const browser=await launch();
    try{
        const {page,emitted}=await mount(browser,{asset:ordinary,permissions:[]});
        await body(page,'poster.png').click();
        await page.evaluate(()=>null);
        assert.deepEqual(emitted,[]);
        assert.equal(page.url(),'http://card.test/explorer');
    }finally{await browser.close();}
});

// Three files under one entry. A click on the whole card used to open the chooser, and most people closed it again.
const stack={...ordinary,id:52,file_name:'campaign.png',attachment_count:3};

browserTest('a stack card opens its main file like any other card, and its count opens the chooser',async()=>{
    const browser=await launch();
    try{
        for(const [permissions,mode] of [[['dam.asset.view','dam.asset.files.index'],'preview'],[['dam.asset.edit','dam.asset.files.index'],'edit']]){
            const {page,emitted}=await mount(browser,{asset:stack,permissions});
            await body(page,'campaign.png').click();
            await page.evaluate(()=>null);
            assert.deepEqual(emitted,[[mode,52]],'the card opens the main file, not the chooser');
            // The keyboard's way in is in the Tab order on a stack too, and opens the same thing.
            const open=page.locator('[data-dam-asset-open-button]');
            assert.equal(await open.getAttribute('tabindex'),null);
            assert.equal(await open.getAttribute('aria-hidden'),null);
            await open.focus();
            await page.keyboard.press('Enter');
            await page.evaluate(()=>null);
            assert.deepEqual(emitted,[[mode,52],[mode,52]]);
            // The count is the way into the stack's files, and a click on it does not open the file as well.
            await page.locator('[data-dam-stack-open]').click();
            await page.evaluate(()=>null);
            assert.deepEqual(emitted,[[mode,52],[mode,52],['open-stack',52]]);
            assert.equal(await page.locator('[data-dam-stack-open-main]').count(),0,'the card itself is the way to the main file');
            await page.close();
        }
        // A role that may not list a stack's files gets the read-only count and the same click on the card.
        const {page,emitted}=await mount(browser,{asset:stack,permissions:['dam.asset.view']});
        assert.equal(await page.locator('[data-dam-stack-open]').count(),0);
        assert.equal(await page.locator('[data-dam-stack-indicator]').count(),1);
        await body(page,'campaign.png').click();
        await page.evaluate(()=>null);
        assert.deepEqual(emitted,[['preview',52]]);
    }finally{await browser.close();}
});

browserTest('a card shows its other attached versions beside the type badge, folding past three into +N',async()=>{
    const browser=await launch();
    try{
        const chips=async versions=>{
            const {page}=await mount(browser,{asset:{...ordinary,versions},permissions:['dam.asset.view']});
            const labels=await page.locator('[data-dam-version-chip]').allTextContents();
            const titles=await page.locator('[data-dam-version-chip]').evaluateAll(els=>els.map(el=>el.title));
            await page.close();
            return {labels:labels.map(label=>label.trim()),titles};
        };
        const three=[{kind:'optimized',label:'OPT'},{kind:'svg',label:'SVG'},{kind:'type',label:'PSD'}];
        assert.deepEqual((await chips(three)).labels,['OPT','SVG','PSD']);
        const folded=await chips([...three,{kind:'type',label:'ZIP'}]);
        assert.deepEqual(folded.labels,['OPT','SVG','+2']);
        assert.match(folded.titles[2],/PSD, ZIP$/);
        assert.deepEqual((await chips(undefined)).labels,[]);
    }finally{await browser.close();}
});
