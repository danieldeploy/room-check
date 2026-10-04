import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { portalUrl } from '../portal.mjs';
import { parseControlledEndpoint, controlledBrowserEndpoint, controlledBrowserProfile, controlledBrowserConnectOptions,
  controlledBookingPage, startBookingCollection, guardPortalRequests, usesControlledBrowser } from '../controlled-browser.mjs';

test('Booking control endpoint is pinned to local Chrome', () => {
  assert.equal(parseControlledEndpoint('38617\n/devtools/browser/12345678-abcd-1234-abcd-123456789012\n'),
    'ws://127.0.0.1:38617/devtools/browser/12345678-abcd-1234-abcd-123456789012');
  for (const value of ['0\n/devtools/browser/abcdefgh\n', '65536\n/devtools/browser/abcdefgh\n',
    '38617\n/devtools/page/abcdefgh\n', '38617\n//evil.example\n',
    '38617\n/devtools/browser/abc?token=x\n']) {
    assert.throws(() => parseControlledEndpoint(value));
  }
});

test('Booking account-1 login, discovery and collection share the fixed profile without imported cookies', () => {
  assert.equal(controlledBrowserProfile({ action: 'login', portal: 'booking', accountId: 1 }), 'primary');
  assert.equal(controlledBrowserProfile({ action: 'login', portal: 'booking', accountId: 1,
    browserProfile: 'fresh_login' }), 'fresh_login');
  for (const action of ['login', 'discover', 'collect', 'verify']) {
    const input = { action, portal: 'booking', accountId: 1, browserProfile: 'fresh_login' };
    assert.equal(controlledBrowserProfile(input), 'fresh_login');
    assert.equal(usesControlledBrowser(input), true);
    assert.throws(() => controlledBrowserProfile({ ...input, session: { cookies: [] } }), /controlled_profile_invalid/);
  }
  assert.equal(usesControlledBrowser({ action: 'preflight', portal: 'booking', accountId: 1 }), false);
  assert.equal(usesControlledBrowser({ action: 'collect', portal: 'booking', accountId: 2 }), false);
  assert.equal(usesControlledBrowser({ action: 'collect', portal: 'airbnb', accountId: 1 }), false);
  for (const input of [
    { action: 'preflight', portal: 'booking', accountId: 1, browserProfile: 'fresh_login' },
    { action: 'login', portal: 'airbnb', accountId: 1, browserProfile: 'fresh_login' },
    { action: 'login', portal: 'booking', accountId: 2, browserProfile: 'fresh_login' },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'fresh_login', session: {} },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'fresh_login', session: { cookies: [] } },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'primary' },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: '../controlled-booking-chrome' },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'ws://127.0.0.1:2000' },
  ]) assert.throws(() => controlledBrowserProfile(input), /controlled_profile_invalid/);
});

test('fresh Booking login bounds CDP commands without changing the primary connection', () => {
  const endpoint = 'ws://127.0.0.1:38617/devtools/browser/12345678-abcd-1234-abcd-123456789012';
  assert.deepEqual(controlledBrowserConnectOptions(endpoint, 'fresh_login'), {
    browserWSEndpoint: endpoint, defaultViewport: null, protocolTimeout: 30000,
  });
  assert.deepEqual(controlledBrowserConnectOptions(endpoint, 'primary'), {
    browserWSEndpoint: endpoint, defaultViewport: null,
  });
  for (const action of ['discover', 'collect']) assert.deepEqual(controlledBrowserConnectOptions(endpoint, 'fresh_login', action), {
    browserWSEndpoint: endpoint, defaultViewport: null,
  }, 'PDF fetches must retain the ordinary protocol timeout');
});

test('fixed persistent profiles keep their endpoint files and ports isolated',
  { skip: process.platform === 'win32' }, async () => {
    const created = await fs.mkdtemp(path.join(os.tmpdir(), 'booking-profile-test-'));
    const root = await fs.realpath(created);
    const primary = path.join(root, 'controlled-booking-chrome');
    const fresh = path.join(root, 'controlled-booking-login-chrome');
    const endpointName = 'DevToolsActivePort';
    try {
      await fs.mkdir(primary, { mode: 0o700 });
      await fs.mkdir(fresh, { mode: 0o700 });
      await fs.writeFile(path.join(primary, endpointName), '38617\n/devtools/browser/12345678-abcd-1234-abcd-123456789012\n');
      await fs.writeFile(path.join(fresh, endpointName), '38618\n/devtools/browser/87654321-abcd-1234-abcd-123456789012\n');
      const primaryEndpoint = await controlledBrowserEndpoint(root);
      const freshEndpoint = await controlledBrowserEndpoint(root, 'fresh_login');
      assert.match(primaryEndpoint, /^ws:\/\/127\.0\.0\.1:38617\//);
      assert.match(freshEndpoint, /^ws:\/\/127\.0\.0\.1:38618\//);
      assert.notEqual(primaryEndpoint, freshEndpoint);
      await assert.rejects(controlledBrowserEndpoint(root, '../../outside'), /controlled_profile_invalid/);

      await fs.rm(path.join(fresh, endpointName));
      await assert.rejects(controlledBrowserEndpoint(root, 'fresh_login'), /ENOENT/);
      await fs.chmod(fresh, 0o755);
      await assert.rejects(controlledBrowserEndpoint(root, 'fresh_login'), /private_storage_permissions/);
      await fs.chmod(fresh, 0o700);
      await fs.symlink(path.join(primary, endpointName), path.join(fresh, endpointName));
      await assert.rejects(controlledBrowserEndpoint(root, 'fresh_login'), /controlled_endpoint_invalid/);
      await fs.rm(fresh, { recursive: true });
      await fs.symlink(primary, fresh, 'dir');
      await assert.rejects(controlledBrowserEndpoint(root, 'fresh_login'), /controlled_profile_invalid/);
    } finally { await fs.rm(root, { recursive: true, force: true }); }
  });

test('Booking login reuses a verified page in the persistent Chrome context', async () => {
  const authenticated = { url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/' };
  const privateTab = { url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/' };
  const browser = {
    defaultBrowserContext: () => ({
      pages: async () => [{ url: () => 'about:blank' }, authenticated],
      newPage: async () => { throw new Error('must reuse the existing tab'); },
    }),
    pages: async () => [privateTab],
    newPage: async () => { throw new Error('must use the default context'); },
  };
  assert.deepEqual(await controlledBookingPage(browser), { page: authenticated, created: false });
});

test('fresh Booking login resumes the newest exact sign-in tab after human verification', async () => {
  const oldSignIn = { url: () => 'https://account.booking.com/sign-in' };
  const solvedChallenge = { url: () => 'https://account.booking.com/sign-in?op_token=private-token' };
  const browser = {
    defaultBrowserContext: () => ({
      pages: async () => [oldSignIn,
        { url: () => 'https://account.booking.com/sign-in-malicious?op_token=bad' },
        { url: () => 'https://evil.example/sign-in' }, solvedChallenge],
      newPage: async () => { throw new Error('must continue the existing tab'); },
    }),
  };
  assert.deepEqual(await controlledBookingPage(browser, 'fresh_login'),
    { page: solvedChallenge, created: false });
  const hotel = { url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/' };
  browser.defaultBrowserContext = () => ({
    pages: async () => [hotel, oldSignIn, solvedChallenge],
    newPage: async () => { throw new Error('must reuse authenticated tab'); },
  });
  assert.deepEqual(await controlledBookingPage(browser, 'fresh_login'),
    { page: hotel, created: false });
  const normalProfile = await controlledBookingPage({ defaultBrowserContext: () => ({
    pages: async () => [oldSignIn], newPage: async () => ({ url: () => 'about:blank' }),
  }) });
  assert.equal(normalProfile.created, true, 'the collection profile does not reuse an account sign-in tab');
});

test('fresh Booking login selects the separate password tab ahead of an older sign-in tab', async () => {
  const oldSignIn = { url: () => 'https://account.booking.com/sign-in?op_token=private' };
  const passwordTab = { url: () => 'https://auth.booking.com/u/login/password?state=private',
    evaluate: async () => true };
  const browser = { defaultBrowserContext: () => ({
    pages: async () => [passwordTab,
      { url: () => 'https://auth.booking.com.evil.example/u/login/password' },
      { url: () => 'http://auth.booking.com/u/login/password' },
      { url: () => 'https://auth.booking.com/u/login/password-confirm' }, oldSignIn],
    newPage: async () => { throw new Error('must continue existing password tab'); },
  }) };
  assert.deepEqual(await controlledBookingPage(browser, 'fresh_login'),
    { page: passwordTab, created: false });
  const regular = await controlledBookingPage({ defaultBrowserContext: () => ({
    pages: async () => [oldSignIn, passwordTab], newPage: async () => ({ url: () => 'about:blank' }),
  }) });
  assert.equal(regular.created, true, 'the collection profile must not reuse login tabs');
});

test('fresh Booking login ignores a non-actionable password tab and rejects two actionable tabs', async () => {
  const signIn = { url: () => 'https://account.booking.com/sign-in' };
  const password = (ready, token) => ({
    url: () => `https://auth.booking.com/u/login/password?state=${token}`,
    evaluate: async () => ready,
  });
  const withPages = pages => ({ defaultBrowserContext: () => ({
    pages: async () => pages, newPage: async () => { throw new Error('must reuse sign-in'); },
  }) });
  assert.deepEqual(await controlledBookingPage(withPages([password(false, 'stale'), signIn]), 'fresh_login'),
    { page: signIn, created: false });
  await assert.rejects(controlledBookingPage(withPages([password(true, 'one'), password(true, 'two'), signIn]),
    'fresh_login'), /controlled_password_tab_ambiguous/);
});

test('Booking login opens a tab in the same persistent Chrome context when needed', async () => {
  const page = { url: () => 'about:blank' };
  const browser = {
    defaultBrowserContext: () => ({
      pages: async () => [{ url: () => 'about:blank' }],
      newPage: async () => page,
    }),
    pages: async () => [{ url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/' }],
    newPage: async () => { throw new Error('must use the default context'); },
  };
  assert.deepEqual(await controlledBookingPage(browser), { page, created: true });
});

test('request guard is detached before a persistent page is reused', async () => {
  const page = new EventEmitter();
  const transitions = [];
  page.setRequestInterception = async enabled => { transitions.push(enabled); };
  let forwarded = 0;
  const allowedUrl = (_portal, url) => { if (url.includes('evil.example')) throw new Error(); };
  const request = url => ({
    isInterceptResolutionHandled: () => false,
    isNavigationRequest: () => true,
    method: () => 'GET', url: () => url,
    continue: async () => { forwarded++; }, abort: async () => {},
  });
  const remove = await guardPortalRequests(page, 'booking', allowedUrl);
  assert.equal(page.listenerCount('request'), 1);
  page.emit('request', request('https://admin.booking.com/'));
  assert.equal(forwarded, 1);
  await remove();
  assert.deepEqual(transitions, [true, false]);
  assert.equal(page.listenerCount('request'), 0);
  const removeAgain = await guardPortalRequests(page, 'booking', allowedUrl);
  assert.equal(page.listenerCount('request'), 1);
  await removeAgain();
  assert.equal(page.listenerCount('request'), 0);
});

test('request guard aborts password and OTP query leaks even on allowed Booking GETs', async () => {
  const page = new EventEmitter();
  page.setRequestInterception = async () => {};
  const secrets = ['p@ss&word'];
  const allowedUrl = (_portal, value) => {
    if (new URL(value).hostname !== 'auth.booking.com') throw new Error('foreign');
  };
  let forwarded = 0; let aborted = 0;
  const request = (url, navigation = true) => ({
    isInterceptResolutionHandled: () => false,
    isNavigationRequest: () => navigation,
    method: () => 'GET', url: () => url,
    continue: async () => { forwarded++; }, abort: async () => { aborted++; },
  });
  const release = await guardPortalRequests(page, 'booking', allowedUrl, secrets);
  page.emit('request', request('https://auth.booking.com/u/login/password?state=normal'));
  page.emit('request', request('https://auth.booking.com/u/login/password?password=p%40ss%26word'));
  page.emit('request', request('https://auth.booking.com/u/login/password?password=p%2540ss%2526word', false));
  secrets.push('742619');
  page.emit('request', request('https://auth.booking.com/u/login/otp?code=742619'));
  assert.equal(forwarded, 1);
  assert.equal(aborted, 3);
  await release();
  assert.equal(page.listenerCount('request'), 0);
});

test('AWS SDK POST and preflight are allowed only from Booking without credentials', async () => {
  const page = new EventEmitter();
  page.setRequestInterception = async () => {};
  const password = 'private-password';
  const otp = '742619';
  const counts = [];
  const release = await guardPortalRequests(page, 'booking', portalUrl, [password, otp], kind => counts.push(kind));
  const origin = 'https://a1b2.edge.captcha-sdk.awswaf.com/';
  for (const [patch, expected] of [
    [{}, 'continued'], [{ method: 'OPTIONS', body: undefined }, 'continued'],
    [{ navigation: true }, 'aborted'], [{ frame: 'https://unrelated.test/' }, 'aborted'],
    [{ frame: undefined }, 'aborted'], [{ method: 'DELETE' }, 'aborted'],
    [{ url: 'http://a1b2.edge.captcha-sdk.awswaf.com/' }, 'aborted'],
    [{ url: 'https://a1b2.edge.captcha-sdk.awswaf.com.evil.test/' }, 'aborted'],
    [{ url: origin.replace('https://', 'https://user:secret@') }, 'aborted'],
    [{ body: undefined }, 'aborted'], [{ body: JSON.stringify({ password }) }, 'aborted'],
    [{ body: encodeURIComponent(JSON.stringify({ code: otp })) }, 'aborted'],
    [{ url: origin + '?password=' + password }, 'aborted'],
  ]) {
    const data = { url: origin, method: 'POST', body: '{"challenge":"synthetic"}',
      frame: 'https://account.booking.com/sign-in', navigation: false, ...patch };
    let actual;
    page.emit('request', { isInterceptResolutionHandled: () => false,
      isNavigationRequest: () => data.navigation, method: () => data.method,
      resourceType: () => 'fetch', url: () => data.url, postData: () => data.body,
      frame: () => data.frame ? { url: () => data.frame } : null,
      continue: async () => { actual = 'continued'; }, abort: async () => { actual = 'aborted'; } });
    assert.equal(actual, expected, JSON.stringify(Object.keys(patch)));
  }
  assert.equal(counts.filter(kind => kind === 'aws_waf_allowed').length, 2);
  assert.ok(counts.every(kind => ['aws_waf_allowed', 'aws_waf_blocked'].includes(kind)));
  await release();
});


test('new collection reuses Booking entry tabs and resets once without retrying an uncertain GET', async () => {
  const entry = { url: () => 'https://admin.booking.com/' };
  const foreign = { url: () => 'https://admin.booking.com.evil.test/' };
  const browser = { defaultBrowserContext: () => ({ pages: async () => [foreign, entry],
    newPage: async () => { throw new Error('must reuse'); } }) };
  assert.deepEqual(await controlledBookingPage(browser, 'fresh_login', {fromEntry:true}), {page:entry,created:false});
  let calls = 0;
  const page = { goto: async (url) => { calls++; assert.equal(url,'https://admin.booking.com/'); throw new Error('uncertain'); } };
  await assert.rejects(startBookingCollection(page,{portal:'booking',accountId:1,action:'collect'}), /uncertain/);
  assert.equal(calls,1);
  for (const action of ['login','discover']) await startBookingCollection(page,{portal:'booking',accountId:1,action});
  assert.equal(calls,1,'access-test continuation must retain its page');
});
