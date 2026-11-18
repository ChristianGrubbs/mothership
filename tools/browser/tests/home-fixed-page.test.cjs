const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {launchBrowser}=require('../launch-browser.cjs');
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');

// The fixed Home page's own parts: tool tiles, the administrator-only cards, the Send panel and
// the iPhone tile. The workspace popup, Signal, Trending and Studio load have their own files.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const source=name=>fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets',name),'utf8');
const ORIGIN='https://home.test';

const tool=(id,title)=>({id,title,description:`${title} description`,icon:id,kind:'tool',settings:{},url:`${ORIGIN}/tools/${id}/index.html`});
const native=(id,title)=>({id,title,description:`${title} description`,icon:id,kind:'native',settings:{}});
const STATUS_CARDS=[native('system-quickview','Studio & people'),{...tool('rss','Signal'),settings:{count:5,feed_id:null}},native('trending','Trending on GitHub'),native('storage','Storage')];

// `prepare` runs in the page before Home's scripts, for whatever a test puts on window first.
async function mount(browser,{fixture={},catalog=[],prepare=null}={}){
    const page=await browser.newPage({viewport:{width:1280,height:900}});
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.route(`${ORIGIN}/**`,route=>route.fulfill({contentType:'text/html',body:route.request().url().includes('/tools/')?'<!doctype html><p>tool</p>':`<!doctype html>${homeApp(fixture)}`}));
    await page.goto(`${ORIGIN}/admin/home`);
    await page.addStyleTag({content:source('css/home.css')});
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(catalog=>{
        const response=data=>new Response(JSON.stringify(data),{status:200,headers:{'Content-Type':'application/json'}});
        window.__requests=[];
        window.fetch=async(url,options={})=>{
            const address=String(url);window.__requests.push(`${options.method||'GET'} ${address}`);
            if(address.endsWith('/layout'))return response({catalog,widgets:catalog.map(({id,settings})=>({id,settings})),links:[]});
            if(address.endsWith('/data/system-quickview'))return response({metrics:{available:false},presence:{available:true,users:[{id:1,name:'Test',is_current:true}]}});
            if(address.endsWith('/data/storage'))return response({available:true,free:500,total:1000});
            if(address.includes('/service/rss/v1/stories'))return response({stories:[{id:'1',title:'Headline',url:'https://example.com/1',source:'Fixture',publishedAt:Date.now()-60000}]});
            if(address.includes('/service/trending/v1/trending'))return response({date:'2026-09-11',status:'ok',updatedAt:Date.now(),days:[],repos:[{repo:'org/project',url:'https://github.com/org/project',description:'A project',rank:1,starsToday:5,onList:true}]});
            return response(window.__homeAnswer(address)??{});
        };
    },catalog);
    if(prepare)await page.evaluate(prepare);
    for(const file of ['js/home-state.js','js/home-system-quickview.js','js/home.js'])await page.addScriptTag({content:source(file)});
    await page.waitForFunction(()=>window.__requests.some(entry=>entry.endsWith('/layout'))&&!document.querySelector('[data-status]').textContent.trim());
    return {page,errors};
}

browserTest('tools are tiles in the Apps section, in the house order, and a tile opens its workspace',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{catalog:[tool('vectorization','Vectorization'),{...tool('rss','Signal'),settings:{count:5,feed_id:null}},tool('mystery','Mystery tool'),tool('looper','Video Loop Refiner'),tool('sphereseam','SphereSeam')]});
        const tiles=page.locator('[data-section="apps"] ul.agh-app-grid > li');
        // Known tools first in their fixed order, an unknown tool after them, the iPhone tile last.
        // Signal is a tool in the catalog but never a tile.
        assert.deepEqual(await tiles.evaluateAll(items=>items.map(item=>item.querySelector('button').dataset.id)),['looper','sphereseam','vectorization','mystery','phone-app']);
        assert.equal(await page.locator('[data-section="apps"]').isVisible(),true);
        const looper=page.locator('[data-section="apps"] button.agh-app[data-open="looper"]');
        assert.equal(await looper.locator('strong').textContent(),'Video Loop Refiner');
        assert.equal(await looper.locator('small').textContent(),'Smooth video loops');
        assert.equal(await looper.getAttribute('title'),'Video Loop Refiner description');
        assert.equal(await page.locator('[data-open="mystery"] small').textContent(),'Mystery tool description');
        assert.equal(await page.locator('.agh-workspace').count(),0);

        await looper.click();
        const workspace=page.locator('.agh-workspace');
        await workspace.locator('[data-tool-close]').waitFor();
        assert.equal(await workspace.getAttribute('role'),'dialog');
        assert.equal(await workspace.getAttribute('aria-label'),'Video Loop Refiner');
        const frame=new URL(await workspace.locator('iframe').getAttribute('src'));
        assert.equal(frame.origin+frame.pathname,`${ORIGIN}/tools/looper/index.html`);
        assert.equal(frame.searchParams.get('embed'),'1');assert.equal(frame.searchParams.get('account'),'1');
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),true);
        assert.equal(await page.evaluate(()=>document.activeElement?.hasAttribute('data-tool-close')),true);

        // Closing hands focus back to the tile and Home is usable again.
        await workspace.locator('[data-tool-close]').click();await workspace.locator('[data-tool-confirm-accept]').click();
        await page.waitForFunction(()=>!document.querySelector('.agh-workspace'));
        assert.equal(await page.evaluate(()=>document.getElementById('app').hasAttribute('inert')),false);
        assert.equal(await page.evaluate(()=>document.activeElement?.dataset.open),'looper');
        assert.equal(await tiles.count(),5,'a redraw replaces the tiles, never adds to them');
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});

browserTest('only a full-access account gets the Studio admin cards',async()=>{
    const browser=await launchBrowser({playwrightPath});
    const studio=entry=>/\/data\/(system-quickview|storage)|\/service\/(rss|trending)\//.test(entry);
    try{
        // The same layout answer for both accounts: what differs is the account, not the data.
        const user=await mount(browser,{fixture:{superuser:false},catalog:STATUS_CARDS});
        assert.equal(await user.page.locator('[data-mothership-home]').getAttribute('data-superuser'),'0');
        await user.page.evaluate(()=>new Promise(resolve=>setTimeout(resolve,100)));
        assert.equal(await user.page.locator('.agh-card').count(),0);
        assert.equal(await user.page.locator('[data-section="status"]').count(),0);
        assert.deepEqual((await user.page.evaluate(()=>window.__requests)).filter(studio),[],'no Studio admin data is requested');
        // No role menu on right-click either: the browser's own menu is left alone.
        assert.equal(await user.page.locator('[data-section="apps"]').evaluate(node=>node.dispatchEvent(new MouseEvent('contextmenu',{bubbles:true,cancelable:true}))),true);
        assert.deepEqual(user.errors,[]);
        await user.page.close();

        const admin=await mount(browser,{fixture:{superuser:true},catalog:STATUS_CARDS});
        const cards=admin.page.locator('[data-section="status"] [data-slot="status"] > .agh-card');
        await admin.page.getByRole('link',{name:/Headline/}).waitFor();
        await admin.page.getByText('org/project',{exact:true}).waitFor();
        await admin.page.getByText('free space left for files').waitFor();
        await admin.page.getByText('1 person online').waitFor();
        assert.deepEqual(await cards.evaluateAll(nodes=>nodes.map(node=>node.dataset.id)),['system-quickview','rss','trending','storage']);
        assert.deepEqual(await cards.locator('h3').allTextContents(),['Studio load and who is online','Signal news','Trending on GitHub','File storage']);
        assert.equal(await admin.page.locator('[data-section="status"]').isVisible(),true);
        assert.equal(await admin.page.locator('.agh-card').count(),4,'nothing else on the page is a card');
        // Signal is the quick feed only: no reader launcher, and no tile.
        assert.equal(await admin.page.locator('[data-open="rss"]').count(),0);
        assert.equal(await admin.page.locator('[data-id="rss"] .agh-card-footer').count(),0);
        assert.deepEqual(admin.errors,[]);
        await admin.page.close();
    }finally{await browser.close();}
});

// The Transfers runtime as transfers-host.js publishes it: subscribe(listener) calls the listener
// with null until the runtime is mounted, then with a state that carries its own open/openPeer.
const transfersRuntime=()=>{
    const labels={ready:'Ready',connecting:'Connecting…',unavailable:'Transfers is unavailable right now.',sendToDevice:'Send files to :name on :device',activeOne:'1 transfer in progress',active:':count transfers in progress'};
    window.__sendCalls=[];window.__listeners=new Set();window.__released=0;
    window.__state=(readyPeers,extra={})=>({status:'ready',readyPeers,activeCount:0,labels,open:target=>window.__sendCalls.push(['open',target]),openPeer:peerId=>window.__sendCalls.push(['openPeer',peerId]),...extra});
    window.__publish=state=>window.__listeners.forEach(listener=>listener(state));
    window.MothershipTransfers={subscribe(listener){window.__listeners.add(listener);listener(null);return()=>{window.__released++;window.__listeners.delete(listener);};}};
};
const peer=(peerId,name,deviceName)=>({userId:peerId,peerId,name,deviceName});

browserTest('the Send panel lists who is online and hands a click to the Transfers runtime',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const {page,errors}=await mount(browser,{fixture:{transfers:true},prepare:transfersRuntime});
        const panel=page.locator('[data-block="send"] [data-send]');
        // Before the runtime has a state, the panel says it is still looking.
        assert.equal(await panel.textContent(),'Looking for people who are online…');
        assert.equal(await panel.locator('[data-send-peer]').count(),0);

        await page.evaluate(()=>window.__publish(window.__state([{userId:'7',peerId:'peer-a',name:'Ana <b>Lopez</b>',deviceName:'Chrome · Mac'},{userId:'9',peerId:'peer-b',name:'Ben Okafor',deviceName:'Safari · iPhone'}])));
        const rows=panel.locator('.agh-send-list > li');
        assert.equal(await rows.count(),2);
        assert.deepEqual(await rows.locator('strong').allTextContents(),['Ana <b>Lopez</b>','Ben Okafor'],'names render as text');
        assert.deepEqual(await rows.locator('small').allTextContents(),['Chrome · Mac','Safari · iPhone']);
        assert.equal(await panel.locator('b').count(),0);
        const sendBen=panel.locator('[data-send-peer="peer-b"]');
        assert.equal(await sendBen.textContent(),'Send files');
        assert.equal(await sendBen.getAttribute('aria-label'),'Send files to Ben Okafor on Safari · iPhone');
        await sendBen.click();
        assert.deepEqual(await page.evaluate(()=>window.__sendCalls),[['openPeer','peer-b']]);
        // The hero's Send files button opens Transfers with nobody chosen.
        await page.locator('.agh-start [data-start="send"]').click();
        assert.deepEqual(await page.evaluate(()=>window.__sendCalls),[['openPeer','peer-b'],['open',null]]);
        assert.equal(new URL(page.url()).pathname,'/admin/home','with a runtime the button never navigates away');

        // A transfer in progress is announced above the list, and the list stays.
        await page.evaluate(()=>window.__publish(window.__state([{userId:'9',peerId:'peer-b',name:'Ben Okafor',deviceName:'Safari · iPhone'}],{activeCount:1})));
        assert.equal(await panel.locator('.agh-send-active').textContent(),'1 transfer in progress');
        assert.equal(await rows.count(),1);

        // Five people at most; the rest are a pointer to Transfers.
        await page.evaluate(()=>window.__publish(window.__state(Array.from({length:7},(_,index)=>({userId:String(index),peerId:`peer-${index}`,name:`Person ${index}`,deviceName:'Chrome · Mac'})))));
        assert.equal(await rows.count(),5);
        assert.equal(await panel.locator('.agh-section-note').textContent(),'2 more online. Open Transfers to see everyone.');

        await page.evaluate(()=>window.__publish(window.__state([])));
        assert.equal(await panel.locator('.agh-empty-note strong').textContent(),'Nobody else is online right now.');
        assert.equal(await panel.locator('[data-send-peer]').count(),0);

        await page.evaluate(()=>window.__publish(window.__state([],{status:'connecting'})));
        assert.equal(await panel.textContent(),'Connecting…');
        await page.evaluate(()=>window.__publish(window.__state([],{status:'failed'})));
        assert.equal(await panel.textContent(),'Transfers is unavailable right now.');

        // Leaving Home releases the subscription.
        assert.equal(await page.evaluate(()=>window.__released),0);
        await page.evaluate(()=>{document.querySelector('[data-mothership-home]').remove();document.dispatchEvent(new Event('mothership:navigate:success'));});
        assert.equal(await page.evaluate(()=>window.__released),1);
        assert.deepEqual(errors,[]);
        await page.close();

        // Transfers configured but its runtime missing: the panel points at the Transfers page.
        const bare=await mount(browser,{fixture:{transfers:true}});
        assert.equal(await bare.page.locator('[data-send]').textContent(),'Open Transfers to send or receive files.');
        assert.equal(await bare.page.locator('[data-block="send"] [data-send-open]').getAttribute('href'),'/admin/transfers');
        assert.deepEqual(bare.errors,[]);
        await bare.page.close();

        // Transfers not configured: no panel at all.
        const none=await mount(browser,{prepare:transfersRuntime});
        assert.equal(await none.page.locator('[data-block="send"],[data-send]').count(),0);
        assert.equal(await none.page.evaluate(()=>window.__listeners.size),0);
        assert.deepEqual(none.errors,[]);
        await none.page.close();
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
        assert.equal(await dialog.getByRole('heading',{name:'The Mothership on your iPhone'}).isVisible(),true);
        assert.equal(await dialog.locator('.agh-phone-steps li').count(),3);
        assert.equal(await page.locator('.agh-workspace').count(),0,'the tile is not a tool launcher');

        await dialog.locator('[data-phone-close]').click();
        assert.equal(await open(),false);assert.equal(await focused(),true);

        // Keyboard: Enter opens, Escape closes, and focus comes back the same way.
        await page.locator('[data-open="looper"]').focus();
        await tile.focus();await page.keyboard.press('Enter');
        assert.equal(await open(),true);
        await page.keyboard.press('Escape');
        assert.equal(await open(),false);assert.equal(await focused(),true);
        assert.deepEqual(errors,[]);
        await page.close();
    }finally{await browser.close();}
});
