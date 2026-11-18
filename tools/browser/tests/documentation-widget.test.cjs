const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const http=require('node:http');
const root=path.resolve(__dirname,'../../..');
const sample=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/widgets/docs-notes/index.html'),'utf8');
const bridge=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/home-frame.js'),'utf8');

test('real notes example follows bridge messages and persists account preferences',async()=>{
    assert.ok(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'Playwright module path required; acceptance must not skip');
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    let state={note:'Saved note'},fail=false;
    const server=http.createServer((req,res)=>{
        if(req.url==='/state'&&req.method==='PUT'){
            let raw='';req.on('data',chunk=>raw+=chunk);req.on('end',()=>{if(!fail)state=JSON.parse(raw).state;res.writeHead(fail?500:200,{'Content-Type':'application/json'});res.end('{}');});return;
        }
        res.setHeader('Content-Type','text/html');
        if(req.url.startsWith('/tool')){
            const config=JSON.stringify({widget:'docs-notes',account:1,state,url:'/state',csrf:'fixture'});
            res.end(sample.replace('<head>',`<head><script>window.__MOTHERSHIP_FRAME__=${config};</script><script>${bridge}</script>`));return;
        }
        res.end('<html><body><script>window.messages=[];addEventListener("message",e=>messages.push(e.data));</script><iframe id="tool" src="/tool?theme=light"></iframe></body></html>');
    });
    await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage();await page.goto(`http://127.0.0.1:${server.address().port}`);
        const frame=page.frames().find(f=>f.url().includes('/tool'));
        await frame.locator('#note').waitFor();
        assert.equal(await frame.locator('#note').inputValue(),'Saved note');
        await page.evaluate(()=>document.querySelector('iframe').contentWindow.postMessage({protocol:'mothership-widget/v1',type:'active',payload:{active:true}},location.origin));
        await frame.waitForFunction(()=>document.body.dataset.active==='true');
        await frame.evaluate(()=>dispatchEvent(new MessageEvent('message',{source:parent,origin:'https://wrong.example',data:{protocol:'mothership-widget/v1',type:'active',payload:{active:false}}})));
        assert.equal(await frame.locator('body').getAttribute('data-active'),'true');
        await page.evaluate(()=>document.querySelector('iframe').contentWindow.postMessage({protocol:'mothership-widget/v1',type:'theme',payload:{theme:'dark'}},location.origin));
        await frame.waitForFunction(()=>getComputedStyle(document.body).backgroundColor==='rgb(24, 24, 27)');
        await frame.locator('#note').fill('Updated note');
        await page.waitForFunction(()=>messages.some(m=>m.type==='dirty'));
        await new Promise((resolve,reject)=>{const deadline=Date.now()+3000;const poll=()=>state.note==='Updated note'?resolve():Date.now()>deadline?reject(Error('state not persisted')):setTimeout(poll,25);poll();});
        await page.locator('iframe').evaluate(el=>el.hidden=true);await page.locator('iframe').evaluate(el=>el.hidden=false);
        assert.equal(await frame.locator('#note').inputValue(),'Updated note');
        const downloaded=page.waitForEvent('download');await frame.locator('#download').click();assert.equal((await downloaded).suggestedFilename(),'notes.txt');
        fail=true;await frame.locator('#note').fill('Unsaved note');
        await page.waitForFunction(()=>messages.some(m=>m.type==='preference-error'));
        assert.equal(state.note,'Updated note');
        await page.close();
    }finally{await browser.close();await new Promise(resolve=>server.close(resolve));}
});
