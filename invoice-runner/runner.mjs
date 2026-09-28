import { blockBookingLoopback } from './booking-permissions.mjs';
import { createBookingCaptchaTest } from './booking-captcha.mjs';
import fs from 'node:fs/promises';
import path from 'node:path';
import { PortalError, validateMap, authenticate, collect } from './booking.mjs';
import { validatePortalMap, authenticatePortal, collectPortal, portalUrl, safeCookies } from './portal.mjs';
import { assertPrivateDirectory } from './private-storage.mjs';
import { controlledBrowserEndpoint, controlledBrowserProfile, controlledBrowserConnectOptions, controlledBookingPage,
  controlledBookingPasswordPage, guardPortalRequests, usesControlledBrowser } from './controlled-browser.mjs';

process.umask(0o077);
let browser;
let profile;
let connected = false;
let controlledPage;
let releaseRequestGuard;
let closing;
let collectionTrace;
async function cleanup() {
  if (closing) return closing;
  closing = (async () => {
    if (browser) {
      if (releaseRequestGuard) await releaseRequestGuard().catch(() => {});
      if (connected) {
        if (controlledPage) await controlledPage.close().catch(() => {});
        browser.disconnect();
      }
      else await browser.close().catch(() => {});
    }
    if (profile) await fs.rm(profile, { recursive: true, force: true });
  })();
  return closing;
}
process.once('SIGTERM', async () => { await cleanup(); process.exit(1); });
const timer = setTimeout(async () => { await cleanup(); process.exit(1); }, 600000);
timer.unref();

try {
  let raw = '';
  for await (const chunk of process.stdin) {
    raw += chunk;
    if (raw.length > 2 * 1024 * 1024) throw new PortalError('browser_unavailable');
  }
  const input = JSON.parse(raw);
  if (input.action === 'verify') collectionTrace = { stage: 'setup' };
  if (!['preflight', 'login', 'collect', 'discover', 'verify'].includes(input.action) || !path.isAbsolute(input.privateDir)
      || input.privateDir.split(path.sep).some(part => ['public_html', '..', '.'].includes(part))) throw new PortalError('browser_unavailable');
  const browserPurpose = controlledBrowserProfile(input);
  const root = await assertPrivateDirectory(input.privateDir);
  if (input.action !== 'preflight' && (!Number.isSafeInteger(input.accountId) || input.accountId < 1
      || !/^20\d{2}-(0[1-9]|1[0-2])$/.test(input.period))) throw new PortalError('connector_unconfigured');
  if (input.action !== 'preflight' && input.portal === 'email') {
    const { openMailbox } = await import('./email.mjs');
    const mailbox = await openMailbox(input.credentials || {});
    await mailbox.logout();
    // The email invoice extractor requires separate document-date validation before collection.
    if (input.action !== 'login') throw new PortalError('connector_unconfigured');
    process.stdout.write(JSON.stringify({ code: 'ok', documents: [] }));
  } else {
    const runtime = input.runtime ?? JSON.parse(await fs.readFile(path.join(root, 'invoice-runtime.json'), 'utf8'));
    if (runtime.executablePath && !path.isAbsolute(runtime.executablePath)) throw new PortalError('browser_unavailable');
    const { default: puppeteer } = await import('puppeteer');
    connected = usesControlledBrowser(input);
    if (connected) {
      browser = await puppeteer.connect(controlledBrowserConnectOptions(
        await controlledBrowserEndpoint(root, browserPurpose), browserPurpose, input.action));
    } else {
      profile = await fs.mkdtemp(path.join(root, '.browser-'));
      browser = await puppeteer.launch({ headless: true, executablePath: runtime.executablePath || undefined,
        userDataDir: profile, timeout: 30000, dumpio: false });
    }
    const selection = connected ? await controlledBookingPage(browser, browserPurpose)
      : { page: await browser.newPage(), created: false };
    const page = selection.page;
    if (connected && selection.created) controlledPage = page;
    page.setDefaultNavigationTimeout(30000); page.setDefaultTimeout(15000);
    if (input.action === 'preflight') {
      await page.setContent('<!doctype html><title>Invoice preflight</title><p>ready</p>');
      if (await page.title() !== 'Invoice preflight') throw new PortalError('browser_unavailable');
      process.stdout.write(JSON.stringify({ code: 'ok' }));
    } else {
      const requestGuards = [];
      const blockedValues = [input.credentials?.password];
      const guard = async target => {
        requestGuards.push(await guardPortalRequests(target, input.portal, portalUrl, blockedValues));
      };
      await guard(page);
      releaseRequestGuard = async () => {
        for (const release of requestGuards.reverse()) await release().catch(() => {});
      };
      const loopbackPermission = input.portal === 'booking'
        ? await blockBookingLoopback(browser, input.automation?.deny_loopback !== false) : undefined;
      const captchaTest = createBookingCaptchaTest(input);
      const loginHooks = {
        loopbackPermission, captchaTest,
        registerBlockedValue: value => { blockedValues.push(value); },
        passwordTab: browserPurpose === 'fresh_login' ? async current => {
          const candidate = await controlledBookingPasswordPage(browser);
          if (!candidate || candidate === current) return null;
          candidate.setDefaultNavigationTimeout(30000); candidate.setDefaultTimeout(15000);
          await guard(candidate);
          return candidate;
        } : undefined,
      };
      if (input.action === 'discover' || (input.action === 'login' && input.portal === 'booking')) {
        if (path.dirname(await fs.realpath(input.exchangeDir)) !== root) throw new PortalError('auth_unconfigured');
        const { discoverPortal, bookingLoginCode } = await import('./discover-portal.mjs');
        const diagnostic = await discoverPortal(page, { ...input, loginOnly: input.action === 'login' }, loginHooks);
        const code = input.action === 'login' ? bookingLoginCode(diagnostic) : diagnostic.failure_code || 'ok';
        // Leave an actual human challenge visible in the persistent Chrome
        // profile so the owner can complete it there. All listeners are still
        // removed by cleanup before detaching from the browser.
        if (connected && code === 'human_verification') controlledPage = undefined;
        process.stdout.write(JSON.stringify({ code, documents: [], diagnostic }));
      } else {
      const mapFile = path.join(root, `account-${input.accountId}-map.json`);
      let map;
      try { map = input.action === 'verify' ? null : input.map ?? JSON.parse(await fs.readFile(mapFile, 'utf8')); }
      catch {
        if (input.portal !== 'booking' || input.accountId !== 1) throw new PortalError('connector_unconfigured');
        map = JSON.parse(await fs.readFile(path.join(root, 'booking-map.json'), 'utf8').catch(() => { throw new PortalError('connector_unconfigured'); }));
      }
      const cookies = safeCookies(input.portal, input.session?.cookies);
      if (!connected && cookies.length) await browser.setCookie(...cookies);
      let documents;
      let verification;
      if (input.action === 'verify' || map?.version === 3) {
        if (!connected || input.portal !== 'booking' || input.accountId !== 1
            || path.dirname(await fs.realpath(input.exchangeDir)) !== root) throw new PortalError('connector_unconfigured');
        const { collectBookingInvoices, validateBookingCollectionMap } = await import('./booking-collection.mjs');
        const target = input.action === 'verify' ? null : validateBookingCollectionMap(map, input);
        const { discoverPortal } = await import('./discover-portal.mjs');
        if (collectionTrace) collectionTrace.stage = 'session';
        const login = await discoverPortal(page, { ...input, loginOnly: true }, loginHooks);
        if (login.authenticated_session !== true) {
          if (login.failure_code === 'human_verification') controlledPage = undefined;
          throw new PortalError(login.failure_code || 'needs_auth');
        }
        const active = await controlledBookingPage(browser, browserPurpose);
        const workPage = active.page;
        if (collectionTrace) collectionTrace.stage = 'navigation';
        if (workPage !== page) await guard(workPage);
        workPage.setDefaultNavigationTimeout(30000); workPage.setDefaultTimeout(15000);
        const navigation = await discoverPortal(workPage, { ...input, loginOnly: false }, loginHooks);
        if (navigation.navigation_stage !== 'invoices_visible') throw new PortalError(navigation.failure_code || 'portal_changed');
        ({ documents, verification } = await collectBookingInvoices(workPage, input, target, collectionTrace));
      } else if (map.version === 1 && input.portal === 'booking' && input.accountId === 1) {
        // Preserve the old validated password/session flow until its map is upgraded.
        const { login, target } = validateMap(map, input.property);
        await authenticate(page, login, input.credentials);
        documents = input.action === 'collect' ? await collect(page, login, target, input.property, input.period) : [];
      } else {
        const { login, target } = validatePortalMap(map, input);
        if (path.dirname(await fs.realpath(input.exchangeDir)) !== root) throw new PortalError('auth_unconfigured');
        await authenticatePortal(page, login, input);
        const downloads = path.join(profile || input.exchangeDir, 'invoice-downloads'); await fs.mkdir(downloads, { mode: 0o700 });
        documents = input.action === 'collect' ? await collectPortal(page, login, target, input, browser, downloads) : [];
      }
      const session = connected ? undefined : { cookies: safeCookies(input.portal, await browser.cookies()) };
      process.stdout.write(JSON.stringify({ code: input.action === 'collect' && !documents.length ? 'no_invoices' : 'ok', documents, session,
        ...(input.action === 'verify' ? { verification, collection_trace: collectionTrace } : {}) }));
      }
    }
  }
} catch (error) {
  // Never emit error.message from Chrome, the portal, network or filesystem.
  const allowed = ['needs_auth', 'human_verification', 'connector_unconfigured', 'portal_changed', 'browser_unavailable', 'document_limit', 'auth_unconfigured', 'auth_timeout', 'auth_invalid', 'account_mismatch', 'invalid_document', 'network_error', 'verification_sample_missing'];
  const code = error instanceof PortalError && allowed.includes(error.message) ? error.message : 'browser_unavailable';
  process.stdout.write(JSON.stringify({ code, ...(collectionTrace ? { collection_trace: collectionTrace } : {}) }));
} finally {
  clearTimeout(timer);
  await cleanup();
}
