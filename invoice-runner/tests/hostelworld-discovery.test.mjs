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

const { hostelworldBinding, discoverHostelworld } = await import('../hostelworld-discovery.mjs');
const configured = () => ({ action: 'discover', portal: 'hostelworld', accountId: 2, property: '305209',
  authMethod: 'email', credentials: { hostel_number: '305209', identifier: 'fixture-user', password: 'fixture-password' },
  hostelworldAuthTargets: [{ host: 'inbox.hostelworld.com', path: '/login/' }] });
test('bootstrap requires the exact account, property and server-approved email target', () => {
  assert.equal(hostelworldBinding(configured()), true);
  for (const change of [{ accountId: 3 }, { property: '77759' }, { authMethod: 'sms' },
    { credentials: { hostel_number: '77759' } }, { hostelworldAuthTargets: [] },
    { hostelworldAuthTargets: [{ host: 'other.hostelworld.com', path: '/login/' }] }])
    assert.equal(hostelworldBinding({ ...configured(), ...change }), false);
});
function flow(identity = true) {
  let url = 'about:blank', authUrl = 'about:blank'; const events = [];
  const listeners = new Map();
  const page = {
    goto: async value => { url = value; events.push(value.includes('/login/') ? 'consume' : 'navigate'); },
    url: () => url,
    evaluate: async fn => {
      const body = String(fn);
      if (body.includes("const eligible")) return [];
      if (body.includes('ready_state')) return { ready_state: 'complete' };
      if (body.includes('formReady')) throw new Error('unused');
      if (body.includes('form.method')) return true;
      if (body.includes('fields_match')) return { fields_match: true, form_valid: true };
      if (body.includes('sent_notice')) return { sent_notice: true, hostel_matches: true };
      if (body.includes('property_marker')) return { login_form: false, property_marker: true,
        invoice_link: true, secure_link_notice: false };
      if (body.includes('const ids')) return identity;
      if (body.includes('pdf_links')) return { pdf_links: 2, pagination: true, issue_date_column: false };
      throw new Error('unexpected evaluation');
    },
    $: async () => null,
    $eval: async selector => selector === '.login-email' ? true : undefined,
    type: async () => events.push('type'),
    click: async () => { events.push('submit'); url = 'https://inbox.hostelworld.com/trylogin.php'; },
    waitForNavigation: async () => {},
    on: () => {}, off: () => {},
  };
  const authPage = {
    evaluate: fn => page.evaluate(fn),
    goto: async value => {
      assert.ok(events.includes('guard_on'));
      assert.equal(url, 'https://inbox.hostelworld.com/trylogin.php', 'the waiting tab is preserved');
      events.push(value.includes('/login/') ? 'consume' : 'invoice_navigation');
      authUrl = value.includes('/login/') ? 'https://inbox.hostelworld.com/loggedin.php' : value;
      const initial = { url: () => value };
      return { status: () => 200, request: () => ({ url: () => authUrl, redirectChain: () => [initial] }) };
    },
    url: () => authUrl,
    browserContext: () => context,
    setDefaultNavigationTimeout: () => {}, setDefaultTimeout: () => {},
    setRequestInterception: async enabled => events.push(enabled ? 'guard_on' : 'guard_off'),
    on: (name, handler) => listeners.set(name, handler),
    off: (name, handler) => { assert.equal(listeners.get(name), handler); listeners.delete(name); },
    close: async () => events.push('auth_tab_closed'),
  };
  const context = { newPage: async () => { events.push('new_tab'); return authPage; } };
  page.browserContext = () => context;
  const broker = { prepare: async () => events.push('prepare'), value: async () => {
    events.push('receive'); return 'https://inbox.hostelworld.com/login/' + 'a'.repeat(32);
  }, close: async () => events.push('close') };
  return { page, authPage, context, broker, events, listeners };
}
test('prepared email challenge precedes submission and discovery never validates collection', async () => {
  const f = flow(); const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.authenticated_session, true);
  assert.equal(result.validated, false);
  assert.equal(result.navigation_stage, 'invoices_visible');
  assert.equal(result.invoice_structure.issue_date_column, false);
  assert.ok(f.events.indexOf('prepare') < f.events.indexOf('submit'));
  assert.ok(f.events.indexOf('receive') < f.events.indexOf('consume'));
  assert.ok(f.events.indexOf('new_tab') < f.events.indexOf('guard_on'));
  assert.ok(f.events.indexOf('guard_on') < f.events.indexOf('consume'));
  assert.equal(result.secure_link_new_tab, true);
  assert.equal(result.post_link_login_attempted, false);
  assert.deepEqual(result.link_navigation, { request_matches: true, response_status: 200, redirect_count: 1 });
  assert.equal(f.events.filter(x => x === 'submit').length, 1);
  assert.equal(f.page.url(), 'https://inbox.hostelworld.com/trylogin.php');
  assert.equal(f.listeners.size, 0);
  assert.ok(f.events.includes('auth_tab_closed'));
  assert.equal(f.events.at(-1), 'close');
  assert.doesNotMatch(JSON.stringify(result), /fixture-|a{32}|cookies/);
});

test('truncated or invalid fields stop before submission without exposing credentials', async () => {
  for (const fields of [{ fields_match: false, form_valid: true,
    mismatched_fields: ['Username', 'PRIVATE_SECRET'], oversized_fields: ['Username', 'PRIVATE_SECRET'] },
  { fields_match: true, form_valid: false }]) {
    const f = flow(); const evaluate = f.page.evaluate;
    f.page.evaluate = async fn => String(fn).includes('fields_match') ? fields : evaluate(fn);
    const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
    assert.equal(result.failure_code, 'auth_unconfigured');
    assert.equal(result.failure_stage, 'verify_filled_form');
    assert.equal(result.login_attempted, false);
    assert.equal(f.events.includes('submit'), false);
    assert.equal(f.events.includes('receive'), false);
    assert.deepEqual(result.submission.mismatched_fields, fields.mismatched_fields ? ['Username'] : []);
    assert.deepEqual(result.submission.oversized_fields, fields.oversized_fields ? ['Username'] : []);
    assert.doesNotMatch(JSON.stringify(result), /fixture-user|fixture-password|PRIVATE_SECRET/);
  }
});

test('failed sign-in records only bounded POST evidence and detaches its observer', async () => {
  const f = flow(); const evaluate = f.page.evaluate;
  f.page.evaluate = async fn => String(fn).includes('sent_notice') ? { sent_notice: false } : evaluate(fn);
  let listener, detached = false;
  f.page.on = (event, fn) => { assert.equal(event, 'response'); listener = fn; };
  f.page.off = (event, fn) => { detached = event === 'response' && fn === listener; };
  f.page.click = async () => {
    listener({ url: () => 'https://inbox.hostelworld.com/trylogin.php?private=FIXTURE_SECRET',
      request: () => ({ method: () => 'POST' }), status: () => 302 });
    listener({ url: () => 'https://other.example/trylogin.php',
      request: () => ({ method: () => 'POST' }), status: () => 500 });
  };
  const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.deepEqual(result.submission, { fields_match: true, form_valid: true, navigation_observed: true,
    post_observed: true, response_status: 302, mismatched_fields: [], oversized_fields: [] });
  assert.equal(detached, true);
  assert.doesNotMatch(JSON.stringify(result), /FIXTURE_SECRET|fixture-password|other.example/);
});
test('wrong property stops before invoice navigation', async () => {
  const f = flow(false);
  const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.failure_code, 'account_mismatch');
  assert.equal(result.authenticated_session, false);
  assert.equal(result.invoice_structure, undefined);
  assert.equal(f.events.includes('invoice_navigation'), false);
  assert.ok(f.events.includes('auth_tab_closed'));
  assert.equal(f.events.at(-1), 'close');
});

test('a new tab returning login or an email notice stops without submitting credentials again', async () => {
  for (const repeated of [{ login_form: true }, { secure_link_notice: true }]) {
    const f = flow(false); const evaluate = f.authPage.evaluate;
    f.authPage.evaluate = async fn => String(fn).includes('property_marker') ? repeated : evaluate(fn);
    const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
    assert.equal(result.failure_code, 'needs_auth');
    assert.equal(result.secure_link_new_tab, true);
    assert.equal(result.post_link_login_attempted, false);
    assert.equal(f.events.filter(x => x === 'submit').length, 1);
    assert.equal(f.events.filter(x => x === 'receive').length, 1);
    assert.ok(f.events.includes('auth_tab_closed'));
    assert.doesNotMatch(JSON.stringify(result), /fixture-password|fixture-user|a{32}/);
  }
});

test('link errors and human checks prevent post-link credential submission', async () => {
  for (const [flag, code] of [['human_challenge','human_verification'], ['invalid_link','auth_invalid'], ['error_notice','needs_auth']]) {
    const f = flow(false); const evaluate = f.authPage.evaluate;
    f.authPage.evaluate = async fn => String(fn).includes('property_marker')
      ? { login_form: true, [flag]: true } : evaluate(fn);
    const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
    assert.equal(result.failure_code, code);
    assert.equal(result.post_link_login_attempted, false);
    assert.equal(f.events.filter(x => x === 'submit').length, 1);
  }
});

test('a tab in a different context is rejected before the link is opened', async () => {
  const f = flow(); f.authPage.browserContext = () => ({});
  const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.failure_code, 'browser_unavailable');
  assert.equal(result.failure_stage, 'open_auth_tab');
  assert.equal(result.secure_link_new_tab, false);
  assert.equal(f.events.includes('consume'), false);
  assert.ok(f.events.includes('auth_tab_closed'));
});

test('the new tab keeps destination and secret guards before consuming the link', async () => {
  const f = flow(); const navigate = f.authPage.goto; const actions = [];
  f.authPage.goto = async value => {
    const listener = f.listeners.get('request');
    for (const [url, method] of [['https://foreign.example/login', 'GET'],
      ['https://inbox.hostelworld.com/?password=fixture-password', 'GET'],
      ['https://foreign.example/submit', 'POST']]) {
      listener({ url: () => url, method: () => method, isNavigationRequest: () => true,
        isInterceptResolutionHandled: () => false,
        abort: async () => actions.push('abort'), continue: async () => actions.push('continue') });
    }
    return navigate(value);
  };
  const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.authenticated_session, true);
  assert.deepEqual(actions, Array(6).fill('abort'));
  assert.equal(f.listeners.size, 0);
});

test('navigation failure closes the extra tab and never emits the link or error message', async () => {
  const f = flow(); f.authPage.goto = async () => { throw new Error('PRIVATE_LINK_VALUE'); };
  const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.failure_code, 'browser_unavailable');
  assert.equal(result.failure_stage, 'consume_link');
  assert.ok(f.events.includes('guard_off'));
  assert.ok(f.events.includes('auth_tab_closed'));
  assert.equal(f.events.at(-1), 'close');
  assert.doesNotMatch(JSON.stringify(result), /PRIVATE_LINK_VALUE|fixture-password|a{32}/);
});

test('unknown login result is not mislabeled as invalid credentials or retried blindly', async () => {
  const f = flow(); const evaluate = f.page.evaluate;
  f.page.evaluate = async fn => String(fn).includes('sent_notice')
    ? { sent_notice: false, hostel_matches: false, credential_error: false, human_challenge: false }
    : evaluate(fn);
  const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.failure_code, 'portal_changed');
  assert.equal(result.failure_stage, 'secure_link_requested');
  assert.equal(result.auth_signals.sent_notice, false);
  assert.equal(f.events.includes('receive'), false);
});
