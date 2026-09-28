import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { discoverPortal, bookingLoginCode } from '../discover-portal.mjs';

test('a property switch awaits group responses that begin after the authenticated reload', async () => {
  const page = new EventEmitter();
  const target = 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=539828&ses=PRIVATE_FIXTURE';
  const group = 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html';
  let current = target.replace('539828', '1140306'), step = 0;
  const visits = [];
  const row = { evaluate: async () => true, $$: async () => [] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  page.url = () => current;
  page.reload = async () => {};
  page.evaluate = async () => [];
  page.waitForFunction = async () => {};
  page.waitForNavigation = async () => {};
  page.goto = async href => {
    visits.push(href);
    if (href === 'https://admin.booking.com/') {
      current = group; step = 1;
      page.emit('response', {
        request: () => ({ resourceType: () => 'fetch', method: () => 'POST' }),
        url: () => 'https://admin.booking.com/dml/graphql.json', status: () => 200,
        json: async () => { await delay(25); return { data: { partnerProperty: { propertyListv2: {
          properties: [{ id: 539828, extranetUrl: target }],
        } } } }; },
      });
    } else { assert.equal(href, target); current = target; step = 2; }
  };
  page.$$ = async selector => selector === 'tr,[role="row"]' ? [row]
    : selector === 'a[href]' ? [] : step === 2 ? [control('finance', 3)]
      : step === 3 ? [control('invoices', 4)] : [];
  const result = await discoverPortal(page, { portal: 'booking', property: '539828',
    propertyLabel: 'Two', period: '2026-08', authMethod: 'password' });
  assert.equal(result.navigation_stage, 'invoices_visible');
  assert.equal(result.authenticated_session, true);
  assert.equal(result.login_attempted, false);
  assert.equal(result.validated, false);
  assert.deepEqual(visits, ['https://admin.booking.com/', target]);
  assert.equal(JSON.stringify(result).includes('PRIVATE_FIXTURE'), false);
  assert.equal(page.listenerCount('response'), 0);
});

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
    waitForFunction: async fn => {
      if (fn.name === 'visibleRejectButton') return;
      waits++; if (stage === 1) stage = 2;
    },
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

function freshKeyboardPage(keyboardError) {
  const action = 'https://account.booking.com/sign-in';
  let url = action; let stage = 0; let presses = 0;
  const typed = [];
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1, action }),
    type: async value => { typed.push([id, value]); },
    press: async () => {
      if (id === 'loginname') throw new Error('identifier field press must not be used');
      url = 'https://admin.booking.com/hotel/hoteladmin/'; stage = 2;
    },
  });
  return {
    typed, get presses() { return presses; },
    page: {
      url: () => url,
      goto: async () => { throw new Error('must reuse the sign-in tab'); },
      waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
      keyboard: { press: async key => {
        assert.equal(key, 'Enter'); presses++;
        if (keyboardError) throw keyboardError;
        stage = 1;
      } },
      $$: async selector => {
        if (selector !== 'input') throw new Error('identifier button must not be queried');
        return stage === 0 ? [field('loginname', 'text')]
          : stage === 1 ? [field('password', 'password')] : [];
      },
    },
  };
}

test('fresh Booking identifier uses one keyboard Enter and never clicks a submit button', async () => {
  const fake = freshKeyboardPage();
  const result = await discoverPortal(fake.page, { portal: 'booking', loginOnly: true, accountId: 1,
    browserProfile: 'fresh_login', credentials: { identifier: 'private@example.com', password: 'private-password' },
    authMethod: 'password' });
  assert.equal(result.identifier_submit, 'enter');
  assert.equal(result.authenticated_session, true);
  assert.equal(fake.presses, 1);
  assert.deepEqual(fake.typed, [['loginname', 'private@example.com'], ['password', 'private-password']]);
  assert.ok(!JSON.stringify(result).includes('private@example.com'));
  assert.ok(!JSON.stringify(result).includes('private-password'));
});

test('fresh Booking identifier follows the new password tab within the same login run', async () => {
  const oldTab = new EventEmitter();
  const passwordTab = new EventEmitter();
  const action = 'https://account.booking.com/sign-in';
  let opened = false; let keyboardSubmissions = 0; let passwordSubmissions = 0;
  let passwordUrl = 'https://auth.booking.com/u/login/password?state=PRIVATE_STATE';
  let passwordFocused = false; let passwordSelected = false; let passwordValue = '';
  oldTab.url = () => action;
  oldTab.goto = async () => { throw new Error('existing identifier tab must be reused'); };
  oldTab.waitForNavigation = async () => {};
  oldTab.waitForFunction = async () => {};
  oldTab.evaluate = async () => [];
  oldTab.keyboard = { press: async key => { assert.equal(key, 'Enter'); keyboardSubmissions++; opened = true; } };
  oldTab.$$ = async selector => selector === 'input' ? [{
    evaluate: async () => ({ visible: true, id: 'loginname', type: 'text', autocomplete: '', action }),
    type: async value => assert.equal(value, 'PRIVATE_IDENTIFIER'),
    press: async () => { throw new Error('identifier field must not press Enter'); },
  }] : [];
  passwordTab.url = () => passwordUrl;
  passwordTab.waitForNavigation = async () => {};
  passwordTab.waitForFunction = async () => {};
  passwordTab.evaluate = async () => [];
  passwordTab.$$ = async selector => selector === 'input' && passwordSubmissions === 0 ? [{
    evaluate: async fn => fn.toString().includes('document.activeElement')
      ? passwordFocused && passwordValue.length === 0
      : { visible: true, id: 'password', type: 'password', autocomplete: '',
        action: 'https://auth.booking.com/u/login/password?state=PRIVATE_ACTION' },
    type: async value => { assert.equal(passwordValue.length, 0); passwordValue = value;
      assert.equal(value, 'PRIVATE_PASSWORD'); },
    press: async key => {
      if (key === 'Control+A') { passwordFocused = true; passwordSelected = true; }
      else if (key === 'Backspace') { if (passwordSelected) passwordValue = ''; passwordSelected = false; }
      else if (key === 'Enter') {
        passwordSubmissions++;
        passwordUrl = 'https://admin.booking.com/hotel/hoteladmin/';
      } else throw new Error('unexpected keyboard action');
    },
  }] : [];
  let guarded = false;
  const result = await discoverPortal(oldTab, { portal: 'booking', loginOnly: true, accountId: 1,
    browserProfile: 'fresh_login', credentials: { identifier: 'PRIVATE_IDENTIFIER', password: 'PRIVATE_PASSWORD' },
    authMethod: 'password' }, { passwordTab: async current => {
      assert.equal(current, oldTab);
      if (!opened) return null;
      guarded = true; // Runner's callback installs the request guard before handing over the tab.
      return passwordTab;
    } });
  assert.equal(guarded, true);
  assert.equal(keyboardSubmissions, 1);
  assert.equal(passwordSubmissions, 1);
  assert.equal(result.authenticated_session, true);
  assert.equal(result.login_attempted, true);
  assert.equal(oldTab.listenerCount('response'), 0);
  assert.equal(passwordTab.listenerCount('response'), 0);
  for (const secret of ['PRIVATE_IDENTIFIER', 'PRIVATE_PASSWORD', 'PRIVATE_STATE', 'PRIVATE_ACTION'])
    assert.ok(!JSON.stringify(result).includes(secret));
});

test('a fresh Booking keyboard CDP timeout is reported without retrying identifier', async () => {
  const secret = 'PRIVATE_IDENTIFIER_AND_PROTOCOL_ERROR';
  const error = new Error(`Input.dispatchKeyEvent timed out: ${secret}`);
  error.name = 'ProtocolError';
  const fake = freshKeyboardPage(error);
  const result = await discoverPortal(fake.page, { portal: 'booking', loginOnly: true, accountId: 1,
    browserProfile: 'fresh_login', credentials: { identifier: secret, password: 'private-password' },
    authMethod: 'password' });
  assert.equal(result.failure_code, 'browser_unavailable');
  assert.equal(result.failure_stage, 'identifier');
  assert.equal(result.identifier_phase, 'submit_action');
  assert.equal(result.browser_error_kind, 'timeout');
  assert.equal(result.identifier_submit, null);
  assert.equal(fake.presses, 1);
  assert.deepEqual(fake.typed, [['loginname', secret]]);
  assert.ok(!JSON.stringify(result).includes(secret));
  assert.ok(!JSON.stringify(result).includes('private-password'));
});

test('Booking identifier browser failures report only a fixed phase and error category', async t => {
  const action = 'https://account.booking.com/sign-in';
  const secret = 'PRIVATE_IDENTIFIER_AND_BROWSER_ERROR_DETAIL';
  const cases = [
    { point: 'type', message: `Node is detached from document: ${secret}`,
      phase: 'typing', kind: 'detached', typed: 1, submitted: 0 },
    { point: 'buttons', message: `Execution context was destroyed: ${secret}`,
      phase: 'button_lookup', kind: 'context_lost', typed: 1, submitted: 0 },
    { point: 'button_evaluate', message: `Cannot find context with specified id: ${secret}`,
      phase: 'button_lookup', kind: 'context_lost', typed: 1, submitted: 0 },
    { point: 'click', message: `Target closed: ${secret}`,
      phase: 'submit_action', kind: 'browser_closed', typed: 1, submitted: 1 },
    { point: 'press', message: `Unexpected browser state: ${secret}`,
      phase: 'submit_action', kind: 'other', typed: 1, submitted: 1 },
  ];
  for (const scenario of cases) await t.test(scenario.point, async () => {
    let typed = 0, submitted = 0;
    const field = {
      evaluate: async () => ({ visible: true, id: 'loginname', type: 'text', autocomplete: '', action }),
      type: async () => { typed++; if (scenario.point === 'type') throw new Error(scenario.message); },
      press: async () => { submitted++; if (scenario.point === 'press') throw new Error(scenario.message); },
    };
    const button = {
      evaluate: async () => {
        if (scenario.point === 'button_evaluate') throw new Error(scenario.message);
        return { visible: true, action };
      },
      click: async () => { submitted++; if (scenario.point === 'click') throw new Error(scenario.message); },
    };
    const page = {
      url: () => action, goto: async () => { throw new Error('must reuse the sign-in tab'); },
      waitForFunction: async () => {}, evaluate: async () => [],
      $$: async selector => {
        if (selector === 'input') return [field];
        if (scenario.point === 'buttons') throw new Error(scenario.message);
        return scenario.point === 'press' ? [] : [button];
      },
    };
    const result = await discoverPortal(page, { portal: 'booking', loginOnly: true,
      browserProfile: 'fresh_login', credentials: { identifier: secret, password: 'SECRET_PASSWORD' },
      authMethod: 'password' });
    assert.equal(result.failure_code, 'browser_unavailable');
    assert.equal(result.failure_stage, 'identifier');
    assert.equal(result.identifier_phase, scenario.phase);
    assert.equal(result.browser_error_kind, scenario.kind);
    assert.equal(result.identifier_submit, null);
    assert.equal(typed, scenario.typed);
    assert.equal(submitted, scenario.submitted);
    assert.ok(!JSON.stringify(result).includes(secret));
    assert.ok(!JSON.stringify(result).includes('SECRET_PASSWORD'));
  });
});

test('expected Booking form validation failure has no browser error category', async () => {
  const page = fakePage(null);
  const result = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    credentials: { identifier: 'private@example.com', password: 'SECRET_PASSWORD' },
    authMethod: 'password' });
  assert.equal(result.failure_code, 'auth_unconfigured');
  assert.equal(result.identifier_phase, 'field_check');
  assert.equal(result.browser_error_kind, null);
  assert.deepEqual(page.typed, []);
});

test('existing Booking extranet session is inspected without credential submission', async () => {
  let reloads = 0; let navigations = 0;
  const page = {
    url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/',
    reload: async () => { reloads++; }, goto: async () => { navigations++; },
    evaluate: async () => [],
  };
  const diagnostic = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' },
    authMethod: 'sms' });
  assert.equal(reloads, 1);
  assert.equal(navigations, 0);
  assert.equal(diagnostic.authenticated_session, true);
  assert.equal(diagnostic.validated, false);
  assert.equal(diagnostic.login_attempted, false);
  assert.equal(diagnostic.sms_prompted, false);
  assert.equal(diagnostic.sms_submitted, false);
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

test('fresh Booking login resumes solved challenge sign-in without navigating away or exposing token', async () => {
  let url = 'https://account.booking.com/sign-in?op_token=secret-do-not-log';
  let step = 0;
  let navigations = 0;
  const typed = [];
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1,
      action: 'https://account.booking.com/sign-in' }),
    type: async value => { typed.push(value); },
    press: async () => { if (++step === 2) url = 'https://admin.booking.com/hotel/hoteladmin/'; },
  });
  const page = {
    url: () => url,
    goto: async () => { navigations++; url = 'https://account.booking.com/sign-in'; },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' ? [] : step === 0
      ? [field('loginname', 'text')] : step === 1 ? [field('password', 'password')] : [],
  };
  const diagnostic = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    browserProfile: 'fresh_login', credentials: { identifier: 'private@example.com', password: 'private-password' },
    authMethod: 'password' });
  assert.equal(navigations, 0);
  assert.deepEqual(typed, ['private@example.com', 'private-password']);
  assert.equal(bookingLoginCode(diagnostic), 'ok');
  assert.ok(!JSON.stringify(diagnostic).includes('secret-do-not-log'));
  assert.ok(!JSON.stringify(diagnostic).includes('private@example.com'));
});

test('fresh Booking login resumes an existing password tab without returning to the username tab', async () => {
  let url = 'https://auth.booking.com/u/login/password?state=PRIVATE_BROWSER_TOKEN';
  let enteredPassword = false;
  let submissions = 0;
  let passwordValue = 'stale-password'; let focused = false; let selected = false;
  const page = {
    url: () => url,
    goto: async () => { throw new Error('must not navigate away from the password tab'); },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' || enteredPassword ? [] : [{
      evaluate: async fn => fn.toString().includes('document.activeElement')
        ? focused && passwordValue.length === 0
        : { visible: true, id: 'password', type: 'password', autocomplete: 'current-password',
          action: 'https://auth.booking.com/u/login/password?state=PRIVATE_FORM_TOKEN' },
      type: async value => {
        assert.equal(passwordValue.length, 0);
        assert.equal(value, 'PRIVATE_PASSWORD');
        passwordValue = value; enteredPassword = true;
      },
      press: async key => {
        if (key === 'Control+A') { focused = true; selected = true; }
        else if (key === 'Backspace') { if (selected) passwordValue = ''; selected = false; }
        else if (key === 'Enter') { submissions++; url = 'https://admin.booking.com/hotel/hoteladmin/'; }
        else throw new Error('unexpected keyboard action');
      },
    }],
  };
  const result = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    browserProfile: 'fresh_login', credentials: { identifier: 'PRIVATE_IDENTIFIER', password: 'PRIVATE_PASSWORD' },
    authMethod: 'password' });
  assert.equal(submissions, 1);
  assert.equal(passwordValue, 'PRIVATE_PASSWORD');
  assert.equal(result.authenticated_session, true);
  assert.equal(result.login_attempted, true, 'the verified password step completes the resumed login attempt');
  assert.equal(bookingLoginCode(result), 'ok');
  for (const secret of ['PRIVATE_BROWSER_TOKEN', 'PRIVATE_FORM_TOKEN', 'PRIVATE_IDENTIFIER', 'PRIVATE_PASSWORD'])
    assert.ok(!JSON.stringify(result).includes(secret));
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

async function loginWithSmsBridge(message, showOtp = true) {
  const exchangeDir = await fs.mkdtemp(path.join(os.tmpdir(), 'booking-sms-test-'));
  let url = 'about:blank'; let stage = 0;
  const typed = [];
  const blockedValues = [];
  const action = 'https://account.booking.com/sign-in';
  const field = (id, type, autocomplete, maxLength = -1) => ({
    evaluate: async () => ({ visible: true, id, type, name: id, autocomplete, maxLength, action }),
    type: async value => {
      if (id === 'sms-code') assert.ok(blockedValues.includes(value), 'OTP is blocked in query before typing');
      typed.push([id, value]);
    },
    press: async () => {
      stage++;
      if (stage === 3 || (!showOtp && stage === 2))
        url = 'https://admin.booking.com/hotel/hoteladmin/groups/home/';
    },
  });
  const page = {
    url: () => url,
    goto: async () => { url = action; },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' ? [] : stage === 0
      ? [field('loginname', 'text', '')] : stage === 1
        ? [field('password', 'password', '')] : stage === 2 && showOtp
          ? [field('sms-code', 'tel', 'one-time-code', 6)] : [],
  };
  const bridge = (async () => {
    let challenge;
    for (let i = 0; i < 200 && !challenge; i++) {
      challenge = await fs.readFile(path.join(exchangeDir, 'challenge.json'), 'utf8').catch(() => null);
      if (!challenge) await delay(10);
    }
    if (!challenge) throw new Error('SMS bridge was not prepared');
    const { id } = JSON.parse(challenge);
    await fs.writeFile(path.join(exchangeDir, `ready-${id}`), '');
    if (showOtp) await fs.writeFile(path.join(exchangeDir, `response-${id}.json`), JSON.stringify({ value: message }));
  })();
  try {
    const diagnostic = await discoverPortal(page, { portal: 'booking', loginOnly: true,
      credentials: { identifier: 'private@example.com', password: 'sensitive-password',
        sms_sender: 'Booking', sms_keyword: 'code' },
      authMethod: 'sms', exchangeDir }, { registerBlockedValue: value => blockedValues.push(value) });
    await bridge;
    return { diagnostic, typed };
  } finally { await fs.rm(exchangeDir, { recursive: true, force: true }); }
}

test('Booking login records only booleans when SMS is requested and submitted', async () => {
  const { diagnostic, typed } = await loginWithSmsBridge('Your code is 742619');
  assert.equal(bookingLoginCode(diagnostic), 'ok');
  assert.equal(diagnostic.login_attempted, true);
  assert.equal(diagnostic.sms_prompted, true);
  assert.equal(diagnostic.sms_submitted, true);
  assert.equal(typed[2][1], '742619');
  assert.equal(JSON.stringify(diagnostic).includes('742619'), false);
});

test('SMS bridge readiness alone does not imply a Booking SMS prompt', async () => {
  const { diagnostic, typed } = await loginWithSmsBridge('', false);
  assert.equal(bookingLoginCode(diagnostic), 'ok');
  assert.equal(diagnostic.sms_prompted, false);
  assert.equal(diagnostic.sms_submitted, false);
  assert.equal(typed.length, 2);
});

test('Booking login distinguishes an SMS prompt from a failed code retrieval', async () => {
  const { diagnostic } = await loginWithSmsBridge('No usable code');
  assert.equal(diagnostic.failure_code, 'auth_invalid');
  assert.equal(diagnostic.failure_stage, 'second_factor');
  assert.equal(diagnostic.login_attempted, true);
  assert.equal(diagnostic.sms_prompted, true);
  assert.equal(diagnostic.sms_submitted, false);
});

test('human verification stops login before password and reports a distinct diagnostic', async () => {
  let url = 'about:blank'; const typed = [];
  const page = {
    url: () => url,
    goto: async () => { url = 'https://account.booking.com/sign-in?op_token=secret'; },
    waitForFunction: async () => {},
    evaluate: async fn => fn.toString().includes('visibleChallenge') ? true : [],
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

test('Booking sign-in with an op_token and no visible challenge still attempts login', async () => {
  let url = 'about:blank'; let step = 0;
  const typed = [];
  const action = 'https://account.booking.com/sign-in';
  const field = (id, type) => ({
    evaluate: async () => ({ visible: true, id, type, autocomplete: '', maxLength: -1, action }),
    type: async value => { typed.push([id, value]); },
    press: async () => { if (++step === 2) url = 'https://admin.booking.com/hotel/hoteladmin/'; },
  });
  const page = {
    url: () => url,
    goto: async () => { url = 'https://account.booking.com/sign-in?op_token=secret'; },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    $$: async selector => selector !== 'input' ? [] : step === 0
      ? [field('loginname', 'text')] : step === 1 ? [field('password', 'password')] : [],
  };
  const result = await discoverPortal(page, { portal: 'booking', loginOnly: true,
    credentials: { identifier: 'private@example.com', password: 'sensitive-password' }, authMethod: 'password' });
  assert.deepEqual(typed, [['loginname', 'private@example.com'], ['password', 'sensitive-password']]);
  assert.equal(bookingLoginCode(result), 'ok');
  assert.equal(result.login_attempted, true);
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

test('Booking login records only safe login_name metadata and challenge visibility, then strips it for Hub', async () => {
  let url = 'https://account.booking.com/sign-in?op_token=URL_SECRET';
  let stage = 0;
  const events = new EventEmitter();
  const page = {
    url: () => url,
    goto: async () => { throw new Error('the existing sign-in tab must be reused'); },
    waitForNavigation: async () => {}, waitForFunction: async () => {}, evaluate: async () => [],
    on: events.on.bind(events), off: events.off.bind(events),
    $$: async selector => {
      if (selector !== 'input') return [];
      if (stage > 1) return [];
      return [{ evaluate: async () => ({ visible: true, id: stage ? 'password' : 'loginname',
        type: stage ? 'password' : 'text', autocomplete: '', maxLength: -1,
        action: 'https://account.booking.com/sign-in' }),
      type: async () => {}, press: async () => {
        if (stage === 0) {
          const request = {
            method: () => 'POST', resourceType: () => 'fetch',
            headers: () => ({ 'content-type': 'application/json',
              'referer': 'https://account.booking.com/sign-in?token=REFERER_SECRET',
              'cookie': 'COOKIE_SECRET', 'sec-fetch-mode': 'cors' }),
            postData: () => JSON.stringify({ login_name: 'USERNAME_SECRET', op_token: 'OP_SECRET' }),
          };
          events.emit('response', { url: () => 'https://account.booking.com/account/sign-in/login_name?query=QUERY_SECRET',
            status: () => 405, request: () => request, headers: () => ({ 'content-type': 'text/html' }) });
        } else url = 'https://admin.booking.com/hotel/hoteladmin/';
        stage++;
      } }];
    },
  };
  const result = await discoverPortal(page, { portal: 'booking', loginOnly: true, browserProfile: 'fresh_login',
    credentials: { identifier: 'USERNAME_SECRET', password: 'PASSWORD_SECRET' }, authMethod: 'password' });
  assert.equal(result.private_login_metadata.challenge_visible_before, false);
  assert.equal(result.private_login_metadata.challenge_visible_after, false);
  assert.equal(result.private_login_metadata.requests.length, 1);
  assert.equal(result.private_login_metadata.requests[0].status, 405);
  assert.equal(result.private_login_metadata.requests[0].body_keys.login_name, 'string');
  assert.equal(events.listenerCount('response'), 0);
  for (const secret of ['URL_SECRET', 'REFERER_SECRET', 'COOKIE_SECRET', 'USERNAME_SECRET',
    'OP_SECRET', 'QUERY_SECRET', 'PASSWORD_SECRET']) assert.ok(!JSON.stringify(result).includes(secret));
  const { removePrivateBookingLoginMetadata } = await import('../booking-login-report.mjs');
  removePrivateBookingLoginMetadata({ diagnostic: result });
  assert.equal(result.private_login_metadata, undefined);
});
