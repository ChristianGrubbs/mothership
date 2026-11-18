const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const source=name=>fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js',name),'utf8');
const quickViewSource=source('home-system-quickview.js');
const stateSource=source('home-state.js');
const homeSource=source('home.js');
const cssSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/css/home.css'),'utf8');
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');
const GIB=1024**3;
const TOOL_ORIGIN='https://tool.test';

const metrics=(extra={})=>({available:true,collected_at:'2026-09-20T12:00:00+00:00',stale:false,cpu:{available:true,percent:41.6},memory:{available:true,used_bytes:24*GIB,total_bytes:64*GIB,percent:37.5},gpu:{available:true,percent:12.2},...extra});
const noMetrics={available:false,collected_at:null,stale:true,cpu:{available:false,percent:null},memory:{available:false,used_bytes:null,total_bytes:null,percent:null},gpu:{available:false,percent:null}};
const people=count=>({available:true,online_count:count,users:Array.from({length:count},(_,index)=>({id:index+1,name:index?`Person ${index}`:'Requester <b>',avatar_url:null,is_current:index===0}))});
const payload=(extra={})=>({server_time:'2026-09-20T12:00:01+00:00',poll_after_seconds:10,metrics:metrics(),presence:people(2),...extra});

async function withBrowser(run){
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{await run(browser);}finally{await browser.close();}
}

// Fake timers and a controllable `document.hidden`, installed before the module under test loads.
async function instrument(page){
    await page.evaluate(()=>{
        window.__timers=[];window.__hidden=false;
        window.setInterval=(callback,delay)=>{window.__timers.push({callback,delay,cleared:false});return window.__timers.length;};
        window.clearInterval=id=>{if(window.__timers[id-1])window.__timers[id-1].cleared=true;};
        Object.defineProperty(document,'hidden',{configurable:true,get:()=>window.__hidden});
        window.__tick=()=>window.__timers.filter(timer=>!timer.cleared).forEach(timer=>timer.callback());
        window.__setHidden=value=>{window.__hidden=value;document.dispatchEvent(new Event('visibilitychange'));};
    });
}

// A bare card driven by a queue of held requests so each response is released explicitly.
async function cardPage(browser,{reducedMotion=false}={}){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    await page.setContent('<article class="agh-card" data-id="system-quickview"><div class="agh-card-body"></div></article><article class="agh-card" data-id="other"><div class="agh-card-body"></div></article>');
    await page.addStyleTag({content:cssSource});
    await instrument(page);
    await page.addScriptTag({content:quickViewSource});
    await page.evaluate(({reducedMotion})=>{
        window.__calls=[];window.__clock=1789000000000;
        const request=path=>new Promise((resolve,reject)=>window.__calls.push({path,resolve,reject}));
        window.__view=window.MothershipSystemQuickView.create({root:document,request,intervalMs:10000,now:()=>window.__clock,reducedMotion});
        window.__settle=async(index,data)=>{window.__calls[index].resolve(data);await new Promise(done=>setTimeout(done,0));};
        window.__fail=async index=>{window.__calls[index].reject(new Error('offline'));await new Promise(done=>setTimeout(done,0));};
        window.__view.mount(document.querySelector('[data-id="system-quickview"]'));
    },{reducedMotion});
    return page;
}
const meter=(page,key)=>page.locator(`[data-qv-metric="${key}"] [role="meter"]`);

browserTest('renders CPU, memory and GPU as meters with memory used of total and one quiet timestamp',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    assert.deepEqual(await page.evaluate(()=>window.__calls.map(call=>call.path)),['/data/system-quickview']);
    await page.evaluate(data=>window.__settle(0,data),payload());
    for(const [key,value] of [['cpu','42'],['memory','38'],['gpu','12']]){
        assert.equal(await meter(page,key).getAttribute('aria-valuemin'),'0');
        assert.equal(await meter(page,key).getAttribute('aria-valuemax'),'100');
        assert.equal(await meter(page,key).getAttribute('aria-valuenow'),value);
    }
    assert.equal(await page.locator('[data-qv-metric="memory"] [data-qv-detail]').textContent(),'24.0 GB of 64.0 GB');
    assert.equal(await page.locator('[data-qv-updated]').count(),1);
    assert.match(await page.locator('[data-qv-updated]').textContent(),/^Updated /);
    assert.equal(await page.locator('[data-qv-updated]').getAttribute('aria-live'),null);
    assert.equal(await page.locator('[data-qv-updated]').evaluate(node=>Boolean(node.closest('[aria-live]'))),false);
    // Routine numbers stay outside every live region; only the dedicated status is polite.
    assert.equal(await page.locator('[aria-live]').count(),1);
    assert.equal(await page.locator('[data-qv-status]').getAttribute('aria-live'),'polite');
    assert.equal(await page.locator('[data-qv-status] [role="meter"], [role="meter"] [aria-live]').count(),0);
    assert.equal(await page.locator('[data-qv-status]').textContent(),'');
}));

browserTest('first-load unavailable metrics show no fake zero and leave presence intact',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    await page.evaluate(data=>window.__settle(0,data),payload({metrics:noMetrics}));
    for(const key of ['cpu','memory','gpu']){
        assert.equal(await meter(page,key).getAttribute('aria-valuenow'),null);
        assert.equal(await meter(page,key).getAttribute('aria-valuetext'),'Unavailable');
        assert.equal(await page.locator(`[data-qv-metric="${key}"] [data-qv-value]`).textContent(),'—');
    }
    assert.equal(await page.locator('[data-qv-count]').textContent(),'2 people online');
    assert.equal(await page.locator('[data-qv-status]').textContent(),'Studio load is unavailable right now.');
}));

browserTest('presence failure leaves metrics intact and announces only the availability change',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    await page.evaluate(data=>window.__settle(0,data),payload());
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(1,data),payload({presence:{available:false,online_count:0,users:[]}}));
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'42');
    assert.equal(await page.locator('[data-qv-count]').textContent(),'Who is online is unavailable right now.');
    assert.equal(await page.locator('[data-qv-status]').textContent(),'Who is online is unavailable right now.');
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(2,data),payload({metrics:metrics({cpu:{available:true,percent:77}})}));
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'77');
    assert.equal(await page.locator('[data-qv-status]').textContent(),'Live updates are back.');
    // A further routine update changes numbers only.
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(3,data),payload({metrics:metrics({cpu:{available:true,percent:12}})}));
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'12');
    assert.equal(await page.locator('[data-qv-status]').textContent(),'Live updates are back.');
}));

browserTest('stale and unavailable payloads retain the last valid metrics with stale styling',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    await page.evaluate(data=>window.__settle(0,data),payload());
    assert.equal(await page.locator('[data-qv-metric].is-stale').count(),0);
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(1,data),payload({metrics:metrics({stale:true})}));
    assert.equal(await page.locator('[data-qv-metric].is-stale').count(),3);
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'42');
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(2,data),payload({metrics:noMetrics}));
    assert.equal(await page.locator('[data-qv-metric].is-stale').count(),3);
    assert.equal(await meter(page,'gpu').getAttribute('aria-valuenow'),'12');
    assert.equal(await page.locator('[data-qv-metric="memory"] [data-qv-detail]').textContent(),'24.0 GB of 64.0 GB');
    assert.equal(await page.locator('[data-qv-count]').textContent(),'2 people online');
}));

browserTest('an endpoint failure degrades and retries without discarding either domain',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    await page.evaluate(data=>window.__settle(0,data),payload());
    await page.evaluate(()=>window.__tick());
    await page.evaluate(()=>window.__fail(1));
    assert.equal(await page.locator('[data-qv].is-degraded').count(),1);
    assert.equal(await page.locator('[data-qv-status]').textContent(),'Live updates paused. Retrying…');
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'42');
    assert.equal(await page.locator('[data-qv-metric].is-stale').count(),3);
    assert.equal(await page.locator('[data-qv-count]').textContent(),'2 people online');
    await page.evaluate(()=>window.__tick());
    assert.equal(await page.evaluate(()=>window.__calls.length),3,'the next tick retries');
    await page.evaluate(data=>window.__settle(2,data),payload());
    assert.equal(await page.locator('[data-qv].is-degraded').count(),0);
    assert.equal(await page.locator('[data-qv-metric].is-stale').count(),0);
}));

browserTest('polls every ten seconds and never lets an older response replace a newer state',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    assert.deepEqual(await page.evaluate(()=>window.__timers.map(timer=>timer.delay)),[10000]);
    await page.evaluate(()=>window.__tick());
    assert.equal(await page.evaluate(()=>window.__calls.length),2);
    await page.evaluate(data=>window.__settle(1,data),payload({metrics:metrics({cpu:{available:true,percent:90}})}));
    await page.evaluate(data=>window.__settle(0,data),payload({metrics:metrics({cpu:{available:true,percent:5}})}));
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'90');
    // A late failure of an older request must not degrade the newer applied state either.
    await page.evaluate(()=>{window.__tick();window.__tick();});
    await page.evaluate(data=>window.__settle(3,data),payload({metrics:metrics({cpu:{available:true,percent:33}})}));
    await page.evaluate(()=>window.__fail(2));
    assert.equal(await meter(page,'cpu').getAttribute('aria-valuenow'),'33');
    assert.equal(await page.locator('[data-qv].is-degraded').count(),0);
}));

browserTest('shows at most four chips, labels the requester You and expands the rest',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser);
    await page.evaluate(data=>window.__settle(0,data),payload({presence:people(7)}));
    const chips=page.locator('[data-qv-users] li.agh-qv-chip');
    assert.equal(await chips.count(),4);
    assert.equal(await chips.first().textContent(),'You');
    assert.equal(await page.locator('[data-qv-users] b').count(),0,'names render as text');
    const more=page.locator('[data-qv-more]');
    assert.equal(await more.textContent(),'+3 more');
    assert.equal(await more.getAttribute('aria-expanded'),'false');
    await more.click();
    assert.equal(await chips.count(),7);
    assert.equal(await page.locator('[data-qv-more]').getAttribute('aria-expanded'),'true');
    // Expansion survives the next poll.
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(1,data),payload({presence:people(7)}));
    assert.equal(await chips.count(),7);
    await page.evaluate(()=>window.__tick());
    await page.evaluate(data=>window.__settle(2,data),payload({presence:people(3)}));
    assert.equal(await chips.count(),3);
    assert.equal(await page.locator('[data-qv-more]').count(),0);
}));

browserTest('pauses while hidden, refreshes on resume, honours reduced motion and releases on unmount',async()=>withBrowser(async browser=>{
    const page=await cardPage(browser,{reducedMotion:true});
    await page.evaluate(data=>window.__settle(0,data),payload());
    assert.equal(await page.locator('[data-qv].is-reduced').count(),1);
    assert.equal(await page.locator('[data-qv-metric="cpu"] .agh-meter span').evaluate(node=>getComputedStyle(node).transitionDuration),'0s');
    await page.evaluate(()=>{window.__hidden=true;window.__tick();window.__tick();});
    assert.equal(await page.evaluate(()=>window.__calls.length),1,'no polling while hidden');
    await page.evaluate(()=>window.__setHidden(false));
    assert.equal(await page.evaluate(()=>window.__calls.length),2,'visibility resume refreshes immediately');

    // Remounting on a rerendered card keeps one timer and repaints the last state at once.
    await page.evaluate(()=>window.__view.mount(document.querySelector('[data-id="other"]')));
    assert.deepEqual(await page.evaluate(()=>window.__timers.map(timer=>timer.cleared)),[false]);
    assert.equal(await page.locator('[data-id="other"] [data-qv-metric="cpu"] [role="meter"]').getAttribute('aria-valuenow'),'42');

    await page.evaluate(()=>window.__view.unmount());
    assert.deepEqual(await page.evaluate(()=>window.__timers.map(timer=>timer.cleared)),[true]);
    await page.evaluate(data=>window.__settle(1,data),payload({metrics:metrics({cpu:{available:true,percent:99}})}));
    assert.equal(await page.locator('[data-id="other"] [data-qv-metric="cpu"] [role="meter"]').getAttribute('aria-valuenow'),'42','a released card is never repainted');
    await page.evaluate(()=>window.__setHidden(false));
    assert.equal(await page.evaluate(()=>window.__calls.length),2,'an unmounted view ignores visibility changes');
}));

async function homePage(browser,{width=1200,holdLayout=false}={}){
    const page=await browser.newPage({viewport:{width,height:900}});
    // Studio load is a Studio admin card, so the account has full access. The tool tile gives the
    // tests a way to make Home redraw: closing a tool does.
    // Home talks to a tool frame at its own origin, so the page needs a real one (not about:blank).
    await page.route(`${TOOL_ORIGIN}/**`,route=>route.fulfill({contentType:'text/html',body:'<!doctype html><p>tool</p>'}));
    await page.goto(`${TOOL_ORIGIN}/`);
    await page.setContent(homeApp({superuser:true,tag:'main'}));
    await page.addStyleTag({content:cssSource});
    await instrument(page);
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(({data,holdLayout,toolUrl})=>{
        const response=(body,status=200)=>new Response(JSON.stringify(body),{status,headers:{'Content-Type':'application/json'}});
        const catalog=[{id:'system-quickview',title:'Studio & people',description:'Live Mac Studio load and who is online now.',icon:'system',kind:'native',always_available:true,settings:{}},{id:'looper',title:'Video Loop Refiner',description:'Build a seamless loop.',icon:'looper',kind:'tool',settings:{},url:toolUrl}];
        window.__requests=[];
        window.fetch=async url=>{
            const address=String(url);window.__requests.push(address);
            if(address.endsWith('/layout')&&holdLayout)await new Promise(resolve=>{window.__releaseLayout=resolve;});
            if(address.endsWith('/layout'))return response({catalog,widgets:catalog.map(({id})=>({id,settings:{}})),links:[]});
            if(address.endsWith('/data/system-quickview'))return response(data);
            const known=window.__homeAnswer(address);
            return known?response(known):response({message:'missing'},404);
        };
    },{data:payload(),holdLayout,toolUrl:`${TOOL_ORIGIN}/tools/looper/index.html`});
    await page.addScriptTag({content:stateSource});
    await page.addScriptTag({content:quickViewSource});
    await page.addScriptTag({content:homeSource});
    return page;
}
const quickViewRequests=page=>page.evaluate(()=>window.__requests.filter(address=>address.endsWith('/data/system-quickview')).length);
const quickViewTimers=page=>page.evaluate(()=>window.__timers.filter(timer=>timer.delay===10000&&!timer.cleared).length);

browserTest('Home owns one QuickView instance across rerenders and releases it on teardown',async()=>withBrowser(async browser=>{
    const page=await homePage(browser);
    const card=page.locator('[data-id="system-quickview"]');
    await card.locator('[data-qv-metric="cpu"] [role="meter"][aria-valuenow="42"]').waitFor();
    assert.equal(await page.evaluate(()=>window.__requests.filter(address=>address.includes('/data/')).join()),'/admin/home/data/system-quickview');
    assert.equal(await quickViewTimers(page),1);

    // Opening and closing a tool redraws Home.
    await page.locator('[data-open="looper"]').click();
    await page.locator('.agh-workspace [data-tool-close]').click();
    await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
    await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
    assert.equal(await page.locator('[data-id="system-quickview"]').count(),1,'a redraw keeps the one card');
    await card.locator('[data-qv-metric="cpu"] [role="meter"][aria-valuenow="42"]').waitFor();
    assert.equal(await quickViewTimers(page),1,'rerenders never duplicate the poller');
    assert.equal(await quickViewRequests(page),1,'rerenders repaint from state without refetching');

    await page.evaluate(()=>window.__tick());
    await page.waitForFunction(()=>window.__requests.filter(address=>address.endsWith('/data/system-quickview')).length===2);

    // AJAX navigation removes the Home root; the owner must release its timer.
    await page.evaluate(()=>{document.querySelector('[data-mothership-home]').remove();document.dispatchEvent(new Event('mothership:navigate:success'));});
    await page.waitForFunction(()=>window.__timers.filter(timer=>timer.delay===10000&&!timer.cleared).length===0);
}));

browserTest('a Home torn down while its layout is still loading never starts QuickView',async()=>withBrowser(async browser=>{
    const page=await homePage(browser,{holdLayout:true});
    await page.waitForFunction(()=>typeof window.__releaseLayout==='function');
    await page.evaluate(()=>{document.querySelector('[data-mothership-home]').remove();document.dispatchEvent(new Event('mothership:navigate:success'));});
    await page.evaluate(async()=>{window.__releaseLayout();await new Promise(done=>setTimeout(done,50));});
    assert.equal(await quickViewRequests(page),0);
    assert.equal(await quickViewTimers(page),0);
}));

browserTest('the QuickView card stays inside its grid cell at a narrow width',async()=>withBrowser(async browser=>{
    const page=await homePage(browser,{width:390});
    const card=page.locator('[data-id="system-quickview"]');
    await card.locator('[data-qv-metric="cpu"] [role="meter"][aria-valuenow="42"]').waitFor();
    assert.equal(await card.locator('[data-qv]').evaluate(node=>node.scrollWidth<=node.clientWidth+1),true);
    assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth+1),true);
}));
