import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';
import { sanitizeBookingLoginNameEvidence } from '../booking-login-metadata.mjs';
import { removePrivateBookingLoginMetadata, validatedBookingLoginMetadata,
  writeBookingLoginReport } from '../booking-login-report.mjs';

function unsafeEvidence() {
  return { url: 'https://account.booking.com/account/sign-in/login_name?op_token=URL_SECRET',
    method: 'POST', status: 405, resourceType: 'fetch',
    requestHeaders: {
      'Content-Type': 'application/json; boundary=HEADER_SECRET',
      'Origin': 'https://account.booking.com/secret/ORIGIN_SECRET?token=QUERY_SECRET',
      'Referer': 'https://account.booking.com/sign-in?op_token=REFERER_SECRET',
      'Cookie': 'COOKIE_SECRET', 'Authorization': 'Bearer AUTH_SECRET',
      'Sec-Fetch-Site': 'same-origin', 'Sec-Fetch-Mode': 'cors',
      'Sec-Fetch-Dest': 'empty', 'Sec-Fetch-User': '?1',
      'X-Requested-With': 'VALUE_SECRET',
    }, responseHeaders: { 'Content-Type': 'text/html; note=RESPONSE_SECRET', 'Set-Cookie': 'SETCOOKIE_SECRET' },
    postData: JSON.stringify({ as_token: 'AS_SECRET', client_id: 'CLIENT_SECRET',
      login_name: 'USERNAME_SECRET', op_token: 'OP_SECRET', scope: ['SCOPE_SECRET'],
      state: { secret: 'STATE_SECRET' }, password: 'PASSWORD_SECRET', cookie: 'BODYCOOKIE_SECRET' }) };
}

test('identifier metadata keeps only known field types, categorical headers and permitted hostname', () => {
  const safe = sanitizeBookingLoginNameEvidence(unsafeEvidence());
  assert.deepEqual(safe.body_keys, { as_token: 'string', client_id: 'string',
    login_name: 'string', op_token: 'string', scope: 'array', state: 'object' });
  assert.equal(safe.request_content_type, 'application/json');
  assert.equal(safe.response_content_type, 'text/html');
  assert.equal(safe.origin_host, 'account.booking.com');
  assert.equal(safe.referer_host, 'account.booking.com');
  assert.deepEqual(safe.sec_fetch, { site: 'same-origin', mode: 'cors', dest: 'empty', user: '?1' });
  assert.equal(safe.x_requested_with_present, true);
  for (const secret of ['URL_SECRET', 'HEADER_SECRET', 'QUERY_SECRET', 'REFERER_SECRET', 'COOKIE_SECRET',
    'AUTH_SECRET', 'VALUE_SECRET', 'RESPONSE_SECRET', 'SETCOOKIE_SECRET', 'AS_SECRET',
    'CLIENT_SECRET', 'USERNAME_SECRET', 'OP_SECRET', 'SCOPE_SECRET', 'STATE_SECRET',
    'PASSWORD_SECRET', 'BODYCOOKIE_SECRET']) assert.ok(!JSON.stringify(safe).includes(secret), secret);
});

test('other endpoints never produce metadata; unknown header values are categorized', () => {
  const input = unsafeEvidence();
  assert.equal(sanitizeBookingLoginNameEvidence({ ...input, url: 'https://evil.example/account/sign-in/login_name' }), null);
  assert.equal(sanitizeBookingLoginNameEvidence({ ...input, url: 'https://account.booking.com/account/sign-in/login_name_extra' }), null);
  assert.equal(sanitizeBookingLoginNameEvidence({ ...input, url: 'http://account.booking.com/account/sign-in/login_name' }), null);
  input.requestHeaders.Origin = 'https://thirdparty.example/?value=SECRET';
  input.requestHeaders.Referer = 'https://thirdparty.example/?value=SECRET';
  input.requestHeaders['Sec-Fetch-Mode'] = 'SECRET';
  input.requestHeaders['Content-Type'] = 'application/SECRET';
  const safe = sanitizeBookingLoginNameEvidence(input);
  assert.equal(safe.origin_host, null);
  assert.equal(safe.referer_host, null);
  assert.equal(safe.sec_fetch.mode, 'other');
  assert.equal(safe.request_content_type, 'other');
  assert.equal(safe.body_keys, 'unavailable');
  assert.ok(!JSON.stringify(safe).includes('SECRET'));
});

test('form body and HAR param fallback reveal only presence/type', () => {
  const input = unsafeEvidence();
  input.requestHeaders['Content-Type'] = 'application/x-www-form-urlencoded';
  input.postData = 'login_name=PRIVATE&as_token=PRIVATE&unexpected=PRIVATE';
  const safe = sanitizeBookingLoginNameEvidence(input);
  assert.equal(safe.body_keys.login_name, 'string');
  assert.equal(safe.body_keys.as_token, 'string');
  assert.equal(safe.body_keys.state, 'absent');
  assert.ok(!JSON.stringify(safe).includes('PRIVATE'));
  input.postData = { params: [{ name: 'state', value: 'PRIVATE' }] };
  assert.equal(sanitizeBookingLoginNameEvidence(input).body_keys.state, 'string');
});

test('Windows report rejects unknown fields and strips local-only metadata before Hub transport', async () => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'booking-login-metadata-'));
  try {
    const report = { version: 1, requests: [sanitizeBookingLoginNameEvidence(unsafeEvidence())],
      challenge_visible_before: false, challenge_visible_after: true };
    const result = { code: 'portal_changed', diagnostic: { failure_stage: 'login_incomplete', private_login_metadata: report } };
    const local = removePrivateBookingLoginMetadata(result);
    assert.equal(result.diagnostic.private_login_metadata, undefined);
    assert.ok(!JSON.stringify(result).includes('body_keys'));
    const filename = await writeBookingLoginReport(directory, 42, local);
    const contents = await fs.readFile(filename, 'utf8');
    assert.deepEqual(JSON.parse(contents), validatedBookingLoginMetadata(report));
    if (process.platform !== 'win32') assert.equal((await fs.stat(filename)).mode & 0o777, 0o600);
    assert.ok(!contents.includes('PRIVATE'));
    await assert.rejects(writeBookingLoginReport(directory, 43, { ...report, url: 'SECRET' }), /invalid_login_metadata/);
    assert.equal(await fs.stat(path.join(directory, 'task-43-booking-login-metadata.json')).catch(() => null), null);
    await assert.rejects(writeBookingLoginReport(directory, 44,
      { ...report, requests: [{ ...report.requests[0], cookie: 'SECRET' }] }), /invalid_login_metadata/);
  } finally { await fs.rm(directory, { recursive: true, force: true }); }
});

test('offline HAR comparison reports only sanitized Booking login_name entries', async () => {
  const directory = await fs.mkdtemp(path.join(os.tmpdir(), 'booking-har-sanitized-'));
  try {
    const filename = path.join(directory, 'manual.har');
    const unsafe = unsafeEvidence();
    const har = { log: { entries: [{ request: { url: unsafe.url, method: unsafe.method,
      headers: Object.entries(unsafe.requestHeaders).map(([name, value]) => ({ name, value })),
      postData: { mimeType: 'application/json', text: unsafe.postData } },
    response: { status: 200, headers: Object.entries(unsafe.responseHeaders)
      .map(([name, value]) => ({ name, value })) }, _resourceType: 'fetch' },
    { request: { url: 'https://account.booking.com/other?secret=EXTRA_SECRET', method: 'POST' },
      response: { status: 200 } }] } };
    await fs.writeFile(filename, JSON.stringify(har), { mode: 0o600 });
    const script = fileURLToPath(new URL('../booking-login-har-metadata.mjs', import.meta.url));
    const child = spawnSync(process.execPath, [script, filename], { encoding: 'utf8' });
    assert.equal(child.status, 0);
    assert.equal(child.stderr, '');
    assert.equal(JSON.parse(child.stdout).requests.length, 1);
    assert.equal(JSON.parse(child.stdout).requests[0].status, 200);
    for (const secret of ['URL_SECRET', 'COOKIE_SECRET', 'PASSWORD_SECRET', 'EXTRA_SECRET'])
      assert.ok(!child.stdout.includes(secret));
  } finally { await fs.rm(directory, { recursive: true, force: true }); }
});
