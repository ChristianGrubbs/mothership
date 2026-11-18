import test from 'node:test';
import assert from 'node:assert/strict';
const modulePath = '../../src/Resources/assets/js/model/paint.mjs';
const triangle = [[0,0,0], [2,0,0], [0,2,0]];
const decode = async (code) => (await import(modulePath)).decodePaint({ code, triangle });
test('paint states include unpainted, first filament, and extended palette indices', async () => {
    assert.equal((await decode('0'))[0].state, 0);
    assert.equal((await decode('4'))[0].state, 1);
    assert.equal((await decode('0C'))[0].state, 3);
    assert.equal((await decode('1FC'))[0].state, 19);
});
test('split paint preserves face area and reverse child order', async () => {
    const faces = await decode('481');
    assert.equal(faces.length, 2);
    assert.deepEqual(faces.map(f => f.state), [2,1]);
    assert.deepEqual(faces[0].triangle, [[1,1,0],[0,2,0],[0,0,0]]);
    const area = faces.reduce((sum,{triangle:[a,b,c]})=>sum+Math.abs((b[0]-a[0])*(c[1]-a[1])-(b[1]-a[1])*(c[0]-a[0]))/2,0);
    assert.equal(area,2);
});
test('bad or truncated paint fails rather than silently inventing colors', async () => {
    await assert.rejects(decode('Q'), /paint/i);
    await assert.rejects(decode('1'), /paint/i);
});
for (const [code,count] of [['4842',3],['4846',3],['484A',3],['48483',4],['485',2],['489',2]]) {
    test(`paint split ${code} preserves winding and total area`,async()=>{
        const faces=await decode(code);
        assert.equal(faces.length,count);
        const areas=faces.map(({triangle:[a,b,c]})=>((b[0]-a[0])*(c[1]-a[1])-(b[1]-a[1])*(c[0]-a[0]))/2);
        assert.ok(areas.every(a=>a>0));assert.equal(areas.reduce((a,b)=>a+b,0),2);
        assert.deepEqual(faces.map(f=>f.state),code.slice(0,-1).split('').reverse().map(s=>parseInt(s,16)>>2));
    });
}
test('invalid split sides are rejected',async()=>{
    await assert.rejects(decode('48487'),/split side/);
    await assert.rejects(decode('48D'),/split side/);
});
