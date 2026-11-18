import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('classic and Explorer cards have no preview hover overlay', () => {
    for (const card of ['shared', 'explorer']) {
        const template = readFileSync(`packages/Mothership/DAM/src/Resources/views/components/${card}/asset-card.blade.php`, 'utf8').split('<script type="module">')[0];
        assert.doesNotMatch(template, /group-hover:opacity-100[\s\S]*icon-dam-preview/);
    }
});
