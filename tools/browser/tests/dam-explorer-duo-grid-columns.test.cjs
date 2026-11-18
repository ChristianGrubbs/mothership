// An unfolded iPhone Duo held sideways is 951px wide, and the Library there keeps the Directory column beside the file
// panel, which leaves the panel about 321px of room. Three columns made 91px cards (2026-11-18), so the file grid stays
// at two columns until lg. This renders the real class list from the Blade source against the committed stylesheets.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');

function fileGridClasses(){
    const blade=source('packages/Mothership/DAM/src/Resources/views/components/explorer/grid.blade.php');
    const match=blade.match(/<p[^>]*>\s*@lang\('dam::app\.admin\.explorer\.sections\.files'\)\s*<\/p>\s*<div class="([^"]+)"/);
    assert.ok(match,'the file grid must still follow the Files heading in grid.blade.php');

    return match[1];
}

function builtStylesheets(){
    return ['public/themes/admin/default/build','public/themes/dam/build'].map(dir=>{
        const manifest=JSON.parse(source(`${dir}/manifest.json`));
        const entry=Object.values(manifest).find(item=>typeof item.file==='string'&&item.file.startsWith('assets/app-')&&item.file.endsWith('.css'));
        assert.ok(entry,`${dir}/manifest.json must list a built assets/app-*.css entry`);

        return path.join(root,dir,entry.file);
    });
}

async function measure(page,{width,panel}){
    await page.setViewportSize({width,height:800});
    const cells=Array.from({length:6},()=>'<div style="height:40px"></div>').join('');
    await page.setContent(`<!doctype html><html><head></head><body style="margin:0"><div style="width:${panel}px"><div class="${fileGridClasses()}" data-grid>${cells}</div></div></body></html>`);
    for(const file of builtStylesheets()){
        await page.addStyleTag({path:file});
    }

    return page.evaluate(()=>{
        const grid=document.querySelector('[data-grid]');

        return {columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,cell:grid.children[0].getBoundingClientRect().width};
    });
}

test('the Library file grid keeps readable cards on an unfolded iPhone Duo and elsewhere',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        const sideways=await measure(page,{width:951,panel:321});
        assert.equal(sideways.columns,2,'951px wide: two columns in the narrow panel');
        assert.ok(sideways.cell>=140,`951px wide: cards stay readable, got ${sideways.cell}px`);

        for(const [width,panel,columns] of [[375,309,1],[382,316,2],[430,364,2],[669,603,2],[1024,640,3],[1280,800,4]]){
            assert.equal((await measure(page,{width,panel})).columns,columns,`${width}px wide: ${columns} columns`);
        }
    } finally {
        await browser.close();
    }
});
