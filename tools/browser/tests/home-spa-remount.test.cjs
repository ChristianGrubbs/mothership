const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const stateSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home-state.js'),'utf8');
const homeSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home.js'),'utf8');

// A full-access account: the page then draws one card (File storage) and one tool tile.
const markup=homeApp({superuser:true,tag:'main'});
const card='[data-grid] [data-slot="status"] [data-id="storage"]';
const tile='[data-grid] [data-section="apps"] [data-open="looper"]';

async function homePage(browser,layoutDelay){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    await page.setContent(markup);
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(delay=>{
        const response=data=>new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});
        const catalog=[{id:'storage',title:'Storage',description:'Available storage',icon:'storage',kind:'native',settings:{}},{id:'looper',title:'Video Loop Refiner',description:'Build a seamless loop.',icon:'looper',kind:'tool',settings:{},url:'https://home.test/tools/looper/index.html'}];
        window.__layoutCalls=0;
        window.fetch=async url=>{
            const address=String(url);
            if(address.endsWith('/layout')){
                const call=++window.__layoutCalls;
                const body=()=>response({catalog,widgets:catalog.map(({id})=>({id,settings:{}})),links:[]});
                // 'hold' keeps the first response pending until the test releases it.
                if(delay==='hold'&&call===1)return new Promise(resolve=>window.__releaseFirstLayout=()=>resolve(body()));
                return body();
            }
            if(address.endsWith('/data/storage'))return response({available:true,free:500,total:1000});
            return response(window.__homeAnswer(address)??{});
        };
    },layoutDelay);
    await page.addScriptTag({content:stateSource});
    await page.addScriptTag({content:homeSource});
    return page;
}

// What a visit's mount does: rebuild #app from the markup as it now stands, then report success.
async function remount(page){
    await page.evaluate(()=>{
        const app=document.querySelector('#app');
        const template=document.createElement('template');
        template.innerHTML=app.outerHTML;
        app.replaceWith(template.content.firstElementChild);
        document.dispatchEvent(new CustomEvent('mothership:navigate:success',{detail:{url:'/admin/home'},bubbles:true}));
    });
}

browserTest('a root rebuilt after Home rendered gets one live card and one live tile, not copies',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await homePage(browser,'now');
        await page.waitForFunction(selectors=>selectors.every(selector=>document.querySelector(selector)),[card,tile]);
        await page.getByText('free space left for files').waitFor();
        await remount(page);
        await page.waitForFunction(selectors=>window.__layoutCalls===2&&selectors.every(selector=>document.querySelector(selector))&&!document.querySelector('[data-status]').textContent.trim(),[card,tile]);
        assert.equal(await page.locator(card).count(),1);
        assert.equal(await page.locator(tile).count(),1);
        assert.equal(await page.locator('[data-section="apps"] [data-tools-end]').count(),1);
        // The rebuilt card is filled by this start, never left as the copied shell.
        await page.getByText('free space left for files').waitFor();
        assert.equal(await page.getByText('free space left for files').count(),1);
        // The rebuilt root's own controls are live.
        await page.locator('[data-phone-open]').click();
        assert.equal(await page.locator('[data-phone-dialog]').evaluate(node=>node.open),true);
        await page.close();
    }finally{
        await browser.close();
    }
});

// 2026-10-12, live: an SPA visit to Home ran home.js, its start() fired while the visit was still
// injecting scripts, and the app mount then rebuilt #app from markup that already carried the
// started marker. The rebuilt root never started and Home stayed on "Loading your Home…".
browserTest('Home starts the root an SPA mount rebuilt after Home had already started',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await homePage(browser,'hold');
        await page.waitForFunction(()=>window.__layoutCalls===1);

        // The first request is still pending: the rebuilt root must start its own.
        await remount(page);
        await page.waitForFunction(()=>window.__layoutCalls===2,null,{timeout:5000});
        await page.waitForFunction(selector=>document.querySelector(selector),card,{timeout:5000});

        // The replaced root's late response must not touch the rebuilt one.
        await page.evaluate(()=>window.__releaseFirstLayout());
        await page.evaluate(()=>new Promise(resolve=>setTimeout(resolve,50)));
        assert.equal((await page.locator('[data-status]').textContent()).trim(),'');
        assert.equal(await page.locator('[data-mothership-home]').count(),1);
        assert.equal(await page.locator(card).count(),1);
        assert.equal(await page.locator(tile).count(),1);
        await page.close();
    }finally{
        await browser.close();
    }
});

// Every SPA visit re-runs home.js, so two copies share one page; only one may start a given root.
browserTest('a second copy of home.js does not start a root the first already started',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await homePage(browser,'hold');
        await page.waitForFunction(()=>window.__layoutCalls===1);
        await page.addScriptTag({content:homeSource});
        await page.evaluate(()=>new Promise(resolve=>setTimeout(resolve,50)));
        assert.equal(await page.evaluate(()=>window.__layoutCalls),1);
        await page.evaluate(()=>window.__releaseFirstLayout());
        await page.waitForFunction(selector=>document.querySelector(selector),card);
        assert.equal(await page.locator(card).count(),1);
        assert.equal(await page.locator(tile).count(),1);
        await page.close();
    }finally{
        await browser.close();
    }
});
