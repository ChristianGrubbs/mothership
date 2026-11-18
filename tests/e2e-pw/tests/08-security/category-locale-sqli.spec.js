const { test, expect } = require('@playwright/test');
const { login } = require('../../utils/login');

// Reproduces SQL injection via the unvalidated `locale` request param on the
// Category DataGrid (DatabaseProductQueryBuilder path, ELASTICSEARCH_ENABLED=false).
//
// The grid data is loaded by an AJAX call, so a plain browser navigation
// (request()->ajax() === false) only returns the HTML shell and never runs the
// vulnerable query. We must send the request with X-Requested-With.

const ENDPOINT = '/admin/catalog/categories';

const ajax = async (page, locale, expectedStatus = 200) => {
  const response = await page.request.get(`${ENDPOINT}?locale=${encodeURIComponent(locale)}`, {
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
    },
  });
  // A 401 must not make the malicious-locale checks pass without querying the grid.
  expect(response.status(), 'authenticated category browse must answer or validate the locale').toBe(expectedStatus);
  expect(response.headers()['content-type']).toContain('application/json');
  const body = await response.json();
  expect(body).toHaveProperty(expectedStatus === 200 ? 'records' : 'errors.locale');

  return response;
};

test.describe('Category DataGrid locale SQL injection', () => {
  // Earlier logout cases invalidate the session saved by global setup. Each
  // security case owns a fresh login and uses that same request cookie jar.
  test.use({ storageState: { cookies: [], origins: [] } });
  test.beforeEach(async ({ page }) => {
    await login(page);
    await expect(page).not.toHaveURL(/\/admin\/login(?:[?#].*)?$/);
  });

  test('baseline: a valid locale returns 200 JSON', async ({ page }) => {
    const res = await ajax(page, 'en_US');
    expect(res.status()).toBe(200);
  });

  test('a single quote in locale no longer breaks the SQL', async ({ page }) => {
    const res = await ajax(page, "en_US'", 422);
    // CategoryBrowseRequest rejects an unknown locale before it reaches SQL.
    expect(res.status()).not.toBe(500);
  });

  test('error-based version() payload leaks nothing', async ({ page }) => {
    const payload = "en_US.name',extractvalue(1,concat(0x7e,(select version()))),'";
    const res = await ajax(page, payload, 422);
    const body = await res.text();

    expect(res.status()).not.toBe(500);
    expect(body).not.toMatch(/XPATH syntax error/);
  });

  test('error-based database() payload leaks nothing', async ({ page }) => {
    const payload = "en_US.name',extractvalue(1,concat(0x7e,(select database()))),'";
    const res = await ajax(page, payload, 422);
    const body = await res.text();

    expect(res.status()).not.toBe(500);
    expect(body).not.toMatch(/XPATH syntax error/);
  });
});
