import test from 'node:test';
import assert from 'node:assert/strict';
import { Box3 } from 'three';
import { fixture } from './model-fixture.mjs';
import { parseThreeMF } from '../../src/Resources/assets/js/model/three-mf.mjs';
import { buildThreeMFScene, buildBuildPlates, printableAreaSize } from '../../src/Resources/assets/js/model/model-scene.mjs';

test('printableAreaSize reads slicer corner strings and rejects anything else', () => {
    assert.deepEqual(printableAreaSize(['0x0','256x0','256x256','0x256']),{width:256,depth:256});
    assert.equal(printableAreaSize([]),null);
    assert.equal(printableAreaSize(['junk']),null);
    assert.equal(printableAreaSize(undefined),null);
});

test('one simulated build plate per slicer plate, sized to the bed, sitting under that plate\'s own objects', () => {
    const data=parseThreeMF({buffer:fixture()});
    const {root,entries}=buildThreeMFScene({data});
    const plates=buildBuildPlates({root,entries,plates:data.plates,printableArea:data.printableArea});
    assert.equal(plates.length,data.plates.length);
    assert.deepEqual(plates.map(p=>p.id),data.plates.map(p=>p.id));
    for (const plate of plates) {
        assert.equal(plate.width,256); assert.equal(plate.depth,256);
        assert.ok(root.children.includes(plate.object),'plate is a child of the model root so it shares printer space');
        assert.equal(plate.object.userData.damBuildPlate,plate.id);
        let meshes=0; plate.object.traverse(o=>{ if(o.isMesh){ meshes++; assert.equal(o.userData.damBuildPlate,plate.id,'the bed mesh is tagged so fit() can skip it'); } });
        assert.equal(meshes,1);
    }
    // The bed lies just below the lowest vertex of the objects it carries (Z up in root space).
    root.updateMatrixWorld(true);
    const first=data.plates[0];
    const own=entries.filter(e=>first.instances.includes(e.key));
    const box=new Box3(); for (const entry of own) box.expandByObject(entry.object);
    const bed=new Box3().expandByObject(plates[0].object);
    // root is rotated -90° about X, so printer Z becomes world Y.
    assert.ok(bed.max.y<=box.min.y+1e-6 && bed.max.y>box.min.y-.1,'bed top sits immediately under the model');
});

test('a 3MF without named plates still gets one bed under the whole build', () => {
    const data=parseThreeMF({buffer:fixture()});
    const {root,entries}=buildThreeMFScene({data});
    const plates=buildBuildPlates({root,entries,plates:[],printableArea:[]});
    assert.equal(plates.length,1);
    assert.equal(plates[0].id,null);
    assert.equal(plates[0].object.userData.damBuildPlate,'all');
    assert.ok(plates[0].width>=100 && plates[0].depth>=100,'unknown bed falls back to a minimum size');
});
