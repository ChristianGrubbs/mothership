/**
 * Locale status over the REST API.
 *
 * The fork has no admin Locales screen, so specs that need a locale in a known
 * state read and set it through `/api/v1/rest/locales/{code}` instead.
 */
'use strict';

const api = require('./api');

async function requireToken(request) {
  const token = await api.authenticate(request);
  if (!token) {
    throw new Error('REST API authentication failed; locale setup needs API credentials');
  }

  return token;
}

/**
 * @returns {Promise<boolean>} whether the locale is active.
 */
async function isLocaleActive(request, code) {
  const token = await requireToken(request);
  const res = await api.get(request, `/locales/${code}`, { token });
  if (!res.ok) {
    throw new Error(`GET /locales/${code} → ${res.status}: ${JSON.stringify(res.body)}`);
  }

  return Number(res.body.status) === 1;
}

/**
 * Activate or deactivate a locale. Deactivation fails while a channel or user
 * still references the locale.
 */
async function setLocaleActive(request, code, active) {
  const token = await requireToken(request);
  const res = await api.put(request, `/locales/${code}`, { token, data: { status: active ? 1 : 0 } });
  if (!res.ok) {
    throw new Error(`PUT /locales/${code} → ${res.status}: ${JSON.stringify(res.body)}`);
  }
}

module.exports = { isLocaleActive, setLocaleActive };
