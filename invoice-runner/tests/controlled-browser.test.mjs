import test from 'node:test';
import assert from 'node:assert/strict';
import { EventEmitter } from 'node:events';
import { parseControlledEndpoint, controlledBookingPage, guardPortalRequests } from '../controlled-browser.mjs';

test('Booking control endpoint is pinned to local Chrome', () => {
  assert.equal(parseControlledEndpoint('38617\n/devtools/browser/12345678-abcd-1234-abcd-123456789012\n'),
    'ws://127.0.0.1:38617/devtools/browser/12345678-abcd-1234-abcd-123456789012');
  for (const value of ['0\n/devtools/browser/abcdefgh\n', '65536\n/devtools/browser/abcdefgh\n',
    '38617\n/devtools/page/abcdefgh\n', '38617\n//evil.example\n',
    '38617\n/devtools/browser/abc?token=x\n']) {
    assert.throws(() => parseControlledEndpoint(value));
  }
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
