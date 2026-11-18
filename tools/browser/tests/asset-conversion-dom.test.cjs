const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
// Mounts the real conversion component with a stubbed $axios whose state answers with the given allowance.
async function mountConversion(page,{allowance}){
    const source=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/conversion.blade.php'),'utf8');
    const template=source.split('<script type="text/x-template" id="v-dam-asset-conversion-template">')[1].split('</script>')[0].replaceAll('@{{','{{');
    const script=source.split('<script type="module">')[1].split('</script>')[0];
    // Only the positioning utilities the menu relies on, and the same flex row the preview toolbar uses, so the
    // menu's anchor is exercised the way it is on the page (Tailwind itself is not loaded here).
    await page.setContent('<style>.relative{position:relative}.absolute{position:absolute}.right-0{right:0}.left-0{left:0}.top-full{top:100%}.w-48{width:12rem}.contents{display:contents}</style>'
        +'<div id="app" style="position:relative;height:500px;padding-left:24px"><div style="display:flex;align-items:center;gap:4px;width:max-content"><v-dam-asset-conversion :asset-id="5" state-url="/state" store-url="/store" files-url="/asset/5?files" /><button type="button">next control</button></div></div>');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_GLOBAL_PATH});
    await page.evaluate(({template,script,allowance})=>{
        let status=null;
        window.calls=[];
        const app=Vue.createApp({});
        app.config.globalProperties.$axios={
            get:async()=>({data:{ready:true,result:status,allowance}}),
            post:async(url,data)=>{window.calls.push(data);status={status:'processing',format:data.format};return {data:{result:status}};},
        };
        window.complete=()=>{status={status:'completed',format:'pdf'};};
        app.config.globalProperties.$emitter={emit(){}};
        const el=document.createElement('script');el.type='text/x-template';el.id='v-dam-asset-conversion-template';el.textContent=template;document.body.append(el);
        new Function('app',script)(app);app.mount('#app');
    },{template,script,allowance});
    // setContent uses an opaque origin; provide the browser UUID API for the test only.
    await page.evaluate(()=>{if(!crypto.randomUUID)crypto.randomUUID=()=> '00000000-0000-4000-8000-000000000005';});
}
test('format popup, image overlay and Files completion work in a browser',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mountConversion(page,{allowance:{unlimited:true}});
        await page.getByRole('button',{name:'Convert'}).click();
        // The menu is wider than its button; it must open from the button's left edge, never run off to the left
        // (operator screenshot 2026-09-15: a right-anchored menu was clipped by the preview panel).
        const [buttonBox,menuBox]=await Promise.all([page.getByRole('button',{name:'Convert'}).boundingBox(),page.locator('[data-dam-conversion-menu]').boundingBox()]);
        assert.ok(menuBox.x>=buttonBox.x-1,`menu starts at ${menuBox.x}, button at ${buttonBox.x}`);
        assert.ok(menuBox.width>buttonBox.width,'the menu is wider than the button, which is why the anchor matters');
        await page.getByRole('button',{name:'PDF',exact:true}).click();
        await page.locator('[data-dam-conversion-progress]').waitFor();
        assert.equal(await page.locator('[data-dam-conversion-menu]').count(),0);
        assert.equal(await page.evaluate(()=>calls.length),1);
        assert.equal(await page.evaluate(()=>calls[0].format),'pdf');
        await page.evaluate(()=>complete());
        await page.getByRole('link',{name:'PDF saved to Files'}).waitFor();
        assert.equal(await page.locator('[data-dam-conversion-progress]').count(),0);
    } finally {await browser.close();}
});
// UI review 2026-10-06: at 0 the menu said "0 conversions available" yet both formats stayed clickable,
// and a click only came back as the server's 403.
test('an exhausted allowance disables the formats and says why',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mountConversion(page,{allowance:{unlimited:false,remaining:0}});
        await page.getByRole('button',{name:'Convert'}).click();
        for(const format of ['SVG','PDF']){
            assert.equal(await page.getByRole('button',{name:format,exact:true}).isDisabled(),true,`${format} is disabled`);
        }
        await page.locator('[data-dam-conversion-exhausted]').waitFor();
        await page.getByRole('button',{name:'PDF',exact:true}).click({force:true});
        assert.equal(await page.evaluate(()=>calls.length),0);
    } finally {await browser.close();}
});
