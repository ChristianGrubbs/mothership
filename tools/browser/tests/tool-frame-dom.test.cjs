const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {bladeFixture}=require('../fixtures/blade.cjs');

// Real-DOM cover for the tool frame page (dam::tools.frame with tool-frame.js): who is asked before a tool is left
// or reloaded, that the question is the page's own band and never a browser dialog, where Back goes, and what a
// tool that did not open looks like. The page's markup is the view as Laravel rendered it (fixtures/blade.cjs).
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js',file),'utf8');

// The fixture was rendered with this root, so the addresses in its markup are on this origin.
const ORIGIN='http://mothership.test';
const FRAME_URL=`${ORIGIN}/admin/tools/sound-effects`;
const TOOLS_URL=`${ORIGIN}/admin/tools`;
const LIBRARY_URL=`${ORIGIN}/admin/dam`;
const FRAME_HTML=`<!doctype html><html><head><title>Sound Effect Maker</title></head><body><div id="app">${bladeFixture('tool-frame.html')}</div><script>${source('tool-frame.js')}<\/script></body></html>`;
const plainPage=name=>`<!doctype html><html><head><title>${name}</title></head><body><h1>${name}</h1></body></html>`;
// The tool runs the real bridge, so typing and Escape inside it reach the page the way a live tool's do.
const TOOL_HTML=`<!doctype html><html><body><script>window.__MOTHERSHIP_FRAME__={widget:"sound-effects",state:{},url:"/admin/home/widgets/sound-effects",csrf:"fixture"};<\/script><script>${source('home-frame.js')}<\/script><p>tool</p><input id="tool-field"></body></html>`;
// What a lapsed session answers with at the tool's address: a page that does not name itself as the tool.
const SIGN_IN_HTML=plainPage('Sign in');

async function framePage(browser,{toolHtml=TOOL_HTML,referer}={}){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    let toolLoads=0,dialogs=0;
    page.on('dialog',dialog=>{dialogs++;dialog.dismiss().catch(()=>{});});
    await page.route(FRAME_URL,route=>route.fulfill({contentType:'text/html',body:FRAME_HTML}));
    await page.route(TOOLS_URL,route=>route.fulfill({contentType:'text/html',body:plainPage('Tools')}));
    await page.route(LIBRARY_URL,route=>route.fulfill({contentType:'text/html',body:plainPage('Library')}));
    await page.route(`${ORIGIN}/admin/home/tools/**`,route=>{toolLoads++;return route.fulfill({contentType:'text/html',body:toolHtml});});
    // Every bridge message the page receives, in arrival order.
    await page.addInitScript(()=>{
        if(window.top!==window)return;
        window.__heard=[];
        window.addEventListener('message',event=>{if(event.data?.protocol==='mothership-widget/v1')window.__heard.push(event.data.type+(event.data.payload?.reportsWork===true?':reports':''));});
    });
    await page.goto(FRAME_URL,referer?{referer}:{});
    return {page,toolLoads:()=>toolLoads,dialogs:()=>dialogs};
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
