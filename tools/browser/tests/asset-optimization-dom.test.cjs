const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');

function loadTemplate() {
    const source=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/optimization.blade.php'),'utf8');
    const template=source.split('<script type="text/x-template" id="v-dam-asset-optimization-template">')[1].split('</script>')[0].replaceAll('@{{','{{');
    const script=source.split('<script type="module">')[1].split('</script>')[0];

    return {template,script};
}

async function mount(page,{template,script},extension,{ready=true,initial=null}={}) {
    await page.setContent('<style>.relative{position:relative}.absolute{position:absolute}.left-0{left:0}.top-full{top:100%}.w-64{width:16rem}.contents{display:contents}</style>'
        +`<div id="app" style="position:relative;height:600px;padding-left:24px"><v-dam-asset-optimization :asset-id="7" extension="${extension}" state-url="/state" store-url="/store" files-url="/asset/7?files" /></div>`);
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_GLOBAL_PATH});
    await page.evaluate(({template,script,ready,initial})=>{
        let status=initial;
        window.calls=[];
        const app=Vue.createApp({});
        app.config.globalProperties.$axios={
            get:async()=>({data:{ready,result:status}}),
            post:async(url,data)=>{window.calls.push(data);status={status:'processing'};return {data:{result:status}};},
        };
        window.complete=(outcome,extra)=>{status=Object.assign({status:'completed',outcome},extra);};
        app.config.globalProperties.$emitter={emitted:[],emit(name,data){this.emitted.push({name,data});}};
        window.emitter=app.config.globalProperties.$emitter;
        const el=document.createElement('script');el.type='text/x-template';el.id='v-dam-asset-optimization-template';el.textContent=template;document.body.append(el);
        window.__html=document.getElementById('app').innerHTML;window.__app=app;
        new Function('app',script)(app);app.mount('#app');
    },{template,script,ready,initial});
    await page.evaluate(()=>{if(!crypto.randomUUID)crypto.randomUUID=()=> '00000000-0000-4000-8000-000000000007';});
}

test('image controls, request payload and savings text work in a browser',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mount(page,loadTemplate(),'png');
        await page.getByRole('button',{name:'Optimize ▾'}).click();
        assert.equal(await page.locator('[data-dam-optimize-scale]').count(),3);
        assert.equal(await page.locator('[data-dam-optimize-adaptive]').count(),1);
        await page.locator('[data-dam-optimize-scale][value="0.5"]').check();
        await page.locator('[data-dam-optimize-adaptive]').check();
        await page.getByRole('button',{name:'Optimize',exact:true}).click();
        await page.locator('[data-dam-optimize-progress]').waitFor();
        assert.equal(await page.locator('[data-dam-optimize-menu]').count(),0);
        const call=await page.evaluate(()=>calls[0]);
        assert.equal(call.scale,'0.5');
        assert.equal(call.adaptive,'1');
        await page.evaluate(()=>complete('optimized',{old_bytes:1000,new_bytes:250,output_extension:'jpg'}));
        await page.getByRole('link',{name:/1000 B.*250 B.*−75%.*JPG saved to Files/}).waitFor();
        assert.equal(await page.locator('[data-dam-optimize-progress]').count(),0);
        const emitted=await page.evaluate(()=>window.emitter.emitted.some(e=>e.name==='dam-asset-files-updated'));
        assert.ok(emitted,'expected dam-asset-files-updated to be emitted on completion');
    } finally {await browser.close();}
});

test('video controls post the full option set',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mount(page,loadTemplate(),'mp4');
        await page.getByRole('button',{name:'Optimize ▾'}).click();
        await page.locator('[data-dam-optimize-compression][value="75"]').check();
        await page.locator('[data-dam-optimize-encoder][value="software"]').check();
        await page.locator('[data-dam-optimize-remove-audio]').check();
        await page.getByRole('button',{name:'Optimize',exact:true}).click();
        const call=await page.evaluate(()=>calls[0]);
        assert.equal(call.compression,'75');
        assert.equal(call.encoder,'software');
        assert.equal(call.scale,'1');
        assert.equal(call.remove_audio,'1');
    } finally {await browser.close();}
});

test('pdf has no options and reports no_reduction',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mount(page,loadTemplate(),'pdf');
        await page.getByRole('button',{name:'Optimize ▾'}).click();
        assert.equal(await page.locator('[data-dam-optimize-scale]').count(),0);
        await page.getByRole('button',{name:'Optimize',exact:true}).click();
        const call=await page.evaluate(()=>calls[0]);
        assert.deepEqual(Object.keys(call).sort(),['request_id']);
        await page.evaluate(()=>complete('no_reduction'));
        await page.getByText('Already optimized, no smaller copy made').waitFor();
    } finally {await browser.close();}
});

test('an unconfigured optimizer shows Optimize disabled with a plain reason (F-09)',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mount(page,loadTemplate(),'png',{ready:false});
        await page.locator('[data-dam-optimize-unavailable]').waitFor();
        assert.equal(await page.locator('[data-dam-optimize]').isDisabled(),true);
        assert.match(await page.locator('[data-dam-optimize-unavailable]').innerText(),/isn't set up on this server; ask an admin/);
    } finally {await browser.close();}
});

test('the queued status sits on an opaque-enough plate over a busy image, with the stalled notice (F-20)',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mount(page,loadTemplate(),'png',{initial:{status:'queued',message:"Still waiting for the optimizer to start — this usually means it isn't running."}});
        const plate=page.locator('[data-dam-optimize-plate]');
        await plate.waitFor();
        assert.match(await plate.innerText(),/Queued for optimization…/);
        assert.match(await plate.locator('[data-dam-optimize-stalled]').innerText(),/Still waiting for the optimizer to start/);
        // The page under test carries no Tailwind build, so assert the backing classes rather than computed paint.
        assert.match(await plate.getAttribute('class'),/\bbg-white\/90\b.*\brounded-xl\b|\brounded-xl\b.*\bbg-white\/90\b/);
        const box=await plate.boundingBox();
        assert.ok(box.width>150&&box.height>60,'the plate wraps the status text');
    } finally {await browser.close();}
});

test('a failed alert can be dismissed, and stays gone after a remount, while a new failure still shows (F-08)',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        // A real origin, so localStorage behaves as it does in the app (about:blank has none).
        await page.route('http://dam.test/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'}));
        await page.goto('http://dam.test/');
        const failed=(id)=>({id,status:'failed',outcome:null,message:"Optimization failed. Your original is unchanged. Try again.",updated_at:new Date().toISOString()});

        await mount(page,loadTemplate(),'png',{initial:failed('run-1')});
        const alert=page.getByRole('alert');
        await alert.waitFor();
        assert.match(await alert.innerText(),/Optimization failed\. Your original is unchanged\. Try again\./);
        const dismiss=page.locator('[data-dam-optimize-dismiss]');
        assert.equal(await dismiss.innerText(),'Dismiss ×');

        await dismiss.click();
        assert.equal(await page.getByRole('alert').count(),0,'the alert is gone once dismissed');
        assert.deepEqual(await page.evaluate(()=>JSON.parse(localStorage.getItem('dam-optimize-dismissed'))),['run-1']);

        await mount(page,loadTemplate(),'png',{initial:failed('run-1')});
        await page.locator('[data-dam-optimize]').waitFor();
        await page.waitForTimeout(300);
        assert.equal(await page.getByRole('alert').count(),0,'still gone after a remount');

        await mount(page,loadTemplate(),'png',{initial:failed('run-2')});
        await page.getByRole('alert').waitFor();
        assert.equal(await page.locator('[data-dam-optimize-dismiss]').count(),1,'a new failure shows with its own Dismiss');
    } finally {await browser.close();}
});

const failedResult=(id)=>({id,status:'failed',outcome:null,message:"Optimization failed. Your original is unchanged. Try again.",updated_at:new Date().toISOString(),stale:false});

async function realOrigin(browser) {
    const page=await browser.newPage();
    await page.route('http://dam.test/**',route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><body></body></html>'}));
    await page.goto('http://dam.test/');
    return page;
}

test('dismissing with the keyboard puts focus back on the Optimize trigger (F-08)',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await realOrigin(browser);
        await mount(page,loadTemplate(),'png',{initial:failedResult('k1')});
        await page.getByRole('alert').waitFor();
        await page.locator('[data-dam-optimize]').focus();
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(()=>document.activeElement?.hasAttribute('data-dam-optimize-dismiss')),true,'Tab reaches Dismiss');
        await page.keyboard.press('Enter');
        assert.equal(await page.getByRole('alert').count(),0);
        assert.equal(await page.evaluate(()=>document.activeElement?.hasAttribute('data-dam-optimize')),true,'focus returns to the Optimize trigger, not <body>');
    } finally {await browser.close();}
});

test('with the trigger disabled, dismissing moves focus to the nearest enabled toolbar control (F-08)',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await realOrigin(browser);
        await mount(page,loadTemplate(),'png',{ready:false,initial:failedResult('k2')});
        await page.getByRole('alert').waitFor();
        await page.evaluate(()=>{const b=document.createElement('button');b.id='sibling';b.textContent='Neighbour';document.getElementById('app').prepend(b);});
        assert.equal(await page.locator('[data-dam-optimize]').isDisabled(),true);
        await page.locator('[data-dam-optimize-dismiss]').focus();
        await page.keyboard.press('Enter');
        assert.equal(await page.getByRole('alert').count(),0);
        assert.equal(await page.evaluate(()=>document.activeElement?.id),'sibling');
    } finally {await browser.close();}
});

test('with storage blocked, a dismissed failure stays dismissed across a remount in the same page (F-08)',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await realOrigin(browser);
        await page.evaluate(()=>Object.defineProperty(window,'localStorage',{configurable:true,get(){throw new DOMException('denied','SecurityError');}}));
        await mount(page,loadTemplate(),'png',{initial:failedResult('k3')});
        await page.getByRole('alert').waitFor();
        await page.locator('[data-dam-optimize-dismiss]').click();
        assert.equal(await page.getByRole('alert').count(),0);

        // Same script run, the component mounted again: what a panel reopening in the SPA does.
        await page.evaluate(()=>{
            const first=window.__app;
            const definition=first.component('v-dam-asset-optimization');   // the one script run's component, registered again
            first.unmount();
            document.getElementById('app').innerHTML=window.__html;
            const second=Vue.createApp({});
            Object.assign(second.config.globalProperties,first.config.globalProperties);
            second.component('v-dam-asset-optimization',definition);
            second.mount('#app');
        });
        await page.locator('[data-dam-optimize]').waitFor();
        await page.waitForTimeout(300);
        assert.equal(await page.getByRole('alert').count(),0,'still dismissed after the remount');
    } finally {await browser.close();}
});
