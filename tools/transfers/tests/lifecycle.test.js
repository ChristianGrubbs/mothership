import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync, chmodSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
import { parseArguments, makePlan, applyPlan, LABEL } from '../deploy/lifecycle.js';
import { stateDirectory, replayPath } from '../server/state.js';

test('lifecycle requires explicit paths and keeps apply opt-in', () => {
    const args = ['--release', '/release', '--node', '/node', '--env-file', '/config.env',
        '--state-dir', '/state', '--plist', `/agents/${LABEL}.plist`];
    assert.equal(parseArguments(args).apply, false);
    assert.equal(parseArguments([...args, '--apply']).apply, true);
    assert.throws(() => parseArguments([...args, '--shell', 'bad']));
    assert.throws(() => parseArguments([...args, '--node', 'relative']));
    assert.throws(() => parseArguments([...args, '--domain', 'system']));
    assert.throws(() => applyPlan({ action: 'dry-run' }));
});

test('dry-run generates escaped launchd config, external state and rollback release without writes', () => {
    const directory = mkdtempSync(join(tmpdir(), 'transfers-lifecycle-'));
    try {
        const release = join(directory, 'release & current'); const old = join(directory, 'previous');
        for (const path of [release, old]) { mkdirSync(join(path, 'server'), { recursive: true }); writeFileSync(join(path, 'server/index.js'), ''); }
        const envFile = join(directory, 'service.env'); writeFileSync(envFile, '', { mode: 0o600 }); chmodSync(envFile, 0o600);
        const node = join(directory, 'node'); writeFileSync(node, '#!/bin/sh\nprintf "v26.12.0\\n"\n', { mode: 0o700 });
        const options = { release, node, 'env-file': envFile, 'state-dir': join(directory, 'state'),
            plist: join(directory, 'agents', `${LABEL}.plist`), domain: 'gui/501', apply: false };
        const before = readdirSync(directory);
        const plan = makePlan(options);
        assert.equal(plan.action, 'dry-run'); assert.match(plan.plist, /release &amp; current/);
        assert.match(plan.plist, /--env-file=/); assert.match(plan.plist, /TRANSFERS_STATE_DIR/);
        assert.match(plan.plist, /KeepAlive/); assert.equal(plan.label, LABEL);
        assert.deepEqual(readdirSync(directory), before);
        if (process.platform === 'darwin') {
            const rendered = join(directory, 'validated.plist');
            writeFileSync(rendered, plan.plist);
            assert.equal(spawnSync('/usr/bin/plutil', ['-lint', rendered], { timeout: 5000 }).status, 0);
        }
        const rollback = makePlan({ ...options, release: old });
        assert.equal(rollback.stateDir, plan.stateDir); assert.equal(rollback.envFile, plan.envFile);
        assert.match(rollback.plist, /previous/);
        assert.throws(() => makePlan({ ...options, 'state-dir': join(release, 'state') }), /runtime_path_inside_release/);
        chmodSync(envFile, 0o644);
        assert.throws(() => makePlan(options), /0600/);
        chmodSync(envFile, 0o600);
        writeFileSync(node, '#!/bin/sh\nprintf "v20.0.0\\n"\n');
        assert.throws(() => makePlan(options), /node22_or_newer/);
    } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('configured state path survives release path changes', () => {
    const previous = process.env.TRANSFERS_STATE_DIR;
    try {
        process.env.TRANSFERS_STATE_DIR = '/persistent/transfers';
        assert.equal(stateDirectory(), '/persistent/transfers');
        assert.equal(replayPath(), '/persistent/transfers/grant-replay.json');
        process.env.TRANSFERS_STATE_DIR = 'relative';
        assert.throws(() => stateDirectory(), /absolute/);
    } finally {
        if (previous === undefined) delete process.env.TRANSFERS_STATE_DIR;
        else process.env.TRANSFERS_STATE_DIR = previous;
    }
});
