const {test}=require('node:test');
const assert=require('node:assert/strict');
const {homeApp,homeAnswersSource,homeScripts}=require('./support/home-fixture.cjs');

const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;

// Signal reads the studio's own feeds, so only a full-access account has it.
async function fixturePage(browser,{superuser=true}={}){
    const page=await browser.newPage({viewport:{width:1280,height:900},reducedMotion:'reduce'});
    await page.clock.install();
    await page.setContent(homeApp({superuser}));
    await page.addScriptTag({content:homeAnswersSource});
    await page.evaluate(()=>{
        const response=(data,status=200)=>new Response(JSON.stringify(data),{status,headers:{'Content-Type':'application/json'}});
        const story=(id,title,url=`https://example.com/${id}`)=>({id,title,url,source:'Fixture',publishedAt:Date.now()-60000});
        window.__feed={mode:'first',story,requests:[]};
        window.fetch=async url=>{
            const address=String(url);window.__feed.requests.push(address);
            if(address.includes('/service/rss/v1/stories')){
                if(window.__feed.mode==='fail')return response({message:'offline'},503);
                if(window.__feed.mode==='unsafe')return response({stories:[story('bad','Unsafe headline','javascript:window.__owned=1')]});
                if(window.__feed.mode==='empty')return response({stories:[]});
                return response({stories:window.__feed.mode==='second'?[story('2','Second headline'),story('1','First headline')]:[story('1','First headline')]});
            }
            if(address.includes('/service/trending/v1/trending'))return response({repos:[{repo:'org/project',description:'<img src=x onerror="window.__owned=1"> fast',starsToday:1234}]});
            return response(window.__homeAnswer(address)??{});
        };
    });
    for(const content of homeScripts)await page.addScriptTag({content});
    return page;
}
const pollSignal=page=>page.clock.fastForward('05:15');

browserTest('Signal lists stories, holds new ones behind a row and shows them when asked',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        const card=page.locator('[data-signal]');
        await card.getByRole('link',{name:/First headline/}).waitFor();
        assert.equal(await card.locator('.hm-story').first().getAttribute('target'),'_blank');
        // Rows hold still while someone reads them.
        await page.evaluate(()=>{window.__feed.mode='second';});
        await pollSignal(page);
        await card.getByRole('button',{name:/1 new story/}).waitFor();
        assert.equal(await card.getByRole('link',{name:/Second headline/}).count(),0);
        await card.locator('[data-signal-show]').click();
        await card.getByRole('link',{name:/Second headline/}).waitFor();
        assert.equal(await card.locator('[data-signal-show]').count(),0);
        await page.close();
    }finally{await browser.close();}
});

browserTest('Signal says when it is not answering, keeps the last stories and recovers on Try again',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        const card=page.locator('[data-signal]');
        await card.getByRole('link',{name:/First headline/}).waitFor();
        await page.evaluate(()=>{window.__feed.mode='fail';});
        await pollSignal(page);
        await card.getByText('Not answering',{exact:true}).waitFor();
        assert.equal(await card.getByRole('link',{name:/First headline/}).count(),1,'the last stories stay on screen');
        await page.evaluate(()=>{window.__feed.mode='first';});
        await card.locator('[data-signal-retry]').click();
        await card.locator('[data-signal-retry]').waitFor({state:'detached'});
        assert.equal(await card.getByText('Not answering',{exact:true}).count(),0);
        await page.close();
    }finally{await browser.close();}
});

browserTest('a feed cannot write a script link or markup into Signal',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        const card=page.locator('[data-signal]');
        await card.getByRole('link',{name:/First headline/}).waitFor();
        await page.evaluate(()=>{window.__feed.mode='unsafe';});
        await pollSignal(page);
        await card.locator('[data-signal-show]').click();
        await card.getByText('Unsafe headline').waitFor();
        assert.equal(await card.locator('a.hm-story').count(),0,'a story without a web address is plain text');
        assert.equal(await card.locator('.hm-repo img').count(),0);
        assert.match(await card.locator('.hm-repo').textContent(),/<img src=x/);
        assert.match(await card.locator('.hm-repo .hm-mono').textContent(),/^\+1\.2K$/);
        assert.equal(await page.evaluate(()=>window.__owned),undefined);
        await page.close();
    }finally{await browser.close();}
});

browserTest('an account without full access never requests or sees Signal',async()=>{
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser,{superuser:false});
        await page.locator('[data-glance-open="away"]').waitFor();
        await pollSignal(page);
        assert.deepEqual((await page.evaluate(()=>window.__feed.requests)).filter(address=>address.includes('/service/')),[]);
        assert.equal(await page.locator('[data-signal]').isHidden(),true);
        assert.equal(await page.locator('[data-glance-open="signal"]').count(),0);
        await page.close();
    }finally{await browser.close();}
});
