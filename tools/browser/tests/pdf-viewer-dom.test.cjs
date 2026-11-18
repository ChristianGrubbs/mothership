const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..'),dam=path.join(root,'packages/Mothership/DAM');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
function documentFixture(){
    const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R 5 0 R] /Count 2 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 400] /Resources << >> /Contents 4 0 R >>','<< /Length 25 >>\nstream\n0 0 1 rg 20 20 80 80 re f\nendstream','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 600 300] /Resources << >> /Contents 6 0 R >>','<< /Length 25 >>\nstream\n1 0 0 rg 20 20 80 80 re f\nendstream'];
    let result='%PDF-1.4\n';const offsets=[0];objects.forEach((object,index)=>{offsets.push(Buffer.byteLength(result));result+=`${index+1} 0 obj\n${object}\nendobj\n`;});const xref=Buffer.byteLength(result);result+=`xref\n0 ${objects.length+1}\n0000000000 65535 f \n`;for(const offset of offsets.slice(1))result+=`${String(offset).padStart(10,'0')} 00000 n \n`;return Buffer.from(result+`trailer\n<< /Size ${objects.length+1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF\n`);
}
async function sourceBundle(){
    const file=path.join(dam,'src/Resources/assets/js/pdf/viewer.mjs');
    assert.ok(fs.existsSync(file),'A lifecycle-managed PDF renderer must exist');
    const esbuild=require(path.join(dam,'node_modules/esbuild'));
    const result=await esbuild.build({entryPoints:[file],bundle:true,format:'esm',write:false,plugins:[{name:'worker-url',setup(build){build.onResolve({filter:/pdf\.worker.*\?url$/},()=>({path:'worker-url',namespace:'fixture'}));build.onLoad({filter:/.*/,namespace:'fixture'},()=>({contents:'export default "/pdf.worker.mjs";'}));}}]});
    return result.outputFiles[0].text;
}
async function fixturePage(browser){
    const built=process.env.MOTHERSHIP_TEST_BUILT_PDF==='1',bundle=built?null:await sourceBundle(),page=await browser.newPage({viewport:{width:1200,height:900}});
    const buildRoot=path.join(root,'public/themes/dam/build');
    const manifest=built?JSON.parse(fs.readFileSync(path.join(buildRoot,'manifest.json'),'utf8')):null;
    await page.route('http://pdf.test/**',route=>{
        const url=route.request().url();
        if(built&&url.includes('/themes/dam/build/')){
            const file=path.join(buildRoot,new URL(url).pathname.split('/themes/dam/build/')[1]);
            if(!fs.existsSync(file))return route.fulfill({status:404,body:'Missing built resource'});
            return route.fulfill({path:file,contentType:/\.m?js$/.test(file)?'text/javascript':file.endsWith('.wasm')?'application/wasm':'application/octet-stream'});
        }
        if(url.includes('/pdfjs/')){
            const file=path.join(dam,'node_modules/pdfjs-dist',new URL(url).pathname.split('/pdfjs/')[1]);
            return route.fulfill({path:file,contentType:file.endsWith('.wasm')?'application/wasm':'application/octet-stream'});
        }
        if(url.endsWith('/locked.pdf')&&process.env.MOTHERSHIP_PDF_PASSWORD_FIXTURE)return route.fulfill({path:process.env.MOTHERSHIP_PDF_PASSWORD_FIXTURE,contentType:'application/pdf'});
        if(url.endsWith('/viewer.mjs'))return route.fulfill({body:bundle,contentType:'text/javascript'});
        if(url.endsWith('/pdf.worker.mjs'))return route.fulfill({path:path.join(dam,'node_modules/pdfjs-dist/build/pdf.worker.mjs'),contentType:'text/javascript'});
        if(url.endsWith('/sample.pdf'))return route.fulfill({body:documentFixture(),contentType:'application/pdf'});
        if(url.endsWith('/broken.pdf'))return route.fulfill({body:'broken PDF',contentType:'application/pdf'});
        return route.fulfill({body:'<div id="app" style="height:800px"></div>',contentType:'text/html'});
    });
    await page.goto('http://pdf.test/');
    await page.evaluate(()=>{const NativeWorker=window.Worker;window.workerStats={created:0,terminated:0};window.Worker=class extends NativeWorker{constructor(...args){super(...args);window.workerStats.created++;}terminate(){window.workerStats.terminated++;return super.terminate();}};});
    const html=fs.readFileSync(path.join(dam,'src/Resources/views/asset/preview-modal/files/pdf-viewer.blade.php'),'utf8');
    await page.locator('#app').evaluate((el,html)=>el.innerHTML=html,html);
    if(built)await page.addScriptTag({url:'/themes/dam/build/'+manifest['src/Resources/assets/js/app.js'].file,type:'module'});
    await page.evaluate(async built=>{const module=built?await window.loadDamPdfViewer():await import('/viewer.mjs');window.mountPdfViewer=module.mountPdfViewer;window.viewer=module.mountPdfViewer(document.querySelector('[data-dam-pdf-viewer]'),{url:'/sample.pdf',fileName:'Fixture'});},built);
    return page;
}
browserTest('PDF renders real pages with fit, zoom, page navigation and worker teardown',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        await page.waitForFunction(()=>document.querySelector('[data-pdf-page="1"] canvas')?.dataset.pdfRendered==='true');
        assert.equal(await page.locator('[data-pdf-count]').textContent(),'2');
        // Inspect rendered pixels, so a blank allocated canvas cannot count as a rendered page.
        assert.equal(await page.locator('[data-pdf-page="1"] canvas').evaluate(canvas=>{const bytes=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height).data;for(let i=0;i<bytes.length;i+=4)if(bytes[i]<20&&bytes[i+1]<20&&bytes[i+2]>200)return true;return false;}),true);
        if(process.env.MOTHERSHIP_TEST_BUILT_PDF==='1'){
            const resources=await page.evaluate(async()=>Promise.all(['cmaps/Adobe-Japan1-UCS2.bcmap','standard_fonts/FoxitFixed.pfb','wasm/openjpeg.wasm'].map(async file=>{const response=await fetch('/themes/dam/build/pdfjs/'+file);return {ok:response.ok,length:(await response.arrayBuffer()).byteLength};})));
            assert.ok(resources.every(resource=>resource.ok&&resource.length>100));
        }

        await page.locator('[data-pdf-fit-page]').click();
        await page.waitForFunction(()=>document.querySelector('[data-pdf-fit-page]').getAttribute('aria-pressed')==='true');
        const fitWidth=await page.locator('[data-pdf-page="1"] canvas').evaluate(el=>el.width);
        await page.locator('[data-pdf-zoom-in]').click();
        await page.waitForFunction(width=>document.querySelector('[data-pdf-page="1"] canvas')?.width>width,fitWidth);
        await page.locator('[data-pdf-next]').click();
        await page.waitForFunction(()=>document.querySelector('[data-pdf-page-number]').value==='2');
        await page.waitForFunction(()=>document.querySelector('[data-pdf-page="2"] canvas')?.dataset.pdfRendered==='true');
        await page.locator('[data-pdf-fit-width]').click();
        await page.waitForFunction(()=>document.querySelector('[data-pdf-fit-width]').getAttribute('aria-pressed')==='true');
        await page.waitForFunction(()=>document.querySelector('[data-pdf-page="2"] canvas')?.dataset.pdfRendered==='true');
        const fit=await page.evaluate(()=>({page:document.querySelector('[data-pdf-page="2"] canvas').getBoundingClientRect().width,viewport:document.querySelector('[data-pdf-scroll]').clientWidth}));
        assert.ok(fit.page<=fit.viewport-30,`Landscape page must fit width: ${fit.page} in ${fit.viewport}`);
        await page.locator('[data-pdf-fit-page]').click();
        await page.waitForFunction(()=>document.querySelector('[data-pdf-fit-page]').getAttribute('aria-pressed')==='true');
        await page.waitForFunction(()=>document.querySelector('[data-pdf-page="2"] canvas')?.dataset.pdfRendered==='true');
        const fittedPage=await page.evaluate(()=>({page:document.querySelector('[data-pdf-page="2"] canvas').getBoundingClientRect().toJSON(),viewport:document.querySelector('[data-pdf-scroll]').getBoundingClientRect().toJSON()}));
        assert.ok(fittedPage.page.width<=fittedPage.viewport.width-30&&fittedPage.page.height<=fittedPage.viewport.height-30);
        assert.equal(await page.locator('[data-pdf-page-number]').inputValue(),'2');
        await page.setViewportSize({width:900,height:760});
        await page.waitForFunction(()=>document.querySelector('[data-pdf-page="2"] canvas')?.dataset.pdfRendered==='true');
        assert.equal(await page.locator('[data-pdf-page-number]').inputValue(),'2');
        await page.evaluate(()=>window.viewer.destroy());
        await page.waitForFunction(()=>window.workerStats.terminated===window.workerStats.created&&window.workerStats.created>0);
        assert.equal(await page.locator('[data-pdf-pages] canvas').count(),0);
    }finally{await browser.close();}
});
browserTest('PDF errors retain original fallback and rapid close cannot resurrect rendering',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        await page.waitForFunction(()=>document.querySelector('[data-pdf-count]').textContent==='2');
        await page.evaluate(()=>{window.viewer.destroy();window.viewer=window.mountPdfViewer(document.querySelector('[data-dam-pdf-viewer]'),{url:'/broken.pdf',fileName:'Broken'});});
        await page.locator('[data-pdf-error]').waitFor({state:'visible'});
        assert.match(await page.locator('[data-pdf-error]').textContent(),/PDF|document/);
        assert.equal(await page.locator('[data-pdf-original]').getAttribute('href'),'http://pdf.test/broken.pdf');
        await page.evaluate(()=>{window.viewer.destroy();window.viewer=window.mountPdfViewer(document.querySelector('[data-dam-pdf-viewer]'),{url:'/sample.pdf',fileName:'Next'});window.viewer.destroy();});
        await page.waitForFunction(()=>window.workerStats.terminated===window.workerStats.created);
        assert.equal(await page.locator('[data-pdf-pages] canvas').count(),0);
    }finally{await browser.close();}
});

(playwrightPath&&process.env.MOTHERSHIP_PDF_PASSWORD_FIXTURE?test:test.skip)('password-protected PDF has a useful original fallback and releases its worker',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        await page.waitForFunction(()=>document.querySelector('[data-pdf-count]').textContent==='2');
        await page.evaluate(()=>{window.viewer.destroy();window.viewer=window.mountPdfViewer(document.querySelector('[data-dam-pdf-viewer]'),{url:'/locked.pdf',fileName:'Locked'});});
        await page.waitForFunction(()=>document.querySelector('[data-pdf-error]').textContent.includes('password'));
        await page.waitForFunction(()=>window.workerStats.terminated===window.workerStats.created);
        assert.equal(await page.locator('[data-pdf-original]').getAttribute('href'),'http://pdf.test/locked.pdf');
        await page.evaluate(()=>window.viewer.destroy());
    }finally{await browser.close();}
});
browserTest('closing during a PDF network load aborts the worker and never paints stale pages',async()=>{
    const browser=await require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await fixturePage(browser);
        await page.waitForFunction(()=>document.querySelector('[data-pdf-count]').textContent==='2');
        let request,started;const requested=new Promise(resolve=>started=resolve);
        await page.route('http://pdf.test/slow.pdf',route=>{request=route;started();});
        await page.evaluate(()=>{window.viewer.destroy();window.viewer=window.mountPdfViewer(document.querySelector('[data-dam-pdf-viewer]'),{url:'/slow.pdf',fileName:'Slow'});});
        await requested;
        await page.evaluate(()=>window.viewer.destroy());
        await request.fulfill({body:documentFixture(),contentType:'application/pdf'}).catch(()=>{});
        await page.waitForFunction(()=>window.workerStats.terminated===window.workerStats.created);
        assert.equal(await page.locator('[data-pdf-pages] canvas').count(),0);
    }finally{await browser.close();}
});
