const {test}=require('node:test');
const assert=require('node:assert/strict');
const {launchBrowser}=require('../launch-browser.cjs');
const {homeApp,homeAnswersSource,homeScripts,homeStyles}=require('./support/home-fixture.cjs');

// The fixed Home page's own parts: the arrivals board, the states it can be in, the Tools shelf, the
// phone sheet and the dialogs. The workspace popup and Signal have their own files.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const ORIGIN='https://home.test';

const tool=(id,title)=>({id,title,description:`${title} description`,icon:id,kind:'tool',settings:{},url:`${ORIGIN}/tools/${id}/index.html`});

// `summary` is the page script that answers /summary: a function of the page's __homeSummary helper.
async function mount(browser,{fixture={},catalog=[],summary=null,width=1280,prepare=null,clock=false}={}){
    const page=await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce'});
    if(clock)await page.clock.install();
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    // The top bar's Upload as the shell draws it, hidden below 720px unless the page brings it back.
    await page.route(`${ORIGIN}/**`,route=>route.fulfill({contentType:'text/html',body:route.request().url().includes('/tools/')?'<!doctype html><p>tool</p>':`<!doctype html><a href="/admin/dam" class="bridge-top-upload shell-phone-hidden" data-bridge-upload><span>Upload</span></a>${homeApp(fixture)}`}));
    await page.goto(`${ORIGIN}/admin/home`);
    await page.addStyleTag({content:`*{box-sizing:border-box}body{margin:0}@media(max-width:719px){.shell-phone-hidden{display:none}}${homeStyles}`});
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(({catalog,summary})=>{
        const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
        const answer=summary?(0,eval)(`(${summary})`):null;
        window.__requests=[];window.__summaryCalls=0;
        window.fetch=async(url,options={})=>{
            const address=String(url);window.__requests.push(`${options.method||'GET'} ${address}`);
            if(address.endsWith('/layout'))return response({catalog,widgets:catalog.map(({id,settings})=>({id,settings})),links:[]});
            if(address.endsWith('/summary')&&answer){window.__summaryCalls++;const body=answer(window.__summaryCalls);return body===null?response({message:'The ship did not answer.'},503):response(body);}
            return response(window.__homeAnswer(address)??{});
        };
    },{catalog,summary:summary?summary.toString():null});
    if(prepare)await page.evaluate(prepare);
    for(const content of homeScripts)await page.addScriptTag({content});
    await page.waitForFunction(()=>window.__requests.some(entry=>entry.endsWith('/layout'))&&!document.querySelector('[data-board]').hasAttribute('aria-busy'));
    return {page,errors};
}

browserTest('the board shows real characters, one cell each, and reads as one sentence per row',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{summary:()=>window.__homeSummary({
            lead:{available:true,state:'new',files:5,bytes:2048,since:null},
            board:{rows:[{status:'landed',source:'craft',producer:'claude-code',title:'Fluid wallpapers',files:5,bytes:2048,at:'2026-11-18T13:31:00Z',previews:[]}],more:0,truncated:false,cargo:[],docked:[]},
        })});
        const row=page.locator('[data-board-rows] .hm-frow');
        assert.equal(await row.count(),1);
        assert.match(await row.locator('.hm-sr').textContent(),/Claude Code: Fluid wallpapers, 5 files, landed\.$/);
        assert.equal(await row.locator('.hm-cells').getAttribute('aria-hidden'),'true');
        // Time 5, From 11, Cargo 20, Qty 3, Status 8: every field fills its width.
        assert.equal(await row.locator('.hm-k i:not(.gap)').count(),47);
        assert.deepEqual((await row.locator('.hm-k.k5 i:not(.gap)').allTextContents()).join('').trim(),'LANDED');
        // One filled blue control, and it is the next step.
        assert.equal(await page.locator('.hm-act').count(),1);
        assert.equal((await page.locator('.hm-act').textContent()).trim(),'Review 5 new files');
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('a board that cannot answer says so, never shows zero, and recovers on Try again',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{summary:call=>call===1?null:window.__homeSummary()});
        const deck=page.locator('[data-deck]');
        assert.equal((await deck.locator('h2').textContent()).trim(),'the board did not load');
        assert.equal((await deck.locator('[role="alert"]').textContent()).trim(),'The ship did not answer.');
        assert.equal((await deck.locator('.hm-fig').textContent()).trim(),'–');
        await deck.locator('[data-summary-retry]').click();
        await deck.getByRole('heading',{name:'nothing new came aboard'}).waitFor();
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('a refresh that fails keeps the last board, says so, and clears on recovery',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{clock:true,summary:call=>call===2||call===3?null:window.__homeSummary()});
        const stale=page.locator('[data-stale]'),deck=page.locator('[data-deck]');
        await deck.getByRole('heading',{name:'nothing new came aboard'}).waitFor();
        assert.equal(await stale.isHidden(),true);

        await page.clock.fastForward('02:00');
        await stale.getByRole('button',{name:'Try again'}).waitFor();
        assert.match(await stale.textContent(),/could not refresh/);
        assert.equal(await deck.getByRole('heading',{name:'nothing new came aboard'}).isVisible(),true,'the last answer stays on screen');

        await stale.getByRole('button',{name:'Try again'}).click();
        await page.waitForFunction(()=>window.__summaryCalls===3);
        assert.equal(await stale.getByRole('button',{name:'Try again'}).isVisible(),true,'a second failure keeps the notice');
        await stale.getByRole('button',{name:'Try again'}).click();
        await page.waitForFunction(()=>!document.querySelector('[data-stale]').firstChild);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('a phone keeps Upload on Home as a named icon button that opens the dialog',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{width:390});
        const upload=page.getByRole('link',{name:'Upload'});
        assert.equal(await upload.isVisible(),true);
        const box=await upload.boundingBox();
        assert.deepEqual([box.width,box.height],[40,40]);
        await upload.click();
        assert.equal(await page.locator('[data-upload-dialog]').evaluate(node=>node.matches(':modal')),true);
        assert.equal(new URL(page.url()).pathname,'/admin/home');
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('a broken picture shows its file type, and Docked says when it read only the newest files',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{summary:()=>{
            const row={key:'asset:1',status:'landed',source:'craft',producer:'claude-code',title:'Harbour study',files:1,bytes:2048,failed_files:0,at:new Date().toISOString(),kinds:[{extension:'png',count:1}],previews:[{id:1,name:'Harbour study',type:'image',extension:'png',url:'/admin/dam/1',thumbnail:'/missing-thumbnail'}]};
            return window.__homeSummary({lead:{available:true,state:'new',files:1,bytes:2048,since:null},board:{rows:[row],cargo:[row],more:0,truncated:true,docked:[{producer:'claude-code',last_save_at:row.at,files_24h:1,hours:Array(24).fill(0)}]}});
        }});
        await page.locator('.hm-pic-kind').waitFor();
        assert.equal(await page.locator('.hm-pic-kind').textContent(),'.png');
        assert.equal((await page.locator('[data-docked] .hm-axis').textContent()).trim(),'Saves among the newest files');
        assert.equal((await page.locator('[data-board-more]').textContent()).trim(),'Counts cover the newest files only');
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('a role without the Library gets no board and an honest count of what it cannot see',async()=>{
    const browser=await launchBrowser({playwrightPath});
    const locked=()=>window.__homeSummary({lead:{available:false,reason:'not-permitted'},board:{available:false,reason:'not-permitted'},away:{shares:{available:false,count:null,reason:'not-permitted'},upload_requests:{available:false,count:null,reason:'not-permitted'}}});
    try{
        const {page,errors}=await mount(browser,{fixture:{library:false},summary:locked});
        await page.waitForFunction(()=>document.querySelector('[data-glance]').children.length>0);
        assert.equal(await page.locator('[data-board-object]').isHidden(),true);
        assert.equal(await page.locator('[data-docked]').isHidden(),true);
        assert.equal(await page.locator('[data-glance-open="docked"]').count(),0,'no craft row for a board this role cannot read');
        assert.match(await page.locator('[data-glance-open="away"]').textContent(),/Links not available/);
        assert.deepEqual(await page.locator('[data-away] .hm-figs b').allTextContents(),['–','–','–']);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('tools sit on the Tools shelf in the house order, and a tile opens its workspace',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{catalog:[tool('vectorization','Vectorization'),{...tool('rss','Signal'),settings:{count:5,feed_id:null}},tool('mystery','Mystery tool'),tool('video-downloader','Video Downloader'),tool('looper','Video Loop Refiner')]});
        const tiles=page.locator('[data-tools] [data-open]');
        // Known tools first in their fixed order, an unknown tool after them. Signal is never a tile.
        assert.deepEqual(await tiles.evaluateAll(items=>items.map(item=>item.dataset.open)),['looper','video-downloader','vectorization','mystery']);
        const downloader=page.locator('[data-open="video-downloader"]');
        assert.equal(await downloader.locator('span').textContent(),'Video Downloader');
        assert.equal(await page.locator('.agh-workspace').count(),0);

        await downloader.click();
        const workspace=page.locator('.agh-workspace');
        await workspace.locator('[data-tool-close]').waitFor();
        assert.equal(await workspace.getAttribute('role'),'dialog');
        assert.equal(await workspace.getAttribute('aria-label'),'Video Downloader');
        const frame=new URL(await workspace.locator('iframe').getAttribute('src'));
        assert.equal(frame.origin+frame.pathname,`${ORIGIN}/tools/video-downloader/index.html`);
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),true);

        // Closing hands focus back to the tile and Home is usable again.
        await workspace.locator('[data-tool-close]').click();await workspace.locator('[data-tool-confirm-accept]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),false);
        assert.equal(await page.evaluate(()=>document.activeElement?.dataset.open),'video-downloader');
        assert.equal(await tiles.count(),4,'a redraw replaces the tiles, never adds to them');
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('the top bar Upload opens the dialog on Home instead of leaving for the Library',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser);
        const dialog=page.locator('[data-upload-dialog]');
        await page.locator('[data-bridge-upload]').click();
        assert.equal(await dialog.evaluate(node=>node.matches(':modal')),true);
        assert.equal(new URL(page.url()).pathname,'/admin/home');
        await dialog.locator('[data-upload-close]').click();
        assert.equal(await page.evaluate(()=>document.activeElement?.hasAttribute('data-bridge-upload')),true);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('on a phone the side column opens in a sheet and goes back when it closes',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{width:390});
        const sheet=page.locator('[data-side-sheet]');
        await page.locator('[data-glance-open="away"]').click();
        assert.equal(await sheet.evaluate(node=>node.matches(':modal')),true);
        assert.equal(await sheet.locator('[data-side] [data-away]').count(),1);
        await sheet.locator('[data-sheet-close]').click();
        assert.equal(await sheet.evaluate(node=>node.open),false);
        await page.waitForFunction(()=>document.querySelector('[data-grid] > [data-side]'));
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('the iPhone tile opens its steps and closing returns focus to the tile',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{catalog:[tool('looper','Video Loop Refiner')]});
        const tile=page.locator('[data-section="apps"] [data-phone-open]'),dialog=page.locator('[data-phone-dialog]');
        const open=()=>dialog.evaluate(node=>node.open),focused=()=>page.evaluate(()=>document.activeElement?.hasAttribute('data-phone-open'));
        assert.equal(await open(),false);
        await tile.click();
        assert.equal(await open(),true);
        assert.equal(await dialog.evaluate(node=>node.matches(':modal')),true);
        assert.equal(await dialog.locator('.agh-phone-steps li').count(),3);
        assert.equal(await page.locator('.agh-workspace').count(),0,'the tile is not a tool launcher');

        await dialog.locator('[data-phone-close]').click();
        assert.equal(await open(),false);assert.equal(await focused(),true);

        // Keyboard: Enter opens, Escape closes, and focus comes back the same way.
        await tile.focus();await page.keyboard.press('Enter');
        assert.equal(await open(),true);
        await page.keyboard.press('Escape');
        assert.equal(await open(),false);assert.equal(await focused(),true);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});
