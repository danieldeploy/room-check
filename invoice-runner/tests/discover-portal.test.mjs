import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { discoverPortal, bookingLoginCode } from '../discover-portal.mjs';

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

test('existing Booking extranet session is inspected without credential submission', async () => {
  let reloads = 0; let navigations = 0;
  const page = {
    url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/',
    reload: async () => { reloads++; }, goto: async () => { navigations++; },
    evaluate: async () => [],
  };
  const diagnostic = await discoverPortal(page, { portal: 'booking',
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'sms' });
  assert.equal(reloads, 1);
  assert.equal(navigations, 0);
  assert.equal(diagnostic.authenticated_session, true);
  assert.equal(diagnostic.validated, false);
  assert.equal(diagnostic.login_attempted, false);
  assert.equal(bookingLoginCode(diagnostic), 'session_active');
  assert.ok(!JSON.stringify(diagnostic).includes('private@example.com'));
});

test('Booking login confirms extranet only after submitting identifier and password', async () => {
  let url = 'about:blank'; let step = 0;
  const typed = [];
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1,
      action: 'https://account.booking.com/sign-in' }),
    type: async value => { typed.push(value); },
    press: async () => { if (++step === 2) url = 'https://admin.booking.com/hotel/hoteladmin/'; },
  });
  const page = {
    url: () => url,
    goto: async () => { url = 'https://account.booking.com/sign-in'; },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' ? [] : step === 0
      ? [field('loginname', 'text')] : step === 1 ? [field('password', 'password')] : [],
  };
  const result = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' }, authMethod: 'password' });
  assert.deepEqual(typed, ['private@example.com', 'sensitive-password']);
  assert.equal(result.authenticated_session, true);
  assert.equal(result.login_attempted, true);
  assert.equal(bookingLoginCode(result), 'ok');
  assert.equal(result.validated, false);
  assert.ok(!JSON.stringify(result).includes('private@example.com'));
});

test('expired controlled Chrome session attempts username and password again', async () => {
  let url = 'https://admin.booking.com/hotel/hoteladmin/groups/home/';
  let stage = 0;
  const typed = [];
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1,
      action: 'https://account.booking.com/sign-in' }),
    type: async value => { typed.push([id, value]); },
    press: async () => {
      if (++stage === 2) url = 'https://admin.booking.com/hotel/hoteladmin/groups/home/';
    },
  });
  const page = {
    url: () => url,
    reload: async () => { url = 'https://account.booking.com/sign-in'; },
    goto: async () => { url = 'https://account.booking.com/sign-in'; },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' ? [] : stage === 0
      ? [field('loginname', 'text')] : stage === 1 ? [field('password', 'password')] : [],
  };
  const diagnostic = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' }, authMethod: 'password' });
  assert.deepEqual(typed, [['loginname', 'private@example.com'], ['password', 'sensitive-password']]);
  assert.equal(bookingLoginCode(diagnostic), 'ok');
  assert.equal(diagnostic.login_attempted, true);
});

test('human verification stops login before password and reports a distinct diagnostic', async () => {
  let url = 'about:blank'; const typed = [];
  const page = {
    url: () => url,
    goto: async () => { url = 'https://account.booking.com/sign-in?op_token=secret'; },
    waitForFunction: async () => {}, evaluate: async () => [],
    $$: async () => { throw new Error('credentials must not be requested'); },
  };
  const result = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' }, authMethod: 'password' });
  assert.deepEqual(typed, []);
  assert.equal(result.failure_code, 'human_verification');
  assert.equal(bookingLoginCode(result), 'human_verification');
  assert.equal(result.login_attempted, false);
  assert.ok(!JSON.stringify(result).includes('secret'));
});

test('response diagnostics release their listener on success and failure', async () => {
  for (const action of ['https://account.booking.com/login', 'https://evil.example/receive']) {
    const page = fakePage(action);
    const events = new EventEmitter();
    page.on = events.on.bind(events);
    page.off = events.off.bind(events);
    const goto = page.goto;
    page.goto = async (...args) => {
      assert.equal(events.listenerCount('response'), 1);
      return goto(...args);
    };
    const result = await discoverPortal(page, { portal: 'booking',
      credentials: { identifier: 'private@example.com', password: 'sensitive-password' }, authMethod: 'password' });
    assert.equal(result.failure_code === undefined, !action.includes('evil.example'));
    assert.equal(events.listenerCount('response'), 0);
  }
});
