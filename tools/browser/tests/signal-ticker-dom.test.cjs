const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const modulePath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
(modulePath?test:test.skip)('ticker DOM stays quiet initially, alerts once, persists through navigation and retains cached content offline',async()=>{
    const {chromium}=require(modulePath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage({viewport:{width:390,height:844}});
        page.setDefaultTimeout(5000);
        page.on('pageerror',error=>console.error(error.message));
        let now=Date.UTC(2026,8,7),fail=false,requests=0;
        let stories=[{id:'1',source:'OpenAI News',title:'Introducing GPT-6',url:'https://openai.com/one',publishedAt:now-60000},{id:'older',source:'OpenAI News',title:'Introducing projects in ChatGPT',url:'https://openai.com/projects',publishedAt:now-120000}];
        stories.push({id:'oldest',source:'OpenAI News',title:'Introducing shared projects in ChatGPT',url:'https://openai.com/shared',publishedAt:now-180000});
        const blade=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/components/signal-ticker.blade.php'),'utf8').split('@pushOnce')[0].replace(/\{\{ auth\('admin'\)->id\(\) \}\}/,'1').replace(/\{\{ url\(.*?\) \}\}/,'/api');
        const script=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/widgets/rss/ticker.js'),'utf8');
        const css=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/widgets/rss/ticker.css'),'utf8');
        await page.route('http://signal.test/**',async route=>{
            if(route.request().url().endsWith('/slow-image')) {
                await new Promise(resolve=>setTimeout(resolve,250));
                return route.fulfill({status:404,body:''});
            }
            if(route.request().url().includes('/api/')){
                requests++;
                return route.fulfill({status:fail?503:200,contentType:'application/json',body:JSON.stringify({stories,sources:[{id:'openai',label:'OpenAI',status:'ok',lastSuccessAt:now},{id:'anthropic',label:'Anthropic',status:'error'},{id:'codex',label:'Codex resets (Sottiaux)',status:'unavailable'}]})});
            }
            // Mothership's Vue app mounts on window load and replaces its template DOM.
            return route.fulfill({contentType:'text/html; charset=utf-8',body:'<html><head><meta charset="utf-8"><style>'+css+'</style></head><body>'+blade+'<img src="/slow-image" alt=""><script>window.addEventListener("load",()=>{const el=document.querySelector("[data-signal-ticker]");el.replaceWith(el.cloneNode(true));});</script><script>'+script+'</script></body></html>'});
        });
        await page.addInitScript(({now})=>{window.fixtureNow=now;Date.now=()=>window.fixtureNow;},{now});
        await page.goto('http://signal.test/first');
        await page.getByRole('link',{name:'OpenAI · Introducing GPT-6'}).waitFor();
        assert.equal(await page.locator('[data-signal-toast]').isVisible(),false);
        assert.equal(requests,1);
        await page.getByRole('button',{name:'Announcement alerts',exact:true}).click();
        assert.match(await page.locator('[data-signal-list]').innerText(),/caught up/);
        assert.equal(await page.locator('[data-signal-health]').innerText(),'Sources 1/3');
        assert.match(await page.locator('[data-signal-sources]').innerText(),/Anthropic: error/);
        await page.setViewportSize({width:1200,height:900});
        await page.waitForFunction(()=>document.querySelectorAll('[data-signal-headline] a').length===2);
        await page.setViewportSize({width:1600,height:900});
        await page.waitForFunction(()=>document.querySelectorAll('[data-signal-headline] a').length===3);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        await page.setViewportSize({width:390,height:844});
        await page.waitForFunction(()=>document.querySelectorAll('[data-signal-headline] a').length===1);
        await page.keyboard.press('Escape');
        now+=360000;
        stories.unshift({id:'2',source:'OpenAI News',title:'Introducing GPT-7',url:'https://openai.com/two',publishedAt:now-1000});
        await page.evaluate(now=>{window.fixtureNow=now;document.dispatchEvent(new Event('visibilitychange'));},now);
        await page.locator('[data-signal-toast]').waitFor({state:'visible'});
        assert.equal(await page.locator('[data-signal-badge]').innerText(),'1');
        await page.goto('http://signal.test/second');
        await page.locator('[data-signal-badge]').waitFor({state:'visible'});
        assert.equal(requests,2);
        assert.equal(await page.locator('[data-signal-toast]').isVisible(),false);
        await page.getByRole('button',{name:'Announcement alerts, 1 unread'}).click();
        await page.getByRole('button',{name:'Mark all read'}).click();
        assert.equal(await page.locator('[data-signal-badge]').isVisible(),false);
        fail=true;now+=360000;
        await page.evaluate(now=>{window.fixtureNow=now;document.dispatchEvent(new Event('visibilitychange'));},now);
        await page.getByText('Feed connection unavailable · showing last updates').waitFor();
        assert.match(await page.locator('[data-signal-headline]').innerText(),/GPT-7/);
        assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),false);
        assert.equal(await page.getByRole('button',{name:'Pause',exact:true}).count(),0);
        await page.evaluate(blade=>{
            document.dispatchEvent(new CustomEvent('mothership:navigate:before'));
            const el=document.querySelector('[data-signal-ticker]');
            el.outerHTML=blade;
            history.pushState({},'', '/library');
            document.dispatchEvent(new CustomEvent('mothership:navigate:success'));
        },blade);
        await page.getByRole('button',{name:'Announcement alerts',exact:true}).click();
        await page.locator('[data-signal-panel]').waitFor({state:'visible'});
        assert.match(await page.locator('[data-signal-headline]').innerText(),/GPT-7/);
        assert.match(await page.locator('[data-signal-list]').innerText(),/caught up/);
    } finally {await browser.close();}
});
