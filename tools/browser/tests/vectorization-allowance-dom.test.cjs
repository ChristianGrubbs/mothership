const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

const root=path.resolve(__dirname,'../../..');
const blade=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/admin/users/vectorization.blade.php'),'utf8');
const vuePath=process.env.MOTHERSHIP_VUE_MODULE_PATH;
const vueEntry=vuePath?require.resolve(vuePath):'';
const vueScript=path.join(path.dirname(vueEntry),'dist/vue.global.js');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;

function sourceParts() {
    const rendered=blade
        .replace(/\{\{\s*route\([\s\S]*?\)\s*\}\}/g,'/allowance')
        .replace(/@json\(csrf_token\(\)\)/g,'"x"')
        .replace(/@\{\{/g,'{{');
    const component=rendered.match(/<v-vectorization-allowance[\s\S]*?<\/v-vectorization-allowance>/)?.[0];
    const template=rendered.match(/<script type="text\/x-template" id="v-vectorization-allowance-template">([\s\S]*?)<\/script>/)?.[1];
    const registration=[...rendered.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].at(-1)?.[1];
    const section=rendered.match(/<section id="vectorization-allowance"[\s\S]*?<\/section>/)?.[0];
    const legacy=rendered.match(/<script>\s*([\s\S]*?)\s*<\/script>/)?.[1];
    return {component,template,registration,section,legacy};
}

test('allowance panel survives Vue replacement and a second Ajax-style mount',async()=>{
    assert.ok(playwrightPath,'Set MOTHERSHIP_PLAYWRIGHT_MODULE_PATH');
    assert.ok(vuePath,'Set MOTHERSHIP_VUE_MODULE_PATH to the resolved Vue package');
    assert.ok(fs.existsSync(vueScript),`Vue global runtime is missing: ${vueScript}`);
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();let gets=0;const puts=[];
        await page.route('https://allowance.test/**',async route=>{
            const request=route.request();
            if(request.url().endsWith('/allowance')) {
                if(request.method()==='PUT') {
                    const payload=request.postDataJSON();puts.push(payload);
                    return route.fulfill({json:{unlimited:false,remaining:payload.remaining,reserved:1,revision:payload.revision+1}});
                }
                gets++;
                return route.fulfill({json:{unlimited:false,remaining:gets===1?2:5,reserved:1,revision:gets===1?4:5}});
            }
            return route.fulfill({contentType:'text/html',body:'<!doctype html>'});
        });
        await page.goto('https://allowance.test/');await page.addScriptTag({path:vueScript});
        const parts=sourceParts();
        if(parts.component) {
            await page.setContent(`<div id="app">${parts.component}</div><script type="text/x-template" id="v-vectorization-allowance-template">${parts.template}</script>`);
            await page.evaluate(code=>{window.app=Vue.createApp({});new Function(code)();window.app.mount('#app');},parts.registration);
        } else {
            await page.setContent(`<div id="app">${parts.section}</div>`);
            await page.evaluate(code=>new Function(code)(),parts.legacy);
            await page.evaluate(()=>{const template=document.querySelector('#app').innerHTML;window.app=Vue.createApp({template});window.app.mount('#app');});
        }
        await page.getByText('2 available · 1 in progress',{exact:true}).waitFor();
        const staleButton=page.locator('[data-save]');
        await page.locator('[data-balance]').fill('5');await staleButton.click();
        await page.getByText('5 available · 1 in progress',{exact:true}).waitFor();
        assert.deepEqual(puts,[{remaining:5,revision:4}]);

        await page.evaluate(({component,template,registration})=>{
            const oldButton=document.querySelector('[data-save]');window.__staleAllowanceButton=oldButton;
            window.app.unmount();document.querySelector('#app').outerHTML=`<div id="app">${component}</div>`;
            document.querySelector('#v-vectorization-allowance-template')?.remove();
            const node=document.createElement('script');node.type='text/x-template';node.id='v-vectorization-allowance-template';node.textContent=template;document.body.appendChild(node);
            window.app=Vue.createApp({});new Function(registration)();window.app.mount('#app');
        },parts);
        await page.getByText('5 available · 1 in progress',{exact:true}).waitFor();
        await page.evaluate(()=>window.__staleAllowanceButton.click());
        await page.locator('[data-balance]').fill('7');await page.locator('[data-save]').click();
        await page.getByText('7 available · 1 in progress',{exact:true}).waitFor();
        assert.deepEqual(puts,[{remaining:5,revision:4},{remaining:7,revision:5}]);
        assert.equal(gets,2);
    } finally {await browser.close();}
});


test('conflict refreshes revision and locks input until retry',async()=>{
    assert.ok(playwrightPath,'Set MOTHERSHIP_PLAYWRIGHT_MODULE_PATH');
    assert.ok(vuePath,'Set MOTHERSHIP_VUE_MODULE_PATH to the resolved Vue package');
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();let gets=0;const puts=[];
        let releasePut,markPutStarted;
        const heldPut=new Promise(resolve=>{releasePut=resolve;});
        const putStarted=new Promise(resolve=>{markPutStarted=resolve;});
        await page.route('https://allowance.test/**',async route=>{
            const request=route.request();
            if(!request.url().endsWith('/allowance'))return route.fulfill({contentType:'text/html',body:'<!doctype html>'});
            if(request.method()==='PUT') {
                const payload=request.postDataJSON();puts.push(payload);
                if(puts.length===1) {
                    markPutStarted();await heldPut;
                    return route.fulfill({status:409,json:{message:'Balance changed on another device.'}});
                }
                return route.fulfill({json:{unlimited:false,remaining:payload.remaining,reserved:0,revision:payload.revision+1}});
            }
            gets++;
            return route.fulfill({json:{unlimited:false,remaining:gets===1?2:3,reserved:0,revision:gets===1?4:5}});
        });
        await page.goto('https://allowance.test/');await page.addScriptTag({path:vueScript});
        const parts=sourceParts();
        await page.setContent(`<div id="app">${parts.component}</div><script type="text/x-template" id="v-vectorization-allowance-template">${parts.template}</script>`);
        await page.evaluate(code=>{window.app=Vue.createApp({});new Function(code)();window.app.mount('#app');},parts.registration);
        await page.getByText('2 available · 0 in progress',{exact:true}).waitFor();
        const input=page.locator('[data-balance]'),save=page.locator('[data-save]');
        await input.fill('6');await save.click();await putStarted;
        assert.equal(await input.isDisabled(),true);
        assert.equal(await save.isDisabled(),true);
        releasePut();
        await page.getByText('Balance changed on another device. Current balance loaded; retry your change.',{exact:true}).waitFor();
        assert.equal(await input.inputValue(),'3');
        assert.equal(await input.isDisabled(),false);
        await input.fill('6');await save.click();
        await page.getByText('6 available · 0 in progress',{exact:true}).waitFor();
        assert.deepEqual(puts,[{remaining:6,revision:4},{remaining:6,revision:5}]);
        assert.equal(gets,2);
    } finally {await browser.close();}
});
