import test from 'node:test';
import assert from 'node:assert/strict';
import { errorDetails } from '../../src/Resources/assets/js/analytics/error-details.js';

const origin = 'https://space.test';
const details = (event, kind = 'error') => errorDetails({ event, kind, origin, pathname: '/admin/dam' });

test('error details include only known type and static same-origin bundle coordinates', () => {
    const actual = details({error: new TypeError('private asset title'), filename: origin + '/themes/dam/build/assets/app-Abc123_d.js?private=secret', lineno: 10, colno: 20});
    assert.deepEqual(actual, {error_type: 'TypeError', source_kind: 'bundle', bundle: 'app-Abc123_d.js', line: 10, column: 20});
    assert.ok(!JSON.stringify(actual).includes('private'));
    assert.deepEqual(details({error: {name: 'private-user-text'}, filename: origin + '/admin/assets/customer-name.js', lineno: 2}), {});
});

test('untrusted URLs, coordinates, messages and primitive rejections never escape', () => {
    for (const filename of [origin + '/storage/assets/private-Abc123_d.js', 'https://other.test/themes/dam/build/assets/app-Abc123_d.js', 'https://user:pass@space.test/themes/dam/build/assets/app-Abc123_d.js', 'javascript:alert(1)', '%broken']) {
        assert.deepEqual(details({filename, lineno: 1, colno: 2, message: 'private data'}), {});
    }
    for (const reason of ['private content', 2, null, undefined, false]) assert.deepEqual(details({reason}, 'rejection'), {});
    assert.deepEqual(details({filename: origin + '/themes/dam/build/assets/app-Abc123_d.js', lineno: -1, colno: '2'}), {source_kind: 'bundle', bundle: 'app-Abc123_d.js'});
});

test('a rejection finds only the first allowed static frame and ignores foreign getters', () => {
    const actual = details({reason: {name: 'TypeError', message: 'private title', stack: 'TypeError: private title\n at fn (https://other.test/themes/dam/build/assets/app-Abc123_d.js:8:9)\n at fn (https://space.test/themes/admin/default/build/assets/app-Abc123_d.js:11:22)\n at fn (https://space.test/themes/dam/build/assets/app-Xyz123_d.js:33:44)'}}, 'rejection');
    assert.deepEqual(actual, {error_type: 'TypeError', source_kind: 'bundle', bundle: 'app-Abc123_d.js', line: 11, column: 22});
    assert.deepEqual(details({reason: {get name() { throw new Error('private'); }}}, 'rejection'), {});
    assert.deepEqual(details({reason: {name: 'Error', get stack() { throw new Error('private'); }}}, 'rejection'), {error_type: 'Error'});
});

test('inline document coordinates omit its path and secret query while other assets are rejected', () => {
    const actual = details({filename: origin + '/admin/dam?private-name=private-value', lineno: 1200, colno: 30});
    assert.deepEqual(actual, {source_kind: 'document', line: 1200, column: 30});
    assert.ok(!JSON.stringify(actual).includes('private'));
    assert.deepEqual(details({filename: origin + '/admin/assets/customer-name?private=value', lineno: 20}), {});
    assert.deepEqual(details({reason: {name: 'Error', message: 'private', stack: 'Error: private\n at fn (https://space.test/admin/dam?private=value:1200:30)'}}, 'rejection'),
        {error_type: 'Error', source_kind: 'document', line: 1200, column: 30});
    assert.deepEqual(details({reason: {name: 'Error', stack: 'fn@https://space.test/admin/dam?private=value:1200:30'}}, 'rejection'),
        {error_type: 'Error', source_kind: 'document', line: 1200, column: 30});
});

test('multiline message URLs and imitated frames are not parsed as rejection coordinates', () => {
    for (const message of ['private\nhttps://space.test/admin/dam:12:34', 'private\n    at https://space.test/admin/dam:12:34']) {
        assert.deepEqual(details({reason: {name: 'TypeError', message, stack: `TypeError: ${message}`}}, 'rejection'), {error_type: 'TypeError'});
    }
    assert.deepEqual(details({reason: {name: 'TypeError', stack: 'TypeError: private\nhttps://space.test/admin/dam:12:34'}}, 'rejection'), {error_type: 'TypeError'});
});

test('a stale, absent or empty name cannot reopen a multiline V8 message as frames', () => {
    const message = 'private\n    at https://space.test/admin/dam:12:34';
    const tail = '\n    at fn (https://space.test/themes/dam/build/assets/app-Abc123_d.js:1:2)';
    for (const [name, header, expected] of [
        ['AbortError', 'Error: ', {error_type: 'AbortError'}],
        [undefined, 'Error: ', {}],
        ['', '', {}],
    ]) {
        assert.deepEqual(details({reason: {name, message, stack: header + message + tail}}, 'rejection'), expected);
    }
});

test('matching headers are stripped before frame syntax and message-first frames fail closed', () => {
    const tail = '\n    at fn (https://space.test/themes/dam/build/assets/three.module-BtCAaDxU.js:1:2)';
    const expected = {error_type: 'TypeError', source_kind: 'bundle', bundle: 'three.module-BtCAaDxU.js', line: 1, column: 2};
    const namedMessage = 'x@https://space.test/admin/dam:12:34';
    assert.deepEqual(details({reason: {name: 'TypeError', message: namedMessage, stack: 'TypeError: ' + namedMessage + tail}}, 'rejection'), expected);
    const emptyNameMessage = '    at https://space.test/admin/dam:12:34';
    assert.deepEqual(details({reason: {name: '', message: emptyNameMessage, stack: emptyNameMessage + tail}}, 'rejection'), {});
    assert.deepEqual(details({reason: {name: 'TypeError', message: '', stack: 'TypeError' + tail}}, 'rejection'), expected);
    assert.deepEqual(details({reason: {name: 'TypeError', message: 'private', stack: 'fn@https://space.test/themes/dam/build/assets/three.module-BtCAaDxU.js:1:2'}}, 'rejection'), expected);
});

test('dotted shipped chunks retain coordinates and an empty filename never becomes a document', () => {
    assert.deepEqual(details({filename: origin + '/themes/dam/build/assets/three.module-BtCAaDxU.js', lineno: 10, colno: 20}),
        {source_kind: 'bundle', bundle: 'three.module-BtCAaDxU.js', line: 10, column: 20});
    assert.deepEqual(errorDetails({event: {filename: '', lineno: 1, colno: 2}, kind: 'error', origin, pathname: '/'}), {});
});
