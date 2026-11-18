// Role editor, lists mode: a saved role whose hidden original capabilities include public_shares used to
// put already-ticked mandatory share keys into "restore", so damEnforceSaveRules re-dispatched
// tree-view:apply, the tree answered with tree-view:change, and the cycle never ended.
//
// This extracts the real damEnforceSaveRules from the Blade template and runs it against a minimal tree.
// DAM_ROLE_TAB_SOURCE overrides the template path (used to prove the test fails on the old version).
const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const source = fs.readFileSync(
  process.env.DAM_ROLE_TAB_SOURCE
    || path.join(__dirname, '../../../packages/Mothership/DAM/src/Resources/views/admin/roles/dam-permissions-tab.blade.php'),
  'utf8'
);

const start = source.indexOf('function damEnforceSaveRules');
const end = source.indexOf('// After a save the stored permissions', start);

assert.ok(start !== -1 && end !== -1, 'damEnforceSaveRules not found in the template');

const SHARE = ['dam.public_shares.create', 'dam.public_shares.view'];

function run({ tree, original, ticked }) {
  let applies = 0;
  const present = new Set(tree.available);
  const selected = new Set(tree.selected);
  let enforce;

  const root = {
    querySelector: (selector) => {
      const key = /value="([^"]+)"/.exec(selector)[1];
      return present.has(key) ? {} : null;
    },
    dispatchEvent: (event) => {
      applies += 1;
      assert.ok(applies < 20, 'tree-view:apply re-dispatched without end');
      event.detail.select.forEach((key) => selected.add(key));
      event.detail.unselect.forEach((key) => selected.delete(key));
      // The tree announces every apply as a change, which re-runs the rules.
      enforce([...selected], false);
    },
  };

  const factory = new Function(
    'root', 'original', 'ticked', 'CustomEvent', 'DAM_CAPABILITIES', 'DAM_MANDATORY_KEYS',
    `function damTreeRoot() { return root; }
     function damTickedCapabilities() { return ticked; }
     function damOriginalCapabilities() { return original; }
     function damNoteSync() {}
     ${source.slice(start, end)}
     return damEnforceSaveRules;`
  );

  enforce = factory(
    root, original, ticked,
    class { constructor(type, init) { this.type = type; this.detail = init.detail; } },
    { public_shares: SHARE, view: ['dam.view'] },
    SHARE
  );

  enforce([...selected], false);

  return { applies, selected };
}

test('hidden original public_shares with the share keys already ticked raises no apply', () => {
  const { applies } = run({
    tree: { available: SHARE, selected: SHARE },
    original: ['public_shares'],
    ticked: [],
  });

  assert.equal(applies, 0);
});

test('a missing mandatory share key is restored once and the cycle ends', () => {
  const { applies, selected } = run({
    tree: { available: SHARE, selected: [SHARE[0]] },
    original: ['public_shares'],
    ticked: [],
  });

  assert.equal(applies, 1);
  assert.deepEqual([...selected].sort(), [...SHARE].sort());
});
