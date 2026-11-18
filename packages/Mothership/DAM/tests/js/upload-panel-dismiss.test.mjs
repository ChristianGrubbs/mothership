import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

/**
 * A completed upload card closes itself (2026-10-09: it stayed until closed by hand and came
 * back on reload). Loads v-dam-drop-upload from its Blade file with controllable timers.
 */
function loadDropUpload({ adminId = 1, storage = { getItem: () => null, setItem() {}, removeItem() {} } } = {}) {
    const blade = readFileSync(
        new URL('../../src/Resources/views/components/asset/drop-upload.blade.php', import.meta.url),
        'utf8',
    );
    let script = [...blade.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)]
        .map(match => match[1])
        .find(source => source.includes("app.component('v-dam-drop-upload'"));

    assert.ok(script, 'v-dam-drop-upload not found');

    // @js() and @json() print a JS literal; neither runs outside Blade.
    script = script.split('\n').map(line => {
        let out = line;
        for (let start = out.search(/@js(?:on)?\(/); start !== -1; start = out.search(/@js(?:on)?\(/)) {
            let depth = 0;
            let i = out.indexOf('(', start);
            for (; i < out.length; i++) {
                if (out[i] === '(') depth++;
                else if (out[i] === ')') { depth--; if (depth === 0) break; }
            }
            const expression = out.slice(start, i + 1);
            const literal = expression === "@js(auth('admin')->id())" ? JSON.stringify(adminId)
                : expression.includes('resume_stale_hours') ? '24' : '0';
            out = out.slice(0, start) + literal + out.slice(i + 1);
        }
        return out;
    }).filter(line => !/^\s*@(if|elseif|else|endif|once|endonce|push|endpush)\b/.test(line)).join('\n');

    const timers = new Map();
    let nextTimer = 1;
    const clock = {
        setTimeout: (fn, ms) => { const id = nextTimer++; timers.set(id, { fn, ms }); return id; },
        clearTimeout: id => { timers.delete(id); },
        // Runs every pending timer of the given delay once, like that much time passing.
        advance(ms) {
            for (const [id, timer] of [...timers]) {
                if (timer.ms === ms) { timers.delete(id); timer.fn(); }
            }
        },
        pending: ms => [...timers.values()].filter(timer => timer.ms === ms).length,
    };

    let component;
    vm.runInNewContext(script, {
        app: { component: (_name, definition) => { component = definition; } },
        window: {},
        document: {},
        localStorage: storage,
        setTimeout: clock.setTimeout,
        clearTimeout: clock.clearTimeout,
    });

    const vmInstance = Object.assign({}, component.data.call({}), component.methods);

    return { vm: vmInstance, clock };
}

const DISMISS_MS = 6000;
const finished = id => ({ id, jobs: [], doneCount: 1, errorCount: 0 });

test('saved filenames and resumable sessions stay with their account across A/B/A reloads', () => {
    const values = new Map();
    const storage = {
        getItem: key => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: key => values.delete(key),
    };
    const session = (id, name) => ({
        id, uuid: `session-${id}`, targetDirId: 7, resumable: true,
        jobs: [{ id, name, status: 'uploading', fileSize: 100 }],
    });
    values.set('dam_upload_state', JSON.stringify({ activeSessions: [session(99, 'legacy-private.png')] }));

    const a = loadDropUpload({ adminId: 11, storage }).vm;
    a.isPrimary = true;
    a.restoreState();
    assert.equal(values.has('dam_upload_state'), false);
    assert.equal(a.activeSessions.length, 0, 'legacy global state is never imported');
    a.activeSessions = [session(1, 'a-private.png')];
    a.persistNow();

    const b = loadDropUpload({ adminId: 22, storage }).vm;
    b.isPrimary = true;
    b.restoreState();
    assert.equal(b.activeSessions.length, 0, 'B cannot restore A filenames or sessions');
    b.activeSessions = [session(2, 'b-private.png')];
    b.persistNow();

    const aReloaded = loadDropUpload({ adminId: 11, storage }).vm;
    aReloaded.restoreState();
    assert.equal(aReloaded.activeSessions.length, 1);
    assert.equal(aReloaded.activeSessions[0].uuid, 'session-1');
    assert.equal(aReloaded.activeSessions[0].jobs[0].name, 'a-private.png');
    assert.equal(aReloaded.activeSessions[0].jobs[0].status, 'interrupted');

    b.activeSessions = [];
    b.persistNow();
    const aAfterBClears = loadDropUpload({ adminId: 11, storage }).vm;
    aAfterBClears.restoreState();
    assert.equal(aAfterBClears.activeSessions[0].jobs[0].name, 'a-private.png');
});

test('a completed card closes itself after the dismiss delay', () => {
    const { vm, clock } = loadDropUpload();
    vm.activeSessions = [finished(1)];

    vm.archiveSession(vm.activeSessions[0]);
    assert.equal(vm.sessions.length, 1);

    clock.advance(DISMISS_MS);
    assert.equal(vm.sessions.length, 0);
    assert.deepEqual(Object.keys(vm._dismissTimers), []);
});

test('a hovered or expanded card waits until the user leaves it', () => {
    const { vm, clock } = loadDropUpload();
    vm.activeSessions = [finished(1)];
    vm.archiveSession(vm.activeSessions[0]);

    vm.hoveredSessionId = 1;
    clock.advance(DISMISS_MS);
    assert.equal(vm.sessions.length, 1);

    vm.hoveredSessionId = null;
    vm.sessions[0].minimized = false;
    clock.advance(DISMISS_MS);
    assert.equal(vm.sessions.length, 1);

    vm.sessions[0].minimized = true;
    clock.advance(DISMISS_MS);
    assert.equal(vm.sessions.length, 0);
});

test('closing a card by hand cancels its timer and drops the record', () => {
    const { vm, clock } = loadDropUpload();
    vm.activeSessions = [finished(1)];
    vm.archiveSession(vm.activeSessions[0]);

    vm.removeSession(1);
    assert.equal(clock.pending(DISMISS_MS), 0);
    assert.deepEqual(Object.keys(vm._dismissTimers), []);
});
