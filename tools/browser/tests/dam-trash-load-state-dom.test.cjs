const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
// Mounts the real Trash page component (P2 S4) with a stubbed $axios whose list answers come from `answers` in
// order: an object is a page of the list, `'fail'` rejects. Blade output is stubbed to the translation key or route
// name, the page-header component and Blade comments are dropped, and the shared empty-state part is stood in for by
// its title key, so the test reads the template's own states. <v-load-error> is the app-wide failed-load component;
// a stand-in with the same role, title and retry event takes its place here.
async function mountTrash(page,answers){
    const source=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/trash/index.blade.php'),'utf8');
    const blade=s=>s
        .replace(/\{\{--[\s\S]*?--\}\}/g,'')
        .replace(/<x-admin::layouts\.page-header[\s\S]*?\/>/g,'')
        .replace(/<x-admin::list\.empty-state([\s\S]*?)\/>/g,(_,attrs)=>`<p data-empty-state>${(attrs.match(/:title="trans\('([^']+)'/)||[])[1]}</p>`)
        .replace(/@js\(trans\('([^']+)'\)\)/g,(_,key)=>JSON.stringify(key))
        .replace(/@js\(route\('([^']+)'(?:, ':id')?\)\)/g,(_,name)=>JSON.stringify('/'+name+'/:id'))
        .replace(/@lang\('([^']+)'\)/g,'$1');
    const template=blade(source.split('<script type="text/x-template" id="v-dam-trash-template">')[1].split('</script>')[0]);
    const script=blade(source.split('<script type="module">')[1].split('</script>')[0]);
    await page.setContent('<div id="app"><v-dam-trash></v-dam-trash></div>');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_GLOBAL_PATH});
    await page.evaluate(({template,script,answers})=>{
        window.flashes=[];
        window.answers=answers;
        const app=Vue.createApp({});
        app.config.globalProperties.$axios={
            get:async()=>{
                const next=window.answers.shift();
                if(next==='fail'||next===undefined)throw new Error('list failed');
                return {data:next};
            },
            post:async()=>({data:{message:'dam::app.admin.dam.trash.restored'}}),
            delete:async()=>({data:{message:'dam::app.admin.dam.trash.deleted'}}),
        };
        app.config.globalProperties.$emitter={emit(name,payload){window.flashes.push({name,payload});}};
        app.component('v-load-error',{props:['title','message','retryable'],emits:['retry'],template:'<div role="alert"><p>{{ title }}</p><button type="button" data-load-error-retry @click="$emit(\'retry\')">retry</button></div>'});
        const el=document.createElement('script');el.type='text/x-template';el.id='v-dam-trash-template';el.textContent=template;document.body.append(el);
        new Function('app',script)(app);app.mount('#app');
    },{template,script,answers});
}
const row=(id,name)=>({id,name,file_name:name,location:'Root/Campaign',deleted_by:'Review Admin',deleted_at:'2026-10-07T15:25:00Z',purge_after:'2026-11-06T15:25:00Z',can_delete:true});
const listPage=(assets)=>({assets,meta:{current_page:1,last_page:1,total:assets.length}});
async function withPage(run){
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {await run(await browser.newPage());} finally {await browser.close();}
}
// Visual review 2026-10-07 (P2 S4): a list that failed to load said "Trash is empty", which misstates the trash.
test('a first load that fails says so and never claims the trash is empty',async()=>{
    await withPage(async page=>{
        await mountTrash(page,['fail']);
        await page.getByRole('alert').filter({hasText:'dam::app.admin.dam.trash.load-failed'}).waitFor();
        assert.equal(await page.getByText('dam::app.admin.dam.trash.empty').count(),0);
        assert.ok(await page.evaluate(()=>flashes.some(f=>f.payload?.type==='error')),'an error flash is raised too');
    });
});
test('an empty trash says it is empty, without the load-failed alert',async()=>{
    await withPage(async page=>{
        await mountTrash(page,[listPage([])]);
        await page.getByText('dam::app.admin.dam.trash.empty').waitFor();
        assert.equal(await page.getByRole('alert').count(),0);
    });
});
test('a refresh that fails after an action keeps the rows already shown',async()=>{
    await withPage(async page=>{
        await mountTrash(page,[listPage([row(1,'hero-banner.jpg'),row(2,'logo.svg')]),'fail']);
        await page.getByText('hero-banner.jpg').waitFor();
        await page.getByRole('button',{name:'dam::app.admin.dam.trash.restore'}).first().click();
        await page.waitForFunction(()=>flashes.some(f=>f.payload?.type==='error'));
        // The restore itself succeeded, so its row leaves at once (G1-12); the failed refresh keeps the rest.
        assert.equal(await page.getByText('hero-banner.jpg').count(),0);
        assert.equal(await page.getByText('logo.svg').count(),1);
        assert.equal(await page.getByText('dam::app.admin.dam.trash.empty').count(),0);
        assert.equal(await page.getByRole('alert').count(),0);
    });
});

// Redesign phase 7: a failed first load offers Try again in the list's own place, and it recovers the list.
test('a failed first load can be retried and then shows the rows',async()=>{
    await withPage(async page=>{
        await mountTrash(page,['fail',listPage([row(1,'hero-banner.jpg')])]);
        await page.getByRole('alert').waitFor();
        await page.locator('[data-load-error-retry]').click();
        await page.getByText('hero-banner.jpg').waitFor();
        assert.equal(await page.getByRole('alert').count(),0);
    });
});
