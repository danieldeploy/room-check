import { SecondFactor } from './second-factor.mjs';
import { PortalError } from './booking.mjs';
import { inspectPortalPage, publicLocation } from './map-diagnostics.mjs';
import { guardPortalRequests } from './controlled-browser.mjs';
import { portalUrl } from './portal.mjs';

const ORIGIN = 'https://inbox.hostelworld.com';
const fail = code => { throw new PortalError(code); };
export function hostelworldBinding(input) {
  const expected = { 2: '305209', 3: '77759' }[input.accountId];
  return input.portal === 'hostelworld' && input.authMethod === 'email' && !!expected
    && String(input.property) === expected && String(input.credentials?.hostel_number) === expected
    && input.hostelworldAuthTargets?.length === 1
    && input.hostelworldAuthTargets[0].host === 'inbox.hostelworld.com'
    && input.hostelworldAuthTargets[0].path === '/login/';
}
function ownUrl(value) {
  let url;
  try { url = new URL(value); } catch { fail('portal_changed'); }
  if (url.origin !== ORIGIN || url.username || url.password) fail('portal_changed');
  return url;
}
function diagnosticLocation(page) {
  try { return publicLocation('hostelworld', page.url()); }
  catch { return null; } // A failed new tab can still be about:blank.
}
// Server-rendered property metadata is compared in the page; no script text,
// account content, credential values or authentication URLs leave the browser.
export async function hostelworldIdentity(page, property) {
  ownUrl(page.url());
  return page.evaluate(expected => {
    const ids = new Set();
    for (const script of document.querySelectorAll('script:not([src])')) {
      for (const match of (script.textContent || '').matchAll(/gtmPageVars\.propertyID\s*=\s*['"](\d{1,12})['"]/g)) ids.add(match[1]);
    }
    return ids.size === 1 && ids.has(expected)
      && !document.querySelector('#loginForm');
  }, String(property));
}
async function requireLoginForm(page) {
    const formReady = await page.evaluate(() => {
      const form = document.querySelector('#loginForm');
      if (!form || new URL(form.action).origin !== location.origin
          || new URL(form.action).pathname !== '/trylogin.php' || form.method.toLowerCase() !== 'post') return false;
      return ['HostelNumber', 'Username', 'Password'].every(id => {
        const el = document.getElementById(id);
        return el?.form === form && !el.disabled && el.getClientRects().length > 0;
      }) && form.querySelectorAll('input[type="submit"]').length === 1;
    });
    if (!formReady) fail('portal_changed');
}
async function fillLoginFields(page, input, submission) {
    for (const [selector, value] of [['#HostelNumber', input.credentials.hostel_number],
      ['#Username', input.credentials.identifier], ['#Password', input.credentials.password]]) {
      ownUrl(page.url());
      await page.$eval(selector, el => { el.value = ''; el.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.type(selector, String(value));
    }
    // Compare inside the page; never return values, lengths or validation text.
    const filled = await page.evaluate(expected => {
      const form = document.querySelector('#loginForm');
      return {
        fields_match: Object.entries(expected).every(([id, value]) => document.getElementById(id)?.value === value),
        form_valid: !!form && [...form.elements].every(el => !el.willValidate || el.validity.valid),
        mismatched_fields: Object.entries(expected).filter(([id, value]) => document.getElementById(id)?.value !== value).map(([id]) => id),
        oversized_fields: Object.entries(expected).filter(([id, value]) => {
          const limit = document.getElementById(id)?.maxLength;
          return Number.isInteger(limit) && limit >= 0 && value.length > limit;
        }).map(([id]) => id),
      };
    }, { HostelNumber: String(input.credentials.hostel_number), Username: String(input.credentials.identifier),
      Password: String(input.credentials.password) });
    submission.fields_match = filled.fields_match === true;
    submission.form_valid = filled.form_valid === true;
    for (const key of ['mismatched_fields', 'oversized_fields']) {
      submission[key] = ['HostelNumber', 'Username', 'Password'].filter(id => filled[key]?.includes(id));
    }
    if (!submission.fields_match || !submission.form_valid) fail('auth_unconfigured');
}
export async function hostelworldSessionSignals(page) {
  return page.evaluate(() => {
    const text = (document.body?.innerText || '').toLowerCase();
    return {
      login_form: !!document.querySelector('#loginForm'),
      property_marker: [...document.querySelectorAll('script:not([src])')].some(el =>
        /gtmPageVars\.propertyID/.test(el.textContent || '')),
      invoice_link: !!document.querySelector('a[href*="/reports/vatinvoices"]'),
      secure_link_notice: !!document.querySelector('.login-email'),
      human_challenge: /verify you are human|checking your browser|unusual traffic|access denied/.test(text),
      invalid_link: /(?:link|token|session).{0,60}(?:expired|invalid|already used)|(?:expired|invalid).{0,40}link/.test(text),
      error_notice: [...document.querySelectorAll('[role="alert"],.error,.alert-danger,.login-error')].some(el =>
        el.getClientRects().length > 0 && !!el.textContent.trim()),
    };
  });
}
export async function waitHostelworldSession(page, property) {
  const facts = { waited: false, completed: false };
  // A 200 response may still be the intermediate email-link document. Wait
  // within that tab; never request the one-time link or credentials again.
  if (/^\/login\//.test(ownUrl(page.url()).pathname)) {
    facts.waited = true;
    try {
      await page.waitForFunction(expected => {
        const ids = [...document.querySelectorAll('script:not([src])')].flatMap(el =>
          [...(el.textContent || '').matchAll(/gtmPageVars\.propertyID\s*=\s*['"](\d{1,12})['"]/g)].map(m => m[1]));
        const text = (document.body?.innerText || '').toLowerCase();
        return document.readyState !== 'loading' && (ids.includes(expected)
          || !/^\/login\//.test(location.pathname) || !!document.querySelector('#loginForm,.login-email,[role="alert"],.error,.alert-danger,.login-error')
          || /verify you are human|checking your browser|unusual traffic|access denied|(?:link|token).{0,60}(?:expired|invalid|already used)/.test(text));
      }, { timeout: 15000, polling: 200 }, String(property));
      facts.completed = true;
    } catch (error) { if (error?.name !== 'TimeoutError') throw error; }
  }
  return { ...facts, ...await page.evaluate(expected => {
    const identityIds = new Set();
    for (const el of document.querySelectorAll('script:not([src])'))
      for (const m of (el.textContent || '').matchAll(/gtmPageVars\.propertyID\s*=\s*['"](\d{1,12})['"]/g)) identityIds.add(m[1]);
    return { property_count: Math.min(2, identityIds.size), expected_property: identityIds.has(expected),
      link_page: /^\/login\//.test(location.pathname), ready: document.readyState === 'complete' };
  }, String(property)) };
}
export async function confirmHostelworldEntry(page) {
  if (!/^\/login\/[a-f0-9]{32}$/i.test(ownUrl(page.url()).pathname)) return { offered: false, clicked: false };
  const control = await page.evaluate(() => {
    const buttons = [...document.querySelectorAll('button.btn-content.large')];
    const visible = buttons.length === 1 && !buttons[0].disabled && buttons[0].getClientRects().length > 0;
    const label = visible ? (buttons[0].textContent || '').trim().replace(/\s+/g,' ').toLowerCase() : '';
    const entry = /^(?:(?:click(?: here)?|proceed|continue) to )?(?:log in|login|sign in)(?: (?:to |into )?(?:your |my )?(?:account|inbox|hostelworld(?: inbox)?))?[.!]?$/.test(label)
      || /^(?:continue|proceed|confirm login|confirm sign in|continue to (?:hostelworld )?inbox|go to inbox|access (?:hostelworld )?inbox)[.!]?$/.test(label);
    const text = (document.body?.innerText || '').toLowerCase();
    const blocked = !!document.querySelector('#loginForm,.login-email,input[type="password"],iframe[src*="captcha"],[role="alert"],.error,.alert-danger,.login-error')
      || /captcha|verify you are human|checking your browser|unusual traffic|access denied|(?:link|token).{0,60}(?:expired|invalid|already used)/.test(text);
    return { offered: !!visible, recognized: !!entry, blocked };
  });
  if (!control.offered || !control.recognized || control.blocked) return { ...control, clicked: false };
  await Promise.all([page.waitForNavigation({waitUntil:'domcontentloaded',timeout:10000}).catch(error => {
    if (error?.name !== 'TimeoutError') throw error;
  }), page.click('button.btn-content.large')]);
  ownUrl(page.url());
  return { ...control, clicked: true };
}
export async function discoverHostelworld(initialPage, input, dependencies = {}) {
  let page = initialPage;
  let authPage;
  let releaseAuthGuard;
  let stage = 'login_structure', attempted = false, authenticated = false;
  const snapshots = [];
  let broker;
  let authSignals;
  let sessionSignals;
  let sessionReadiness;
  let secureLinkNewTab = false;
  const linkNavigation = { request_matches: false, response_status: null, redirect_count: 0 };
  const submission = { fields_match: false, form_valid: false, navigation_observed: false,
    post_observed: false, response_status: null, mismatched_fields: [], oversized_fields: [] };
  const onResponse = response => {
    try {
      const url = new URL(response.url());
      if (url.origin === ORIGIN && url.pathname === '/trylogin.php'
          && response.request().method() === 'POST') {
        submission.post_observed = true;
        const status = response.status();
        if (Number.isInteger(status) && status >= 100 && status <= 599) submission.response_status = status;
      }
    } catch { /* Only bounded metadata may leave this observer. */ }
  };
  try {
    await page.goto(ORIGIN + '/', { waitUntil: 'domcontentloaded' });
    ownUrl(page.url());
    snapshots.push(await inspectPortalPage(page, 'hostelworld'));
    // Preserve the existing safe structural-only operation when binding is not ready.
    if (!hostelworldBinding(input)) return { version: 1, portal: 'hostelworld', validated: false,
      login_attempted: false, authenticated_session: false, navigation_stage: stage,
      failure_code: 'auth_unconfigured', location: publicLocation('hostelworld', page.url()), snapshots, responses: [] };
    if (!input.credentials.identifier || !input.credentials.password) fail('auth_unconfigured');
    stage = 'login_form';
    await requireLoginForm(page);
    const cookie = await page.$('#truste-consent-button');
    if (cookie && await cookie.evaluate(el => el.getClientRects().length > 0)) await cookie.click();
    stage = 'prepare_email';
    broker = dependencies.broker || new SecondFactor(input, ownUrl);
    await broker.prepare();
    stage = 'verify_filled_form';
    await fillLoginFields(page, input, submission);
    stage = 'submit_credentials'; attempted = true;
    page.on('response', onResponse);
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 })
      .then(() => { submission.navigation_observed = true; }).catch(() => {}),
      page.click('#loginForm input[type="submit"]')]);
    ownUrl(page.url());
    stage = 'secure_link_requested';
    snapshots.push(await inspectPortalPage(page, 'hostelworld'));
    authSignals = await page.evaluate(expected => {
      const notice = document.querySelector('.login-email');
      const text = (document.body?.innerText || '').toLowerCase();
      return {
        sent_notice: !!notice && /sent a secure link/i.test(notice.textContent || ''),
        hostel_matches: !!notice && [...notice.querySelectorAll('strong')].some(el =>
          el.textContent.trim().replace(/\s+/g, ' ') === 'Hostel Number ' + expected),
        login_form: !!document.querySelector('#loginForm'),
        credential_error: /incorrect password|invalid password|invalid login|incorrect login|incorrect username/.test(text),
        human_challenge: /verify you are human|checking your browser|unusual traffic/.test(text),
      };
    }, String(input.property));
    if (!authSignals.sent_notice || !authSignals.hostel_matches)
      fail(authSignals.human_challenge ? 'human_verification' : authSignals.credential_error ? 'auth_invalid' : 'portal_changed');
    stage = 'waiting_auth';
    const link = await broker.value({ method: 'email', kind: 'link', linkHost: 'inbox.hostelworld.com', linkPath: '/login/' });
    const destination = ownUrl(link).href;
    stage = 'open_auth_tab';
    // Match the confirmed email-link flow: preserve the waiting tab and open
    // a new tab in its existing browser context. No cookie export or new profile.
    const context = initialPage.browserContext();
    authPage = await context.newPage();
    if (authPage === initialPage || authPage.browserContext() !== context) fail('browser_unavailable');
    authPage.setDefaultNavigationTimeout(30000);
    authPage.setDefaultTimeout(15000);
    releaseAuthGuard = await guardPortalRequests(authPage, 'hostelworld', portalUrl, [input.credentials.password]);
    page = authPage;
    secureLinkNewTab = true;
    stage = 'consume_link';
    const response = await page.goto(destination, { waitUntil: 'domcontentloaded' });
    // Only bounded transport facts leave the browser; never token URLs/headers.
    if (response) {
      const requests = [...response.request().redirectChain(), response.request()];
      linkNavigation.request_matches = requests[0]?.url() === destination;
      linkNavigation.redirect_count = Math.min(20, requests.length - 1);
      const status = response.status();
      if (Number.isInteger(status) && status >= 100 && status <= 599) linkNavigation.response_status = status;
    }
    ownUrl(page.url());
    stage = 'account_check';
    sessionReadiness = await waitHostelworldSession(page, input.property);
    if (sessionReadiness.link_page && sessionReadiness.property_count === 0) {
      const confirmation = await confirmHostelworldEntry(page);
      if (confirmation.clicked) sessionReadiness = await waitHostelworldSession(page, input.property);
      sessionReadiness.confirmation_offered = confirmation.offered === true;
      sessionReadiness.confirmation_recognized = confirmation.recognized === true;
      sessionReadiness.confirmation_blocked = confirmation.blocked === true;
      sessionReadiness.confirmation_clicked = confirmation.clicked === true;
    }
    sessionSignals = await hostelworldSessionSignals(page);
    snapshots.push(await inspectPortalPage(page, 'hostelworld'));
    if (sessionSignals.human_challenge) fail('human_verification');
    if (sessionSignals.invalid_link) fail('auth_invalid');
    if (sessionSignals.error_notice) fail('needs_auth');
    // The email link should directly authenticate its new tab. A returned
    // sign-in form is a failed session, not a reason to request another email.
    if (sessionSignals.login_form || sessionSignals.secure_link_notice || sessionSignals.error_notice) fail('needs_auth');
    stage = 'account_check';
    if (!await hostelworldIdentity(page, input.property)) fail('account_mismatch');
    authenticated = true;
    stage = 'invoice_navigation';
    await page.goto(ORIGIN + '/reports/vatinvoices', { waitUntil: 'domcontentloaded' });
    if (!await hostelworldIdentity(page, input.property)) fail('account_mismatch');
    snapshots.push(await inspectPortalPage(page, 'hostelworld'));
    const invoices = await page.evaluate(() => ({
      pdf_links: [...document.querySelectorAll('a[href]')].filter(el => /^\/reports\/vatinvoices\/pdf\/\d+$/.test(new URL(el.href).pathname)).length,
      pagination: !!document.querySelector('a[href^="/reports/vatinvoices/2"]'),
      issue_date_column: [...document.querySelectorAll('th')].some(el => /^(invoice date|issue date|date issued)$/i.test(el.textContent.trim())),
    }));
    stage = 'invoices_visible';
    if (dependencies.withInvoices) await dependencies.withInvoices(page);
    return { version: 1, portal: 'hostelworld', validated: false, login_attempted: attempted,
      authenticated_session: true, account_verified: true, secure_link_new_tab: secureLinkNewTab,
      link_navigation: linkNavigation, post_link_login_attempted: false, navigation_stage: stage,
      session_readiness: sessionReadiness,
      location: publicLocation('hostelworld', page.url()), snapshots, responses: [], invoice_structure: invoices };
  } catch (error) {
    return { version: 1, portal: 'hostelworld', validated: false, login_attempted: attempted,
      authenticated_session: authenticated, failure_code: error instanceof PortalError ? error.message : 'browser_unavailable',
      failure_stage: stage, auth_signals: authSignals, session_signals: sessionSignals,
      session_readiness: sessionReadiness,
      secure_link_new_tab: secureLinkNewTab, link_navigation: linkNavigation, post_link_login_attempted: false, submission,
      location: diagnosticLocation(page), snapshots, responses: [] };
  } finally {
    initialPage.off?.('response', onResponse);
    if (releaseAuthGuard) await releaseAuthGuard().catch(() => {});
    if (authPage && authPage !== initialPage) await authPage.close().catch(() => {});
    if (broker) await broker.close();
  }
}
