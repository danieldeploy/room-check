import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { parseControlledEndpoint, controlledBrowserEndpoint, controlledBrowserProfile,
  controlledBookingPage, guardPortalRequests } from '../controlled-browser.mjs';

test('Booking control endpoint is pinned to local Chrome', () => {
  assert.equal(parseControlledEndpoint('38617\n/devtools/browser/12345678-abcd-1234-abcd-123456789012\n'),
    'ws://127.0.0.1:38617/devtools/browser/12345678-abcd-1234-abcd-123456789012');
  for (const value of ['0\n/devtools/browser/abcdefgh\n', '65536\n/devtools/browser/abcdefgh\n',
    '38617\n/devtools/page/abcdefgh\n', '38617\n//evil.example\n',
    '38617\n/devtools/browser/abc?token=x\n']) {
    assert.throws(() => parseControlledEndpoint(value));
  }
});

test('only a Booking account-1 login without saved cookies can select the fixed fresh browser', () => {
  assert.equal(controlledBrowserProfile({ action: 'login', portal: 'booking', accountId: 1 }), 'primary');
  assert.equal(controlledBrowserProfile({ action: 'login', portal: 'booking', accountId: 1,
    browserProfile: 'fresh_login' }), 'fresh_login');
  for (const input of [
    { action: 'discover', portal: 'booking', accountId: 1, browserProfile: 'fresh_login' },
    { action: 'login', portal: 'airbnb', accountId: 1, browserProfile: 'fresh_login' },
    { action: 'login', portal: 'booking', accountId: 2, browserProfile: 'fresh_login' },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'fresh_login', session: {} },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'fresh_login', session: { cookies: [] } },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'primary' },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: '../controlled-booking-chrome' },
    { action: 'login', portal: 'booking', accountId: 1, browserProfile: 'ws://127.0.0.1:2000' },
  ]) assert.throws(() => controlledBrowserProfile(input), /controlled_profile_invalid/);
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
