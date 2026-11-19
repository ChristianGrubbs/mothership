const {test}=require('node:test');
const assert=require('node:assert/strict');
const {homeApp,homeAnswersSource,homeScripts}=require('./support/home-fixture.cjs');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const homeSource=homeScripts.at(-1);

const markup=homeApp({tag:'main'});
const row='[data-grid] [data-board-rows] .hm-frow';
// Calendar shows once Home has read a layout that has it; Home starts every root with it hidden.

async function homePage(browser,layoutDelay){
    const page=await browser.newPage({viewport:{width:1200,height:900},reducedMotion:'reduce'});
    await page.setContent(markup);
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(delay=>{
        const response=data=>new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});
        const catalog=[{id:'looper',title:'Video Loop Refiner',description:'Build a seamless loop.',icon:'looper',kind:'tool',settings:{},url:'https://home.test/tools/looper/index.html'},{id:'calendar',title:'Calendar',description:'Events.',icon:'calendar',kind:'native',settings:{}}];
        const landed={status:'landed',source:'craft',producer:'blender',title:'Hull study',files:2,bytes:1024,at:new Date().toISOString(),previews:[]};
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
            if(address.endsWith('/summary'))return response(window.__homeSummary({board:{rows:[landed],more:0,truncated:false,cargo:[],docked:[]}}));
            return response(window.__homeAnswer(address)??{});
        };
    },layoutDelay);
    for(const content of homeScripts)await page.addScriptTag({content});
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

// Waits until the board has answered and Calendar is offered, which is when Home has finished starting.
const settled=page=>page.waitForFunction(selector=>document.querySelector(selector)&&document.querySelector('[data-calendar-open]')?.hidden===false&&document.querySelector('[data-board-rows] .hm-sr')?.textContent.includes('Hull study'),row);

browserTest('a root rebuilt after Home rendered gets one live board and one Docked card, not copies',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await homePage(browser,'now');
        await settled(page);
        await remount(page);
        await page.waitForFunction(()=>window.__layoutCalls===2);
        await settled(page);
        assert.equal(await page.locator(row).count(),1);
        // The Docked card is painted again inside the foot Home keeps, not beside a copy of the old paint.
        assert.equal(await page.locator('[data-docked] h2').count(),1);
        assert.equal(await page.locator('[data-docked] [data-phone-open]').count(),1);
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
// started marker. The rebuilt root never started and Home stayed empty.
browserTest('Home starts the root an SPA mount rebuilt after Home had already started',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await homePage(browser,'hold');
        await page.waitForFunction(()=>window.__layoutCalls===1);

        // The first request is still pending: the rebuilt root must start its own.
        await remount(page);
        await page.waitForFunction(()=>window.__layoutCalls===2,null,{timeout:5000});
        await settled(page);

        // The replaced root's late response must not touch the rebuilt one.
        await page.evaluate(()=>window.__releaseFirstLayout());
        await page.evaluate(()=>new Promise(resolve=>setTimeout(resolve,50)));
        assert.equal((await page.locator('[data-status]').textContent()).trim(),'');
        assert.equal(await page.locator('[data-mothership-home]').count(),1);
        assert.equal(await page.locator(row).count(),1);
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
        await settled(page);
        assert.equal(await page.locator(row).count(),1);
        await page.close();
    }finally{
        await browser.close();
    }
});
