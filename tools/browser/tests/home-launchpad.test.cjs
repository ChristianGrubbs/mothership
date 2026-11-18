const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {launchBrowser}=require('../launch-browser.cjs');
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');
const root=path.resolve(__dirname,'../../..');
const source=name=>fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets',name),'utf8');
const browserTest=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip;
const origin='https://home.test';
// A full-access account, so the page also carries a card (File storage) whose exposure is tracked.
const html=`<!doctype html>${homeApp({superuser:true,tag:'main',attrs:{'data-base':'/home','data-library':'/library'}})}`;
const tools=['stylepress','looper','loop-maker','vectorization','sphereseam'];

browserTest('tool tiles keep keyboard workflows and truthful telemetry at every width',async()=>{
    const browser=await launchBrowser({playwrightPath:process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try{
        for(const width of [1280,768,641,390]){
            const page=await browser.newPage({viewport:{width,height:900}});
            const errors=[];page.on('pageerror',e=>errors.push(e.message));
            await page.route(`${origin}/**`,route=>route.fulfill({contentType:'text/html',body:route.request().url().includes('/tool')?'<script>window.__MOTHERSHIP_FRAME__={widget:"stylepress"}</script><button>Tool fixture</button>':html}));
            await page.goto(origin);
            if(width===641)await page.evaluate(()=>Object.defineProperty(crypto,'randomUUID',{value:undefined,configurable:true}));
            if(width===768)await page.evaluate(()=>document.documentElement.classList.add('dark'));
            await page.addStyleTag({content:'*{box-sizing:border-box}body{margin:0;font-family:system-ui}'+source('css/home.css')});
            await page.addScriptTag({content:homeAnswersSource});
            await page.evaluate(({origin,tools})=>{
                window.events=[];window.damTrack=(name,subject,props,surface,duration)=>window.events.push({name,subject,props,surface,duration});
                window.writes=0;
                const catalog=[...tools.map(id=>({id,title:id==='stylepress'?'StylePress':id,description:'Turn source material into finished work.',kind:'tool',settings:{},url:origin+'/tool'})),{id:'storage',title:'Storage',description:'Available storage',icon:'storage',kind:'native',settings:{}}];
                window.fetch=async(url,options={})=>{
                    const address=String(url);
                    if(options.method&&options.method!=='GET')window.writes++;
                    const body=address.endsWith('/layout')?{widgets:catalog.map(({id})=>({id,settings:{}})),catalog,links:[]}:address.endsWith('/data/storage')?{available:true,free:500,total:1000}:window.__homeAnswer(address)??{};
                    return new Response(JSON.stringify(body),{headers:{'Content-Type':'application/json'}});
                };
            },{origin,tools});
            await page.addScriptTag({content:source('js/home-state.js')});
            await page.addScriptTag({content:source('js/home.js')});
            const launcher=page.locator('[data-open="stylepress"]');await launcher.waitFor();
            // Every tool is one tile in the Apps section, in front of the iPhone tile, named by its product.
            assert.deepEqual(await page.locator('[data-section="apps"] [data-open]').evaluateAll(nodes=>nodes.map(node=>node.dataset.open).sort()),[...tools].sort());
            assert.equal(await page.locator('[data-section="apps"] li').last().locator('[data-phone-open]').count(),1);
            assert.equal(await launcher.locator('strong').textContent(),'StylePress');
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,`Home must not scroll sideways at ${width}px`);
            await page.locator('[data-id="storage"]').scrollIntoViewIfNeeded();
            await page.waitForFunction(()=>window.events.some(e=>e.name==='home.feature.exposed'));
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
            // Closing a tool redraws Home; that must not re-fire exposure for the same card instance.
            await page.locator('[data-id="storage"]').scrollIntoViewIfNeeded();
            await page.waitForTimeout(1100);
            assert.equal(await page.evaluate(()=>window.events.filter(e=>e.name==='home.feature.exposed'&&e.props.widget_id==='storage').length),1);
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
