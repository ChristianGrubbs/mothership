const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const stateSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home-state.js'),'utf8');
const homeSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home.js'),'utf8');

// Signal is one of the Studio admin cards, so the fixture account has full access.
async function fixturePage(browser){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    await page.setContent(homeApp({superuser:true}));
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(()=>{
        const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
        const catalog=[{id:'rss',title:'Signal',description:'Feeds',icon:'rss',kind:'tool',settings:{count:5,feed_id:null}}];
        window.__homeFixture={storyMode:'success',stories:0,releaseStory:null};
        window.fetch=async url=>{
            const address=String(url);
            if(address.endsWith('/layout'))return response({catalog,widgets:[{id:'rss',settings:{count:5,feed_id:null}}],links:[]});
            if(address.includes('/v1/stories')){
                if(window.__homeFixture.storyMode==='hold-failure')return new Promise(resolve=>window.__homeFixture.releaseStory=()=>resolve(response({message:'offline'},503)));
                if(window.__homeFixture.storyMode==='fail')return response({message:'offline'},503);
                // 'repeat' returns the same story id on every call, so a second refresh in this mode
                // genuinely has nothing new — the only way to observe "No new stories" honestly.
                if(window.__homeFixture.storyMode==='repeat')return response({stories:[{id:'pinned',title:'Pinned headline',url:'https://example.com/pinned',source:'Fixture',publishedAt:Date.now()-60000}]});
                const number=++window.__homeFixture.stories;return response({stories:[{id:String(number),title:'Headline '+number,url:'https://example.com/'+number,source:'Fixture',publishedAt:Date.now()-60000}]});
            }
            return response(window.__homeAnswer(address)??{});
        };
    });
    await page.addScriptTag({content:stateSource});
    await page.addScriptTag({content:homeSource});
    await page.getByRole('link',{name:/Headline 1/}).waitFor();
    return page;
}

browserTest('actual Home DOM keeps the Signal offline state and refresh feedback across async renders',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const offline=await fixturePage(browser);
        // The card is the quick feed only: the reader has no launcher on Home.
        assert.equal(await offline.locator('[data-id="rss"] [data-open]').count(),0);
        await offline.evaluate(()=>window.__homeFixture.storyMode='fail');
        await offline.locator('[data-signal-refresh]').click();
        await offline.getByText('Offline — showing last update',{exact:true}).waitFor();
        await offline.setViewportSize({width:390,height:800});
        await offline.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        assert.equal(await offline.getByText('Offline — showing last update',{exact:true}).count(),1);
        await offline.evaluate(()=>window.__homeFixture.storyMode='success');
        await offline.locator('[data-signal-refresh]').click();
        // A click's own transient result (not just the poller's silent "Updated …" timestamp) must
        // be visible right where the click happened, not hidden by display:none.
        await offline.getByText(/new stor(y|ies)/).waitFor();
        assert.equal(await offline.locator('[data-id="rss"] [data-refresh-status]').isHidden(),false);
        await offline.getByText('Live feed',{exact:true}).waitFor();
        // A refresh that genuinely returns nothing new must say so honestly, not just "N new
        // stories" every time: two consecutive fetches of the same pinned story id.
        await offline.evaluate(()=>window.__homeFixture.storyMode='repeat');
        await offline.locator('[data-signal-refresh]').click();
        await offline.getByText('Pinned headline',{exact:true}).waitFor();
        await offline.locator('[data-signal-refresh]').click();
        await offline.getByText('No new stories',{exact:true}).waitFor();
        assert.equal(await offline.locator('[data-id="rss"] [data-refresh-status]').isHidden(),false);
        await offline.evaluate(()=>window.__homeFixture.storyMode='hold-failure');
        await offline.locator('[data-signal-refresh]').click();
        // The button disables and announces busy for the whole in-flight request, not just a flash.
        await offline.waitForFunction(()=>document.querySelector('[data-signal-refresh]')?.disabled===true);
        assert.equal(await offline.locator('[data-signal-refresh]').getAttribute('aria-busy'),'true');
        assert.equal(await offline.locator('[data-signal-refresh]').textContent(),'Refreshing…');
        await offline.waitForFunction(()=>typeof window.__homeFixture.releaseStory==='function');
        await offline.setViewportSize({width:800,height:800});
        await offline.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
        await offline.evaluate(()=>window.__homeFixture.releaseStory());
        await offline.getByText('Offline — showing last update',{exact:true}).waitFor();
        // The error from this click renders as the transient status too, and the button re-enables.
        await offline.waitForFunction(()=>document.querySelector('[data-signal-refresh]')?.disabled===false);
        assert.equal(await offline.locator('[data-id="rss"] [data-refresh-status]').textContent(),'offline');
        assert.equal(await offline.locator('[data-id="rss"] [data-refresh-status]').isHidden(),false);
        await offline.close();
    }finally{await browser.close();}
});
