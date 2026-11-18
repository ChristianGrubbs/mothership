const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const sourcePath = path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/assets/js/home-state.js');
function state() {
    const context = { window: {} };
    vm.runInNewContext(fs.existsSync(sourcePath) ? fs.readFileSync(sourcePath, 'utf8') : '', context);
    return context.window.MothershipHomeState;
}
test('reordering keeps settings and does not mutate the saved layout', () => {
    const api = state();
    assert.ok(api, 'Home state API exists');
    const widgets = [{id:'recent',settings:{limit:6}}, {id:'rss',settings:{}}];
    const moved = api.move({ widgets, id: 'rss', target: 0 });
    assert.equal(moved[0].id, 'rss');
    assert.equal(widgets[0].id, 'recent');
    assert.equal(moved[1].settings.limit, 6);
});
test('collapse retains a tool session while explicit discard removes it', () => {
    const api = state();
    assert.ok(api, 'Home state API exists');
    const sessions = api.sessions();
    const first = sessions.open('looper');
    sessions.markDirty('looper', true);
    sessions.collapse();
    assert.equal(sessions.active, null);
    assert.equal(sessions.open('looper'), first);
    assert.equal(sessions.hasDirty(), true);
    sessions.discard('looper');
    assert.equal(sessions.hasDirty(), false);
    assert.notEqual(sessions.open('looper'), first);
});
test('bridge rejects messages from a different frame or origin', () => {
    const api = state();
    assert.ok(api, 'Home state API exists');
    const frame = {};
    const base = { origin: 'https://assets.example', frame };
    assert.equal(api.acceptMessage({ ...base, event: {origin:base.origin,source:{},data:{protocol:'mothership-widget/v1'}} }), false);
    assert.equal(api.acceptMessage({ ...base, event: {origin:'https://else.example',source:frame,data:{protocol:'mothership-widget/v1'}} }), false);
    assert.equal(api.acceptMessage({ ...base, event: {origin:base.origin,source:frame,data:{protocol:'mothership-widget/v1'}} }), true);
    assert.equal(api.acceptMessage({ ...base, event: {origin:base.origin,source:frame,data:{protocol:'other-tool/v1'}} }), false);
});

test('legacy widths normalize once to version 2 spans without mutating the saved widget', () => {
    const api = state();
    const widget = {id:'rss',size:'large',settings:{feedId:12,count:8}};
    assert.equal(JSON.stringify(api.dimensions(widget)), JSON.stringify({grid_version:2,columns:8,rows:2}));
    assert.equal(widget.columns, undefined);
    assert.equal(JSON.stringify(api.resize({widget, columns:9, rows:0})), JSON.stringify({id:'rss',size:'large',settings:{feedId:12,count:8},grid_version:2,columns:8,rows:1}));
});

test('grid packing fills an available hole deterministically', () => {
    const api = state();
    const placements = api.pack([
        {id:'a',grid_version:2,columns:3,rows:1},
        {id:'b',grid_version:2,columns:2,rows:1},
        {id:'c',grid_version:2,columns:1,rows:1},
    ], 4);
    assert.deepEqual(placements.map(({id,column,row}) => ({id,column,row})), [
        {id:'a',column:1,row:1},
        {id:'b',column:1,row:2},
        {id:'c',column:4,row:1},
    ]);
});

test('narrow viewport packing clamps saved columns without consulting the legacy size', () => {
    const api = state();
    const [tablet] = api.pack([{id:'a',grid_version:2,size:'small',columns:3,rows:2}], 4);
    const [phone] = api.pack([{id:'a',grid_version:2,size:'large',columns:3,rows:2}], 1);
    assert.equal(JSON.stringify({columns:tablet.columns,rows:tablet.rows,column:tablet.column}), JSON.stringify({columns:3,rows:2,column:1}));
    assert.equal(JSON.stringify({columns:phone.columns,rows:phone.rows,column:phone.column}), JSON.stringify({columns:1,rows:2,column:1}));
});

test('Signal refresh state deduplicates requests, pauses while hidden and backs off after failure', async () => {
    const api = state();
    let now = 1_000, calls = 0, visible = true;
    let release;
    const request = () => {
        calls++;
        if (calls === 2) return Promise.reject(new Error('offline'));
        return new Promise(resolve => { release = resolve; });
    };
    const poller = api.poller({request, now:()=>now, visible:()=>visible, interval:60_000, maxBackoff:240_000});
    const first = poller.refresh();
    assert.equal(poller.refresh(), first);
    await Promise.resolve();
    release({stories:[{id:'one'}]});
    await first;
    visible = false; now += 61_000;
    assert.equal(await poller.tick(), null);
    assert.equal(calls, 1);
    visible = true;
    await assert.rejects(poller.tick(), /offline/);
    assert.equal(calls, 2);
    assert.equal(poller.snapshot().retryAt, now + 120_000);
});

test('Signal view state retains offline status across renders and isolates feed identities', () => {
    const store=state().signalState();
    const feedA=store.select('12:5');
    store.success({key:'12:5',generation:feedA,stories:[{id:'a'}],refreshedAt:1000});
    store.failure({key:'12:5',generation:feedA,error:new Error('offline')});
    assert.equal(JSON.stringify(store.snapshot()),JSON.stringify({key:'12:5',stories:[{id:'a'}],refreshedAt:1000,error:'offline'}));
    assert.equal(store.select('12:5'),feedA);
    const feedB=store.select('44:3');
    assert.equal(store.success({key:'12:5',generation:feedA,stories:[{id:'late-a'}],refreshedAt:2000}),false);
    assert.equal(JSON.stringify(store.snapshot()),JSON.stringify({key:'44:3',stories:[],refreshedAt:0,error:null}));
    store.success({key:'44:3',generation:feedB,stories:[{id:'b'}],refreshedAt:3000});
    assert.equal(store.snapshot().stories[0].id,'b');
});

test('Signal feed settings preserve selection while loading or failed and ignore late sessions', () => {
    const settings=state().feedSettings(12);
    const first=settings.open();
    assert.equal(settings.value(''),12);
    settings.fail(first,new Error('offline'));
    assert.equal(settings.value(''),12);
    const second=settings.open();
    settings.close(second);
    const third=settings.open();
    assert.equal(settings.resolve(second,[{id:99,name:'Late'}]),false);
    assert.equal(settings.resolve(third,[{id:12,name:'Saved'},{id:44,name:'Other'}]),true);
    assert.equal(settings.value('44'),44);
});

test('layout history tracks dirty state and restores undo and redo snapshots', () => {
    const history=state().layoutHistory([{id:'recent',columns:2,rows:1,settings:{limit:6}}]);
    assert.equal(history.snapshot().dirty,false);
    history.commit([{id:'recent',columns:3,rows:2,settings:{limit:6}}]);
    assert.equal(history.snapshot().dirty,true);
    assert.equal(history.snapshot().canUndo,true);
    assert.equal(history.undo()[0].columns,2);
    assert.equal(history.snapshot().canRedo,true);
    assert.equal(history.redo()[0].rows,2);
    history.saved([{id:'recent',columns:3,rows:2,settings:{limit:6}}]);
    assert.equal(history.snapshot().dirty,false);
});

test('layout history truncates redo after a new edit and never shares settings objects', () => {
    const initial=[{id:'recent',columns:1,rows:1,settings:{limit:6}}];
    const history=state().layoutHistory(initial);
    history.commit([{...initial[0],columns:2}]);
    history.undo();
    const current=history.commit([{...initial[0],rows:2}]);
    current[0].settings.limit=24;
    assert.equal(history.snapshot().canRedo,false);
    assert.equal(history.current()[0].settings.limit,6);
});

test('grid snapping uses measured tracks and gaps instead of a fixed card size', () => {
    const api=state();
    assert.equal(JSON.stringify(api.snapResize({deltaX:171,deltaY:132,columnWidth:160,rowHeight:120,columnGap:12,rowGap:8})),JSON.stringify({columns:1,rows:1}));
    assert.equal(JSON.stringify(api.snapResize({deltaX:-90,deltaY:-70,columnWidth:160,rowHeight:120,columnGap:12,rowGap:8})),JSON.stringify({columns:-1,rows:-1}));
});

test('version 2 widths keep one-column widgets compact while legacy values retain proportions', () => {
    const api=state();
    assert.equal(JSON.stringify(api.dimensions({grid_version:2,columns:1,rows:1})),JSON.stringify({grid_version:2,columns:1,rows:1}));
    assert.equal(JSON.stringify(api.dimensions({columns:1,rows:1})),JSON.stringify({grid_version:2,columns:2,rows:1}));
    assert.equal(JSON.stringify(api.dimensions({columns:4,rows:1})),JSON.stringify({grid_version:2,columns:8,rows:1}));
});

// The state API runs in a vm context, whose objects carry another realm's prototypes.
const plain = value => JSON.parse(JSON.stringify(value));
const cells = list => plain(list.map(({id,column,row}) => ({id,column,row})));

test('a layout without saved cells places exactly like the previous packing', () => {
    const api = state();
    const widgets = [{id:'a',grid_version:2,columns:4,rows:2},{id:'b',grid_version:2,columns:2,rows:1},{id:'c',grid_version:2,columns:2,rows:2},{id:'d',grid_version:2,columns:4,rows:1}];
    assert.deepEqual(cells(api.place(widgets)), cells(api.pack(widgets, 8)));
});

test('a widget can be placed in empty space and keeps that cell', () => {
    const api = state();
    const widgets = [{id:'a',grid_version:2,columns:2,rows:1,settings:{}},{id:'b',grid_version:2,columns:2,rows:1,settings:{}}];
    const moved = api.normalize(api.moveTo({widgets, id:'a', column:7, row:1}));
    assert.deepEqual(cells(moved), [{id:'b',column:3,row:1},{id:'a',column:7,row:1}]);
    assert.equal(widgets[0].column, undefined);
    assert.deepEqual(cells(api.place(moved)), cells(moved));
});

test('a move is a pure function of the pre-gesture layout, so a held pointer cannot oscillate', () => {
    const api = state();
    const widgets = [{id:'big',grid_version:2,columns:4,rows:2,settings:{}},{id:'small',grid_version:2,columns:2,rows:2,settings:{}}];
    const first = JSON.stringify(api.moveTo({widgets, id:'small', column:1, row:1}));
    for (let i = 0; i < 5; i++) assert.equal(JSON.stringify(api.moveTo({widgets, id:'small', column:1, row:1})), first);
    assert.deepEqual(cells(JSON.parse(first)), [{id:'big',column:1,row:3},{id:'small',column:1,row:1}]);
});

test('landing on a same-shaped card trades places; resizing pushes neighbours down and stays on the canvas', () => {
    const api = state();
    const widgets = [{id:'a',grid_version:2,columns:1,rows:1,settings:{}},{id:'b',grid_version:2,columns:1,rows:1,settings:{}},{id:'c',grid_version:2,columns:7,rows:1,column:2,row:2,settings:{}}];
    assert.deepEqual(cells(api.moveTo({widgets, id:'a', column:2, row:1})), [{id:'a',column:2,row:1},{id:'b',column:1,row:1},{id:'c',column:2,row:2}]);
    const grown = api.resizeTo({widgets, id:'b', columns:9, rows:2});
    assert.deepEqual(plain(grown.map(({id,column,row,columns,rows}) => ({id,column,row,columns,rows}))), [{id:'a',column:1,row:1,columns:1,rows:1},{id:'b',column:2,row:1,columns:7,rows:2},{id:'c',column:2,row:3,columns:7,rows:1}]);
});

test('normalizing closes fully empty rows and drops nothing', () => {
    const api = state();
    const widgets = [{id:'a',grid_version:2,columns:2,rows:1,column:1,row:4,settings:{limit:6}},{id:'b',grid_version:2,columns:2,rows:2,column:5,row:9,settings:{}}];
    assert.deepEqual(plain(api.normalize(widgets).map(({id,column,row,settings}) => ({id,column,row,settings}))), [{id:'a',column:1,row:1,settings:{limit:6}},{id:'b',column:5,row:2,settings:{}}]);
    assert.deepEqual(plain(api.unplaced(widgets).map(widget => Object.keys(widget).sort().join())), ['columns,grid_version,id,rows,settings','columns,grid_version,id,rows,settings']);
});
