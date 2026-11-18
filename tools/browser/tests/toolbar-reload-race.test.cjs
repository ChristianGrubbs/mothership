// The history datagrid, the DAM asset picker and the Library explorer keep their toolbar mounted while a
// reload is in flight, so the focused per-page toggle, pagination button or page input survives the
// reload. That lets a second per-page or page change start before the first response lands; when the
// responses arrive out of order, only the latest request may update the grid. The Admin and classic DAM
// datagrids have the same treatment, covered in datagrid-reload-race.test.cjs.
const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'../../..');

const HISTORY='packages/Mothership/Admin/src/Resources/views/components/history';
const PICKER='packages/Mothership/DAM/src/Resources/views/components/asset/picker';
const EXPLORER='packages/Mothership/DAM/src/Resources/views/components/explorer';

function moduleScript(file,componentName){
    const source=fs.readFileSync(path.join(root,file),'utf8');
    const script=[...source.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].map(match=>match[1])
        .find(body=>body.includes(`app.component('${componentName}'`));
    assert.ok(script,`${componentName} not found in ${file}`);
    return script;
}

function grid(file,componentName,{setTimeout:timeout=setTimeout,clearTimeout:clear=clearTimeout}={}){
    const script=moduleScript(file,componentName)
        .replace(/@lang\('[^']+'\)/g,'Translation').replace(/@json\(trans\('[^']+'\)\)/g,'"Translation"');
    let definition;const calls=[],storage={};
    const document={body:{name:null},activeElement:null};
    vm.runInNewContext(script,{app:{component(name,value){definition=value;}},window:{location:{search:''}},URLSearchParams,URL,localStorage:{getItem:key=>storage[key]??null,setItem:(key,value)=>storage[key]=value,removeItem:key=>delete storage[key]},setTimeout:timeout,clearTimeout:clear,AbortController,document,console:{error(){},warn(){}}});
    const state={...definition.data(),...definition.methods,src:'/admin/grid',$el:{querySelector:()=>null},$refs:{filterDrawer:{close(){}}},$nextTick:callback=>Promise.resolve().then(callback),$emitter:{emit(){}},$axios:{get(url,options){calls.push({url,options});return new Promise((resolve,reject)=>Object.assign(calls.at(-1),{resolve,reject}));}}};
    return {state,calls,document,perPage:value=>state.changePerPageOption(value),records:()=>state.available.records,shownPerPage:()=>state.available.meta.per_page,loading:()=>state.isLoading};
}

// Replaces each @json(/@js(<php expression>) with null, matching parentheses so nested calls stay whole.
function stripBladeJson(script){
    let out='',from=0,at;
    for (const rx=/@(?:json|js)\(/g;(at=script.slice(from).search(rx))!==-1;) {
        at+=from;
        let depth=0,i=at+script.slice(at).indexOf('(');
        for (;i<script.length;i++) { if (script[i]==='(') depth++; else if (script[i]===')' && --depth===0) break; }
        out+=script.slice(from,at)+'null';from=i+1;
    }
    return out+script.slice(from);
}

// The explorer tab fetches with its own state names (loading, meta, perPage, assets) and already guards
// with _fetchRequestId; evaluate the real component so the toolbar condition is checked against it.
function explorer(){
    const script=stripBladeJson(moduleScript(`${EXPLORER}/tab.blade.php`,'v-dam-tab'))
        .replace(/@lang\('[^']+'\)/g,'Translation')
        .split('\n').filter(line=>!/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');
    let definition;const calls=[];
    const storage={getItem:()=>null,setItem(){},removeItem(){}};
    vm.runInNewContext(script,{app:{component(name,value){definition=value;}},window:{MothershipLibraryNavigation:{resolveSort:()=>'name:asc'}},localStorage:storage,sessionStorage:storage,document:{},setTimeout,clearTimeout,AbortController});
    const props={tabId:'t1',initialDirectoryId:null,initialSearch:'',initialScope:'all',initialMediaType:'all',clearSavedFilters:false,initialViewMode:'grid',initialPage:1,initialPerPage:50,aclBypass:true,accessibleIds:[]};
    const state={...props,...definition.methods,$emit(){},$emitter:{emit(){}},$nextTick:callback=>Promise.resolve().then(callback),
        $axios:{get(url,options){calls.push({url,options});return new Promise(resolve=>calls.at(-1).resolve=resolve);}}};
    Object.assign(state,definition.data.call(state));
    // Selection and tab-sync bookkeeping is not under test here.
    Object.assign(state,{restoreSelection(){},computeSelectionMode(){},sync(){},userKey:()=>null,syncSortToSearch(){}});
    return {state,calls,perPage:value=>state.onPerPage(value),records:()=>state.assets,shownPerPage:()=>state.meta.per_page,loading:()=>state.loading};
}

const gridResponse=(perPage,records,{page=1,lastPage=1}={})=>({data:{id:1,columns:[],actions:[],mass_actions:[],records,meta:{current_page:page,last_page:lastPage,per_page:perPage,per_page_options:[10,25,100]}}});
const explorerResponse=(perPage,records)=>({data:{directories:[],assets:records,meta:{current_page:1,last_page:1,per_page:perPage}}});
const settle=()=>new Promise(resolve=>setImmediate(resolve));
const json=value=>JSON.parse(JSON.stringify(value));

// The original defect: the toolbar's own loading branch swapped it for the shimmer on every reload,
// destroying the control that had focus. Evaluate each toolbar's actual v-if against the real state.
function toolbarShimmerCondition(file,{firstLoadShimmer=true}={}){
    const blade=fs.readFileSync(path.join(root,file),'utf8').replace(/\{\{--[\s\S]*?--\}\}/g,'');
    // The Library explorer's bar wraps to one, two or three rows with the panel width, so swapping a fixed-height
    // shimmer for it moved the file list when the first page landed (CLS). It renders from the first paint instead.
    if (! firstLoadShimmer) {
        assert.ok(! blade.includes('x-admin::shimmer.datagrid.toolbar'),`${file} must render the real bar from the first paint`);
        assert.ok(! /^\s*<(div|template)\b[^>]*\bv-(if|else)\b/.test(blade.replace(/@once\([^)]*\)/,'')),`${file} must not put its bar behind a condition`);
        return ()=>false;
    }
    const condition=blade.match(/<template v-if="([^"]+)">\s*<x-admin::shimmer\.datagrid\.toolbar/);
    assert.ok(condition,`${file} must have the toolbar shimmer branch`);
    assert.equal(blade.indexOf('<template'),condition.index,`${file} must open with the toolbar shimmer branch`);
    return state=>new Function('state',`with (state) { return (${condition[1]}); }`)(state);
}

const SUBJECTS=[
    {name:'history datagrid',toolbar:`${HISTORY}/toolbar.blade.php`,make:()=>grid(`${HISTORY}/index.blade.php`,'v-history-datagrid'),response:gridResponse},
    {name:'DAM asset picker',toolbar:`${PICKER}/toolbar.blade.php`,make:()=>grid(`${PICKER}/index.blade.php`,'v-asset-picker'),response:gridResponse},
    {name:'Library explorer',toolbar:`${EXPLORER}/toolbar.blade.php`,make:explorer,response:explorerResponse,firstLoadShimmer:false},
];

for (const {name,toolbar,make,response,firstLoadShimmer=true} of SUBJECTS) {
    test(`${name} ignores a per-page response that lands after a newer one`,async()=>{
        const subject=make();
        subject.perPage(25);
        subject.perPage(100);
        assert.equal(subject.calls.length,2);

        subject.calls[1].resolve(response(100,[{id:100}]));await settle();
        assert.equal(subject.loading(),false);
        subject.calls[0].resolve(response(25,[{id:25}]));await settle();

        assert.deepEqual(json(subject.records()),[{id:100}]);
        assert.equal(subject.shownPerPage(),100);
        assert.equal(subject.loading(),false);
    });

    test(`${name} keeps loading when an older response lands while the newer one is pending`,async()=>{
        const subject=make();
        subject.perPage(25);
        subject.perPage(100);

        subject.calls[0].resolve(response(25,[{id:25}]));await settle();
        assert.equal(subject.loading(),true);
        assert.deepEqual(json(subject.records()),[]);

        subject.calls[1].resolve(response(100,[{id:100}]));await settle();
        assert.deepEqual(json(subject.records()),[{id:100}]);
        assert.equal(subject.loading(),false);
    });

    test(`${name} toolbar ${firstLoadShimmer?'shows the shimmer on the first load only and ':''}stays mounted through a reload`,async()=>{
        const shimmer=toolbarShimmerCondition(toolbar,{firstLoadShimmer});
        const subject=make();
        subject.perPage(25);
        assert.equal(shimmer(subject.state),firstLoadShimmer,firstLoadShimmer?'first load shows the shimmer':'the bar is mounted on the first load too');
        subject.calls[0].resolve(response(25,[{id:25}]));await settle();
        assert.equal(shimmer(subject.state),false);

        subject.perPage(100);
        assert.equal(subject.loading(),true);
        assert.equal(shimmer(subject.state),false,'a reload must keep the toolbar (and its focused control) mounted');
        subject.calls[1].resolve(response(100,[{id:100}]));await settle();
        assert.equal(shimmer(subject.state),false);
    });
}

// With the pagination arrows usable during a reload, Next and Previous must step from the page already
// requested; stepping from the last loaded page asked for page 2 twice, or rejected page 0.
for (const {name,make} of SUBJECTS.slice(0,2)) {
    for (const [moves,pages] of [[['next','next'],[2,3]],[['next','previous'],[2,1]]]) {
        test(`${name} ${moves.join(' then ')} before the reload lands requests pages ${pages.join(', ')}`,async()=>{
            const subject=make();
            subject.state.get();
            subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:3}));await settle();

            for (const move of moves) subject.state.changePage(move);

            assert.deepEqual(subject.calls.slice(1).map(call=>call.options.params.pagination.page),pages);
        });
    }
}

// A failed request leaves its page unloaded, so the next step must start from the last loaded page:
// otherwise a failed Next then Next skipped a page, or every later Next was rejected as out of range.
for (const {name,make} of SUBJECTS.slice(0,2)) {
    for (const [from,lastPage,retry] of [[1,3,2],[2,3,3]]) {
        test(`${name} retries page ${retry} after a rejected Next from page ${from} of ${lastPage}`,async()=>{
            const subject=make();
            subject.state.applied.pagination.page=from;subject.state.get();
            subject.calls[0].resolve(gridResponse(10,[{id:from}],{page:from,lastPage}));await settle();

            subject.state.changePage('next');
            subject.calls[1].reject(new Error('network'));await settle();
            assert.equal(subject.state.applied.pagination.page,from);

            subject.state.changePage('next');
            assert.equal(subject.calls.length,3,'the retry must not be rejected as out of range');
            assert.equal(subject.calls[2].options.params.pagination.page,retry);
        });
    }

    test(`${name} ignores a stale request's failure when restoring the page`,async()=>{
        const subject=make();
        subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:3}));await settle();

        subject.state.changePage('next');subject.state.changePage('next');
        subject.calls[1].reject(new Error('network'));await settle();
        assert.equal(subject.state.applied.pagination.page,3,'an older failure must not move the pending page');
    });
}

// An auth redirect answers with HTML. With the toolbar mounted through the reload, that answer must not
// wipe available.meta (the toolbar reads it) or strand the requested page as the step origin.
for (const {name,make} of SUBJECTS.slice(0,2)) {
    test(`${name} keeps the last load when a Next is answered with HTML`,async()=>{
        const subject=make();
        subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:3}));await settle();

        subject.state.changePage('next');
        subject.calls[1].resolve({data:'<html>login</html>'});await settle();

        assert.equal(subject.state.available.meta.current_page,1);
        assert.deepEqual(json(subject.state.available.records),[{id:1}]);
        assert.equal(subject.state.applied.pagination.page,1);
        assert.equal(subject.state.isLoading,false);
    });
}

// A failed request must end its own loading state (only the latest one may), or the table and gallery
// kept their shimmer until some later request happened to succeed.
for (const {name,make} of SUBJECTS.slice(0,2)) {
    test(`${name} stops loading and keeps the last load when the latest request fails`,async()=>{
        const subject=make();
        subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:3}));await settle();

        subject.state.changePage('next');
        subject.calls[1].reject(new Error('network'));await settle();

        assert.equal(subject.loading(),false);
        assert.deepEqual(json(subject.records()),[{id:1}]);
    });

    test(`${name} stops loading when the first load fails`,async()=>{
        const subject=make();
        subject.state.get();
        subject.calls[0].reject(new Error('network'));await settle();
        assert.equal(subject.loading(),false);
    });

    test(`${name} keeps loading when an older request fails while the newer one is pending`,async()=>{
        const subject=make();
        subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:3}));await settle();

        subject.state.changePage('next');subject.state.changePage('next');
        subject.calls[1].reject(new Error('network'));await settle();
        assert.equal(subject.loading(),true);
    });
}

// last_page belongs to the page size and filters it was loaded with. With the toolbar usable during a
// page-size or filter reload, a Next validated against the old last_page asked for a page the new scope
// does not have (page 2 of 10 rows per page, at 100 rows per page), and the server returned it empty.
for (const {name,make} of SUBJECTS.slice(0,2)) {
    const loaded=async(page=1)=>{
        const subject=make();
        subject.state.applied.pagination.page=page;subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page,lastPage:10}));await settle();
        return subject;
    };

    test(`${name} waits for a page-size reload before paging`,async()=>{
        const subject=await loaded();
        subject.perPage(100);
        subject.state.changePage('next');subject.state.changePage(2);
        assert.equal(subject.calls.length,2,'no page request against the old last_page');

        subject.calls[1].resolve(gridResponse(100,[{id:1}],{page:1,lastPage:1}));await settle();
        subject.state.changePage('next');
        assert.equal(subject.calls.length,2,'page 2 does not exist at 100 per page');
        assert.equal(subject.state.applied.pagination.page,1);
    });

    test(`${name} pages normally once the page-size reload lands`,async()=>{
        const subject=await loaded();
        subject.perPage(25);
        subject.calls[1].resolve(gridResponse(25,[{id:1}],{page:1,lastPage:4}));await settle();

        subject.state.changePage('next');
        assert.equal(subject.calls.length,3);
        assert.deepEqual(json(subject.calls[2].options.params.pagination),{page:2,per_page:25});
    });

    test(`${name} waits for a filter reload before paging`,async()=>{
        const subject=await loaded();
        subject.state.applied.filters.columns=[{index:'all',value:['narrow']}];subject.state.get();
        subject.state.changePage('next');
        assert.equal(subject.calls.length,2);
    });

    // A failed scope change leaves the old last_page and the new page size or filters: paging retries the
    // new scope from page 1 instead of locking, or asking for a page the new scope may not have.
    for (const [answer,settleWith] of [['fails',call=>call.reject(new Error('network'))],['is answered with HTML',call=>call.resolve({data:'<html>login</html>'})]]) {
        test(`${name} retries from page 1 when Next follows a page-size reload that ${answer}`,async()=>{
            const subject=await loaded();
            subject.perPage(100);
            settleWith(subject.calls[1]);await settle();
            assert.equal(subject.loading(),false);

            subject.state.changePage('next');
            assert.equal(subject.calls.length,3,'a failed reload must not lock pagination');
            assert.deepEqual(json(subject.calls[2].options.params.pagination),{page:1,per_page:100});

            subject.calls[2].resolve(gridResponse(100,[{id:1},{id:2}],{page:1,lastPage:1}));await settle();
            assert.deepEqual(json(subject.records()),[{id:1},{id:2}]);
            subject.state.changePage('next');
            assert.equal(subject.calls.length,3,'page 2 does not exist at 100 per page');
        });
    }

    test(`${name} retries a failed filter narrowing from page 1`,async()=>{
        const subject=await loaded(3);
        subject.state.applied.filters.columns=[{index:'all',value:['narrow']}];subject.state.get();
        subject.calls[1].reject(new Error('network'));await settle();

        subject.state.changePage(5);
        assert.equal(subject.calls.length,3);
        assert.equal(subject.calls[2].options.params.pagination.page,1);
        assert.deepEqual(json(subject.calls[2].options.params.filters.all),['narrow']);
    });
}

// The picker edits filters without reloading until Save (filterPage, removeAppliedColumnValue). The old
// last_page does not hold for the edited filters either, so paging loads them from page 1.
for (const pending of [false,true]) {
    test(`DAM asset picker loads an unsaved filter edit from page 1 when paging${pending?' after a pending Next lands':''}`,async()=>{
        const subject=grid(`${PICKER}/index.blade.php`,'v-asset-picker');
        subject.state.applied.filters.columns.push({index:'type',value:['image','video']});
        subject.state.applied.pagination.page=2;subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:2,lastPage:3}));await settle();

        if (pending) subject.state.changePage('next');
        subject.state.removeAppliedColumnValue('type','video');
        if (pending) {
            subject.state.changePage('next');
            assert.equal(subject.calls.length,2,'waits for the pending request');
            subject.calls[1].resolve(gridResponse(10,[{id:3}],{page:3,lastPage:3}));await settle();
        }

        subject.state.changePage('next');
        const last=subject.calls.at(-1).options.params;
        assert.equal(last.pagination.page,1);
        assert.deepEqual(json(last.filters.type),['image']);
    });
}

// The picker puts focus back in the search input after a reload, which used to replace it. The toolbar
// now stays mounted, so focus can move during the request; putting it back would steal it.
for (const [moved,expected] of [[false,true],[true,false]]) {
    test(`DAM asset picker ${expected?'restores':'does not steal'} search focus when focus ${moved?'moved':'was lost'} during the reload`,async()=>{
        const subject=grid(`${PICKER}/index.blade.php`,'v-asset-picker');
        let focused=false;
        const search={name:'search',selectionStart:3,focus(){focused=true;},setSelectionRange(){}};
        subject.state.$el={querySelector:selector=>selector==='input[name="search"]'?search:null};

        subject.document.activeElement=search;
        subject.state.get();
        subject.document.activeElement=moved?{name:null}:subject.document.body;
        subject.calls[0].resolve(gridResponse(10,[{id:1}]));await settle();await settle();

        assert.equal(focused,expected);
    });
}

// filterPage() resets applied.pagination.page to 1 while the drawer is open, before anything loads. Editing
// and reverting a filter leaves the loaded scope, so paging must step from the page on screen.
for (const [move,expected] of [['next',4],['previous',2]]) {
    test(`DAM asset picker ${move} after editing and reverting a filter on page 3 requests page ${expected}`,async()=>{
        const subject=grid(`${PICKER}/index.blade.php`,'v-asset-picker');
        subject.state.applied.filters.columns.push({index:'type',value:['image']});
        subject.state.applied.pagination.page=3;subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:3,lastPage:5}));await settle();

        subject.state.applied.filters.columns[1].value=['image','video'];subject.state.applied.pagination.page=1;
        subject.state.applied.filters.columns[1].value=['image'];

        subject.state.changePage(move);
        assert.equal(subject.calls.length,2);
        assert.equal(subject.calls[1].options.params.pagination.page,expected);
    });
}

// Typing in the picker search while a reload is in flight: the response re-renders the toolbar, and Vue writes
// the input's :value back to the DOM. Bound to the applied search, that replaced the new text with the old one,
// and the debounce then read the input and searched for the old text.
test('DAM asset picker keeps text typed into search while a reload lands inside the debounce',async()=>{
    const timers=[];
    const subject=grid(`${PICKER}/index.blade.php`,'v-asset-picker',{setTimeout:callback=>timers.push(callback),clearTimeout(){}});
    const blade=fs.readFileSync(path.join(root,`${PICKER}/toolbar.blade.php`),'utf8');
    const binding=blade.match(/name="search"[\s\S]*?:value="([^"]+)"/);
    assert.ok(binding,'the picker toolbar must bind the search input value');
    const rendered=()=>String(new Function('state',`with (state) { return (${binding[1]}); }`)(subject.state));

    subject.state.get();
    subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:3}));await settle();
    subject.state.changePage('next');

    const input={name:'search',value:'new search'};
    subject.state.debouncedFilterPage({target:input,srcElement:input});
    subject.calls[1].resolve(gridResponse(10,[{id:2}],{page:2,lastPage:3}));await settle();
    input.value=rendered();
    assert.equal(input.value,'new search','the reload must not put the old search back');

    timers.at(-1)();
    assert.deepEqual(json(subject.calls.at(-1).options.params.filters.all),['new search']);
    assert.equal(subject.calls.at(-1).options.params.pagination.page,1);
    assert.equal(rendered(),'new search');

    // Once applied, the input follows the applied search again, so clearing the filters empties it.
    subject.state.findAppliedColumn('all').value=[];
    assert.equal(rendered(),'');
});

// The page-number input commits on change. A reload landing while a number is being typed re-renders the
// input's :value; bound to the loaded page, that replaced the typed number before it was committed.
function pageInput(toolbar,state){
    const blade=fs.readFileSync(path.join(root,toolbar),'utf8');
    const input=blade.match(/<input[^>]*?:value="([^"]*current_page[^"]*)"[^>]*?@input="([^"]+)"[^>]*?@change="([^"]+)"[^>]*?@blur="([^"]+)"/);
    assert.ok(input,`${toolbar} page input must keep a draft (:value, @input, @change, @blur)`);
    const value=()=>String(new Function('state',`with (state) { return (${input[1]}); }`)(state));
    // Vue runs an inline handler as statements, so it may hold several separated by semicolons.
    const handle=expression=>target=>new Function('state','$event',`with (state) { ${expression}; }`)(state,{target});
    return {value,type:handle(input[2]),commit:handle(input[3]),blur:handle(input[4])};
}

for (const {name,toolbar,make} of SUBJECTS.slice(0,2)) {
    test(`${name} keeps a page number typed while a reload lands, then requests it`,async()=>{
        const subject=make();
        subject.state.get();
        subject.calls[0].resolve(gridResponse(10,[{id:1}],{page:1,lastPage:9}));await settle();
        const input=pageInput(toolbar,subject.state);

        subject.state.changePage('next');
        const target={value:'7'};input.type(target);
        subject.calls[1].resolve(gridResponse(10,[{id:2}],{page:2,lastPage:9}));await settle();
        target.value=input.value();
        assert.equal(target.value,'7','the reload must not replace the typed page');

        input.commit(target);input.blur(target);
        assert.equal(subject.calls.at(-1).options.params.pagination.page,7);
        assert.equal(input.value(),'2','after committing, the input follows the loaded page');
    });
}
