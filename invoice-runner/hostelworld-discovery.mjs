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
export async function discoverHostelworld(page, input, dependencies = {}) {
  let stage = 'login_structure', attempted = false, authenticated = false;
  const snapshots = [];
  let broker;
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
    const cookie = await page.$('#truste-consent-button');
    if (cookie && await cookie.evaluate(el => el.getClientRects().length > 0)) await cookie.click();
    stage = 'prepare_email';
    broker = dependencies.broker || new SecondFactor(input, ownUrl);
    await broker.prepare();
    for (const [selector, value] of [['#HostelNumber', input.credentials.hostel_number],
      ['#Username', input.credentials.identifier], ['#Password', input.credentials.password]]) {
      ownUrl(page.url());
      await page.$eval(selector, el => { el.value = ''; el.dispatchEvent(new Event('input', { bubbles: true })); });
      await page.type(selector, String(value));
    }
    stage = 'submit_credentials'; attempted = true;
    await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {}),
      page.click('#loginForm input[type="submit"]')]);
    ownUrl(page.url());
    stage = 'secure_link_requested';
    const sent = await page.$eval('.login-email', (el, expected) =>
      /sent a secure link/i.test(el.textContent || '')
      && [...el.querySelectorAll('strong')].some(x => x.textContent.trim() === 'Hostel Number ' + expected),
    String(input.property)).catch(() => false);
    if (!sent) fail('auth_invalid');
    stage = 'waiting_auth';
    const link = await broker.value({ method: 'email', kind: 'link', linkHost: 'inbox.hostelworld.com', linkPath: '/login/' });
    stage = 'consume_link';
    await page.goto(ownUrl(link).href, { waitUntil: 'domcontentloaded' });
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
      authenticated_session: true, account_verified: true, navigation_stage: stage,
      location: publicLocation('hostelworld', page.url()), snapshots, responses: [], invoice_structure: invoices };
  } catch (error) {
    return { version: 1, portal: 'hostelworld', validated: false, login_attempted: attempted,
      authenticated_session: authenticated, failure_code: error instanceof PortalError ? error.message : 'browser_unavailable',
      failure_stage: stage, snapshots, responses: [] };
  } finally { if (broker) await broker.close(); }
}
