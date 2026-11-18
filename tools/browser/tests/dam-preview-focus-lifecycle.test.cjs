'use strict';

// The real focus helper shared by both lightboxes (preview-modal/focus-script.blade.php), mounted next to a
// stand-in for the Share dialog that opens above the lightbox and owns focus while it is up.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..', '..', '..');
const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/focus-script.blade.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\{\{--[\s\S]*?--\}\}/g, '');

function mount({shareOpen = () => false} = {}) {
    const listeners = {};
    const focusCalls = [];
    const inside = new Set();
    const first = {name: 'first', focus: () => focusCalls.push('first'), getClientRects: () => [1]};
    const dialog = {
        focus: () => focusCalls.push('dialog'),
        contains: (el) => inside.has(el),
        querySelectorAll: () => [first],
    };
    inside.add(first);
    const body = {};
    const document = {
        body,
        activeElement: body,
        querySelector: () => dialog,
        addEventListener: (type, fn) => { listeners[type] = fn; },
        removeEventListener: (type) => { delete listeners[type]; },
    };
    const context = {window: {damShareDialogOpen: shareOpen}, document, getComputedStyle: () => ({visibility: 'visible'})};
    vm.createContext(context);
    vm.runInContext(script, context);
    const state = {isOpen: true, ...context.window._damPreviewFocus.methods};
    state.startPreviewFocusGuard();

    return {state, listeners, focusCalls};
}

test('focus that leaves the open lightbox is pulled back inside it', () => {
    const {listeners, focusCalls} = mount();
    listeners.focusin({target: {name: 'page link'}});
    assert.deepEqual(focusCalls, ['first']);
});

test('the guard stands down while the Share dialog is up, and resumes once it is gone', () => {
    let share = true;
    const {listeners, focusCalls} = mount({shareOpen: () => share});
    listeners.focusin({target: {name: 'share field'}});
    assert.deepEqual(focusCalls, [], 'focus inside the Share dialog is left alone');
    share = false;
    listeners.focusin({target: {name: 'page link'}});
    assert.deepEqual(focusCalls, ['first']);
});

test('stopping the guard removes its listener', () => {
    const {state, listeners} = mount();
    state.stopPreviewFocusGuard();
    assert.equal(listeners.focusin, undefined);
});

const navigation = require(path.join(root, 'packages/Mothership/DAM/src/Resources/assets/js/preview-navigation.js'));

test('origin restores its connected trigger and scroll; removed Files trigger falls back to its panel', () => {
    const calls = [];
    const trigger = {isConnected: true, tagName: 'BUTTON', tabIndex: 0, focus: () => calls.push('trigger')};
    const panel = {tagName: 'DIV', setAttribute: (key, value) => calls.push([key, value]), focus: () => calls.push('panel')};
    const doc = {querySelector: selector => selector === '[data-dam-files-panel]' ? panel : null};
    const win = {scrollTo: ({top}) => calls.push(top)};
    const origin = {kind: 'files', assetId: 7, fileId: 2, trigger, scrollTop: 380};
    navigation.restoreOrigin({origin, document: doc, window: win});
    assert.deepEqual(calls, [380, 'trigger']);
    trigger.isConnected = false;
    navigation.restoreOrigin({origin, document: doc, window: win});
    assert.deepEqual(calls.slice(2), [380, ['tabindex', '-1'], 'panel']);
});

test('removed chooser trigger falls back to its listbox without changing the asset', () => {
    let focused = false;
    const list = {tagName: 'DIV', hasAttribute: () => true, focus: () => { focused = true; }};
    assert.equal(navigation.restoreOrigin({origin: {kind: 'chooser', assetId: 7, fileId: 20, trigger: null}, document: {querySelector: selector => selector.includes('listbox') ? list : null}, window: {}}), true);
    assert.equal(focused, true);
});

test('body, documentElement and connected nonfocusable origins use the Files heading', () => {
    const calls = [];
    const body = {isConnected: true, tabIndex: -1, focus: () => calls.push('body')};
    const html = {isConnected: true, tabIndex: -1, focus: () => calls.push('html')};
    const heading = {tagName: 'H2', hasAttribute: () => true, focus: () => calls.push('heading')};
    const nonfocusable = {isConnected: true, tabIndex: -1, hasAttribute: () => false, focus: () => calls.push('div')};
    const document = {body, documentElement: html, querySelector: () => heading};
    for (const trigger of [body, html, nonfocusable]) navigation.restoreOrigin({origin: {kind: 'files', trigger}, document, window: {}});
    assert.deepEqual(calls, ['heading', 'heading', 'heading']);
});

const playwrightPath = process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH;
const browserTest = playwrightPath ? test : test.skip;
browserTest('real preview history closes preserve the chooser beyond AJAX remount timing and leave page history operational', async () => {
    const {launchBrowser} = require('../launch-browser.cjs');
    const browser = await launchBrowser({playwrightPath});
    const grid = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/grid-preview-modal.blade.php'), 'utf8');
    const method = (name, next) => grid.slice(grid.indexOf(`                ${name}(`), grid.indexOf(`                ${next}(`)).trim().replace(/,$/, '');
    const methods = [method('syncPreviewHistory','_formatTime'), method('restorePreviewOrigin','closePreview'), method('closePreview','handleEscape')].join(',');
    const escape = grid.match(/\n {16}handleEscape\(e\) \{[\s\S]*?\n {16}\},/)[0].trim().replace(/,$/,'');
    const pop = grid.slice(grid.indexOf('                // An open preview is a history entry'), grid.indexOf('                this.imgMounted();'));
    const explorerSource = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/components/explorer/index.blade.php'), 'utf8');
    const syncLocation = explorerSource.slice(explorerSource.indexOf('        syncLocation('), explorerSource.indexOf('        onLabelChange(')).trim().replace(/,$/, '');
    const explorerPop = explorerSource.slice(explorerSource.indexOf('        this._popStateHandler ='), explorerSource.indexOf('    beforeUnmount()')).replace(/    },\s*$/, '');
    const navSource = fs.readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/plugins/navigation.js'), 'utf8');
    const constants = fs.readFileSync(path.join(root, 'packages/Mothership/Admin/src/Resources/assets/js/constants.js'), 'utf8');
    try {
        for (const boot of ['hard','ajax']) for (const {close,flow} of [...['button','Escape','Back'].map(close=>({close,flow:'normal'})), ...['folder','similarity','foreignOwner','reopen'].map(flow=>({close:'button',flow})), ...['refetchOpen','refetchLoading'].flatMap(flow=>['button','Escape','Back'].map(close=>({close,flow}))), ...['button','Back'].map(close=>({close,flow:'similarityOpen'})), ...['refetchSimilarityOpen','refetchSimilarityLoading'].flatMap(flow=>['button','Back'].map(close=>({close,flow})))]) {
            const page = await browser.newPage();
            const errors = [], visits = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('http://history.test/**', route => {
                const request = route.request(), pathname = new URL(request.url()).pathname;
                if (pathname === '/navigation.js') return route.fulfill({contentType:'text/javascript',body:navSource});
                if (pathname === '/constants') return route.fulfill({contentType:'text/javascript',body:constants});
                if (request.headers()['x-ajax-nav']) visits.push(pathname);
                return route.fulfill({contentType:'text/html',body:'<div id="app"><p>Destination page</p></div>'});
            });
            await page.goto('http://history.test/admin/dam/assets');
            await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH || path.join(root,'packages/Mothership/DAM/node_modules/vue/dist/vue.global.js')});
            await page.addScriptTag({path:path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/preview-navigation.js')});
            await page.addScriptTag({path:path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/library-navigation.js')});
            await page.evaluate(async ({methods, escape, pop, boot, flow, syncLocation, explorerPop}) => {
                history.replaceState({ajaxNav:true},'', '/admin/previous');
                history.pushState({damSimilarity:{assetId:2},damLibraryView:{}},'', flow==='normal'?'/admin/dam/assets':flow.startsWith('refetchSimilarity')?'/admin/dam/assets?scope=all&similar_to=7&similar_name=Seed':'/admin/dam/assets?scope=all');
                const noop = () => {};
                const app = Vue.createApp({
                    template:'<div data-dam-stack-fan><button data-member="5" @click="open">Member five</button></div><div v-if="isOpen" data-dam-preview-backdrop><button data-dam-preview-close @click="closePreview">Close preview</button><button data-find-similar @click="findSimilar">Find similar</button></div>',
                    data:()=>({isOpen:false,isLoading:false,_previewRequestGeneration:0,previewData:{id:7},previewOrigin:null}),
                    watch:{isOpen(open){this.syncPreviewHistory(open);}},
                    methods:{...new Function(`return ({${methods},${escape}})`)(),
                        open(event){this.previewOrigin={kind:'chooser',assetId:7,fileId:5,trigger:event.currentTarget,scrollTop:0};this.isOpen=true;},
                        findSimilar(){const tab=window.explorer.tabs[0];tab.similarity={assetId:8,name:'Similar',priorView:{page:1}};window.explorer.syncLocation(tab);},
                        videoStopOnClose:noop,audioStopOnClose:noop,pdfDestroy:noop,panoDestroy:noop,resetTextPreview:noop,restorePreviewFocus:noop,restoreInfoPane:noop,
                    },
                    beforeUnmount(){this._unregisterPreviewHistory?.();window.removeEventListener('keydown',this.handleEscape);window.removeEventListener('dam:preview-history-retired',this._retirePreviewHistory);},
                });
                app.config.globalProperties.$emitter={emit:noop};
                window.app=app;
                window.state=app.mount('#app');
                // Hard loads register both components before the router; AJAX remounts register after it.
                window.restoredPlans=[];
                window.explorer={...new Function(`return ({${syncLocation}})`)(),_locationSeeded:true,activeTabId:'tab',tabs:[{id:'tab',scope:'all',directoryId:null,similarity:flow.startsWith('refetchSimilarity')?{assetId:7,name:'Seed'}:null}],$emitter:{emit:(event,plan)=>window.restoredPlans.push(plan)}};
                const mountHistory=()=>{new Function(explorerPop).call(window.explorer);new Function(pop).call(window.state);};
                if(boot==='hard') mountHistory();
                window.createAdminApp=()=>{window.app={mount(){},unmount(){}};};
                (await import('/navigation.js')).default();
                if(boot==='ajax') mountHistory();
                window.backCalls=0;
                const nativeBack=history.back.bind(history);history.back=()=>{window.backCalls++;nativeBack();};
                window.completedVisits=0;
                document.addEventListener('mothership:navigate:success',()=>window.completedVisits++);
                window.otherHistoryCalls=0;
                window.mothership.registerLocalHistoryHandler(()=>{window.otherHistoryCalls++;return false;});
                window.addEventListener('keydown',window.state.handleEscape);
            }, {methods,escape,pop,boot,flow,syncLocation,explorerPop});
            const member = page.locator('[data-member="5"]');
            await member.click();
            await page.waitForFunction(()=>history.state?.damPreview===true);
            if(flow.startsWith('refetch')) {
                const before=await page.evaluate(()=>({length:history.length,owner:history.state.damPreviewOwner}));
                await page.evaluate(async loading=>{
                    if(loading){window.state.isLoading=true;window.state.isOpen=false;await Vue.nextTick();}
                    window.explorer.syncLocation(window.explorer.tabs[0]);
                },flow.endsWith('Loading'));
                assert.equal(await page.evaluate(()=>history.state?.damPreview),true,`${boot}/${flow}: background sync preserves active entry`);
                assert.equal(await page.evaluate(()=>history.state.damPreviewOwner),before.owner,'background sync retains the same owner');
                if(flow.endsWith('Loading')&&close!=='Back') {
                    await page.evaluate(async()=>{window.state.isLoading=false;window.state.isOpen=true;await Vue.nextTick();});
                }
                if(close==='button')await page.locator('[data-dam-preview-close]').click();
                else if(close==='Escape')await page.keyboard.press('Escape');
                else await page.goBack();
                await page.waitForFunction(()=>!history.state?.damPreview);
                await page.waitForTimeout(800);
                assert.equal(await page.evaluate(()=>history.length),before.length,'background sync/open completion creates no duplicate history entry');
                assert.equal(await page.evaluate(()=>window.backCalls),close==='Back'?0:1,'Close/Escape takes exactly one owned Back');
                assert.equal(await page.evaluate(()=>window.state.isOpen||window.state.isLoading),false,'Back cancels any loading preview');
                assert.equal(await page.locator('[data-dam-stack-fan]').count(),1);
                assert.equal(await member.evaluate(el=>el===document.activeElement),true,'background sync close restores member focus');
                assert.deepEqual(visits,[],'background sync never hands owned Back to AJAX router');
                assert.deepEqual(errors,[]);
                await page.close();
                continue;
            }
            if(flow==='similarityOpen') {
                await page.locator('[data-find-similar]').click();
                assert.equal(await page.evaluate(()=>Object.hasOwn(history.state,'damPreview')||Object.hasOwn(history.state,'damPreviewOwner')),false,'open Find similar retires both markers');
                if(close==='button') {
                    await page.locator('[data-dam-preview-close]').click();
                    await page.waitForFunction(()=>!window.state.isOpen);
                    assert.equal(await page.evaluate(()=>window.backCalls),0,'deferred close cannot Back over similarity');
                }
                await page.goBack();
                await page.waitForFunction(()=>window.completedVisits===1);
                assert.equal(new URL(page.url()).searchParams.has('similar_to'),false);
                assert.ok(await page.evaluate(()=>window.restoredPlans.some(plan=>plan.kind==='all'&&!plan.view.similarity)),'open Find similar Back restores listing');
                assert.deepEqual(errors,[]);
                await page.close();
                continue;
            }
            if(close==='button')await page.locator('[data-dam-preview-close]').click();
            else if(close==='Escape')await page.keyboard.press('Escape');
            else await page.goBack();
            await page.waitForFunction(()=>!history.state?.damPreview);
            await page.waitForTimeout(800);
            assert.equal(await page.locator('[data-dam-stack-fan]').count(),1,`${close}: preview close must not remount the Library`);
            assert.equal(await member.evaluate(el=>el===document.activeElement),true,`${close}: selected member must retain focus`);
            assert.deepEqual(visits,[],`${close}: preview-only history must not fetch the page`);
            assert.deepEqual(await page.evaluate(()=>history.state),{damSimilarity:{assetId:2},damLibraryView:{}},'underlying Library/similarity history is preserved');
            await page.goForward();
            await page.waitForTimeout(800);
            assert.equal(await page.locator('[data-dam-stack-fan]').count(),1,`${close}: Forward retains the chooser`);
            assert.equal(await page.locator('[data-dam-preview-backdrop]').count(),0,'Forward preserves the closed chooser rather than resurrecting stale media');
            assert.equal(await member.evaluate(el=>el===document.activeElement),true,'Forward retains selected member focus');
            assert.deepEqual(visits,[],'owned Forward must not fetch the page');
            if(flow!=='normal') {
                if(flow==='folder') {
                    await page.evaluate(()=>{
                        const tab=window.explorer.tabs[0];Object.assign(tab,{scope:'folder',directoryId:9});
                        window.explorer.syncLocation(tab);
                    });
                    const folderUrl=page.url();
                    assert.equal(await page.evaluate(()=>Object.hasOwn(history.state,'damPreview')||Object.hasOwn(history.state,'damPreviewOwner')),false,'folder state never inherits preview markers');
                    await member.click();
                    await page.waitForFunction(()=>history.state?.damPreview===true);
                    await page.locator('[data-dam-preview-close]').click();
                    await page.waitForFunction(()=>!history.state?.damPreview);
                    await page.waitForTimeout(800);
                    assert.equal(page.url(),folderUrl,'closing a new-folder preview retains that folder');
                    assert.deepEqual(visits,[],'folder preview close stays local');
                    assert.equal(await page.locator('[data-dam-stack-fan]').count(),1);
                } else if(flow==='similarity') {
                    await page.evaluate(()=>{
                        const tab=window.explorer.tabs[0];tab.similarity={assetId:8,name:'Similar',priorView:{page:1}};
                        window.explorer.syncLocation(tab);
                    });
                    assert.equal(await page.evaluate(()=>Object.hasOwn(history.state,'damPreview')||Object.hasOwn(history.state,'damPreviewOwner')),false,'similarity replacement retires both preview markers');
                    await page.goBack();
                    await page.waitForFunction(()=>window.completedVisits===1);
                    assert.equal(new URL(page.url()).searchParams.has('similar_to'),false,'Back restores folder URL');
                    assert.ok(await page.evaluate(()=>window.restoredPlans.some(plan=>plan.kind==='all'&&!plan.view.similarity)),'explorer listener restores folder view even when router registered first');
                } else if(flow==='foreignOwner') {
                    await page.evaluate(()=>history.replaceState({...history.state,damPreviewOwner:'foreign'},'',location.href));
                    await page.goBack();
                    await page.waitForFunction(()=>!history.state?.damPreview);
                    // Returning Forward to an entry whose owner changed must route, not claim this viewer.
                    await page.goForward();
                    await page.waitForFunction(()=>window.completedVisits===1);
                    assert.deepEqual(visits,['/admin/dam/assets'],'foreign-owner preview reaches router');
                } else if(flow==='reopen') {
                    await member.click();
                    await page.waitForFunction(()=>window.state.isOpen===true);
                    await page.locator('[data-dam-preview-close]').click();
                    await page.waitForFunction(()=>!history.state?.damPreview);
                    await page.waitForTimeout(800);
                    assert.deepEqual(visits,[],'reopening Forward entry reuses only its own URL entry');
                    assert.equal(await page.locator('[data-dam-stack-fan]').count(),1);
                    assert.equal(await member.evaluate(el=>el===document.activeElement),true);
                }
                assert.deepEqual(errors,[],`${boot}/${flow}: no browser errors`);
                await page.close();
                continue;
            }
            await page.goBack();
            await page.waitForFunction(()=>!history.state?.damPreview);
            await page.goForward();
            await page.waitForFunction(()=>history.state?.damPreview===true);
            await page.waitForTimeout(800);
            assert.equal(await page.locator('[data-dam-stack-fan]').count(),1,'repeated Back/Forward retains chooser');
            assert.deepEqual(visits,[],'repeated owned transitions do not visit');
            await page.goBack();
            await page.waitForFunction(()=>!history.state?.damPreview);
            // Once the preview entry is gone, a different owner's same-URL similarity entry must reach the router.
            await page.evaluate(()=>history.pushState({damSimilarity:{assetId:3}},'',location.href));
            await page.goBack();
            await page.waitForFunction(()=>window.completedVisits===1);
            assert.deepEqual(visits,['/admin/dam/assets'],'foreign same-page history is not swallowed by the viewer');
            await page.goBack();
            await page.waitForFunction(()=>location.pathname==='/admin/previous');
            await page.waitForFunction(()=>window.completedVisits===2);
            await page.goForward();
            await page.waitForFunction(()=>location.pathname==='/admin/dam/assets');
            await page.waitForFunction(()=>window.completedVisits===3);
            assert.deepEqual(visits,['/admin/dam/assets','/admin/previous','/admin/dam/assets'],'true page Back/Forward still invokes the router');
            assert.equal(await page.evaluate(()=>window.otherHistoryCalls),3,'registration and viewer unmount preserve the other history owner');
            assert.deepEqual(errors,[]);
            await page.close();
        }
    } finally { await browser.close(); }
});
