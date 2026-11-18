const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..'),dam=path.join(root,'packages/Mothership/DAM');
const views=path.join(dam,'src/Resources/views');
const playwrightPath=process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const {launchBrowser}=require('../launch-browser.cjs');
const browserTest=playwrightPath?test:test.skip;
// The Web Pages browser and its preview frame, mounted from their own Blade files against the CSS the
// Studio actually serves: the committed admin and DAM bundles. Nothing rebuilds those on deploy, so a
// utility class that exists only in a template silently does nothing (2026-09-23: the page cards
// collapsed to one full-width column, the preview rendered as an unstyled box in the page flow).
const builds={admin:path.join(root,'public/themes/admin/default/build'),dam:path.join(root,'public/themes/dam/build')};
const types={'.css':'text/css; charset=utf-8','.js':'text/javascript','.svg':'image/svg+xml','.woff':'font/woff','.woff2':'font/woff2','.otf':'font/otf'};
const sandbox=/SANDBOX = '([^']+)'/.exec(fs.readFileSync(path.join(dam,'src/Services/WebPagePolicy.php'),'utf8'))[1];
const blade=file=>fs.readFileSync(path.join(views,file),'utf8');
const templateOf=(source,id)=>new RegExp(`id="${id}">([\\s\\S]*?)</script>`).exec(source)[1];
const scriptOf=source=>source.slice(source.lastIndexOf('<script type="module">')+22).replace(/<\/script>[\s\S]*$/,'');
// Blade the browser never sees: comments dropped, escaped echoes unescaped, a translation becomes the
// last segment of its key, and the frame's server-side values become fixed test values.
const unblade=source=>source.replace(/\{\{--[\s\S]*?--\}\}/g,'')
    .replace(/@lang\('([^']+)'\)/g,(_,key)=>key.split('.').pop())
    .replace(/\{\{ \\Mothership\\DAM\\Services\\WebPagePolicy::SANDBOX \}\}/g,sandbox)
    .replace(/\{\{ url\(config\('app\.admin_url'\)\.'(\/dam\/[a-z]+)'\) \}\}/g,'/admin$1')
    .replace(/@json\(csrf_token\(\)\)/g,'"csrf-token-stub"')
    .replace(/@json\(\$frameMessages\)/g,JSON.stringify({preparing:'Preparing…','bundle-failed':'Bundle failed','member-preparing':'Preparing this file…','member-failed':'This file could not be prepared as a page.','member-slow':'This file is taking longer than usual to prepare. It may still finish: use Reload to check again.','member-stale':'This file changed since it was opened.','member-unavailable':'This file is no longer part of this page.','member-not-shown':'This file could not be shown here. It may have changed or is no longer available.','member-checking':'Checking this file…','member-check-failed':'This file could not be shown, and whether it changed could not be checked.','member-unsupported':'This file is not a web page, so it has no page preview.'}))
    .replace(/@\{\{/g,'{{');
const browserSource=blade('pages/browser-script.blade.php'),frameSource=blade('asset/preview-modal/web/frame-script.blade.php');
const markup={browser:unblade(templateOf(browserSource,'v-dam-web-pages-browser-template')),frame:unblade(templateOf(frameSource,'v-dam-web-page-frame-template'))};
const scripts=[unblade(scriptOf(frameSource)),unblade(scriptOf(browserSource))];
// Every class a Web Pages template can put on an element: static class attributes and the keys of
// :class objects (the only :class form these templates use).
function templateClasses(template){
    const classes=new Set();
    for(const [,value] of template.matchAll(/(?:^|\s)class="([^"]*)"/g))value.split(/\s+/).filter(Boolean).forEach(name=>classes.add(name));
    for(const [,object] of template.matchAll(/:class="\{([^"]*)\}"/g))for(const [,keys] of object.matchAll(/'([^']+)'\s*:/g))keys.split(/\s+/).filter(Boolean).forEach(name=>classes.add(name));
    return [...classes].sort();
}
const page=(id,extra={})=>({id,display_name:`Page ${id}`,file_name:`page-${id}.zip`,can_delete:id!==2,updated_at:null,creator:'Example',versions_count:1,active_share_token:null,collection_id:3,collection_path:null,library_directory:'Web Pages',state:'ready',error:null,thumbnail_url:'/thumb.svg',...extra});
const props={
    initial:{collection:3,breadcrumb:[{id:null,name:'Web Pages'},{id:3,name:'CIL'}],children:[{id:9,name:'Projects',page_count:2,children_count:0,can_modify:true,can_delete:true},{id:10,name:'Shared',page_count:1,children_count:0,can_modify:false,can_delete:false}],pages:{data:[1,2,3,4,5,6,7,8].map(id=>page(id)),current_page:1,last_page:1,total:8},search_mode:false,filters:{owner:'everyone',q:''}},
    routes:{listing:'/admin/dam/pages/listing',store:'/admin/dam/pages/collections',update:'/admin/dam/pages/collections',destroy:'/admin/dam/pages/collections',move:'/admin/dam/pages/move',show:'/admin/dam/pages',grant:'/admin/dam/pages',download:'/admin/dam/assets/download',upload:'/admin/dam/assets/upload',sharedLinksStore:'/admin/dam/shared-links',customThumbnail:'/admin/dam/assets'},
    can:{organize:true,share:true,upload:true,download:true},
    labels:{title:'Web Pages',search:'Search titles','owner-me':'Mine','owner-everyone':'Everyone',shared:'Shared',preparing:'Preparing…','bundle-failed':'Bundle failed','disk-unsupported':'Local disk only','open-full-screen':'Open full screen','copy-share-link':'Copy share link','download-original':'Download original',versions:':count versions',empty:'No web pages yet.',browser:{'new-folder':'New folder',open:'Open','folder-actions-for':'Page group actions for :name','upload-here':'Upload here',select:'Select',cancel:'Cancel',save:'Save','move-to':'Move to…',unfiled:'Unfiled',clear:'Clear',rename:'Rename',delete:'Delete','delete-confirm':':children subfolders and :pages pages move to :target','empty-root':'No web pages yet.','empty-folder':'This folder is empty.','upload-target':'Upload to',upload:'Upload','member-position':'File :position of :count','member-prev':'Previous file','member-next':'Next file','member-select':'Choose a file','members-loading':'Loading files…','members-failed':'The file list could not be loaded, so only the main page is shown.'},'member-stale':'This file changed since it was opened.','member-show-current':'Show current file','member-check-again':'Check again'},
    notice:null,
    uploadDirectories:[{id:1,name:'Web Pages'}],
};
props.routes.trashPage='/admin/dam/pages';
props.routes.shareCandidates='/admin/dam/shared-links/web-collections';
// The real English strings of Share pages in folder (en_US), so the captures show what people read.
Object.assign(props.labels.browser,{close:'Close','share-folder':'Share pages in folder','share-folder-title':'Share pages in folder','share-folder-loading':'Checking which pages can be shared…','share-folder-failed':'The pages in this folder could not be checked. Nothing was shared.','share-folder-empty':'This folder has no pages you can share. Nothing was shared.','share-folder-confirm':'Share :count page|Share :count pages','share-folder-explain':'Each new link is for one page and its existing stack files, and anyone with it can open them without signing in, for 30 days. Only the pages listed here are shared: pages in sub-folders and pages added later are not included, and no link covers the folder itself.','share-folder-keep':':count of these already have a link you can use. That link is kept with its own expiry and options, and no new one is made.','share-folder-omitted':':omitted more pages in this folder come later by name and are not included in this batch. To share one, use Copy share link on its own page.','share-folder-create':'Create public links','share-folder-progress':'Sharing :done of :total…','share-folder-stop':'Stop','share-folder-stopped':'The run stopped before every page was shared. Pages not yet reached were not shared.','share-folder-summary':':created new, :kept kept, :failed not shared','share-folder-unknown':':created new, :kept kept, :failed not shared, :unknown may exist','share-folder-created':'Link created','share-folder-kept':'Existing link kept (:expiry)','share-folder-expires':'expires :date','share-folder-no-expiry':'no expiry','share-folder-password':'password protected','share-folder-preview':'downloads off','share-folder-creator':'closes if its creator loses access','share-folder-waiting':'Waiting','share-folder-working':'Sharing…','share-folder-more':'More pages below: scroll the list','share-folder-unknown':':created new, :kept kept, :failed not shared, :unknown may exist','share-folder-changed':'This page changed or moved since the list was prepared, so it was not shared.','share-folder-denied':'You can no longer share this page.','share-folder-row-failed':'Could not create the link.','share-folder-uncertain':'No answer came back, so this link may exist. Check Shared links before trying again.','share-folder-skipped':'Not attempted','share-folder-copy-links':'Copy links','share-folder-copied':'Links copied','share-folder-links':'Links to copy'});
Object.assign(props.labels,{'move-to-trash':'Move to Trash','trash-page-confirm':'Move ":name" to Trash? You can restore it from Trash for 30 days.','trash-page-failed':'Could not move to Trash','page-trashed':'Page moved to Trash.'});
// Share pages in folder: the candidate answer and the per-page link answers, plus every request the page made.
const shareFixture={candidates:null,candidatesStatus:200,answers:[],posts:[],candidateGets:0,gate:null,lateAnswers:0};
const sharePages=(ids,extra={})=>ids.map(id=>({id,display_name:`Launch page ${id}`,revision:`${String(id).padStart(2,'0')}`.repeat(32),reuse:null,...(extra[id]||{})}));
const resetShare=(pages,overrides={})=>{Object.assign(shareFixture,{candidates:{success:true,collection:9,cap:100,total:pages.length,omitted:0,pages},candidatesStatus:200,answers:[],posts:[],candidateGets:0,gate:null,lateAnswers:0},overrides);};
const memberFixture={rows:{},answers:{},grants:[],delays:{},membersDelay:0,membersStatus:200,membersCalls:0};
async function withPage(fn,viewport={width:1440,height:900},contextOptions={}){
    const browser=await launchBrowser({playwrightPath});
    try{
        const context=await browser.newContext({viewport,...contextOptions});
        const tab=await context.newPage();
        tab.problems=[];
        tab.on('pageerror',error=>tab.problems.push(error.message));
        tab.writes=[];
        tab.on('request',request=>{if(request.method()!=='GET')tab.writes.push(`${request.method()} ${new URL(request.url()).pathname}`);});
        tab.on('console',message=>{if(/was accessed during render but is not defined|Unhandled error|Failed to resolve component/.test(message.text()))tab.problems.push(message.text());});
        const stylesheets=Object.entries(builds).map(([name,build])=>`/${name}/${JSON.parse(fs.readFileSync(path.join(build,'manifest.json'),'utf8'))['src/Resources/assets/css/app.css'].file}`);
        await context.route('http://pages.test/**',async route=>{
            const pathname=new URL(route.request().url()).pathname;
            if(pathname==='/')return route.fulfill({contentType:'text/html',body:`<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1">${stylesheets.map(href=>`<link rel="stylesheet" href="${href}">`).join('')}<div id="app"></div>`});
            // The real grid placeholder a thumbnail-less page shows: 301x206, so a box that follows the
            // image's own ratio instead of 16:9 is measurable.
            if(pathname==='/thumb.svg')return route.fulfill({contentType:'image/svg+xml',body:fs.readFileSync(path.join(dam,'src/Resources/assets/images/grid/unspecified.svg'))});
            // A stack's files (the members route), and a grant that names one of them. `memberFixture` is the case's own stub.
            const members=/^\/admin\/dam\/pages\/(\d+)\/members$/.exec(pathname);
            if(members){
                memberFixture.membersCalls++;
                if(memberFixture.membersDelay)await new Promise(resolve=>setTimeout(resolve,memberFixture.membersDelay));
                if(memberFixture.membersStatus!==200)return route.fulfill({status:memberFixture.membersStatus,contentType:'application/json',body:'{"message":"Server Error"}'});
                return route.fulfill({contentType:'application/json',body:JSON.stringify({members:memberFixture.rows[members[1]]||[]})});
            }
            if(/^\/admin\/dam\/pages\/\d+\/grant$/.test(pathname)){
                const file=new URL(route.request().url()).searchParams.get('file');
                memberFixture.grants.push(file);
                // A slow answer, to prove a late one for a file the viewer already left is dropped.
                if(file&&memberFixture.delays[file])await new Promise(resolve=>setTimeout(resolve,memberFixture.delays[file]));
                if(file&&memberFixture.answers[file])return route.fulfill({status:memberFixture.answers[file].status,contentType:'application/json',body:JSON.stringify(memberFixture.answers[file].body)});
                return route.fulfill({contentType:'application/json',body:JSON.stringify({url:file?`http://pages.test/frame/member-${file}/`:'http://pages.test/frame/'})});
            }
            const framed=/^\/frame\/member-(\d+)\/$/.exec(pathname);
            if(framed)return route.fulfill({contentType:'text/html',body:`<!doctype html><title>member</title><h1 style="color:#06c">Member page ${framed[1]}</h1>`});
            const candidates=/^\/admin\/dam\/shared-links\/web-collections\/(\d+)\/candidates$/.exec(pathname);
            if(candidates){
                shareFixture.candidateGets++;
                return route.fulfill({status:shareFixture.candidatesStatus,contentType:'application/json',body:JSON.stringify(shareFixture.candidates||{})});
            }
            if(pathname==='/admin/dam/shared-links'&&route.request().method()==='POST'){
                const body=JSON.parse(route.request().postData()||'{}');
                const index=shareFixture.posts.push(body)-1;
                if(shareFixture.gate)await shareFixture.gate(index);
                const answer=shareFixture.answers[index]||{status:200,body:{success:true,share:{token:`tok${body.target_id}`},reused:false}};
                try{
                    if(answer.abort)return await route.abort('failed');
                    return await route.fulfill({status:answer.status,contentType:'application/json',body:JSON.stringify(answer.body)});
                }catch(error){shareFixture.lateAnswers=(shareFixture.lateAnswers||0)+1;return;}
            }
            if(pathname==='/admin/dam/pages/listing')return route.fulfill({contentType:'application/json',body:JSON.stringify({children:[]})});
            if(pathname==='/frame/')return route.fulfill({contentType:'text/html',body:'<!doctype html><title>framed</title><h1 style="color:#0a0">Framed page</h1>'});
            const [,name,relative]=/^\/(admin|dam)\/(.+)$/.exec(pathname)||[];
            const file=name&&path.join(builds[name],relative);
            if(!file||!file.startsWith(builds[name]+path.sep)||!fs.existsSync(file))return route.fulfill({status:404,body:''});
            return route.fulfill({contentType:types[path.extname(file)]||'application/octet-stream',body:fs.readFileSync(file)});
        });
        await tab.goto('http://pages.test/');
        await tab.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH||path.join(dam,'node_modules/vue/dist/vue.global.js')});
        await tab.evaluate(()=>{window.definitions={};window.app={component:(name,definition)=>{window.definitions[name]=definition;}};});
        for(const content of scripts)await tab.addScriptTag({content});
        await tab.evaluate(({markup,props})=>{
            const application=Vue.createApp({data:()=>({props}),template:'<v-dam-web-pages-browser v-bind="props"></v-dam-web-pages-browser>'});
            application.component('v-dam-web-pages-browser',{...window.definitions['v-dam-web-pages-browser'],template:markup.browser});
            application.component('v-dam-web-page-frame',{...window.definitions['v-dam-web-page-frame'],template:markup.frame});
            application.mount('#app');
        },{markup,props});
        // Thumbnails are loading="lazy", so only the ones inside the viewport ever load.
        await tab.waitForFunction(()=>[...document.querySelectorAll('[data-web-page] img')].filter(img=>img.getBoundingClientRect().top<innerHeight).every(img=>img.complete&&img.naturalWidth>0));
        await fn(tab);
        assert.deepEqual(tab.problems,[]);
    }finally{await browser.close();}
}
// Review evidence: set to a directory to save the settled screenshots these cases take; otherwise a no-op.
const shotDir=process.env.MOTHERSHIP_WEB_PAGES_SHOT_DIR;
const shot=async(tab,name)=>{if(!shotDir)return;const file=path.join(shotDir,`${name}.png`);if(fs.existsSync(file))throw new Error(`refusing to overwrite the capture ${file}; point MOTHERSHIP_WEB_PAGES_SHOT_DIR at a fresh directory`);await tab.evaluate(()=>document.fonts.ready);await tab.waitForTimeout(150);await tab.screenshot({path:file});};
const box=(tab,selector)=>tab.$eval(selector,element=>{const rect=element.getBoundingClientRect();return {width:rect.width,height:rect.height,top:rect.top,left:rect.left};});
// A fixed overlay covers the window except a classic scrollbar's gutter, so allow up to 20px there.
const coversViewport=(tab,overlay)=>{const {width,height}=tab.viewportSize();return overlay.left===0&&overlay.top===0&&overlay.height===height&&overlay.width>=width-20&&overlay.width<=width;};
browserTest('page trash is visible only when allowed and confirms in grid list and preview',async()=>{
    await withPage(async tab=>{
        await tab.locator('[data-web-page-menu="1"]').click();
        await tab.locator('[data-action="trash-page"]').click();
        await tab.locator('[data-dialog="trash-page"]').waitFor({state:'visible'});
        assert.match(await tab.locator('[data-dialog-text]').textContent(),/Page 1.*restore/);
        assert.deepEqual(tab.writes,[]);
        if(process.env.MOTHERSHIP_SCREENSHOT_DIR)await tab.screenshot({path:path.join(process.env.MOTHERSHIP_SCREENSHOT_DIR,'1440-page-trash-confirm.png')});
        await tab.setViewportSize({width:375,height:812});
        if(process.env.MOTHERSHIP_SCREENSHOT_DIR)await tab.screenshot({path:path.join(process.env.MOTHERSHIP_SCREENSHOT_DIR,'375-page-trash-confirm.png')});
        const panel=await tab.locator('[data-dialog="trash-page"] section').boundingBox();
        assert.ok(panel.x>=0&&panel.x+panel.width<=375);
        await tab.setViewportSize({width:1440,height:900});
        await tab.getByRole('button',{name:'Cancel',exact:true}).click();
        await tab.locator('[data-web-page-menu="2"]').click();
        assert.equal(await tab.locator('[data-action="trash-page"]').count(),0);
        await tab.keyboard.press('Escape');
        await tab.locator('[data-action="view-list"]').click();
        await tab.locator('[data-web-page-menu="1"]').click();
        assert.equal(await tab.locator('[data-action="trash-page"]').count(),1);
        await tab.keyboard.press('Escape');
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-dialog="preview"]').waitFor({state:'visible'});
        await tab.locator('[data-action="trash-page"]').click();
        await tab.locator('[data-dialog="trash-page"]').waitFor({state:'visible'});
        await tab.getByRole('button',{name:'Cancel',exact:true}).click();
        await tab.locator('[data-dialog="preview"]').waitFor({state:'visible'});
        await tab.locator('[data-action="close-preview"]').click();
        assert.deepEqual(tab.writes,[]);
        await tab.context().route('http://pages.test/admin/dam/pages/1',route=>route.fulfill({contentType:'application/json',body:'{}'}));
        await tab.context().route('http://pages.test/admin/dam/pages/listing*',route=>route.fulfill({contentType:'application/json',body:JSON.stringify({...props.initial,pages:{...props.initial.pages,data:props.initial.pages.data.filter(page=>page.id!==1),total:7}})}));
        await tab.locator('[data-web-page-menu="1"]').click();
        await tab.locator('[data-action="trash-page"]').click();
        await tab.locator('[data-action="confirm-trash-page"]').click();
        await tab.locator('[data-web-page="1"]').waitFor({state:'detached'});
        assert.deepEqual(tab.writes,['DELETE /admin/dam/pages/1']);
    });
});
const centredIn=(outer,inner)=>Math.abs(inner.left+inner.width/2-(outer.left+outer.width/2))<2&&Math.abs(inner.top+inner.height/2-(outer.top+outer.height/2))<2;

browserTest('every class the Web Pages templates use exists in the committed CSS bundles',async()=>{
    await withPage(async tab=>{
        const classes=[...new Set([...templateClasses(markup.browser),...templateClasses(markup.frame)])];
        const missing=await tab.evaluate(classes=>{
            const selectors=[];
            const walk=rules=>{for(const rule of rules){if(rule.selectorText)selectors.push(rule.selectorText);if(rule.cssRules)walk(rule.cssRules);}};
            for(const sheet of document.styleSheets)walk(sheet.cssRules);
            const text=selectors.join('\n');
            return classes.filter(name=>!new RegExp(`\\.${CSS.escape(name).replace(/[\\^$.*+?()[\]{}|]/g,'\\$&')}(?![\\w-]|\\\\)`).test(text));
        },classes);
        assert.deepEqual(missing,[],`classes absent from the committed bundles do nothing on the Studio: ${missing.join(', ')}`);
    });
});

browserTest('page cards sit on a multi-column grid with 16:9 thumbnails',async()=>{
    await withPage(async tab=>{
        const cards=await tab.$$eval('[data-web-page]',elements=>elements.map(card=>{
            const outer=card.getBoundingClientRect(),thumb=card.querySelector('img').parentElement.getBoundingClientRect();
            return {top:Math.round(outer.top),width:outer.width,thumbWidth:thumb.width,thumbHeight:thumb.height};
        }));
        assert.equal(cards.length,8);
        assert.ok(cards.filter(card=>card.top===cards[0].top).length>=4,`first row holds ${cards.filter(card=>card.top===cards[0].top).length} cards`);
        for(const card of cards){
            assert.ok(card.width>=240&&card.width<=400,`card is ${card.width}px wide`);
            assert.ok(Math.abs(card.thumbWidth/card.thumbHeight-16/9)<0.02,`thumbnail is ${card.thumbWidth}x${card.thumbHeight}`);
        }
        // The collapsed "‹ Parent" link is for narrow screens; on desktop the full breadcrumb shows.
        const back=await tab.$$eval('[data-web-pages-breadcrumb] a',links=>links.filter(link=>getComputedStyle(link).display!=='none').map(link=>link.textContent.trim()));
        assert.deepEqual(back,['Web Pages','CIL']);
    });
});

browserTest('a card opens the preview as a viewport-filling modal and closing returns focus to it',async()=>{
    await withPage(async tab=>{
        await tab.click('[data-web-page="2"]');
        await tab.waitForSelector('dialog[data-dialog="preview"] iframe');
        assert.equal(await tab.$eval('dialog[data-dialog="preview"]',dialog=>dialog.matches(':modal')),true);
        const viewport=tab.viewportSize(),overlay=await box(tab,'dialog[data-dialog="preview"]'),panel=await box(tab,'[data-preview-panel]'),frame=await box(tab,'dialog[data-dialog="preview"] iframe');
        assert.ok(coversViewport(tab,overlay),`overlay at ${JSON.stringify(overlay)}`);
        assert.ok(centredIn(overlay,panel),`panel at ${JSON.stringify(panel)}`);
        assert.ok(panel.height>=viewport.height-40,`panel is ${panel.height}px tall`);
        assert.ok(frame.height>=viewport.height*0.6,`frame is ${frame.height}px tall`);
        assert.equal(await tab.$eval('dialog[data-dialog="preview"] iframe',frame=>[frame.getAttribute('sandbox'),frame.src]).then(String),`${sandbox},http://pages.test/frame/`);
        assert.equal(await tab.$eval('dialog[data-dialog="preview"] p',title=>title.textContent.trim()),'Page 2');
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webPage),'2');
        // A click on the overlay outside the panel is Cancel; a click inside the panel is not.
        await tab.click('[data-web-page="3"]');
        await tab.waitForSelector('dialog[data-dialog="preview"] iframe');
        await tab.click('dialog[data-dialog="preview"] header p');
        assert.ok(await tab.$('dialog[data-dialog="preview"]'));
        // A press in the first 500 ms is the tail of the double-click that opened the dialog, so this one waits.
        await tab.waitForTimeout(550);
        await tab.mouse.click(5,5);
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        // The browser's own close request (a cancel event, not a keydown the page sees) closes it too.
        await tab.click('[data-web-page="4"]');
        await tab.waitForSelector('dialog[data-dialog="preview"] iframe');
        await tab.$eval('dialog[data-dialog="preview"]',dialog=>dialog.dispatchEvent(new Event('cancel',{cancelable:true})));
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
    });
});

browserTest('Enter on a card opens the preview with focus on Close, and the same Enter presses nothing inside it',async()=>{
    await withPage(async tab=>{
        await tab.focus('[data-web-page="2"]');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('dialog[data-dialog="preview"] iframe');
        await tab.waitForTimeout(200);
        assert.ok(await tab.$('dialog[data-dialog="preview"]'),'the preview closed itself');
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'close-preview');
        assert.deepEqual(tab.writes,[],'Copy share link must not have been pressed');
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        // Enter on a card's own kebab opens that menu; it is not an Enter on the card.
        await tab.focus('[data-web-page-menu="3"]');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('[data-web-page-menu-open="3"]');
        assert.equal(await tab.$('dialog[data-dialog]'),null);
    });
});

browserTest('a dialog opened from a kebab menu gives focus back to that kebab',async()=>{
    await withPage(async tab=>{
        await tab.click('[data-web-collection-menu="9"]');
        await tab.click('[data-web-collection-menu-open="9"] >> text=Delete');
        await tab.waitForSelector('dialog[data-dialog="delete"]');
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webCollectionMenu),'9');
        await tab.click('[data-web-page-menu="5"]');
        await tab.click('[data-web-page-menu-open="5"] >> text=Move to…');
        await tab.waitForSelector('dialog[data-dialog="move"]');
        await tab.click('dialog[data-dialog="move"] >> text=Cancel');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webPageMenu),'5');
    });
});

browserTest('a folder card opens its menu on right-click and from the keyboard, offers only what the folder allows, and closes with focus kept',async()=>{
    await withPage(async tab=>{
        const items=selector=>tab.$$eval(`${selector} [role="menuitem"]`,found=>found.map(item=>item.dataset.action));
        // Right-click: the menu opens in place of the browser's, nothing navigates, nothing is renamed.
        await tab.click('[data-web-collection="9"]',{button:'right'});
        await tab.waitForSelector('[data-web-collection-menu-open="9"]');
        assert.deepEqual(await items('[data-web-collection-menu-open="9"]'),['open-folder','share-folder','rename-folder','move-folder','delete-folder']);
        await shot(tab,'desktop-1440-light-folder-menu');
        assert.equal(await tab.$('[data-rename-input]'),null);
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'open-folder','focus moves into the menu');
        await tab.keyboard.press('ArrowDown');
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'share-folder');
        await tab.keyboard.press('ArrowDown');
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'rename-folder');
        await tab.keyboard.press('ArrowDown');
        await tab.keyboard.press('ArrowDown');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('dialog[data-dialog="delete"]');
        await shot(tab,'desktop-1440-light-delete-confirm');
        await tab.click('dialog[data-dialog="delete"] >> text=Cancel');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.deepEqual(tab.writes,[],'cancelling the confirmation deletes nothing');
        await tab.click('[data-web-collection="9"]',{button:'right'});
        await tab.waitForSelector('[data-web-collection-menu-open="9"]');
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('[data-web-collection-menu-open]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webCollection),'9','Escape returns focus to the card');
        // A folder the signed-in role did not create offers Open, and Share where this person may make links.
        await tab.focus('[data-web-collection="10"]');
        await tab.keyboard.press('ContextMenu');
        await tab.waitForSelector('[data-web-collection-menu-open="10"]');
        assert.deepEqual(await items('[data-web-collection-menu-open="10"]'),['open-folder','share-folder']);
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('[data-web-collection-menu-open]',{state:'detached'});
        await tab.keyboard.press('Shift+F10');
        await tab.waitForSelector('[data-web-collection-menu-open="10"]');
        await tab.keyboard.press('Tab');
        await tab.waitForSelector('[data-web-collection-menu-open]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webCollection),'10');
        assert.deepEqual(tab.writes,[],'opening menus writes nothing');
    });
});

browserTest('an own folder holding someone else\'s page offers Open, Rename and Move but not Delete, and its focused button opens the menu from the ContextMenu and Shift+F10 keys',async()=>{
    const holding={id:11,name:'Holds a colleague page',page_count:1,children_count:0,can_modify:true,can_delete:false};
    props.initial.children.push(holding);
    try{
        await withPage(async tab=>{
            const items=()=>tab.$$eval('[data-web-collection-menu-open="11"] [role="menuitem"]',found=>found.map(item=>item.dataset.action));
            for(const key of ['ContextMenu','Shift+F10']){
                await tab.focus('[data-web-collection-menu="11"]');
                await tab.keyboard.press(key);
                await tab.waitForSelector('[data-web-collection-menu-open="11"]');
                assert.deepEqual(await items(),['open-folder','share-folder','rename-folder','move-folder']);
                assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'open-folder',`${key} leaves focus inside the menu`);
                await tab.keyboard.press('ArrowDown');
                assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'share-folder');
                await tab.keyboard.press('Escape');
                await tab.waitForSelector('[data-web-collection-menu-open]',{state:'detached'});
                assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webCollection),'11',`Escape after ${key} returns focus to the folder`);
            }
            assert.deepEqual(tab.writes,[]);
        });
    }finally{props.initial.children.pop();}
});

browserTest('the folder action button is visible without hover on a touch screen, and opens the same menu',async()=>{
    await withPage(async tab=>{
        await tab.evaluate(()=>document.documentElement.classList.add('dark'));
        assert.equal(await tab.$eval('[data-web-collection-menu="9"]',button=>getComputedStyle(button).opacity),'1');
        await tab.tap('[data-web-collection-menu="9"]');
        await tab.waitForSelector('[data-web-collection-menu-open="9"]');
        await shot(tab,'mobile-375-dark-folder-menu');
        await tab.tap('[data-web-collection-menu-open="9"] >> text=Delete');
        await tab.waitForSelector('dialog[data-dialog="delete"]');
        await shot(tab,'mobile-375-dark-delete-confirm');
        const dialog=await box(tab,'dialog[data-dialog="delete"] section');
        assert.ok(dialog.left>=0&&dialog.left+dialog.width<=375,'the confirmation fits the phone width');
        await tab.tap('dialog[data-dialog="delete"] >> text=Cancel');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.deepEqual(tab.writes,[]);
    },{width:375,height:800},{hasTouch:true,isMobile:true});
});

browserTest('a Mac ctrl+click after the context menu keeps the menu open and opens nothing; an outside right-click dismisses it; a left click still opens the folder',async()=>{
    await withPage(async tab=>{
        await tab.evaluate(()=>Object.defineProperty(navigator,'platform',{get:()=>'MacIntel',configurable:true}));
        const search=await tab.evaluate(()=>location.search);
        const listings=[];
        tab.on('request',request=>{if(new URL(request.url()).pathname==='/admin/dam/pages/listing')listings.push(request.url());});
        await tab.click('[data-web-collection="9"]',{button:'right'});
        await tab.waitForSelector('[data-web-collection-menu-open="9"]');
        // The click some browsers still send after the context menu of a Mac ctrl+click.
        await tab.dispatchEvent('[data-web-collection="9"]','click',{button:0,ctrlKey:true,bubbles:true});
        await tab.waitForTimeout(100);
        assert.ok(await tab.$('[data-web-collection-menu-open="9"]'),'the document click listener closed the menu');
        assert.equal(await tab.evaluate(()=>location.search),search);
        assert.deepEqual(listings,[]);
        await tab.mouse.click(5,5,{button:'right'});
        await tab.waitForSelector('[data-web-collection-menu-open]',{state:'detached'});
        // Dismissed by the pointer, Escape later must not pull focus onto the folder card.
        await tab.focus('[data-web-page-menu="3"]');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('[data-web-page-menu-open="3"]');
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('[data-web-page-menu-open]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webPageMenu),'3','focus stays on the page kebab');
        const [request]=await Promise.all([tab.waitForRequest(request=>request.url().includes('collection=9')),tab.click('[data-web-collection="9"]')]);
        assert.ok(request);
    });
});

browserTest('every page kebab item can be clicked; none is clipped by the card',async()=>{
    await withPage(async tab=>{
        await tab.click('[data-web-page-menu="1"]');
        const hidden=await tab.$$eval('[data-web-page-menu-open="1"] [role="menuitem"]',items=>items.filter(item=>{
            const rect=item.getBoundingClientRect(),hit=document.elementFromPoint(rect.left+rect.width/2,rect.top+rect.height/2);
            return !item.contains(hit);
        }).map(item=>item.textContent.trim()));
        assert.deepEqual(hidden,[]);
    });
});

browserTest('a drag from the name field released on the overlay keeps the dialog and the name',async()=>{
    await withPage(async tab=>{
        await tab.click('[data-action="new-folder"]');
        await tab.keyboard.type('Smoke');
        const input=await box(tab,'dialog[data-dialog="new-folder"] input');
        await tab.mouse.move(input.left+input.width/2,input.top+input.height/2);
        await tab.mouse.down();
        await tab.mouse.move(5,5,{steps:5});
        await tab.mouse.up();
        await tab.waitForTimeout(100);
        assert.equal(await tab.$eval('dialog[data-dialog="new-folder"] input',field=>field.value),'Smoke');
    });
});

browserTest('in the dark theme the typed folder name stays readable',async()=>{
    await withPage(async tab=>{
        await tab.evaluate(()=>document.documentElement.classList.add('dark'));
        await tab.click('[data-action="new-folder"]');
        const contrast=await tab.$eval('dialog[data-dialog="new-folder"] input',field=>{
            const style=getComputedStyle(field);
            const luminance=color=>{
                const [r,g,b]=color.match(/[\d.]+/g).slice(0,3).map(Number).map(value=>{const c=value/255;return c<=0.03928?c/12.92:((c+0.055)/1.055)**2.4;});
                return 0.2126*r+0.7152*g+0.0722*b;
            };
            const [light,dark]=[luminance(style.color),luminance(style.backgroundColor)].sort((a,b)=>b-a);
            return (light+0.05)/(dark+0.05);
        });
        assert.ok(contrast>=4.5,`input text contrast is ${contrast.toFixed(2)}:1`);
    });
});

browserTest('toolbar dialogs are modal, centred, and give focus back to their button',async()=>{
    await withPage(async tab=>{
        await tab.click('[data-action="new-folder"]');
        await tab.waitForSelector('dialog[data-dialog="new-folder"]');
        assert.equal(await tab.$eval('dialog[data-dialog="new-folder"]',dialog=>dialog.matches(':modal')),true);
        assert.equal(await tab.evaluate(()=>document.activeElement?.tagName),'INPUT');
        const overlay=await box(tab,'dialog[data-dialog="new-folder"]'),panel=await box(tab,'dialog[data-dialog="new-folder"] section');
        assert.ok(coversViewport(tab,overlay),`overlay at ${JSON.stringify(overlay)}`);
        assert.ok(centredIn(overlay,panel),`panel at ${JSON.stringify(panel)}`);
        assert.ok(panel.width<=450,`panel is ${panel.width}px wide`);
        await tab.click('dialog[data-dialog="new-folder"] button[type="button"]');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'new-folder');
        // A double-click on the button opens the dialog and keeps it open: the second press lands on the overlay.
        await tab.dblclick('[data-action="new-folder"]');
        await tab.waitForTimeout(300);
        assert.ok(await tab.$('dialog[data-dialog="new-folder"]'),'the second click of a double-click does not cancel the dialog');
        await tab.waitForTimeout(300);
        await tab.mouse.click(5,5);
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
    });
});

browserTest('on a phone the cards stack in one column, the breadcrumb collapses, and the preview fits',async()=>{
    await withPage(async tab=>{
        const cards=await tab.$$eval('[data-web-page]',elements=>elements.map(card=>{const rect=card.getBoundingClientRect();return {left:Math.round(rect.left),top:Math.round(rect.top),width:rect.width};}));
        assert.ok(cards.every(card=>card.left===cards[0].left),`card lefts ${cards.map(card=>card.left)}`);
        assert.ok(cards.every((card,index)=>index===0||card.top>cards[index-1].top),`card tops ${cards.map(card=>card.top)}`);
        assert.ok(cards.every(card=>card.width>=240),`card widths ${cards.map(card=>card.width)}`);
        assert.ok(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),'the page scrolls sideways');
        const crumbs=await tab.$$eval('[data-web-pages-breadcrumb] a',links=>links.map(link=>link.textContent.trim()));
        assert.deepEqual(crumbs,['‹ Web Pages']);
        await tab.click('[data-web-page="1"]');
        await tab.waitForSelector('dialog[data-dialog="preview"] iframe');
        const panel=await box(tab,'[data-preview-panel]');
        assert.ok(panel.left>=0&&panel.left+panel.width<=375&&panel.top>=0&&panel.top+panel.height<=812,`panel at ${JSON.stringify(panel)}`);
    },{width:375,height:812});
});

const sum=letter=>letter.repeat(64);
const stack=[
    {attachment_id:5,file_id:50,checksum:sum('a'),name:'Launch site.zip',candidate:true,is_main:true,extension:'zip',state:'ready'},
    {attachment_id:6,file_id:60,checksum:sum('b'),name:'Second concept with a very long file name that must truncate.zip',candidate:true,is_main:false,extension:'zip',state:'none'},
    {attachment_id:7,file_id:70,checksum:sum('c'),name:'notes.txt',candidate:false,is_main:false,extension:'txt',state:'unsupported'},
];
const resetMembers=()=>{memberFixture.rows={1:stack};memberFixture.answers={};memberFixture.grants=[];memberFixture.delays={};memberFixture.membersDelay=0;memberFixture.membersStatus=200;memberFixture.membersCalls=0;};

browserTest('a stack previews each of its files from its own page, with a read-only strip, previous/next, a pinned full screen and an honest state per file',async()=>{
    resetMembers();
    memberFixture.answers[70]={status:422,body:{state:'unsupported'}};
    await withPage(async tab=>{
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
        assert.equal(await tab.locator('[data-preview-member]').count(),3);
        assert.equal((await tab.locator('[data-preview-member-position]').textContent()).trim(),'File 1 of 3');
        assert.equal(await tab.locator('[data-preview-member="5"]').getAttribute('aria-pressed'),'true');
        assert.equal(await tab.locator('[data-action="member-prev"]').isDisabled(),true);
        const frame=()=>tab.frameLocator('[data-dialog="preview"] iframe');
        await frame().locator('h1').waitFor();
        assert.equal(await frame().locator('h1').textContent(),'Member page 50','the explicit representative uses the exact-member path');
        assert.match(await tab.locator('[data-action="open-full-screen"]').getAttribute('href'),new RegExp(`/pages/1/open\\?file=50&checksum=${sum('a')}$`));
        await shot(tab,'member-strip-desktop-1-of-3');

        await tab.locator('[data-action="member-next"]').click();
        await frame().locator('h1:has-text("Member page 60")').waitFor();
        assert.equal((await tab.locator('[data-preview-member-position]').textContent()).trim(),'File 2 of 3');
        assert.equal(await tab.locator('[data-preview-member="6"]').getAttribute('aria-pressed'),'true');
        assert.equal(await tab.locator('[data-preview-member="5"]').getAttribute('aria-pressed'),'false');
        assert.match(await tab.locator('[data-action="open-full-screen"]').getAttribute('href'),new RegExp(`file=60&checksum=${sum('b')}$`));
        const strip=await box(tab,'[data-preview-member-strip]'),dialog=await box(tab,'[data-dialog="preview"] section');
        assert.ok(strip.left>=dialog.left&&strip.left+strip.width<=dialog.left+dialog.width+1,'a long name truncates inside the strip');
        await shot(tab,'member-strip-desktop-2-of-3');

        await tab.locator('[data-action="member-next"]').click();
        await tab.locator('[data-dam-web-member-status][data-state="unsupported"]').waitFor();
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),0,'a file that is no page shows a state, never another file');
        assert.equal(await tab.locator('[data-action="member-next"]').isDisabled(),true);
        // The selected file is no page: full screen stays in place but cannot be pressed, as for a changed or removed one.
        assert.equal(await tab.locator('[data-action="open-full-screen"]').count(),0);
        assert.equal(await tab.locator('[data-action="open-full-screen-unavailable"]').isDisabled(),true,'full screen is not actionable for a file with no page preview');
        assert.equal(await tab.locator('[data-action="preview-copy-share-link"]').isDisabled(),false,'a file that is no page is still shared by its slot');
        await shot(tab,'member-strip-desktop-unsupported');

        await tab.locator('[data-action="member-prev"]').click();
        await tab.locator('[data-action="member-prev"]').click();
        await frame().locator('h1:has-text("Member page 50")').waitFor();
        assert.deepEqual(tab.writes,[],'choosing a file writes nothing');
        await tab.locator('[data-action="close-preview"]').click();
        await tab.locator('[data-dialog="preview"]').waitFor({state:'hidden'});
        // Reopening starts clean: the strip is read again for the page, not carried over.
        memberFixture.rows[1]=stack.slice(0,1);
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-dialog="preview"] iframe').waitFor();
        assert.equal(await tab.locator('[data-preview-members]').count(),0,'a one-file stack shows no strip');
    });
});

browserTest('a file revised while open is reported as changed once, never swapped for the new one until the viewer chooses',async()=>{
    resetMembers();
    // What the server really answers for the old file id of a revised slot: it is no longer a current file at all.
    memberFixture.answers[60]={status:404,body:{message:'Not Found'}};
    await withPage(async tab=>{
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
        await tab.locator('[data-preview-member-strip]').evaluate(el=>el.scrollLeft=0);
        const edge=async()=>{const position=await box(tab,'[data-preview-member-position]');return {positionRight:Math.round(position.left+position.width),next:await box(tab,'[data-action="member-next"]'),row:await box(tab,'[data-preview-members]')};};
        const before=await edge();
        assert.equal(await tab.locator('[data-action="preview-copy-share-link"]').isDisabled(),false,'a current file can be shared');
        // The slot now holds a newer file; the open pin is not current any more.
        memberFixture.rows[1]=[stack[0],{...stack[1],file_id:61,checksum:sum('d')},stack[2]];
        await tab.locator('[data-action="member-next"]').click();
        const status=tab.locator('[data-dam-web-member-status]');
        await status.waitFor();
        await tab.waitForFunction(()=>document.querySelector('[data-dam-web-member-status]')?.dataset.state==='stale');
        assert.equal((await status.locator('p').textContent()).trim(),'This file changed since it was opened.','the stage says changed, as the list does');
        assert.equal(await tab.getByText('This file changed since it was opened.').count(),1,'one message, not one in the strip and one on the stage');
        assert.equal(await tab.getByText('no longer part of this page').count(),0);
        assert.equal(await tab.locator('[data-preview-member-changed]').count(),0);
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),0);
        assert.equal(await tab.locator('[data-action="open-full-screen"]').count(),0,'no full screen link for a file that no longer opens');
        assert.equal(await tab.locator('[data-action="preview-copy-share-link"]').isDisabled(),true,'no link is pinned to a file that changed');
        assert.equal((await tab.locator('[data-preview-member-position]').textContent()).trim(),'File 2 of 3');
        assert.deepEqual(await edge(),before,'the strip controls do not move when the file changes');
        await shot(tab,'member-changed-desktop');
        await tab.locator('[data-action="show-current-member"]').click();
        await tab.frameLocator('[data-dialog="preview"] iframe').locator('h1:has-text("Member page 61")').waitFor();
        assert.equal(await tab.locator('[data-dam-web-member-status]').count(),0);
        assert.deepEqual(memberFixture.grants.filter(Boolean),['50','60','61'],'the changed file asked for its own pin once; the new file only on the viewer\'s choice');
    });
});

browserTest('a file whose slot was removed while open keeps its name, says it is unavailable and offers the remaining files by choice only',async()=>{
    resetMembers();
    memberFixture.answers[60]={status:404,body:{message:'Not Found'}};
    await withPage(async tab=>{
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
        memberFixture.rows[1]=[stack[0],stack[2]];
        await tab.locator('[data-action="member-next"]').click();
        await tab.locator('[data-dam-web-member-status][data-state="unavailable"]').waitFor();
        await tab.locator('[data-preview-member-removed]').waitFor();
        assert.equal((await tab.locator('[data-dam-web-member-status] p').textContent()).trim(),'This file is no longer part of this page.');
        assert.equal(await tab.locator('[data-preview-member-removed]').getAttribute('title'),stack[1].name,'the strip keeps the pinned file\'s own name');
        assert.equal(await tab.locator('[data-preview-members]').textContent().then(text=>/File 0 of/.test(text)),false,'never "File 0 of N"');
        assert.equal((await tab.locator('[data-preview-member-position]').textContent()).trim(),'','no position is claimed for a file that is gone');
        assert.equal(await tab.locator('[data-action="show-current-member"]').count(),0,'a removed file has no newer file to show');
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),0,'no silent fallback to the representative');
        assert.equal(await tab.locator('[data-action="member-prev"]').isDisabled(),true);
        assert.equal(await tab.locator('[data-action="member-next"]').isDisabled(),true);
        assert.equal(await tab.locator('[data-action="preview-copy-share-link"]').isDisabled(),true,'no link is pinned to a file that is gone');
        await shot(tab,'member-removed-desktop');
        // The remaining file is the viewer's explicit choice.
        await tab.locator('[data-preview-member="5"]').click();
        await tab.frameLocator('[data-dialog="preview"] iframe').locator('h1:has-text("Member page 50")').waitFor();
        assert.equal(await tab.locator('[data-action="preview-copy-share-link"]').isDisabled(),false,'a current file can be shared again');
        assert.equal(await tab.locator('[data-preview-member-removed]').count(),0);
        assert.equal((await tab.locator('[data-preview-member-position]').textContent()).trim(),'File 1 of 2');
    });
});

browserTest('a 404 on the standalone full-screen frame stays neutral, while the preview\'s confirmed removal keeps its specific message',async()=>{
    resetMembers();
    memberFixture.answers[60]={status:404,body:{message:'Not Found'}};
    await withPage(async tab=>{
        // The full-screen page mounts the frame alone: no preview list answers for it, so a 404 proves nothing about removal.
        await tab.evaluate(({markup,member})=>{
            const host=document.createElement('div');host.id='standalone';document.body.appendChild(host);
            const application=Vue.createApp({data:()=>({member}),template:'<v-dam-web-page-frame :asset-id="1" file-name="Standalone" :file-id="member.file_id" :checksum="member.checksum"></v-dam-web-page-frame>'});
            application.component('v-dam-web-page-frame',{...window.definitions['v-dam-web-page-frame'],template:markup.frame});
            application.mount(host);
        },{markup,member:stack[1]});
        const status=tab.locator('#standalone [data-dam-web-member-status]');
        await status.waitFor();
        assert.equal(await status.getAttribute('data-state'),'not-shown');
        const text=(await status.locator('p').textContent()).trim();
        assert.equal(text,'This file could not be shown here. It may have changed or is no longer available.');
        assert.doesNotMatch(text,/no longer part of this page/);
        assert.equal(memberFixture.membersCalls,0,'the standalone frame reads no file list and settles nothing by itself');
        assert.equal(await tab.locator('#standalone [data-action="show-current-member"]').count(),0);
    });
});
browserTest('a late answer for a file the viewer already left never repaints the stage, whether they went back to the first file or closed the preview',async()=>{
    resetMembers();
    memberFixture.delays[60]=900;
    await withPage(async tab=>{
        const frame=()=>tab.frameLocator('[data-dialog="preview"] iframe');
        await tab.locator('[data-web-page="1"]').click();
        await frame().locator('h1:has-text("Member page 50")').waitFor();

        // A -> B -> A: B's answer is still on its way when the viewer is back on A.
        await tab.locator('[data-action="member-next"]').click();
        await tab.locator('[data-preview-member="6"][aria-pressed="true"]').waitFor();
        await tab.locator('[data-action="member-prev"]').click();
        await frame().locator('h1:has-text("Member page 50")').waitFor();
        await tab.waitForTimeout(1300);
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),1);
        assert.match(await tab.locator('[data-dialog="preview"] iframe').getAttribute('src'),/member-50\/$/,'the stage still shows the file the viewer is on');
        assert.equal(await tab.locator('iframe[src*="member-60"]').count(),0,'the late answer for B painted nothing');
        assert.equal(await tab.locator('[data-preview-member="5"]').getAttribute('aria-pressed'),'true');

        // A -> B -> close: the answer arrives after the preview is gone.
        await tab.locator('[data-action="member-next"]').click();
        await tab.locator('[data-preview-member="6"][aria-pressed="true"]').waitFor();
        await tab.locator('[data-action="close-preview"]').click();
        await tab.locator('[data-dialog="preview"]').waitFor({state:'hidden'});
        await tab.waitForTimeout(1300);
        assert.equal(await tab.locator('iframe').count(),0,'nothing is drawn after the preview closed');
        assert.deepEqual(tab.writes,[]);
    });
});

// The 2 s between the frame's asks is shortened inside the page so the test does not wait a minute; how many times it asks is the
// product's own bound, counted on the wire.
const shortenPollWait=tab=>tab.evaluate(()=>{const native=window.setTimeout.bind(window);window.setTimeout=(fn,ms,...rest)=>native(fn,ms===2000?0:ms,...rest);});
const asksFor=file=>memberFixture.grants.filter(asked=>asked===file).length;
const untilAsks=async(tab,file,count)=>{for(let guard=0;guard<200&&asksFor(file)<count;guard++)await tab.waitForTimeout(25);assert.ok(asksFor(file)>=count,`only ${asksFor(file)} asks for file ${file}`);};
const reachSlow=async tab=>{
    await shortenPollWait(tab);
    await tab.locator('[data-web-page="1"]').click();
    await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
    await tab.locator('[data-dialog="preview"] iframe').waitFor();
    memberFixture.grants=[];
    await tab.locator('[data-action="member-next"]').click();
    await tab.locator('[data-dam-web-frame-slow]').waitFor();
};

browserTest('a member still being prepared when the asks run out is slow, not failed: Reload asks again for one more bounded round, and a queue that caught up opens it',async()=>{
    resetMembers();
    memberFixture.answers[60]={status:409,body:{state:'preparing'}};
    await withPage(async tab=>{
        await reachSlow(tab);
        assert.equal(asksFor('60'),31,'the first ask and the product\'s 30 polls, no more');
        assert.equal((await tab.locator('[data-dam-web-frame-slow]').textContent()).trim(),'This file is taking longer than usual to prepare. It may still finish: use Reload to check again.');
        assert.equal(await tab.getByText('could not be prepared').count(),0,'a slow queue is not reported as a failure');
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),0,'nothing else is shown in its place');
        assert.equal(await tab.locator('[data-dam-web-member-status]').count(),0);
        assert.equal(await tab.locator('[data-dam-web-reload]').count(),1,'the toolbar Reload is the way to ask again');
        assert.equal(await tab.locator('[data-dam-web-reload]').isEnabled(),true);
        await tab.waitForTimeout(400);
        assert.equal(asksFor('60'),31,'nothing asks again by itself');
        await shot(tab,'member-slow-desktop');

        // Reload is the viewer's own request: exactly one more round of the same bound, ending slow again while the queue is slow.
        await tab.locator('[data-dam-web-reload]').click();
        await untilAsks(tab,'60',62);
        await tab.locator('[data-dam-web-frame-slow]').waitFor();
        await tab.waitForTimeout(400);
        assert.equal(asksFor('60'),62);
        assert.equal(await tab.getByText('could not be prepared').count(),0);

        // The queue caught up: the next Reload opens exactly that file.
        delete memberFixture.answers[60];
        await tab.locator('[data-dam-web-reload]').click();
        await tab.frameLocator('[data-dialog="preview"] iframe').locator('h1:has-text("Member page 60")').waitFor();
        assert.equal(await tab.locator('[data-dam-web-frame-slow]').count(),0);
        assert.equal(await tab.locator('[data-action="open-full-screen"]').count(),1,'a ready file offers full screen again');
        assert.deepEqual(tab.writes,[]);
    });
});

browserTest('on a phone, in the dark theme, the slow message and Reload fit inside the preview and nothing scrolls sideways',async()=>{
    resetMembers();
    memberFixture.answers[60]={status:409,body:{state:'preparing'}};
    await withPage(async tab=>{
        await tab.evaluate(()=>document.documentElement.classList.add('dark'));
        await reachSlow(tab);
        const dialog=await box(tab,'[data-dialog="preview"] section');
        for(const selector of ['[data-dam-web-frame-slow]','[data-dam-web-reload]','[data-action="member-next"]','[data-preview-member-strip]']){
            const rect=await box(tab,selector);
            assert.ok(rect.left>=dialog.left-1&&rect.left+rect.width<=dialog.left+dialog.width+1,`${selector} fits the 375px preview`);
        }
        assert.equal(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal page scroll');
        await shot(tab,'member-slow-mobile-dark');
    },{width:375,height:812},{isMobile:true,hasTouch:true,deviceScaleFactor:2});
});

const noContradiction=async tab=>{
    // At no moment does the stage assert something the final state may contradict, nor the strip claim "only the main page".
    assert.equal(await tab.getByText('no longer part of this page').count(),0);
    assert.equal(await tab.getByText('only the main page is shown').count(),0);
    assert.equal(await tab.locator('[data-preview-member-removed]').count(),0);
    assert.equal(await tab.locator('[data-preview-members-failed]').count(),0);
};

browserTest('while the one list refresh is out the stage only says it is checking, then settles on changed',async()=>{
    resetMembers();
    memberFixture.answers[60]={status:404,body:{message:'Not Found'}};
    await withPage(async tab=>{
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
        memberFixture.rows[1]=[stack[0],{...stack[1],file_id:61,checksum:sum('d')},stack[2]];
        memberFixture.membersDelay=900;
        await tab.locator('[data-action="member-next"]').click();
        await tab.locator('[data-dam-web-member-status][data-state="checking"]').waitFor();
        assert.equal((await tab.locator('[data-dam-web-member-status] p').textContent()).trim(),'Checking this file…');
        await noContradiction(tab);
        assert.equal(await tab.getByText('This file changed since it was opened.').count(),0,'not claimed before there is evidence');
        assert.equal(await tab.locator('[data-action="show-current-member"]').count(),0);
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),0);
        assert.equal(await tab.locator('[data-preview-member="6"]').getAttribute('aria-pressed'),'true','the original pin stays selected');
        await shot(tab,'member-checking-desktop');
        await tab.locator('[data-dam-web-member-status][data-state="stale"]').waitFor();
        assert.equal(await tab.getByText('This file changed since it was opened.').count(),1);
        assert.equal(memberFixture.membersCalls,2,'the first list read and exactly one refresh');
        assert.deepEqual(memberFixture.grants.filter(Boolean),['50','60'],'the current revision was never asked for on its own');
    });
});

browserTest('a refresh that fails claims nothing, keeps the pin and the list, and checks again only on the viewer\'s click',async()=>{
    resetMembers();
    memberFixture.answers[60]={status:404,body:{message:'Not Found'}};
    await withPage(async tab=>{
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
        memberFixture.membersStatus=500;
        await tab.locator('[data-action="member-next"]').click();
        await tab.locator('[data-dam-web-member-status][data-state="check-failed"]').waitFor();
        assert.equal((await tab.locator('[data-dam-web-member-status] p').textContent()).trim(),'This file could not be shown, and whether it changed could not be checked.');
        await noContradiction(tab);
        assert.equal(await tab.getByText('This file changed since it was opened.').count(),0);
        assert.equal(await tab.locator('[data-preview-member]').count(),3,'the list stays as it was');
        assert.equal(await tab.locator('[data-preview-member="6"]').getAttribute('aria-pressed'),'true');
        assert.equal(await tab.locator('[data-dialog="preview"] iframe').count(),0,'nothing else is shown in its place');
        assert.equal(await tab.locator('[data-action="show-current-member"]').count(),0);
        await tab.waitForTimeout(2500);
        assert.equal(memberFixture.membersCalls,2,'no automatic retry loop');
        await shot(tab,'member-check-failed-desktop');
        // The viewer asks again; the slot is gone now.
        memberFixture.membersStatus=200;
        memberFixture.rows[1]=[stack[0],stack[2]];
        await tab.locator('[data-action="check-member-again"]').click();
        await tab.locator('[data-dam-web-member-status][data-state="unavailable"]').waitFor();
        await tab.locator('[data-preview-member-removed]').waitFor();
        assert.equal(memberFixture.membersCalls,3,'one read for the one click');
        assert.equal(await tab.locator('[data-action="check-member-again"]').count(),0);
    });
});

for(const [name,rows,state,text] of [['changed',()=>[stack[0],{...stack[1],file_id:61,checksum:sum('d')},stack[2]],'stale','This file changed since it was opened.'],['removed',()=>[stack[0],stack[2]],'unavailable','This file is no longer part of this page.']]){
    browserTest(`on a phone a ${name} file shows one message, keeps the strip inside the preview and moves no control`,async()=>{
        resetMembers();
        memberFixture.answers[60]={status:404,body:{message:'Not Found'}};
        await withPage(async tab=>{
            await tab.locator('[data-web-page="1"]').click();
            await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
            await tab.locator('[data-dialog="preview"] iframe').waitFor();
            const bar=async()=>({prev:await box(tab,'[data-action="member-prev"]'),next:await box(tab,'[data-action="member-next"]'),row:await box(tab,'[data-preview-members]')});
            const before=await bar();
            memberFixture.rows[1]=rows();
            await tab.locator('[data-action="member-next"]').click();
            await tab.locator(`[data-dam-web-member-status][data-state="${state}"]`).waitFor();
            assert.equal(await tab.getByText(text).count(),1);
            const dialog=await box(tab,'[data-dialog="preview"] section');
            for(const selector of ['[data-action="member-prev"]','[data-action="member-next"]','[data-preview-member-strip]','[data-dam-web-member-status] p'].concat(name==='changed'?['[data-action="show-current-member"]']:[])){
                const rect=await box(tab,selector);
                assert.ok(rect.left>=dialog.left-1&&rect.left+rect.width<=dialog.left+dialog.width+1,`${selector} fits the 375px preview`);
            }
            // The position label's width follows its digits, so the arrows are compared by row and size, not by left edge.
            const settle=({prev,next,row})=>({prevTop:prev.top,nextTop:next.top,prevLeft:prev.left,size:[prev.width,prev.height,next.width,next.height],row:[row.top,row.left,row.width,row.height]});
            assert.deepEqual(settle(await bar()),settle(before),'the arrows and the row do not move');
            assert.equal(await tab.locator('[data-action="open-full-screen-unavailable"]').isDisabled(),true,'the full screen button stays in place, disabled');
            assert.equal(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal page scroll');
            await shot(tab,`member-${name}-mobile`);
        },{width:375,height:812},{isMobile:true,hasTouch:true,deviceScaleFactor:2});
    });
}

browserTest('on a phone the member strip stays inside the preview and scrolls its names',async()=>{
    resetMembers();
    await withPage(async tab=>{
        await tab.locator('[data-web-page="1"]').click();
        await tab.locator('[data-preview-member-strip]').waitFor({state:'visible'});
        await tab.locator('[data-dialog="preview"] iframe').waitFor();
        const dialog=await box(tab,'[data-dialog="preview"] section');
        for(const selector of ['[data-action="member-prev"]','[data-action="member-next"]','[data-preview-member-strip]','[data-preview-member-position]']){
            const rect=await box(tab,selector);
            assert.ok(rect.left>=dialog.left-1&&rect.left+rect.width<=dialog.left+dialog.width+1,`${selector} fits the 375px preview`);
        }
        assert.equal(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'no horizontal page scroll');
        await shot(tab,'member-strip-mobile');
    },{width:375,height:812},{isMobile:true,hasTouch:true,deviceScaleFactor:2});
});


// ── Share pages in folder ──────────────────────────────────────────────────────────────────────────────

const openShareFolder=async(tab,{menu='context'}={})=>{
    if(menu==='context')await tab.click('[data-web-collection="9"]',{button:'right'});
    else await tab.tap('[data-web-collection-menu="9"]');
    await tab.waitForSelector('[data-web-collection-menu-open="9"]');
    await tab.click('[data-web-collection-menu-open="9"] [data-action="share-folder"]').catch(()=>tab.tap('[data-web-collection-menu-open="9"] [data-action="share-folder"]'));
    await tab.waitForSelector('dialog[data-dialog="share-folder"] :is([data-share-folder-count],[data-share-folder-state="failed"],[data-share-folder-state="empty"])');
};
// What a viewer sees of a list: whether its last row's text is really shown inside the box (the pixel under it belongs to the list),
// how far above the bottom edge it ends, and whether the "more below" cue is up. Observed, not derived from scroll sizes.
const lastRowView=(tab,selector)=>tab.$eval(selector,list=>{const text=list.lastElementChild.lastElementChild,r=text.getBoundingClientRect(),hit=document.elementFromPoint(r.left+Math.min(r.width,40)/2,r.bottom-1);return {shown:!!hit&&list.contains(hit),room:Math.round(list.getBoundingClientRect().bottom-r.bottom)};});
const cueCount=tab=>tab.$$('[data-share-folder-more]').then(found=>found.length);
const cueMatchesView=async(tab,selector,note)=>{const view=await lastRowView(tab,selector);assert.equal(await cueCount(tab),view.shown?0:1,`${note}: the cue is up exactly while the last row is cut off`);return view;};
const resultStatuses=tab=>tab.$$eval('[data-share-folder-result]',rows=>rows.map(row=>[row.dataset.shareFolderResult,row.dataset.status]));
const fitsWidth=async(tab,selector,width)=>{
    const found=await box(tab,selector);
    assert.ok(found.left>=0&&found.left+found.width<=width,`${selector} fits ${width}px (left ${found.left}, width ${found.width})`);
    assert.equal(await tab.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),true,'the page does not scroll sideways');
};

browserTest('Share pages in folder asks first, shows the count and what is excluded, and Cancel and Escape create nothing',async()=>{
    resetShare(sharePages([1,2,3],{2:{reuse:{expires_at:'2027-01-15T00:00:00+00:00',has_password:true,allow_download:true}}}),{});
    shareFixture.candidates.omitted=0;
    await withPage(async tab=>{
        await openShareFolder(tab);
        assert.equal(shareFixture.candidateGets,1);
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'share-folder-cancel','focus starts on the safe choice');
        assert.equal((await tab.textContent('[data-share-folder-count]')).trim(),'Share 3 pages');
        const explain=await tab.textContent('[data-share-folder-explain]');
        assert.match(explain,/30 days/);assert.match(explain,/sub-folders/);assert.match(explain,/stack files/);
        assert.match(await tab.textContent('[data-share-folder-keep]'),/1 of these already have a link/);
        assert.deepEqual(await tab.$$eval('[data-share-folder-pages] li',items=>items.map(item=>item.querySelector('span').textContent)),['Launch page 1','Launch page 2','Launch page 3']);
        await shot(tab,'desktop-1440-light-share-folder-confirm');
        await tab.click('[data-action="share-folder-cancel"]');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.deepEqual(shareFixture.posts,[],'Cancel creates nothing');
        assert.deepEqual(tab.writes,[]);
        // Escape before the confirmation is the same Cancel, and focus returns to the folder.
        await openShareFolder(tab);
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        assert.deepEqual(shareFixture.posts,[]);
        assert.deepEqual(tab.writes,[],'opening and cancelling twice wrote nothing');
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.webCollectionMenu),'9','focus returns to the folder\'s action button, as for every other dialog opened from its menu');
    });
});

browserTest('Share pages in folder from the keyboard: the visible kebab, Enter on the item, and a confirmed run reports each page',async()=>{
    resetShare(sharePages([1,2,3,4,5]),{});
    shareFixture.answers=[
        {status:200,body:{success:true,share:{token:'tokA'},reused:false}},
        {status:200,body:{success:true,share:{token:'tokB',expires_at:'2027-01-15T00:00:00+00:00',has_password:true},reused:true}},
        {status:409,body:{success:false,reason:'page-changed'}},
        {status:404,body:{message:'gone'}},
        {status:422,body:{message:'bad'}},
    ];
    await withPage(async tab=>{
        await tab.focus('[data-web-collection-menu="9"]');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('[data-web-collection-menu-open="9"]');
        await tab.keyboard.press('ArrowDown');
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'share-folder');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('[data-share-folder-count]');
        await tab.keyboard.press('Tab');
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'share-folder-create');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('[data-share-folder-results] [data-status="not-allowed"]');
        await tab.waitForFunction(()=>!document.querySelector('[data-action="share-folder-stop"]'));
        assert.deepEqual(await resultStatuses(tab),[['1','created'],['2','kept'],['3','changed'],['4','not-allowed'],['5','failed']]);
        assert.deepEqual(shareFixture.posts.map(post=>[post.target_id,post.web_collection_id,post.page_revision.length,post.expiry_days,post.share_type]),[[1,9,64,30,'asset'],[2,9,64,30,'asset'],[3,9,64,30,'asset'],[4,9,64,30,'asset'],[5,9,64,30,'asset']]);
        assert.ok(shareFixture.posts.every(post=>post.public_link_confirmed===true&&!('slug' in post)&&!('password' in post)&&!('name' in post)));
        assert.equal((await tab.textContent('[data-share-folder-progress]')).trim(),'1 new, 1 kept, 3 not shared');
        assert.match(await tab.textContent('[data-share-folder-result="2"]'),/Existing link kept \(expires .*password protected\)/);
        assert.match(await tab.textContent('[data-share-folder-result="3"]'),/changed or moved/);
        assert.equal(await tab.evaluate(()=>document.activeElement?.dataset.action),'share-folder-close');
        // Five rows that all show claim no more below, and the fifth does not sit flush against the border.
        const view=await cueMatchesView(tab,'[data-share-folder-results]','five results');
        assert.equal(view.shown,true,'the fifth result is fully visible');
        assert.equal(await cueCount(tab),0,'a list whose rows all show says nothing');
        assert.ok(view.room>=6,`the fifth row leaves room above the border (${view.room}px)`);
        await shot(tab,'desktop-1440-light-share-folder-partial');
        // Padding-only overflow: shrink the box until the fifth row's text still shows but the box's bottom padding no longer fits. The
        // list can now scroll a few pixels, yet nothing is hidden, so it still says nothing.
        const padded=await tab.$eval('[data-share-folder-results]',list=>{
            const text=list.lastElementChild.lastElementChild.getBoundingClientRect();
            list.style.maxHeight=`${Math.ceil(text.bottom-list.getBoundingClientRect().top)+3}px`;
            list.dispatchEvent(new Event('scroll'));
            return list.scrollHeight>list.clientHeight+2;
        });
        assert.equal(padded,true,'the box really can scroll, by its padding alone');
        await tab.waitForTimeout(150);
        assert.equal((await lastRowView(tab,'[data-share-folder-results]')).shown,true,'the fifth result is still fully visible');
        assert.equal(await cueCount(tab),0,'padding alone is not more pages');
        await tab.keyboard.press('Enter');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
    });
});

// A request the server never answers: the route holds it until the test lets go, long after the viewer has stopped.
browserTest('a request that genuinely never answers is recovered by Stop and by Escape: the page may exist, nothing is retried, and a late answer changes nothing',async()=>{
    for(const way of ['stop','escape']){
        resetShare(sharePages([1,2,3]),{});
        let release;const held=new Promise(resolve=>{release=resolve;});
        shareFixture.gate=async index=>{if(index===0)await held;};
        await withPage(async tab=>{
            await openShareFolder(tab);
            await tab.click('[data-action="share-folder-create"]');
            await tab.waitForSelector('[data-action="share-folder-stop"]');
            await tab.waitForFunction(()=>document.querySelectorAll('[data-share-folder-result]').length===3);
            assert.equal((await tab.textContent('[data-share-folder-progress]')).trim(),'Sharing 0 of 3…');
            assert.equal(await tab.getAttribute('dialog[data-dialog="share-folder"] section','aria-busy'),'true');
            // The page being shared and the ones behind it read differently.
            const labels=await tab.$$eval('[data-share-folder-result]',rows=>rows.map(row=>row.children[1].textContent.trim()));
            assert.deepEqual(labels,['Sharing…','Waiting','Waiting']);
            const emphasis=await tab.$$eval('[data-share-folder-result]',rows=>rows.map(row=>getComputedStyle(row.children[1]).fontWeight));
            assert.ok(Number(emphasis[0])>Number(emphasis[1]),'the current row is visibly stronger than a waiting one');
            if(way==='stop')await shot(tab,'desktop-1440-light-share-folder-progress');
            await tab.waitForTimeout(400);
            assert.equal(shareFixture.posts.length,1,'the request is genuinely pending, not failed');
            // A stray press on the dark backdrop is not a Stop: the page in flight is not abandoned and the run goes on.
            await tab.mouse.click(5,5);
            await tab.waitForTimeout(300);
            assert.ok(await tab.$('[data-action="share-folder-stop"]'),'the run is still going after a press on the backdrop');
            assert.deepEqual(await tab.$$eval('[data-share-folder-result]',rows=>rows.map(row=>row.children[1].textContent.trim())),['Sharing…','Waiting','Waiting']);
            assert.equal(await tab.$('[data-share-folder-stopped]'),null);
            if(way==='stop')await tab.click('[data-action="share-folder-stop"]');
            else{
                await tab.keyboard.press('Escape');
                assert.ok(await tab.$('dialog[data-dialog="share-folder"]'),'Escape never hides a run');
            }
            await tab.waitForSelector('[data-share-folder-stopped]');
            assert.deepEqual(await resultStatuses(tab),[['1','uncertain'],['2','not-attempted'],['3','not-attempted']]);
            assert.match(await tab.textContent('[data-share-folder-result="1"]'),/may exist/);
            assert.equal((await tab.textContent('[data-share-folder-progress]')).trim(),'0 new, 0 kept, 2 not shared, 1 may exist');
            // The banner says only that the run stopped; the one page that may have a link carries its own warning.
            assert.match(await tab.textContent('[data-share-folder-stopped]'),/The run stopped/);
            assert.doesNotMatch(await tab.textContent('[data-share-folder-stopped]'),/when you stopped|you stopped/i);
            if(way==='stop')await shot(tab,'desktop-1440-light-share-folder-stopped');
            // The server answers at last; the run it belonged to has moved on and reads nothing of it.
            release();
            await tab.waitForTimeout(400);
            assert.deepEqual(await resultStatuses(tab),[['1','uncertain'],['2','not-attempted'],['3','not-attempted']]);
            assert.equal(shareFixture.posts.length,1,'no retry and no next page');
            assert.equal(await tab.$('[data-action="share-folder-copy"]'),null,'no link was claimed');
            // Closing and opening again is a clean start.
            await tab.click('[data-action="share-folder-close"]');
            await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
            shareFixture.gate=null;
            await openShareFolder(tab);
            assert.equal((await tab.textContent('[data-share-folder-count]')).trim(),'Share 3 pages');
            await tab.click('[data-action="share-folder-cancel"]');
            await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
            assert.equal(shareFixture.posts.length,1);
        });
    }
});

browserTest('a response whose body never finishes is recovered by Stop and by Escape: the page may exist, nothing is retried, and the late body changes nothing',async()=>{
    for(const way of ['stop','escape']){
        resetShare(sharePages([1,2,3]),{});
        await withPage(async tab=>{
            await openShareFolder(tab);
            // Real fetch semantics for the one link request: the status arrives at once, the body is a stream held open until the test ends it.
            await tab.evaluate(()=>{
                const real=window.fetch.bind(window);
                window.__stall={posts:0,end:null};
                window.fetch=(url,options)=>{
                    if(options?.method!=='POST'||!String(url).includes('shared-links'))return real(url,options);
                    window.__stall.posts++;
                    const body=new ReadableStream({start(controller){window.__stall.end=()=>{try{controller.enqueue(new TextEncoder().encode(JSON.stringify({share:{token:'late'},reused:false})));controller.close();}catch(error){}};}});
                    return Promise.resolve(new Response(body,{status:200,headers:{'Content-Type':'application/json'}}));
                };
            });
            await tab.click('[data-action="share-folder-create"]');
            await tab.waitForSelector('[data-action="share-folder-stop"]');
            await tab.waitForTimeout(400);
            assert.equal(await tab.evaluate(()=>window.__stall.posts),1,'the headers are in and the body is pending, not failed');
            assert.deepEqual(await tab.$$eval('[data-share-folder-result]',rows=>rows.map(row=>row.children[1].textContent.trim())),['Sharing…','Waiting','Waiting']);
            if(way==='stop')await tab.click('[data-action="share-folder-stop"]');
            else await tab.keyboard.press('Escape');
            await tab.waitForSelector('[data-share-folder-stopped]');
            assert.ok(await tab.$('dialog[data-dialog="share-folder"]'),'the stopped result stays open to be read');
            assert.deepEqual(await resultStatuses(tab),[['1','uncertain'],['2','not-attempted'],['3','not-attempted']]);
            assert.match(await tab.textContent('[data-share-folder-result="1"]'),/may exist/);
            await tab.evaluate(()=>window.__stall.end());
            await tab.waitForTimeout(400);
            assert.deepEqual(await resultStatuses(tab),[['1','uncertain'],['2','not-attempted'],['3','not-attempted']]);
            assert.equal(await tab.evaluate(()=>window.__stall.posts),1,'no retry and no next page');
            assert.equal(await tab.$('[data-action="share-folder-copy"]'),null,'no link was claimed from the late body');
            await tab.click('[data-action="share-folder-close"]');
            await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
            await openShareFolder(tab);
            assert.equal((await tab.textContent('[data-share-folder-count]')).trim(),'Share 3 pages');
            await tab.click('[data-action="share-folder-cancel"]');
            await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        });
    }
});

browserTest('a list says there is more only while its last row is cut off: not for rows that all show, and gone at the end of a long one',async()=>{
    await withPage(async tab=>{
        // A short list fits: every row shows and nothing claims more.
        resetShare(sharePages([1,2,3]),{});
        await openShareFolder(tab);
        assert.equal((await cueMatchesView(tab,'[data-share-folder-pages]','three candidates')).shown,true);
        await tab.click('[data-action="share-folder-cancel"]');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        resetShare(sharePages([1,2,3,4,5,6,7,8,9,10,11,12,13,14]),{});
        await openShareFolder(tab);
        assert.equal((await lastRowView(tab,'[data-share-folder-pages]')).shown,false,'fourteen candidates really do not fit');
        assert.match(await tab.textContent('[data-share-folder-more]'),/More pages below/);
        await shot(tab,'desktop-1440-light-share-folder-many-confirm');
        await tab.$eval('[data-share-folder-pages]',list=>{list.scrollTop=list.scrollHeight/3;});
        await tab.waitForTimeout(150);
        assert.equal((await cueMatchesView(tab,'[data-share-folder-pages]','part way down')).shown,false);
        await tab.$eval('[data-share-folder-pages]',list=>{list.scrollTop=list.scrollHeight;});
        await tab.waitForSelector('[data-share-folder-more]',{state:'detached'});
        assert.equal((await cueMatchesView(tab,'[data-share-folder-pages]','at the end')).shown,true,'the last page is fully inside the box once scrolled');
        await tab.click('[data-action="share-folder-create"]');
        await tab.waitForSelector('[data-action="share-folder-close"]');
        // The result list obeys the same rule.
        assert.equal((await cueMatchesView(tab,'[data-share-folder-results]','results, top')).shown,false);
        assert.equal(await cueCount(tab),1,'the cue is there while rows are cut off');
        await shot(tab,'desktop-1440-light-share-folder-many-results');
        await tab.$eval('[data-share-folder-results]',list=>{list.scrollTop=list.scrollHeight;});
        await tab.waitForSelector('[data-share-folder-more]',{state:'detached'});
        assert.equal((await cueMatchesView(tab,'[data-share-folder-results]','results, end')).shown,true);
        await tab.click('[data-action="share-folder-close"]');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
    });
});

browserTest('a kept link shows every option it has before and after the run, and the run changes none of them',async()=>{
    const options={expires_at:null,has_password:true,allow_download:false,requires_creator_access:true};
    resetShare(sharePages([1,2],{1:{reuse:options}}),{});
    shareFixture.answers=[{status:200,body:{success:true,share:{token:'tokKept',expires_at:null,has_password:true,allow_download:false,requires_creator_access:true},reused:true}}];
    await withPage(async tab=>{
        await openShareFolder(tab);
        const text='Existing link kept (no expiry, password protected, downloads off, closes if its creator loses access)';
        assert.equal((await tab.textContent('[data-share-folder-pages] li:first-child span:nth-child(2)')).trim(),text);
        // The long option label wraps under the page name: it is neither cut off at the box edge nor squeezing the name away.
        const fit=await tab.$eval('[data-share-folder-pages]',list=>{const box=list.getBoundingClientRect(),row=list.querySelector('li'),name=row.querySelector('span'),label=row.querySelector('[data-share-folder-kept]');return {sideways:list.scrollWidth>list.clientWidth+1,labelRight:label.getBoundingClientRect().right<=box.right,nameWidth:name.getBoundingClientRect().width,nameCut:name.scrollWidth>name.clientWidth+1};});
        assert.deepEqual([fit.sideways,fit.labelRight,fit.nameCut],[false,true,false],'the kept-options label fits the box without cutting the page name');
        assert.ok(fit.nameWidth>60,'the page name keeps room');
        await shot(tab,'desktop-1440-light-share-folder-kept-options');
        await tab.click('[data-action="share-folder-create"]');
        await tab.waitForSelector('[data-action="share-folder-close"]');
        assert.equal((await tab.textContent('[data-share-folder-result="1"] span:nth-child(2)')).trim(),text);
        assert.deepEqual(shareFixture.posts.map(post=>Object.keys(post).sort()),[['expiry_days','page_revision','public_link_confirmed','share_type','target_id','web_collection_id'],['expiry_days','page_revision','public_link_confirmed','share_type','target_id','web_collection_id']]);
        await tab.click('[data-action="share-folder-close"]');
    });
});

browserTest('a request that fails outright stops the run, is not retried, and says the link may exist',async()=>{
    resetShare(sharePages([1,2,3]),{});
    shareFixture.answers=[{status:200,body:{success:true,share:{token:'tokA'},reused:false}},{abort:true}];
    await withPage(async tab=>{
        await openShareFolder(tab);
        await tab.click('[data-action="share-folder-create"]');
        await tab.waitForSelector('[data-share-folder-stopped]');
        assert.deepEqual(await resultStatuses(tab),[['1','created'],['2','uncertain'],['3','not-attempted']]);
        assert.equal(shareFixture.posts.length,2,'the uncertain write was not repeated and the third page was not tried');
        assert.match(await tab.textContent('[data-share-folder-result="2"]'),/may exist/);
        assert.equal((await tab.textContent('[data-share-folder-progress]')).trim(),'1 new, 0 kept, 1 not shared, 1 may exist');
        await shot(tab,'desktop-1440-light-share-folder-uncertain');
    });
});

browserTest('an unreadable, empty or capped candidate answer says so, and never offers to create',async()=>{
    await withPage(async tab=>{
        resetShare([],{candidatesStatus:500});
        await openShareFolder(tab);
        assert.match(await tab.textContent('[data-share-folder-state="failed"]'),/Nothing was shared/);
        assert.equal(await tab.$('[data-action="share-folder-create"]'),null);
        await shot(tab,'desktop-1440-light-share-folder-failed');
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        resetShare([],{});
        await openShareFolder(tab);
        assert.ok(await tab.$('[data-share-folder-state="empty"]'));
        assert.equal(await tab.$('[data-action="share-folder-create"]'),null);
        await tab.keyboard.press('Escape');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
        resetShare(sharePages([1,2]),{});
        shareFixture.candidates.omitted=7;shareFixture.candidates.total=9;
        await openShareFolder(tab);
        assert.match(await tab.textContent('[data-share-folder-omitted]'),/7 more pages/);
        assert.doesNotMatch(await tab.textContent('[data-share-folder-omitted]'),/again|afterwards|next time/i,'the notice never promises that another run reaches them');
        assert.match(await tab.textContent('[data-share-folder-omitted]'),/Copy share link/);
        assert.match(await tab.textContent('[data-share-folder-omitted]'),/7 more pages in this folder come later by name/,'the omitted pages are said to be the later ones by name, with no name or link revealed');
        await shot(tab,'desktop-1440-light-share-folder-capped');
        assert.deepEqual(tab.writes,[]);
    });
});

browserTest('on a phone, in the dark theme, the confirmation, progress and partial result fit and nothing scrolls sideways',async()=>{
    // The kept link carries every option there is, so its label is the longest the dialog ever shows.
    const longest={expires_at:null,has_password:true,allow_download:false,requires_creator_access:true};
    resetShare(sharePages([1,2,3,4],{2:{reuse:longest}}),{});
    shareFixture.answers=[{status:200,body:{success:true,share:{token:'tokA'},reused:false}},{status:200,body:{success:true,share:{token:'tokB',...longest},reused:true}},{status:409,body:{reason:'page-changed'}}];
    const longestLabel='Existing link kept (no expiry, password protected, downloads off, closes if its creator loses access)';
    // The long label stays whole inside its box, beside or under a page name that is itself whole, with nothing scrolling sideways.
    const keptFits=async(tab,rowSelector)=>{
        const fit=await tab.$eval(rowSelector,row=>{const list=row.closest('ul'),box=list.getBoundingClientRect(),spans=[...row.querySelectorAll('span')],name=spans[0],label=spans[spans.length-1];return {text:label.textContent.trim(),sideways:list.scrollWidth>list.clientWidth+1,labelInside:label.getBoundingClientRect().left>=box.left&&label.getBoundingClientRect().right<=box.right,labelCut:label.scrollWidth>label.clientWidth+1,nameCut:name.scrollWidth>name.clientWidth+1,nameWidth:name.getBoundingClientRect().width};});
        assert.equal(fit.text,longestLabel);
        assert.deepEqual([fit.sideways,fit.labelInside,fit.labelCut,fit.nameCut],[false,true,false,false],`${rowSelector} at 375px: the long label and the page name both fit`);
        assert.ok(fit.nameWidth>60,'the page name keeps room');
    };
    let release;const held=new Promise(resolve=>{release=resolve;});
    shareFixture.gate=async index=>{if(index===3)await held;};
    await withPage(async tab=>{
        await tab.evaluate(()=>document.documentElement.classList.add('dark'));
        await tab.tap('[data-web-collection-menu="9"]');
        await tab.waitForSelector('[data-web-collection-menu-open="9"]');
        await shot(tab,'mobile-375-dark-share-folder-menu');
        await tab.tap('[data-web-collection-menu-open="9"] [data-action="share-folder"]');
        await tab.waitForSelector('[data-share-folder-count]');
        await shot(tab,'mobile-375-dark-share-folder-confirm');
        await fitsWidth(tab,'dialog[data-dialog="share-folder"] section',375);
        await keptFits(tab,'[data-share-folder-pages] li:nth-child(2)');
        await tab.tap('[data-action="share-folder-create"]');
        await tab.waitForSelector('[data-action="share-folder-stop"]');
        await tab.waitForSelector('[data-share-folder-result="3"][data-status="changed"]');
        await shot(tab,'mobile-375-dark-share-folder-progress');
        await fitsWidth(tab,'dialog[data-dialog="share-folder"] section',375);
        release();
        await tab.waitForSelector('[data-action="share-folder-close"]');
        await shot(tab,'mobile-375-dark-share-folder-partial');
        await fitsWidth(tab,'dialog[data-dialog="share-folder"] section',375);
        await keptFits(tab,'[data-share-folder-result="2"]');
        await tab.tap('[data-action="share-folder-close"]');
        await tab.waitForSelector('dialog[data-dialog]',{state:'detached'});
    },{width:375,height:800},{hasTouch:true,isMobile:true});
});
