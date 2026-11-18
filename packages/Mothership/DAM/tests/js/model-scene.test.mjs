import test from 'node:test';
import assert from 'node:assert/strict';
import { Box3 } from 'three';
import { fixture, encode } from './model-fixture.mjs';
import { parseThreeMF } from '../../src/Resources/assets/js/model/three-mf.mjs';
test('scene preserves part and instance transforms and colors', async()=>{
    const {buildThreeMFScene}=await import('../../src/Resources/assets/js/model/model-scene.mjs');
    const {root,entries}=buildThreeMFScene({data:parseThreeMF({buffer:fixture()})});
    assert.equal(entries.length,2);
    root.updateMatrixWorld(true);
    const box=new Box3().setFromObject(entries[0].object);
    assert.ok(Math.abs(box.min.x-13)<1e-6);
    assert.ok(Math.abs(box.min.z+22)<1e-6);
    let mesh; entries[0].object.traverse(o=>{if(o.isMesh) mesh=o;});
    assert.deepEqual(Array.from(mesh.geometry.attributes.color.array.slice(0,3)),[0,1,0]);
    assert.deepEqual(Array.from(mesh.geometry.attributes.color.array.slice(9,12)),[1,0,0]);
});
test('cyclic components fail with a readable error',async()=>{
    const {buildThreeMFScene}=await import('../../src/Resources/assets/js/model/model-scene.mjs');
    const data=parseThreeMF({buffer:fixture()});
    data.objects['3D/3dmodel.model#2'].components[0]={id:'2',path:'3D/3dmodel.model',transform:[1,0,0,0,1,0,0,0,1,0,0,0]};
    assert.throws(()=>buildThreeMFScene({data}),/cyclic/i);
});
test('unpainted standard 3MF vertex colors survive scene assembly',async()=>{
    const {buildThreeMFScene}=await import('../../src/Resources/assets/js/model/model-scene.mjs');
    const buffer=encode({'3D/3dmodel.model':'<model><resources><colorgroup id="5"><color color="#FF0000"/><color color="#00FF00"/><color color="#0000FF"/></colorgroup><object id="1"><mesh><vertices><vertex x="0" y="0" z="0"/><vertex x="1" y="0" z="0"/><vertex x="0" y="1" z="0"/></vertices><triangles><triangle v1="0" v2="1" v3="2" pid="5" p1="0" p2="1" p3="2"/></triangles></mesh></object></resources><build><item objectid="1"/></build></model>'});
    const {root}=buildThreeMFScene({data:parseThreeMF({buffer})});let mesh;root.traverse(o=>{if(o.isMesh)mesh=o;});
    assert.deepEqual(Array.from(mesh.geometry.attributes.color.array),[1,0,0,0,1,0,0,0,1]);
});
