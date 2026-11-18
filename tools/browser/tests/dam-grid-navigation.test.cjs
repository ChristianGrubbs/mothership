const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const root=path.resolve(__dirname,'../../..');
const navigation=require(path.join(root,'packages/Mothership/DAM/src/Resources/assets/js/library-navigation.js'));
function grid({search='',explicit=false,saved=[],src='/admin/dam?media_type=all'}={}){
    const source=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/components/datagrid/dam.blade.php'),'utf8');
    const script=source.match(/<script type="module">([\s\S]*?)<\/script>/)[1].replace(/@lang\('[^']+'\)/g,'Translation').replace(/@json\(trans\('[^']+'\)\)/g,'"Translation"').replace(/@js\(trans\('[^']+'\)\)/g,'"Translation"').replace(/@json\(dam_access_lists\(\)\)/g,'false').replace(/\{\{[\s\S]*?\}\}/g,'/admin/dam');
    let definition;const calls=[],storage={datagrids:JSON.stringify(saved)};
    vm.runInNewContext(script,{app:{component(name,value){definition=value;}},window:{location:{search},MothershipLibraryNavigation:navigation},URLSearchParams,URL,localStorage:{getItem:key=>storage[key],setItem:(key,value)=>storage[key]=value},setTimeout,clearTimeout,document:{activeElement:null}});
    const state={...definition.data(),...definition.methods,src,ignoreStoredState:explicit,$refs:{},$nextTick:callback=>Promise.resolve().then(callback),$emitter:{emit(){}},$axios:{get(url,options){calls.push({url,options});return new Promise(resolve=>calls.at(-1).resolve=resolve);}}};
    return {state,definition,calls,storage,script};
}
function saved(){return[{src:'/admin/dam?media_type=all',applied:{pagination:{page:7,perPage:100},sort:{column:'id',order:'desc'},filters:{columns:[{index:'all',value:['old']},{index:'directory_id',value:[44]},{index:'extension',value:['pdf']}]}}}];}
const json=value=>JSON.parse(JSON.stringify(value));
test('actual DAM datagrid fresh global search discards stored folder/filter/page state',()=>{
    const {state,calls}=grid({search:'?scope=all&search=caf%C3%A9&media_type=all',explicit:true,saved:saved()});state.boot();
    assert.equal(calls[0].options.params.pagination.page,1);
    assert.deepEqual(json(calls[0].options.params.filters),{all:['café']});
});
test('actual DAM datagrid applies explicit directory and empty search and restores passive visits',()=>{
    const explicit=grid({search:'?directory_id=12&search=',explicit:true,saved:saved()});explicit.state.boot();
    assert.deepEqual(json(explicit.calls[0].options.params.filters),{all:[],directory_id:[12]});
    const passive=grid({saved:saved()});passive.state.boot();assert.equal(passive.calls[0].options.params.pagination.page,7);assert.deepEqual(json(passive.calls[0].options.params.filters.directory_id),[44]);
});
test('actual DAM source changes reset page and ignore stale media responses',async()=>{
    const {state,definition,calls}=grid();state.applied.pagination.page=9;state.get();state.src='/admin/dam?media_type=audio';
    assert.equal(typeof definition.watch.src,'function','The actual DAM datagrid must react to media source changes');
    definition.watch.src.call(state);
    assert.equal(calls[1].url,'/admin/dam?media_type=audio');assert.equal(calls[1].options.params.pagination.page,1);
    const response=records=>({data:{id:1,columns:[],actions:[],mass_actions:[],records,meta:{current_page:1,last_page:1}}});
    calls[1].resolve(response([{id:2}]));await new Promise(resolve=>setImmediate(resolve));
    calls[0].resolve(response([{id:1}]));await new Promise(resolve=>setImmediate(resolve));
    assert.deepEqual(json(state.available.records),[{id:2}]);
});

(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH?test:test.skip)('Vue DAM grid forwarded props drive the actual fresh search and media request lifecycle',async()=>{
    const {chromium}=require(process.env.MOTHERSHIP_PLAYWRIGHT_MODULE_PATH);
    const browser=await chromium.launch({headless:true,channel:process.env.MOTHERSHIP_BROWSER_CHANNEL||'chrome'});
    try{
        const page=await browser.newPage();
        await page.route('http://grid.test/**',route=>route.fulfill({body:'<div id="app"></div>',contentType:'text/html'}));
        await page.goto('http://grid.test/?scope=all&search=caf%C3%A9');
        await page.addScriptTag({path:process.env.MOTHERSHIP_VUE_RUNTIME_PATH||path.join(root,'packages/Mothership/DAM/node_modules/vue/dist/vue.global.js')});
        await page.evaluate(saved=>{localStorage.setItem('datagrids',JSON.stringify(saved));window.requests=[];window.app={component:(name,definition)=>window.definition=definition};},saved());
        await page.addScriptTag({content:grid().script});
        await page.evaluate(()=>{
            const app=Vue.createApp({template:'<button @click="source=\'/admin/dam?media_type=audio\'">Audio</button><v-dam-datagrid :src="source" :ignore-stored-state="true"></v-dam-datagrid>',data:()=>({source:'/admin/dam?media_type=all'})});
            app.config.globalProperties.$emitter={on(){},emit(){}};
            app.config.globalProperties.$axios={get:(url,options)=>new Promise(resolve=>window.requests.push({url,options,resolve}))};
            app.component('v-dam-datagrid',{...window.definition,template:'<output>{{ available.records[0]?.id }}</output>'});
            window.gridApp=app;app.mount('#app');
        });
        const first=await page.evaluate(()=>({url:requests[0].url,params:requests[0].options.params}));
        assert.equal(first.params.pagination.page,1);assert.deepEqual(first.params.filters,{all:['café']});
        await page.getByRole('button',{name:'Audio',exact:true}).click();
        await page.waitForFunction(()=>window.requests.length===2);
        assert.equal(await page.evaluate(()=>requests[1].url),'/admin/dam?media_type=audio');
        await page.evaluate(()=>{const payload=id=>({data:{id:1,columns:[],actions:[],mass_actions:[],records:[{id}],meta:{current_page:1,last_page:1}}});requests[1].resolve(payload(2));requests[0].resolve(payload(1));});
        await page.waitForFunction(()=>document.querySelector('output').textContent==='2');
        await page.evaluate(()=>window.gridApp.unmount());
    }finally{await browser.close();}
});
