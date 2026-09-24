import fs from 'node:fs/promises';
import path from 'node:path';
import { assertPrivateDirectory } from './private-storage.mjs';

// Chrome writes an endpoint inside each fixed, ACL-restricted persistent profile.
// Never accept an endpoint, host or profile path from a portal or a Hub job.
export function controlledBrowserProfile(input) {
  if (input.browserProfile === undefined) return 'primary';
  if (input.browserProfile === 'fresh_login' && input.action === 'login'
      && input.portal === 'booking' && input.accountId === 1
      && !Object.hasOwn(input, 'session')) return 'fresh_login';
  throw new Error('controlled_profile_invalid');
}

export async function controlledBrowserEndpoint(root, purpose = 'primary') {
  const folder = purpose === 'primary' ? 'controlled-booking-chrome'
    : purpose === 'fresh_login' ? 'controlled-booking-login-chrome' : null;
  if (!folder) throw new Error('controlled_profile_invalid');
  const profile = path.join(root, folder);
  const info = await fs.lstat(profile);
  if (!info.isDirectory() || info.isSymbolicLink()
      || path.dirname(await fs.realpath(profile)) !== root) throw new Error('controlled_profile_invalid');
  await assertPrivateDirectory(profile);
  const endpointFile = path.join(profile, 'DevToolsActivePort');
  const endpointInfo = await fs.lstat(endpointFile);
  if (!endpointInfo.isFile() || endpointInfo.isSymbolicLink()) throw new Error('controlled_endpoint_invalid');
  return parseControlledEndpoint(await fs.readFile(endpointFile, 'utf8'));
}
export function parseControlledEndpoint(data) {
  if (typeof data !== 'string' || data.length > 256) throw new Error('controlled_endpoint_invalid');
  const [port, endpoint] = data.trim().split(/\r?\n/);
  if (!/^[1-9]\d{0,4}$/.test(port) || Number(port) > 65535
      || !/^\/devtools\/browser\/[a-zA-Z0-9-]{8,80}$/.test(endpoint))
    throw new Error('controlled_endpoint_invalid');
  return 'ws://127.0.0.1:' + port + endpoint;
}

// Both discovery and login use the dedicated persistent Chrome profile. A fresh
// incognito context discards the human verification already completed there.
export async function controlledBookingPage(browser, purpose = 'primary') {
  const context = browser.defaultBrowserContext();
  const pages = await context.pages();
  const existing = pages.find(candidate => {
    try {
      const url = new URL(candidate.url());
      return url.protocol === 'https:' && url.hostname === 'admin.booking.com'
        && url.pathname.startsWith('/hotel/');
    } catch { return false; }
  });
  // The human challenge remains in its original tab. Reuse the newest exact
  // Booking sign-in tab after the owner completes it; a new tab would lose the
  // pending challenge and can provoke another verification request.
  const continuation = purpose === 'fresh_login' ? [...pages].reverse().find(candidate => {
    try {
      const url = new URL(candidate.url());
      return url.protocol === 'https:' && url.hostname === 'account.booking.com'
        && url.pathname === '/sign-in';
    } catch { return false; }
  }) : null;
  const selected = existing || continuation;
  return selected ? { page: selected, created: false }
    : { page: await context.newPage(), created: true };
}

export async function guardPortalRequests(page, portal, allowedUrl) {
  await page.setRequestInterception(true);
  const onRequest = request => {
    if (request.isInterceptResolutionHandled()) return;
    if (request.isNavigationRequest() || !['GET', 'HEAD'].includes(request.method())) {
      try { allowedUrl(portal, request.url()); }
      catch { void request.abort().catch(() => {}); return; }
    }
    void request.continue().catch(() => {});
  };
  try { page.on('request', onRequest); }
  catch (error) {
    await page.setRequestInterception(false).catch(() => {});
    throw error;
  }
  return async () => {
    try { await page.setRequestInterception(false); }
    finally { page.off('request', onRequest); }
  };
}
