import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

// The words and sums Home's arrivals board, deck and Cargo shelves are drawn from.
const context = { window: {}, URLSearchParams };
vm.runInNewContext(readFileSync(new URL('../../src/Resources/assets/js/home-state.js', import.meta.url), 'utf8'), context);
const state = context.window.MothershipHomeState;
const plain = value => JSON.parse(JSON.stringify(value));

test('a landing without a shared folder opens recent assets across the Library', () => {
    assert.equal(state.recentLibraryUrl('/admin/dam', null), '/admin/dam?scope=all&recent=1');
});

test('no stylesheet comment swallows the dialog rules Home still needs', () => {
    const css = readFileSync(new URL('../../src/Resources/assets/css/home.css', import.meta.url), 'utf8');
    for (const comment of css.match(/\/\*[\s\S]*?\*\//g)) assert.doesNotMatch(comment, /[{}]/, comment.slice(0, 60));
    const live = css.replace(/\/\*[\s\S]*?\*\//g, '');
    for (const selector of ['.agh-calendar-dialog[open]', '.agh-upload{', '.agh-upload-folders{', '.agh-phone-steps{']) assert.ok(live.includes(selector), selector);
});

test('a board field always fills its width with one cell per character', () => {
    assert.deepEqual(plain(state.boardCells({ text: 'You', width: 5 })), ['Y', 'O', 'U', ' ', ' ']);
    assert.deepEqual(plain(state.boardCells({ text: '14', width: 3, align: 'right' })), [' ', '1', '4']);
    assert.deepEqual(plain(state.boardCells({ text: 'Pavilion', width: 5 })), ['P', 'A', 'V', 'I', '…']);
    assert.equal(state.boardCells({ text: null, width: 4 }).length, 4);
});

test('a board row tells a screen reader what its cells show', () => {
    const row = state.boardRow({ row: { status: 'landed', source: 'craft', producer: 'claude-code', title: 'Fluid wallpapers', files: 5 }, time: '13:31' });
    assert.equal(row.from, 'Claude Code');
    assert.equal(row.quantity, '5');
    assert.equal(row.sentence, '13:31, Claude Code: Fluid wallpapers, 5 files, landed.');
});

test('a row without a title says what is happening to the upload', () => {
    const failed = state.boardRow({ row: { status: 'failed', source: 'upload', mine: true, title: null, files: 1 }, time: '12:20' });
    assert.equal(failed.cargo, 'Upload failed');
    assert.equal(failed.sentence, '12:20, You: Upload failed, 1 file, failed.');
    assert.equal(state.boardRow({ row: { status: 'stowing', phase: 'processing', source: 'upload', title: null, files: 3 }, time: '13:52' }).cargo, 'Processing upload');
});

test('the deck names its sources only when the board accounts for every new file', () => {
    const rows = [
        { status: 'landed', source: 'craft', producer: 'artcraft', files: 6 },
        { status: 'landed', source: 'craft', producer: 'blender', files: 2 },
        { status: 'landed', source: 'upload', mine: true, files: 1 },
        { status: 'seen', source: 'craft', producer: 'codex', files: 9 },
    ];
    assert.equal(state.deckSentence({ rows, files: 9, size: '48 MB' }), 'From two craft and one upload · 48 MB.');
    assert.equal(state.deckSentence({ rows, files: 30, size: '48 MB' }), '48 MB since your last visit.');
});

test('a shelf shows every picture when they all fit', () => {
    assert.deepEqual(plain(state.shelfPlan({ files: 3, previews: 3, full: 3, withMore: 3 })), { pictures: 3, more: 0 });
});

test('a shelf that cannot show everything counts the rest on its tile', () => {
    assert.deepEqual(plain(state.shelfPlan({ files: 6, previews: 6, full: 3, withMore: 3 })), { pictures: 3, more: 3 });
    assert.deepEqual(plain(state.shelfPlan({ files: 200, previews: 6, full: 3, withMore: 2 })), { pictures: 2, more: 198 });
});

test('a shelf counts files it has no picture for', () => {
    assert.deepEqual(plain(state.shelfPlan({ files: 2, previews: 1, full: 3, withMore: 3 })), { pictures: 1, more: 1 });
});

test('the shelf size leaves room for the tile and always keeps one picture', () => {
    const phone = { room: 336, tile: 150, least: 112, moreTile: 56, gap: 10 };
    assert.deepEqual(plain(state.shelfFit(phone)), { full: 2, withMore: 2 });
    assert.deepEqual(plain(state.shelfFit({ ...phone, room: 100 })), { full: 1, withMore: 1 });
});
