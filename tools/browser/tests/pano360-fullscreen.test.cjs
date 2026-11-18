const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {launchBrowser}=require('../launch-browser.cjs');
const root=path.resolve(__dirname,'../../..');
const views=path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/pano360');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
// MOTHERSHIP_BROWSER_ENGINE=webkit runs the iPhone case in Playwright's WebKit (Safari's engine) instead of Chrome.
const launch=()=>process.env.MOTHERSHIP_BROWSER_ENGINE==='webkit'?require(playwrightPath).webkit.launch({headless:true}):launchBrowser({playwrightPath});
// The committed admin stylesheet: the viewer's Tailwind utilities (relative, w-full h-full) come from it.
function builtAdminCss(){
    const dir=path.join(root,'public/themes/admin/default/build');
    const manifest=JSON.parse(fs.readFileSync(path.join(dir,'manifest.json'),'utf8'));
    return path.join(dir,Object.values(manifest).find(item=>typeof item.file==='string'&&item.file.startsWith('assets/app-')&&item.file.endsWith('.css')).file);
}
// The real viewer template and component script, mounted in a 320×200 frame (the preview modal's stage). The WebGL
// runtime is never started: fullscreen only needs the container.
async function setup(browser){
    const page=await browser.newPage({viewport:{width:800,height:600}});
    await page.route('https://media.test/**',route=>route.fulfill({contentType:'text/html',body:'<style>#frame{width:320px;height:200px}</style><div id="frame"><div id="app"></div></div>'}));
    await page.goto('https://media.test/');
    await page.addStyleTag({path:builtAdminCss()});
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
    await page.addScriptTag({content:fs.readFileSync(path.join(views,'viewer-script.blade.php'),'utf8').replace(/<\/?script[^>]*>/g,'').replace(/@js\(trans\('[^']+'\)\)/g,'\'Text\'')});
    // Blade drops {{-- comments --}} server-side; left in, Vue would compile them as interpolations.
    const template=fs.readFileSync(path.join(views,'viewer.blade.php'),'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'').replace(/@js\(trans\('[^']+'\)\)/g,'\'Control\'').replace(/@lang\('[^']+'\)/g,'Control').replace(/@\{\{/g,'{{');
    await page.evaluate(template=>{
        const viewer=window._damPanoViewer;
        window.viewer=Vue.createApp({template,data:()=>({...viewer.data,previewData:{id:1,playbackKind:'pano360-image',playbackUrl:'/pano.jpg'}}),computed:viewer.computed,methods:viewer.methods}).mount('#app');
    },template);
    return page;
}
const box=page=>page.locator('[data-dam-360-viewer]').evaluate(el=>{const r=el.getBoundingClientRect();return{width:r.width,height:r.height,position:getComputedStyle(el).position};});
browserTest('360 fullscreen takes the viewer fullscreen where the browser allows it',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const page=await setup(browser);
        await page.locator('[data-dam-360-fullscreen]').click();
        await page.waitForFunction(()=>document.fullscreenElement?.hasAttribute('data-dam-360-viewer'));
        assert.equal(await page.evaluate(()=>viewer.panoPseudoFullscreen),false,'desktop keeps real fullscreen');
        await page.evaluate(()=>viewer.panoToggleFullscreen());
        await page.waitForFunction(()=>!document.fullscreenElement);
    }finally{await browser.close();}
});
browserTest('360 fullscreen covers the viewport itself where elements cannot go fullscreen (iPhone)',async()=>{
    const browser=await launch();
    try{
        const page=await setup(browser);
        // iPhone Safari (iOS 27.1 Duo simulator, probed): no requestFullscreen or webkitRequestFullscreen on elements.
        await page.evaluate(()=>{delete Element.prototype.requestFullscreen;delete Element.prototype.webkitRequestFullscreen;});
        assert.deepEqual(await box(page),{width:320,height:200,position:'relative'});
        await page.locator('[data-dam-360-fullscreen]').click();
        // The admin stylesheet reserves a scrollbar gutter, which a 100vw box would run under.
        const viewport=await page.evaluate(()=>({width:document.documentElement.clientWidth,height:innerHeight}));
        assert.deepEqual(await box(page),{...viewport,position:'fixed'});
        assert.equal(await page.evaluate(({width})=>document.elementFromPoint(width-5,5)?.closest('[data-dam-360-viewer]')!=null,viewport),true,'the viewer is on top at the far corner');
        await page.locator('[data-dam-360-fullscreen]').click();
        assert.deepEqual(await box(page),{width:320,height:200,position:'relative'});
        await page.locator('[data-dam-360-fullscreen]').click();
        await page.evaluate(()=>viewer.panoResetState());
        assert.equal(await page.evaluate(()=>viewer.panoPseudoFullscreen),false,'another asset starts out of fullscreen');
    }finally{await browser.close();}
});
