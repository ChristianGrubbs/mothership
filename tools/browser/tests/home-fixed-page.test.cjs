const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {launchBrowser}=require('../launch-browser.cjs');
const {homeApp,homeAnswersSource,homeScripts,homeStyles}=require('./support/home-fixture.cjs');

// The fixed Home page's own parts: the arrivals board, the states it can be in, the phone sheet and the
// dialogs. Signal has its own file; tools open from the Tools page, which has its own.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const ORIGIN='https://home.test';

const tool=(id,title)=>({id,title,description:`${title} description`,icon:id,kind:'tool',settings:{},url:`${ORIGIN}/tools/${id}/index.html`});

// `summary` is the page script that answers /summary: a function of the page's __homeSummary helper.
async function mount(browser,{fixture={},catalog=[],links=[],summary=null,width=1280,prepare=null,clock=false}={}){
    const page=await browser.newPage({viewport:{width,height:900},reducedMotion:'reduce'});
    if(clock)await page.clock.install();
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    // The top bar's Upload as the shell draws it: hidden until Home brings it back.
    await page.route(`${ORIGIN}/**`,route=>route.fulfill({contentType:'text/html',body:route.request().url().includes('/tools/')?'<!doctype html><p>tool</p>':`<!doctype html><a href="/admin/dam" class="bridge-top-upload" style="display:none" data-bridge-upload><span>Upload</span></a>${homeApp(fixture)}`}));
    await page.goto(`${ORIGIN}/admin/home`);
    await page.addStyleTag({content:`*{box-sizing:border-box}body{margin:0}${homeStyles}`});
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(({catalog,links,summary})=>{
        const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
        const answer=summary?(0,eval)(`(${summary})`):null;
        window.__requests=[];window.__summaryCalls=0;
        window.fetch=async(url,options={})=>{
            const address=String(url);window.__requests.push(`${options.method||'GET'} ${address}`);
            if(address.endsWith('/layout'))return response({catalog,widgets:catalog.map(({id,settings})=>({id,settings})),links});
            if(address.endsWith('/summary')&&answer){window.__summaryCalls++;const body=answer(window.__summaryCalls);return body===null?response({message:'The ship did not answer.'},503):response(body);}
            return response(window.__homeAnswer(address)??{});
        };
    },{catalog,links,summary:summary?summary.toString():null});
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
        // An explicit scope stops the Library reopening a remembered folder or search.
        assert.equal(new URL(await page.locator('.hm-act').getAttribute('href'),ORIGIN).search,'?scope=all');
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

browserTest('the top bar Upload belongs to Home and goes when Home goes',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser);
        const upload=page.locator('[data-bridge-upload]');
        assert.equal(await upload.isVisible(),true);
        await page.evaluate(()=>document.querySelector('[data-mothership-home]').remove());
        assert.equal(await upload.isVisible(),false);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('the real header link stays hidden under the built shell stylesheet on every page but Home',async()=>{
    const root=path.resolve(__dirname,'../../..');
    const build=path.join(root,'public/themes/admin/default/build');
    const manifest=JSON.parse(fs.readFileSync(path.join(build,'manifest.json'),'utf8'));
    const stylesheets=[...new Set(Object.values(manifest).flatMap(entry=>[entry.file,...(entry.css||[])]).filter(file=>file.endsWith('.css')))];
    const shell=stylesheets.map(file=>fs.readFileSync(path.join(build,file),'utf8')).join('\n');
    const header=fs.readFileSync(path.join(root,'packages/Mothership/Admin/src/Resources/views/components/layouts/header/index.blade.php'),'utf8');
    const link=header.match(/<a [^>]*data-bridge-upload[^>]*>/)[0].replace(/\{\{.*?\}\}/g,'/admin/dam');
    const browser=await launchBrowser({playwrightPath});
    try{
        const page=await browser.newPage({viewport:{width:1440,height:900}});
        await page.setContent(`<!doctype html><div class="bridge-top-actions">${link}<span>Upload</span></a></div><main id="page"></main>`);
        await page.addStyleTag({content:shell});
        // Home's stylesheet can outlive Home after in-app navigation, so the Library is tested with it loaded.
        await page.addStyleTag({content:homeStyles});
        const upload=page.locator('[data-bridge-upload]');
        assert.equal(await upload.isVisible(),false,'hidden on the Library and every other page');
        await page.evaluate(()=>document.querySelector('#page').setAttribute('data-mothership-home',''));
        assert.equal(await upload.isVisible(),true,'shown on Home');
        await page.setViewportSize({width:390,height:844});
        assert.equal(await upload.isVisible(),true,'shown on Home on a phone');
        await page.evaluate(()=>document.querySelector('#page').removeAttribute('data-mothership-home'));
        assert.equal(await upload.isVisible(),false,'hidden again after leaving Home');
        await page.close();
    }finally{await browser.close();}
});

browserTest('Cargo keeps an upload under way after newer rows push it off the board',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{summary:()=>{
            const at=new Date().toISOString();
            const landed={key:'asset:1',status:'landed',source:'craft',producer:'claude-code',title:'Harbour study',files:1,bytes:2048,failed_files:0,at,kinds:[],previews:[]};
            const upload={key:'upload:1',status:'stowing',phase:'uploading',source:'upload',mine:true,title:null,files:2,bytes:2048,received_bytes:1024,failed_files:0,at};
            return window.__homeSummary({lead:{available:true,state:'new',files:1,bytes:2048,since:null},board:{rows:[landed],moving:[upload],cargo:[landed],more:1,truncated:false,docked:[]}});
        }});
        const cargo=page.locator('[data-cargo]');
        await cargo.getByRole('heading',{name:'Uploading files'}).waitFor();
        assert.equal(await cargo.getByRole('progressbar').getAttribute('aria-valuenow'),'50');
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('shelf tiles follow a small change of width',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{width:390,summary:()=>{
            const picture=id=>({id,name:`Study ${id}`,type:'image',extension:'png',url:`/admin/dam/${id}`,thumbnail:`/thumbnail-${id}`});
            const row={key:'asset:1',status:'landed',source:'craft',producer:'claude-code',title:'Harbour study',files:6,bytes:2048,failed_files:0,at:new Date().toISOString(),kinds:[{extension:'png',count:6}],previews:[picture(1),picture(2),picture(3)]};
            return window.__homeSummary({lead:{available:true,state:'new',files:6,bytes:2048,since:null},board:{rows:[row],moving:[],cargo:[row],more:0,truncated:false,docked:[]}});
        }});
        await page.locator('.hm-shelf').first().waitFor();
        const before=await page.locator('.hm-shelf').first().evaluate(node=>node.style.getPropertyValue('--tile'));
        await page.setViewportSize({width:380,height:900});
        await page.waitForFunction(value=>document.querySelector('.hm-shelf')?.style.getPropertyValue('--tile')!==value,before);
        // A shelf at rest must not keep redrawing, or its pictures never get the chance to load.
        const redraws=await page.evaluate(()=>new Promise(resolve=>{let count=0;const watcher=new MutationObserver(()=>{count++;});watcher.observe(document.querySelector('[data-cargo]'),{childList:true});setTimeout(()=>{watcher.disconnect();resolve(count);},800);}));
        assert.equal(redraws,0);
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

browserTest('Home draws no tool to launch, puts Calendar beside Open in Library for an account that has it, and lists saved links at the foot of Away from ship',async()=>{
    const browser=await launchBrowser({playwrightPath});
    // Calendar sits in the head of Cargo, which shows once the library has a file.
    const withCargo=()=>{
        const row={key:'asset:1',status:'landed',source:'craft',producer:'claude-code',title:'Harbour study',files:1,bytes:2048,failed_files:0,at:new Date().toISOString(),kinds:[{extension:'png',count:1}],previews:[]};
        return window.__homeSummary({lead:{available:true,state:'new',files:1,bytes:2048,since:null},board:{rows:[row],cargo:[row],more:0,truncated:false,docked:[]}});
    };
    try{
        // Tools open from the Tools page: a catalogue full of them puts nothing to launch on Home.
        const tools=[tool('looper','Video Loop Refiner'),tool('video-downloader','Video Downloader'),tool('vectorization','Vectorization')];
        let {page,errors}=await mount(browser,{catalog:tools,summary:withCargo});
        await page.locator('#hm-cargo').waitFor();
        assert.equal(await page.locator('[data-calendar-open]').isHidden(),true,'no Calendar for an account without it');
        assert.equal(await page.locator('[data-open]').count(),0);
        assert.equal(await page.locator('[data-section="links"]').isHidden(),true,'no saved links, no group');
        assert.deepEqual(errors,[]);
        await page.close();

        ({page,errors}=await mount(browser,{catalog:[...tools,{...tool('calendar','Calendar'),kind:'widget',url:null}],links:[{title:'Print shop order form',url:'https://example.edu/print'}],summary:withCargo}));
        const calendar=page.locator('[data-calendar-open]');
        await calendar.waitFor();
        const [opener,library]=await Promise.all([calendar,page.locator('#hm-cargo a.hm-sec-link')].map(locator=>locator.boundingBox()));
        assert.ok(Math.abs(opener.y+opener.height/2-(library.y+library.height/2))<1&&opener.x+opener.width<library.x,'Calendar sits just before Open in Library, on its line');
        const saved=page.locator('[data-away] [data-section="links"]');
        await saved.waitFor();
        assert.deepEqual(await saved.locator('a').allTextContents(),['Print shop order form ↗']);
        // The group is the card's foot, under what the summary paints there.
        assert.equal(await page.locator('[data-away]').evaluate(card=>card.lastElementChild.matches('[data-section="links"]')&&card.querySelector('[data-away-body]').textContent.includes('Nothing away from ship')),true);
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

browserTest('Add your iPhone, at the foot of Docked, opens its steps and closing returns focus to it, on the page and in the phone sheet',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        let {page,errors}=await mount(browser,{catalog:[tool('looper','Video Loop Refiner')]});
        let opener=page.locator('[data-docked] [data-phone-open]');
        const dialog=()=>page.locator('[data-phone-dialog]');
        const open=()=>dialog().evaluate(node=>node.open),focused=()=>page.evaluate(()=>document.activeElement?.hasAttribute('data-phone-open'));
        assert.equal((await opener.textContent()).trim(),'Add your iPhone');
        // The summary has painted the card's body; the foot is the card's last part and outlives that paint.
        assert.equal(await page.locator('[data-docked]').evaluate(card=>card.lastElementChild.contains(card.querySelector('[data-phone-open]'))&&card.querySelector('[data-docked-body] h2')!==null),true);
        assert.equal(await open(),false);
        await opener.click();
        assert.equal(await open(),true);
        assert.equal(await dialog().evaluate(node=>node.matches(':modal')),true);
        assert.equal(await dialog().locator('.agh-phone-steps li').count(),3);
        await dialog().locator('[data-phone-close]').click();
        assert.equal(await open(),false);assert.equal(await focused(),true);

        // Keyboard: Enter opens, Escape closes, and focus comes back the same way.
        await opener.focus();await page.keyboard.press('Enter');
        assert.equal(await open(),true);
        await page.keyboard.press('Escape');
        assert.equal(await open(),false);assert.equal(await focused(),true);
        assert.deepEqual(errors,[]);
        await page.close();

        // On a phone the card is in the sheet: the steps open over it, and closing them leaves the sheet open on the link.
        ({page,errors}=await mount(browser,{width:390}));
        await page.locator('[data-glance-open="docked"]').click();
        const sheet=page.locator('[data-side-sheet]');
        opener=sheet.locator('[data-docked] [data-phone-open]');
        await opener.click();
        assert.equal(await open(),true);
        await page.keyboard.press('Escape');
        assert.equal(await open(),false);
        assert.equal(await sheet.evaluate(node=>node.open),true,'the sheet stays open under the steps');
        assert.equal(await focused(),true);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});
