const {test}=require('node:test');
const assert=require('node:assert/strict');
const {homeApp,homeAnswersSource,homeScripts,homeStyles}=require('./support/home-fixture.cjs');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const launch=()=>require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});

const repo=(name,extra={})=>({repo:name,url:`https://github.com/${name}`,description:`About ${name}`,language:'Python',stars:1200,starsToday:50,rank:1,bestRank:1,firstSeenAt:Date.now()-7200000,lastSeenAt:Date.now(),onList:true,...extra});
const past=Array.from({length:8},(_,index)=>({date:`2026-09-${String(10-index).padStart(2,'0')}`,count:1}));
const today={date:'2026-09-11',status:'ok',updatedAt:Date.now()-3600000,
    repos:[repo('b/two',{description:'<img src=x onerror="window.__owned=1"> fast',url:'javascript:alert(1)'}),repo('a/gone',{onList:false,rank:9,bestRank:2})],
    days:[{date:'2026-09-11',count:2},...past]};
const day={date:'2026-09-10',complete:true,repos:[repo('c/yesterday',{onList:false})]};

async function fixturePage(browser,{trending='success',reducedMotion='no-preference',stories=['1','2','3','4'],viewport={width:1280,height:1000}}={}){
    const page=await browser.newPage({viewport,reducedMotion});
    await page.clock.install();
    await page.setContent(`<!doctype html>${homeApp({superuser:true})}`);
    await page.addStyleTag({content:homeStyles});
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(({trending,today,day,stories})=>{
        const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
        window.__fixture={trending,today,stories,requests:[],release:{},forceMode:'forced'};
        window.fetch=async url=>{
            const address=String(url),fixture=window.__fixture;fixture.requests.push(address);
            if(address.includes('/service/rss/v1/stories'))return response({stories:fixture.stories.map(id=>({id,title:`Story ${id}`,url:`https://example.com/${id}`,source:'Fixture',publishedAt:Date.now()-60000}))});
            if(address.includes('refresh=1')){
                if(fixture.forceMode==='hold')return new Promise(resolve=>{fixture.releaseForced=failed=>resolve(failed?response({message:'GitHub Trending could not be reached. Please try again shortly.'},502):response({...fixture.today,forced:true,refreshedAt:Date.now(),throttledUntil:Date.now()+600000}));});
                if(fixture.forceMode==='throttled-error')return response({...fixture.today,status:'error',forced:false,refreshedAt:Date.now()-900000,throttledUntil:Date.now()+300000});
                const throttled=fixture.forceMode==='throttled';
                return response({...fixture.today,forced:!throttled,refreshedAt:Date.now(),throttledUntil:Date.now()+(throttled?300000:600000)});
            }
            if(address.endsWith('/service/trending/v1/trending'))return fixture.trending==='fail'?response({message:'offline'},503):response(fixture.today);
            if(address.endsWith('/service/trending/v1/trending/2026-09-10'))return response(day);
            const held=address.match(/\/v1\/trending\/(2026-09-0[89])$/);
            if(held)return new Promise(resolve=>{fixture.release[held[1]]=message=>resolve(response({message},503));});
            return response(window.__homeAnswer(address)??{});
        };
    },{trending,today,day,stories});
    for(const content of homeScripts)await page.addScriptTag({content});
    return page;
}
const pollSignal=page=>page.clock.fastForward('05:15');
const settle=page=>page.evaluate(()=>Promise.all(document.querySelector('[data-signal]').getAnimations({subtree:true}).map(animation=>animation.finished.catch(()=>{}))));

browserTest('a Trending repository opens its details and keeps a feed from writing markup or script links',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{reducedMotion:'reduce'});
        const card=page.locator('[data-signal]');
        await card.getByText('b/two',{exact:true}).waitFor();
        assert.equal(await card.locator('.hm-repo').count(),2);
        assert.equal(await card.locator('.hm-repo.is-off').count(),1);
        assert.match(await card.locator('.hm-repo.is-off').textContent(),/No longer on the list/,'leaving the list is said in words');
        assert.equal(await card.locator('img').count(),0,'descriptions render as text');

        await card.locator('.hm-repo').first().click();
        const dialog=page.locator('[data-trend-dialog]');
        assert.equal(await dialog.evaluate(node=>node.open),true);
        assert.equal(await dialog.locator('h2').textContent(),'b/two');
        const link=dialog.getByRole('link',{name:/Open on GitHub/});
        assert.equal(await link.getAttribute('href'),'https://github.com/b/two','an unsafe address falls back to the repository page');
        assert.equal(await link.getAttribute('target'),'_blank');
        assert.match(await link.getAttribute('rel'),/noopener/);
        await dialog.getByRole('button',{name:'Close',exact:true}).click();
        await page.waitForFunction(()=>!document.querySelector('[data-trend-dialog]').open);
        await page.waitForFunction(()=>document.activeElement?.dataset.trendRepo==='0');
        assert.equal(await page.evaluate(()=>window.__owned),undefined);

        await card.locator('.hm-repo.is-off').click();
        assert.match(await dialog.textContent(),/No longer on the list/);
        await page.keyboard.press('Escape');
        await page.waitForFunction(()=>!document.querySelector('[data-trend-dialog]').open);

        await card.locator('.hm-repo').first().click();
        await dialog.locator('.hm-trend-desc').click();
        assert.equal(await dialog.evaluate(node=>node.open),true,'a click inside the popup leaves it open');
        await page.mouse.click(4,4);
        await page.waitForFunction(()=>!document.querySelector('[data-trend-dialog]').open);
        await page.close();
    }finally{await browser.close();}
});

browserTest('Trending browses past days, caches them and never shows a late failure for another day',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{reducedMotion:'reduce'});
        const card=page.locator('[data-signal]'),select=card.locator('[data-trend-day]'),dialog=page.locator('[data-trend-dialog]');
        await card.getByText('b/two',{exact:true}).waitFor();
        assert.equal(await select.locator('option').count(),9,'today and every earlier day');

        await select.selectOption('2026-09-10');
        await card.getByText('c/yesterday',{exact:true}).waitFor();
        assert.equal(await card.locator('.hm-tr span').nth(1).textContent(),'Stars that day');
        assert.equal(await card.locator('[data-trending-refresh]').isHidden(),true,'only today can be refreshed');
        await card.locator('.hm-repo').first().click();
        assert.doesNotMatch(await dialog.textContent(),/No longer on the list/,'a past day does not claim a live list');
        await page.keyboard.press('Escape');
        await page.waitForFunction(()=>!document.querySelector('[data-trend-dialog]').open);

        await select.selectOption('');
        await card.getByText('b/two',{exact:true}).waitFor();
        await select.selectOption('2026-09-10');
        await card.getByText('c/yesterday',{exact:true}).waitFor();
        assert.equal((await page.evaluate(()=>window.__fixture.requests)).filter(address=>address.endsWith('/2026-09-10')).length,1,'past days are cached');

        await select.selectOption('2026-09-09');
        await select.selectOption('2026-09-08');
        await page.waitForFunction(()=>window.__fixture.release['2026-09-08']&&window.__fixture.release['2026-09-09']);
        await page.evaluate(()=>window.__fixture.release['2026-09-08']('Sep 8 failed'));
        await card.getByText('Sep 8 failed').waitFor();
        await page.evaluate(()=>window.__fixture.release['2026-09-09']('Sep 9 failed'));
        await page.waitForTimeout(150);
        assert.match(await card.textContent(),/Sep 8 failed/);
        assert.doesNotMatch(await card.textContent(),/Sep 9 failed/);
        await page.close();
    }finally{await browser.close();}
});

browserTest('Refresh forces GitHub, reports each outcome honestly and keeps its width',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{reducedMotion:'reduce'});
        const card=page.locator('[data-signal]'),button=card.locator('[data-trending-refresh]'),note=card.locator('[data-refresh-status]');
        await card.getByText('b/two',{exact:true}).waitFor();
        const width=await button.evaluate(node=>node.getBoundingClientRect().width);

        await page.evaluate(()=>{window.__fixture.forceMode='hold';});
        await button.click();
        await page.waitForFunction(()=>typeof window.__fixture.releaseForced==='function');
        assert.equal(await button.getAttribute('aria-busy'),'true');
        assert.equal(await card.getByRole('button',{name:'Refreshing…'}).count(),1);
        assert.equal(await button.evaluate(node=>node.getBoundingClientRect().width),width,'working does not widen the button');
        await page.evaluate(()=>window.__fixture.releaseForced());
        await card.getByRole('button',{name:'Refreshed'}).waitFor();
        assert.equal(await button.evaluate(node=>node.getBoundingClientRect().width),width,'the result does not widen the button');
        assert.equal((await page.evaluate(()=>window.__fixture.requests)).some(address=>address.includes('/service/trending/v1/trending?refresh=1')),true);
        await page.clock.fastForward(2000);
        await card.getByRole('button',{name:'Refresh',exact:true}).waitFor();

        await page.evaluate(()=>{window.__fixture.releaseForced=null;window.__fixture.forceMode='hold';});
        await button.click();
        await page.waitForFunction(()=>typeof window.__fixture.releaseForced==='function');
        await page.evaluate(()=>window.__fixture.releaseForced(true));
        await note.getByText('GitHub Trending could not be reached. Please try again shortly.',{exact:true}).waitFor();
        assert.equal(await card.getByRole('button',{name:'Refreshed'}).count(),0,'a failed fetch never claims success');
        assert.equal(await card.getByText('b/two',{exact:true}).count(),1,'the last good list stays');
        assert.equal(await card.locator('.hm-tr span').nth(1).textContent(),'Out of date');

        await page.evaluate(()=>{window.__fixture.forceMode='throttled';});
        await button.click();
        await note.getByText(/Fresh as of .+, next check in \d+ min/).waitFor();

        await page.evaluate(()=>{window.__fixture.forceMode='throttled-error';});
        await button.click();
        await note.getByText('GitHub is still unreachable.',{exact:true}).waitFor();
        assert.doesNotMatch(await note.textContent(),/Fresh as of/,'a cached failure is never called fresh');
        await page.close();
    }finally{await browser.close();}
});

browserTest('Trending offers its own Retry when it has never answered',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{trending:'fail',reducedMotion:'reduce'});
        const card=page.locator('[data-signal]');
        await card.getByText('Trending is offline').waitFor();
        await card.getByRole('link',{name:/Story 1/}).waitFor();
        await page.evaluate(()=>{window.__fixture.trending='success';});
        await card.getByRole('button',{name:'Retry'}).click();
        await card.getByText('b/two',{exact:true}).waitFor();
        await card.getByRole('button',{name:'Refreshed'}).waitFor();
        await page.close();
    }finally{await browser.close();}
});

browserTest('a long Trending list opens on request and past the first rows keeps each repository reachable',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{reducedMotion:'reduce'});
        const card=page.locator('[data-signal]');
        await card.getByText('b/two',{exact:true}).waitFor();
        assert.equal(await card.locator('[data-trend-all]').isHidden(),true,'a short list has nothing more to show');
        await page.evaluate(()=>{const fixture=window.__fixture;fixture.today={...fixture.today,repos:Array.from({length:6},(_,index)=>({...fixture.today.repos[0],repo:`o/r${index}`,url:`https://github.com/o/r${index}`}))};});
        await pollSignal(page);
        await card.getByText('o/r0',{exact:true}).waitFor();
        assert.equal(await card.locator('.hm-repo').count(),3);
        await card.getByRole('button',{name:'Show all 6'}).click();
        assert.equal(await card.locator('.hm-repo').count(),6);
        await card.locator('.hm-repo').nth(5).click();
        assert.equal(await page.locator('[data-trend-dialog] h2').textContent(),'o/r5');
        await page.close();
    }finally{await browser.close();}
});

browserTest('rows hold still under a reader, and new stories dock in once nobody is reading',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser);
        const card=page.locator('[data-signal]');
        await card.getByRole('link',{name:/Story 1/}).waitFor();
        await settle(page);
        const tops=()=>card.locator('.hm-story').evaluateAll(rows=>rows.map(row=>Math.round(row.getBoundingClientRect().top)));
        const height=()=>card.evaluate(node=>Math.round(node.getBoundingClientRect().height));
        const before=await tops(),cardHeight=await height();

        await card.hover();
        await page.evaluate(()=>{window.__fixture.stories=['5','1','2','3'];});
        await pollSignal(page);
        const show=card.getByRole('button',{name:/1 new story/});
        await show.waitFor();
        assert.deepEqual(await tops(),before,'no row moves while someone reads');
        assert.equal(await height(),cardHeight,'the waiting line takes the place of the updated time');
        assert.equal(await card.getByRole('link',{name:/Story 5/}).count(),0);

        await page.mouse.move(2,2);
        await pollSignal(page);
        await card.getByRole('link',{name:/Story 5/}).waitFor();
        const moving=await card.locator('.hm-story').evaluateAll(rows=>rows.filter(row=>row.getAnimations().length).length);
        assert.ok(moving>0,'the new row and its neighbours animate');
        await settle(page);
        await page.waitForFunction(()=>document.querySelectorAll('[data-signal-list] .hm-story').length===4);
        assert.deepEqual(await card.locator('.hm-story b').allTextContents(),['Story 5','Story 1','Story 2','Story 3']);
        assert.equal(await height(),cardHeight,'the list keeps its height after the last row leaves');
        await page.close();
    }finally{await browser.close();}
});

browserTest('nothing moves when nothing changed, and a changed Trending list waits for the reader to leave',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser);
        const card=page.locator('[data-signal]');
        await card.getByText('b/two',{exact:true}).waitFor();
        await settle(page);
        await pollSignal(page);
        await page.waitForFunction(()=>window.__fixture.requests.filter(address=>address.includes('/service/rss/')).length>=2);
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(resolve)));
        const rowsMoving=await card.locator('.hm-story,.hm-repo').evaluateAll(rows=>rows.filter(row=>row.getAnimations().length).length);
        assert.equal(rowsMoving,0,'a poll with the same answer moves no row');

        await card.locator('.hm-repo').first().hover();
        await page.evaluate(()=>{const fixture=window.__fixture;fixture.today={...fixture.today,repos:[fixture.today.repos[1],fixture.today.repos[0]]};});
        await pollSignal(page);
        await page.waitForFunction(()=>window.__fixture.requests.filter(address=>address.includes('/service/rss/')).length>=3);
        await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(resolve)));
        assert.match(await card.locator('.hm-repo').first().textContent(),/b\/two/,'the list holds under the pointer');

        await page.mouse.move(2,2);
        await page.waitForFunction(()=>document.querySelector('[data-signal] .hm-repo b')?.textContent==='a/gone');
        await page.close();
    }finally{await browser.close();}
});

browserTest('on a phone the Signal sheet fits the screen however long a description runs',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{reducedMotion:'reduce',viewport:{width:390,height:844}});
        await page.evaluate(()=>{const fixture=window.__fixture;fixture.today={...fixture.today,repos:[{...fixture.today.repos[0],description:'A very long description that never wraps and would otherwise push the whole card past the edge of the phone screen'}]};});
        await page.locator('[data-glance-open="signal"]').click();
        const card=page.locator('[data-signal]');
        await card.locator('.hm-repo').first().waitFor();
        const fit=await page.locator('[data-side-sheet]').evaluate(sheet=>({scroll:sheet.scrollWidth,client:sheet.clientWidth}));
        assert.equal(fit.scroll,fit.client,'the sheet does not scroll sideways');
        await page.close();
    }finally{await browser.close();}
});

browserTest('reduced motion keeps every change and moves nothing',async()=>{
    const browser=await launch();
    try{
        const page=await fixturePage(browser,{reducedMotion:'reduce'});
        const card=page.locator('[data-signal]');
        await card.getByRole('link',{name:/Story 1/}).waitFor();
        await page.evaluate(()=>{window.__fixture.stories=['5','1','2','3'];});
        await pollSignal(page);
        await card.getByRole('link',{name:/Story 5/}).waitFor();
        const travel=await card.evaluate(node=>node.getAnimations({subtree:true}).flatMap(animation=>animation.effect.getKeyframes()).filter(frame=>frame.transform&&frame.transform!=='none').length);
        assert.equal(travel,0,'no animation carries a transform');
        assert.equal(await card.locator('.hm-story').count(),4);
        await page.close();
    }finally{await browser.close();}
});
