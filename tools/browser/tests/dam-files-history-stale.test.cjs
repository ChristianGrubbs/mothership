const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const root=path.resolve(__dirname,'../../..');
// Runs the Files tab's real toggleHistory() (pulled out of the blade) against a plain state object, so a slow
// answer for one file can be settled after the user has moved to another and the shared state checked.
const source=fs.readFileSync(path.join(root,'packages/Mothership/DAM/src/Resources/views/asset/files/index.blade.php'),'utf8');
const body=source.split('async toggleHistory(file) {')[1].split('async restore(file, revision)')[0].replace(/\},\s*$/,'');
const toggle=new Function('return async function(file) {'+body+'}')();
// Each request gets its own deferred promise, in call order per attachment, so any answer can be settled late.
const make=()=>{
    const calls=[];
    const state={
        historyFor:null,history:[],historyLoading:false,historyGeneration:0,flashes:[],
        measureHistory(){},
        arrangeUrl:(_,file)=>String(file.attachment_id),
        $emitter:{emit(name,payload){state.flashes.push(payload);}},
        $axios:{get:url=>new Promise((resolve,reject)=>{calls.push({url,resolve,reject});})},
    };
    return {state,calls};
};
const tick=()=>new Promise(r=>setImmediate(r));
const A={attachment_id:1},B={attachment_id:2};
test('a late answer for the previous file does not replace or un-load the open one',async()=>{
    const {state,calls}=make();
    const a=toggle.call(state,A);
    const b=toggle.call(state,B);
    calls[1].resolve({data:{revisions:[{id:20}]}});
    await b;
    calls[0].resolve({data:{revisions:[{id:10}]}});
    await a;
    assert.equal(state.historyFor,2);
    assert.deepEqual(state.history,[{id:20}]);
    assert.equal(state.historyLoading,false);
});
test('a stale answer for A while B is still pending leaves B open and loading',async()=>{
    const {state,calls}=make();
    const a=toggle.call(state,A);
    const b=toggle.call(state,B);
    calls[0].resolve({data:{revisions:[{id:10}]}});
    await a;
    assert.equal(state.historyFor,2);
    assert.equal(state.historyLoading,true);
    assert.deepEqual(state.history,[]);
    calls[1].resolve({data:{revisions:[{id:20}]}});
    await b;
    assert.deepEqual(state.history,[{id:20}]);
    assert.equal(state.historyLoading,false);
});
test('a stale failure for A while B is still pending does not close or un-load B',async()=>{
    const {state,calls}=make();
    const a=toggle.call(state,A);
    const b=toggle.call(state,B);
    await tick();
    calls[0].reject(new Error('late'));
    await a;
    assert.equal(state.historyFor,2,'B stays open');
    assert.equal(state.historyLoading,true,'B is still loading');
    assert.equal(state.flashes.length,0);
    calls[1].resolve({data:{revisions:[{id:20}]}});
    await b;
    assert.deepEqual(state.history,[{id:20}]);
});
test('open A, close A, reopen A: a late success from the first request is ignored',async()=>{
    const {state,calls}=make();
    const first=toggle.call(state,A);
    await toggle.call(state,A);
    assert.equal(state.historyFor,null);
    const second=toggle.call(state,A);
    assert.equal(state.historyFor,1);
    calls[0].resolve({data:{revisions:[{id:'stale'}]}});
    await first;
    assert.deepEqual(state.history,[]);
    assert.equal(state.historyLoading,true,'the reopened history is still loading');
    calls[1].resolve({data:{revisions:[{id:'fresh'}]}});
    await second;
    assert.deepEqual(state.history,[{id:'fresh'}]);
    assert.equal(state.historyLoading,false);
});
test('open A, close A, reopen A: a late failure from the first request does not close the reopened one',async()=>{
    const {state,calls}=make();
    const first=toggle.call(state,A);
    await toggle.call(state,A);
    const second=toggle.call(state,A);
    calls[0].reject(new Error('late'));
    await first;
    assert.equal(state.historyFor,1);
    assert.equal(state.historyLoading,true);
    assert.equal(state.flashes.length,0);
    calls[1].resolve({data:{revisions:[{id:'fresh'}]}});
    await second;
    assert.deepEqual(state.history,[{id:'fresh'}]);
});
