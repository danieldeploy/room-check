import test from 'node:test';
import assert from 'node:assert/strict';
import { solveAmazonCaptcha, amazonTask } from '../captcha-provider.mjs';
import { createBookingCaptchaTest } from '../booking-captcha.mjs';
import { blockBookingLoopback, BOOKING_AUTH_ORIGINS } from '../booking-permissions.mjs';

const key = 'a'.repeat(32);
const challenge = { websiteURL: 'https://account.booking.com/', websiteKey: 'fixture-key',
  iv: 'fixture-iv', context: 'fixture-context' };
const input = { action: 'login', portal: 'booking', automation: {
  captcha_mode: 'test', captcha_provider: 'anti-captcha', captcha_api_key: key } };
const response = value => new Response(JSON.stringify(value));

test('provider polls one paid task, strips extra fields and keeps key in HTTPS POST body', async () => {
  const requests = [];
  const replies = [{ errorId: 0, taskId: 7 }, { errorId: 0, status: 'processing' },
    { errorId: 0, status: 'ready', solution: { token: 'fixture-solution', userAgent: 'not-used' } }];
  const token = await solveAmazonCaptcha({ ...challenge, password: 'do-not-forward' }, key, {
    sleep: async () => {}, fetchImpl: async (url, options) => {
      requests.push({ url, options }); return response(replies.shift());
    },
  });
  assert.equal(token, 'fixture-solution');
  assert.equal(requests.filter(r => r.url.endsWith('/createTask')).length, 1);
  assert.equal(requests.length, 3);
  for (const { url, options } of requests) {
    assert.equal(new URL(url).origin, 'https://api.anti-captcha.com');
    assert.equal(options.redirect, 'error');
    assert.equal(options.method, 'POST');
    assert.equal(url.includes(key), false);
    assert.equal(options.body.includes('do-not-forward'), false);
  }
});

test('invalid inputs never create paid tasks or forward private URLs', async () => {
  let calls = 0;
  const deps = { fetchImpl: async () => { calls++; throw new Error('unexpected'); } };
  for (const patch of [{ websiteURL: 'https://account.booking.com/?op_token=private' },
    { websiteURL: 'https://name:secret@account.booking.com/' }, { websiteURL: 'http://account.booking.com/' },
    { captchaScript: 'https://evil.test/captcha.js' }, { captchaScript: 'https://evilcaptcha.awswaf.com/captcha.js' },
    { iv: '' }, { context: 'x'.repeat(20000) }]) {
    await assert.rejects(solveAmazonCaptcha({ ...challenge, ...patch }, key, deps), /unsupported/);
  }
  await assert.rejects(solveAmazonCaptcha(challenge, '', deps), /unconfigured/);
  assert.equal(calls, 0);
});

test('provider errors are sanitized; uncertain creation never retried; polling is bounded', async () => {
  for (const body of [{ errorId: 1, errorDescription: key }, { errorId: 0, taskId: 'invalid' }]) {
    let calls = 0;
    await assert.rejects(solveAmazonCaptcha(challenge, key, { fetchImpl: async () => {
      calls++; return response(body);
    } }), error => error.message === 'provider_error');
    assert.equal(calls, 1);
  }
  let count = 0, clock = 0;
  await assert.rejects(solveAmazonCaptcha(challenge, key, {
    now: () => clock, sleep: async ms => { clock += ms; },
    fetchImpl: async () => response(++count === 1 ? { errorId: 0, taskId: 3 } : { errorId: 0, status: 'processing' }),
  }), /timeout/);
  assert.ok(count <= 24);
  await assert.rejects(solveAmazonCaptcha(challenge, key, { fetchImpl: async () => {
    throw new Error('private network details ' + key);
  } }), error => error.message === 'provider_error');
  await assert.rejects(solveAmazonCaptcha(challenge, key, { fetchImpl: async () => new Response('x'.repeat(65537)) }), /provider_error/);
});

test('invalid solution is never accepted', async () => {
  for (const token of ['', 'contains;cookie', 'contains\nheader', null]) {
    let created = false;
    await assert.rejects(solveAmazonCaptcha(challenge, key, { sleep: async () => {}, fetchImpl: async () => {
      if (!created) { created = true; return response({ errorId: 0, taskId: 2 }); }
      return response({ errorId: 0, status: 'ready', solution: { token } });
    } }), /provider_error/);
  }
});

function pageFixture() {
  let url = 'https://account.booking.com/sign-in?op_token=fixture-private';
  const cookies = [];
  return { cookies, url: () => url, browserContext: () => ({ setCookie: async c => cookies.push(c) }),
    goto: async value => { url = value; }, waitForFunction: async () => {} };
}
test('test mode never runs on collections; missing and unsupported configuration make no API calls', async () => {
  let count = 0;
  const deps = { solve: async () => { count++; }, read: async () => null };
  for (const action of ['collect', 'verify', 'discover', 'preflight']) {
    const controller = createBookingCaptchaTest({ ...input, action }, deps);
    assert.equal(await controller.attempt(pageFixture(), async () => true), false);
    assert.equal(controller.status(), 'disabled');
  }
  const unsupported = createBookingCaptchaTest(input, deps);
  await unsupported.attempt(pageFixture(), async () => true);
  assert.equal(unsupported.status(), 'unsupported');
  assert.equal(count, 0);
});

test('one attempt per job and success requires challenge disappearance, not just a provider token', async () => {
  for (const remains of [true, false]) {
    let calls = 0, checks = 0;
    const page = pageFixture();
    const controller = createBookingCaptchaTest(input, {
      read: async () => amazonTask(challenge), solve: async () => { calls++; return 'fixture-token'; },
    });
    assert.equal(await controller.attempt(page, async () => ++checks === 1 || remains), !remains);
    assert.equal(controller.status(), remains ? 'not_accepted' : 'challenge_cleared');
    assert.equal(page.cookies.length, 1);
    assert.equal(page.cookies[0].domain, 'account.booking.com');
    assert.equal(await controller.attempt(page, async () => true), false);
    assert.equal(calls, 1);
  }
});

test('expired challenge or human navigation never receives a stale provider token', async () => {
  for (const navigate of [true, false]) {
    const page = pageFixture(); let reads = 0;
    const controller = createBookingCaptchaTest(input, {
      read: async () => ({ ...challenge, iv: ++reads === 1 ? 'before' : 'after' }),
      solve: async () => { if (navigate) await page.goto('https://admin.booking.com/hotel/'); return 'fixture-token'; },
    });
    assert.equal(await controller.attempt(page, async () => true), false);
    assert.equal(controller.status(), 'stale');
    assert.equal(page.cookies.length, 0);
  }
});

test('permission denial is scoped and failure never broadens permission changes', async () => {
  const commands = [];
  const browser = { target: () => ({ createCDPSession: async () => ({
    send: async (method, params) => commands.push({ method, params }), detach: async () => {},
  }) }) };
  const permission = await blockBookingLoopback(browser);
  assert.equal(permission.status, 'blocked');
  assert.deepEqual(commands.map(c => c.params.origin), [...BOOKING_AUTH_ORIGINS]);
  assert.ok(commands.every(c => c.method === 'Browser.setPermission'
    && c.params.permission.name === 'loopback-network' && c.params.setting === 'denied'));
  assert.equal((await blockBookingLoopback(browser, false)).status, 'disabled');
  assert.equal(commands.length, 3);
  assert.equal((await blockBookingLoopback({ target() { throw new Error('private error'); } })).status, 'unavailable');
  await permission.release();
});
