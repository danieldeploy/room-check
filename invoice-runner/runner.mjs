import fs from 'node:fs/promises';
import path from 'node:path';
import { PortalError, validateMap, authenticate, collect } from './booking.mjs';
import { validatePortalMap, authenticatePortal, collectPortal, portalUrl, safeCookies } from './portal.mjs';
import { assertPrivateDirectory } from './private-storage.mjs';

process.umask(0o077);
let browser;
let profile;
let closing;
async function cleanup() {
  if (closing) return closing;
  closing = (async () => {
    if (browser) await browser.close().catch(() => {});
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
  if (!['preflight', 'login', 'collect', 'discover'].includes(input.action) || !path.isAbsolute(input.privateDir)
      || input.privateDir.split(path.sep).some(part => ['public_html', '..', '.'].includes(part))) throw new PortalError('browser_unavailable');
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
    profile = await fs.mkdtemp(path.join(root, '.browser-'));
    browser = await puppeteer.launch({ headless: true, executablePath: runtime.executablePath || undefined,
      userDataDir: profile, timeout: 30000, dumpio: false });
    const page = await browser.newPage();
    page.setDefaultNavigationTimeout(30000); page.setDefaultTimeout(15000);
    if (input.action === 'preflight') {
      await page.setContent('<!doctype html><title>Invoice preflight</title><p>ready</p>');
      if (await page.title() !== 'Invoice preflight') throw new PortalError('browser_unavailable');
      process.stdout.write(JSON.stringify({ code: 'ok' }));
    } else {
      if (input.action === 'discover') {
        if (path.dirname(await fs.realpath(input.exchangeDir)) !== root) throw new PortalError('auth_unconfigured');
        await page.setRequestInterception(true);
        page.on('request', request => {
          if (request.isInterceptResolutionHandled()) return;
          if (request.isNavigationRequest() || !['GET', 'HEAD'].includes(request.method())) {
            try { portalUrl(input.portal, request.url()); } catch { void request.abort().catch(() => {}); return; }
          }
          void request.continue().catch(() => {});
        });
        const { discoverPortal } = await import('./discover-portal.mjs');
        const diagnostic = await discoverPortal(page, input);
        process.stdout.write(JSON.stringify({ code: diagnostic.failure_code || 'ok', documents: [], diagnostic }));
      } else {
      const mapFile = path.join(root, `account-${input.accountId}-map.json`);
      let map;
      try { map = input.map ?? JSON.parse(await fs.readFile(mapFile, 'utf8')); }
      catch {
        if (input.portal !== 'booking' || input.accountId !== 1) throw new PortalError('connector_unconfigured');
        map = JSON.parse(await fs.readFile(path.join(root, 'booking-map.json'), 'utf8').catch(() => { throw new PortalError('connector_unconfigured'); }));
      }
      await page.setRequestInterception(true);
      page.on('request', request => {
        if (request.isInterceptResolutionHandled()) return;
        if (request.isNavigationRequest() || !['GET', 'HEAD'].includes(request.method())) {
          try { portalUrl(input.portal, request.url()); } catch { void request.abort().catch(() => {}); return; }
        }
        void request.continue().catch(() => {});
      });
      const cookies = safeCookies(input.portal, input.session?.cookies);
      if (cookies.length) await browser.setCookie(...cookies);
      let documents;
      if (map.version === 1 && input.portal === 'booking' && input.accountId === 1) {
        // Preserve the old validated password/session flow until its map is upgraded.
        const { login, target } = validateMap(map, input.property);
        await authenticate(page, login, input.credentials);
        documents = input.action === 'collect' ? await collect(page, login, target, input.property, input.period) : [];
      } else {
        const { login, target } = validatePortalMap(map, input);
        if (path.dirname(await fs.realpath(input.exchangeDir)) !== root) throw new PortalError('auth_unconfigured');
        await authenticatePortal(page, login, input);
        const downloads = path.join(profile, 'invoice-downloads'); await fs.mkdir(downloads, { mode: 0o700 });
        documents = input.action === 'collect' ? await collectPortal(page, login, target, input, browser, downloads) : [];
      }
      const session = { cookies: safeCookies(input.portal, await browser.cookies()) };
      process.stdout.write(JSON.stringify({ code: input.action === 'collect' && !documents.length ? 'no_invoices' : 'ok', documents, session }));
      }
    }
  }
} catch (error) {
  // Never emit error.message from Chrome, the portal, network or filesystem.
  const allowed = ['needs_auth', 'connector_unconfigured', 'portal_changed', 'browser_unavailable', 'document_limit', 'auth_unconfigured', 'auth_timeout', 'auth_invalid', 'account_mismatch', 'invalid_document', 'network_error'];
  const code = error instanceof PortalError && allowed.includes(error.message) ? error.message : 'browser_unavailable';
  process.stdout.write(JSON.stringify({ code }));
} finally {
  clearTimeout(timer);
  await cleanup();
}
