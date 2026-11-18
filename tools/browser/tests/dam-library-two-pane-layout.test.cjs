// The Library (packages/Mothership/DAM/src/Resources/views/asset/index.blade.php, explorer enabled) puts the Directory
// column and the asset panel side by side from md (768px) up, so a tablet or an unfolded iPhone Duo held sideways (951px)
// gets both panes; below md the column is an off-canvas drawer. Upright, the inner display (669 to 703px) once kept both
// panes too, which left a two-column grid of ~136px cards whose names showed three characters (operator screenshot,
// 2026-10-29); there the asset panel now takes the row. The panel keeps 360px below lg and 400px from lg. The row
// once wrapped below xl, so between 1024 and 1279px, with the main nav expanded, the asset panel dropped under the
// tall Directory column and the first screen showed only the tree (production, 2026-10-07). This renders the real
// row, column and panel classes from the Blade source against the committed built stylesheets.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');

// The explorer-enabled branch of each `{{ config('dam.explorer.enabled') ? '…' : '…' }}` class list.
function explorerClasses(){
    const blade=source('packages/Mothership/DAM/src/Resources/views/asset/index.blade.php');
    const branch=/\{\{\s*config\('dam\.explorer\.enabled'\)\s*\?\s*'([^']*)'\s*:\s*'([^']*)'\s*\}\}/g;
    const resolve=text=>text.replace(branch,(_,enabled)=>enabled).replace(/\s+/g,' ').trim();
    const row=blade.match(/<div class="(\{\{ config\('dam\.explorer\.enabled'\) \? 'flex gap-2\.5[^"]*)"[^>]*>/);
    const side=blade.match(/data-explorer-sidebar\s+class="([^"]*)"/);
    const panel=blade.match(/<div class="(flex flex-col gap-2 flex-1[^"]*)"[^>]*>\s*\{!! view_render_event\('dam\.admin\.main\.form\.grid\.before'\)/);
    assert.ok(row&&side&&panel,'the Library row, Directory column and asset panel must still be found in the Blade source');

    return {row:resolve(row[1]),side:resolve(side[1]),panel:resolve(panel[1])};
}

function builtStylesheets(){
    const sheet=(dir)=>{
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

// The expanded main nav is 270px and the content area pads 16px each side; the column is 280px wide by default (the
// Vue component sets the dragged width inline from lg up) and as tall as a production tree. The panel holds a toolbar whose chips,
// laid out on one line, need far more than the room left, as the explorer's do.
async function layoutAt(page,width,classes,{columnWidth=280,navWidth=270,dir='ltr'}={}){
    await page.setViewportSize({width,height:900});
    const chips=Array.from({length:14},(_,i)=>`<button class="whitespace-nowrap px-3 py-1">Toolbar control ${i+1}</button>`).join('');
    await page.setContent(`<!doctype html><html dir="${dir}"><head></head><body style="margin:0">
        <div style="display:flex"><nav style="width:${navWidth}px;flex-shrink:0"></nav>
        <main style="flex:1;min-width:0;padding:0 16px">
            <div class="${classes.row}" data-row>
                <div class="${classes.side}" style="width:${columnWidth}px" data-side><div style="height:1100px"></div></div>
                <div class="${classes.panel}" data-panel><div class="flex flex-wrap gap-2">${chips}</div></div>
            </div>
        </main></div></body></html>`);
    for(const file of builtStylesheets()){
        await page.addStyleTag({path:file});
    }

    return page.evaluate(()=>{
        const box=selector=>document.querySelector(selector).getBoundingClientRect().toJSON();

        return {row:box('[data-row]'),side:box('[data-side]'),panel:box('[data-panel]'),pageWidth:document.documentElement.scrollWidth};
    });
}

test('the asset panel stays beside the Directory column at every desktop width, nav expanded',async()=>{
    const classes=explorerClasses();
    await withPage(async page=>{
        for(const width of [1024,1100,1180,1279,1280,1440]){
            const {row,side,panel,pageWidth}=await layoutAt(page,width,classes);
            assert.equal(Math.round(panel.top),Math.round(side.top),`${width}px: the panel must start level with the column, not under it`);
            assert.ok(panel.left>=side.right,`${width}px: the panel sits to the right of the column`);
            assert.ok(panel.right<=row.right+1,`${width}px: the panel fits the row (${panel.right} > ${row.right})`);
            assert.ok(pageWidth<=width,`${width}px: no horizontal page scroll (${pageWidth})`);
        }
    });
});

// Below lg the admin nav is an overlay, so the row has the full width: the unfolded iPhone Duo held sideways (951px) and
// portrait tablets keep both panes, and a column dragged wide gives way so the panel keeps 360px.
test('the asset panel stays beside the Directory column from 768px, nav hidden',async()=>{
    const classes=explorerClasses();
    await withPage(async page=>{
        for(const width of [768,820,951,1023]){
            for(const columnWidth of [280,560]){
                const {row,side,panel,pageWidth}=await layoutAt(page,width,classes,{navWidth:0,columnWidth});
                assert.equal(Math.round(panel.top),Math.round(side.top),`${width}px column ${columnWidth}: the panel starts level with the column`);
                assert.ok(panel.left>=side.right&&panel.width>=359,`${width}px column ${columnWidth}: the panel sits beside the column at 360px or more (${panel.width})`);
                assert.ok(side.width>=220,`${width}px column ${columnWidth}: the column keeps its 220px minimum (${side.width})`);
                assert.ok(panel.right<=row.right+1&&pageWidth<=width,`${width}px column ${columnWidth}: everything fits the row`);
            }
        }
    });
});

// An upright unfolded iPhone Duo (669 to 703px) or a large phone: the Directory column is the drawer, and the asset
// panel has the whole row for its grid.
test('below 768px the asset panel takes the row and the Directory column is a drawer',async()=>{
    const classes=explorerClasses();
    await withPage(async page=>{
        for(const width of [390,669,703,767]){
            const {row,panel,pageWidth}=await layoutAt(page,width,classes,{navWidth:0});
            assert.ok(Math.abs(panel.left-row.left)<=1&&panel.width>=row.width-1,`${width}px: the panel spans the row (${panel.width} of ${row.width})`);
            assert.equal(await page.evaluate(()=>getComputedStyle(document.querySelector('[data-side]')).position),'fixed',`${width}px: the column is the off-canvas drawer`);
            assert.ok(pageWidth<=width,`${width}px: nothing scrolls sideways`);
        }
    });
});

// The column can be dragged out to 560px. Where that leaves too little room, the column gives way (down to its 220px
// drag minimum) and the panel keeps 400px, instead of shrinking to a sliver or wrapping under the tree.
test('a column dragged wide gives way so the asset panel keeps a usable width',async()=>{
    const classes=explorerClasses();
    await withPage(async page=>{
        for(const width of [1024,1180,1280,1440]){
            const {row,side,panel,pageWidth}=await layoutAt(page,width,classes,{columnWidth:560});
            assert.equal(Math.round(panel.top),Math.round(side.top),`${width}px: the panel must start level with the column`);
            assert.ok(panel.width>=399,`${width}px: the panel keeps at least 400px (${panel.width})`);
            assert.ok(side.width>=220,`${width}px: the column keeps its 220px minimum (${side.width})`);
            assert.ok(panel.right<=row.right+1&&pageWidth<=width,`${width}px: everything fits the row`);
        }
    });
});

// The same holds with the nav collapsed to its 70px rail, and right to left, where the panel sits to the column's left.
test('the asset panel stays beside the column with the nav collapsed and right to left',async()=>{
    const classes=explorerClasses();
    await withPage(async page=>{
        for(const width of [1024,1180]){
            for(const [label,options] of [['collapsed nav',{navWidth:70}],['rtl',{dir:'rtl'}],['rtl, column 560',{dir:'rtl',columnWidth:560}]]){
                const {row,side,panel,pageWidth}=await layoutAt(page,width,classes,options);
                const beside=options.dir==='rtl'?panel.right<=side.left:panel.left>=side.right;
                assert.equal(Math.round(panel.top),Math.round(side.top),`${width}px ${label}: the panel starts level with the column`);
                assert.ok(beside&&panel.width>=399,`${width}px ${label}: the panel sits beside the column at 400px or more (${panel.width})`);
                assert.ok(panel.left>=row.left-1&&panel.right<=row.right+1&&pageWidth<=width,`${width}px ${label}: everything fits the row`);
            }
        }
    });
});
