const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

// Real-DOM cover for the Home workspace popup (Gadget) exit controls. These assert behaviour the
// source-regex suite cannot reach: that the popup still closes when the browser suppresses native
// dialogs, that a pending prompt and the async status line never overwrite each other, and that
// focus, inert and the accepted Reload navigation land correctly.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const stateSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home-state.js'),'utf8');
const homeSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home.js'),'utf8');
// The fixture frame runs the real bootstrap, so a keypress inside the tool travels the same path a
// live tool uses: home-frame.js turns Escape into the bridge message the host acts on.
const frameSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home-frame.js'),'utf8');

const ORIGIN='https://mothership.test';
const toolUrl=tool=>`${ORIGIN}/admin/home/tools/${tool}/index.html`;
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');
const PAGE_HTML=`<!doctype html><html><body>${homeApp({tag:'main'})}</body></html>`;
// The shell only accepts a frame that identifies itself, otherwise it shows a load error instead.
const bridgedTool=tool=>`<!doctype html><html><body><script>window.__MOTHERSHIP_FRAME__={widget:"${tool}",state:{},url:"/admin/home/widgets/${tool}",csrf:"fixture"};<\/script><script>${frameSource}<\/script><p>tool</p><input id="tool-field"><button id="tool-button" type="button">Rotate</button></body></html>`;
const TOOL_HTML=bridgedTool('sphereseam');

// The same tool without the bridge: it identifies itself, so the shell accepts it, but nothing in it
// promises to report work.
const BARE_TOOL_HTML=`<!doctype html><html><body><script>window.__MOTHERSHIP_FRAME__={widget:"sphereseam"};<\/script><p>tool</p><input id="tool-field"></body></html>`;

async function workspacePage(browser,{toolGate=null,toolHtml=TOOL_HTML,tool='sphereseam'}={}){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    let toolLoads=0;
    await page.route(`${ORIGIN}/admin/home`,route=>route.fulfill({contentType:'text/html',body:PAGE_HTML}));
    await page.route(`${ORIGIN}/admin/home/tools/**`,async route=>{toolLoads++;await (typeof toolGate==='function'?toolGate(toolLoads):toolGate);return route.fulfill({contentType:'text/html',body:toolHtml});});
    await page.goto(`${ORIGIN}/admin/home`);
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(({url,id})=>{
        const response=data=>new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});
        const catalog=[{id,title:'SphereSeam',description:'Repair the wrap seam.',icon:id,kind:'tool',settings:{},url}];
        // Every native dialog is counted and suppressed, exactly as a browser does once the user
        // ticks "prevent this page from creating additional dialogs".
        window.__nativeDialogs=0;
        window.confirm=()=>{window.__nativeDialogs++;return undefined;};
        window.fetch=async address=>String(address).endsWith('/layout')
            ? response({catalog,widgets:[{id,settings:{}}],links:[]})
            : response(window.__homeAnswer(address)??{});
    },{url:toolUrl(tool),id:tool});
    await page.addScriptTag({content:stateSource});
    await page.addScriptTag({content:homeSource});
    // Every bridge message, kept in arrival order. The host's own listener was registered first, so
    // it has handled a message by the time it is recorded here.
    await page.evaluate(()=>{window.__heard=[];window.addEventListener('message',event=>{if(event.data?.protocol==='mothership-widget/v1')window.__heard.push({type:event.data.type,payload:event.data.payload});});});
    await page.locator(`[data-open="${tool}"]`).waitFor();
    return {page,toolLoads:()=>toolLoads};
}

const openWorkspace=async(page,tool='sphereseam')=>{
    await page.locator(`[data-open="${tool}"]`).click();
    await page.locator('.agh-workspace [data-tool-close]').waitFor();
};

// Messages are posted from inside the tool document so they travel the shell's real bridge: the
// host only accepts a message whose source is that frame's window and whose origin is its own.
// The shell appends the iframe with its src in the same tick as the header, so openWorkspace() can
// resolve while the frame is still about:blank. Resolve it from the element and wait for the tool
// document to commit and load, rather than scanning page.frames() for a URL that may not exist yet.
const toolFrame=async page=>{
    const frame=await (await page.locator('.agh-workspace iframe').elementHandle()).contentFrame();
    await frame.waitForURL(url=>url.pathname.startsWith('/admin/home/tools/'));
    return frame;
};
const postFromTool=async(page,message)=>(await toolFrame(page)).evaluate(
    payload=>parent.postMessage({protocol:'mothership-widget/v1',...payload},location.origin),message);
// The bridge has told the host that it reports work; `count` is how many such promises to wait for
// (one per tool document loaded).
const bridgeReady=(page,count=1)=>page.waitForFunction(wanted=>window.__heard.filter(message=>message.type==='ready'&&message.payload?.reportsWork===true).length>=wanted,count);
// Type into the tool, as a person would, and wait until the host has been told.
const holdWork=async page=>{
    const before=await page.evaluate(()=>window.__heard.filter(message=>message.type==='dirty').length);
    await (await toolFrame(page)).locator('#tool-field').fill('work');
    await page.waitForFunction(count=>window.__heard.filter(message=>message.type==='dirty').length>count,before);
};
const waitForLoads=async(toolLoads,target)=>{
    const deadline=Date.now()+5000;
    while(toolLoads()<target&&Date.now()<deadline)await new Promise(resolve=>setTimeout(resolve,25));
    return toolLoads();
};

browserTest('toolFrame waits for a tool document that has not committed yet',async()=>{
    // The flake this covers (run 35917255339): the helper read page.frames() while the iframe was
    // still uncommitted and returned undefined. Hold the tool response to pin that state open.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        let release;
        const {page,toolLoads}=await workspacePage(browser,{toolGate:new Promise(resolve=>{release=resolve;})});
        await openWorkspace(page);
        assert.equal(await waitForLoads(toolLoads,1),1,'the tool request is in flight');
        const pending=await (await page.locator('.agh-workspace iframe').elementHandle()).contentFrame();
        assert.equal(pending.url().includes('/admin/home/tools/'),false,'the frame has not committed the tool document');

        const resolved=toolFrame(page);
        release();
        const frame=await resolved;
        assert.equal(new URL(frame.url()).pathname,new URL(toolUrl('sphereseam')).pathname);
        assert.equal(await frame.locator('#tool-field').count(),1);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('the popup still closes when the browser suppresses native dialogs',async()=>{
    // The regression this covers: both exits were gated behind window.confirm, so a suppressed
    // dialog left the popup with no way out and Home inert behind it.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);
        assert.equal(await page.locator('.agh-workspace').count(),1);
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),true);

        // Close tool asks once the tool holds work.
        await holdWork(page);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Close this tool? Work you have not exported is discarded.');
        assert.equal(await page.locator('.agh-workspace [data-tool-actions]').isVisible(),false);

        await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),false);
        assert.equal(await page.evaluate(()=>document.activeElement?.getAttribute('data-open')),'sphereseam');
        assert.equal(await page.evaluate(()=>window.__nativeDialogs),0,'no exit may depend on a native dialog');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Close tool asks only when the tool reported work',async()=>{
    // PostHog review 2026-11-11, decision B: 9 of 11 recorded closes paid a second "Discard and
    // close" click, most of them for a tool nobody had done anything in.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        const closed=()=>page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        // Opened, looked at, closed: one click, and Home is usable again.
        await openWorkspace(page);
        await bridgeReady(page);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await closed();
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),false);
        assert.equal(await page.evaluate(()=>document.activeElement?.getAttribute('data-open')),'sphereseam');

        // Opened again and worked in: the question comes back, and Cancel keeps the tool and its work.
        await openWorkspace(page);
        await bridgeReady(page,2);
        await holdWork(page);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Close this tool? Work you have not exported is discarded.');
        await page.locator('.agh-workspace [data-tool-confirm-cancel]').click();
        assert.equal(await (await toolFrame(page)).locator('#tool-field').inputValue(),'work');

        // Escape still asks whatever the tool holds, so a stray key never closes a tool. A fresh
        // popup first: discarding the work closes this one, and the next has none.
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
        await closed();
        await openWorkspace(page);
        await bridgeReady(page,3);
        await page.keyboard.press('Escape');
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('.agh-workspace').count(),1);
        assert.equal(await page.evaluate(()=>window.__nativeDialogs),0);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('while an accepted Reload has not arrived, Close tool asks',async()=>{
    // Review finding: accepting Reload forgets the work the old document reported, and that
    // document stays usable until the new one arrives. A button edit made there is not reported
    // (the tool reported its work once, when it started), so Close tool must not trust the old
    // document's promise in that gap.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        let release;
        const held=new Promise(resolve=>{release=resolve;});
        const {page,toolLoads}=await workspacePage(browser,{toolGate:load=>load===2?held:null});
        await openWorkspace(page);
        await bridgeReady(page);
        await holdWork(page);
        const before=toolLoads();
        await page.locator('.agh-workspace [data-tool-reload]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
        assert.equal(await waitForLoads(toolLoads,before+1),before+1);
        // Still the old document: a button there changes something the host never hears about.
        await (await toolFrame(page)).locator('#tool-button').click();
        // Review finding: the old document's ready can arrive only now. Its promise is not taken again.
        const readies=()=>page.evaluate(()=>window.__heard.filter(message=>message.type==='ready').length);
        const heardReadies=await readies();
        await postFromTool(page,{type:'ready',payload:{reportsWork:true}});
        await page.waitForFunction(count=>window.__heard.filter(message=>message.type==='ready').length>count,heardReadies);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('.agh-workspace').count(),1);
        await page.locator('.agh-workspace [data-tool-confirm-cancel]').click();

        // The new document arrives untouched and makes its own promise: one click closes it.
        release();
        await bridgeReady(page,3);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a tool that never promised to report work is still asked about',async()=>{
    // Without the bridge nothing tells the host when work starts, so "no work reported" proves
    // nothing and the question stays.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser,{toolHtml:BARE_TOOL_HTML});
        await openWorkspace(page);
        await (await toolFrame(page)).locator('#tool-field').fill('work the host never hears about');
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('.agh-workspace').count(),1);
        await page.close();
    } finally { await browser.close(); }
});

// The tool reports its own parameters, as a tool may when it finishes loading and after each change.
const reportParams=async page=>{
    const before=await page.evaluate(()=>window.__heard.filter(message=>message.type==='params').length);
    await postFromTool(page,{type:'params'});
    await page.waitForFunction(count=>window.__heard.filter(message=>message.type==='params').length>count,before);
};
// The stubbed native confirm answers nothing, so a guarded Home reports that it cannot be left.
const canLeave=async page=>Boolean(await page.evaluate(()=>window.__mothershipHomeCanLeave()));

browserTest('a button pressed in a tool is not work; its own reports decide',async()=>{
    // A click that changes nothing raises no input event and must not bring the question back.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);
        await bridgeReady(page);
        await (await toolFrame(page)).locator('#tool-button').click();
        await reportParams(page);
        assert.equal(await page.evaluate(()=>window.__heard.filter(message=>message.type==='dirty').length),0);
        assert.equal(await canLeave(page),true);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        await page.close();
    } finally { await browser.close(); }
});

browserTest('parameters reported by a frame without the bridge still count as work',async()=>{
    // Nothing in such a frame says when a person starts work, so its own report is all Home has.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser,{toolHtml:BARE_TOOL_HTML});
        await openWorkspace(page);
        await toolFrame(page);
        assert.equal(await canLeave(page),true);
        await reportParams(page);
        assert.equal(await canLeave(page),false);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a tool that declares it holds no work closes in one click and never arms a prompt',async()=>{
    // PostHog review 2026-11-11: staff closing Brand Assets were asked to discard work that
    // cannot exist, on every close. The tool opts out in its ready message, so even something
    // typed into it (a search) is not work.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);
        // The host's own listener was registered first, so it has handled a message by the time
        // this later listener sees it.
        const heard=type=>page.evaluate(wanted=>new Promise(resolve=>window.addEventListener('message',function listen(event){
            if(event.data?.type!==wanted)return;window.removeEventListener('message',listen);resolve();})),type);
        const ready=heard('ready');
        await postFromTool(page,{type:'ready',payload:{holdsWork:false}});
        await ready;

        // A search typed into the tool is not work: the bridge still reports it, the host ignores it.
        const dirty=heard('dirty');
        await (await toolFrame(page)).locator('#tool-field').fill('logo');
        await dirty;
        assert.equal(await page.evaluate(()=>window.__mothershipHomeCanLeave()),true,'leaving Home is not guarded');

        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),false);
        assert.equal(await page.evaluate(()=>document.activeElement?.getAttribute('data-open')),'sphereseam');
        assert.equal(await page.evaluate(()=>window.__nativeDialogs),0,'neither exit asked anything');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('the no-work declaration ends with the document that made it',async()=>{
    // Review finding on the first version of this change: the flag lived on the popup entry, so a
    // reloaded frame that no longer declared it kept closing without asking and ignoring work.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page,toolLoads}=await workspacePage(browser);
        await openWorkspace(page);
        const heard=type=>page.evaluate(wanted=>new Promise(resolve=>window.addEventListener('message',function listen(event){
            if(event.data?.type!==wanted)return;window.removeEventListener('message',listen);resolve();})),type);
        const ready=heard('ready');
        await postFromTool(page,{type:'ready',payload:{holdsWork:false}});
        await ready;

        // Reload still asks (unchanged), and brings in a new document that declares nothing.
        const before=toolLoads();
        await page.locator('.agh-workspace [data-tool-reload]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
        assert.equal(await waitForLoads(toolLoads,before+1),before+1);
        await page.locator('.agh-workspace [data-tool-actions]').waitFor({state:'visible'});
        await page.waitForFunction(()=>document.querySelector('.agh-workspace iframe').contentDocument?.getElementById('tool-field'));

        const dirty=heard('dirty');
        await (await toolFrame(page)).locator('#tool-field').fill('real work');
        await dirty;
        // The stubbed native confirm answers nothing, so a guarded Home reports that it cannot be left.
        const canLeave=async()=>Boolean(await page.evaluate(()=>window.__mothershipHomeCanLeave()));
        assert.equal(await canLeave(),false,'work typed into the new document guards leaving Home');
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Close this tool? Work you have not exported is discarded.');
        assert.equal(await page.locator('.agh-workspace').count(),1,'the reloaded tool is not closed without asking');

        // Each signal the host counts as work is ignored under the declaration. (params is not one
        // of them for a document with the bridge; see reportParams above.)
        await page.locator('.agh-workspace [data-tool-confirm-cancel]').click();
        const say=async message=>{const got=heard(message.type);await postFromTool(page,message);await got;};
        for(const type of ['dirty','media-loaded']){
            await say({type:'ready',payload:{holdsWork:false}});
            await say({type});
            assert.equal(await canLeave(),true,`${type} is ignored while the tool holds no work`);
        }
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));

        // A tool can also change its answer (the Video Downloader, while a fetched file waits to be
        // saved on the device). Saying it holds work is itself a report of work: both exits ask,
        // with no dirty signal. Saying it holds none again takes that back.
        await openWorkspace(page);
        await bridgeReady(page,3);
        await say({type:'ready',payload:{holdsWork:false}});
        await say({type:'ready',payload:{holdsWork:true}});
        assert.equal(await canLeave(),false,'a tool that says it holds work guards leaving Home');
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('.agh-workspace').count(),1);
        await page.locator('.agh-workspace [data-tool-confirm-cancel]').click();
        await say({type:'ready',payload:{holdsWork:false}});
        assert.equal(await canLeave(),true,'the answer changed back');
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a pending prompt and the async status line never overwrite each other',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);
        await holdWork(page);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();

        // Drive the shell's own preference-error handler over the bridge, not the DOM directly, so
        // this also proves that handler still targets the status element and not the prompt.
        await postFromTool(page,{type:'preference-error',payload:{message:'Widget preferences could not be saved.'}});
        await page.waitForFunction(()=>document.querySelector('.agh-workspace .agh-frame-status:not([data-tool-prompt])')?.textContent==='Widget preferences could not be saved.');
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Close this tool? Work you have not exported is discarded.');
        assert.equal(await page.locator('[data-tool-prompt]').isVisible(),true);

        // Cancelling must not restore a stale snapshot over that newer status.
        await page.locator('.agh-workspace [data-tool-confirm-cancel]').click();
        await page.locator('.agh-workspace [data-tool-actions]').waitFor({state:'visible'});
        assert.equal(await page.locator('.agh-workspace .agh-frame-status:not([data-tool-prompt])').textContent(),'Widget preferences could not be saved.');
        assert.equal(await page.locator('[data-tool-prompt]').isVisible(),false);
        assert.equal(await page.locator('.agh-workspace').count(),1,'cancel keeps the tool open');
        assert.equal(await page.evaluate(()=>document.activeElement?.hasAttribute('data-tool-close')),true);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('repeated activation raises one prompt and Tab cycles only the visible controls',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);
        await holdWork(page);
        await page.evaluate(()=>{const button=document.querySelector('.agh-workspace [data-tool-close]');button.click();button.click();});
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]:visible').count(),1);
        assert.equal(await page.locator('.agh-workspace [data-tool-confirm-accept]').textContent(),'Discard and close');

        // Focus starts on Discard and the cycle contains only the prompt's own controls plus the
        // frame. Reload and Close tool are hidden while the prompt is up and must stay unreachable.
        assert.equal(await page.evaluate(()=>document.activeElement?.textContent),'Discard and close');
        assert.deepEqual(
            await page.evaluate(()=>[...document.querySelectorAll('.agh-workspace button,.agh-workspace iframe')]
                .filter(node=>node.tagName==='IFRAME'||node.offsetParent!==null)
                .map(node=>node.tagName==='IFRAME'?'iframe':node.textContent.trim())),
            ['Cancel','Discard and close','iframe'],
        );
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(()=>document.activeElement?.tagName),'IFRAME');

        // Shift+Tab from the first visible control wraps to the end of that same cycle.
        await page.locator('.agh-workspace [data-tool-confirm-cancel]').focus();
        await page.keyboard.press('Shift+Tab');
        assert.equal(await page.evaluate(()=>document.activeElement?.tagName),'IFRAME');
        // Known limit, unchanged by this fix: once focus is inside the frame its keydown never
        // reaches the host trap, so the host cannot wrap focus back out of the frame itself.
        await page.close();
    } finally { await browser.close(); }
});

browserTest('Escape backs out of the prompt, and a second Escape asks again',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);
        await holdWork(page);
        await page.locator('.agh-workspace [data-tool-close]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();

        await page.keyboard.press('Escape');
        await page.locator('.agh-workspace [data-tool-actions]').waitFor({state:'visible'});
        assert.equal(await page.locator('.agh-workspace').count(),1,'a stray Escape never discards work');
        assert.equal(await page.locator('[data-tool-prompt]').isVisible(),false);

        await page.keyboard.press('Escape');
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Close this tool? Work you have not exported is discarded.');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('an Escape raised inside the tool goes through the same prompt, never a silent discard',async()=>{
    // Tools forward their own Escape over the bridge (home-frame.js). That path must behave like
    // the host key: ask first, and back out on a second press, so dismissing a select or the DAM
    // asset picker inside the tool can never destroy unexported work.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await openWorkspace(page);

        // A real keypress inside the tool, so home-frame.js's own emitter is under test.
        await (await toolFrame(page)).locator('#tool-field').focus();
        await page.keyboard.press('Escape');
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Close this tool? Work you have not exported is discarded.');
        assert.equal(await page.locator('.agh-workspace').count(),1,'the frame Escape never closes on its own');

        await page.keyboard.press('Escape');
        await page.locator('.agh-workspace [data-tool-actions]').waitFor({state:'visible'});
        assert.equal(await page.locator('[data-tool-prompt]').isVisible(),false);
        assert.equal(await page.locator('.agh-workspace').count(),1);
        assert.equal(await page.evaluate(()=>window.__nativeDialogs),0);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('an accepted Reload re-navigates the frame and restores the header',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page,toolLoads}=await workspacePage(browser);
        await openWorkspace(page);
        const before=toolLoads();

        await page.locator('.agh-workspace [data-tool-reload]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').waitFor();
        assert.equal(await page.locator('[data-tool-prompt]').textContent(),'Reload this tool? Work you have not exported is discarded.');
        assert.equal(await page.locator('.agh-workspace [data-tool-confirm-accept]').textContent(),'Discard and reload');

        await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
        assert.equal(await waitForLoads(toolLoads,before+1),before+1,'the frame is fetched again');
        await page.locator('.agh-workspace [data-tool-actions]').waitFor({state:'visible'});
        assert.equal(await page.locator('[data-tool-prompt]').isVisible(),false);
        assert.equal(await page.locator('.agh-workspace').count(),1,'reload keeps the tool open');
        // The popup stays open and Home stays inert, so focus must land on a visible control here
        // rather than being stranded on the hidden accept button or falling back to the body.
        assert.equal(await page.evaluate(()=>document.activeElement?.hasAttribute('data-tool-reload')),true);
        assert.equal(await page.evaluate(()=>document.activeElement?.offsetParent!==null),true);
        assert.equal(await page.evaluate(()=>window.__nativeDialogs),0);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a tool that navigates away from itself leaves the popup for the full window',async()=>{
    // The regression this covers: a tool that opened a full admin page from the Home gadget loaded it
    // inside the popup iframe, under a false "This tool could not open" banner, with a second copy of
    // the admin chrome squeezed into the frame (operator report 2026-09-15).
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page}=await workspacePage(browser);
        await page.route(`${ORIGIN}/admin/dam/assets/edit/**`,route=>route.fulfill({contentType:'text/html',body:'<!doctype html><html><body><p data-editor>editor</p></body></html>'}));
        await openWorkspace(page);
        // The "Opening…" line clears on the first load of a tool that identifies itself.
        await page.waitForFunction(()=>document.querySelector('.agh-workspace .agh-frame-status:not([data-tool-prompt])')?.hidden===true);

        await (await toolFrame(page)).evaluate(()=>{window.location.href='/admin/dam/assets/edit/7';});
        await page.waitForURL(`${ORIGIN}/admin/dam/assets/edit/7`);
        assert.equal(await page.locator('[data-editor]').count(),1,'the editor page owns the whole window');
        assert.equal(await page.locator('.agh-workspace').count(),0,'the popup is gone with the Home page');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a tool frame that goes to a file leaves the window on Home, with Reload to get the tool back',async()=>{
    // The regression this covers: in the app installed to an iPhone Home Screen a tool's file link took the popup
    // frame to the file, and the shell then moved the whole window there: a file preview with no Back or Done, and
    // force-quitting as the only way out (operator report 2026-11-11). Only a page leaves the popup for the window.
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const {page,toolLoads}=await workspacePage(browser);
        const png=Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==','base64');
        await page.route(`${ORIGIN}/admin/dam/files/logo.png`,route=>route.fulfill({contentType:'image/png',body:png}));
        await openWorkspace(page);
        const status=page.locator('.agh-workspace .agh-frame-status:not([data-tool-prompt])');
        await page.waitForFunction(()=>document.querySelector('.agh-workspace .agh-frame-status:not([data-tool-prompt])')?.hidden===true);
        await page.evaluate(()=>{window.__home=true;});

        await (await toolFrame(page)).evaluate(()=>{window.location.href='/admin/dam/files/logo.png';});
        await status.filter({hasText:'This tool opened a file here. Use Reload to get the tool back, or Close tool to return to Home.'}).waitFor();
        assert.equal(page.url(),`${ORIGIN}/admin/home`,'the window did not follow the frame to the file');
        assert.equal(await page.evaluate(()=>window.__home),true,'Home is the same document, not a reload');
        assert.equal(await page.locator('.agh-workspace [data-tool-close]').isVisible(),true,'Close tool is still there');

        await page.locator('.agh-workspace [data-tool-reload]').click();
        await page.locator('.agh-workspace [data-tool-confirm-accept]').click();
        await page.waitForFunction(()=>document.querySelector('.agh-workspace .agh-frame-status:not([data-tool-prompt])')?.hidden===true);
        assert.equal(await waitForLoads(toolLoads,2),2,'Reload loaded the tool again');
        assert.equal(await (await toolFrame(page)).evaluate(()=>window.__MOTHERSHIP_FRAME__.widget),'sphereseam');
        await page.close();
    } finally { await browser.close(); }
});
