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
const open=async(browser,{bridge=false,width=1440,height=900,touch=false}={})=>{
    const page=await browser.newPage({viewport:{width,height},hasTouch:touch,reducedMotion:'reduce'});
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

// Apple's HIG asks for 44 pt targets: under touch the segment's tabs, each swatch and the hex that copies it are 44 px.
// A mouse keeps the smaller ones.
browserTest('on a touch screen the tabs, each swatch and the hex that copies it are 44 px targets',async()=>{
    const browser=await launch();
    try{
        for(const touch of [true,false]){
            const {page,errors}=await open(browser,{width:390,height:844,touch});
            assert.equal(await page.evaluate(()=>matchMedia('(pointer: coarse)').matches),touch);
            const sizes=await page.evaluate(()=>Object.fromEntries(['.ps-seg .tk-seg-opt','.ps-chip','.ps-hex'].map(selector=>[selector,
                [...document.querySelectorAll(selector)].filter(node=>node.checkVisibility()).map(node=>{const box=node.getBoundingClientRect();return {width:box.width,height:box.height};})])));
            for(const [selector,boxes] of Object.entries(sizes)){
                assert.ok(boxes.length>0,`${selector} is shown`);
                if(touch)assert.deepEqual(boxes.filter(box=>box.height<44||(selector==='.ps-chip'&&box.width<44)),[],`${selector} under touch`);
                else assert.ok(boxes.every(box=>box.height<44),`${selector} keeps its size under a mouse`);
            }
            assert.deepEqual(errors,[]);
            await page.close();
        }
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

browserTest('a save in progress holds the Library sheet, and the Library is asked once',async()=>{
    const browser=await launch();
    try{
        const {page,errors}=await open(browser,{bridge:true});
        // The Library takes its time: each save waits until the test lets it go.
        await page.evaluate(()=>{const bridge=window.MothershipFrame,save=bridge.save.bind(bridge);window.__asked=0;bridge.save=args=>{window.__asked++;return new Promise(done=>{window.__finish=()=>done(save(args));});};});
        const held=()=>page.evaluate(()=>{const go=document.querySelector('[data-library-go]');return {label:go.textContent,disabled:[go,...document.querySelectorAll('[data-save-image],[data-save-tokens],[data-folder]')].map(node=>node.disabled)};});
        await page.evaluate(()=>window.__press('library'));
        await page.locator('[data-folder] option',{hasText:'Brand'}).waitFor({state:'attached'});
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(()=>window.__asked===1);
        assert.deepEqual(await held(),{label:'Saving…',disabled:[true,true,true,true]});

        // Closed and opened again, the sheet shows the same save, still going.
        await page.keyboard.press('Escape');
        await page.evaluate(()=>window.__press('library'));
        assert.equal(await sheetOpen(page,'library'),true);
        assert.deepEqual(await held(),{label:'Saving…',disabled:[true,true,true,true]});
        // A press that reaches Save anyway starts nothing.
        await page.evaluate(()=>{const go=document.querySelector('[data-library-go]');go.disabled=false;go.click();});
        await settled(page);
        assert.equal(await page.evaluate(()=>window.__asked),1,'a second press while saving asks the Library nothing');

        await page.evaluate(()=>window.__finish());
        await page.waitForFunction(()=>window.__told.notices.length===1);
        assert.deepEqual(await page.evaluate(()=>window.__told.saves),[{names:['png','json'],folder:'7'}]);
        assert.equal(await sheetOpen(page,'library'),false);
        // The next save starts from a free sheet.
        await page.evaluate(()=>window.__press('library'));
        assert.deepEqual(await held(),{label:'Save',disabled:[false,false,false,false]});
        assert.deepEqual(errors,[]);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a refused save is never lost: the open sheet keeps the focus on Save, and a closed one is said in the bar and again when it opens',async()=>{
    const browser=await launch();
    try{
        const {page,errors}=await open(browser,{bridge:true});
        const line=()=>page.locator('[data-sheet="library"] .tk-status').evaluate(node=>({text:node.textContent,error:node.classList.contains('is-error')}));
        const openLibrary=async()=>{
            await page.evaluate(()=>window.__press('library'));
            await page.locator('[data-folder] option',{hasText:'Brand'}).waitFor({state:'attached'});
        };

        // Refused while the sheet is open: it says why, and Save has the focus back so Enter tries again.
        await openLibrary();
        await page.evaluate(()=>{window.MothershipFrame.save=async()=>{throw new Error('The Library is full.');};});
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(()=>document.querySelector('[data-sheet="library"] .tk-status').textContent==='The Library is full.');
        assert.deepEqual(await line(),{text:'The Library is full.',error:true});
        assert.equal(await page.evaluate(()=>document.activeElement.matches('[data-library-go]:not(:disabled)')),true,'Save keeps the focus');
        assert.deepEqual(await page.evaluate(()=>window.__told.notices),[],'the open sheet says it; the bar does not');

        // Refused after the sheet was closed: the bar says it at once...
        await page.evaluate(()=>{window.MothershipFrame.save=()=>new Promise((_,refuse)=>{window.__refuse=()=>refuse(new Error('The Library is full.'));});});
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(()=>typeof window.__refuse==='function');
        await page.keyboard.press('Escape');
        assert.equal(await sheetOpen(page,'library'),false);
        await page.evaluate(()=>window.__refuse());
        await page.waitForFunction(()=>window.__told.notices.length===1);
        assert.deepEqual(await page.evaluate(()=>window.__told.notices[0]),{text:'Not saved to the Library. The Library is full.',tone:'error'});
        assert.equal(await page.locator('[data-toast]').isHidden(),true,'the bar says it once; the tool keeps quiet');
        // ...and the sheet says it again when it opens, free to try again; once seen, it is not said a second time.
        await openLibrary();
        assert.deepEqual(await line(),{text:'The Library is full.',error:true});
        assert.equal(await page.locator('[data-library-go]').isDisabled(),false);
        await page.keyboard.press('Escape');
        await openLibrary();
        assert.deepEqual(await line(),{text:'',error:false});
        assert.deepEqual(errors,[]);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('with no folder to save into, the Library sheet says so and offers no ticks',async()=>{
    const browser=await launch();
    try{
        const {page,errors}=await open(browser,{bridge:true});
        await page.evaluate(()=>{window.MothershipFrame.folders=async()=>[];});
        await page.evaluate(()=>window.__press('library'));
        await page.locator('[data-folder-empty]').waitFor();
        assert.equal(await page.locator('[data-save-image]').isVisible(),false);
        assert.equal(await page.locator('[data-save-tokens]').isVisible(),false);
        assert.equal(await page.locator('[data-folder]').isVisible(),false);
        assert.equal(await page.locator('[data-library-go]').isDisabled(),true);
        assert.deepEqual(errors,[]);
        await page.close();
    } finally { await browser.close(); }
});

browserTest('a save sends the palette as it was when Save was pressed, however it changes while the picture is made',async()=>{
    const browser=await launch();
    try{
        const {page,errors}=await open(browser,{bridge:true});
        // The picture's encoding waits until the test lets it go; the bridge keeps each file's name and words.
        await page.evaluate(()=>{
            const encode=HTMLCanvasElement.prototype.toBlob;
            HTMLCanvasElement.prototype.toBlob=function(...args){window.__drawn=()=>encode.apply(this,args);};
            window.MothershipFrame.save=async({files})=>{window.__sent=await Promise.all(files.map(async file=>({name:file.name,text:file.name.endsWith('.json')?await file.blob.text():''})));return {count:files.length,url:null};};
        });
        const hexes=text=>(text.match(/#[0-9a-f]{6}/gi)||[]).map(hex=>hex.toLowerCase());
        await page.locator('[data-name]').fill('Before');
        await page.locator('[data-name]').press('Tab');
        const before=hexes((await page.locator('[data-swatches]').allTextContents()).join(' '));
        assert.ok(before.length>0);

        await page.evaluate(()=>window.__press('library'));
        await page.locator('[data-folder] option',{hasText:'Brand'}).waitFor({state:'attached'});
        await page.locator('[data-library-go]').click();
        await page.waitForFunction(()=>typeof window.__drawn==='function');
        // While the picture is still being made, the sheet is closed and the palette changed.
        await page.keyboard.press('Escape');
        await page.locator('[data-name]').fill('After');
        await page.locator('[data-name]').press('Tab');
        await page.locator('[data-generate]').click();
        await page.evaluate(()=>window.__drawn());
        await page.waitForFunction(()=>window.__sent);

        const sent=await page.evaluate(()=>window.__sent);
        assert.deepEqual(sent.map(file=>file.name),['Before.png','Before.json']);
        assert.match(sent[1].text,/Before/);
        assert.doesNotMatch(sent[1].text,/After/);
        assert.deepEqual(hexes(sent[1].text),before,'the tokens are the colours on screen when Save was pressed');
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
