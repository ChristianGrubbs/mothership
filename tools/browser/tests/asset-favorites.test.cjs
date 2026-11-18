const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const source = fs.readFileSync(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/assets/js/asset-favorites.js'), 'utf8');
function api() {
    const context = {window:{},queueMicrotask,Promise,Map,Set,Number,Error};
    vm.runInNewContext(source, context);
    return context.window.MothershipFavoriteState;
}

test('favorite status requests made in one render are batched once', async () => {
    const calls = [];
    const client = api().client({request: async options => {
        calls.push(options);
        return {favorites:{'1':true,'2':false,'3':true}};
    }});
    assert.deepEqual(await Promise.all([client.has(1),client.has(2),client.has(3)]), [true,false,true]);
    assert.equal(calls.length, 1);
    assert.equal(JSON.stringify(calls[0]), JSON.stringify({method:'GET',ids:[1,2,3]}));
});

test('a 250-card render uses three bounded status requests and resolves every card', async () => {
    const calls=[];
    const client=api().client({request:async options=>{
        calls.push(options);
        return {favorites:Object.fromEntries(options.ids.map(id=>[id,id%2===0]))};
    }});
    const ids=Array.from({length:250},(_,index)=>index+1);
    const statuses=await Promise.all(ids.map(id=>client.has(id)));
    assert.deepEqual(calls.map(call=>call.ids.length),[100,100,50]);
    assert.equal(calls.every(call=>call.ids.length<=100),true);
    assert.equal(statuses.length,250);
    assert.equal(statuses[0],false);
    assert.equal(statuses[249],true);
});

test('preview selection ignores stale favorite success and error responses', async () => {
    const pending = new Map();
    const client = {has(id){return new Promise((resolve,reject)=>pending.set(id,{resolve,reject}));},toggle:async()=>true};
    const selection = api().selection(client);
    const first = selection.load(1);
    selection.select(2);
    const second = selection.load(2);
    pending.get(2).resolve(true);
    await second;
    pending.get(1).reject(new Error('late failure'));
    await first;
    assert.equal(JSON.stringify(selection.snapshot()), JSON.stringify({id:2,favorite:true,error:null,generation:3}));
});

test('preview toggle cannot apply to an asset opened while the mutation is pending', async () => {
    let release;
    const client = {has:async()=>false,toggle:()=>new Promise(resolve=>{release=resolve;})};
    const selection = api().selection(client);
    await selection.load(4);
    const toggle = selection.toggle();
    const next = selection.load(5);
    release(true);
    await Promise.all([toggle,next]);
    assert.equal(JSON.stringify(selection.snapshot()), JSON.stringify({id:5,favorite:false,error:null,generation:2}));
});

test('rapid favorite toggles serialize so the second observes the first result', async () => {
    const calls=[];let releaseFirst;
    const client=api().client({request:options=>{
        calls.push(options);
        if(options.method==='GET')return Promise.resolve({favorites:{'8':false}});
        if(calls.filter(call=>call.method!=='GET').length===1)return new Promise(resolve=>{releaseFirst=()=>resolve({});});
        return Promise.resolve({});
    }});
    await client.has(8);
    const first=client.toggle(8);
    const second=client.toggle(8);
    for(let index=0;index<5&&!releaseFirst;index++)await Promise.resolve();
    assert.deepEqual(calls.map(call=>call.method),['GET','POST']);
    releaseFirst();
    assert.deepEqual(await Promise.all([first,second]),[true,false]);
    assert.deepEqual(calls.map(call=>call.method),['GET','POST','DELETE']);
});

test('a rejected toggle rejects its caller while the next queued toggle still runs from unchanged state', async () => {
    const calls=[];
    const client=api().client({request:async options=>{
        calls.push(options);
        if(options.method==='GET')return {favorites:{'9':false}};
        if(options.method==='POST'&&calls.filter(call=>call.method==='POST').length===1)throw new Error('write failed');
        return {};
    }});
    await client.has(9);
    const first=client.toggle(9);
    const second=client.toggle(9);
    await assert.rejects(first,/write failed/);
    assert.equal(await second,true);
    assert.deepEqual(calls.map(call=>call.method),['GET','POST','POST']);
});
