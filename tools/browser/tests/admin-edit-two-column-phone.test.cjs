// The category-field and association-type edit pages (catalog/categories/field/edit.blade.php and
// catalog/associations/types/edit.blade.php) put a flexible left column beside a 360px settings column.
// At phone width the settings column kept its 360px, so the pair still sat side by side and the left
// column was squeezed to a sliver (about 28px at 430px). Below the admin `sm` breakpoint (525px) the row
// now wraps and the settings column takes the full width, so the columns stack.
//
// This renders the real row and column classes from each Blade source against the committed admin
// stylesheet, with a left column holding unbreakable wide content as the field builder does.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');

const pages={
    'association-type edit':'packages/Mothership/Admin/src/Resources/views/catalog/associations/types/edit.blade.php',
    'category-field edit':'packages/Mothership/Admin/src/Resources/views/catalog/categories/field/edit.blade.php',
};

// The two-column row (the one that wraps below sm), its left column (the next div) and the 360px column.
function columnClasses(file){
    const blade=source(file);
    const row=blade.match(/<div class="(flex gap-2\.5[^"]*max-sm:flex-wrap[^"]*|flex gap-2\.5[^"]*)">((?:\s*<!--[\s\S]*?-->)*)\s*<div class="([^"]*flex-1[^"]*)">/);
    const right=blade.match(/<div class="([^"]*w-\[360px\][^"]*)">/);
    assert.ok(row&&right,`the two-column row and the 360px column must still be found in ${file}`);

    return {row:row[1],left:row[3],right:right[1]};
}

function adminStylesheet(){
    const manifest=JSON.parse(source('public/themes/admin/default/build/manifest.json'));
    const entry=Object.values(manifest).find(item=>typeof item.file==='string'&&item.file.startsWith('assets/app-')&&item.file.endsWith('.css'));
    assert.ok(entry,'the committed admin manifest must list a built assets/app-*.css entry');

    return path.join(root,'public/themes/admin/default/build',entry.file);
}

const browserTest=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip;

async function layoutAt(page,width,classes){
    await page.setViewportSize({width,height:900});
    // The content area pads 16px each side; the left column holds a field-builder table that cannot shrink.
    await page.setContent(`<!doctype html><html><head></head><body style="margin:0">
        <main style="padding:0 16px">
            <div class="${classes.row}" data-row>
                <div class="${classes.left}" data-left><div style="min-width:640px;height:200px">Fields</div></div>
                <div class="${classes.right}" data-right><input type="text" class="w-full" value="code"><p>General</p></div>
            </div>
        </main></body></html>`);
    await page.addStyleTag({path:adminStylesheet()});

    return page.evaluate(()=>{
        const box=selector=>{const rect=document.querySelector(selector).getBoundingClientRect();return {top:rect.top,bottom:rect.bottom,left:rect.left,right:rect.right,width:rect.width};};
        const doc=document.documentElement;

        return {left:box('[data-left]'),right:box('[data-right]'),content:document.querySelector('main').clientWidth-32,overflow:Math.max(0,doc.scrollWidth-doc.clientWidth,document.body.scrollWidth-doc.clientWidth)};
    });
}

for(const [name,file] of Object.entries(pages)){
    browserTest(`${name}: the two columns stack below 525px with no horizontal overflow`,async()=>{
        const classes=columnClasses(file);
        const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
        const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});

        try {
            const page=await browser.newPage();

            for(const width of [375,430,524]){
                const layout=await layoutAt(page,width,classes);
                assert.ok(layout.right.top>=layout.left.bottom-1,`${width}px: the settings column sits below the left column (left bottom ${layout.left.bottom}, right top ${layout.right.top})`);
                assert.ok(layout.left.width>=layout.content-1,`${width}px: the left column takes the full width (${layout.left.width} of ${layout.content})`);
                assert.ok(layout.right.width<=layout.content+1,`${width}px: the settings column fits (${layout.right.width} of ${layout.content})`);
                assert.equal(layout.overflow,0,`${width}px: the page does not scroll sideways`);
            }

            const wide=await layoutAt(page,525,classes);
            assert.ok(Math.abs(wide.left.top-wide.right.top)<1,'525px: the columns sit side by side');
            assert.ok(Math.abs(wide.right.width-360)<1||wide.right.width<=360,'525px: the settings column keeps its 360px');
        } finally {
            await browser.close();
        }
    });
}
