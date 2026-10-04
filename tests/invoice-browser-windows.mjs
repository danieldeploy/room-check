// Only synthetic CI preflight input is used here. No portal accounts or secrets.
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';
import { spawnSync } from 'node:child_process';
import { assertPrivateDirectory } from '../invoice-runner/private-storage.mjs';
import { testBookingAutomation } from './booking-automation-browser.mjs';
import { testBookingCookieConsent } from './booking-cookie-consent-browser.mjs';
import { testBookingNavigation } from './booking-navigation-browser.mjs';

const root = process.argv[2];
assert.equal(process.platform, 'win32');
await assertPrivateDirectory(root);
const script = fileURLToPath(new URL('../invoice-runner/runner.mjs', import.meta.url));
const result = spawnSync(process.execPath, [script], {
  input: JSON.stringify({ action: 'preflight', privateDir: root, runtime: {} }), encoding: 'utf8', timeout: 60000,
});
if (result.status !== 0 || JSON.parse(result.stdout || '{}').code !== 'ok') {
  console.error('Synthetic preflight status:', result.status, result.stdout);
  // Diagnose only this empty test browser. Production runner errors remain sanitized.
  const requireRunner = createRequire(new URL('../invoice-runner/package.json', import.meta.url));
  const { default: puppeteer } = await import(pathToFileURL(requireRunner.resolve('puppeteer')).href);
  console.info('Installed Chrome:', puppeteer.executablePath());
  const profile = await fs.mkdtemp(path.join(root, '.diagnostic-'));
  let browser;
  try {
    browser = await puppeteer.launch({ headless: true, userDataDir: profile, timeout: 30000, dumpio: true });
    const page = await browser.newPage(); await page.setContent('<title>CI diagnostic</title>');
    console.info('Direct Chrome launch:', await page.title());
  } finally {
    if (browser) await browser.close();
    await fs.rm(profile, { recursive: true, force: true, maxRetries: 5, retryDelay: 500 });
  }
  throw new Error('Production runner preflight failed');
}
console.info('Production runner preflight passed over UTF-8 stdin with a Unicode private path.');
const requireRunner = createRequire(new URL('../invoice-runner/package.json', import.meta.url));
const { default: puppeteer } = await import(pathToFileURL(requireRunner.resolve('puppeteer')).href);
const consentProfile = await fs.mkdtemp(path.join(root, '.consent-'));
let consentBrowser;
try {
  consentBrowser = await puppeteer.launch({ headless: true, userDataDir: consentProfile, timeout: 30000 });
  await testBookingCookieConsent(consentBrowser);
  await testBookingNavigation(consentBrowser);
  console.info('Repeated Finance/Invoices navigation passed with non-clickable native controls for both properties.');
  await testBookingAutomation(consentBrowser);
  console.info('Scoped loopback denial and synthetic CAPTCHA login continuation passed.');
  console.info('Delayed Booking cookie rejection and login continuation passed in sandboxed Windows Chrome.');
} finally {
  if (consentBrowser) await consentBrowser.close();
  await fs.rm(consentProfile, { recursive: true, force: true, maxRetries: 5, retryDelay: 500 });
}
