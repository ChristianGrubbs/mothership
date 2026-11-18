const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../../public/pwa/register.js'), 'utf8');

function fixture({standalone = false, throwing = false} = {}) {
    const handlers = {}, docHandlers = {}, registrationHandlers = {}, workerHandlers = {}, captured = [];
    const registration = {waiting: null, installing: {addEventListener: (name, fn) => workerHandlers[name] = fn},
        addEventListener: (name, fn) => registrationHandlers[name] = fn, update: async () => {}};
    const window = {isSecureContext: true, damTrack: (...args) => {if (throwing) throw Error('capture');captured.push(JSON.parse(JSON.stringify(args)));}};
    const document = {readyState: 'complete', documentElement: {dataset: {}}, addEventListener: (name, fn) => docHandlers[name] = fn};
    vm.runInNewContext(source, {window, document, navigator: {serviceWorker: {controller: {}, register: async () => registration}},
        matchMedia: () => ({matches: standalone}), addEventListener: (name, fn) => handlers[name] = fn});
    return {handlers, docHandlers, registrationHandlers, workerHandlers, registration, captured};
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('PWA reports eligibility, actual prompt choice, installed and waiting update as distinct milestones', async () => {
    const f = fixture();await settle();
    let prompted = 0;
    f.handlers.beforeinstallprompt({preventDefault() {}, prompt: async () => prompted++, userChoice: Promise.resolve({outcome: 'dismissed'})});
    await f.docHandlers.click({target: {closest: () => true}, preventDefault() {}});
    assert.equal(prompted, 1);
    assert.deepEqual(f.captured.map(x => x[0]), ['pwa.installable', 'pwa.install.prompted', 'pwa.install.choice']);
    assert.deepEqual(f.captured[2][2], {choice: 'dismissed'});
    f.handlers.appinstalled();
    f.registrationHandlers.updatefound();
    f.workerHandlers.statechange();
    assert.equal(f.captured.some(x => x[0] === 'pwa.update.ready'), false);
    f.registration.waiting = {};
    f.workerHandlers.statechange();f.workerHandlers.statechange();
    assert.equal(f.captured.filter(x => x[0] === 'pwa.update.ready').length, 1);
    assert.equal(f.captured.filter(x => x[0] === 'pwa.installed').length, 1);
});

test('standalone launch is observed and broken capture never interrupts install handling', async () => {
    assert.deepEqual(fixture({standalone: true}).captured.map(x => x[0]), ['pwa.standalone.opened']);
    const f = fixture({throwing: true});await settle();
    let prompted = false;
    assert.doesNotThrow(() => f.handlers.beforeinstallprompt({preventDefault() {}, prompt: async () => {prompted = true;}, userChoice: Promise.resolve({outcome: 'accepted'})}));
    await f.docHandlers.click({target: {closest: () => true}, preventDefault() {}});
    assert.equal(prompted, true);
});
