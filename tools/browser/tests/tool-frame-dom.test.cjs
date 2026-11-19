const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {bladeFixture}=require('../fixtures/blade.cjs');

// Real-DOM cover for the tool frame page (dam::tools.frame with tool-frame.js): who is asked before a tool is left
// or reloaded, that the question is the page's own band and never a browser dialog, where Back goes, and what a
// tool that did not open looks like. The page's markup is the view as Laravel rendered it (fixtures/blade.cjs), under
// the admin stylesheet as it is built and committed, so what covers what is the real thing.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js',file),'utf8');
const BUILD='public/themes/admin/default/build';
const builtStyles=()=>{
    const manifest=JSON.parse(fs.readFileSync(path.join(root,BUILD,'manifest.json'),'utf8'));
    const entry=Object.values(manifest).find(item=>typeof item.file==='string'&&item.file.startsWith('assets/app-')&&item.file.endsWith('.css'));
    assert.ok(entry,'the committed admin manifest lists a built assets/app-*.css');
    return fs.readFileSync(path.join(root,BUILD,entry.file),'utf8');
};

// The fixture was rendered with this root, so the addresses in its markup are on this origin.
const ORIGIN='http://mothership.test';
const FRAME_URL=`${ORIGIN}/admin/tools/sound-effects`;
const TOOLS_URL=`${ORIGIN}/admin/tools`;
const LIBRARY_URL=`${ORIGIN}/admin/dam`;
const FRAME_HTML=`<!doctype html><html><head><title>Sound Effect Maker</title><style>${builtStyles()}</style></head><body><div id="app">${bladeFixture('tool-frame.html')}</div><script>${source('tool-frame.js')}<\/script></body></html>`;
const plainPage=name=>`<!doctype html><html><head><title>${name}</title></head><body><h1>${name}</h1></body></html>`;
// The tool runs the real bridge, so typing and Escape inside it reach the page the way a live tool's do.
const TOOL_HTML=`<!doctype html><html><body><script>window.__MOTHERSHIP_FRAME__={widget:"sound-effects",state:{},url:"/admin/home/widgets/sound-effects",csrf:"fixture"};<\/script><script>${source('home-frame.js')}<\/script><p>tool</p><input id="tool-field"></body></html>`;
// What a lapsed session answers with at the tool's address: a page that does not name itself as the tool.
const SIGN_IN_HTML=plainPage('Sign in');
// A file a tool may put in its own place: a one-pixel picture.
const FILE_PATH='/admin/home/tools/sound-effects/exports/cover.png';
const PNG=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=','base64');

// `clock` puts the page on a clock the test moves; `held` starts with the tool's page never answering (hold() turns
// that on and off later).
async function framePage(browser,{toolHtml=TOOL_HTML,referer,clock=false,held=false}={}){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    let toolLoads=0,dialogs=0;
    if(clock)await page.clock.install();
    page.on('dialog',dialog=>{dialogs++;dialog.dismiss().catch(()=>{});});
    await page.route(FRAME_URL,route=>route.fulfill({contentType:'text/html; charset=utf-8',body:FRAME_HTML}));
    await page.route(TOOLS_URL,route=>route.fulfill({contentType:'text/html',body:plainPage('Tools')}));
    await page.route(LIBRARY_URL,route=>route.fulfill({contentType:'text/html',body:plainPage('Library')}));
    await page.route(`${ORIGIN}/admin/home/tools/**`,route=>{
        if(new URL(route.request().url()).pathname===FILE_PATH)return route.fulfill({contentType:'image/png',body:PNG});
        toolLoads++;
        // A held page is never answered: the frame keeps waiting, as it does for a server that has stalled.
        return held?undefined:route.fulfill({contentType:'text/html',body:toolHtml});
    });
    // Every bridge message the page receives, in arrival order.
    await page.addInitScript(()=>{
        if(window.top!==window)return;
        window.__heard=[];
        window.addEventListener('message',event=>{if(event.data?.protocol==='mothership-widget/v1')window.__heard.push(event.data.type+(event.data.payload?.reportsWork===true?':reports':''));});
    });
    // A page whose tool never answers never finishes loading, so it is waited for only as far as its own markup.
    await page.goto(FRAME_URL,{...(referer?{referer}:{}),...(held?{waitUntil:'domcontentloaded'}:{})});
    return {page,toolLoads:()=>toolLoads,dialogs:()=>dialogs,hold:on=>{held=on;}};
}

// The tool is open once its own page has loaded in the frame and has promised to report work; `loads` is how many
// tool pages have promised so far (one per load).
const toolOpen=async(page,loads=1)=>{
    await page.waitForFunction(wanted=>window.__heard.filter(type=>type==='ready:reports').length>=wanted,loads);
    await page.locator('[data-tool-note]').waitFor({state:'hidden'});
    const frame=await (await page.locator('[data-tool-window]').elementHandle()).contentFrame();
    await frame.waitForURL(url=>url.pathname.startsWith('/admin/home/tools/'));
    return frame;
};
// Type into the tool, as a person would, and wait until the page has been told.
const holdWork=async(page,frame)=>{
    const before=await page.evaluate(()=>window.__heard.filter(type=>type==='dirty').length);
    await frame.locator('#tool-field').fill('work');
    await page.waitForFunction(count=>window.__heard.filter(type=>type==='dirty').length>count,before);
};
const ask=page=>page.evaluate(()=>{
    const band=document.querySelector('[data-tool-ask]'),accept=band.querySelector('[data-tool-ask-accept]');
    const focus=document.activeElement;
    return {
        shown:!band.hidden,
        question:band.querySelector('[data-tool-question]').textContent,
        accept:accept.textContent,
        discards:accept.classList.contains('is-discard'),
        focus:focus===accept?'accept':focus?.hasAttribute('data-tool-back')?'back':focus?.hasAttribute('data-tool-reload')?'reload':focus?.tagName.toLowerCase(),
    };
});
// What a person would touch in the middle of the stage: the tool's own frame, or the note laid over it.
const stageTop=page=>page.evaluate(()=>{
    const box=document.querySelector('[data-tool-stage]').getBoundingClientRect();
    const hit=document.elementFromPoint(box.left+box.width/2,box.top+box.height/2);
    return hit?.hasAttribute('data-tool-window')?'tool':hit?.closest('[data-tool-note]')?'note':hit?.tagName.toLowerCase();
});
const note=page=>page.evaluate(()=>{
    const box=document.querySelector('[data-tool-note]'),reload=box.querySelector('[data-tool-note-reload]');
    return {shown:!box.hidden,text:box.querySelector('[data-tool-note-text]').textContent,actions:!box.querySelector('[data-tool-note-actions]').hidden,filled:reload.classList.contains('bridge-btn-act')};
});
const until=async(check,what)=>{
    for(let tries=0;tries<200;tries++){if(check())return;await new Promise(resolve=>setTimeout(resolve,25));}
    assert.fail(what);
};
const launch=()=>require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});

browserTest('Back leaves at once while the tool holds no work, and asks in the page before work is lost',async()=>{
    const browser=await launch();
    try{
        const idle=await framePage(browser);
        await toolOpen(idle.page);
        await idle.page.locator('[data-tool-back]').click();
        await idle.page.waitForURL(TOOLS_URL);
        assert.equal(idle.dialogs(),0,'leaving a tool that holds nothing raises no browser dialog');
        await idle.page.close();

        const {page,dialogs}=await framePage(browser);
        await holdWork(page,await toolOpen(page));
        await page.locator('[data-tool-back]').click();
        assert.deepEqual(await ask(page),{shown:true,question:'Close Sound Effect Maker? Work you have not exported is discarded.',accept:'Discard and close',discards:true,focus:'accept'});
        assert.equal(page.url(),FRAME_URL,'the tool is still open while the question is up');

        // Cancel takes the question away and gives focus back to the control that raised it.
        await page.locator('[data-tool-ask-cancel]').click();
        assert.deepEqual((({shown,focus})=>({shown,focus}))(await ask(page)),{shown:false,focus:'back'});
        assert.equal(page.url(),FRAME_URL);

        await page.locator('[data-tool-back]').click();
        await page.locator('[data-tool-ask-accept]').click();
        await page.waitForURL(TOOLS_URL);
        assert.equal(dialogs(),0,'the question is the page\'s own band: no browser dialog, before or after the answer');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Escape never closes a tool by itself: the first asks, the second takes the question away',async()=>{
    const browser=await launch();
    try{
        const {page,dialogs}=await framePage(browser);
        const frame=await toolOpen(page);

        // Escape pressed inside the tool reaches the page as a bridge message.
        await frame.locator('#tool-field').press('Escape');
        await page.locator('[data-tool-ask]').waitFor({state:'visible'});
        assert.deepEqual(await ask(page),{shown:true,question:'Close Sound Effect Maker?',accept:'Close',discards:false,focus:'accept'});

        // Focus is on the page now, so this Escape is the page's own key.
        await page.keyboard.press('Escape');
        assert.deepEqual((({shown,focus})=>({shown,focus}))(await ask(page)),{shown:false,focus:'iframe'});
        assert.equal(page.url(),FRAME_URL,'two Escapes leave the tool open');

        // With work in the tool the same key asks about the work.
        await holdWork(page,frame);
        await frame.locator('#tool-field').press('Escape');
        await page.locator('[data-tool-ask]').waitFor({state:'visible'});
        assert.equal((await ask(page)).accept,'Discard and close');
        assert.equal(dialogs(),0);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Reload loads the tool again, and asks first only when work would be lost',async()=>{
    const browser=await launch();
    try{
        const {page,toolLoads}=await framePage(browser);
        await toolOpen(page);
        await page.locator('[data-tool-reload]').click();
        const frame=await toolOpen(page,2);
        assert.equal(toolLoads(),2,'a tool that holds nothing reloads without a question');
        assert.equal((await ask(page)).shown,false);

        await holdWork(page,frame);
        await page.locator('[data-tool-reload]').click();
        assert.deepEqual(await ask(page),{shown:true,question:'Reload Sound Effect Maker? Work you have not exported is discarded.',accept:'Discard and reload',discards:true,focus:'accept'});
        assert.equal(toolLoads(),2,'nothing reloads until the question is answered');

        await page.locator('[data-tool-ask-accept]').click();
        await toolOpen(page,3);
        assert.equal(toolLoads(),3);
        // The reloaded tool starts clean: leaving it asks nothing.
        await page.locator('[data-tool-back]').click();
        await page.waitForURL(TOOLS_URL);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a tool that did not open says so, offers Reload first, and lets the person leave without a question',async()=>{
    const browser=await launch();
    try{
        const {page,toolLoads,dialogs}=await framePage(browser,{toolHtml:SIGN_IN_HTML});
        await page.locator('[data-tool-note-actions]').waitFor({state:'visible'});
        assert.equal(await page.locator('[data-tool-note-text]').textContent(),'Sound Effect Maker could not open. Reload it, or go back and check that you are still signed in.');
        assert.equal(await page.locator('[data-tool-note-reload]').evaluate(button=>button.classList.contains('bridge-btn-act')),true,'Reload is the filled control when the tool is not coming');
        assert.equal(await stageTop(page),'note','the note covers the page that came in place of the tool');

        await page.locator('[data-tool-note-reload]').click();
        await page.waitForFunction(()=>document.querySelector('[data-tool-note-actions]').hidden===false&&document.querySelector('[data-tool-note-text]').textContent.includes('could not open'));
        assert.equal(toolLoads(),2,'Reload in the note asks for the tool again');

        // Escape still asks, in plain words: a tool that never opened holds nothing.
        await page.keyboard.press('Escape');
        assert.deepEqual((({shown,accept,discards})=>({shown,accept,discards}))(await ask(page)),{shown:true,accept:'Close',discards:false});
        await page.keyboard.press('Escape');

        await page.locator('[data-tool-note-back]').click();
        await page.waitForURL(TOOLS_URL);
        assert.equal(dialogs(),0);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Back returns to the page of the app the person came from, and to Tools otherwise',async()=>{
    const browser=await launch();
    try{
        const fromLibrary=await framePage(browser,{referer:LIBRARY_URL});
        await toolOpen(fromLibrary.page);
        const back=fromLibrary.page.locator('[data-tool-back]');
        assert.equal(await back.getAttribute('aria-label'),'Back');
        assert.equal(await back.getAttribute('href'),LIBRARY_URL);
        await back.click();
        await fromLibrary.page.waitForURL(LIBRARY_URL);
        await fromLibrary.page.close();

        // Another tool's frame, the sign-in page and another site are not places to go back to.
        for(const referer of [`${ORIGIN}/admin/tools/plaque`,`${ORIGIN}/admin/login`,'http://elsewhere.test/admin/dam']){
            const {page}=await framePage(browser,{referer});
            await toolOpen(page);
            assert.equal(await page.locator('[data-tool-back]').getAttribute('aria-label'),'Back to Tools',referer);
            assert.equal(await page.locator('[data-tool-back]').getAttribute('href'),TOOLS_URL,referer);
            await page.close();
        }
    } finally { await browser.close(); }
});

browserTest('a file the tool opens in its own place stays in view under a notice, and Reload brings the tool back',async()=>{
    const browser=await launch();
    try{
        const {page,toolLoads,dialogs}=await framePage(browser);
        const frame=await toolOpen(page);
        assert.equal(await stageTop(page),'tool','an open tool is what the stage shows');
        await holdWork(page,frame);

        // The tool moves its own frame to a file. The step is deferred so the call returns before the frame goes.
        await frame.evaluate(file=>{setTimeout(()=>location.assign(file),0);},FILE_PATH);
        await page.locator('[data-tool-notice]').waitFor({state:'visible'});
        assert.equal(await page.locator('[data-tool-notice-text]').textContent(),'Sound Effect Maker opened a file here. Reload brings the tool back.');
        assert.equal((await note(page)).shown,false,'nothing is laid over the file');
        assert.equal(await stageTop(page),'tool','the file can be seen and reached');
        assert.equal(new URL(frame.url()).pathname,FILE_PATH,'the frame still shows the file');

        // The tool's page went when the file came, so nothing is left to lose: Escape asks in plain words. The
        // notice steps aside while the question is up and comes back with it gone.
        await page.locator('[data-tool-reload]').focus();
        await page.keyboard.press('Escape');
        assert.deepEqual(await ask(page),{shown:true,question:'Close Sound Effect Maker?',accept:'Close',discards:false,focus:'accept'});
        assert.equal(await page.locator('[data-tool-notice]').isVisible(),false,'one filled control at a time');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-tool-notice]').isVisible(),true);

        await page.locator('[data-tool-notice-reload]').click();
        await toolOpen(page,2);
        assert.equal(toolLoads(),2,'Reload in the notice asks for the tool again, without a question');
        assert.equal(await page.locator('[data-tool-notice]').isVisible(),false,'the notice goes when the tool is back');
        assert.equal(await stageTop(page),'tool');

        await page.locator('[data-tool-back]').click();
        await page.waitForURL(TOOLS_URL);
        assert.equal(dialogs(),0);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('every try at loading the tool says so when it takes thirty seconds, and Reload tries again',async()=>{
    const SLOW={shown:true,text:'Sound Effect Maker is taking longer than usual to open.',actions:true,filled:false};
    const browser=await launch();
    try{
        // A tool that opened, then stalls on a reload.
        const opened=await framePage(browser,{clock:true});
        await opened.page.clock.fastForward(1000);
        await toolOpen(opened.page);
        opened.hold(true);
        await opened.page.locator('[data-tool-reload]').click();
        await until(()=>opened.toolLoads()===2,'Reload asks for the tool again');
        assert.deepEqual(await note(opened.page),{shown:true,text:'Reloading Sound Effect Maker…',actions:false,filled:false});
        await opened.page.clock.fastForward(31000);
        assert.deepEqual(await note(opened.page),SLOW,'a reload that stalls says so, with Reload and Back');
        await opened.page.close();

        // A tool that never answered: the first try is slow, the next try is slow again, the third opens.
        const {page,toolLoads,hold}=await framePage(browser,{clock:true,held:true});
        await page.clock.fastForward(1000);
        await until(()=>toolLoads()===1,'the page asks for the tool');
        assert.deepEqual(await note(page),{shown:true,text:'Opening Sound Effect Maker…',actions:false,filled:false});
        await page.clock.fastForward(31000);
        assert.deepEqual(await note(page),SLOW);

        await page.locator('[data-tool-note-reload]').click();
        await until(()=>toolLoads()===2,'Reload in the note asks for the tool again');
        assert.equal((await note(page)).text,'Reloading Sound Effect Maker…');
        await page.clock.fastForward(31000);
        assert.deepEqual(await note(page),SLOW,'the second try gets its own thirty seconds');

        hold(false);
        await page.locator('[data-tool-note-reload]').click();
        await toolOpen(page);
        assert.equal(toolLoads(),3);
        assert.equal(await stageTop(page),'tool');
        await page.close();
    } finally { await browser.close(); }
});
