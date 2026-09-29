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

test('provider diagnostics allowlist matching error codes and identify create versus poll failure', async () => {
  for (const [errorId, errorCode, expected] of [
    [1, 'ERROR_KEY_DOES_NOT_EXIST', 'provider_key_invalid'],
    [10, 'ERROR_ZERO_BALANCE', 'provider_zero_balance'],
    [11, 'ERROR_IP_NOT_ALLOWED', 'provider_ip_denied'],
    [12, 'ERROR_CAPTCHA_UNSOLVABLE', 'provider_unsolvable'],
    [24, 'ERROR_INCORRECT_SESSION_DATA', 'provider_session_invalid'],
    [52, 'ERROR_FAILED_LOADING_WIDGET', 'provider_widget_failed'],
    [55, 'ERROR_ACCOUNT_SUSPENDED', 'provider_account_suspended'],
    [1, 'ERROR_ZERO_BALANCE', 'provider_error'],
    [1, key, 'provider_error'],
  ]) {
    for (const polling of [false, true]) {
      let calls = 0;
      const stages = [];
      await assert.rejects(solveAmazonCaptcha(challenge, key, {
        sleep: async () => {}, onStage: stage => stages.push(stage),
        fetchImpl: async () => {
          calls++;
          return response(polling && calls === 1 ? { errorId: 0, taskId: 7 }
            : { errorId, errorCode, errorDescription: key });
        },
      }), error => error.message === expected && !String(error).includes(key));
      assert.deepEqual(stages, polling ? ['create_task', 'poll_task'] : ['create_task']);
      assert.equal(calls, polling ? 2 : 1);
    }
  }
});

test('controller distinguishes local capture failure from sanitized provider rejection', async () => {
  let calls = 0;
  const local = createBookingCaptchaTest(input, {
    read: async () => { throw new Error(key); }, solve: async () => { calls++; },
  });
  await local.attempt(pageFixture(), async () => true);
  assert.equal(local.status(), 'browser_error');
  assert.equal(local.stage(), 'capture_read');
  assert.equal(calls, 0);
  const remote = createBookingCaptchaTest(input, {
    read: async () => challenge,
    solve: (task, apiKey, deps) => solveAmazonCaptcha(task, apiKey, { ...deps,
      fetchImpl: async () => response({ errorId: 10, errorCode: 'ERROR_ZERO_BALANCE', errorDescription: key }),
    }),
  });
  await remote.attempt(pageFixture(), async () => true);
  assert.equal(remote.status(), 'provider_zero_balance');
  assert.equal(remote.stage(), 'create_task');
  assert.equal(await remote.attempt(pageFixture(), async () => true), false);
});

test('capture diagnostics distinguish reload, readiness and context failures without leaking error details', async () => {
  for (const [where, name, message, expected] of [
    ['read', 'Error', 'Execution context was destroyed: ' + key, 'browser_context_lost'],
    ['reload', 'Error', 'net::ERR_ABORTED at https://account.booking.com/?op_token=' + key, 'browser_navigation_aborted'],
    ['ready', 'TimeoutError', 'Waiting failed: ' + key, 'browser_timeout'],
    ['read', 'TargetCloseError', 'private ' + key, 'browser_page_closed'],
    ['read', 'Error', 'private ' + key, 'browser_error'],
  ]) {
    const page = pageFixture();
    let calls = 0;
    const fail = async () => { throw Object.assign(new Error(message), { name }); };
    if (where === 'reload') page.goto = fail;
    if (where === 'ready') page.waitForFunction = fail;
    const controller = createBookingCaptchaTest(input, {
      widget: { read: where === 'read' ? fail : async () => null, canRestart: async () => true },
      read: async () => null, solve: async () => { calls++; },
    });
    assert.equal(await controller.attempt(page, async () => true), false);
    assert.equal(controller.status(), expected);
    assert.equal(controller.stage(), 'capture_' + where);
    assert.equal(calls, 0);
    assert.equal(JSON.stringify({ status: controller.status(), stage: controller.stage() }).includes(key), false);
    assert.equal(await controller.attempt(page, async () => true), false);
  }
});

function pageFixture() {
  let url = 'https://account.booking.com/sign-in?op_token=fixture-private';
  const cookies = [];
  return { cookies, url: () => url, browserContext: () => ({ setCookie: async c => cookies.push(c) }),
    goto: async value => { url = value; }, waitForFunction: async () => {} };
}

test('reload timeout continues only for the same completed document without another GET', async () => {
  for (const state of ['complete', 'interactive', 'other-page']) {
    const page = pageFixture();
    let gets = 0, solves = 0, applied = false, reads = 0;
    page.goto = async () => {
      gets++;
      if (state === 'other-page') page.url = () => 'https://account.booking.com/other';
      throw Object.assign(new Error('private navigation ' + key), { name: 'TimeoutError' });
    };
    page.evaluate = async () => state;
    const controller = createBookingCaptchaTest(input, {
      widget: { read: async () => ++reads === 1 ? null : {
        wafType: 'widget', websiteKey: 'fixture-widget-key', widgetId: 'fixture-id',
        jsapiScript: 'https://a1b2c3.edge.captcha-sdk.awswaf.com/a1b2c3/jsapi.js',
      }, canRestart: async () => true, complete: async () => { applied = true; return true; } },
      read: async () => null,
      solve: async () => { solves++; return 'fixture-token'; },
    });
    assert.equal(await controller.attempt(page, async () => !applied), state === 'complete');
    assert.equal(gets, 1);
    assert.equal(solves, state === 'complete' ? 1 : 0);
    assert.equal(controller.status(), state === 'complete' ? 'challenge_cleared' : 'browser_timeout');
    assert.equal(JSON.stringify({ status: controller.status(), stage: controller.stage() }).includes(key), false);
  }
});
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

test('local apply exceptions are not reported as server rejection and never leak details', async () => {
  for (const [message, expected] of [
    ['widget token cookie conflict', 'browser_cookie_conflict'],
    ['widget token cookie unavailable', 'browser_cookie_unavailable'],
    ['widget callback failed', 'browser_callback_error'],
    ['Execution context was destroyed: ' + key, 'browser_context_lost'],
    ['private callback data ' + key, 'browser_error'],
  ]) {
    let calls = 0;
    const controller = createBookingCaptchaTest(input, {
      widget: { read: async () => ({ wafType: 'widget', websiteKey: 'fixture-widget-key',
        widgetId: 'fixture-id', jsapiScript: 'https://a1b2c3.edge.captcha-sdk.awswaf.com/a1b2c3/jsapi.js' }),
        complete: async () => { throw new Error(message); } },
      solve: async () => { calls++; return 'fixture-token'; },
    });
    assert.equal(await controller.attempt(pageFixture(), async () => true), false);
    assert.equal(controller.status(), expected);
    assert.equal(controller.stage(), 'apply');
    assert.equal(calls, 1);
    assert.equal(JSON.stringify({ status: controller.status(), stage: controller.stage() }).includes(key), false);
  }
});

test('one attempt per job and success requires challenge disappearance, not just a provider token', async () => {
  for (const remains of [true, false]) {
    let calls = 0, checks = 0;
    const page = pageFixture();
    const controller = createBookingCaptchaTest(input, {
      read: async () => amazonTask(challenge), solve: async () => { calls++; return 'fixture-token'; },
    });
    assert.equal(await controller.attempt(page, async () => ++checks <= 2 || remains), !remains);
    assert.equal(controller.status(), remains ? 'not_accepted' : 'challenge_cleared');
    assert.equal(page.cookies.length, 1);
    assert.equal(page.cookies[0].domain, 'account.booking.com');
    assert.equal(await controller.attempt(page, async () => true), false);
    assert.equal(calls, 1);
  }
});

test('AWS widget provider payload uses documented fields and excludes callback/private data', async () => {
  const task = amazonTask({ websiteURL: challenge.websiteURL, websiteKey: 'widget-fixture-key',
    wafType: 'widget', jsapiScript: 'https://a1b2c3.edge.captcha-sdk.awswaf.com/a1b2c3/jsapi.js',
    widgetId: 'private-capture-id', onSuccess: 'private-callback', iv: 'not-for-widget', password: 'private' });
  assert.deepEqual(task, { type: 'AmazonTaskProxyless', websiteURL: challenge.websiteURL,
    websiteKey: 'widget-fixture-key', wafType: 'widget',
    jsapiScript: 'https://a1b2c3.edge.captcha-sdk.awswaf.com/a1b2c3/jsapi.js' });
  let calls = 0;
  for (const jsapiScript of ['https://evil.test/jsapi.js',
    'https://a1b2c3.edge.captcha-sdk.awswaf.com.evil.test/a1b2c3/jsapi.js',
    'https://a1b2c3.edge.captcha-sdk.awswaf.com/other/jsapi.js',
    task.jsapiScript + '?token=private', task.jsapiScript + '#private']) {
    await assert.rejects(solveAmazonCaptcha({ ...task, jsapiScript }, key, {
      fetchImpl: async () => { calls++; throw new Error('unexpected'); },
    }), /unsupported/);
  }
  assert.equal(calls, 0);
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
    assert.equal(controller.staleReason(), navigate ? 'page_changed' : 'challenge_changed');
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
