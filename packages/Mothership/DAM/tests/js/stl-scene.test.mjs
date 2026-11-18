import test from 'node:test';
import assert from 'node:assert/strict';
import { Box3 } from 'three';
import { stlFixture, asciiStlFixture } from './model-fixture.mjs';

const load = () => import('../../src/Resources/assets/js/model/model-scene.mjs');

test('binary STL becomes one neutral Z-up mesh with normals computed from its faces', async () => {
    const { buildSTLScene } = await load();
    const { root, entries } = buildSTLScene({ buffer: stlFixture(), name: 'bracket.stl' });
    assert.equal(entries.length, 1);
    assert.equal(entries[0].name, 'bracket.stl');
    assert.equal(entries[0].enabled, true);
    let mesh; root.traverse(o => { if (o.isMesh) mesh = o; });
    assert.ok(mesh, 'a mesh is built');
    assert.equal(mesh.geometry.attributes.position.count, 3);
    // Zero normals in the file must not survive: every vertex gets its face normal.
    assert.deepEqual(Array.from(mesh.geometry.attributes.normal.array), [0, 0, 1, 0, 0, 1, 0, 0, 1]);
    assert.equal(mesh.material.color.getHexString(), 'b8bec9');
    assert.equal(mesh.material.vertexColors, false);
    // STL is Z-up (printer space); the scene is Y-up like the 3MF path.
    root.updateMatrixWorld(true);
    const box = new Box3().setFromObject(entries[0].object);
    assert.ok(Math.abs(box.max.y - 0) < 1e-6 && Math.abs(box.min.z + 2) < 1e-6, `Z-up remapped: ${JSON.stringify(box)}`);
});

test('ASCII STL parses through the same builder', async () => {
    const { buildSTLScene } = await load();
    const { entries } = buildSTLScene({ buffer: asciiStlFixture() });
    let mesh; entries[0].object.traverse(o => { if (o.isMesh) mesh = o; });
    assert.equal(mesh.geometry.attributes.position.count, 3);
    assert.equal(entries[0].name, 'Model');
});

test('an STL past the triangle limit is refused with a readable error', async () => {
    const { buildSTLScene } = await load();
    const two = stlFixture({ triangles: [[[0, 0, 0], [1, 0, 0], [0, 1, 0]], [[0, 0, 1], [1, 0, 1], [0, 1, 1]]] });
    assert.throws(() => buildSTLScene({ buffer: two, maxTriangles: 1 }), /triangle/i);
    assert.throws(() => buildSTLScene({ buffer: asciiStlFixture(), maxTriangles: 0 }), /triangle/i);
});

test('an STL with no complete triangles is refused instead of rendering a blank stage', async () => {
    const { buildSTLScene } = await load();
    const broken = new TextEncoder().encode('solid broken\n facet normal 0 0 0\n outer loop\n vertex 0 0 0\n endloop\n endfacet\nendsolid').buffer;
    assert.throws(() => buildSTLScene({ buffer: broken }), /no triangles/i);
    assert.throws(() => buildSTLScene({ buffer: new TextEncoder().encode('not an stl at all').buffer }), /no triangles/i);
});

test('a binary STL carrying the colour extension still renders in the neutral material', async () => {
    // STL colour is a non-standard extension; the preview spec is one neutral PBR material for
    // every STL, so decoded per-facet colours must never switch the material to vertex colours.
    const { buildSTLScene } = await load();
    const { entries } = buildSTLScene({ buffer: stlFixture({ color: [31, 0, 0] }) });
    let mesh; entries[0].object.traverse(o => { if (o.isMesh) mesh = o; });
    assert.equal(mesh.geometry.hasColors, true, 'the fixture must actually exercise the colour path');
    assert.equal(mesh.material.vertexColors, false);
    assert.equal(mesh.material.color.getHexString(), 'b8bec9');
});
