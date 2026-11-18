const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'../../..');
const source=file=>fs.readFileSync(path.join(root,file),'utf8');
const base='packages/Mothership/DAM/src/';
const fileOps=require(path.join(root,base+'Resources/assets/js/library-file-ops.js'));

// Same Blade-to-vm transform library-navigation.test.cjs uses, so this file can load the tree
// component's real addedItems() method rather than a reimplementation of it.
function blade(source){
    let result=source.replace(/@js\(/g,'@json(');
    let pos;
    while((pos=result.indexOf('@json('))!==-1){
        let depth=1,index=pos+6,quote=null;
        for(;index<result.length&&depth;index++){
            const c=result[index];
            if(quote){if(c==='\\')index++;else if(c===quote)quote=null;}
            else if(c==='"'||c==="'")quote=c;else if(c==='(')depth++;else if(c===')')depth--;
        }
        result=result.slice(0,pos)+'null'+result.slice(index);
    }
    return result.replace(/@\{\{/g,'{{').replace(/\{\{\s*(?:route|config)[\s\S]*?\}\}/g,match=>'/'+(match.match(/(?:route|config)\(['"]([^'"]+)/)?.[1]||'fixture')+'/:id')
        .replace(/@lang\('[^']*'\)/g,'Translation').replace(/^\s*@(if|elseif|else|endif|php|endphp).*$/gm,'');
}
function modules(file){return [...source(file).matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map(match=>blade(match[1]));}
function component(file,name){
    const components={};
    const context={app:{component:(name,value)=>components[name]=value},window:{MothershipFileOps:fileOps},URL,URLSearchParams,console,setTimeout,clearTimeout};
    vm.createContext(context);for(const code of modules(file))vm.runInContext(code,context);
    return {definition:components[name],context};
}
const treeFile=base+'Resources/views/components/tree/damdirectories.blade.php';

test('movePreviewQuery serializes ids with bracket notation, never axios\'s bare-key array repetition', () => {
    const qs = fileOps.movePreviewQuery({type: 'asset', ids: [1, 2, 3], target: 9});
    assert.equal(qs, 'type=asset&ids%5B%5D=1&ids%5B%5D=2&ids%5B%5D=3&target=9');
});

test('confirmMovePreview never asks for confirmation when the preview count is zero', async () => {
    let asked = 0;
    const axios = {get: async () => ({data: {folders: 0, files: 0}})};
    const proceeded = await fileOps.confirmMovePreview({
        axios, previewUrl: '/preview', type: 'directory', ids: [5], target: 9,
        confirmMove: () => { asked++; return true; },
    });
    assert.equal(proceeded, true);
    assert.equal(asked, 0);
});

test('confirmMovePreview surfaces the summed count to confirmMove and honours a decline', async () => {
    const axios = {get: async () => ({data: {folders: 1, files: 2}})};
    let seenCount = null;
    const proceeded = await fileOps.confirmMovePreview({
        axios, previewUrl: '/preview', type: 'directory', ids: [5], target: 9,
        confirmMove: (count) => { seenCount = count; return false; },
    });
    assert.equal(seenCount, 3);
    assert.equal(proceeded, false);
});

test('confirmMovePreview throws when the preview request itself fails, rather than proceeding unconfirmed (Codex review of PR #75, finding 6)', async () => {
    const axios = {get: async () => { throw new Error('network'); }};

    await assert.rejects(
        fileOps.confirmMovePreview({
            axios, previewUrl: '/preview', type: 'asset', ids: [5], target: 9,
            confirmMove: () => false,
        }),
        /network/,
    );
});

test('guardedMovePreview catches a preview failure, reports it and never proceeds (Codex review of PR #75, finding 6)', async () => {
    const axios = {get: async () => { throw new Error('network'); }};
    let reported = null;

    const proceeded = await fileOps.guardedMovePreview({
        axios, previewUrl: '/preview', type: 'asset', ids: [5], target: 9,
        confirmMove: () => true,
        onPreviewFailed: (error) => { reported = error; },
    });

    assert.equal(proceeded, false);
    assert.equal(reported.message, 'network');
});

test('guardedMovePreview refuses locally past MOVE_PREVIEW_MAX_IDS, with no network call at all', async () => {
    let called = false;
    const axios = {get: async () => { called = true; return {data: {folders: 0, files: 0}}; }};
    let reported = null;

    const proceeded = await fileOps.guardedMovePreview({
        axios, previewUrl: '/preview', type: 'asset',
        ids: Array.from({length: fileOps.MOVE_PREVIEW_MAX_IDS + 1}, (_, i) => i + 1),
        target: 9,
        confirmMove: () => true,
        onPreviewFailed: (error) => { reported = error; },
    });

    assert.equal(proceeded, false);
    assert.equal(called, false);
    assert.ok(reported instanceof Error);
});

test('moveAssets skips the move POST entirely when the preview confirmation is declined', async () => {
    let posted = false;
    const axios = {
        get: async () => ({data: {folders: 0, files: 1}}),
        post: async () => { posted = true; },
    };
    const result = await fileOps.moveAssets({
        axios, url: '/move', statusUrl: '/status', ids: [1], targetId: 2,
        previewUrl: '/preview', confirmMove: () => false,
    });
    assert.equal(result, null);
    assert.equal(posted, false);
});

test('moveAssets posts and polls as before when no previewUrl/confirmMove is given (grants parity)', async () => {
    let posted = false;
    const axios = {
        post: async () => { posted = true; },
        get: async () => ({data: {status: 'completed'}}),
    };
    const result = await fileOps.moveAssets({axios, url: '/move', statusUrl: '/status', ids: [1], targetId: 2});
    assert.equal(posted, true);
    assert.equal(result.status, 'completed');
});

test("the tree's addedItems() restores the folder snapshot and posts nothing when the preview is declined", async () => {
    const {definition} = component(treeFile, 'v-dam-tree-view');
    let posted = false;
    let restored = 0;
    const state = {
        ...definition.methods,
        damAccessLists: true,
        $axios: {
            get: async () => ({data: {folders: 0, files: 1}}),
            post: async () => { posted = true; },
        },
        $emitter: {emit: (event, payload) => { if (event === 'open-confirm-modal') payload.disagree(); }},
        restoreFolderTreeSnapshot: () => restored++,
    };
    await state.addedItems({id: 2, name: 'Moved'}, 1, 'directory');
    assert.equal(posted, false);
    assert.equal(restored, 1);
});

test("the tree's addedItems() posts the move once the preview confirmation is agreed", async () => {
    const {definition} = component(treeFile, 'v-dam-tree-view');
    let posted = false;
    const state = {
        ...definition.methods,
        damAccessLists: true,
        isLoading: false, actionStatus: null, moveStatusLabel: '', movingDirectoryId: null,
        $axios: {
            get: async () => ({data: {folders: 0, files: 1}}),
            post: async () => { posted = true; return {data: {message: 'ok'}}; },
        },
        $emitter: {emit: (event, payload) => { if (event === 'open-confirm-modal') payload.agree(); }},
        invalidateAllAssetCaches: () => {},
        // Real code calls this 1s after a successful directory move via setTimeout; stubbed so the
        // real method's own polling (which this test's minimal $axios stub can never satisfy) does not
        // keep rescheduling itself and hang the process well past this test's own assertions.
        checkActionStatus: () => {},
    };
    await state.addedItems({id: 2, name: 'Moved'}, 1, 'directory');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(posted, true);
});

test('addedItems() never calls the preview endpoint under `grants` (damAccessLists false)', async () => {
    const {definition} = component(treeFile, 'v-dam-tree-view');
    let previewCalled = false;
    let posted = false;
    const state = {
        ...definition.methods,
        damAccessLists: false,
        isLoading: false, actionStatus: null, moveStatusLabel: '', movingDirectoryId: null,
        $axios: {
            get: async () => { previewCalled = true; return {data: {}}; },
            post: async () => { posted = true; return {data: {message: 'ok'}}; },
        },
        $emitter: {emit: () => {}},
        invalidateAllAssetCaches: () => {},
    };
    await state.addedItems({id: 2, file_name: 'a.jpg'}, 1, 'asset');
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(previewCalled, false);
    assert.equal(posted, true);
});
