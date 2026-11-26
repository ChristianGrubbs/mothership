// An unfolded iPhone Duo held sideways is 951px wide, and the Library there keeps the Directory column beside the file
// panel, which leaves the panel about 321px of room. Three columns made 91px cards (2026-11-18), so the file grid stays
// at two columns until xl, where it fills with cards at least 240px wide. This renders the real class list from the
// Blade source against the committed stylesheets.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {bladeComponent, bladeFixture} = require('../fixtures/blade.cjs');
const {launchBrowser} = require('../launch-browser.cjs');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');

function fileGridClasses(){
    const blade=source('packages/Mothership/DAM/src/Resources/views/components/explorer/grid.blade.php');
    const match=blade.match(/<p[^>]*>\s*@lang\('dam::app\.admin\.explorer\.sections\.files'\)\s*<\/p>\s*<div class="([^"]+)"/);
    assert.ok(match,'the file grid must still follow the Files heading in grid.blade.php');

    return match[1];
}

// The folder grid follows its own width, not the window: it fills with cards at least 11.5rem (184px) wide, and one
// card takes the whole row when the room is narrower than that.
function folderGridClasses(){
    const blade=source('packages/Mothership/DAM/src/Resources/views/components/explorer/grid.blade.php');
    const match=blade.match(/<div class="(grid grid-cols-\[repeat\(auto-fill,minmax\(min\(100%,11\.5rem\),1fr\)\)\][^"]*)"/);
    assert.ok(match,'the folder grid must keep its fill rule in grid.blade.php');

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

async function measureFolders(page,{panel,count}){
    await page.setViewportSize({width:1280,height:800});
    const cells=Array.from({length:count},()=>'<div style="height:40px"></div>').join('');
    await page.setContent(`<!doctype html><html><head></head><body style="margin:0"><div style="width:${panel}px" data-panel><div class="${folderGridClasses()}" data-grid>${cells}</div></div></body></html>`);
    for(const file of builtStylesheets()){
        await page.addStyleTag({path:file});
    }

    return page.evaluate(()=>{
        const grid=document.querySelector('[data-grid]');
        const boxes=[...grid.children].map(cell=>cell.getBoundingClientRect());

        return {
            columns:getComputedStyle(grid).gridTemplateColumns.split(' ').length,
            card:boxes[0].width,
            rows:new Set(boxes.map(box=>Math.round(box.top))).size,
            sideways:grid.scrollWidth>grid.clientWidth||document.querySelector('[data-panel]').scrollWidth>document.querySelector('[data-panel]').clientWidth,
        };
    });
}

test('the Library folder grid fills its own width with cards at least 184px wide, and one card per row below that',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();

        // [panel width, folders, columns, rows]. The gap is 12px, so two cards need 380px.
        for(const [panel,count,columns,rows] of [[160,1,1,1],[160,3,1,3],[321,3,1,3],[400,1,2,1],[400,3,2,2],[800,1,4,1],[800,5,4,2]]){
            const got=await measureFolders(page,{panel,count});
            assert.equal(got.columns,columns,`${panel}px panel, ${count} folders: ${columns} columns`);
            assert.equal(got.rows,rows,`${panel}px panel, ${count} folders: ${rows} rows`);
            assert.equal(got.sideways,false,`${panel}px panel, ${count} folders: nothing scrolls sideways`);
            if(panel<184){
                assert.equal(Math.round(got.card),panel,`${panel}px panel: one card takes the whole row`);
            } else {
                assert.ok(got.card>=184,`${panel}px panel: cards are at least 184px wide, got ${got.card}px`);
            }
        }
    } finally {
        await browser.close();
    }
});

test('the Library file grid keeps readable cards on an unfolded iPhone Duo and elsewhere',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {
        const page=await browser.newPage();
        const sideways=await measure(page,{width:951,panel:321});
        assert.equal(sideways.columns,2,'951px wide: two columns in the narrow panel');
        assert.ok(sideways.cell>=140,`951px wide: cards stay readable, got ${sideways.cell}px`);

        for(const [width,panel,columns] of [[375,309,1],[382,316,2],[430,364,2],[669,603,2],[1024,640,2],[1280,800,3],[1440,1100,4]]){
            assert.equal((await measure(page,{width,panel})).columns,columns,`${width}px wide: ${columns} columns`);
        }
    } finally {
        await browser.close();
    }
});

test('changing tile size resizes the Library cards, fits a narrow pane, and survives reopening', async () => {
    const browser = await launchBrowser({playwrightPath: process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH});
    try {
        const page = await browser.newPage({viewport: {width: 1440, height: 800}});
        const tab = bladeComponent('explorer-tab');
        const grid = bladeComponent('explorer-grid');
        await page.route('http://library.test/', route => route.fulfill({contentType: 'text/html', body: '<main id="app"></main>'}));

        async function openLibrary() {
            await page.goto('http://library.test/');
            for (const file of builtStylesheets()) await page.addStyleTag({path: file});
            await page.addScriptTag({path: process.env.MOTHERSHIP_VUE_RUNTIME_PATH});
            await page.evaluate(() => {
                window.definitions = {};
                window.app = {component(name, definition) { definitions[name] = definition; }};
                window.MothershipLibraryNavigation = {resolveSort: () => 'name:asc'};
            });
            for (const script of [bladeFixture('explorer-marquee.js'), tab.script, grid.script]) {
                await page.addScriptTag({content: script});
            }
            await page.evaluate(({tabTemplate, gridTemplate}) => {
                // Mount the rendered control and its actual grid binding, isolating the tab from its API requests.
                const template = document.createElement('template');
                template.innerHTML = tabTemplate;
                const control = template.content.querySelector('[data-explorer-tile-size]').closest('label');
                const [gridBinding] = tabTemplate.match(/<v-dam-explorer-grid\b[\s\S]*?<\/v-dam-explorer-grid>/);
                const tab = definitions['v-dam-tab'];
                const app = Vue.createApp({
                    template: control.outerHTML + gridBinding,
                    props: tab.props,
                    methods: tab.methods,
                    data() {
                        return {...tab.data.call(this), blockIndex: 0, emptyState: null, canAccessCurrentDir: true,
                            block: {dirs: [], assets: Array.from({length: 12}, (_, i) => ({id: i + 1}))}};
                    },
                }, {tabId: 'size-test'});
                app.component('v-dam-explorer-grid', {...definitions['v-dam-explorer-grid'], template: gridTemplate});
                app.component('v-dam-explorer-asset-card', {template: '<div data-card></div>'});
                for (const name of ['v-dam-explorer-folder-card', 'v-dam-explorer-ctx']) {
                    app.component(name, {template: '<div></div>'});
                }
                app.mount('#app');
            }, {tabTemplate: tab.template, gridTemplate: grid.template});
        }

        const size = page.getByRole('combobox');
        const card = page.locator('[data-card]').first();
        const cardWidth = () => card.evaluate(el => el.getBoundingClientRect().width);
        await openLibrary();
        assert.equal(await size.inputValue(), 'medium');
        const medium = await cardWidth();
        await size.selectOption('small');
        assert.ok(await cardWidth() < medium);
        await size.selectOption('large');
        assert.ok(await cardWidth() > medium);
        await openLibrary();
        assert.equal(await size.inputValue(), 'large');
        await page.setViewportSize({width: 375, height: 667});
        const largePhone = await cardWidth();
        assert.ok(largePhone <= 375);
        await size.selectOption('small');
        assert.ok(await cardWidth() < largePhone);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), false);
    } finally {
        await browser.close();
    }
});
