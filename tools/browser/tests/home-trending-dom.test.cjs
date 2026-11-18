const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const stateSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home-state.js'),'utf8');
const homeSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home.js'),'utf8');

const cssSource=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/css/home.css'),'utf8');
const {homeApp,homeAnswersSource}=require('./support/home-fixture.cjs');

const repo=(name,extra={})=>({repo:name,url:'https://github.com/'+name,description:'About '+name,language:'Python',stars:1200,starsToday:50,rank:1,bestRank:1,firstSeenAt:Date.now()-7200000,lastSeenAt:Date.now(),onList:true,...extra});
const past=Array.from({length:8},(_,index)=>({date:`2026-09-${String(10-index).padStart(2,'0')}`,count:1}));

// Trending is one of the Studio admin cards, so the fixture account has full access.
async function fixturePage(browser,{mode='success'}={}){
    const page=await browser.newPage({viewport:{width:1200,height:900}});
    await page.setContent(homeApp({superuser:true,tag:'main'}));
    await page.addStyleTag({content:cssSource});
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(({mode,today,day})=>{
        const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
        const catalog=[{id:'trending',title:'Trending on GitHub',description:'Trending',icon:'trending',kind:'native',settings:{}}];
        // forceMode drives what a ?refresh=1 (the forced-refresh button) request returns, independent
        // of `mode`, which only governs the plain background-poll read.
        window.__trendingFixture={mode,requests:[],release:{},forceMode:'forced'};
        window.fetch=async url=>{
            const address=String(url);window.__trendingFixture.requests.push(address);
            if(address.endsWith('/layout'))return response({catalog,widgets:[{id:'trending',settings:{}}],links:[]});
            const known=window.__homeAnswer(address);if(known)return response(known);
            const base=address.split('?')[0];
            if(base.endsWith('/service/trending/v1/trending')){
                if(address.includes('refresh=1')){
                    const fixture=window.__trendingFixture;
                    // The 'hold'/'fail' bodies below are the exact shape tools/rss/server.py's
                    // /v1/trending?refresh=1 route actually returns for a forced fetch that failed
                    // (see server.py's `if snapshot.get("forced") and snapshot.get("status") ==
                    // "error"` branch, proven end-to-end in tools/rss/test_trending.py's
                    // test_a_forced_refresh_that_fails_is_a_non_2xx_never_a_disguised_success): a
                    // plain {message} body on a non-2xx, never a disguised {forced:true} snapshot.
                    if(fixture.forceMode==='hold')return new Promise(resolve=>{fixture.releaseForced=failed=>resolve(failed?response({message:'GitHub Trending could not be reached. Please try again shortly.'},502):response({...today,forced:true,refreshedAt:Date.now(),throttledUntil:Date.now()+600000}));});
                    if(fixture.forceMode==='fail')return response({message:'GitHub Trending could not be reached. Please try again shortly.'},502);
                    // A throttled call rereads whatever is cached, which can itself still be a
                    // failed/stale snapshot (status:"error") even though the HTTP call itself is a
                    // plain 200 — the browser must inspect `status`, not just the HTTP code, here.
                    if(fixture.forceMode==='throttled-error')return response({...today,status:'error',forced:false,refreshedAt:Date.now()-900000,throttledUntil:Date.now()+300000});
                    const throttled=fixture.forceMode==='throttled';
                    return response({...today,forced:!throttled,refreshedAt:Date.now(),throttledUntil:Date.now()+(throttled?300000:600000)});
                }
                return window.__trendingFixture.mode==='fail'?response({message:'offline'},503):response(today);
            }
            if(address.endsWith('/service/trending/v1/trending/2026-09-10'))return response(day);
            const held=address.match(/\/v1\/trending\/(2026-09-0[89])$/);
            if(held)return new Promise(resolve=>{window.__trendingFixture.release[held[1]]=message=>resolve(response({message},503));});
            return response({message:'missing'},404);
        };
    },{mode,today:{date:'2026-09-11',status:'ok',updatedAt:Date.now()-3600000,intervalSeconds:7200,
        repos:[repo('b/two',{description:'<img src=x onerror="window.__owned=1"> fast',url:'javascript:alert(1)'}),repo('a/gone',{onList:false,rank:9,bestRank:2})],
        days:[{date:'2026-09-11',count:2},...past]},
        day:{date:'2026-09-10',complete:true,repos:[repo('c/yesterday',{onList:false})]}});
    await page.addScriptTag({content:stateSource});
    await page.addScriptTag({content:homeSource});
    return page;
}

browserTest('actual Home DOM lists trending repositories, opens a details popup and browses past days',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        const card=page.locator('[data-id="trending"]');
        await card.getByText('b/two',{exact:true}).waitFor();
        assert.equal(await card.locator('.agh-trend-row').count(),2);
        assert.equal(await card.locator('.agh-trend-row.is-off').count(),1);
        assert.equal(await card.locator('img').count(),0,'descriptions render as text');
        assert.equal(await card.locator('[data-trend-day]').count(),7,'today plus six recent days');
        assert.equal(await card.locator('[data-trend-older] option').count(),3,'placeholder plus two older days');

        await card.locator('.agh-trend-row').first().click();
        const dialog=page.locator('[data-trend-dialog]');
        assert.equal(await dialog.evaluate(node=>node.open),true);
        assert.equal(await dialog.locator('h2').textContent(),'b/two');
        const link=dialog.getByRole('link',{name:/Open on GitHub/});
        assert.equal(await link.getAttribute('href'),'https://github.com/b/two','unsafe URLs fall back to the repository page');
        assert.equal(await link.getAttribute('target'),'_blank');
        assert.match(await link.getAttribute('rel'),/noopener/);
        await dialog.getByRole('button',{name:'Close',exact:true}).click();
        assert.equal(await dialog.evaluate(node=>node.open),false);
        assert.equal(await page.evaluate(()=>document.activeElement?.dataset.trendRepo),'0');
        assert.equal(await page.evaluate(()=>window.__owned),undefined);

        await card.locator('.agh-trend-row.is-off').click();
        assert.match(await dialog.textContent(),/no longer on the list/);
        await page.keyboard.press('Escape');

        await card.locator('[data-trend-day="2026-09-10"]').click();
        await card.getByText('c/yesterday',{exact:true}).waitFor();
        assert.match(await card.locator('.agh-signal-meta').textContent(),/1 repository trended on/);
        assert.equal(await card.locator('[data-trend-day="2026-09-10"]').getAttribute('aria-pressed'),'true');
        await card.locator('.agh-trend-row').first().click();
        assert.doesNotMatch(await dialog.textContent(),/no longer on the list/,'past days do not claim a live list');
        await page.keyboard.press('Escape');

        await card.locator('[data-trend-day=""]').click();
        await card.getByText('b/two',{exact:true}).waitFor();
        await card.locator('[data-trend-day="2026-09-10"]').click();
        await card.getByText('c/yesterday',{exact:true}).waitFor();
        assert.equal((await page.evaluate(()=>window.__trendingFixture.requests)).filter(address=>address.endsWith('/2026-09-10')).length,1,'past days are cached');

        // Day A then day B are requested; B fails first, then A. Only B's failure may show.
        await card.locator('[data-trend-day="2026-09-09"]').click();
        await card.locator('[data-trend-day="2026-09-08"]').click();
        await page.waitForFunction(()=>window.__trendingFixture.release['2026-09-08']&&window.__trendingFixture.release['2026-09-09']);
        await page.evaluate(()=>window.__trendingFixture.release['2026-09-08']('Sep 8 failed'));
        await card.getByText('Sep 8 failed').waitFor();
        await page.evaluate(()=>window.__trendingFixture.release['2026-09-09']('Sep 9 failed'));
        await page.waitForTimeout(150);
        assert.match(await card.textContent(),/Sep 8 failed/);
        assert.doesNotMatch(await card.textContent(),/Sep 9 failed/,'a stale failure for another day never replaces the selected day');

        // Refresh used to only reread the adapter's cache (a fetch GitHub does at most once every
        // two hours), so a click could sit there with nothing to show for up to two hours. It must
        // now force the adapter through ?refresh=1 and show its own result.
        await card.locator('[data-trend-day=""]').click();
        await card.getByText('b/two',{exact:true}).waitFor();
        await page.evaluate(()=>{window.__trendingFixture.forceMode='hold';});
        await card.locator('[data-trending-refresh]').click();
        await page.waitForFunction(()=>typeof window.__trendingFixture.releaseForced==='function');
        assert.equal(await card.locator('[data-trending-refresh]').getAttribute('aria-busy'),'true');
        assert.equal(await card.locator('[data-trending-refresh]').isDisabled(),true);
        assert.equal(await card.locator('[data-trending-refresh]').textContent(),'Refreshing…');
        await page.evaluate(()=>window.__trendingFixture.releaseForced());
        await card.getByText('Refreshed',{exact:true}).waitFor();
        // The click's own result is visible in the card as Home draws it, not clipped off by
        // overflow or hidden by display:none.
        assert.equal(await card.locator('[data-refresh-status]').isHidden(),false);
        assert.equal(await card.locator('[data-refresh-status]').evaluate(node=>{const box=node.getBoundingClientRect(),body=node.closest('.agh-card-body').getBoundingClientRect();return box.width>0&&box.left>=body.left-1&&box.right<=body.right+1;}),true);
        assert.equal((await page.evaluate(()=>window.__trendingFixture.requests)).some(address=>address.includes('/service/trending/v1/trending?refresh=1')),true,'the click hit the forced-refresh endpoint');
        assert.equal(await card.locator('[data-trending-refresh]').isDisabled(),false);

        // A forced fetch that fails comes back non-2xx (the real tools/rss/server.py shape, proven
        // by tools/rss/test_trending.py) and must never be reported as "Refreshed", nor clear the
        // stale/offline styling — this is the regression PR #172 review flagged.
        await page.evaluate(()=>{window.__trendingFixture.forceMode='hold';});
        await card.locator('[data-trending-refresh]').click();
        await page.waitForFunction(()=>typeof window.__trendingFixture.releaseForced==='function');
        assert.equal(await card.locator('[data-trending-refresh]').getAttribute('aria-busy'),'true');
        await page.evaluate(()=>window.__trendingFixture.releaseForced(true));
        await card.getByText('GitHub Trending could not be reached. Please try again shortly.',{exact:true}).waitFor();
        assert.doesNotMatch(await card.textContent(),/Refreshed/,'a failed forced fetch never claims success');
        assert.equal(await card.locator('[data-trending-refresh]').isDisabled(),false);
        // The card still shows the last good day (b/two), never wiped by the failed click.
        assert.equal(await card.getByText('b/two',{exact:true}).count(),1);

        await page.evaluate(()=>{window.__trendingFixture.forceMode='throttled';});
        await card.locator('[data-trending-refresh]').click();
        await card.getByText(/Fresh as of .+, next check in \d+ min/).waitFor();

        // A throttled read (still HTTP 200) can surface a cache that is itself an error snapshot;
        // that must read as still-unreachable, never "Fresh as of …".
        await page.evaluate(()=>{window.__trendingFixture.forceMode='throttled-error';});
        await card.locator('[data-trending-refresh]').click();
        await card.getByText('GitHub is still unreachable.',{exact:true}).waitFor();
        assert.doesNotMatch(await card.textContent(),/Fresh as of/,'a throttled read of a stale error snapshot is never reported as fresh');

        await page.evaluate(()=>{window.__trendingFixture.forceMode='fail';});
        await card.locator('[data-trending-refresh]').click();
        await card.getByText('GitHub Trending could not be reached. Please try again shortly.',{exact:true}).waitFor();
        assert.doesNotMatch(await card.textContent(),/Refreshed/,'a failed forced fetch never claims success');
        await page.close();

        const offline=await fixturePage(browser,{mode:'fail'});
        const offlineCard=offline.locator('[data-id="trending"]');
        await offlineCard.getByText('Trending is offline').waitFor();
        await offline.evaluate(()=>{window.__trendingFixture.mode='success';});
        await offlineCard.getByRole('button',{name:'Retry'}).click();
        await offlineCard.getByText('b/two',{exact:true}).waitFor();
        await offline.close();
    }finally{
        await browser.close();
    }
});
