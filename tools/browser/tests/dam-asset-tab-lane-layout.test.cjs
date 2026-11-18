// The asset detail page (packages/Mothership/DAM/src/Resources/views/components/layouts/with-history/asset.blade.php)
// shows eight tabs next to the navButtons slot (Share, Embed, Add to collection). Below 640px the slot once stayed
// beside the tab lane on a shrink-0 row and left the lane about 10px wide at 375px, so Files and the other tabs could
// not be reached by pointer or keyboard (QA, 2026-10-07). This renders the real row, lane, tab and slot classes and
// the lane script from the Blade source against the committed built stylesheets.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');
const BLADE='packages/Mothership/DAM/src/Resources/views/components/layouts/with-history/asset.blade.php';
const TABS=['preview','meta-data','properties','comments','linked-resources','relationships','files','history'];
const LABELS=['Preview','Embedded Meta Info','Properties','Comments','Linked Resources','Relationships','Files','History'];

function realMarkup(){
    const blade=source(BLADE);
    const grab=(re,what)=>{const m=blade.match(re);assert.ok(m,`${what} must still be found in the Blade source`);return m[1].replace(/\s+/g,' ').trim();};
    const script=blade.match(/<script type="module">\s*\/\/ The phone tab lane[\s\S]*?<\/script>/);
    assert.ok(script,'the lane script must still be found in the Blade source');

    return {
        row:grab(/<div class="(flex gap-4 my-4[^"]*)">\s*<div class="flex flex-1 min-w-0/,'the tab row'),
        lane:grab(/<div class="(flex flex-1 min-w-0[^"]*)" data-dam-tab-lane>/,'the tab lane'),
        tab:grab(/<a href="[^"]*" class="(self-stretch flex items-end[^"]*)" data-dam-tab=/,'a tab link'),
        label:grab(/\}\}\s*(pb-3 px-2[^"]*)">\s*<span class="text-lg/,'a tab label'),
        active:grab(/"(-mb-px border-primary-700[^"]*)"/,'the active tab style'),
        slot:grab(/<div class="(ml-auto shrink-0[^"]*)">\s*\{\{ \$navButtons \}\}/,'the navButtons slot'),
        script:script[0].replace(/^<script type="module">/,'').replace(/<\/script>$/,''),
    };
}

function builtStylesheets(){
    const sheet=dir=>{
        const manifest=JSON.parse(source(`${dir}/manifest.json`));
        const entry=Object.values(manifest).find(item=>typeof item.file==='string'&&item.file.startsWith('assets/app-')&&item.file.endsWith('.css'));
        assert.ok(entry,`${dir}/manifest.json must list a built assets/app-*.css entry`);

        return path.join(root,dir,entry.file);
    };

    return [sheet('public/themes/admin/default/build'),sheet('public/themes/dam/build')];
}

async function withPage(run){
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {await run(await browser.newPage());} finally {await browser.close();}
}

// The content area pads 16px each side; from lg the 270px main nav takes room too (below lg it is a drawer).
async function render(page,width,{dark=false,dir='ltr',active='preview'}={}){
    const m=realMarkup();
    const navWidth=width>=1024?270:0;
    await page.setViewportSize({width,height:900});
    const tabs=TABS.map((code,i)=>`<a href="#${code}" class="${m.tab}" data-dam-tab="${code}" ${code===active?'aria-current="page"':''}>
        <div class="${code===active?m.active:''} ${m.label}"><span class="text-lg icon-general"></span>${LABELS[i]}</div></a>`).join('');
    await page.setContent(`<!doctype html><html dir="${dir}" class="${dark?'dark':''}"><head></head><body style="margin:0" class="${dark?'bg-cherry-800':''}">
        <div style="display:flex"><nav style="width:${navWidth}px;flex-shrink:0"></nav>
        <main style="flex:1;min-width:0;padding:0 16px">
            <div class="${m.row}" data-row><div class="${m.lane}" data-dam-tab-lane>${tabs}</div>
            <div class="${m.slot}" data-slot><a class="secondary-button" href="#share">Share</a><button class="transparent-button">Embed</button><button class="secondary-button">Add to collection</button></div></div>
        </main></div></body></html>`);
    for(const file of builtStylesheets()){
        await page.addStyleTag({path:file});
    }
    // The page's own `load` hook already fired for setContent; run the real script, then fire it. A real page runs its
    // module scripts before `load`; an injected inline module script runs later and Playwright does not wait for it,
    // so on a slow runner `load` was fired before the script had registered its listener (reproduced under 20x CPU
    // throttling). The same source runs here as a classic script in a function scope, which executes synchronously.
    await page.addScriptTag({content:`(function(){${m.script}\n})();`});
    await page.evaluate(()=>window.dispatchEvent(new Event('load')));
    // Injecting the stylesheets after the markup starts real CSS transitions: the buttons carry `transition-property:
    // all` over 150ms, so their padding, border and font weight animate from the browser defaults and the slot grows
    // about 76px. A fixed 150ms wait raced that under CPU load (CI job 113958724636 measured a light run mid-transition
    // and a dark run settled, 345 against 254px of lane). Wait for the fonts and every finite animation to finish, then
    // two frames so layout and the lane script's ResizeObserver have run on the settled styles.
    await page.evaluate(async()=>{
        await document.fonts.ready;
        await Promise.all(document.getAnimations().filter(a=>a.effect?.getComputedTiming().endTime!==Infinity).map(a=>a.finished.catch(()=>{})));
        await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));
    });
    await page.evaluate(()=>document.querySelectorAll('[data-dam-tab]').forEach(a=>a.addEventListener('click',e=>{e.preventDefault();window.__clicked=a.dataset.damTab;})));
}

const geometry=page=>page.evaluate(()=>{
    const box=el=>{const r=el.getBoundingClientRect();return {top:Math.round(r.top),bottom:Math.round(r.bottom),left:Math.round(r.left),right:Math.round(r.right),width:Math.round(r.width)};};
    const lane=document.querySelector('[data-dam-tab-lane]');
    const tabs=[...lane.querySelectorAll('[data-dam-tab]')];

    return {
        lane:box(lane),laneClient:lane.clientWidth,laneScroll:lane.scrollWidth,slot:box(document.querySelector('[data-slot]')),
        files:box(lane.querySelector('[data-dam-tab=files]')),firstTab:box(tabs[0]),tabRows:new Set(tabs.map(t=>Math.round(t.getBoundingClientRect().top))).size,
        pageWidth:document.documentElement.scrollWidth,
    };
});

test('below 640px the tab lane keeps its width and Files is reachable by click and keyboard',async()=>{
    await withPage(async page=>{
        for(const width of [375,639]){
            for(const dark of [false,true]){
                const label=`${width}px${dark?' dark':''}`;
                await render(page,width,{dark});
                const g=await geometry(page);
                assert.ok(g.laneClient>=200,`${label}: the lane is at least 200px wide (${g.laneClient})`);
                assert.ok(g.laneScroll>g.laneClient&&g.tabRows===1,`${label}: the tabs scroll sideways in one row`);
                assert.ok(g.slot.top>=g.lane.bottom-1,`${label}: the buttons sit on their own line below the lane (${g.slot.top} < ${g.lane.bottom})`);
                assert.ok(g.slot.right<=width&&g.pageWidth<=width,`${label}: the buttons and the page fit the viewport`);

                await page.click('[data-dam-tab=files]',{timeout:3000});
                assert.equal(await page.evaluate(()=>window.__clicked),'files',`${label}: clicking Files reaches the tab`);

                await render(page,width,{dark});
                await page.focus('[data-dam-tab=preview]');
                for(let i=0;i<TABS.indexOf('files');i++){
                    await page.keyboard.press('Tab');
                }
                assert.equal(await page.evaluate(()=>document.activeElement?.dataset?.damTab),'files',`${label}: Tab reaches Files`);
                await page.waitForTimeout(100);
                const f=await geometry(page);
                assert.ok(f.files.left>=f.lane.left-1&&f.files.right<=f.lane.right+1,`${label}: the focused Files tab is scrolled into view`);
                for(const name of ['Share','Embed','Add to collection']){
                    await page.locator('[data-slot]').getByText(name,{exact:true}).click({trial:true,timeout:3000});
                }
            }
        }
    });
});

test('an active tab past the edge opens scrolled into view at 375px',async()=>{
    await withPage(async page=>{
        await render(page,375,{active:'history'});
        const g=await geometry(page);
        assert.ok(g.laneScroll>g.laneClient,`the lane overflows, so History is past the edge (${g.laneScroll} <= ${g.laneClient})`);
        const lane=await page.evaluate(()=>document.querySelector('[data-dam-tab-lane]').scrollLeft);
        assert.ok(lane>0,`the lane opens scrolled toward the active tab (scrollLeft ${lane})`);
        const history=await page.evaluate(()=>{const l=document.querySelector('[data-dam-tab-lane]').getBoundingClientRect(),h=document.querySelector('[data-dam-tab=history]').getBoundingClientRect();return h.left>=l.left-1&&h.right<=l.right+1;});
        assert.ok(history,'the active History tab is inside the lane');
    });
});

test('from 640px the tabs wrap and the buttons stay on the first row, in light and dark',async()=>{
    await withPage(async page=>{
        for(const width of [640,1024,1440]){
            let reference=null;
            for(const dark of [false,true]){
                const label=`${width}px${dark?' dark':''}`;
                await render(page,width,{dark});
                const g=await geometry(page);
                assert.ok(g.laneScroll<=g.laneClient,`${label}: the lane does not scroll, the tabs wrap`);
                assert.ok(g.slot.left>=g.lane.right-1,`${label}: the buttons sit beside the lane (${g.slot.left} < ${g.lane.right})`);
                assert.ok(g.slot.top<g.firstTab.bottom,`${label}: the buttons are on the first row of tabs`);
                assert.ok(g.pageWidth<=width,`${label}: no horizontal page scroll`);
                if(width===1440){
                    assert.equal(g.tabRows<=2,true,`${label}: the eight tabs take at most two rows (${g.tabRows})`);
                }
                const key=JSON.stringify({lane:g.lane,slot:g.slot,rows:g.tabRows});
                if(reference===null){
                    reference=key;
                } else {
                    assert.equal(key,reference,`${label}: dark mode does not change the geometry`);
                }
            }
        }
    });
});

test('right to left keeps the lane usable at 375px',async()=>{
    await withPage(async page=>{
        await render(page,375,{dir:'rtl'});
        const g=await geometry(page);
        assert.ok(g.laneClient>=200,`rtl: the lane is at least 200px wide (${g.laneClient})`);
        await page.click('[data-dam-tab=files]',{timeout:3000});
        assert.equal(await page.evaluate(()=>window.__clicked),'files','rtl: clicking Files reaches the tab');
    });
});
