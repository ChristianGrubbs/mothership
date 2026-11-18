import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync('packages/Mothership/DAM/src/Resources/views/components/shared/name-tail.blade.php', 'utf8');
const window = {};
vm.runInNewContext(source.match(/<script>([\s\S]*?)<\/script>/)[1], { window });
// Spread into this realm: the helper ran in a vm context, whose objects have another Object prototype.
const nameTail = (name) => ({ ...window.MothershipNameTail(name) });

test('a short name is all head', () => {
    assert.deepEqual(nameTail('menu.png'), { head: 'menu.png', tail: '' });
    assert.deepEqual(nameTail('x'.repeat(24)), { head: 'x'.repeat(24), tail: '' });
});

test('a long name keeps its ending, where the files of one stack differ', () => {
    const dng = nameTail('DJI_20261007140405_0157_D.DNG');
    const jpg = nameTail('DJI_20261007140405_0157_D.JPG');

    assert.equal(dng.head + dng.tail, 'DJI_20261007140405_0157_D.DNG');
    assert.equal(dng.tail, '_0157_D.DNG');
    assert.equal(jpg.tail, '_0157_D.JPG');
    assert.notEqual(dng.tail, jpg.tail);
    assert.equal(dng.head, jpg.head);
});

test('a long name is cut at a separator, not inside a word', () => {
    const parts = nameTail('Season-TV ad campaign final.png');

    assert.equal(parts.head + parts.tail, 'Season-TV ad campaign final.png');
    assert.equal(parts.tail, ' final.png');
    assert.match(parts.tail, /^[ _.-]/);
    assert.ok(parts.tail.length <= 16);
});

test('a name with no separator in its last 16 characters is cut eleven from the end', () => {
    const parts = nameTail('A'.repeat(30));

    assert.deepEqual(parts, { head: 'A'.repeat(19), tail: 'A'.repeat(11) });
});

test('the 24 character limit: 24 stays whole, 25 is cut', () => {
    assert.deepEqual(nameTail('abcdefghijklmnopqrst.png'), { head: 'abcdefghijklmnopqrst.png', tail: '' });
    const parts = nameTail('abcdefghijklmnopqrstu.png');

    assert.equal(parts.head + parts.tail, 'abcdefghijklmnopqrstu.png');
    assert.equal(parts.tail, '.png');
});

test('a missing name is empty rather than "undefined"', () => {
    assert.deepEqual(nameTail(undefined), { head: '', tail: '' });
    assert.deepEqual(nameTail(null), { head: '', tail: '' });
});
