import fs from 'node:fs/promises';
import path from 'node:path';
import { PortalError, validateMap, authenticate, collect } from './booking.mjs';

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
  if (!['preflight', 'login', 'collect'].includes(input.action) || !path.isAbsolute(input.privateDir)
      || input.privateDir.split(path.sep).some(part => ['public_html', '..', '.'].includes(part))) throw new PortalError('browser_unavailable');
  const root = await fs.realpath(input.privateDir);
  const stat = await fs.stat(root);
  if (!stat.isDirectory() || root.split(path.sep).includes('public_html') || (stat.mode & 0o077)) throw new PortalError('browser_unavailable');
  const runtime = JSON.parse(await fs.readFile(path.join(root, 'invoice-runtime.json'), 'utf8'));
  // Sandbox remains enabled. No arbitrary Chrome flags are accepted from configuration.
  if (runtime.executablePath && !path.isAbsolute(runtime.executablePath)) throw new PortalError('browser_unavailable');
  const { default: puppeteer } = await import('puppeteer');
  profile = await fs.mkdtemp(path.join(root, '.browser-'));
  browser = await puppeteer.launch({ headless: true, executablePath: runtime.executablePath || undefined,
    userDataDir: profile, timeout: 30000, dumpio: false });
  const page = await browser.newPage();
  page.setDefaultNavigationTimeout(30000);
  page.setDefaultTimeout(15000);
  if (input.action === 'preflight') {
    await page.setContent('<!doctype html><title>Invoice preflight</title><p>ready</p>');
    if (await page.title() !== 'Invoice preflight') throw new PortalError('browser_unavailable');
    process.stdout.write(JSON.stringify({ code: 'ok' }));
  } else {
    const map = JSON.parse(await fs.readFile(path.join(root, 'booking-map.json'), 'utf8').catch(() => { throw new PortalError('connector_unconfigured'); }));
    const { login, target } = validateMap(map, input.property);
    const cookies = input.session?.cookies;
    if (Array.isArray(cookies) && cookies.length <= 200) {
      const safe = cookies.filter(cookie => typeof cookie.domain === 'string'
        && /(^|\.)booking\.com$/.test(cookie.domain.replace(/^\./, '')) && cookie.secure === true);
      if (safe.length) await browser.setCookie(...safe);
    }
    await authenticate(page, login, input.credentials);
    const documents = input.action === 'collect' ? await collect(page, login, target, input.property, input.period) : [];
    const session = { cookies: (await browser.cookies()).filter(cookie => /(^|\.)booking\.com$/.test(cookie.domain.replace(/^\./, '')) && cookie.secure) };
    process.stdout.write(JSON.stringify({ code: input.action === 'collect' && !documents.length ? 'no_invoices' : 'ok', documents, session }));
  }
} catch (error) {
  // Never emit error.message from Chrome, the portal, network or filesystem.
  const allowed = ['needs_auth', 'connector_unconfigured', 'portal_changed', 'browser_unavailable', 'document_limit'];
  const code = error instanceof PortalError && allowed.includes(error.message) ? error.message : 'browser_unavailable';
  process.stdout.write(JSON.stringify({ code }));
} finally {
  clearTimeout(timer);
  await cleanup();
}
