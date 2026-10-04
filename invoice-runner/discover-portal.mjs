import { inspectPortalPage, publicLocation } from './map-diagnostics.mjs';
import { portalUrl } from './portal.mjs';
import { SecondFactor } from './second-factor.mjs';
import { PortalError } from './booking.mjs';
import { navigateBookingInvoices } from './booking-discovery.mjs';
import { sanitizeBookingLoginNameEvidence } from './booking-login-metadata.mjs';
import { inspectBookingInvoices } from './booking-invoice-inspection.mjs';
import { rejectBookingOptionalCookies } from './booking-cookie-consent.mjs';

const fail = code => { throw new PortalError(code); };
// Classify browser errors without ever returning their message, stack, or data
// from the sign-in page. The categories are deliberately fixed and bounded.
function browserErrorKind(error) {
  const message = error instanceof Error ? error.message : '';
  if (/node is detached|not attached to the (?:dom|document)/i.test(message)) return 'detached';
  if (/execution context was destroyed|cannot find context with specified id/i.test(message)) return 'context_lost';
  if (/target closed|session closed|connection closed/i.test(message)) return 'browser_closed';
  if (/not clickable|not an element|not visible/i.test(message)) return 'not_clickable';
  if (error?.name === 'TimeoutError'
      || (error?.name === 'ProtocolError' && /timed out|timeout/i.test(message))) return 'timeout';
  return 'other';
}
function authenticatedBookingPage(page) {
  try {
    const url = new URL(page.url());
    return url.protocol === 'https:' && url.hostname === 'admin.booking.com'
      && url.pathname.startsWith('/hotel/');
  } catch { return false; }
}
export function bookingLoginCode(diagnostic) {
  if (diagnostic.failure_code) return diagnostic.failure_code;
  if (diagnostic.authenticated_session !== true) return 'portal_changed';
  return diagnostic.login_attempted === true ? 'ok' : 'session_active';
}
async function humanChallenge(page) {
  const current = new URL(page.url());
  // Booking also puts op_token on an ordinary sign-in page. A token alone is
  // never evidence of a challenge and must not block credential submission.
  if (current.pathname.includes('security_challenge')) return true;
  return await page.evaluate(() => {
    const visibleChallenge = [...document.querySelectorAll(
      'iframe[src*="captcha"], [id*="captcha"], [class*="captcha"], [data-testid*="captcha"]')]
      .some(el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden');
    return visibleChallenge || /let.s make sure you.re human|verify you are human|choose all the /i.test(
      (document.body?.innerText || '').slice(0, 3000));
  }) === true;
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
async function clearInput(page, input) {
  await input.focus();
  // Puppeteer press() accepts one key, not a chord string such as Control+A.
  await page.keyboard.down('Control');
  try { await page.keyboard.press('A'); }
  finally { await page.keyboard.up('Control'); }
  await input.press('Backspace');
  if (await input.evaluate(el => document.activeElement === el && el.value.length === 0) !== true) fail('portal_changed');
}
async function submit(page, field, portal, identifierStep = false, onPhase = () => {}, keyboardIdentifier = false) {
  if (!field.info.action) fail('auth_unconfigured');
  portalUrl(portal, field.info.action);
  let method = 'enter';
  if (portal === 'booking' && identifierStep && keyboardIdentifier) {
    // The fresh login uses one keyboard action. A timed-out CDP command may
    // still have reached Chrome, so never fall back to a button or retry it.
    onPhase('submit_action');
    await page.keyboard.press('Enter');
  } else if (portal === 'booking' && identifierStep) {
    onPhase('button_lookup');
    const buttons = await page.$$('form.nw-signin button:not([type]), form.nw-signin button[type="submit"], form.nw-signin input[type="submit"]');
    const usable = [];
    for (const button of buttons) {
      const info = await button.evaluate(el => ({ visible: el.getClientRects().length > 0 && !el.disabled,
        action: el.form?.action || null }));
      if (info.visible && info.action === field.info.action) usable.push(button);
    }
    onPhase('submit_action');
    if (usable.length === 1) { await usable[0].click(); method = 'form_button'; }
    else await field.input.press('Enter');
  } else { onPhase('submit_action'); await field.input.press('Enter'); }
  onPhase('navigation_wait');
  await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {});
  onPhase('destination_check');
  portalUrl(portal, page.url());
  return method;
}
export async function discoverPortal(initialPage, input, hooks = {}) {
  let page = initialPage;
  const { portal, credentials = {}, authMethod, loginOnly = false } = input;
  const start = portal === 'booking' ? 'https://admin.booking.com/' : input.map?.login?.url;
  if (!start) fail('connector_unconfigured');
  portalUrl(portal, start);
  const broker = new SecondFactor(input, value => portalUrl(portal, value));
  const snapshots = [];
  const responses = [];
  const loginNameRequests = [];
  let challengeVisibleBefore = null, challengeVisibleAfter = null;
  const privateLoginMetadata = () => portal === 'booking' && loginOnly
    ? { private_login_metadata: { version: 1, requests: loginNameRequests.slice(0, 3),
      challenge_visible_before: challengeVisibleBefore, challenge_visible_after: challengeVisibleAfter } } : {};
  const propertyUrls = [];
  const propertyReads = [];
  const onResponse = response => {
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
        if (portal === 'booking' && loginOnly && loginNameRequests.length < 3) {
          const safe = sanitizeBookingLoginNameEvidence({ url: response.url(), status,
            method: request.method(), resourceType: request.resourceType(),
            requestHeaders: request.headers(), responseHeaders: response.headers(), postData: request.postData() });
          if (safe) loginNameRequests.push(safe);
        }
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
  };
  const observedPages = new Set();
  const observe = candidate => {
    if (typeof candidate.on === 'function' && !observedPages.has(candidate)) {
      candidate.on('response', onResponse);
      observedPages.add(candidate);
    }
  };
  observe(page);
  const followPasswordTab = async () => {
    if (portal !== 'booking' || !loginOnly || input.browserProfile !== 'fresh_login'
        || typeof hooks.passwordTab !== 'function') return false;
    const candidate = await hooks.passwordTab(page);
    if (!candidate || candidate === page) return false;
    const url = portalUrl(portal, candidate.url());
    if (url.hostname !== 'auth.booking.com' || url.pathname !== '/u/login/password') fail('portal_changed');
    page = candidate;
    observe(page);
    return true;
  };
  let identifierSent = false, passwordSent = false, otpSent = false;
  let resumedPasswordStep = false;
  let smsPrompted = false, smsSubmitted = false;
  let cookieConsentRejected = false;
  const dismissCookies = async (waitMs = 0) => {
    if (portal !== 'booking') return false;
    const rejected = await rejectBookingOptionalCookies(page, { waitMs });
    cookieConsentRejected ||= rejected;
    return rejected;
  };
  const restoreTypingAfterCookies = async (field, value) => {
    if (!await dismissCookies()) return;
    // Rejecting the banner moves focus. Refill only before the first submit,
    // since its appearance may also have intercepted some typing.
    await clearInput(page, field);
    await field.type(value);
  };
  const smsSignals = () => portal === 'booking' && loginOnly
    ? { sms_prompted: smsPrompted, sms_submitted: smsSubmitted,
      cookie_consent_rejected: cookieConsentRejected,
      ...(hooks.requestGuardCounts ? { aws_waf_allowed: hooks.requestGuardCounts.aws_waf_allowed,
        aws_waf_blocked: hooks.requestGuardCounts.aws_waf_blocked } : {}),
      ...(hooks.loopbackPermission ? { loopback_permission: hooks.loopbackPermission } : {}),
      ...(hooks.captchaTest ? { captcha_status: hooks.captchaTest.status(),
        ...(hooks.captchaTest.staleReason?.() ? { captcha_stale_reason: hooks.captchaTest.staleReason() } : {}),
        ...(hooks.captchaTest.stage ? { captcha_stage: hooks.captchaTest.stage() } : {}) } : {}) } : {};
  let stage = 'prepare';
  let identifierSubmit = null;
  let identifierPhase = null;
  let navigationPhase = null;
  try {
    stage = 'navigate';
    const currentUrl = new URL(page.url());
    if (portal === 'booking' && currentUrl.protocol === 'https:'
        && currentUrl.hostname === 'admin.booking.com' && currentUrl.pathname.startsWith('/hotel/')) {
      await page.reload({ waitUntil: 'domcontentloaded' });
      if (typeof page.waitForFunction === 'function') {
        await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 }).catch(() => {});
      }
      let challengeNow = await humanChallenge(page);
      if (loginOnly) challengeVisibleBefore = challengeNow;
      if (challengeNow && await hooks.captchaTest?.attempt(page, humanChallenge)) challengeNow = false;
      if (challengeNow) { stage = 'human_verification'; fail('human_verification'); }
      await dismissCookies();
      const verified = new URL(page.url());
      if (verified.hostname === 'admin.booking.com' && verified.pathname.startsWith('/hotel/')) {
        stage = 'authenticated_session';
        if (loginOnly) return { version: 1, portal, validated: false, login_attempted: false,
          authenticated_session: true, location: publicLocation(portal, page.url()), snapshots: [], responses,
          ...smsSignals(), ...privateLoginMetadata() };
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
        }, { waitForPropertyEntries: () => Promise.allSettled(propertyReads),
          onPhase: phase => { navigationPhase = phase; } });
        if (navigationStage !== 'invoices_visible') snapshots.push(await inspectPortalPage(page, portal));
        const invoiceInspection = navigationStage === 'invoices_visible' ? await inspectBookingInvoices(page, input) : undefined;
        return { version: 1, portal, validated: false, login_attempted: false,
          authenticated_session: true, navigation_stage: navigationStage,
          location: publicLocation(portal, page.url()), snapshots: snapshots.slice(0, 6), responses,
          invoice_inspection: invoiceInspection };
      }
    }
    if (authMethod === 'sms') await broker.prepare();
    // A human may have just solved Booking's challenge in the persistent fresh
    // profile, or Booking may have opened its password step in another tab.
    // Resume that exact page without copying private in-browser query tokens.
    const continuingSignIn = portal === 'booking' && loginOnly && input.browserProfile === 'fresh_login'
      && currentUrl.protocol === 'https:' && (currentUrl.hostname === 'account.booking.com'
        && currentUrl.pathname === '/sign-in'
        || currentUrl.hostname === 'auth.booking.com' && currentUrl.pathname === '/u/login/password');
    const continuingPasswordStep = continuingSignIn && currentUrl.hostname === 'auth.booking.com';
    if (!continuingSignIn) await page.goto(start, { waitUntil: 'domcontentloaded' });
    if (portal === 'booking') {
      // Its identifier handler is installed by client-side scripts after DOMContentLoaded.
      await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 });
    }
    for (let step = 0; step < 6; step++) {
      portalUrl(portal, page.url());
      stage = 'inspect';
      snapshots.push(await inspectPortalPage(page, portal));
      let challengeNow = portal === 'booking' && await humanChallenge(page);
      if (portal === 'booking' && loginOnly) {
        if (identifierSent) challengeVisibleAfter = challengeNow;
        else if (challengeVisibleBefore === null) challengeVisibleBefore = challengeNow;
      }
      let captchaRestartedSignIn = false;
      if (challengeNow && await hooks.captchaTest?.attempt(page, humanChallenge)) {
        challengeNow = false;
        const resumed = new URL(page.url());
        captchaRestartedSignIn = resumed.hostname === 'account.booking.com' && resumed.pathname === '/sign-in';
        const restartedPassword = resumed.hostname === 'auth.booking.com' && resumed.pathname === '/u/login/password';
        if (captchaRestartedSignIn || restartedPassword) {
          // A consumed SMS code cannot be replayed. A new job obtains a fresh
          // challenge through the existing broker instead of weakening its one-use contract.
          if (otpSent) { stage = 'second_factor'; fail('needs_auth'); }
          passwordSent = false;
          if (captchaRestartedSignIn) identifierSent = false;
          else resumedPasswordStep = true;
        }
      }
      if (challengeNow) {
        stage = 'human_verification'; fail('human_verification');
      }
      if (loginOnly && authenticatedBookingPage(page)) break;
      stage = 'cookie_consent';
      await dismissCookies(step === 0 ? 2500 : 0);
      const identifier = await uniqueInput(page, x => x.autocomplete === 'username' || x.type === 'email'
        || (portal === 'booking' && x.id === 'loginname' && x.type === 'text'));
      if (identifier && !identifierSent) {
        stage = 'identifier';
        identifierPhase = 'field_check';
        if (!credentials.identifier) fail('auth_unconfigured');
        if (!identifier.info.action) fail('auth_unconfigured');
        portalUrl(portal, identifier.info.action);
        identifierPhase = 'typing';
        if (captchaRestartedSignIn) await clearInput(page, identifier.input);
        await identifier.input.type(credentials.identifier); identifierSent = true;
        await restoreTypingAfterCookies(identifier.input, credentials.identifier);
        identifierPhase = 'button_lookup';
        identifierSubmit = await submit(page, identifier, portal, true,
          phase => { identifierPhase = phase; },
          loginOnly && input.accountId === 1 && input.browserProfile === 'fresh_login');
        identifierPhase = 'submitted';
        // Booking can temporarily remove the sign-in form while loading the password step.
        // Wait for an actionable next field instead of treating the loading state as a portal change.
        if (portal === 'booking') {
          const ready = () => location.hostname === 'admin.booking.com' && location.pathname.startsWith('/hotel/')
            || !!document.querySelector('[id*="captcha"], [class*="captcha"]')
            || [...document.querySelectorAll('input')].some(el => {
              const actionable = (el.type === 'password' && el.id !== 'hidden-password')
                || el.autocomplete === 'one-time-code' || (el.type === 'tel' && el.maxLength === 6);
              return actionable && el.getClientRects().length > 0 && !el.disabled;
            });
          if (typeof hooks.passwordTab === 'function' && loginOnly && input.browserProfile === 'fresh_login') {
            // The original tab can remain on the identifier form while Booking
            // opens the password page in another tab. Poll both for up to 20 s;
            // the identifier Enter is never repeated after a CDP timeout.
            for (let attempt = 0; attempt < 10; attempt++) {
              if (await followPasswordTab()) break;
              const oldTabReady = await page.waitForFunction(ready, { timeout: 2000 }).then(() => true, () => false);
              if (oldTabReady || await followPasswordTab()) break;
            }
          } else await page.waitForFunction(ready, { timeout: 20000 }).catch(() => {});
        }
        continue;
      }
      const password = await uniqueInput(page, x => x.type === 'password'
        && !(portal === 'booking' && x.id === 'hidden-password'));
      if (password && !passwordSent) {
        stage = 'password';
        if (!credentials.password) fail('auth_unconfigured');
        if (!password.info.action) fail('auth_unconfigured');
        const passwordAction = portalUrl(portal, password.info.action);
        const passwordPage = portalUrl(portal, page.url());
        const activePasswordStep = portal === 'booking' && loginOnly && input.browserProfile === 'fresh_login'
          && passwordPage.hostname === 'auth.booking.com' && passwordPage.pathname === '/u/login/password';
        if (continuingPasswordStep && passwordAction.hostname === 'auth.booking.com'
            && passwordAction.pathname === '/u/login/password') resumedPasswordStep = true;
        if (activePasswordStep) {
          // The password tab is persistent. Focus and clear through real keyboard
          // events so reruns never append a secret to a retained field value.
          await clearInput(page, password.input);
        }
        await password.input.type(credentials.password); passwordSent = true;
        await restoreTypingAfterCookies(password.input, credentials.password);
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
      if (otp && authMethod === 'sms') smsPrompted = true;
      if (otp && !otpSent) {
        stage = 'second_factor';
        if (authMethod !== 'sms') fail('needs_auth');
        const code = await broker.value({ method: 'sms', digits: 6 });
        if (typeof hooks.registerBlockedValue === 'function') hooks.registerBlockedValue(code);
        await otp.input.type(code); otpSent = true;
        await restoreTypingAfterCookies(otp.input, code);
        await submit(page, otp, portal); smsSubmitted = true;
        if (portal === 'booking' && loginOnly) await page.waitForFunction(() =>
          location.hostname === 'admin.booking.com' && location.pathname.startsWith('/hotel/')
          || !!document.querySelector('[id*="captcha"], [class*="captcha"]'),
        { timeout: 20000 }).catch(() => {});
        continue;
      }
      break;
    }
    if (loginOnly) {
      let challengeNow = portal === 'booking' && await humanChallenge(page);
      if (portal === 'booking') challengeVisibleAfter = challengeNow;
      if (challengeNow) { stage = 'human_verification'; fail('human_verification'); }
      if (!authenticatedBookingPage(page)) { stage = 'login_incomplete'; fail('portal_changed'); }
      return { version: 1, portal, validated: false,
        login_attempted: passwordSent && (identifierSent || resumedPasswordStep),
        authenticated_session: true, location: publicLocation(portal, page.url()),
        snapshots: snapshots.slice(0, 6), identifier_submit: identifierSubmit, responses,
        ...smsSignals(), ...privateLoginMetadata() };
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
      failure_stage: stage, identifier_submit: identifierSubmit, responses,
      ...(portal === 'booking' && loginOnly && stage === 'identifier'
        ? { identifier_phase: identifierPhase,
          browser_error_kind: error instanceof PortalError ? null : browserErrorKind(error) } : {}),
      ...(portal === 'booking' && navigationPhase
        ? { navigation_phase: navigationPhase,
          browser_error_kind: error instanceof PortalError ? null : browserErrorKind(error) } : {}),
      ...smsSignals(), ...privateLoginMetadata() };
  } finally {
    for (const observed of observedPages) if (typeof observed.off === 'function') observed.off('response', onResponse);
    await broker.close();
  }
}
