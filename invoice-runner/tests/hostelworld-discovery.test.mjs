import test from 'node:test';
import assert from 'node:assert/strict';
import { discoverPortal } from '../discover-portal.mjs';

test('Hostelworld discovery without a map records structure, never credentials or validation', async () => {
  let current = 'about:blank', evaluations = 0;
  const page = {
    goto: async url => { assert.equal(url, 'https://inbox.hostelworld.com/'); current = url; },
    url: () => current,
    evaluate: async () => ++evaluations === 1
      ? [{ tag: 'input', id: 'HostelNumber', classes: [], type: 'text' },
        { tag: 'form', id: 'loginForm', classes: [], action: current + '?token=PRIVATE_FIXTURE' }]
      : { ready_state: 'complete', form: true, password: true },
  };
  const result = await discoverPortal(page, { action: 'discover', portal: 'hostelworld',
    accountId: 2, credentials: { identifier: 'PRIVATE_USER', password: 'PRIVATE_PASSWORD' },
    map: { login: { url: 'https://evil.example/' } } });
  assert.equal(result.validated, false);
  assert.equal(result.authenticated_session, false);
  assert.equal(result.login_attempted, false);
  assert.equal(result.navigation_stage, 'login_structure');
  assert.equal(result.snapshots[0].hints[0].selector, '#HostelNumber');
  assert.doesNotMatch(JSON.stringify(result), /PRIVATE_|evil\.example/);
});

test('Hostelworld login still refuses a missing map', async () => {
  await assert.rejects(discoverPortal({}, { action: 'login', portal: 'hostelworld' }),
    /connector_unconfigured/);
});
