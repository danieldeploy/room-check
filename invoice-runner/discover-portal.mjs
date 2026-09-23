import { inspectPortalPage, publicLocation } from './map-diagnostics.mjs';
import { portalUrl } from './portal.mjs';
import { SecondFactor } from './second-factor.mjs';
import { PortalError } from './booking.mjs';
import { navigateBookingInvoices } from './booking-discovery.mjs';

const fail = code => { throw new PortalError(code); };
function authenticatedBookingPage(page) {
  try {
    const url = new URL(page.url());
    return url.protocol === 'https:' && url.hostname === 'admin.booking.com'
      && url.pathname.startsWith('/hotel/');
  } catch { return false; }
}
async function humanChallenge(page) {
  const current = new URL(page.url());
  if (current.searchParams.has('op_token') || current.pathname.includes('security_challenge')) return true;
  return await page.evaluate(() => !!document.querySelector(
    'iframe[src*="captcha"], [id*="captcha"], [class*="captcha"], [data-testid*="captcha"]')
    || /let.s make sure you.re human|verify you are human|choose all the /i.test(
      (document.body?.innerText || '').slice(0, 3000))) === true;
}
async function uniqueInput(page, predicate) {
  const inputs = await page.$$('input');
  const matches = [];
  for (const input of inputs) {
    const info = await input.evaluate(el => ({
      visible: el.getClientRects().length > 0 && !el.disabled,
      type: el.type, id: el.id, name: el.name, autocomplete: el.autocomplete, maxLength: el.maxLength,
      action: el.form?.action || null,
    }));
    if (info.visible && predicate(info)) matches.push({ input, info });
  }
  return matches.length === 1 ? matches[0] : null;
}
async function submit(page, field, portal, identifierStep = false) {
  if (!field.info.action) fail('auth_unconfigured');
  portalUrl(portal, field.info.action);
  let method = 'enter';
  if (portal === 'booking' && identifierStep) {
    const buttons = await page.$$('form.nw-signin button:not([type]), form.nw-signin button[type="submit"], form.nw-signin input[type="submit"]');
    const usable = [];
    for (const button of buttons) {
      const info = await button.evaluate(el => ({ visible: el.getClientRects().length > 0 && !el.disabled,
        action: el.form?.action || null }));
      if (info.visible && info.action === field.info.action) usable.push(button);
    }
    if (usable.length === 1) { await usable[0].click(); method = 'form_button'; }
    else await field.input.press('Enter');
  } else await field.input.press('Enter');
  await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {});
  portalUrl(portal, page.url());
  return method;
}
export async function discoverPortal(page, input) {
  const { portal, credentials = {}, authMethod, loginOnly = false } = input;
  const start = portal === 'booking' ? 'https://admin.booking.com/' : input.map?.login?.url;
  if (!start) fail('connector_unconfigured');
  portalUrl(portal, start);
  const broker = new SecondFactor(input, value => portalUrl(portal, value));
  const snapshots = [];
  const responses = [];
  const propertyUrls = [];
  const propertyReads = [];
  if (typeof page.on === 'function') page.on('response', response => {
    try {
      const request = response.request();
      if (!['document','xhr','fetch'].includes(request.resourceType())) return;
      const url = portalUrl(portal, response.url());
      // Keep login transitions visible; telemetry can otherwise evict every useful response.
      if (/\/(?:js-metric|js_errors|navigation_times|js-track)$/.test(url.pathname)
          || url.pathname.includes('/telemetry') || url.pathname.includes('/api/v1/acul/beacon')) return;
      const location = url.pathname.includes('/__challenge_') ? url.origin + '/security_challenge'
        : publicLocation(portal, response.url());
      const status = response.status();
      if (!Number.isInteger(status) || status < 100 || status > 599) return;
      const entry = { location, status, method: ['GET','POST'].includes(request.method()) ? request.method() : 'other' };
      if (url.hostname === 'account.booking.com' && url.pathname === '/account/sign-in/login_name') {
        entry.resource_type = ['xhr','fetch','document'].includes(request.resourceType()) ? request.resourceType() : 'other';
        const mime = String(request.headers()['content-type'] || '').split(';', 1)[0].trim().toLowerCase();
        entry.content_type = mime === 'application/json' ? 'json'
          : mime === 'application/x-www-form-urlencoded' ? 'form' : 'other';
      }
      responses.push(entry);
      if (responses.length > 16) responses.shift();
      if (portal === 'booking' && url.hostname === 'admin.booking.com'
          && url.pathname === '/dml/graphql.json' && status === 200) {
        propertyReads.push(response.json().then(body => {
          const properties = body?.data?.partnerProperty?.propertyListv2?.properties;
          if (!Array.isArray(properties)) return;
          for (const item of properties) {
            if (String(item.id) === String(input.property) && typeof item.extranetUrl === 'string')
              propertyUrls.push(item.extranetUrl);
          }
        }).catch(() => {}));
      }
    } catch { /* ignore foreign and malformed responses */ }
  });
  let identifierSent = false, passwordSent = false, otpSent = false;
  let stage = 'prepare';
  let identifierSubmit = null;
  try {
    stage = 'navigate';
    const currentUrl = new URL(page.url());
    if (portal === 'booking' && currentUrl.protocol === 'https:'
        && currentUrl.hostname === 'admin.booking.com' && currentUrl.pathname.startsWith('/hotel/')) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      const verified = new URL(page.url());
      if (verified.hostname === 'admin.booking.com' && verified.pathname.startsWith('/hotel/')) {
        stage = 'authenticated_session';
        if (loginOnly) return { version: 1, portal, validated: false, login_attempted: false,
          authenticated_session: true, location: publicLocation(portal, page.url()), snapshots: [], responses };
        if (verified.pathname.includes('/groups/home') && typeof page.waitForFunction === 'function') {
          await page.waitForFunction(values => [...document.querySelectorAll('tr,[role="row"]')]
            .some(el => el.getClientRects().length > 0
              && ((el.textContent || '').toLowerCase().includes(values.label.toLowerCase())
                || (el.textContent || '').includes(values.property))),
          { timeout: 15000 }, { label: String(input.propertyLabel || ''), property: String(input.property || '') })
            .catch(() => {});
        }
        await Promise.allSettled(propertyReads);
        snapshots.push(await inspectPortalPage(page, portal));
        const navigationStage = await navigateBookingInvoices(page, { ...input, propertyEntryUrls: propertyUrls }, async () => {
          snapshots.push(await inspectPortalPage(page, portal));
        });
        if (navigationStage !== 'invoices_visible') snapshots.push(await inspectPortalPage(page, portal));
        return { version: 1, portal, validated: false, login_attempted: false,
          authenticated_session: true, navigation_stage: navigationStage,
          location: publicLocation(portal, page.url()), snapshots: snapshots.slice(0, 6), responses };
      }
    }
    if (authMethod === 'sms') await broker.prepare();
    await page.goto(start, { waitUntil: 'domcontentloaded' });
    if (portal === 'booking') {
      // Its identifier handler is installed by client-side scripts after DOMContentLoaded.
      await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 });
    }
    for (let step = 0; step < 6; step++) {
      portalUrl(portal, page.url());
      stage = 'inspect';
      snapshots.push(await inspectPortalPage(page, portal));
      if (portal === 'booking' && await humanChallenge(page)) {
        stage = 'human_verification'; fail('human_verification');
      }
      if (loginOnly && authenticatedBookingPage(page)) break;
      const identifier = await uniqueInput(page, x => x.autocomplete === 'username' || x.type === 'email'
        || (portal === 'booking' && x.id === 'loginname' && x.type === 'text'));
      if (identifier && !identifierSent) {
        stage = 'identifier';
        if (!credentials.identifier) fail('auth_unconfigured');
        if (!identifier.info.action) fail('auth_unconfigured');
        portalUrl(portal, identifier.info.action);
        await identifier.input.type(credentials.identifier); identifierSent = true;
        identifierSubmit = await submit(page, identifier, portal, true);
        // Booking can temporarily remove the sign-in form while loading the password step.
        // Wait for an actionable next field instead of treating the loading state as a portal change.
        if (portal === 'booking') await page.waitForFunction(() =>
          location.hostname === 'admin.booking.com' && location.pathname.startsWith('/hotel/')
          || !!document.querySelector('[id*="captcha"], [class*="captcha"]')
          || [...document.querySelectorAll('input')].some(el => {
            const actionable = (el.type === 'password' && el.id !== 'hidden-password')
              || el.autocomplete === 'one-time-code' || (el.type === 'tel' && el.maxLength === 6);
            return actionable && el.getClientRects().length > 0 && !el.disabled;
          }), { timeout: 20000 }).catch(() => {});
        continue;
      }
      const password = await uniqueInput(page, x => x.type === 'password'
        && !(portal === 'booking' && x.id === 'hidden-password'));
      if (password && !passwordSent) {
        stage = 'password';
        if (!credentials.password) fail('auth_unconfigured');
        if (!password.info.action) fail('auth_unconfigured');
        portalUrl(portal, password.info.action);
        await password.input.type(credentials.password); passwordSent = true;
        await submit(page, password, portal);
        if (portal === 'booking' && loginOnly) await page.waitForFunction(() =>
          location.hostname === 'admin.booking.com' && location.pathname.startsWith('/hotel/')
          || !!document.querySelector('[id*="captcha"], [class*="captcha"]')
          || [...document.querySelectorAll('input')].some(el => el.getClientRects().length > 0
            && !el.disabled && (el.autocomplete === 'one-time-code' || el.type === 'tel')),
        { timeout: 20000 }).catch(() => {});
        continue;
      }
      const otp = await uniqueInput(page, x => x.autocomplete === 'one-time-code' || (x.type === 'tel' && x.maxLength === 6));
      if (otp && !otpSent) {
        stage = 'second_factor';
        if (authMethod !== 'sms') fail('needs_auth');
        const code = await broker.value({ method: 'sms', digits: 6 });
        await otp.input.type(code); otpSent = true;
        await submit(page, otp, portal);
        if (portal === 'booking' && loginOnly) await page.waitForFunction(() =>
          location.hostname === 'admin.booking.com' && location.pathname.startsWith('/hotel/')
          || !!document.querySelector('[id*="captcha"], [class*="captcha"]'),
        { timeout: 20000 }).catch(() => {});
        continue;
      }
      break;
    }
    if (loginOnly) {
      if (portal === 'booking' && await humanChallenge(page)) { stage = 'human_verification'; fail('human_verification'); }
      if (!authenticatedBookingPage(page)) { stage = 'login_incomplete'; fail('portal_changed'); }
      return { version: 1, portal, validated: false, login_attempted: identifierSent && passwordSent,
        authenticated_session: true, location: publicLocation(portal, page.url()),
        snapshots: snapshots.slice(0, 6), identifier_submit: identifierSubmit, responses };
    }
    if (!identifierSent || !passwordSent) { stage = 'login_incomplete'; fail('portal_changed'); }
    const current = publicLocation(portal, page.url());
    // A structural diagnostic never establishes account identity or a validated map.
    return { version: 1, portal, validated: false, login_attempted: identifierSent && passwordSent,
      location: current, snapshots: snapshots.slice(0, 6), identifier_submit: identifierSubmit, responses };
  } catch (error) {
    let location;
    try { location = publicLocation(portal, page.url()); } catch { location = publicLocation(portal, start); }
    return { version: 1, portal, validated: false, login_attempted: identifierSent && passwordSent,
      location, snapshots: snapshots.slice(0, 6), failure_code: error instanceof PortalError ? error.message : 'browser_unavailable',
      failure_stage: stage, identifier_submit: identifierSubmit, responses };
  } finally { await broker.close(); }
}
