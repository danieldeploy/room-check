// Private deployment diagnostic: no portal connections or credentials are used.
import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { createRequire } from 'node:module';

process.umask(0o077);
const require = createRequire(import.meta.url);
const result = { node: process.version, sandboxEnabled: true, success: false };
let browser;
let profile;

try {
  const { default: puppeteer } = await import('puppeteer');
  result.puppeteer = require('puppeteer/package.json').version;
  result.executablePath = puppeteer.executablePath();
  try {
    await fs.access(result.executablePath, fs.constants.X_OK);
  } catch {
    result.code = 'chrome_not_installed';
    throw new Error(result.code);
  }
  profile = await fs.mkdtemp(path.join(path.dirname(fileURLToPath(import.meta.url)), '.diagnostic-'));
  browser = await puppeteer.launch({ headless: true, userDataDir: profile, timeout: 30000 });
  const page = await browser.newPage();
  await page.setContent('<!doctype html><title>Management Hub diagnostic</title><p>ready</p>');
  if (await page.title() !== 'Management Hub diagnostic') throw new Error('render_failed');
  result.chrome = await browser.version();
  result.success = true;
  result.code = 'ok';
} catch (error) {
  if (!result.code) {
    const message = String(error?.message || '');
    const missingLibrary = message.match(/error while loading shared libraries:\s*([A-Za-z0-9_.+-]+)/);
    if (missingLibrary) {
      result.code = 'missing_shared_library';
      result.library = missingLibrary[1];
    } else if (/no usable sandbox|sandbox.*not supported|operation not permitted|failed to move to new namespace/i.test(message)) {
      result.code = 'sandbox_unavailable';
    } else if (error?.code === 'ERR_MODULE_NOT_FOUND' || error?.code === 'MODULE_NOT_FOUND') {
      result.code = 'puppeteer_not_installed';
    } else {
      result.code = 'browser_launch_or_render_failed';
    }
  }
} finally {
  if (browser) await browser.close().catch(() => {});
  if (profile) await fs.rm(profile, { recursive: true, force: true }).catch(() => {});
  process.stdout.write(JSON.stringify(result, null, 2) + '\n');
  if (!result.success) process.exitCode = 1;
}
