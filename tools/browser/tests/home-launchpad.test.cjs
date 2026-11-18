const {test}=require('node:test');
const assert=require('node:assert/strict');
const {launchBrowser}=require('../launch-browser.cjs');
const {homeApp,homeAnswersSource,homeScripts,homeStyles}=require('./support/home-fixture.cjs');
const browserTest=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip;
const origin='https://home.test';
const html=`<!doctype html>${homeApp({superuser:true,tag:'main',attrs:{'data-base':'/home','data-library':'/library'}})}`;
const tools=['stylepress','looper','loop-maker','vectorization','sphereseam'];

browserTest('tool tiles keep keyboard workflows and truthful telemetry at every width',async()=>{
    const browser=await launchBrowser({playwrightPath:process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try{
        for(const width of [1280,768,641,390]){
            const page=await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce'});
            const errors=[];page.on('pageerror',e=>errors.push(e.message));
            await page.route(`${origin}/**`,route=>route.fulfill({contentType:'text/html',body:route.request().url().includes('/tool')?'<script>window.__MOTHERSHIP_FRAME__={widget:"stylepress"}</script><button>Tool fixture</button>':html}));
            await page.goto(origin);
            if(width===641)await page.evaluate(()=>Object.defineProperty(crypto,'randomUUID',{value:undefined,configurable:true}));
            if(width===768)await page.evaluate(()=>document.documentElement.classList.add('dark'));
            await page.addStyleTag({content:'*{box-sizing:border-box}body{margin:0;font-family:system-ui}'+homeStyles});
            await page.addScriptTag({content:homeAnswersSource});
            await page.evaluate(({origin,tools})=>{
                window.events=[];window.damTrack=(name,subject,props,surface,duration)=>window.events.push({name,subject,props,surface,duration});
                window.writes=0;
                const catalog=tools.map(id=>({id,title:id==='stylepress'?'StylePress':id,description:'Turn source material into finished work.',kind:'tool',settings:{},url:origin+'/tool'}));
                window.fetch=async(url,options={})=>{
                    const address=String(url);
                    if(options.method&&options.method!=='GET')window.writes++;
                    const body=address.endsWith('/layout')?{widgets:catalog.map(({id})=>({id,settings:{}})),catalog,links:[]}:window.__homeAnswer(address)??{};
                    return new Response(JSON.stringify(body),{headers:{'Content-Type':'application/json'}});
                };
            },{origin,tools});
            for(const content of homeScripts)await page.addScriptTag({content});
            const launcher=page.locator('[data-open="stylepress"]');await launcher.waitFor();
            // Every tool is one tile on the shelf, in front of the iPhone tile, named by its product.
            assert.deepEqual(await page.locator('[data-section="apps"] [data-open]').evaluateAll(nodes=>nodes.map(node=>node.dataset.open).sort()),[...tools].sort());
            assert.equal(await page.locator('[data-section="apps"] [data-tools] > li').last().locator('[data-phone-open]').count(),1);
            assert.equal(await launcher.locator('span').textContent(),'StylePress');
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`Home must not scroll sideways at ${width}px`);
            // The funnel starts when a tool has been on screen for a second.
            await launcher.scrollIntoViewIfNeeded();
            await page.waitForFunction(()=>window.events.some(e=>e.name==='home.feature.exposed'&&e.props.widget_id==='stylepress'));
            assert.deepEqual(await page.evaluate(()=>window.events.find(e=>e.name==='home.feature.exposed'&&e.props.widget_id==='stylepress').props),{widget_id:'stylepress',cols:1,rows:1,visible_ms:1000});
            await launcher.focus();await launcher.press('Enter');
            await page.waitForFunction(()=>window.events.some(e=>e.name==='home.launch.loaded'));
            const launch=await page.evaluate(()=>window.events.filter(e=>e.name.startsWith('home.launch.')));
            assert.deepEqual(launch.map(e=>e.name),['home.launch.attempted','home.launch.loaded']);
            assert.equal(launch[0].props.launch_id,launch[1].props.launch_id);assert.ok(launch[1].duration>=0);
            assert.equal(await page.evaluate(()=>window.events.find(e=>e.name==='home.feature.activated').props.input),'keyboard');
            await page.locator('[data-tool-close]').press('Escape');
            await page.locator('[data-tool-confirm-cancel]').click();
            assert.ok(await page.locator('.agh-workspace').isVisible());
            await page.locator('[data-tool-close]').click();await page.locator('[data-tool-confirm-accept]').click();
            assert.ok(await launcher.evaluate(node=>node===document.activeElement));
            // Closing a tool redraws the shelf; that must not count the same tool as seen twice.
            await launcher.scrollIntoViewIfNeeded();
            await page.waitForTimeout(1100);
            assert.equal(await page.evaluate(()=>window.events.filter(e=>e.name==='home.feature.exposed'&&e.props.widget_id==='stylepress').length),1);
            // A broken tool identity is a failure, never a successful load.
            await page.route(`${origin}/tool**`,route=>route.fulfill({contentType:'text/html',body:'<p>Sign in again</p>'}));
            await launcher.click();
            await page.waitForFunction(()=>window.events.some(e=>e.name==='home.launch.failed'));
            assert.equal(await page.evaluate(()=>window.events.find(e=>e.name==='home.launch.failed').props.error_code),'frame_identity_missing');
            await page.locator('[data-tool-close]').click();await page.locator('[data-tool-confirm-accept]').click();
            // A user leaving a still-pending tool is abandonment, not an application failure.
            await page.route(`${origin}/tool**`,()=>{});
            await launcher.click();
            await page.locator('[data-tool-close]').click();await page.locator('[data-tool-confirm-accept]').click();
            assert.equal(await page.evaluate(()=>window.events.filter(e=>e.name==='home.launch.abandoned').length),1);
            // Analytics failure must not disable the real launch control.
            await page.evaluate(()=>{window.damTrack=()=>{throw Error('collector unavailable');};});
            await launcher.click();assert.ok(await page.locator('.agh-workspace').isVisible());
            // The fixed page has nothing to save: no launch or redraw writes to the server.
            assert.equal(await page.evaluate(()=>window.writes),0);
            assert.deepEqual(errors,[]);
            await page.close();
        }
    }finally{await browser.close();}
});
