const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');

// 2026-09-16 regression: `this._prepared=result` in rebuild() was a plain instance property, not
// Vue-reactive data. Every computed that derived from it (resolvedSettings, preparedWidth,
// exportOptions) evaluated once, cached that result as if it had no reactive dependency, and
// never recomputed — so choosing a new source panorama (or any other change that re-ran
// rebuild()) left the Auto quality/resolution tier and the export-width default pinned to
// whatever the FIRST prepared panorama produced. That is the "saving at lower resolution than the
// original image" half of the operator report. `.call(state)`-style unit tests on the computed
// functions in isolation cannot catch this class of bug: they bypass Vue's reactivity system
// entirely. This file mounts the real component with the real Vue runtime so both the computed
// cache invalidation AND actual template rendering (e.g. the Retry button) are exercised.
const root=path.resolve(__dirname,'../../..');
const blade=fs.readFileSync(path.join(root,'packages/Mothership/SphereSeam/Resources/views/editor.blade.php'),'utf8');
const geometrySource=fs.readFileSync(path.join(root,'packages/Mothership/SphereSeam/Resources/assets/sphereseam/sphereseam.js'),'utf8');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;

const IMPORT_LINE="import { preparePanorama, composeBridgeCanvasPlan, reinsertGeneratedBridge, createWrapPreview, createWrapComparison, scalePanorama, resolveGenerationSettings, resolveExportOptions, describeProviderOutput, checkUploadSize } from @json(route('sphereseam.script'));";
const IMPORT_REPLACEMENT="const { preparePanorama, composeBridgeCanvasPlan, reinsertGeneratedBridge, createWrapPreview, createWrapComparison, scalePanorama, resolveGenerationSettings, resolveExportOptions, describeProviderOutput, checkUploadSize } = window.__sphereSeamGeometry;";

function sourceParts() {
    assert.ok(blade.includes(IMPORT_LINE),'editor.blade.php no longer imports the geometry module the way this test expects — update IMPORT_LINE/IMPORT_REPLACEMENT to match.');
    const rendered=blade
        .replace(IMPORT_LINE,IMPORT_REPLACEMENT)
        // Blade comments ({{-- ... --}}) are stripped server-side at compile time; this file has
        // several inline the <script type="module"> block, and left in place they are not valid
        // JS (a `{` immediately followed by `--` is a syntax error), so the extracted script would
        // never parse.
        .replace(/\{\{--[\s\S]*?--\}\}/g,'')
        .replace(/@json\(csrf_token\(\)\)/g,'"x"')
        // Every remaining @json($variable) in this file is a route URL, the SDK-ready flag, the
        // asset-create permission flag, or the upload-limits map — none of which this test's
        // reactivity assertions read. null is fine everywhere they are used.
        .replace(/@json\(\$[A-Za-z_][A-Za-z0-9_]*\)/g,'null')
        .replace(/@\{\{/g,'{{');
    const template=rendered.match(/<script type="text\/x-template" id="v-sphere-seam-template">([\s\S]*?)<\/script>/)?.[1];
    const registration=rendered.match(/<script type="module">([\s\S]*?)<\/script>/)?.[1];
    assert.ok(template,'could not extract the v-sphere-seam template');
    assert.ok(registration,'could not extract the v-sphere-seam component registration script');

    return {template,registration};
}

// Mounts the real component into `page` and stashes the child instance on window.__seam for
// subsequent page.evaluate() calls to interact with directly (data, computed, methods are all on
// the public instance proxy by default for this Options API component).
async function mountSeam(page) {
    const {template,registration}=sourceParts();
    await page.setContent('<div id="app"><v-sphere-seam ref="seam"></v-sphere-seam></div>');
    await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_GLOBAL_PATH});
    await page.evaluate(async({template,registration,geometrySource})=>{
        // The geometry helpers (preparePanorama, resolveGenerationSettings, ...) are ordinary
        // ESM exports; stash them on window so the classic-script-style eval below (and the
        // component registration module) can reach them without a real module graph.
        const geometryModule=document.createElement('script');
        geometryModule.type='module';
        geometryModule.textContent=geometrySource+'\nwindow.__sphereSeamGeometry={preparePanorama,composeBridgeCanvasPlan,reinsertGeneratedBridge,createWrapPreview,createWrapComparison,scalePanorama,resolveGenerationSettings,resolveExportOptions,describeProviderOutput,checkUploadSize};';
        document.body.append(geometryModule);
        await new Promise((resolve,reject)=>{
            const started=Date.now();
            (function poll(){
                if(window.__sphereSeamGeometry)return resolve();
                if(Date.now()-started>5000)return reject(new Error('geometry module never set window.__sphereSeamGeometry'));
                setTimeout(poll,10);
            })();
        });

        const templateEl=document.createElement('script');
        templateEl.type='text/x-template';
        templateEl.id='v-sphere-seam-template';
        templateEl.textContent=template;
        document.body.append(templateEl);

        window.app=Vue.createApp({});
        new Function('app',registration)(window.app);
        const vm=window.app.mount('#app');
        window.__seam=vm.$refs.seam;
    },{template,registration,geometrySource});
}

test('choosing a new source panorama updates the Auto resolution tier and the export-width default, not just the first prepare',async()=>{
    assert.ok(playwrightPath,'Set MOTHERSHIP_PLAYWRIGHT_MODULE_PATH');
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mountSeam(page);

        const result=await page.evaluate(async()=>{
            const seam=window.__seam;
            const sourceImage=async(width,height,color)=>{
                const canvas=document.createElement('canvas');
                canvas.width=width;canvas.height=height;
                const ctx=canvas.getContext('2d');
                ctx.fillStyle=color;ctx.fillRect(0,0,width,height);
                const image=new Image();
                image.src=canvas.toDataURL('image/png');
                await new Promise(resolve=>{image.onload=resolve;});

                return image;
            };

            // A small source: the tiny bridge it produces should resolve to the 1K auto tier and
            // a 400px export default.
            seam._sourceImage=await sourceImage(400,200,'#785a3c');
            await seam.rebuild();
            const before={
                resolution: seam.resolvedSettings.resolution,
                preparedWidth: seam.preparedWidth,
                exportOptions: seam.exportOptions.map(o=>o.value),
            };

            // A large source (the operator's real 8192x4096 case): the bridge it produces should
            // resolve to the 4K auto tier and an 8192px export default. Under the pre-2026-09-16
            // bug these three values would be identical to `before` — the computed cache, once
            // warmed by the first rebuild(), never re-read the reassigned (non-reactive)
            // `this._prepared`.
            seam._sourceImage=await sourceImage(8192,4096,'#123456');
            await seam.rebuild();
            const after={
                resolution: seam.resolvedSettings.resolution,
                preparedWidth: seam.preparedWidth,
                exportOptions: seam.exportOptions.map(o=>o.value),
            };

            // Also exercise the OTHER trigger named in the bug report: a plain data change (here,
            // the bridge ratio) that fires through Vue's `watch`, not an explicit rebuild() call.
            seam.ratio='9:16';
            await Vue.nextTick();
            await Vue.nextTick();
            const afterRatioChange={
                resolution: seam.resolvedSettings.resolution,
                preparedWidth: seam.preparedWidth,
            };

            return {before,after,afterRatioChange};
        });

        assert.equal(result.before.resolution,'1k',`expected the 400x200 source's tiny bridge to resolve Auto to 1K, got ${result.before.resolution}`);
        assert.equal(result.before.preparedWidth,400);
        assert.deepEqual(result.before.exportOptions,[400,4096,8192],'the true original width must be listed first, with no duplicate against the fixed widths');

        assert.equal(result.after.resolution,'4k',`expected the 8192x4096 source's bridge to resolve Auto to 4K, got ${result.after.resolution} — this is the "stale resolution" regression if it still reads 1K`);
        assert.equal(result.after.preparedWidth,8192,'preparedWidth must follow the newly chosen source, not stay pinned at the first prepare\'s width');
        assert.deepEqual(result.after.exportOptions,[8192,4096],'the export select must offer the NEW true original first, not the stale one, and must not duplicate it against the fixed widths');

        // A ratio change reshapes the bridge (its width changes with the crop), so the resolution
        // implied by that new bridge can differ again — proving the watch-driven rebuild() path
        // updates the same reactive state as the explicit rebuild() calls above do.
        assert.equal(result.afterRatioChange.preparedWidth,8192,'the panorama width itself does not change with ratio, only the bridge does');
        assert.ok(['1k','2k','4k'].includes(result.afterRatioChange.resolution));
    } finally {
        await browser.close();
    }
});

test('the Retry button is hidden for a submission Higgsfield never confirmed, and shown for an ordinary failure',async()=>{
    // 2026-09-16 review follow-up: SphereSeamTest.php only proved the JSON payload the editor
    // reads carries `submission_ambiguous` — nothing there actually mounts the component or
    // renders the button, so a regression in the `v-if` on the button itself would have gone
    // uncaught. This mounts the real template and checks the real rendered DOM.
    assert.ok(playwrightPath,'Set MOTHERSHIP_PLAYWRIGHT_MODULE_PATH');
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mountSeam(page);

        const ambiguous=await page.evaluate(async()=>{
            const seam=window.__seam;
            seam.activeJob={id:'job-ambiguous',status:'failed',submission_ambiguous:true,error:'Higgsfield did not confirm this seam bridge. Check the shared work account before starting another, in case it was charged.',created_at:new Date().toISOString()};
            await Vue.nextTick();

            return Array.from(document.querySelectorAll('button')).some(b=>b.textContent.includes('Retry this seam'));
        });
        assert.equal(ambiguous,false,'a submission_ambiguous failure must not offer Retry — it may already be charged, and a retry would submit `generate create` again');

        const ordinary=await page.evaluate(async()=>{
            const seam=window.__seam;
            seam.activeJob={id:'job-ordinary',status:'failed',submission_ambiguous:false,error:'The shared Higgsfield connection is unavailable. Retry shortly.',created_at:new Date().toISOString()};
            await Vue.nextTick();

            return Array.from(document.querySelectorAll('button')).some(b=>b.textContent.includes('Retry this seam'));
        });
        assert.equal(ordinary,true,'an ordinary (non-ambiguous) failure must still offer Retry — this proves the assertion above is discriminating on submission_ambiguous, not just never rendering the button at all');
    } finally {
        await browser.close();
    }
});

// 2026-09-16 regression: Save to DAM stayed clickable while `busy` was true. An 18 MB 4K export
// takes seconds to upload, a second click started a parallel import, and production answered the
// loser with a 500 (dam_assets_path_unique). The button follows `busy` like Generate does, and
// saveToDam() itself refuses to start while a save is already in flight.
test('Save to DAM is disabled while a save is in flight, so a second click cannot race the first',async()=>{
    assert.ok(playwrightPath,'Set MOTHERSHIP_PLAYWRIGHT_MODULE_PATH');
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mountSeam(page);

        const result=await page.evaluate(async()=>{
            const seam=window.__seam;
            const button=()=>[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Save to DAM');
            seam.canCreateAssets=true;
            seam.activeJob={id:'gen-1',status:'completed'};
            seam.finalPreview='blob:preview';
            await Vue.nextTick();
            const idle=button()?.disabled;

            seam.busy=true;
            await Vue.nextTick();
            const whileBusy=button()?.disabled;

            // A programmatic second call while busy must not reach the network either.
            let requests=0;
            seam.request=async()=>{requests++;return {asset:{id:1,url:'/asset/1'}};};
            seam._final=document.createElement('canvas');
            await seam.saveToDam();
            const stillBusy=seam.busy;

            seam.busy=false;
            await Vue.nextTick();
            return {rendered:!!button(),idle,whileBusy,requests,stillBusy,after:button()?.disabled};
        });

        assert.ok(result.rendered,'the Save to DAM button must render for an editor with a completed job and a final preview');
        assert.equal(result.idle,false);
        assert.equal(result.whileBusy,true,'Save to DAM must be disabled while busy');
        assert.equal(result.requests,0,'a second saveToDam() while busy must not start another import request');
        assert.equal(result.stillBusy,true,'a refused second call must not clear the in-flight busy flag');
        assert.equal(result.after,false);
    } finally {
        await browser.close();
    }
});

// 2026-09-16 review follow-up: the test above only ever sets `busy` BY HAND to prove the button
// and the guard react to it; it never drives an actual request through saveToDam() far enough to
// prove the `finally{this.busy=false}` in the real code is what clears it. A rejected request is
// exactly the path most likely to leave `busy` stuck (an early `return`, a forgotten `finally`),
// so this drives one for real: reject, assert the button recovers on its own, then retry for real
// and assert the retry sends exactly one new request, not zero (stuck) and not two (double-fired).
test('a rejected Save to DAM clears busy and re-enables the button on its own, and a real retry sends exactly one more request',async()=>{
    assert.ok(playwrightPath,'Set MOTHERSHIP_PLAYWRIGHT_MODULE_PATH');
    const {chromium}=require(playwrightPath);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        await mountSeam(page);
        // The real success path navigates via `window.location.href=data.asset.url`. Aborting the
        // navigation request keeps THIS page (and this test's execution context) alive so the
        // assertions below can still run, without changing anything about saveToDam() itself.
        await page.route('**/asset/1',route=>route.abort());

        const result=await page.evaluate(async()=>{
            const seam=window.__seam;
            const button=()=>[...document.querySelectorAll('button')].find(b=>b.textContent.trim()==='Save to DAM');
            seam.canCreateAssets=true;
            seam.activeJob={id:'gen-1',status:'completed'};
            seam.finalPreview='blob:preview';
            seam._final=document.createElement('canvas');
            // This test drives a REAL saveToDam() call through to the network layer (unlike the
            // test above, which never gets past the busy short-circuit), so the client-side
            // upload-size pre-check needs a real limit — the harness nulls every other
            // @json($variable) in the component, uploadLimits and importUrl included.
            seam.uploadLimits={import:99999999,normalized:99999999,bridge:99999999};
            seam.url=(template,id)=>'/mock/sphere-seam/'+id+'/import';

            let requests=0;
            let shouldFail=true;
            seam.request=async()=>{
                requests++;
                if(shouldFail)throw new Error('The shared Higgsfield connection is unavailable. Retry shortly.');

                return {asset:{id:1,url:'/asset/1'}};
            };

            // First attempt: the mocked request rejects. Nothing here ever touches seam.busy
            // directly — only saveToDam()'s own try/finally does.
            await seam.saveToDam();
            await Vue.nextTick();
            const afterRejection={busy:seam.busy,error:seam.error,disabled:button()?.disabled,requests};

            // Retry: the SAME button, now re-enabled, drives a second real call. This time the
            // mock succeeds.
            shouldFail=false;
            await seam.saveToDam();
            await Vue.nextTick();
            const afterRetry={busy:seam.busy,error:seam.error,requests};

            return {afterRejection,afterRetry};
        });

        assert.equal(result.afterRejection.busy,false,'a rejected save must clear busy on its own, via saveToDam()\'s own finally block');
        assert.ok(result.afterRejection.error,'the rejection must surface a visible error');
        assert.equal(result.afterRejection.disabled,false,'the Save to DAM button must re-enable itself once busy clears, with no manual intervention');
        assert.equal(result.afterRejection.requests,1);

        assert.equal(result.afterRetry.busy,false,'a successful retry must also leave busy false when it settles');
        assert.equal(result.afterRetry.requests,2,'the retry must send exactly one new request — neither stuck at 1 (refused to retry) nor jumping to 3+ (double-fired)');
    } finally {
        await browser.close();
    }
});
