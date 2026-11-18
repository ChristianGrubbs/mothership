// The asset detail page's Preview tab (packages/Mothership/DAM/src/Resources/views/asset/edit.blade.php, preview-modal/
// card.blade.php, share/components/zoomable-image.blade.php) once rendered the image wider than the phone content
// area: the card is a flex item that could not shrink below its content, the zoom toolbar (about 346px) set that
// minimum, and at 375px the card ran 15px past the 332px content box, so the picture and the Next arrow clipped at
// the viewport edge (QA, 2026-10-08). This renders the real card, row, header, viewer and toolbar classes from the
// Blade source against the committed built stylesheets and measures the boxes. On desktop the frame height is set by
// the real measuring script (preview-modal/frame-fit-script.blade.php), which the fixture extracts and runs.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');
const VIEWS='packages/Mothership/DAM/src/Resources/views';
const EDIT=`${VIEWS}/asset/edit.blade.php`;
const CARD=`${VIEWS}/asset/preview-modal/card.blade.php`;
const LAYOUT='packages/Mothership/Admin/src/Resources/views/components/layouts/index.blade.php';
const MODAL=`${VIEWS}/asset/preview-modal.blade.php`;
const FIT=`${VIEWS}/asset/preview-modal/frame-fit-script.blade.php`;
const STATUS=`${VIEWS}/asset/preview-modal/pano360/status.blade.php`;
const ZOOM=`${VIEWS}/share/components/zoomable-image.blade.php`;
const OPTIMIZATION=`${VIEWS}/asset/preview-modal/optimization.blade.php`;
const PHONE_BELOW=525; // the theme's `sm` breakpoint: the phone rules are `max-sm:`

// The class string of one preview frame (data-preview-frame="<kind>") from the Blade source, with the shared
// {{ $previewFrame }} variable resolved to the string assigned at the top of the file.
const FRAME_KINDS=['web','pano360','image','video'];
function frameClass(kind){
    const text=source(CARD);
    const shared=text.match(/\$previewFrame\s*=\s*'([^']+)'/);
    assert.ok(shared,'the shared $previewFrame class string must still be assigned in card.blade.php');
    const m=text.match(new RegExp(`<(?:div|button)\\b[^>]*class="([^"]*)"[^>]*data-preview-frame="${kind}"`));
    assert.ok(m,`the ${kind} frame must still carry data-preview-frame="${kind}" in card.blade.php`);
    assert.ok(!/style="[^"]*height/.test(m[0]),`the ${kind} frame must not set an inline height`);

    return m[1].replace('{{ $previewFrame }}',shared[1]).replace(/\s+/g,' ').trim();
}

function realMarkup(){
    const grab=(file,re,what)=>{const m=source(file).match(re);assert.ok(m,`${what} must still be found in ${file}`);return (m[1]||'').replace(/\s+/g,' ').trim();};

    return {
        body:grab(LAYOUT,/<body class="([^"]*)"/,'the admin body'),
        app:grab(LAYOUT,/<div id="app" class="([^"]*)"/,'the admin app shell'),
        mainContent:grab(LAYOUT,/<main id="main-content" class="([^"]*)"/,'#main-content'),
        modalRoot:grab(MODAL,/<div class="(flex min-w-0 max-w-full flex-col items-center gap-2 w-full)">/,'the preview component root'),
        row:grab(EDIT,/<div class="(flex min-w-0 max-w-full gap-2\.5 mt-3\.5 max-\[639px\]:mt-0 w-full flex-wrap)">\s*\n\s*<div class="flex flex-col flex-1/,'the preview row'),
        card:grab(EDIT,/<div class="(flex flex-col flex-1[^"]*)">\s*<div class="relative flex flex-1 min-w-0 max-w-full min-h-0">/,'the preview card'),
        group:grab(EDIT,/<div class="(group relative flex flex-col flex-1[^"]*)">/,'the preview group'),
        side:grab(EDIT,/<div class="(flex min-w-0 flex-col gap-5 w-\[360px\][^"]*)">/,'the details panel'),
        frame:frameClass('image'),
        cardRoot:grab(CARD,/<div class="(relative min-w-0 max-w-full w-full[^"]*)" data-dam-preview-card>/,'the preview card root'),
        header:grab(CARD,/<div class="(flex flex-wrap justify-between items-center gap-2 w-full[^"]*)">/,'the card header'),
        ctl:grab(CARD,/class="(flex items-center justify-center w-10 h-10 rounded-lg text-xl[^"]*)"/,'a header icon control'),
        // Identify the actual status component, independently of its visibility predicate.
        pano:grab(STATUS,/<div\b(?=[^>]*\bdata-dam-panorama-status=)[^>]*\bclass="([^"]*)"/,'the panorama status strip'),
        optimize:grab(OPTIMIZATION,/data-dam-optimize class="([^"]*)"/,'the Optimize button'),
        note:grab(OPTIMIZATION,/class="([^"]*)" data-dam-optimize-unavailable>/,'the Optimization unavailable note'),
        noteText:grab(OPTIMIZATION,/data-dam-optimize-unavailable>([^<]+)</,'the Optimization unavailable text'),
        optimizeText:grab(OPTIMIZATION,/data-dam-optimize class="[^"]*"[^>]*>([^<]+)</,'the Optimize label'),
        info:grab(CARD,/class="([^"]*)"[^>]*data-dam-info-trigger/,'the info control'),
        editImage:grab(CARD,/class="([^"]*)"[^>]*@click="editTarget = 'original'/,'the edit image control'),
        preview:grab(CARD,/class="([^"]*)"[^>]*data-dam-preview-open/,'the preview control'),
        actions:grab(CARD,/<div class="(flex min-w-0 items-center justify-end gap-1[^"]*)"[^>]*>/,'the header action group'),
        viewer:grab(ZOOM,/<div\s+class="(flex flex-col w-full h-full overflow-hidden[^"]*)"/,'the viewer root'),
        stage:grab(ZOOM,/<div\s+class="(relative flex-1 min-h-0 overflow-hidden[^"]*)"/,'the viewer stage'),
        img:grab(ZOOM,/class="(max-w-none max-h-none block)"/,'the viewer image'),
        toolbar:grab(ZOOM,/<div class="(flex [^"]*items-center justify-center gap-1 px-3 py-2 border-t[^"]*)">/,'the zoom toolbar'),
        sep:grab(ZOOM,/<span class="(w-px h-4 [^"]*)"><\/span>/,'a toolbar separator'),
        label:grab(ZOOM,/<span class="(min-w-\[44px\][^"]*)">/,'the zoom percentage'),
        button:grab(ZOOM,/class="(flex items-center justify-center w-8 h-8 rounded[^"]*)"/,'a toolbar button'),
        arrow:grab(EDIT,/class="(absolute right-2 top-1\/2[^"]*)"/,'the Next arrow'),
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
    const launcher=process.env.MOTHERSHIP_CHROME_LAUNCHER;
    const browser=launcher
        ? await (await import(launcher)).launchChrome({chromium,playwrightVersion:require(path.join(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH,'package.json')).version})
        : await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try {await run(await browser.newPage());} finally {await browser.close();}
}

// Page chrome above the preview card. Normal: a 60px sticky admin header plus a 196px spacer standing in for the page
// title, tabs and row margins, calibrated so the stage top lands at y320 at 1440x900 (QA's measurement). Wrapped: the
// same plus a title that wraps to several lines, a tab strip that wraps to a second row, and long breadcrumbs that wrap
// the card header, and the panorama status strip above the frame. Those are real wrapping elements, but their text and
// widths are invented, not the real page's.
const CHROME_ABOVE=196;
const LONG_CRUMBS=Array.from({length:14},(_,i)=>`Directory-${i}`).join(' / ');
const wrappedTitle=()=>`<div style="padding:8px 0"><h1 style="font-size:20px;margin:0;max-width:420px">${'A very long asset title that wraps '.repeat(2)}</h1></div><div style="display:flex;flex-wrap:wrap;gap:8px;max-width:520px">${Array.from({length:12},(_,i)=>`<span style="padding:6px 12px">Tab ${i}</span>`).join('')}</div>`;
const chromeTop=wrapped=>`<div data-sticky style="position:sticky;top:0;height:60px;z-index:30;background:#fff;margin:0 -16px"></div><div style="height:${CHROME_ABOVE}px"></div>${wrapped?wrappedTitle():''}`;
// The real admin shell (layouts/index.blade.php): body h-screen overflow-hidden, #app a flex column of header and a
// min-h-0 row, and #main-content the flex-1 overflow-y-auto scroller that holds the page. The header is a flex item
// (data-sticky stands in for it; it never scrolls), not an overlay.
function pageHtml(m,{width,dark,dir,chrome,wrapped},inner){
    const navWidth=width>=1024?270:0;
    const nav=`<nav style="width:${navWidth}px;flex-shrink:0"></nav>`;
    const html=`<html dir="${dir}" class="${dark?'dark':''}"><head></head>`;
    if(chrome==='window'){return `<!doctype html>${html}<body style="margin:0" class="${dark?'bg-cherry-800':''}"><div style="display:flex">${nav}<main style="flex:1;min-width:0;padding:0 16px">${chromeTop(wrapped)}${inner}</main></div></body></html>`;}
    if(chrome){return `<!doctype html>${html}<body style="margin:0" class="${m.body}"><div id="app" class="${m.app}"><div data-sticky style="height:60px;flex:none;background:#fff"></div><div class="flex flex-1 min-h-0">${nav}<main id="main-content" class="${m.mainContent}"><div style="height:${CHROME_ABOVE-12}px"></div>${wrapped?wrappedTitle():''}${inner}</main></div></div></body></html>`;}

    return `<!doctype html>${html}<body style="margin:0" class="${dark?'bg-cherry-800':''}"><div style="display:flex">${nav}<main style="flex:1;min-width:0;padding:0 16px">${inner}</main></div></body></html>`;
}
const fitScript=()=>{const m=source(FIT).match(/<script>([\s\S]*?)<\/script>/);assert.ok(m,`the fit script must still be a plain <script> in ${FIT}`);return m[1];};
const settle=page=>page.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(()=>requestAnimationFrame(r)))));
// The stylesheet is added after the markup, so #main-content's transition-all would animate from unstyled to styled; the
// real page loads CSS first. Let those transitions finish before measuring.
const settleStyles=page=>page.evaluate(()=>Promise.all(document.getAnimations().map(a=>a.finished.catch(()=>{}))));
async function startFit(page,selector){
    await settleStyles(page);
    await page.evaluate(([code,sel])=>{(0,eval)(code);window.__fit=window._damPreviewFrameFit.start(document.querySelector(sel));},[fitScript(),selector]);
    await settle(page);
}
const svgImage=(width,height)=>`data:image/svg+xml;utf8,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="${width}" height="${height}"><rect width="${width}" height="${height}" fill="#2a9d5a"/></svg>`)}`;
const IMAGE=svgImage(1356,1356);

// The content area pads 16px each side; from lg the 270px main nav takes room too (below lg it is a drawer).
// longNote: the Optimize button and its "Optimization unavailable" note as a server without optimization shows them (the
// component's root is display:contents, so both sit directly in the header action group).
async function render(page,width,{dark=false,dir='ltr',height=812,chrome=false,wrapped=false,longNote=false,image=IMAGE}={}){
    const m=realMarkup();
    const icon=`<button type="button" class="${m.button}" data-act><svg class="w-4 h-4" viewBox="0 0 24 24"></svg></button>`;
    const tool=`<button type="button" class="${m.button}" data-tool><svg class="w-4 h-4" viewBox="0 0 24 24"></svg></button>`;
    const headerIcon=(cls,attrs='')=>`<button type="button" class="${cls}"${attrs} data-act><svg class="w-4 h-4" viewBox="0 0 24 24"></svg></button>`;
    const sep=`<span class="${m.sep}"></span>`;
    // Rotate left and right, zoom out, the percentage, zoom in, fit, 1:1 and reset, as the Blade source orders them.
    const tools=[tool,tool,sep,tool,`<span class="${m.label}">100%</span>`,tool,sep,tool,tool,tool].join('');
    await page.setViewportSize({width,height});
    await page.setContent(pageHtml(m,{width,dark,dir,chrome,wrapped},`<div class="${m.row}" data-row>
            <div class="${m.card}" data-card><div class="relative flex flex-1 min-w-0 max-w-full min-h-0"><div class="${m.group}">
                <button type="button" class="${m.arrow}" data-next></button>
                <div class="${m.modalRoot}" data-root><div class="${m.cardRoot}" data-dam-preview-card>
                    <div class="${m.header}" data-header><nav class="flex items-center gap-1 flex-wrap text-sm min-w-0" data-crumbs>${wrapped?LONG_CRUMBS:'Root / wide'}</nav>
                        <div class="${m.actions}" data-actions><button type="button" class="secondary-button" data-act>Export…</button><button type="button" class="secondary-button" data-act>Convert</button>${longNote?`<div class="contents"><div class="relative"><button type="button" class="${m.optimize}" data-dam-optimize data-act>${m.optimizeText}</button></div><span class="${m.note}" data-dam-optimize-unavailable data-note>${m.noteText}</span></div>`:'<button type="button" class="secondary-button" data-act>Optimize</button>'}${headerIcon(m.ctl)}${headerIcon(m.info,' data-dam-info-trigger')}${headerIcon(m.editImage)}${headerIcon(m.preview,' data-dam-preview-open')}</div></div>
                    ${wrapped?`<div class="${m.pano}" data-pano><span>360 panorama</span><button type="button" class="secondary-button">Build</button></div>`:''}
                    <div class="${m.frame}" data-frame data-preview-frame="image"><div class="${m.viewer}"><div class="${m.stage}"><img class="${m.img}" data-img src="${image}" style="max-height:100%;max-width:100%"></div><div class="${m.toolbar}" data-toolbar>${tools}</div></div></div>
                </div></div></div></div></div>
            <div class="${m.side}" data-side>Details</div>
        </div>`));
    for(const file of builtStylesheets()){
        await page.addStyleTag({path:file});
    }
    await page.waitForFunction(()=>document.querySelector('[data-img]').complete);
    await startFit(page,'[data-root]');
}

const geometry=page=>page.evaluate(()=>{
    const box=el=>{const r=el.getBoundingClientRect();return {top:Math.round(r.top),bottom:Math.round(r.bottom),left:Math.round(r.left),right:Math.round(r.right),width:Math.round(r.width),height:Math.round(r.height)};};
    const q=sel=>document.querySelector(sel);
    const all=sel=>[...document.querySelectorAll(sel)].map(box);

    return {
        row:box(q('[data-row]')),frame:box(q('[data-frame]')),card:box(q('[data-card]')),img:box(q('[data-img]')),side:box(q('[data-side]')),next:box(q('[data-next]')),toolbar:box(q('[data-toolbar]')),
        tools:all('[data-tool]'),header:box(q('[data-header]')),crumbs:box(q('[data-crumbs]')),actions:box(q('[data-actions]')),sticky:q('[data-sticky]')?box(q('[data-sticky]')):null,acts:all('[data-act]'),note:q('[data-note]')?box(q('[data-note]')):null,pageWidth:document.documentElement.scrollWidth,
    };
});

const inside=(inner,outer)=>inner.left>=outer.left-1&&inner.right<=outer.right+1;

test('at phone widths the preview image, arrow, toolbar and header actions stay inside the card, in light, dark and RTL',async()=>{
    await withPage(async page=>{
        for(const width of [375,414]){
            for(const [mode,opts] of [['light',{}],['dark',{dark:true}],['rtl',{dir:'rtl'}]]){
                const label=`${width}px ${mode}`;
                await render(page,width,opts);
                const g=await geometry(page);
                assert.ok(inside(g.card,g.row),`${label}: the card stays inside the row (${g.card.left}-${g.card.right} in ${g.row.left}-${g.row.right})`);
                assert.ok(inside(g.img,g.card),`${label}: the image fits the card (${g.img.left}-${g.img.right} in ${g.card.left}-${g.card.right})`);
                assert.ok(inside(g.next,g.card),`${label}: the Next arrow is inside the card`);
                assert.ok(inside(g.toolbar,g.card)&&g.tools.every(t=>inside(t,g.card)),`${label}: every toolbar control is inside the card`);
                assert.ok(g.acts.every(a=>inside(a,g.card)),`${label}: every header action is inside the card`);
                assert.ok(g.pageWidth<=width,`${label}: no horizontal page scroll (${g.pageWidth})`);
                assert.ok(g.side.top>=g.card.bottom-1,`${label}: the details panel sits below the preview`);
                // Path, then the preview, then the file's actions (operator, 2026-10-30).
                assert.ok(g.crumbs.bottom<=g.frame.top+1&&g.actions.top>=g.frame.bottom-1,`${label}: path (${g.crumbs.bottom}) above the preview (${g.frame.top}-${g.frame.bottom}), actions (${g.actions.top}) below it`);
            }
        }
    });
});

// A screen 480px tall or less (the iPhone Duo outer display, or a phone, on its side) gets the phone order at any width.
test('on a short screen the card shows path, preview, then its actions; taller, the actions sit above the preview again',async()=>{
    await withPage(async page=>{
        for(const [width,height,below] of [[594,368,true],[1024,480,true],[1024,481,false],[594,800,false]]){
            const label=`${width}x${height}`;
            await render(page,width,{height});
            const g=await geometry(page);
            assert.ok(g.acts.every(a=>inside(a,g.card)),`${label}: every header action is inside the card`);
            if(below) assert.ok(g.crumbs.bottom<=g.frame.top+1&&g.actions.top>=g.frame.bottom-1,`${label}: actions (${g.actions.top}) below the preview (${g.frame.bottom})`);
            else assert.ok(g.actions.bottom<=g.frame.top+1,`${label}: actions (${g.actions.bottom}) above the preview (${g.frame.top})`);
        }
    });
});

test('from the sm breakpoint the card keeps its pre-fix layout: full-width above the panel to 1024px, beside it at 1440px',async()=>{
    await withPage(async page=>{
        for(const width of [PHONE_BELOW+1,639,640,1024,1440]){
            for(const dark of [false,true]){
                const label=`${width}px${dark?' dark':''}`;
                await render(page,width,{dark});
                const g=await geometry(page);
                assert.ok(inside(g.img,g.card)&&inside(g.toolbar,g.card)&&inside(g.next,g.card),`${label}: image, toolbar and arrow are inside the card`);
                assert.ok(g.actions.bottom<=g.frame.top+1,`${label}: the header actions sit above the preview (${g.actions.bottom} vs ${g.frame.top})`);
                assert.ok(g.pageWidth<=width,`${label}: no horizontal page scroll (${g.pageWidth})`);
                if(width<1240){
                    assert.ok(g.side.top>=g.card.bottom-1,`${label}: the details panel wraps below the card (${g.side.top} < ${g.card.bottom})`);
                } else {
                    assert.ok(g.side.top<g.card.bottom&&g.card.width>=500,`${label}: the details panel sits beside a wide card (${g.card.width}px)`);
                }
            }
        }
    });
});

// A long status note once let the header actions run past the card; letting them wrap at every width then dropped the
// card's minimum width, so the details panel moved beside a ~350px card at 1024 (CI, 2026-10-13). 749 and 767 are the
// band just below md, where the actions wrap and only the card's full basis keeps the panel below it.
test('with the long Optimization unavailable note the header stays inside the card and the panel keeps its place',async()=>{
    await withPage(async page=>{
        for(const width of [375,749,767,768,1024,1440]){
            for(const dark of [false,true]){
                const label=`${width}px${dark?' dark':''} with the note`;
                await render(page,width,{dark,longNote:true});
                const g=await geometry(page);
                assert.ok(g.note&&g.note.width>0,`${label}: the note renders`);
                assert.ok(inside(g.note,g.card),`${label}: the note is inside the card (${g.note.left}-${g.note.right} in ${g.card.left}-${g.card.right})`);
                assert.ok(g.acts.every(a=>inside(a,g.card)),`${label}: every header action is inside the card`);
                assert.ok(inside(g.card,g.row),`${label}: the card stays inside the row`);
                assert.ok(g.pageWidth<=width,`${label}: no horizontal page scroll (${g.pageWidth})`);
                if(width<1240){
                    assert.ok(g.side.top>=g.card.bottom-1,`${label}: the details panel wraps below the card (${g.side.top} < ${g.card.bottom})`);
                } else {
                    assert.ok(g.side.top<g.card.bottom&&g.card.width>=500,`${label}: the details panel sits beside a wide card (${g.card.width}px)`);
                }
            }
        }
    });
});


const FLOOR=200; // frame-fit-script.blade.php: the absolute minimum frame height
const SHARE=0.55; // frame-fit-script.blade.php: the frame never gets less than this share of the scroller's visible height
const sharePx=page=>page.evaluate(share=>Math.round((document.querySelector('#main-content')?.clientHeight??window.innerHeight)*share),SHARE);

async function assertFits(page,g,vh,label){
    assert.ok(g.header.top>=g.sticky.bottom-1,`${label}: the card header (top ${g.header.top}) is not under the sticky header (bottom ${g.sticky.bottom})`);
    assert.ok(g.img.top>=g.sticky.bottom-1,`${label}: the image is not under the sticky header`);
    const share=await sharePx(page);
    if(g.frame.height===FLOOR||g.frame.height===share){
        // The floor and the share are the only cases where the page scrolls: the space left below the chrome is smaller.
        assert.ok(g.frame.top+g.frame.height>vh-20,`${label}: the ${g.frame.height}px minimum is used only when the space below the chrome is smaller (frame top ${g.frame.top}, viewport ${vh})`);
        return;
    }
    assert.ok(g.frame.bottom<=vh,`${label}: the frame (bottom ${g.frame.bottom}) is inside the ${vh}px viewport`);
    assert.ok(g.img.bottom<=vh&&g.toolbar.bottom<=vh,`${label}: the whole image (${g.img.bottom}) and zoom toolbar (${g.toolbar.bottom}) are inside the viewport`);
    assert.ok(g.header.bottom<=vh&&g.header.top>=0,`${label}: the card header is in the viewport`);
}

test('at desktop viewports the frame, card header, image and zoom toolbar fit on first load with normal and wrapped chrome',async()=>{
    await withPage(async page=>{
        for(const [width,height] of [[1440,900],[1280,800],[1920,1080],[1440,600]]){
            for(const wrapped of [false,true]){
                const label=`${width}x${height} ${wrapped?'wrapped chrome':'normal chrome'}`;
                await render(page,width,{height,chrome:true,wrapped});
                const g=await geometry(page);
                await assertFits(page,g,height,label);
                if(!wrapped&&height>=800){assert.ok(g.img.height>=300,`${label}: the image stays usefully large (${g.img.height}px)`);}
                if(height>=(wrapped?1080:800)){assert.notEqual(g.frame.height,FLOOR,`${label}: a normal desktop viewport never needs the floor`);}
            }
        }
    });
});

const inlineHeight=page=>page.evaluate(()=>parseFloat(document.querySelector('[data-frame]').style.height));
// Returns the replacement's inline height read in the same synchronous task as the swap, before any observer or rAF can run.
const replaceFrame=page=>page.evaluate(()=>{const f=document.querySelector('[data-frame]');const c=f.cloneNode(true);c.style.height='';f.replaceWith(c);return c.style.height;});

test('with the window as the scroller the frame follows chrome growth, a resize, a frame swap and keeps the scroll position',async()=>{
    await withPage(async page=>{
        await render(page,1440,{height:900,chrome:'window'});
        await page.evaluate(()=>{const t=document.createElement('div');t.style.height='1500px';document.querySelector('main').append(t);});
        await settle(page);
        const before=await geometry(page);
        assert.notEqual(before.frame.height,630,'the fitted height clearly differs from 70vh here');
        await page.evaluate(()=>window.scrollTo(0,70));
        const y=await page.evaluate(()=>window.scrollY);
        assert.equal(y,70,'the fixture really scrolls the window');
        await page.evaluate(()=>window.dispatchEvent(new Event('resize')));
        await settle(page);
        assert.equal(await page.evaluate(()=>window.scrollY),y,'a resize does not move the window scroll position');
        assert.ok(Math.abs((await geometry(page)).frame.height-before.frame.height)<=1,'the height is the same while the window is scrolled');
        // The replacement has no inline height; right after one settle (no other resize) the helper must have sized it.
        assert.equal(await replaceFrame(page),'','the replacement starts without an inline height');
        await settle(page);
        assert.ok(Math.abs((await inlineHeight(page))-before.frame.height)<=1,`the replaced frame is sized to the fitted height (${await inlineHeight(page)} vs ${before.frame.height}), not left at 70vh`);
        assert.equal(await page.evaluate(()=>window.scrollY),y,'a frame swap does not move the window scroll position');
        await page.evaluate(()=>window.scrollTo(0,0));
        const top=await geometry(page);
        assert.ok(Math.abs(top.frame.height-before.frame.height)<=1,'scrolling back leaves the height unchanged');

        await page.evaluate(()=>{const d=document.createElement('div');d.style.height='90px';document.querySelector('[data-row]').before(d);});
        await settle(page);
        const grown=await geometry(page);
        // 90px more chrome would leave 462px; the frame holds its 55% share of the 900px window instead, so the page scrolls.
        assert.ok(grown.frame.height<before.frame.height-50,`the frame shrinks when chrome above it grows (${before.frame.height} -> ${grown.frame.height})`);
        assert.equal(grown.frame.height,Math.round(900*SHARE),'the shrink stops at the 55% share of the visible height');
        await assertFits(page,grown,900,'after chrome growth');
        await page.setViewportSize({width:1440,height:700});
        await settle(page);
        await assertFits(page,await geometry(page),700,'after resize to 1440x700');
    });
});

// A tall portrait image fills the stage to the 70vh cap, so the frame is exactly the class height here.
test('on phones a frame that cannot be image-sized is exactly 70vh, with the horizontal fit intact',async()=>{
    await withPage(async page=>{
        for(const [width,height] of [[375,812],[375,400],[430,900]]){
            const label=`${width}x${height}`;
            await render(page,width,{height,chrome:true,wrapped:true,image:svgImage(900,1800)});
            const g=await geometry(page);
            assert.ok(Math.abs(g.frame.height-height*0.7)<=1,`${label}: the phone frame is 70vh (${g.frame.height} vs ${height*0.7})`);
            
            assert.ok(inside(g.card,g.row)&&inside(g.img,g.card)&&inside(g.toolbar,g.card)&&inside(g.next,g.card),`${label}: image, toolbar and arrow are inside the card`);
            assert.ok(g.acts.every(a=>inside(a,g.card)),`${label}: every header action is inside the card`);
            assert.ok(g.pageWidth<=width,`${label}: no horizontal page scroll (${g.pageWidth})`);
        }
    });
});

const stageBox=page=>page.evaluate(()=>{const r=document.querySelector('[data-img]').parentElement.getBoundingClientRect();return {width:r.width,height:r.height};});
const frameHeightNow=page=>page.evaluate(()=>document.querySelector('[data-frame]').getBoundingClientRect().height);
const setImage=async(page,src)=>{
    await page.evaluate(src=>new Promise(resolve=>{const img=document.querySelector('[data-img]');img.addEventListener('load',()=>resolve(),{once:true});img.src=src;}),src);
    await settle(page);
};

test('on phones an image frame is sized to the loaded image, capped at 70vh, and follows load, zoom and rotation',async()=>{
    await withPage(async page=>{
        const cap=812*0.7;
        await render(page,375,{height:812,chrome:true,image:svgImage(2400,1000)});
        const landscape=await geometry(page);
        const stage=await stageBox(page);
        assert.ok(landscape.frame.height<cap-50,`a 2400x1000 image frame is shorter than 70vh (${landscape.frame.height} vs ${cap})`);
        assert.ok(Math.abs(stage.height-stage.width*1000/2400)<=2,`the stage height matches the image aspect (${stage.height} vs ${stage.width*1000/2400})`);
        assert.ok(Math.abs(landscape.img.height-stage.height)<=2&&Math.abs(landscape.img.width-stage.width)<=2,`the image fills its stage (${landscape.img.width}x${landscape.img.height} in ${stage.width}x${stage.height})`);
        assert.ok(landscape.frame.height-stage.height>=landscape.toolbar.height-1,`the frame keeps the measured toolbar chrome (${landscape.frame.height} - ${stage.height} vs toolbar ${landscape.toolbar.height})`);

        // A new image loading in the same viewer re-fits (the load event does not bubble; the helper listens in the capture phase).
        await setImage(page,svgImage(900,1800));
        assert.ok(Math.abs(await frameHeightNow(page)-cap)<=1,`a 900x1800 image is capped at 70vh (${await frameHeightNow(page)} vs ${cap})`);
        await setImage(page,svgImage(1000,1000));
        const square=await stageBox(page);
        assert.ok(Math.abs(square.height-square.width)<=2,`a square image gets a square stage (${square.width}x${square.height})`);

        // Zoomed, panned or rotated: the viewer's transform is not identity, so the plain 70vh class applies and zoom is not clamped.
        for(const transform of ['translate(0px, 0px) scale(2) rotate(0deg)','translate(10px, 0px) scale(1) rotate(0deg)','translate(0px, 0px) scale(1) rotate(90deg)']){
            await page.evaluate(t=>{document.querySelector('[data-img]').style.transform=t;},transform);
            await settle(page);
            assert.ok(Math.abs(await frameHeightNow(page)-cap)<=1,`${transform}: the frame returns to 70vh (${await frameHeightNow(page)})`);
        }
        await page.evaluate(()=>{document.querySelector('[data-img]').style.transform='translate(0px, 0px) scale(1) rotate(0deg)';});
        await settle(page);
        const back=await stageBox(page);
        assert.ok(Math.abs(back.height-back.width)<=2,`back at rest the frame is image-sized again (${back.width}x${back.height})`);

        // A zoom-out lands on floating-point noise around 1: that is still the rest state; a visible pan or zoom is not.
        for(const [transform,rest] of [['translate(0.2px, 0px) scale(0.9999999) rotate(0deg)',true],['translate(0px, 0px) scale(1.0000001) rotate(0deg)',true],['translate(0px, 0px) scale(1.01) rotate(0deg)',false],['translate(1px, 0px) scale(1) rotate(0deg)',false]]){
            await page.evaluate(t=>{document.querySelector('[data-img]').style.transform=t;},transform);
            await settle(page);
            const near=await stageBox(page);
            assert.equal(Math.abs(await frameHeightNow(page)-cap)<=1,!rest,`${transform}: ${rest?'image-sized':'70vh'} (${await frameHeightNow(page)})`);
            if(rest)assert.ok(Math.abs(near.height-near.width)<=2,`${transform}: still image-sized (${near.width}x${near.height})`);
        }
        await page.evaluate(()=>{document.querySelector('[data-img]').style.transform='translate(0px, 0px) scale(1) rotate(0deg)';});
        await settle(page);

        // A resize re-fits: the stage width changes with the viewport.
        await page.setViewportSize({width:430,height:900});
        await settle(page);
        const wide=await stageBox(page);
        assert.ok(wide.width>back.width&&Math.abs(wide.height-wide.width)<=2,`a wider phone re-fits (${wide.width}x${wide.height})`);

        // After stop() the helper no longer reacts to an image load.
        const frozen=await frameHeightNow(page);
        await page.evaluate(()=>window.__fit.stop());
        await setImage(page,svgImage(2400,1000));
        assert.equal(await frameHeightNow(page),frozen,'a stopped helper ignores image loads');
    });
});

test('on phones web, pano360 and video frames keep 70vh, and so does an image that has not loaded',async()=>{
    await withPage(async page=>{
        for(const kind of ['web','pano360','video']){
            await page.setViewportSize({width:375,height:812});
            await page.setContent(pageHtml(realMarkup(),{width:375,dark:false,dir:'ltr',chrome:true,wrapped:false},`<div class="${realMarkup().modalRoot}" data-root><div class="${frameClass(kind)}" data-frame data-preview-frame="${kind}"><img data-img src="${svgImage(2400,1000)}"></div></div>`));
            for(const file of builtStylesheets()){await page.addStyleTag({path:file});}
            await page.waitForFunction(()=>document.querySelector('[data-img]').complete);
            await startFit(page,'[data-root]');
            assert.ok(Math.abs(await frameHeightNow(page)-812*0.7)<=1,`${kind}: the phone frame is 70vh even with a loaded image inside (${await frameHeightNow(page)})`);
        }
        await page.setContent(pageHtml(realMarkup(),{width:375,dark:false,dir:'ltr',chrome:true,wrapped:false},`<div class="${realMarkup().modalRoot}" data-root><div class="${frameClass('image')}" data-frame data-preview-frame="image"><div class="relative flex-1"><img data-img></div></div></div>`));
        for(const file of builtStylesheets()){await page.addStyleTag({path:file});}
        await startFit(page,'[data-root]');
        assert.ok(Math.abs(await frameHeightNow(page)-812*0.7)<=1,`an image frame without a loaded image is 70vh (${await frameHeightNow(page)})`);
    });
});

// A phone video frame follows the clip once its metadata loads: its aspect at the frame's width, at least 240px for the
// controls, at most 70vh (operator, 2026-10-30). Before that it keeps 70vh.
test('on phones a video frame is sized to the clip once its metadata loads, between 240px and 70vh',async()=>{
    await withPage(async page=>{
        for(const [videoWidth,videoHeight,expect] of [[1000,1000,'aspect'],[1920,1080,'floor'],[1080,1920,'cap']]){
            await page.setViewportSize({width:375,height:812});
            await page.setContent(pageHtml(realMarkup(),{width:375,dark:false,dir:'ltr',chrome:true,wrapped:false},`<div class="${realMarkup().modalRoot}" data-root><div class="${frameClass('video')}" data-frame data-preview-frame="video"><video data-video></video></div></div>`));
            for(const file of builtStylesheets()){await page.addStyleTag({path:file});}
            await startFit(page,'[data-root]');
            assert.ok(Math.abs(await frameHeightNow(page)-812*0.7)<=1,`${videoWidth}x${videoHeight}: 70vh before metadata (${await frameHeightNow(page)})`);
            const width=await page.evaluate(({videoWidth,videoHeight})=>{
                const video=document.querySelector('[data-video]');
                Object.defineProperty(video,'videoWidth',{get:()=>videoWidth});
                Object.defineProperty(video,'videoHeight',{get:()=>videoHeight});
                video.dispatchEvent(new Event('loadedmetadata'));
                return document.querySelector('[data-frame]').clientWidth;
            },{videoWidth,videoHeight});
            const wanted={aspect:Math.round(width*videoHeight/videoWidth),floor:240,cap:Math.round(812*0.7)}[expect];
            if(expect==='aspect') assert.ok(wanted>240&&wanted<812*0.7,`the square clip lands between the floor and the cap (${wanted})`);
            await page.waitForFunction(h=>Math.abs(document.querySelector('[data-frame]').getBoundingClientRect().height-h)<=1,wanted,{timeout:2000}).catch(()=>{});
            assert.ok(Math.abs(await frameHeightNow(page)-wanted)<=1,`${videoWidth}x${videoHeight}: ${expect} ${wanted} (${await frameHeightNow(page)})`);
        }
    });
});

// The tall chrome of a stack asset: the file strip above the card pushes the frame down. The 45% of the visible height
// that the chrome may use is the budget; past it the frame keeps its 55% share and the page scrolls by the difference.
const restingTop=page=>page.evaluate(()=>{const f=document.querySelector('[data-frame]'),m=document.querySelector('#main-content');return f.getBoundingClientRect().top-m.getBoundingClientRect().top-m.clientTop+m.scrollTop;});
// A strip inserted above the row is not an element the helper observes yet (it only knows the siblings present when it
// started), and the fixture's #main-content has a fixed height, so no ResizeObserver fires: dispatch the resize the real
// page gets from the browser, the same way the existing chrome-growth case does.
const addStrip=async(page,px)=>{await page.evaluate(h=>{const d=document.createElement('div');d.style.height=h+'px';document.querySelector('[data-row]').before(d);window.dispatchEvent(new Event('resize'));},px);await settle(page);};

test('on desktop the frame keeps 55% of the visible height when tall chrome sits above it, and is unchanged otherwise',async()=>{
    await withPage(async page=>{
        for(const [width,height] of [[1440,900],[1280,720],[1024,768]]){
            const label=`${width}x${height}`;
            await render(page,width,{height,chrome:true});
            const visible=await page.evaluate(()=>document.querySelector('#main-content').clientHeight);
            const plainTop=await restingTop(page);
            const old=Math.max(Math.round(visible-plainTop-20),FLOOR);
            const plain=await frameHeightNow(page);
            if(old>=Math.round(visible*SHARE)){
                assert.equal(plain,old,`${label}: with room to spare the frame is exactly the old fit (${plain} vs ${old})`);
            } else {
                assert.equal(plain,Math.round(visible*SHARE),`${label}: with little room the frame keeps its share (${plain})`);
            }

            // A 160px file strip: the chrome now takes more than 45% of the visible height at these sizes.
            await addStrip(page,160);
            const top=await restingTop(page);
            assert.ok(visible-top-20<Math.round(visible*SHARE),`${label}: the fixture chrome really leaves less than the share (${visible-top-20})`);
            assert.equal(await frameHeightNow(page),Math.round(visible*SHARE),`${label}: with a stack file strip the frame is round(visible * ${SHARE}) = ${Math.round(visible*SHARE)}`);
            assert.ok(await frameHeightNow(page)>=FLOOR,`${label}: never below the floor`);
        }
    });
});

test('on desktop the share rule only engages once the chrome leaves less than 55%: the strip height sweeps across the boundary',async()=>{
    await withPage(async page=>{
        await render(page,1440,{height:900,chrome:true});
        const visible=await page.evaluate(()=>document.querySelector('#main-content').clientHeight);
        const share=Math.round(visible*SHARE);
        const base=await restingTop(page);
        const roomy=Math.round(visible-base-20);
        assert.ok(roomy>share,'the plain 1440x900 layout leaves more than the share');
        assert.equal(await frameHeightNow(page),roomy,'plain 1440x900: the frame is the old fit');
        // Chrome that still leaves more than the share changes nothing but the old arithmetic.
        const spare=roomy-share;
        await addStrip(page,Math.floor(spare/2));
        assert.equal(await frameHeightNow(page),Math.round(visible-await restingTop(page)-20),'chrome below the 45% budget yields exactly visible - top - GAP');
        // Past the budget the share holds, and the frame's bottom runs below the visible area by the difference.
        await addStrip(page,spare);
        assert.equal(await frameHeightNow(page),share,'chrome past the 45% budget yields round(visible * 0.55)');
        const exposed=await page.evaluate(()=>{const f=document.querySelector('[data-frame]').getBoundingClientRect(),m=document.querySelector('#main-content');return {scrollable:m.scrollHeight>m.clientHeight,top:f.top};});
        assert.ok(exposed.scrollable,'the page scrolls by the difference');
    });
});

test('every preview frame (web, pano360, image, video) is sized to the viewport at 1440x900 and 1440x600, with wrapped chrome',async()=>{
    await withPage(async page=>{
        for(const kind of FRAME_KINDS){
            for(const height of [900,600]){
                await page.setViewportSize({width:1440,height});
                await page.setContent(pageHtml(realMarkup(),{width:1440,dark:false,dir:'ltr',chrome:true,wrapped:true},`<div class="${realMarkup().modalRoot}" data-root><div class="${frameClass(kind)}" data-frame data-preview-frame="${kind}"></div></div><div style="height:2000px"></div>`));
                for(const file of builtStylesheets()){await page.addStyleTag({path:file});}
                await startFit(page,'[data-root]');
                const r=await page.evaluate(()=>{const b=document.querySelector('[data-frame]').getBoundingClientRect();return {top:Math.round(b.top),bottom:Math.round(b.bottom),height:Math.round(b.height)};});
                if(r.height===FLOOR||r.height===Math.round(await page.evaluate(()=>document.querySelector('#main-content').clientHeight)*SHARE)){assert.ok(r.top+r.height>height-20,`${kind} ${height}: the minimum is used only when nothing more fits`);continue;}
                assert.ok(r.bottom<=height,`${kind} ${height}: the frame is fully inside the viewport (bottom ${r.bottom})`);
                assert.ok(r.height>=FLOOR,`${kind} ${height}: the frame is at least the floor`);
            }
        }
    });
});

test('in the real fixed-height admin shell the frame is sized from #main-content and never from a scroll position',async()=>{
    await withPage(async page=>{
        for(const [width,height,wrapped] of [[1440,900,false],[1440,900,true],[1280,800,false],[1440,600,false]]){
            const label=`${width}x${height} ${wrapped?'wrapped':'normal'} chrome in the shell`;
            await render(page,width,{height,chrome:true,wrapped});
            const rest=await geometry(page);
            await assertFits(page,rest,height,label);
            const main=await page.evaluate(()=>{const e=document.querySelector('#main-content');return {top:Math.round(e.getBoundingClientRect().top),bottom:Math.round(e.getBoundingClientRect().bottom),client:e.clientHeight,scrollable:e.scrollHeight>e.clientHeight};});
            assert.equal(await page.evaluate(()=>document.documentElement.scrollHeight<=window.innerHeight),true,`${label}: the document itself does not scroll`);
            if(rest.frame.height!==FLOOR&&rest.frame.height!==await sharePx(page)){assert.ok(rest.toolbar.bottom<=main.bottom,`${label}: the toolbar is inside #main-content (${rest.toolbar.bottom} <= ${main.bottom})`);}
            // Scroll the real scroller, resize, and replace the frame: the height must not move, the helper must not touch scrollTop.
            await page.evaluate(()=>{document.querySelector('#main-content').scrollTop=120;});
            const st=await page.evaluate(()=>document.querySelector('#main-content').scrollTop);
            const scrollTop=()=>page.evaluate(()=>document.querySelector('#main-content').scrollTop);
            await page.evaluate(()=>window.dispatchEvent(new Event('resize')));
            await settle(page);
            const scrolled=await geometry(page);
            assert.equal(await scrollTop(),st,`${label}: a resize leaves #main-content's scrollTop exactly alone`);
            assert.ok(st>0||rest.frame.height===FLOOR||rest.frame.height===await sharePx(page),`${label}: the fixture really scrolls #main-content (${st})`);
            assert.ok(Math.abs(scrolled.frame.height-rest.frame.height)<=1,`${label}: the height does not change while #main-content is scrolled`);
            assert.equal(await replaceFrame(page),'',`${label}: the replacement starts without an inline height`);
            await settle(page);
            assert.ok(Math.abs((await inlineHeight(page))-rest.frame.height)<=1,`${label}: the replaced frame is sized to the fitted height right after the swap (${await inlineHeight(page)} vs ${rest.frame.height})`);
            assert.equal(await scrollTop(),st,`${label}: a frame swap leaves scrollTop exactly alone`);
            await page.evaluate(()=>{document.querySelector('#main-content').scrollTop=0;});
            await settle(page);
            const back=await geometry(page);
            assert.ok(Math.abs(back.frame.height-rest.frame.height)<=1,`${label}: scrolling back leaves the height unchanged`);
            await assertFits(page,back,height,`${label} after scrolling back`);
        }
    });
});

// The production root wrapper, the real helper, and pages with observers/listeners instrumented before the script runs.
async function lifecyclePage(page,height=900){
    const m=realMarkup();
    await page.setViewportSize({width:1440,height});
    await page.addInitScript(()=>{
        const stats={observers:0,disconnected:0,resizeAdded:0,resizeRemoved:0,rafPending:new Set()};
        window.__stats=stats;
        for(const name of ['ResizeObserver','MutationObserver']){
            const Real=window[name];
            window[name]=class extends Real{constructor(cb){super(cb);stats.observers++;}disconnect(){stats.disconnected++;super.disconnect();}};
        }
        const add=window.addEventListener.bind(window),remove=window.removeEventListener.bind(window);
        window.addEventListener=(type,...rest)=>{if(type==='resize')stats.resizeAdded++;return add(type,...rest);};
        window.removeEventListener=(type,...rest)=>{if(type==='resize')stats.resizeRemoved++;return remove(type,...rest);};
    });
    await page.goto('data:text/html,<html></html>');
    const frame=kind=>`<div class="${frameClass(kind)}" data-frame data-preview-frame="${kind}" style=""></div>`;
    await page.setContent(pageHtml(m,{width:1440,dark:false,dir:'ltr',chrome:true,wrapped:false},`<div class="${m.modalRoot}" data-root="a">${frame('image')}</div><div class="${m.modalRoot}" data-root="b">${frame('video')}</div>`));
    for(const file of builtStylesheets()){await page.addStyleTag({path:file});}
    await settleStyles(page);
    await page.evaluate(code=>{(0,eval)(code);},fitScript());
}
const frameHeight=(page,root)=>page.evaluate(r=>{const f=document.querySelector(`[data-root="${r}"] [data-preview-frame]`);return f?Math.round(f.getBoundingClientRect().height):null;},root);

test('the helper survives a frame swap, two instances, and unmount with pending animation-frame work',async()=>{
    await withPage(async page=>{
        await lifecyclePage(page);
        const base=await page.evaluate(()=>({o:window.__stats.observers,a:window.__stats.resizeAdded}));
        await page.evaluate(()=>{window.__a=window._damPreviewFrameFit.start(document.querySelector('[data-root="a"]'));window.__b=window._damPreviewFrameFit.start(document.querySelector('[data-root="b"]'));});
        await settle(page);
        const a0=await frameHeight(page,'a'),b0=await frameHeight(page,'b');
        assert.ok(a0>=FLOOR&&b0>=FLOOR,`both instances size their frames (${a0}, ${b0})`);

        // A frame swap inside instance B: the replacement has no inline height, and one settle later it must hold the fitted
        // value (visible height - resting top - 20), which here is clearly not the 70vh class height.
        const startInline=await page.evaluate(()=>{const r=document.querySelector('[data-root="b"]');const old=r.querySelector('[data-preview-frame]');const n=old.cloneNode(true);n.style.height='';old.remove();r.append(n);return n.style.height;});
        assert.equal(startInline,'','the swapped-in frame starts without an inline height');
        await settle(page);
        const swapped=await page.evaluate(()=>{const f=document.querySelector('[data-root="b"] [data-preview-frame]');const m=document.querySelector('#main-content');
            const top=f.getBoundingClientRect().top-m.getBoundingClientRect().top-m.clientTop+m.scrollTop;
            return {inline:parseFloat(f.style.height),expected:Math.max(Math.round(m.clientHeight-top-20),200,Math.round(m.clientHeight*0.55)),seventyVh:window.innerHeight*0.7};});
        assert.ok(Math.abs(swapped.expected-swapped.seventyVh)>50,`the fitted height clearly differs from 70vh (${swapped.expected} vs ${swapped.seventyVh})`);
        assert.ok(Math.abs(swapped.inline-swapped.expected)<=1,`the swapped-in frame holds the fitted inline height (${swapped.inline} vs ${swapped.expected})`);

        // Unmount A with fit work pending: stop right after a resize, then detach A's root and resize again.
        const before=await page.evaluate(()=>{window.dispatchEvent(new Event('resize'));const h=document.querySelector('[data-root="a"] [data-preview-frame]').style.height;window.__a.stop();return h;});
        await page.evaluate(()=>{window.__aRoot=document.querySelector('[data-root="a"]');window.__aRoot.remove();});
        await page.setViewportSize({width:1440,height:700});
        await settle(page);
        const after=await page.evaluate(()=>window.__aRoot.querySelector('[data-preview-frame]').style.height);
        assert.equal(after,before,'nothing writes to the detached, stopped root');
        const stats=await page.evaluate(()=>{const {observers,disconnected,resizeAdded,resizeRemoved}=window.__stats;return {observers,disconnected,resizeAdded,resizeRemoved};});
        assert.equal(stats.resizeRemoved,1,'the stopped instance removed its resize listener');
        assert.ok(stats.disconnected>=2,`the stopped instance disconnected its observers (${stats.disconnected})`);
        // The surviving instance still sizes correctly after the resize.
        const b1=await frameHeight(page,'b');
        const top=await page.evaluate(()=>{const f=document.querySelector('[data-root="b"] [data-preview-frame]');const m=document.querySelector('#main-content');return f.getBoundingClientRect().top-m.getBoundingClientRect().top-m.clientTop+m.scrollTop;});
        const expected=Math.max(Math.round(await page.evaluate(()=>document.querySelector('#main-content').clientHeight)-top-20),FLOOR,await sharePx(page));
        assert.ok(Math.abs(b1-expected)<=1,`the surviving instance re-sizes for the 700px viewport (${b1} vs ${expected})`);

        await page.evaluate(()=>window.__b.stop());
        const end=await page.evaluate(()=>window.__stats);
        assert.equal(end.resizeAdded-base.a,end.resizeRemoved,'every resize listener the two instances added was removed');
        assert.equal(end.disconnected,end.observers-base.o,'every observer the two instances created was disconnected');
    });
});

test('a frame swap is sized by the MutationObserver alone, with no ResizeObserver and no resize event',async()=>{
    await withPage(async page=>{
        // ResizeObserver unavailable: the only thing that can notice the swap is the helper's MutationObserver.
        await page.addInitScript(()=>{window.ResizeObserver=undefined;});
        await page.goto('data:text/html,<html></html>');
        await render(page,1440,{height:900,chrome:true});
        assert.equal(await page.evaluate(()=>typeof window.ResizeObserver),'undefined','the fixture runs without ResizeObserver');
        const rest=await geometry(page);
        assert.ok(Math.abs(rest.frame.height-630)>50,`the fitted height clearly differs from 70vh (${rest.frame.height})`);
        assert.equal(await replaceFrame(page),'','the replacement starts without an inline height');
        await settle(page);
        assert.ok(Math.abs((await inlineHeight(page))-rest.frame.height)<=1,`the swapped-in frame is sized by the MutationObserver alone (${await inlineHeight(page)} vs ${rest.frame.height})`);
    });
});
