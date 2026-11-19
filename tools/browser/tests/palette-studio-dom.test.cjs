const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {pathToFileURL}=require('node:url');

// Palette Studio in a real browser, opened from disk: alone, and with a stand-in for the bridge the tool frame gives
// it. The tool kit's colours are held equal to the shell's here too, because a tool is drawn inside the shell.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const TOOL_URL=pathToFileURL(path.join(root,'packages/Mothership/DAM/src/Resources/widgets/palette-studio/index.html')).href;
const launch=()=>require(playwrightPath).chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});

// The custom properties of the rule block that holds `marker`.
const tokens=(css,marker)=>{
    const at=css.indexOf(marker);
    assert.ok(at>=0,`found ${marker}`);
    const body=css.slice(css.lastIndexOf('{',at)+1,css.indexOf('}',at)).replace(/\/\*[\s\S]*?\*\//g,'');
    return Object.fromEntries([...body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map(([,name,value])=>[name,value.replace(/\s+/g,' ').trim().toLowerCase()]));
};

test('every colour in the tool kit equals the shell token of the same name, in both themes',()=>{
    const shell=fs.readFileSync(path.join(root,'packages/Mothership/Admin/src/Resources/assets/css/app.css'),'utf8');
    const kit=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/widgets/_kit/tool-kit.css'),'utf8');
    const shellLight=tokens(shell,'--ground: #EFEFEF'),shellDark=tokens(shell,'--ground: #0B0B0B');
    const kitLight=tokens(kit,'--ground: #EFEFEF'),kitDark=tokens(kit,'--ground: #0B0B0B');
    // The sheet's shade and drop shadow are the kit's own; the type stacks carry no colour.
    const own=name=>['--shade','--float'].includes(name)||name.startsWith('--font');
    const names=Object.keys(kitLight).filter(name=>!own(name));
    assert.ok(names.length>=15,`the kit names its colours: ${names.length}`);
    for(const name of names){
        assert.equal(kitLight[name],shellLight[name],`${name} in the light theme`);
        // A colour the kit's dark theme does not restate is inherited from its light one.
        assert.equal(kitDark[name]??kitLight[name],shellDark[name]??shellLight[name],`${name} in the dark theme`);
    }
});

// Stands in for home-frame.js: keeps what the tool told the page, and presses the bar's buttons.
const withBridge=()=>{
    const handlers={};
    window.__told={chrome:[],notices:[],saves:[]};
    window.__press=(name,detail)=>handlers[name]?.(detail);
    window.MothershipFrame={
        bar:true,
        library:true,
        chrome(shown){window.__told.chrome.push(shown);},
        on(name,handler){handlers[name]=handler;},
        notice(shown){window.__told.notices.push(shown);},
        async folders(){return [{id:'7',name:'Brand',depth:0}];},
        async save({files,folder}){window.__told.saves.push({names:files.map(file=>file.name.split('.').pop()),folder});return {count:files.length,url:'/admin/dam/assets/1'};},
    };
};
const open=async(browser,{bridge=false,width=1440,height=900}={})=>{
    const page=await browser.newPage({viewport:{width,height},reducedMotion:'reduce'});
    const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    if(bridge)await page.addInitScript(withBridge);
    await page.goto(TOOL_URL);
    await page.locator('[data-swatches] li').first().waitFor();
    return {page,errors};
};
const settled=page=>page.evaluate(()=>new Promise(done=>requestAnimationFrame(()=>requestAnimationFrame(done))));
const sheetOpen=(page,name)=>page.locator(`[data-sheet="${name}"]`).evaluate(sheet=>sheet.open);

browserTest('alone, the tool shows its own way to its sheets and offers no Save to Library',async()=>{
    const browser=await launch();
    try{
        const {page,errors}=await open(browser);
        assert.equal(await page.locator('[data-own-bar]').isVisible(),true);
        assert.equal(await page.locator('[data-open-library]').isVisible(),false);

        // The selected swatch says so in words, and each contrast pair gives one verdict with its sign.
        assert.equal(await page.locator('[data-swatches] li',{hasText:'Selected'}).count(),1);
        const verdicts=await page.locator('[data-contrast] > li').allTextContents();
        assert.ok(verdicts.length>0);
        for(const pair of verdicts)assert.match(pair,/AAA|AA|Large only|Fails/);

        await page.locator('[data-open-export]').click();
        assert.equal(await sheetOpen(page,'export'),true);
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page,'export'),false);
        assert.deepEqual(errors,[]);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('in the frame, the bar speaks for the tool and a save goes to the chosen folder',async()=>{
    const browser=await launch();
    try{
        const {page,errors}=await open(browser,{bridge:true});
        assert.equal(await page.locator('[data-own-bar]').isVisible(),false);
        const said=await page.evaluate(()=>window.__told.chrome.at(-1));
        assert.equal(said.setsLabel,'Saved palettes');
        assert.equal(said.export,true);
        assert.equal(said.library,true);
        assert.equal(said.sets.filter(set=>set.current===true).length,1,'one saved palette is the open one');

        await page.evaluate(()=>window.__press('export'));
        assert.equal(await sheetOpen(page,'export'),true);
        assert.equal(await page.evaluate(()=>document.activeElement.matches('input[type="radio"]')),true,'the first choice takes the focus, never the scrolling body');
        await page.keyboard.press('Escape');
        await page.evaluate(()=>window.__press('manage-sets'));
        assert.equal(await sheetOpen(page,'saved'),true);
        await page.keyboard.press('Escape');

        await page.evaluate(()=>window.__press('library'));
        await page.locator('[data-folder] option',{hasText:'Brand'}).waitFor({state:'attached'});
        // Nothing ticked is nothing to save.
        await page.locator('[data-save-image]').uncheck();
        await page.locator('[data-save-tokens]').uncheck();
        assert.equal(await page.locator('[data-library-go]').isDisabled(),true);
        await page.locator('[data-save-image]').check();
        await page.locator('[data-save-tokens]').check();

        await page.locator('[data-library-go]').click();
        await page.waitForFunction(()=>window.__told.notices.length===1);
        assert.deepEqual(await page.evaluate(()=>window.__told.saves),[{names:['png','json'],folder:'7'}]);
        const notice=await page.evaluate(()=>window.__told.notices[0]);
        assert.equal(notice.text,'Saved 2 files to Brand.');
        assert.deepEqual(notice.link,{label:'Open in Library',url:'/admin/dam/assets/1'});
        assert.equal(await sheetOpen(page,'library'),false);
        assert.equal(await page.locator('[data-toast]').isHidden(),true,'the bar says it once; the tool keeps quiet');
        assert.deepEqual(errors,[]);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('the panes follow the tool\'s width, and nothing scrolls sideways',async()=>{
    const browser=await launch();
    try{
        const {page}=await open(browser);
        const left=selector=>page.locator(selector).evaluate(node=>Math.round(node.getBoundingClientRect().left));
        for(const [width,height] of [[1440,900],[1024,768],[768,1024],[720,900],[719,900],[669,867],[594,368],[390,844]]){
            await page.setViewportSize({width,height});
            await settled(page);
            assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth-document.documentElement.clientWidth),0,`sideways at ${width}`);
        }

        await page.setViewportSize({width:1440,height:900});
        await settled(page);
        assert.ok(await left('[data-pane="swatches"]')<await left('[data-pane="scales"]'));
        assert.ok(await left('[data-pane="scales"]')<await left('[data-pane="contrast"]'),'three panes side by side');

        await page.setViewportSize({width:1024,height:768});
        await settled(page);
        assert.equal(await left('[data-pane="scales"]'),await left('[data-pane="contrast"]'),'Contrast under Scales');

        await page.setViewportSize({width:390,height:844});
        await settled(page);
        assert.equal(await page.locator('[data-pane="swatches"]').isVisible(),true);
        assert.equal(await page.locator('[data-pane="scales"]').isVisible(),false);
        await page.locator('[data-view-opt="scales"]').click();
        assert.equal(await page.locator('[data-pane="swatches"]').isVisible(),false);
        assert.equal(await page.locator('[data-pane="scales"]').isVisible(),true);
        assert.equal(await page.locator('[data-view-opt="scales"]').getAttribute('aria-pressed'),'true');
        await page.close();
    } finally { await browser.close(); }
});

browserTest('on a book fold the panes and a sheet keep clear of the strip',async()=>{
    const browser=await launch();
    try{
        const {page}=await open(browser,{width:951,height:585});
        await page.evaluate(()=>{
            const html=document.documentElement;
            html.dataset.fold='columns';
            html.style.setProperty('--fold-start','455px');
            html.style.setProperty('--fold-end','495px');
        });
        await settled(page);
        const onStrip=await page.evaluate(()=>[...document.querySelectorAll('main button, main input, main select, main summary')].filter(node=>{
            const box=node.getBoundingClientRect();
            return box.width>0&&box.height>0&&box.left<495&&box.right>455;
        }).map(node=>node.getAttribute('aria-label')||node.textContent.trim().slice(0,30)));
        assert.deepEqual(onStrip,[]);

        await page.locator('[data-open-export]').click();
        await settled(page);
        const sheet=await page.locator('[data-sheet="export"]').evaluate(node=>{const box=node.getBoundingClientRect();return {left:box.left,right:box.right};});
        assert.ok(sheet.left>=495,`the sheet starts past the fold: ${sheet.left}`);
        assert.ok(sheet.right<=951,`the sheet stays on the screen: ${sheet.right}`);
        await page.close();
    } finally { await browser.close(); }
});
