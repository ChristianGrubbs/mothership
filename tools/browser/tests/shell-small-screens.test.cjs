const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const {launchBrowser}=require('../launch-browser.cjs');

// The admin shell on small and folded screens, under the admin stylesheet as it is built and committed: where the rail
// is words, icons or gone, which screens get the phone's tab bar, the analytics notice on one line, and what a list
// gives up on a short screen. Home and the iPhone Duo layouts are laid out on these numbers, so they are a contract.
// The page here is the shell's skeleton with the hooks the views carry; the first test ties those hooks to the views.
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest=playwrightPath?test:test.skip;
const root=path.resolve(__dirname,'../../..');
const read=file=>fs.readFileSync(path.join(root,file),'utf8');
const BUILD='public/themes/admin/default/build';
const builtStyles=()=>{
    const manifest=JSON.parse(read(`${BUILD}/manifest.json`));
    const entry=Object.values(manifest).find(item=>typeof item.file==='string'&&item.file.startsWith('assets/app-')&&item.file.endsWith('.css'));
    assert.ok(entry,'the committed admin manifest lists a built assets/app-*.css');
    return read(`${BUILD}/${entry.file}`);
};
const ADMIN_VIEWS='packages/Mothership/Admin/src/Resources/views/components/layouts';
const NOTICE_VIEW='packages/Mothership/DAM/src/Resources/views/analytics/footer.blade.php';
// The notice's own words, so "one line" is tested on the sentence people get.
const notice=(()=>{
    const found=read('packages/Mothership/DAM/src/Resources/lang/en_US/app.php')
        .match(/'footer'\s*=>\s*\[\s*'text'\s*=>\s*'([^']*)',\s*'link'\s*=>\s*'([^']*)',\s*'text-short'\s*=>\s*'([^']*)',\s*'link-short'\s*=>\s*'([^']*)'/);
    assert.ok(found,'the analytics notice has a long and a short sentence and link');
    return {text:found[1],link:found[2],textShort:found[3],linkShort:found[4]};
})();

const railRow=name=>`<div class="bridge-rail-row"><a href="#" class="bridge-rail-link" aria-label="${name}"><svg width="20" height="20"></svg><span class="bridge-rail-text">${name}</span></a></div>`;
const tab=name=>`<a href="#"><svg width="22" height="22"></svg><span>${name}</span></a>`;
const listRow=name=>`<div class="bridge-list-row"><div class="bridge-list-main">${name}</div><div class="bridge-list-status">Active</div><div class="bridge-list-actions"><button type="button">Copy link</button></div><div class="bridge-list-meta">Expires in 3 days</div></div>`;
const PAGE=`<!doctype html><html><head><style>${builtStyles()}</style></head><body>
<div id="app" class="flex flex-col h-screen">
  <div class="flex flex-1 min-h-0 sidebar-not-collapsed">
    <div id="mothership-sidebar" class="bridge-rail bridge-phone-hide flex flex-col shrink-0 h-full">
      <div class="bridge-rail-scroll flex-1 min-h-0 overflow-y-auto overflow-x-hidden"><nav class="bridge-rail-nav">${['Home','Library','Collections','Tools','Shared links','Upload requests','Inbox','Tag review','Trash'].map(railRow).join('')}</nav></div>
      <div class="bridge-rail-foot"><nav class="bridge-rail-nav">${['Connections','Settings','Help'].map(railRow).join('')}</nav></div>
    </div>
    <div class="bridge-column">
      <header class="bridge-top"><div class="bridge-top-actions"><button type="button" class="bridge-icon-btn bridge-phone-hide" data-feedback>?</button></div></header>
      <div class="bridge-phone-only" data-drawer-theme>Theme</div>
      <main id="main-content" class="flex-1 min-w-0 overflow-y-auto overflow-x-hidden px-4 pt-3 pb-6">
        <div class="bridge-page-head flex min-h-10 items-center justify-between gap-4"><div class="flex flex-col gap-1.5"><p class="bridge-page-group">Sorting</p><div class="flex flex-col gap-1"><h1 class="text-xl font-bold">Tag review</h1><p class="text-sm" data-description>Tags suggested for files you can tag.</p></div></div></div>
        <div data-list-width><div class="bridge-list">
          <div class="bridge-list-bar"><label class="bridge-list-pick"><span class="bridge-list-check"><input type="checkbox" class="sr-only"><span>x</span></span><span data-select-all-words>Select all on this page</span></label><span role="status">2 selected</span><button type="button" class="bridge-list-clear">Clear</button><span class="bridge-list-bar-actions"><button type="button" class="primary-button">Accept (2)</button><button type="button" class="secondary-button">Dismiss (2)</button></span></div>
          ${listRow('One')}${listRow('Two')}
        </div></div>
        <div data-explorer-selection-bar>Move</div>
      </main>
    </div>
  </div>
  <nav class="bridge-tabbar" data-bridge-tabbar>${['Home','Library','Collections','Away'].map(tab).join('')}<button type="button"><svg width="22" height="22"></svg><span>More</span></button></nav>
  <footer data-fold-wrap class="bridge-notice shrink-0 border-t px-4 py-1 text-xs"><span class="bridge-notice-text"><span class="bridge-notice-long">${notice.text}</span><span class="bridge-notice-short">${notice.textShort}</span></span><a href="#"><span class="bridge-notice-long">${notice.link}</span><span class="bridge-notice-short">${notice.linkShort}</span></a></footer>
</div></body></html>`;

async function shell(browser,width,height){
    const page=await browser.newPage({viewport:{width,height},reducedMotion:'reduce'});
    await page.route('http://mothership.test/**',route=>route.fulfill({contentType:'text/html',body:PAGE}));
    await page.goto('http://mothership.test/');
    return page;
}

// What the shell looks like at one size: boxes as [left, top, width, height], null for a part that is not drawn.
const readShell=page=>page.evaluate(()=>{
    const drawn=node=>{
        if(!node)return null;
        const box=node.getBoundingClientRect();
        return getComputedStyle(node).display!=='none'&&box.width>0&&box.height>0?[box.left,box.top,box.width,box.height].map(Math.round):null;
    };
    const one=selector=>drawn(document.querySelector(selector));
    const footer=document.querySelector('footer[data-fold-wrap]');
    return {
        rail:one('.bridge-rail'),railWords:one('.bridge-rail .bridge-rail-text'),railLink:one('.bridge-rail .bridge-rail-link'),
        railScrolls:(()=>{const rail=document.querySelector('.bridge-rail');return rail.scrollHeight>rail.clientHeight&&getComputedStyle(rail).overflowY==='auto';})(),
        top:one('.bridge-top'),feedback:one('[data-feedback]'),drawerTheme:one('[data-drawer-theme]'),tabbar:one('.bridge-tabbar'),
        notice:drawn(footer),noticeLink:drawn(footer.querySelector('a')),noticeWords:footer.innerText.replace(/\s+/g,' ').trim(),
        sideways:document.documentElement.scrollWidth-innerWidth,
    };
});

test('the shell views carry the hooks this file styles',()=>{
    assert.match(read(`${ADMIN_VIEWS}/sidebar/index.blade.php`),/class="bridge-rail bridge-phone-hide /);
    const header=read(`${ADMIN_VIEWS}/header/index.blade.php`);
    assert.match(header,/class="bridge-icon-btn bridge-phone-hide"/);
    assert.match(header,/class="mb-4 bridge-phone-only"/);
    assert.match(read(`${ADMIN_VIEWS}/page-header.blade.php`),/'class' => 'bridge-page-head /);
    const footer=read(NOTICE_VIEW);
    for(const hook of ['bridge-notice ','bridge-notice-text','bridge-notice-long','bridge-notice-short'])assert.ok(footer.includes(hook),`the notice view carries ${hook.trim()}`);
    // The Library's bulk-action bar, with files picked: fixed across the bottom of the screen under 640 px wide.
    const toolbar=read('packages/Mothership/DAM/src/Resources/views/components/explorer/toolbar.blade.php');
    assert.ok(toolbar.includes('data-explorer-selection-bar')&&toolbar.includes('max-[639px]:fixed max-[639px]:inset-x-0 max-[639px]:bottom-0'),'the bulk-action bar carries the hook and the classes the page here gives it');
    // No width utility decides the phone form in the views any more: the stylesheet's one test does.
    assert.doesNotMatch(read(`${ADMIN_VIEWS}/sidebar/index.blade.php`)+header,/max-\[719px\]:hidden|min-\[720px\]:hidden/);
});

browserTest('the rail is words from 1180 px wide and icons from 720 to 1179',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const wide=await readShell(await shell(browser,1180,800));
        assert.equal(wide.rail[2],224);
        assert.ok(wide.railWords,'the rail shows its words at 1180 px');
        // 951 x 585 and 951 x 543 are an iPhone Duo open and held sideways: the icon rail leaves 387 px before its fold.
        for(const [width,height] of [[1179,800],[1024,768],[951,585],[951,543],[720,900]]){
            const at=await readShell(await shell(browser,width,height));
            assert.equal(at.rail[2],72,`rail width at ${width} x ${height}`);
            assert.equal(at.railLink[2],52,`rail link width at ${width} x ${height}`);
            assert.equal(at.railWords,null,`no rail words at ${width} x ${height}`);
            assert.equal(at.tabbar,null,`no tab bar at ${width} x ${height}`);
            assert.equal(at.sideways,0);
        }
    }finally{await browser.close();}
});

browserTest('under 720 px wide the phone form shows, except on a short, wide screen, which keeps the icon rail',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        // Phones upright, the narrowest and widest phone forms, and a screen one pixel too tall or too narrow for the rail.
        for(const [width,height] of [[382,580],[390,844],[719,900],[719,501],[579,368]]){
            const at=await readShell(await shell(browser,width,height));
            assert.equal(at.rail,null,`no rail at ${width} x ${height}`);
            assert.equal(at.feedback,null,`no feedback button in the phone bar at ${width} x ${height}`);
            assert.ok(at.drawerTheme,`the drawer carries the theme switch at ${width} x ${height}`);
            assert.ok(at.tabbar,`tab bar at ${width} x ${height}`);
            assert.equal(at.tabbar[1]+at.tabbar[3],height,'the tab bar sits on the bottom edge');
            // The notice ends where the tab bar starts, so its link can be reached.
            assert.equal(at.notice[1]+at.notice[3],at.tabbar[1],`the notice sits on the tab bar at ${width} x ${height}`);
            assert.equal(at.sideways,0);
        }
        // 594 x 368 is an iPhone Duo closed and held sideways; 667 x 375 a phone on its side.
        for(const [width,height] of [[594,368],[667,375],[580,500],[719,500]]){
            const at=await readShell(await shell(browser,width,height));
            assert.equal(at.rail[2],72,`icon rail at ${width} x ${height}`);
            assert.equal(at.tabbar,null,`no tab bar at ${width} x ${height}`);
            assert.equal(at.drawerTheme,null);
            assert.ok(at.feedback,`the wide top bar keeps its feedback button at ${width} x ${height}`);
            assert.equal(at.top[3],60,`top bar height at ${width} x ${height}`);
            assert.equal(at.notice[1]+at.notice[3],height,'the notice sits on the bottom edge');
            assert.equal(at.sideways,0);
        }
        // Twelve rows do not fit 368 px: the rail scrolls as one piece, foot included, so every row can be reached.
        const short=await shell(browser,594,368);
        assert.equal((await readShell(short)).railScrolls,true);
        await short.locator('.bridge-rail-foot .bridge-rail-link').last().scrollIntoViewIfNeeded();
        const last=await short.locator('.bridge-rail-foot .bridge-rail-link').last().boundingBox();
        assert.ok(last.y>=0&&last.y+last.height<=368,'the last rail row scrolls into the screen');
        // With files picked the Library's bulk-action bar takes its fixed classes. Beside the rail it starts where the
        // rail ends and the notice it would cut in two steps aside; on a phone it runs edge to edge over the notice.
        const picked=target=>target.evaluate(()=>{
            const bar=document.querySelector('[data-explorer-selection-bar]');
            bar.className='max-[639px]:fixed max-[639px]:inset-x-0 max-[639px]:bottom-0';
            return [getComputedStyle(bar).position,Math.round(bar.getBoundingClientRect().left),getComputedStyle(document.querySelector('footer[data-fold-wrap]')).visibility];
        });
        assert.deepEqual(await picked(short),['fixed',72,'hidden']);
        assert.deepEqual(await picked(await shell(browser,390,844)),['fixed',0,'visible']);
    }finally{await browser.close();}
});

browserTest('the analytics notice is one line at every width and its link stays on the screen',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const oneLine=(await readShell(await shell(browser,1440,900))).notice[3];
        for(const [width,height] of [[375,667],[382,580],[385,571],[390,844],[594,368],[669,867],[719,900],[720,900],[951,585]]){
            const at=await readShell(await shell(browser,width,height));
            assert.equal(at.notice[3],oneLine,`notice height at ${width} x ${height}`);
            assert.ok(at.noticeLink[0]>=0&&at.noticeLink[0]+at.noticeLink[2]<=width,`the notice's link is inside ${width} px`);
            // One sentence and one link are shown, never both forms: the short ones under 720 px.
            assert.equal(at.noticeWords,width<720?`${notice.textShort} ${notice.linkShort}`:`${notice.text} ${notice.link}`);
        }
    }finally{await browser.close();}
});

browserTest('a list takes its row form from 620 px of its own width, and a short screen gives its rows the first screen',async()=>{
    const browser=await launchBrowser({playwrightPath});
    try{
        const page=await shell(browser,1440,900);
        const areas=width=>page.evaluate(listWidth=>{
            document.querySelector('[data-list-width]').style.width=`${listWidth}px`;
            return getComputedStyle(document.querySelector('.bridge-list-row')).gridTemplateAreas;
        },width);
        // 637 px is the list on the inner screen of an iPhone Duo held upright.
        assert.match(await areas(637),/"main status actions"/);
        assert.match(await areas(620),/"main status actions"/);
        assert.doesNotMatch(await areas(619),/"main status actions"/);

        const strip=target=>target.evaluate(()=>{
            const box=selector=>{const node=document.querySelector(selector);return getComputedStyle(node).display==='none'?null:node.getBoundingClientRect();};
            return {
                group:box('.bridge-page-group')?.height??0,description:box('[data-description]')?.height??0,
                wordsWidth:Math.round(box('[data-select-all-words]').width),wordsInPage:document.querySelector('[data-select-all-words]').textContent,
                bar:Math.round(box('.bridge-list-bar').height),firstRow:Math.round(box('.bridge-list-row').top),
            };
        });
        const tall=await strip(await shell(browser,594,900));
        assert.ok(tall.group>0&&tall.description>0,'a tall screen keeps the group name and the description');
        assert.ok(tall.wordsWidth>100,'a tall screen spells out "Select all on this page"');

        const short=await strip(await shell(browser,594,368));
        assert.equal(short.group,0);
        assert.equal(short.description,0);
        // The words leave the line, not the page: a screen reader still names the tick box.
        assert.equal(short.wordsWidth,1);
        assert.equal(short.wordsInPage,'Select all on this page');
        assert.ok(short.bar<=60,`the selection strip is one line (${short.bar} px)`);
        assert.ok(short.firstRow<tall.firstRow,'the first row starts higher on the short screen');
    }finally{await browser.close();}
});
