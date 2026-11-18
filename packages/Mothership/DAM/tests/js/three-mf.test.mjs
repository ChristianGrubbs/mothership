import test from 'node:test';
import assert from 'node:assert/strict';
import { fixture, encode } from './model-fixture.mjs';
test('Bambu parser resolves package parts and assigns repeated instances to separate plates', async()=>{
    const {parseThreeMF} = await import('../../src/Resources/assets/js/model/three-mf.mjs');
    const data=parseThreeMF({buffer:fixture()});
    assert.equal(data.plates.length,2);
    assert.equal(data.items.length,2);
    assert.deepEqual(data.plates.map(p=>p.instances),[['2:0'],['2:1']]);
    assert.equal(data.objects['3D/Objects/a.model#1'].positions.length,18);
    assert.deepEqual(Array.from(data.objects['3D/Objects/a.model#1'].states),[2,1]);
    assert.equal(data.objects['3D/3dmodel.model#2'].components[0].path,'3D/Objects/a.model');
    assert.equal(data.settings['2'].extruder,2);
});
test('missing model parts and invalid zip fail explicitly', async()=>{
    const {parseThreeMF} = await import('../../src/Resources/assets/js/model/three-mf.mjs');
    assert.throws(()=>parseThreeMF({buffer:new ArrayBuffer(3)}));
    assert.throws(()=>parseThreeMF({buffer:encode({'readme.txt':'no model'})}),/model/i);
});
