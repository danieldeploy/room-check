import { SecondFactor } from './second-factor.mjs';
import { PortalError } from './booking.mjs';
import { inspectPortalPage, publicLocation } from './map-diagnostics.mjs';

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
export async function discoverHostelworld(page, input, dependencies = {}) {
  let stage = 'login_structure', attempted = false, authenticated = false;
  const snapshots = [];
  let broker;
  let authSignals;
  let sessionSignals;
  let postLinkLoginAttempted = false;
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
    stage = 'consume_link';
    await page.goto(ownUrl(link).href, { waitUntil: 'domcontentloaded' });
    stage = 'account_check';
    sessionSignals = await hostelworldSessionSignals(page);
    if (sessionSignals.human_challenge) fail('human_verification');
    if (sessionSignals.invalid_link) fail('auth_invalid');
    if (sessionSignals.error_notice) fail('needs_auth');
    // A secure link can establish device trust and return to the sign-in form.
    // Continue that visible form once; never request/consume another email in a loop.
    if (sessionSignals.login_form) {
      stage = 'post_link_login';
      await requireLoginForm(page);
      await fillLoginFields(page, input, submission);
      postLinkLoginAttempted = true;
      await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {}),
        page.click('#loginForm input[type="submit"]')]);
      ownUrl(page.url());
      sessionSignals = await hostelworldSessionSignals(page);
    }
    if (sessionSignals.human_challenge) fail('human_verification');
    if (sessionSignals.invalid_link) fail('auth_invalid');
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
    return { version: 1, portal: 'hostelworld', validated: false, login_attempted: attempted,
      authenticated_session: true, account_verified: true, post_link_login_attempted: postLinkLoginAttempted, navigation_stage: stage,
      location: publicLocation('hostelworld', page.url()), snapshots, responses: [], invoice_structure: invoices };
  } catch (error) {
    return { version: 1, portal: 'hostelworld', validated: false, login_attempted: attempted,
      authenticated_session: authenticated, failure_code: error instanceof PortalError ? error.message : 'browser_unavailable',
      failure_stage: stage, auth_signals: authSignals, session_signals: sessionSignals, post_link_login_attempted: postLinkLoginAttempted, submission,
      location: publicLocation('hostelworld', page.url()), snapshots, responses: [] };
  } finally {
    page.off?.('response', onResponse);
    if (broker) await broker.close();
  }
}
