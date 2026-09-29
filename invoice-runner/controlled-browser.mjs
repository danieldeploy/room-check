import fs from 'node:fs/promises';
import path from 'node:path';
import { assertPrivateDirectory } from './private-storage.mjs';

// Chrome writes an endpoint inside each fixed, ACL-restricted persistent profile.
// Never accept an endpoint, host or profile path from a portal or a Hub job.
export function controlledBrowserProfile(input) {
  if (input.browserProfile === undefined) return 'primary';
  if (input.browserProfile === 'fresh_login' && ['login', 'discover', 'collect', 'verify'].includes(input.action)
      && input.portal === 'booking' && input.accountId === 1
      && !Object.hasOwn(input, 'session')) return 'fresh_login';
  throw new Error('controlled_profile_invalid');
}

export function usesControlledBrowser(input) {
  return input.portal === 'booking' && input.accountId === 1
    && ['login', 'discover', 'collect', 'verify'].includes(input.action);
}

export function controlledBrowserConnectOptions(endpoint, purpose, action = 'login') {
  return { browserWSEndpoint: endpoint, defaultViewport: null,
    ...(purpose === 'fresh_login' && action === 'login' ? { protocolTimeout: 30000 } : {}) };
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

async function exactBookingPasswordTab(pages) {
  const candidates = [];
  for (const page of pages) {
    try {
      const url = new URL(page.url());
      if (url.protocol !== 'https:' || url.hostname !== 'auth.booking.com'
          || url.pathname !== '/u/login/password') continue;
      const actionable = await page.evaluate(() => [...document.querySelectorAll('input[type="password"]')]
        .filter(el => {
          if (!el.form || !el.getClientRects().length || el.disabled) return false;
          try {
            const action = new URL(el.form.action);
            return action.protocol === 'https:' && action.hostname === 'auth.booking.com'
              && action.pathname === '/u/login/password';
          } catch { return false; }
        }).length === 1);
      if (actionable === true) candidates.push(page);
    } catch { /* An unresponsive or foreign tab is never a login candidate. */ }
  }
  if (candidates.length > 1) throw new Error('controlled_password_tab_ambiguous');
  return candidates[0] || null;
}

export async function controlledBookingPasswordPage(browser) {
  return exactBookingPasswordTab(await browser.defaultBrowserContext().pages());
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
  // Booking may open the password step in a different tab on auth.booking.com.
  // Prefer that exact step over an older account sign-in tab without navigating
  // away from either tab or copying its private query tokens.
  const passwordContinuation = purpose === 'fresh_login' ? await exactBookingPasswordTab(pages) : null;
  // The human challenge remains in its original tab. Reuse the newest exact
  // Booking sign-in tab after the owner completes it when no password tab exists.
  const identifierContinuation = purpose === 'fresh_login' ? [...pages].reverse().find(candidate => {
    try {
      const url = new URL(candidate.url());
      return url.protocol === 'https:' && url.hostname === 'account.booking.com'
        && url.pathname === '/sign-in';
    } catch { return false; }
  }) : null;
  const selected = existing || passwordContinuation || identifierContinuation;
  return selected ? { page: selected, created: false }
    : { page: await context.newPage(), created: true };
}

function awsWafDestination(value) {
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && !url.username && !url.password
      && (!url.port || url.port === '443') && url.hostname.endsWith('.awswaf.com');
  } catch { return false; }
}

function allowedBookingWafRequest(request, portal, allowedUrl, blockedValues) {
  if (portal !== 'booking' || request.isNavigationRequest()
      || !['POST', 'OPTIONS'].includes(request.method())
      || !['fetch', 'xhr', 'other'].includes(request.resourceType())
      || !awsWafDestination(request.url())) return false;
  try {
    // Only the Booking document may initiate SDK traffic. AWS endpoints never
    // become valid destinations for credential forms, navigation or downloads.
    allowedUrl('booking', request.frame()?.url());
    const body = request.postData();
    if (request.method() === 'POST' && typeof body !== 'string') return false;
    if (typeof body === 'string') {
      let decoded = body;
      for (let pass = 0; pass < 3; pass++) {
        if (blockedValues.some(secret => typeof secret === 'string' && secret.length > 0
            && (decoded.includes(secret) || decoded.includes(JSON.stringify(secret).slice(1, -1))))) return false;
        try { decoded = decodeURIComponent(decoded); } catch { break; }
      }
    }
    return true;
  } catch { return false; }
}

export async function guardPortalRequests(page, portal, allowedUrl, blockedValues = [], report = () => {}) {
  await page.setRequestInterception(true);
  const count = kind => { try { report(kind); } catch { /* Diagnostics cannot affect requests. */ } };
  const onRequest = request => {
    if (request.isInterceptResolutionHandled()) return;
    // The password form can be a GET SPA form. If its script fails to intercept
    // Enter, never send a native form navigation containing a vault secret.
    // blockedValues is mutable so a newly received OTP can be registered too.
    try {
      const url = new URL(request.url());
      const queryValues = [...url.searchParams.values()];
      if (blockedValues.some(secret => typeof secret === 'string' && secret.length > 0
          && queryValues.some(value => {
            for (let i = 0; i < 3; i++) {
              if (value.includes(secret)) return true;
              try { value = decodeURIComponent(value); } catch { break; }
            }
            return false;
          }))) {
        void request.abort().catch(() => {});
        return;
      }
    } catch { /* The regular destination allowlist handles invalid URLs. */ }
    if (request.isNavigationRequest() || !['GET', 'HEAD'].includes(request.method())) {
      try { allowedUrl(portal, request.url()); }
      catch {
        if (allowedBookingWafRequest(request, portal, allowedUrl, blockedValues)) count('aws_waf_allowed');
        else {
          if (portal === 'booking' && awsWafDestination(request.url())) count('aws_waf_blocked');
          void request.abort().catch(() => {}); return;
        }
      }
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
