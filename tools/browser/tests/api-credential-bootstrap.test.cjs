const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.resolve(__dirname,
  '../../../tests/e2e-pw/utils/api-credential-setup.js'), 'utf8');

// Exercise the actual bootstrap decision, replacing only the browser/network
// integration creation and config-file sink. No request or credential is logged.
function bootstrap({ env = {}, existing = {} } = {}) {
  const calls = [];
  const context = vm.createContext({
    module: { exports: {} }, __dirname, process: { env }, calls, URL,
    require(name) {
      if (name === '@playwright/test') return {};
      if (name === './api-config') return { getCredentials: () => existing };
      return require(name);
    },
  });
  vm.runInContext(source, context);
  vm.runInContext('createApiIntegration = async options => { calls.push(options); return { saved: true }; }; writeConfig = () => {};', context);
  return { ensure: context.module.exports.ensureApiCredentials, calls };
}

for (const baseUrl of ['http://127.0.0.1:18421', 'http://127.0.0.1:18421/', 'http://[::1]:18003']) {
  test(`explicit disposable-fixture opt-in allows defaults on ${baseUrl}`, async () => {
    const { ensure, calls } = bootstrap({ env: { PW_DISPOSABLE_FIXTURE: '1' } });
    await ensure({ baseUrl });
    assert.equal(calls.length, 1);
    assert.equal(calls[0].adminEmail, 'admin@example.com');
    assert.equal(calls[0].adminPassword, 'admin123');
  });
}

for (const baseUrl of [
  'https://example.invalid', 'http://localhost:18421',
  'https://127.0.0.1:18421', 'http://127.0.0.1:18421/app',
  'http://127.0.0.1:18421?target=remote', 'http://127.0.0.1:18421/#fragment',
  'http://127.0.0.1:18421.example.invalid', 'invalid-url', 'http://admin@127.0.0.1:18421',
]) {
  test(`even disposable opt-in rejects implicit credentials for ${baseUrl}`, async () => {
    const { ensure, calls } = bootstrap({ env: { PW_DISPOSABLE_FIXTURE: '1' } });
    await assert.rejects(ensure({ baseUrl }), /Missing: admin email, admin password/);
    assert.equal(calls.length, 0);
  });
}

test('CI alone does not authorize demo defaults on a remote target', async () => {
  const { ensure, calls } = bootstrap({ env: { CI: '1' } });
  await assert.rejects(ensure({ baseUrl: 'https://example.invalid' }), /Missing: admin email, admin password/);
  assert.equal(calls.length, 0);
});

test('unflagged loopback does not authorize demo defaults', async () => {
  const { ensure, calls } = bootstrap();
  await assert.rejects(ensure({ baseUrl: 'http://127.0.0.1:8001' }), /Missing: admin email, admin password/);
  assert.equal(calls.length, 0);
});

test('a truthy-looking opt-in other than the explicit value does not authorize defaults', async () => {
  const { ensure, calls } = bootstrap({ env: { PW_DISPOSABLE_FIXTURE: 'true' } });
  await assert.rejects(ensure({ baseUrl: 'http://127.0.0.1:8001' }), /Missing: admin email, admin password/);
  assert.equal(calls.length, 0);
});

for (const port of ['8001', '8002']) {
  test(`opted-in CI fixtures support dynamically allocated app port ${port}`, async () => {
    const { ensure, calls } = bootstrap({ env: { CI: '1', APP_PORT: port, PW_DISPOSABLE_FIXTURE: '1' } });
    await ensure({ baseUrl: `http://127.0.0.1:${port}` });
    assert.equal(calls.length, 1);
    assert.equal(calls[0].adminEmail, 'admin@example.com');
  });
}

test('explicit remote admin arguments are preserved', async () => {
  const { ensure, calls } = bootstrap();
  await ensure({ baseUrl: 'https://example.invalid', adminEmail: 'admin@example.invalid', adminPassword: 'p' });
  assert.equal(calls[0].adminEmail, 'admin@example.invalid');
  assert.equal(calls[0].adminPassword, 'p');
});

test('explicit admin environment works on remote targets', async () => {
  const { ensure, calls } = bootstrap({ env: { ADMIN_EMAIL: 'admin@example.invalid', ADMIN_PASSWORD: 'p' } });
  await ensure({ baseUrl: 'https://example.invalid' });
  assert.equal(calls[0].adminEmail, 'admin@example.invalid');
  assert.equal(calls[0].adminPassword, 'p');
});

test('ADMIN_USERNAME takes precedence over ADMIN_EMAIL, and arguments override both', async () => {
  const { ensure, calls } = bootstrap({ env: {
    ADMIN_USERNAME: 'username@example.invalid', ADMIN_EMAIL: 'email@example.invalid', ADMIN_PASSWORD: 'p',
  } });
  await ensure({ baseUrl: 'https://example.invalid' });
  await ensure({ baseUrl: 'https://example.invalid', adminEmail: 'argument@example.invalid', adminPassword: 'q' });
  assert.equal(calls[0].adminEmail, 'username@example.invalid');
  assert.equal(calls[1].adminEmail, 'argument@example.invalid');
  assert.equal(calls[1].adminPassword, 'q');
});

for (const [settings, missing] of [
  [{ adminEmail: 'admin@example.invalid' }, 'admin password'],
  [{ adminPassword: 'p' }, 'admin email'],
]) {
  test(`partial remote settings retain useful missing ${missing} error`, async () => {
    const { ensure, calls } = bootstrap({ existing: { username: 'robot@example.invalid', password: 'r' } });
    await assert.rejects(ensure({ baseUrl: 'https://example.invalid', ...settings }), new RegExp(`Missing: ${missing}\\.`));
    assert.equal(calls.length, 0);
  });
}

test('robot credentials never replace missing human admin settings', async () => {
  const { ensure, calls } = bootstrap({ existing: { username: 'robot@example.invalid', password: 'r' } });
  await assert.rejects(ensure({ baseUrl: 'https://example.invalid' }), /Missing: admin email, admin password/);
  assert.equal(calls.length, 0);
});

test('an already configured API client needs no human admin bootstrap', async () => {
  const existing = { client_id: 'fixture', client_secret: 's', username: 'robot@example.invalid', password: 'r' };
  const { ensure, calls } = bootstrap({ existing });
  assert.equal(await ensure({ baseUrl: 'https://example.invalid' }), existing);
  assert.equal(calls.length, 0);
});
