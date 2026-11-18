const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../../..');
function component(crypto = {randomUUID: () => 'request'}) {
    const source = fs.readFileSync(path.join(root, 'packages/Mothership/DAM/src/Resources/views/asset/preview-modal/conversion.blade.php'), 'utf8');
    let result;
    const script = source.split('<script type="module">')[1].split('</script>')[0];
    vm.runInNewContext(script, {app: {component: (name, value) => {result=value;}}, setTimeout, clearTimeout, crypto});
    const instance = {...result.data(), assetId: 5, extension: 'png', stateUrl: '/state/5', storeUrl: '/store/5', $emitter:{emit(){}}, $axios:{}};
    Object.entries(result.methods).forEach(([key,value]) => {instance[key]=value.bind(instance);});
    Object.entries(result.computed).forEach(([key,value]) => Object.defineProperty(instance,key,{get:()=>value.call(instance)}));
    return instance;
}
test('conversion submission is single flight and keeps selected format', async () => {
    const c = component(); let resolve, count=0;
    c.$axios.post = (url, data) => {count++; assert.equal(data.format,'pdf'); return new Promise(r=>{resolve=r;});};
    c.schedulePoll = () => {};
    const first=c.convert('pdf'); await c.convert('svg'); assert.equal(count,1);
    resolve({data:{result:{status:'queued',format:'pdf'}}}); await first;
    assert.equal(c.busy,true);
});
test('late completion after unmount cannot update or publish completion', async () => {
    const c=component(); let resolve, emitted=0;
    c.$emitter.emit=()=>emitted++;
    c.$axios.get=()=>new Promise(r=>{resolve=r;});
    const pending=c.refresh(); c.alive=false;
    resolve({data:{result:{status:'completed',format:'svg'}}}); await pending;
    assert.equal(c.result,null); assert.equal(emitted,0);
});
test('transient status failure keeps polling but permission denial stops', async () => {
    const c=component(); let polls=0; c.schedulePoll=()=>polls++;
    c.$axios.get=async()=>{throw {response:{status:503}};};
    await c.refresh(); assert.equal(polls,1);
    c.$axios.get=async()=>{throw {response:{status:403}};};
    await c.refresh(); assert.equal(polls,1); assert.ok(c.error);
});
test('missing randomUUID submits a valid UUID and does not strand the overlay', async () => {
    const c=component({}); let submitted;
    c.$axios.post=async(url,data)=>{submitted=data;return {data:{result:{status:'queued'}}};};
    c.schedulePoll=()=>{};
    await c.convert('svg');
    assert.match(submitted.request_id,/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
    assert.equal(c.submitting,false);
});
test('an identity-generation error clears submitting and permits recovery', async () => {
    const c=component({randomUUID(){throw new Error('unavailable');}});
    await c.convert('svg');
    assert.equal(c.submitting,false);assert.ok(c.error);
});
