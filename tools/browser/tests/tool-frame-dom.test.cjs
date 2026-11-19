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
async function framePage(browser,{toolHtml=TOOL_HTML,referer,clock=false,held=false,init}={}){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    let toolLoads=0,dialogs=0;
    if(clock)await page.clock.install();
    if(init)await page.addInitScript(init);
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

// A tool that offers everything the bar can show, through the real bridge, and keeps what the bar told it.
const BAR_TOOL_HTML=`<!doctype html><html><body><script>window.__MOTHERSHIP_FRAME__={widget:"sound-effects",state:{},url:"/admin/home/widgets/sound-effects",csrf:"fixture",library:{folders:"/folders",save:"/save",open:"/open/__ID__"}};<\/script><script>${source('home-frame.js')}<\/script><p>tool</p><input id="tool-field"><dialog id="sheet"><button>In the sheet</button></dialog><script>
window.__commands=[];
for(const name of ['open-set','manage-sets','export','library'])MothershipFrame.on(name,detail=>window.__commands.push(name==='open-set'?name+':'+detail.id:name));
document.addEventListener('DOMContentLoaded',()=>MothershipFrame.chrome({set:'Harbour',sets:[{id:'a',name:'Harbour',current:true},{id:'b',name:'<b>Dusk</b>'}],setsLabel:'Saved palettes',manage:'Manage saved palettes…',export:true,library:true}));
<\/script></body></html>`;
const barOpen=async page=>{const frame=await toolOpen(page);await page.locator('[data-tool-sets]').waitFor();return frame;};
const seen=(page,selector)=>page.locator(selector).evaluate(node=>{const box=node.getBoundingClientRect();return box.width>0&&box.height>0;});

browserTest('the bar shows what the tool offers, writes its names as text, and tells it what was pressed',async()=>{
    const browser=await launch();
    try{
        const {page}=await framePage(browser,{toolHtml:BAR_TOOL_HTML});
        const frame=await barOpen(page);
        assert.equal(await frame.evaluate(()=>MothershipFrame.bar),true,'the tool knows the page draws a bar for it');
        assert.equal(await page.locator('[data-tool-set-name]').textContent(),'Harbour');

        // The menu by keys: Arrow Down opens it on the open set, the arrows walk it, Escape closes it and asks nothing.
        await page.locator('[data-tool-sets-btn]').focus();
        await page.keyboard.press('ArrowDown');
        const items=page.locator('[data-tool-sets-menu] button');
        assert.deepEqual(await items.allTextContents(),['Harbouropen now','<b>Dusk</b>','Manage saved palettes…']);
        assert.equal(await page.locator('[data-tool-sets-menu] b').count(),0,'a name from a tool is never markup');
        assert.equal(await page.evaluate(()=>document.activeElement.getAttribute('aria-checked')),'true');
        await page.keyboard.press('ArrowDown');
        assert.equal(await page.evaluate(()=>document.activeElement.textContent),'<b>Dusk</b>');
        await page.keyboard.press('Escape');
        assert.equal(await page.locator('[data-tool-sets-menu]').isHidden(),true);
        assert.equal(await page.evaluate(()=>document.activeElement.hasAttribute('data-tool-sets-btn')),true);
        assert.equal((await ask(page)).shown,false);

        await page.locator('[data-tool-sets-btn]').click();
        await items.nth(1).click();
        await page.locator('.bridge-toolbar [data-tool-export]').click();
        await page.locator('.bridge-toolbar [data-tool-library]').click();
        await frame.waitForFunction(()=>window.__commands.length===3);
        assert.deepEqual(await frame.evaluate(()=>window.__commands),['open-set:b','export','library']);

        // What the tool says lands in the status line; a link may only stay in the app.
        await frame.evaluate(()=>MothershipFrame.notice({text:'Saved 2 files.',link:{label:'Open in Library',url:'/admin/dam'}}));
        await page.locator('[data-tool-status] a').waitFor();
        assert.equal(await page.locator('[data-tool-status] a').getAttribute('href'),LIBRARY_URL);
        await frame.evaluate(()=>MothershipFrame.notice({text:'Elsewhere.',link:{label:'Away',url:'https://example.test/'}}));
        await page.waitForFunction(()=>document.querySelector('[data-tool-status]').textContent==='Elsewhere.');
        assert.equal(await page.locator('[data-tool-status] a').count(),0);

        // Escape that closes a sheet inside the tool is the tool's own.
        // While it is open the page's own actions step back, so the sheet holds the one filled control.
        await frame.evaluate(()=>document.querySelector('#sheet').showModal());
        await page.waitForSelector('[data-tool-frame][data-tool-sheet]',{state:'attached'});
        assert.equal(await page.locator('.bridge-toolbar [data-tool-library]').evaluate(node=>node.inert),true);
        await frame.locator('#sheet button').press('Escape');
        await frame.waitForFunction(()=>!document.querySelector('#sheet').open);
        await page.waitForSelector('[data-tool-frame]:not([data-tool-sheet])',{state:'attached'});
        assert.equal(await page.locator('.bridge-toolbar [data-tool-library]').evaluate(node=>node.inert),false);
        assert.equal((await ask(page)).shown,false);
        await page.close();
    } finally { await browser.close(); }
});

// A tool whose bar commands open its sheet: Export and the sets' manage item at once, Save to Library after an await,
// and then it goes on reading its folders until the test lets it finish. Picking a set opens none. The tool's own
// button opens the same sheet.
const SHEET_TOOL_HTML=`<!doctype html><html><body><script>window.__MOTHERSHIP_FRAME__={widget:"sound-effects",state:{},url:"/admin/home/widgets/sound-effects",csrf:"fixture",library:{folders:"/folders",save:"/save",open:"/open/__ID__"}};<\/script><script>${source('home-frame.js')}<\/script><p>tool</p><button id="own">Own sheet</button><dialog id="sheet"><button>In the sheet</button></dialog><script>
const sheet=document.querySelector('#sheet');
MothershipFrame.on('export',()=>sheet.showModal());
MothershipFrame.on('manage-sets',()=>sheet.showModal());
MothershipFrame.on('library',async()=>{await new Promise(done=>setTimeout(done,50));sheet.showModal();await new Promise(done=>{window.__foldersRead=done;});});
MothershipFrame.on('open-set',()=>{});
document.querySelector('#own').addEventListener('click',()=>sheet.showModal());
document.addEventListener('DOMContentLoaded',()=>MothershipFrame.chrome({set:'Harbour',sets:[{id:'a',name:'Harbour',current:true}],setsLabel:'Saved palettes',manage:'Manage saved palettes…',export:true,library:true}));
<\/script></body></html>`;

browserTest('a sheet the bar opened gives the focus back to the bar when it closes; a sheet the tool opened keeps it in the tool',async()=>{
    const browser=await launch();
    try{
        const {page}=await framePage(browser,{toolHtml:SHEET_TOOL_HTML});
        const frame=await barOpen(page);
        const focusOn=selector=>page.evaluate(wanted=>document.activeElement===document.querySelector(wanted),selector);
        // Escape inside the sheet closes it, and the page hears that it closed.
        const escapeSheet=async()=>{
            await page.waitForSelector('[data-tool-frame][data-tool-sheet]',{state:'attached'});
            await frame.locator('#sheet button').press('Escape');
            await page.waitForSelector('[data-tool-frame]:not([data-tool-sheet])',{state:'attached'});
        };

        await page.locator('.bridge-toolbar [data-tool-export]').click();
        await escapeSheet();
        assert.equal(await focusOn('.bridge-toolbar [data-tool-export]'),true,'Export has the focus again');
        await page.locator('.bridge-toolbar [data-tool-library]').click();
        await escapeSheet();
        assert.equal(await focusOn('.bridge-toolbar [data-tool-library]'),true,'so does Save to Library, whose sheet came after an await');
        // Its folders are still being read, but the press has had its sheet: the next one is the tool's own.
        await frame.locator('#own').click();
        await escapeSheet();
        assert.equal(await frame.evaluate(()=>document.activeElement.id),'own','a sheet the tool opens while a press is still answered keeps the focus in the tool');
        assert.equal(await focusOn('[data-tool-window]'),true);
        await frame.evaluate(()=>window.__foldersRead());
        await page.locator('[data-tool-sets-btn]').click();
        await page.locator('[data-tool-sets-menu] button',{hasText:'Manage saved palettes…'}).click();
        await escapeSheet();
        assert.equal(await focusOn('[data-tool-sets-btn]'),true,'a sheet from the sets menu gives it to the menu\'s button');
        assert.equal((await ask(page)).shown,false,'and none of these Escapes asks');

        // Picking a set opens no sheet, so a sheet the tool opens later from its own button is the tool's.
        await page.locator('[data-tool-sets-btn]').click();
        await page.locator('[data-tool-sets-menu] button',{hasText:'Harbour'}).click();
        await frame.locator('#own').click();
        await escapeSheet();
        assert.equal(await focusOn('[data-tool-window]'),true,'the focus stays in the tool');
        assert.equal(await frame.evaluate(()=>document.activeElement.id),'own');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('the phone form moves Export and Save to Library under the tool, and only the phone form',async()=>{
    const browser=await launch();
    try{
        const {page}=await framePage(browser,{toolHtml:BAR_TOOL_HTML});
        await barOpen(page);
        for(const [width,height,foot] of [[390,844,true],[579,800,true],[594,368,false],[669,867,false],[1200,900,false]]){
            await page.setViewportSize({width,height});
            assert.equal(await seen(page,'.bridge-toolfoot [data-tool-library]'),foot,`foot at ${width}`);
            assert.equal(await seen(page,'.bridge-toolbar [data-tool-library]'),!foot,`bar at ${width}`);
            const bar=await page.evaluate(()=>{
                const edge=document.querySelector('.bridge-toolbar').getBoundingClientRect();
                const reload=document.querySelector('[data-tool-reload]').getBoundingClientRect();
                return {sideways:Math.max(0,document.documentElement.scrollWidth-document.documentElement.clientWidth),reloadInside:reload.right<=edge.right,sameRow:Math.abs(reload.top-document.querySelector('[data-tool-back]').getBoundingClientRect().top)<1};
            });
            assert.deepEqual(bar,{sideways:0,reloadInside:true,sameRow:true},`the bar's controls at ${width}`);
        }
        await page.close();
    } finally { await browser.close(); }
});

browserTest('the question keeps one row beside its buttons from 580 px up, and stacks on a phone',async()=>{
    const browser=await launch();
    try{
        const {page}=await framePage(browser);
        await holdWork(page,await toolOpen(page));
        await page.locator('[data-tool-back]').click();
        const band=()=>page.evaluate(()=>{
            const box=selector=>document.querySelector(selector).getBoundingClientRect();
            const words=box('[data-tool-question]'),cancel=box('[data-tool-ask-cancel]');
            return {beside:cancel.left>=words.right,cancel:Math.round(cancel.width),height:Math.round(box('[data-tool-ask]').height)};
        });
        for(const [width,height] of [[594,368],[669,867],[951,585]]){
            await page.setViewportSize({width,height});
            const shown=await band();
            assert.equal(shown.beside,true,`beside the sentence at ${width}`);
            assert.ok(shown.cancel<140,`Cancel keeps its own width at ${width}: ${shown.cancel}`);
            assert.ok(shown.height<=68,`one row at ${width}: ${shown.height}`);
        }
        await page.setViewportSize({width:390,height:844});
        const phone=await band();
        assert.equal(phone.beside,false);
        assert.ok(phone.cancel>=150,`the phone's full-width buttons: ${phone.cancel}`);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('the page tells a tool where the fold crosses it',async()=>{
    const browser=await launch();
    try{
        const {page}=await framePage(browser,{toolHtml:BAR_TOOL_HTML,init:()=>{
            if(window.top!==window)return;
            window.__segments=[{left:0,right:580,top:0,bottom:900},{left:620,right:1200,top:0,bottom:900}];
            Object.defineProperty(window,'viewport',{configurable:true,value:{get segments(){return window.__segments;}}});
        }});
        const frame=await barOpen(page);
        const fold=()=>frame.evaluate(()=>{const root=document.documentElement;return [root.dataset.fold??null,root.style.getPropertyValue('--fold-start'),root.style.getPropertyValue('--fold-end')];});
        await frame.waitForFunction(()=>document.documentElement.dataset.fold==='columns');
        assert.deepEqual(await fold(),['columns','580px','620px']);

        // Unfolded flat, the same page says the fold has gone.
        await page.evaluate(()=>{window.__segments=[{left:0,right:1200,top:0,bottom:900}];window.dispatchEvent(new CustomEvent('mothership:fold'));});
        await frame.waitForFunction(()=>!document.documentElement.dataset.fold);
        assert.deepEqual(await fold(),[null,'','']);
        await page.close();
    } finally { await browser.close(); }
});
