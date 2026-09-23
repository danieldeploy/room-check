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
    waitForFunction: async () => {},
    $$: async selector => {
      if (selector !== 'input') return [];
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
  const diagnostic = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' });
  assert.equal(diagnostic.failure_code, 'connector_unconfigured');
  assert.equal(diagnostic.failure_stage, 'identifier');
  assert.equal(diagnostic.validated, false);
  assert.deepEqual(page.typed, []);
  assert.ok(!JSON.stringify(diagnostic).includes('private@example.com'));
});

test('missing form action leaves a private diagnostic at the stopped step', async () => {
  const page = fakePage(null);
  const diagnostic = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' });
  assert.equal(diagnostic.failure_code, 'auth_unconfigured');
  assert.equal(diagnostic.failure_stage, 'identifier');
  assert.equal(diagnostic.snapshots.length, 1);
  assert.deepEqual(page.typed, []);
});

test('Booking sign-in ignores hidden password and submits loginname first', async () => {
  let step = 0;
  const typed = [];
  const make = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, name: id, autocomplete: '',
      maxLength: -1, action: 'https://account.booking.com/sign-in' }),
    type: async value => { typed.push([id, value]); },
    press: async () => { step++; },
  });
  const page = {
    url: () => 'https://account.booking.com/sign-in',
    goto: async () => {}, waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' ? [] : step === 0
      ? [make('hidden-password', 'password'), make('loginname', 'text')]
      : step === 1 ? [make('password', 'password')] : [],
  };
  const result = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' });
  assert.deepEqual(typed, [['loginname', 'private@example.com'], ['password', 'sensitive-password']]);
  assert.equal(result.login_attempted, true);
  assert.equal(result.failure_code, undefined);
});

test('waits through Booking loading state before inspecting password step', async () => {
  let stage = 0;
  let waits = 0;
  const typed = [];
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1,
      action: 'https://account.booking.com/sign-in' }),
    type: async value => { typed.push([id, value]); },
    press: async () => { stage = id === 'loginname' ? 1 : 3; },
  });
  const page = {
    url: () => 'https://account.booking.com/sign-in',
    goto: async () => {}, waitForNavigation: async () => {}, evaluate: async () => [],
    waitForFunction: async () => { waits++; if (stage === 1) stage = 2; },
    $$: async selector => selector !== 'input' ? [] : stage === 0 ? [field('loginname', 'text')]
      : stage === 1 ? [] : stage === 2 ? [field('password', 'password')] : [],
  };
  const result = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' });
  assert.equal(waits, 2);
  assert.equal(result.login_attempted, true);
  assert.deepEqual(typed, [['loginname', 'private@example.com'], ['password', 'sensitive-password']]);
});

test('Booking identifier uses the unique button belonging to the validated form', async () => {
  let step = 0;
  let clicks = 0;
  const typed = [];
  const action = 'https://account.booking.com/sign-in';
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1, action }),
    type: async value => { typed.push([id, value]); },
    press: async () => { if (id === 'loginname') throw new Error('should click submit'); step = 2; },
  });
  const button = {
    evaluate: async () => ({ visible: true, action }),
    click: async () => { clicks++; step = 1; },
  };
  const page = {
    url: () => action, goto: async () => {}, evaluate: async () => [],
    waitForNavigation: async () => {}, waitForFunction: async () => {},
    $$: async selector => selector === 'input'
      ? step === 0 ? [field('loginname', 'text')] : step === 1 ? [field('password', 'password')] : []
      : step === 0 ? [button] : [],
  };
  const result = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'password' });
  assert.equal(clicks, 1);
  assert.equal(result.identifier_submit, 'form_button');
  assert.equal(result.login_attempted, true);
  assert.deepEqual(typed, [['loginname', 'private@example.com'], ['password', 'sensitive-password']]);
});
