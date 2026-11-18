// The Admin and classic DAM datagrids keep their toolbar mounted while a reload is in flight (so the
// focused per-page toggle or pagination button survives the reload). That lets a second per-page or
// page change start before the first response lands; when the responses arrive out of order, only the
// latest request may update the grid, otherwise the toggle reads 100 while the rows are the 25 set.
// Gallery DAM (dam.blade.php) has the same guard, covered in dam-grid-navigation.test.cjs.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'../../..');

function grid(file){
    const source=fs.readFileSync(path.join(root,file),'utf8');
    const script=source.match(/<script type="module">([\s\S]*?)<\/script>/)[1]
        .replace(/@lang\('[^']+'\)/g,'Translation').replace(/@json\(trans\('[^']+'\)\)/g,'"Translation"').replace(/@js\(trans\('[^']+'\)\)/g,'"Translation"')
        // Gallery DAM only: its access lists and route() URLs; neither appears in the other two scripts.
        .replace(/@json\(dam_access_lists\(\)\)/g,'false').replace(/\{\{[\s\S]*?\}\}/g,'/admin/dam');
    let definition;const calls=[],storage={};
    vm.runInNewContext(script,{app:{component(name,value){definition=value;}},window:{location:{search:''}},console:{error(){},warn(){}},URLSearchParams,URL,localStorage:{getItem:key=>storage[key]??null,setItem:(key,value)=>storage[key]=value,removeItem:key=>delete storage[key]},setTimeout,clearTimeout,document:{activeElement:null}});
    const state={...definition.data(),...definition.methods,src:'/admin/grid',$refs:{filterDrawer:{close(){}}},$nextTick:callback=>Promise.resolve().then(callback),$emitter:{emit(){}},$axios:{get(url,options){calls.push({url,options});return new Promise((resolve,reject)=>Object.assign(calls.at(-1),{resolve,reject}));}}};
    return {state,calls};
}
const response=(perPage,records,{page=1,lastPage=1}={})=>({data:{id:1,columns:[],actions:[],mass_actions:[],records,meta:{current_page:page,last_page:lastPage,per_page:perPage,per_page_options:[10,25,100]}}});
const settle=()=>new Promise(resolve=>setImmediate(resolve));
const json=value=>JSON.parse(JSON.stringify(value));

for (const [name,file] of [
    ['Admin','packages/Mothership/Admin/src/Resources/views/components/datagrid/index.blade.php'],
    ['classic DAM','packages/Mothership/DAM/src/Resources/views/components/datagrid/index.blade.php'],
]) {
    test(`${name} datagrid ignores a per-page response that lands after a newer one`,async()=>{
        const {state,calls}=grid(file);
        state.applied.pagination.perPage=25;state.get();
        state.applied.pagination.perPage=100;state.get();
        assert.equal(calls.length,2);
        assert.equal(calls[1].options.params.pagination.per_page,100);

        calls[1].resolve(response(100,[{id:100}]));await settle();
        assert.equal(state.isLoading,false);
        calls[0].resolve(response(25,[{id:25}]));await settle();

        assert.deepEqual(json(state.available.records),[{id:100}]);
        assert.equal(state.available.meta.per_page,100);
        assert.equal(state.isLoading,false);
        assert.equal(state.hasLoaded,true);
    });

    test(`${name} datagrid keeps loading when an older response lands while the newer one is pending`,async()=>{
        const {state,calls}=grid(file);
        state.applied.pagination.perPage=25;state.get();
        state.applied.pagination.perPage=100;state.get();

        // An auth redirect answers with HTML; Admin returns early on it, after the generation check.
        calls[0].resolve(name==='Admin'?{data:'<html>login</html>'}:response(25,[{id:25}]));await settle();
        assert.equal(state.isLoading,true);
        assert.deepEqual(json(state.available.records),[]);

        calls[1].resolve(response(100,[{id:100}]));await settle();
        assert.deepEqual(json(state.available.records),[{id:100}]);
        assert.equal(state.isLoading,false);
    });
}

// The original defect: the toolbar's own loading branch swapped it for the shimmer on every reload,
// destroying the per-page toggle that v-dropdown had just handed focus back to. Evaluate each toolbar's
// actual v-if against the real grid state: shimmer on the first load only, toolbar kept on a reload.
function toolbarShimmerCondition(file){
    const blade=fs.readFileSync(path.join(root,file),'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'');
    // DAM wraps the shimmer in a sizing div (its loaded toolbar row is taller), so allow one wrapper.
    const condition=blade.match(/^\s*<template v-if="([^"]+)">\s*(?:<div\b[^>]*>\s*)?<x-admin::shimmer\.datagrid\.toolbar/);
    assert.ok(condition,`${file} must open with the toolbar shimmer branch`);
    return state=>new Function('state',`with (state) { return (${condition[1]}); }`)(state);
}
for (const [name,file,toolbar] of [
    ['Admin','packages/Mothership/Admin/src/Resources/views/components/datagrid/index.blade.php','packages/Mothership/Admin/src/Resources/views/components/datagrid/toolbar.blade.php'],
    ['classic DAM','packages/Mothership/DAM/src/Resources/views/components/datagrid/index.blade.php','packages/Mothership/DAM/src/Resources/views/components/datagrid/toolbar.blade.php'],
]) {
    test(`${name} toolbar shows the shimmer on the first load only and stays mounted through a reload`,async()=>{
        const shimmer=toolbarShimmerCondition(toolbar);
        const {state,calls}=grid(file);
        state.get();
        assert.equal(shimmer(state),true,'first load shows the shimmer');
        calls[0].resolve(response(25,[{id:25}]));await settle();
        assert.equal(shimmer(state),false);

        state.applied.pagination.perPage=100;state.get();
        assert.equal(state.isLoading,true);
        assert.equal(shimmer(state),false,'a reload must keep the toolbar (and its focused toggle) mounted');
        calls[1].resolve(response(100,[{id:100}]));await settle();
        assert.equal(shimmer(state),false);
    });
}

// With the pagination arrows usable during a reload, Next and Previous must step from the page already
// requested; stepping from the last loaded page asked for page 2 twice, or rejected page 0. Gallery DAM's
// toolbar was already live during a reload, so it carried the same defect.
const GRIDS=[
    ['Admin','packages/Mothership/Admin/src/Resources/views/components/datagrid/index.blade.php'],
    ['classic DAM','packages/Mothership/DAM/src/Resources/views/components/datagrid/index.blade.php'],
    ['gallery DAM','packages/Mothership/DAM/src/Resources/views/components/datagrid/dam.blade.php'],
];
for (const [name,file] of GRIDS) {
    for (const [moves,pages] of [[['next','next'],[2,3]],[['next','previous'],[2,1]]]) {
        test(`${name} datagrid ${moves.join(' then ')} before the reload lands requests pages ${pages.join(', ')}`,async()=>{
            const {state,calls}=grid(file);
            state.get();
            calls[0].resolve(response(10,[{id:1}],{page:1,lastPage:3}));await settle();

            for (const move of moves) state.changePage(move);

            assert.deepEqual(calls.slice(1).map(call=>call.options.params.pagination.page),pages);
        });
    }
}

// A request that fails must not leave the requested page behind as the step origin: the next Next retries
// that page rather than skipping it, including at the last page, where a skip would be rejected outright.
// Each grid's malformed-response branch (an auth redirect answering with HTML) is the same failure.
const page=call=>call.options.params.pagination.page;
for (const [name,file] of GRIDS) {
    const failures=[['rejected',call=>call.reject(new Error('Network Error'))],['HTML',call=>call.resolve({data:'<html>login</html>'})]];
    for (const [kind,fail] of failures) {
        for (const loaded of [1,2]) {
            test(`${name} datagrid retries page ${loaded+1} after a ${kind} Next from page ${loaded} of 3`,async()=>{
                const {state,calls}=grid(file);
                state.applied.pagination.page=loaded;state.get();
                calls[0].resolve(response(10,[{id:loaded}],{page:loaded,lastPage:3}));await settle();

                state.changePage('next');
                fail(calls[1]);await settle();
                assert.equal(state.applied.pagination.page,loaded);
                state.changePage('next');

                assert.deepEqual(calls.slice(1).map(page),[loaded+1,loaded+1]);
            });
        }
    }

    for (const order of [[0,1],[1,0]]) {
        test(`${name} datagrid settles on page 3 when overlapping Next responses land ${order[0]?'newest':'oldest'} first`,async()=>{
            const {state,calls}=grid(file);
            state.get();
            calls[0].resolve(response(10,[{id:1}],{page:1,lastPage:3}));await settle();
            state.changePage('next');state.changePage('next');

            for (const index of order) {calls[1+index].resolve(response(10,[{id:2+index}],{page:2+index,lastPage:3}));await settle();}

            assert.deepEqual(json(state.available.records),[{id:3}]);
            assert.equal(state.available.meta.current_page,3);
            assert.equal(state.applied.pagination.page,3);
            assert.equal(state.isLoading,false);
        });
    }
}

// The fallback is guarded: an older request failing must not pull the step origin back under newer
// navigation, and a first load that fails has no loaded page to fall back to (meta is still {}).
for (const [name,file] of GRIDS) {
    for (const [kind,fail] of [['rejected',call=>call.reject(new Error('Network Error'))],['HTML',call=>call.resolve({data:'<html>login</html>'})]])
    for (const newerFirst of [false,true]) {
        test(`${name} datagrid keeps the newer page when an older ${kind} Next lands ${newerFirst?'after':'before'} the newer one`,async()=>{
            const {state,calls}=grid(file);
            state.get();
            calls[0].resolve(response(10,[{id:1}],{page:1,lastPage:3}));await settle();
            state.changePage('next');state.changePage('next');

            if (newerFirst) {calls[2].resolve(response(10,[{id:3}],{page:3,lastPage:3}));await settle();}
            fail(calls[1]);await settle();
            assert.equal(state.applied.pagination.page,3);
            if (!newerFirst) {
                assert.equal(state.isLoading,true,'the newer request is still pending');
                assert.deepEqual(json(state.available.records),[{id:1}]);
                calls[2].resolve(response(10,[{id:3}],{page:3,lastPage:3}));await settle();
            }

            assert.deepEqual(json(state.available.records),[{id:3}]);
            assert.equal(state.available.meta.current_page,3);
            assert.equal(state.applied.pagination.page,3);
        });
    }

    const firstFailures=[['rejected',call=>call.reject(new Error('Network Error'))],['HTML',call=>call.resolve({data:'<html>login</html>'})]];
    for (const [kind,fail] of firstFailures) {
        test(`${name} datagrid keeps the requested page when a ${kind} first load has nothing loaded to fall back to`,async()=>{
            const {state,calls}=grid(file);
            state.applied.pagination.page=2;state.get();
            fail(calls[0]);await settle();

            assert.equal(state.applied.pagination.page,2);
            assert.equal(state.hasLoaded,false);
        });
    }
}

for (const [kind,fail] of [['rejected',call=>call.reject(new Error('Network Error'))],['HTML',call=>call.resolve({data:'<html>login</html>'})]]) {
    test(`gallery DAM datagrid ignores a ${kind} Next from a media source it has since left`,async()=>{
        const {state,calls}=grid('packages/Mothership/DAM/src/Resources/views/components/datagrid/dam.blade.php');
        state.get();
        calls[0].resolve(response(10,[{id:1}],{page:1,lastPage:3}));await settle();
        state.changePage('next');
        state.src='/admin/dam?media_type=audio';

        fail(calls[1]);await settle();

        assert.equal(state.applied.pagination.page,2);
        assert.equal(state.isLoading,true);
        assert.deepEqual(json(state.available.records),[{id:1}]);
    });
}

// An HTML answer must leave the last load in place (the mounted toolbar reads its meta) and end loading,
// without throwing from the grid's own fallback.
for (const [name,file] of GRIDS) {
    test(`${name} datagrid keeps the last load and ends loading after an HTML Next response`,async()=>{
        const {state,calls}=grid(file);
        state.get();
        calls[0].resolve(response(10,[{id:1}],{page:1,lastPage:3}));await settle();
        state.changePage('next');

        const unhandled=[];const record=error=>unhandled.push(error);
        process.on('unhandledRejection',record);
        try {calls[1].resolve({data:'<html>login</html>'});await settle();await settle();}
        finally {process.off('unhandledRejection',record);}

        assert.deepEqual(unhandled,[]);
        assert.deepEqual(json(state.available.records),[{id:1}]);
        assert.equal(state.available.meta.current_page,1);
        assert.equal(state.applied.pagination.page,1);
        assert.equal(state.isLoading,false);
    });
}

// The DAM toolbar (classic and gallery) dims and blocks the arrows that cannot move. Reading the loaded
// page left Previous pointer-events-none after Next from page 1 until the response landed, so a mouse
// could not undo the step. Evaluate each arrow's actual :class condition against the real grid state.
function arrowDisabledConditions(file){
    const blade=fs.readFileSync(path.join(root,file),'utf8');
    const conditions={};
    for (const [,condition,move] of blade.matchAll(/:class="\{ 'opacity-40 pointer-events-none': ([^}]+?) \}"\s*@click="changePage\('(\w+)'\)"/g)) {
        conditions[move]=state=>new Function('state',`with (state) { return (${condition}); }`)(state);
    }
    assert.deepEqual(Object.keys(conditions).sort(),['first','last','next','previous']);
    return conditions;
}
for (const [name,file] of [
    ['classic DAM','packages/Mothership/DAM/src/Resources/views/components/datagrid/index.blade.php'],
    ['gallery DAM','packages/Mothership/DAM/src/Resources/views/components/datagrid/dam.blade.php'],
]) {
    test(`${name} toolbar disables the arrows from the requested page while a reload is in flight`,async()=>{
        const disabled=arrowDisabledConditions('packages/Mothership/DAM/src/Resources/views/components/datagrid/toolbar.blade.php');
        const blocked=state=>Object.keys(disabled).filter(move=>disabled[move](state)).sort();
        const {state,calls}=grid(file);
        state.get();
        calls[0].resolve(response(10,[{id:1}],{page:1,lastPage:3}));await settle();
        assert.deepEqual(blocked(state),['first','previous']);

        state.changePage('next');
        assert.equal(state.isLoading,true);
        assert.deepEqual(blocked(state),[],'Previous must be clickable while page 2 loads');

        state.changePage('next');
        assert.deepEqual(blocked(state),['last','next'],'Next must stop at the last page before it loads');
    });
}
