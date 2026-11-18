const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');

// The Assign Assets picker's folder tree names the set the grid shows: the breadcrumb is the
// picker's title and 'data-grid:filter' scopes the grid. With several top-level folders it once
// drew only the first, titled the picker after it and left the grid showing every folder.
function loadSource() {
    const source=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/components/asset/picker/directory-tree.blade.php'),'utf8')
        .replace(/@js\(trans\('[^']*all-folders'\)\)/g,'"All folders"')
        .replace(/@lang\('[^']*all-folders'\)/g,'All folders')
        .replace(/@lang\('[^']*'\)/g,'Translation')
        .replace(/@\{\{/g,'{{')
        .replace(/\{\{\s*route\(['"]([^'"]+)['"][^}]*\}\}/g,(m,name)=>'/'+name+'/:id');
    const templates=[...source.matchAll(/<script\s+type="text\/x-template"\s+id="([^"]+)"\s*>([\s\S]*?)<\/script>/g)].map(m=>({id:m[1],html:m[2]}));
    const scripts=[...source.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map(m=>m[1]);

    return {templates,scripts};
}

async function mount(page,{templates,scripts},roots) {
    await page.setContent('<div id="app"><v-directory-tree></v-directory-tree></div>');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_GLOBAL_PATH});
    await page.evaluate(({templates,scripts,roots})=>{
        for (const {id,html} of templates) {
            const el=document.createElement('script');el.type='text/x-template';el.id=id;el.textContent=html;document.body.append(el);
        }
        const app=Vue.createApp({});
        const handlers={};
        window.emitted=[];
        app.config.globalProperties.$emitter={
            on(name,fn){(handlers[name]=handlers[name]||[]).push(fn);},
            emit(name,data){window.emitted.push({name,data});(handlers[name]||[]).forEach(fn=>fn(data));},
        };
        window.emitter=app.config.globalProperties.$emitter;
        app.config.globalProperties.$axios={
            get:async()=>({data:{data:JSON.parse(JSON.stringify(roots))}}),
            post:async()=>({data:{data:{}}}),
        };
        app.component('v-asset-count-badge',{props:['count'],template:'<span>({{ count }})</span>'});
        for (const script of scripts) new Function('app',script)(app);
        app.mount('#app');
    },{templates,scripts,roots});
    await page.locator('[data-dir-id]').first().waitFor();
}

const last=(page,name)=>page.evaluate(n=>JSON.parse(JSON.stringify(window.emitted.filter(e=>e.name===n).at(-1)?.data??null)),name);
const folder=(id,name,children=[])=>({id,name,children,has_children:children.length>0});

async function withPage(fn) {
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {await fn(await browser.newPage());} finally {await browser.close();}
}

test('several top-level folders: every one is listed and the title, filter and selection agree',async()=>withPage(async page=>{
    await mount(page,loadSource(),[folder(1,'Spring Campaign'),folder(2,'Single Asset'),folder(4,'Archive',[folder(5,'2025')])]);

    assert.deepEqual(await page.locator('.tree-container > [data-dir-id]').evaluateAll(els=>els.map(e=>e.dataset.dirId)),['all','1','2','4']);
    // Nothing is chosen at first: the grid is unscoped, so the title is "All folders".
    assert.equal(await last(page,'current-directory'),null);
    assert.deepEqual(await last(page,'picker:breadcrumb'),[{id:null,name:'All folders'}]);
    assert.equal(await last(page,'data-grid:filter'),null);
    assert.equal(await page.locator('[data-dir-id="all"] .font-semibold').count(),1);

    await page.locator('[data-dir-id="4"]').click();
    assert.equal((await last(page,'current-directory')).id,4);
    assert.deepEqual(await last(page,'picker:breadcrumb'),[{id:null,name:'All folders'},{id:4,name:'Archive'}]);
    assert.deepEqual(await last(page,'data-grid:filter'),{column:{column:'directory_id',index:'directory_id'},value:[4]});

    // An upload into a loaded descendant of a later root reveals it (picker-modal onUploadsRefresh),
    // and the title, current folder and filter all move to it together.
    await page.evaluate(()=>window.emitter.emit('dam:reveal-directory',{id:5}));
    await page.waitForFunction(()=>window.emitted.some(e=>e.name==='current-directory'&&e.data?.id===5));
    assert.deepEqual(await last(page,'picker:breadcrumb'),[{id:null,name:'All folders'},{id:4,name:'Archive'},{id:5,name:'2025'}]);
    assert.deepEqual(await last(page,'data-grid:filter'),{column:{column:'directory_id',index:'directory_id'},value:[5]});

    // The "All folders" crumb returns to the unscoped grid.
    await page.evaluate(()=>window.emitter.emit('picker:navigate-directory',{id:null}));
    assert.equal(await last(page,'current-directory'),null);
    assert.deepEqual(await last(page,'picker:breadcrumb'),[{id:null,name:'All folders'}]);
    assert.deepEqual(await last(page,'data-grid:filter'),{column:{column:'directory_id',index:'directory_id'},value:[]});
}));

test('a single Root is chosen at once and no "All folders" row appears',async()=>withPage(async page=>{
    await mount(page,loadSource(),[folder(1,'Root',[folder(2,'Spring Campaign')])]);

    assert.equal(await page.locator('[data-dir-id="all"]').count(),0);
    assert.equal((await last(page,'current-directory')).id,1);
    assert.deepEqual(await last(page,'picker:breadcrumb'),[{id:1,name:'Root'}]);
}));
