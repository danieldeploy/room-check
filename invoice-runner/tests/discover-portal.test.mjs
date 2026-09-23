import test from 'node:test';
import assert from 'node:assert/strict';
import { discoverPortal } from '../discover-portal.mjs';

function fakePage(action = 'https://account.booking.com/login') {
  let url = 'https://admin.booking.com/';
  let stage = 0;
  const typed = [];
  const field = (type, autocomplete) => ({
    evaluate: async () => ({ visible: true, type, autocomplete, maxLength: -1, action }),
    type: async value => { typed.push(value); },
    press: async () => { url = 'https://account.booking.com/login'; stage++; },
  });
  return {
    typed,
    url: () => url,
    goto: async () => { url = 'https://account.booking.com/login'; },
    waitForNavigation: async () => {},
    $$: async () => {
      if (stage === 0) return [field('email','username')];
      if (stage === 1) return [field('password','current-password')];
      return [];
    },
    evaluate: async () => [],
  };
}
test('discovery uses saved credentials without serializing them or validating the map', async () => {
  const page = fakePage();
  const result = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' });
  assert.deepEqual(page.typed, ['private@example.com', 'sensitive-password']);
  assert.equal(result.validated, false);
  assert.equal(result.login_attempted, true);
  assert.ok(!JSON.stringify(result).includes('private@example.com'));
  assert.ok(!JSON.stringify(result).includes('sensitive-password'));
});
test('credentials are never sent to a foreign form action', async () => {
  const page = fakePage('https://evil.example/receive');
  await assert.rejects(discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' }), /connector_unconfigured/);
  assert.deepEqual(page.typed, ['private@example.com']);
});
