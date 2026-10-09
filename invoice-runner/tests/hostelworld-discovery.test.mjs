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
  let url = 'about:blank'; const events = []; let inspect = 0;
  const page = {
    goto: async value => { url = value; events.push(value.includes('/login/') ? 'consume' : 'navigate'); },
    url: () => url,
    evaluate: async fn => {
      const body = String(fn);
      if (body.includes("const eligible")) return [];
      if (body.includes('ready_state')) return { ready_state: 'complete' };
      if (body.includes('formReady')) throw new Error('unused');
      if (body.includes('form.method')) return true;
      if (body.includes('sent_notice')) return { sent_notice: true, hostel_matches: true };
      if (body.includes('const ids')) { inspect++; return identity; }
      if (body.includes('pdf_links')) return { pdf_links: 2, pagination: true, issue_date_column: false };
      throw new Error('unexpected evaluation');
    },
    $: async () => null,
    $eval: async selector => selector === '.login-email' ? true : undefined,
    type: async () => events.push('type'),
    click: async () => { events.push('submit'); url = 'https://inbox.hostelworld.com/trylogin.php'; },
    waitForNavigation: async () => {},
  };
  const broker = { prepare: async () => events.push('prepare'), value: async () => {
    events.push('receive'); return 'https://inbox.hostelworld.com/login/' + 'a'.repeat(32);
  }, close: async () => events.push('close') };
  return { page, broker, events };
}
test('prepared email challenge precedes submission and discovery never validates collection', async () => {
  const f = flow(); const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.authenticated_session, true);
  assert.equal(result.validated, false);
  assert.equal(result.navigation_stage, 'invoices_visible');
  assert.equal(result.invoice_structure.issue_date_column, false);
  assert.ok(f.events.indexOf('prepare') < f.events.indexOf('submit'));
  assert.ok(f.events.indexOf('receive') < f.events.indexOf('consume'));
  assert.equal(f.events.at(-1), 'close');
  assert.doesNotMatch(JSON.stringify(result), /fixture-|a{32}|cookies/);
});
test('wrong property stops before invoice navigation', async () => {
  const f = flow(false); const result = await discoverHostelworld(f.page, configured(), { broker: f.broker });
  assert.equal(result.failure_code, 'account_mismatch');
  assert.equal(result.authenticated_session, false);
  assert.equal(result.invoice_structure, undefined);
  assert.equal(f.events.at(-1), 'close');
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
