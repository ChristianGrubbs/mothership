const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const script=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/widgets/vectorization/app.js'),'utf8');
const view=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/home/vectorization.blade.php'),'utf8');

test('zero balance, completed download, zoom and denied clipboard work',async()=>{
    assert.ok(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'Set the documented Playwright module path');
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
            .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
            .replace(/data-csrf="[^"]*"/,'data-csrf="x"');
        await page.route('https://vectorization.test/**',route=>{
            const url=route.request().url();
            if(url.endsWith('/state'))return route.fulfill({json:{ready:true,allowance:{unlimited:false,remaining:0,reserved:0},
                results:[{id:'a',status:'completed',created_at:'2026-09-08',svg_url:'/a.svg',pdf_url:'/a.pdf'}]}});
            if(url.includes('a.svg'))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>'});
            return route.fulfill({contentType:'text/html',body});
        });
        await page.goto('https://vectorization.test/');
        await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{value:{writeText:async()=>{throw Error('denied');}},configurable:true}));
        await page.addScriptTag({content:script});
        await page.getByText('0 available · 0 in progress',{exact:true}).waitFor();
        assert.equal(await page.locator('[data-submit-button]').isDisabled(),true);
        await page.locator('[data-history] button').click();
        await page.waitForFunction(()=>!document.querySelector('[data-copy]').disabled);
        assert.equal(await page.locator('[data-svg]').getAttribute('href'),'/a.svg');
        assert.equal(await page.locator('[data-pdf]').getAttribute('href'),'/a.pdf');
        await page.locator('[data-copy]').click();
        assert.match(await page.locator('[data-code]').inputValue(),/^<svg/);
        await page.locator('[data-zoom]').fill('200');await page.locator('[data-zoom]').dispatchEvent('input');
        assert.equal(await page.locator('[data-preview]').evaluate(element=>element.style.width),'200%');
    }finally{await browser.close();}
});

test('unlimited balance permits submission without a remaining count',async()=>{
    assert.ok(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'Set the documented Playwright module path');
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();let submitted=0;
        const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
            .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
            .replace(/data-csrf="[^"]*"/,'data-csrf="x"');
        await page.route('https://vectorization.test/**',route=>{
            const url=route.request().url();
            if(url.endsWith('/state'))return route.fulfill({json:{ready:true,
                allowance:{unlimited:true,remaining:null,reserved:0},results:[]}});
            if(url.endsWith('/submit')){
                submitted++;
                return route.fulfill({status:202,json:{result:{id:'unlimited',status:'queued'}}});
            }
            return route.fulfill({contentType:'text/html',body});
        });
        await page.goto('https://vectorization.test/');await page.addScriptTag({content:script});
        await page.getByText('Unlimited vectorizations',{exact:true}).waitFor();
        assert.equal(await page.locator('[data-submit-button]').isDisabled(),true);
        await page.locator('[data-input]').setInputFiles({name:'mark.png',mimeType:'image/png',buffer:Buffer.from('fixture')});
        assert.equal(await page.locator('[data-submit-button]').isDisabled(),false);
        await page.locator('[data-submit-button]').click();
        await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('Vectorizing'));
        assert.equal(submitted,1);
    } finally {await browser.close();}
});

test('invalid and empty selections clear a stale original preview',async()=>{
    assert.ok(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'Set the documented Playwright module path');
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
            .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
            .replace(/data-csrf="[^"]*"/,'data-csrf="x"');
        await page.route('https://vectorization.test/**',route=>{
            if(route.request().url().endsWith('/state'))return route.fulfill({json:{ready:true,
                allowance:{unlimited:true,remaining:null,reserved:0},results:[]}});
            return route.fulfill({contentType:'text/html',body});
        });
        await page.goto('https://vectorization.test/');await page.addScriptTag({content:script});
        await page.getByText('Unlimited vectorizations',{exact:true}).waitFor();
        const input=page.locator('[data-input]'),original=page.locator('[data-original]');
        const valid={name:'mark.png',mimeType:'image/png',buffer:Buffer.from('fixture')};
        await input.setInputFiles(valid);
        assert.equal(await original.evaluate(element=>element.hidden),false);
        assert.match(await original.getAttribute('src'),/^blob:/);

        await input.setInputFiles({name:'too-large.png',mimeType:'image/png',buffer:Buffer.alloc(20*1024*1024+1)});
        await page.getByText('Choose an image up to 20 MiB.',{exact:true}).waitFor();
        assert.equal(await page.locator('[data-submit-button]').isDisabled(),true);
        assert.equal(await original.evaluate(element=>element.hidden),true);
        assert.equal(await original.getAttribute('src'),null);

        await input.setInputFiles(valid);
        assert.equal(await original.evaluate(element=>element.hidden),false);
        await input.setInputFiles([]);
        assert.equal(await page.locator('[data-submit-button]').isDisabled(),true);
        assert.equal(await original.evaluate(element=>element.hidden),true);
        assert.equal(await original.getAttribute('src'),null);
    } finally {await browser.close();}
});

test('HTTP submission retry reuses the request identity',async()=>{
    assert.ok(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'Set the documented Playwright module path');
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();let submitted=0;const requestIds=[];
        const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
            .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
            .replace(/data-csrf="[^"]*"/,'data-csrf="x"');
        await page.route('https://vectorization.test/**',route=>{
            const request=route.request(),url=request.url();
            if(url.endsWith('/state'))return route.fulfill({json:{ready:true,
                allowance:{unlimited:false,remaining:1,reserved:0},results:[]}});
            if(url.endsWith('/submit')){
                submitted++;const match=request.postData().match(/name="request_id"\r\n\r\n([^\r\n]+)/);
                requestIds.push(match?.[1]);
                return submitted===1?route.abort('failed'):route.fulfill({status:202,json:{result:{id:'retry',status:'queued'}}});
            }
            return route.fulfill({contentType:'text/html',body});
        });
        await page.goto('https://vectorization.test/');await page.addScriptTag({content:script});
        await page.getByText('1 available · 0 in progress',{exact:true}).waitFor();
        await page.locator('[data-input]').setInputFiles({name:'mark.png',mimeType:'image/png',buffer:Buffer.from('fixture')});
        await page.locator('[data-submit-button]').click();
        await page.getByText(/You can retry this submission\./).waitFor();
        await page.locator('[data-submit-button]').click();
        await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('Vectorizing'));
        assert.equal(submitted,2);
        assert.match(requestIds[0],/^[0-9a-f-]{36}$/);
        assert.equal(requestIds[1],requestIds[0]);
    } finally {await browser.close();}
});

for(const outcome of ['completed','failed']) {
    test(`submission settles as ${outcome} without duplicate POST`,async()=>{
        assert.ok(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'Set the documented Playwright module path');
        const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
        const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
        try {
            const page=await browser.newPage();let submitted=0,reads=0;
            const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
                .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
                .replace(/data-csrf="[^"]*"/,'data-csrf="x"');
            await page.route('https://vectorization.test/**',route=>{
                const url=route.request().url();
                if(url.endsWith('/submit')){submitted++;return route.fulfill({status:202,json:{result:{id:'b',status:'queued'}}});}
                if(url.endsWith('/state')) {
                    reads++;const done=submitted>0&&reads>2;
                    return route.fulfill({json:{ready:true,
                        allowance:{unlimited:false,remaining:submitted?(done&&outcome==='failed'?1:0):1,reserved:submitted&&!done?1:0},
                        results:submitted?[{id:'b',created_at:'2026-09-08',status:done?outcome:'queued',
                            message:outcome==='failed'?'Vectorization failed. Your allowance was returned.':null,
                            svg_url:done&&outcome==='completed'?'/b.svg':null,pdf_url:done&&outcome==='completed'?'/b.pdf':null}]:[]}});
                }
                if(url.includes('b.svg'))return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"><path d="M0 0H1V1Z"/></svg>'});
                return route.fulfill({contentType:'text/html',body});
            });
            await page.goto('https://vectorization.test/');await page.addScriptTag({content:script});
            await page.getByText('1 available · 0 in progress',{exact:true}).waitFor();
            await page.locator('[data-input]').setInputFiles({name:'mark.png',mimeType:'image/png',buffer:Buffer.from('fixture')});
            await page.locator('[data-submit-button]').evaluate(button=>{button.click();button.click();});
            if(outcome==='completed') {
                await page.waitForFunction(()=>!document.querySelector('[data-copy]').disabled);
                assert.equal(await page.locator('[data-svg]').getAttribute('href'),'/b.svg');
                await page.getByText('0 available · 0 in progress',{exact:true}).waitFor();
            } else {
                await page.getByText('Vectorization failed. Your allowance was returned.',{exact:true}).waitFor();
                await page.getByText('1 available · 0 in progress',{exact:true}).waitFor();
                assert.equal(await page.locator('[data-result]').isHidden(),true);
            }
            assert.equal(submitted,1);
        } finally {await browser.close();}
    });
}


test('asset shortcut loads the original without spending until submit',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();let submitted=0,downloaded=0;
        const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
            .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
            .replace(/data-csrf="[^"]*"/,'data-csrf="x"').replace(/data-asset="[^"]*"/,'data-asset="/asset/__ASSET__"').replace(/data-source="[^"]*"/,'data-source="/original/__ASSET__"');
        await page.route('https://vectorization.test/**',route=>{
            const url=new URL(route.request().url());
            if(url.pathname==='/state')return route.fulfill({json:{ready:true,allowance:{unlimited:true,remaining:null,reserved:0},results:[]}});
            if(url.pathname==='/asset/42')return route.fulfill({json:{success:true,asset:{file_name:'original.png',extension:'png',file_size:7},downloadUrl:'https://bucket.test/original'}});
            if(url.pathname==='/original/42'){downloaded++;return route.fulfill({contentType:'image/png',body:'fixture'});}
            if(url.pathname==='/submit'){submitted++;assert.match(route.request().postData(),/original.png/);return route.fulfill({status:202,json:{result:{id:'asset',status:'queued'}}});}
            return route.fulfill({contentType:'text/html',body});
        });
        await page.goto('https://vectorization.test/?asset=42');await page.addScriptTag({content:script});
        await page.waitForFunction(()=>!document.querySelector('[data-submit-button]').disabled,{},{timeout:5000});
        assert.equal(downloaded,1);assert.equal(submitted,0);
        await page.locator('[data-submit-button]').click();
        await page.waitForFunction(()=>document.querySelector('[data-status]').textContent.includes('Vectorizing'));
        assert.equal(submitted,1);
    } finally {await browser.close();}
});


for(const outcome of ['denied','oversized','svg']) {
    test(`asset shortcut refuses ${outcome} source without submitting`,async()=>{
        const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
        const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
        try {
            const page=await browser.newPage();let downloads=0,submissions=0;
            const body=view.slice(view.indexOf('<main'),view.indexOf('</main>')+7)
                .replace(/data-state="[^"]*"/,'data-state="/state"').replace(/data-submit="[^"]*"/,'data-submit="/submit"')
                .replace(/data-csrf="[^"]*"/,'data-csrf="x"').replace(/data-asset="[^"]*"/,'data-asset="/asset/__ASSET__"').replace(/data-source="[^"]*"/,'data-source="/original/__ASSET__"');
            await page.route('https://vectorization.test/**',route=>{
                const url=new URL(route.request().url());
                if(url.pathname==='/state')return route.fulfill({json:{ready:true,allowance:{unlimited:true,remaining:null,reserved:0},results:[]}});
                if(url.pathname==='/asset/42')return outcome==='denied'?route.fulfill({status:403,json:{message:'Access denied'}}):route.fulfill({json:{success:true,asset:{file_name:'original.png',extension:outcome==='svg'?'svg':'png',file_size:outcome==='oversized'?20971521:7},downloadUrl:'/original'}});
                if(url.pathname==='/original/42')downloads++;
                if(url.pathname==='/submit')submissions++;
                return route.fulfill({contentType:'text/html',body});
            });
            await page.goto('https://vectorization.test/?asset=42');await page.addScriptTag({content:script});
            await page.getByText(/Choose an image to continue/).waitFor();
            assert.equal(await page.locator('[data-submit-button]').isDisabled(),true);
            assert.equal(await page.locator('[data-input]').isDisabled(),false);
            assert.equal(downloads,0);assert.equal(submissions,0);
        } finally {await browser.close();}
    });
}
