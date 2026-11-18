const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function settings() {
    const source = fs.readFileSync(path.resolve(__dirname, '../../../packages/Mothership/DAM/src/Resources/views/configuration/capture.blade.php'), 'utf8');
    const script = source.match(/<script type="module">([\s\S]*?)<\/script>/)[1].replace(/@js\(route\([^\n]*?\)\)/g, "'/endpoint'");
    let component;
    vm.runInNewContext(script, { app: { component(name, value) { component = value; } }, setTimeout, clearTimeout });
    const events = [];
    const context = { initial: { layouts: [], fields: {}, profiles: [], can_update: true }, $emitter: { emit(...args) { events.push(args); } } };
    Object.assign(context, component.data.call(context));
    for (const [key, value] of Object.entries(component.methods)) context[key] = value.bind(context);
    return { context, events };
}

test('saving suppresses duplicate requests and acknowledges only a completed save', async () => {
    const { context, events } = settings();
    let complete;
    let requests = 0;
    const request = () => { requests++; return new Promise(resolve => { complete = resolve; }); };
    const pending = context.run('layouts', request, 'Details saved.');
    assert.equal(context.feedback.layouts.state, 'saving');
    assert.equal(events.length, 0);
    await context.run('layouts', request, 'Details saved.');
    assert.equal(requests, 1);
    complete();
    await pending;
    assert.equal(context.feedback.layouts.state, 'saved');
    assert.equal(context.feedback.layouts.message, 'Details saved.');
    assert.equal(events[0][0], 'add-flash');
    assert.equal(events[0][1].type, 'success');
});

test('failed save keeps edits and exposes validation error before successful retry', async () => {
    const { context, events } = settings();
    context.draft.name = 'Drone photos';
    await context.run('profile', async () => { throw { response: { data: { errors: { name: ['Name is already used.'] } } } }; }, 'Profile saved.');
    assert.equal(context.feedback.profile.state, 'error');
    assert.match(context.feedback.profile.message, /Name is already used/);
    assert.equal(context.draft.name, 'Drone photos');
    assert.equal(context.busy, false);
    assert.equal(events[0][1].type, 'error');
    await context.run('profile', async () => {}, 'Profile saved.');
    assert.equal(context.feedback.profile.state, 'saved');
});
