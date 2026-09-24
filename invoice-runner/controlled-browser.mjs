import fs from 'node:fs/promises';
import path from 'node:path';
import { assertPrivateDirectory } from './private-storage.mjs';

// Chrome writes this file inside its dedicated, ACL-restricted profile.
// Never accept an endpoint, host or profile path from a portal or a Hub job.
export async function controlledBrowserEndpoint(root) {
  const profile = path.join(root, 'controlled-booking-chrome');
  if (path.dirname(await fs.realpath(profile)) !== root) throw new Error('controlled_profile_invalid');
  await assertPrivateDirectory(profile);
  const data = await fs.readFile(path.join(profile, 'DevToolsActivePort'), 'utf8');
  return parseControlledEndpoint(data);
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
export async function controlledBookingPage(browser) {
  const context = browser.defaultBrowserContext();
  const existing = (await context.pages()).find(candidate => {
    try {
      const url = new URL(candidate.url());
      return url.protocol === 'https:' && url.hostname === 'admin.booking.com'
        && url.pathname.startsWith('/hotel/');
    } catch { return false; }
  });
  return existing ? { page: existing, created: false }
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
