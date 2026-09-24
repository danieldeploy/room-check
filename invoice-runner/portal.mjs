import fs from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { PortalError, invoiceDate } from './booking.mjs';
import { SecondFactor } from './second-factor.mjs';

export const DOMAINS = {
  booking: ['booking.com'], hostelworld: ['hostelworld.com'], airbnb: ['airbnb.com', 'airbnb.pt'],
  expedia: ['expediapartnercentral.com'], hostelsclub: ['hostelsclub.com'],
};
const fail = code => { throw new PortalError(code); };
export function portalUrl(portal, value) {
  let url; try { url = new URL(value); } catch { fail('connector_unconfigured'); }
  if (url.protocol !== 'https:' || url.username || url.password || (url.port && url.port !== '443')
      || !(DOMAINS[portal] || []).some(d => url.hostname === d || url.hostname.endsWith('.' + d))) fail('connector_unconfigured');
  return url;
}
export function safeCookies(portal, cookies) {
  return Array.isArray(cookies) && cookies.length <= 300 ? cookies.filter(c => c.secure === true && typeof c.domain === 'string'
    && (DOMAINS[portal] || []).some(d => c.domain.replace(/^\./, '') === d || c.domain.endsWith('.' + d))) : [];
}
export function validatePortalMap(map, input) {
  const { portal, property, accountId } = input;
  if (!DOMAINS[portal] || map?.version !== 2 || map.validated !== true || map.accountId !== accountId || map.portal !== portal) fail('connector_unconfigured');
  const login = map.login; const target = map.properties?.[property];
  if (!login || !target || !login.authenticated || !login.accountMarker || !login.accountText) fail('connector_unconfigured');
  portalUrl(portal, login.url); portalUrl(portal, target.url);
  if (!target.propertyMarker || !target.propertyText) fail('connector_unconfigured');
  if (!['pdf-list', 'csv-export'].includes(target.type) || (portal === 'airbnb') !== (target.type === 'csv-export')) fail('connector_unconfigured');
  if (target.type === 'csv-export' && (property !== 'account' || input.periodBasis !== 'export_month' || !target.periodSelect || !target.periodValue || !target.download)) fail('connector_unconfigured');
  if (target.type === 'pdf-list' && !['rows', 'number', 'date', 'pdf', 'empty', 'dateFormat'].every(k => target[k])) fail('connector_unconfigured');
  if (input.periodBasis === 'service_month' && (!target.servicePeriod || !target.servicePeriodFormat)) fail('connector_unconfigured');
  if (portal === 'booking' && new URL(target.url).searchParams.get('hotel_id') !== property) fail('connector_unconfigured');
  return { login, target };
}
export async function visible(page, selector) {
  return !!selector && await page.$eval(selector, e => e.getClientRects().length > 0).catch(() => false);
}
async function click(page, selector) {
  await Promise.all([page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 5000 }).catch(() => {}), page.click(selector)]);
}
export async function assertAccount(page, login, portal) {
  portalUrl(portal, page.url());
  const marker = await page.$eval(login.accountMarker, e => e.textContent.trim()).catch(() => '');
  if (marker !== login.accountText) fail('account_mismatch');
}
async function fill(page, selector, value, portal) {
  portalUrl(portal, page.url());
  if (!selector || !value || !(await visible(page, selector))) fail('auth_unconfigured');
  await page.$eval(selector, e => { e.value = ''; e.dispatchEvent(new Event('input', { bubbles: true })); });
  await page.type(selector, value);
}
export async function authenticatePortal(page, login, input) {
  const checkUrl = value => portalUrl(input.portal, value);
  const broker = new SecondFactor(input, checkUrl);
  try {
    // Start inbox/device correlation before the portal can send an automatic challenge.
    await broker.prepare();
    await page.goto(login.url, { waitUntil: 'domcontentloaded' });
    let identifierSent = false, passwordSent = false;
    for (let step = 0; step < 8; step++) {
      checkUrl(page.url());
      if (await visible(page, login.blocked)) fail('needs_auth');
      if (await visible(page, login.invalid)) fail('auth_invalid');
      if (await visible(page, login.authenticated)) { await assertAccount(page, login, input.portal); return; }
      let matched = false;
      for (const challenge of login.challenges || []) {
        if (!(await visible(page, challenge.selector))) continue;
        if (challenge.method !== input.authMethod) continue;
        if (challenge.request) await click(page, challenge.request);
        const value = await broker.value(challenge);
        if (challenge.kind === 'link') {
          await page.goto(checkUrl(value).href, { waitUntil: 'domcontentloaded' });
        } else {
          await fill(page, challenge.input, value, input.portal);
          await click(page, challenge.submit);
        }
        matched = true; break;
      }
      if (matched) continue;
      if (!identifierSent && await visible(page, login.identifier)) {
        if (login.hostelNumber) await fill(page, login.hostelNumber, input.credentials.hostel_number, input.portal);
        await fill(page, login.identifier, input.credentials.identifier, input.portal);
        identifierSent = true;
        if (login.next) { await click(page, login.next); continue; }
      }
      if (!passwordSent && await visible(page, login.password)) {
        await fill(page, login.password, input.credentials.password, input.portal);
        passwordSent = true; await click(page, login.submit); continue;
      }
      if (await visible(page, login.challenge)) fail('needs_auth');
      await delay(1000);
    }
    fail('needs_auth');
  } finally { await broker.close(); }
}

async function assertProperty(page, target, input) {
  const url = portalUrl(input.portal, page.url());
  if (input.portal === 'booking' && url.searchParams.get('hotel_id') !== input.property) fail('account_mismatch');
  const marker = await page.$eval(target.propertyMarker, e => e.textContent.trim()).catch(() => '');
  if (marker !== target.propertyText) fail('account_mismatch');
}

async function pdfContent(page, value, portal) {
  const url = portalUrl(portal, value);
  if (url.origin !== new URL(page.url()).origin) fail('portal_changed');
  return page.evaluate(async href => {
    const response = await fetch(href, { credentials: 'same-origin', redirect: 'error', signal: AbortSignal.timeout(30000) });
    if (!response.ok || Number(response.headers.get('content-length')) > 20 * 1024 * 1024) return null;
    const reader = response.body.getReader(); const parts = []; let length = 0;
    for (;;) {
      const { value, done } = await reader.read(); if (done) break;
      length += value.length;
      if (length > 20 * 1024 * 1024) { await reader.cancel(); return null; }
      parts.push(value);
    }
    const bytes = new Uint8Array(length); let offset = 0;
    for (const part of parts) { bytes.set(part, offset); offset += part.length; }
    if (new TextDecoder().decode(bytes.slice(0, 5)) !== '%PDF-') return null;
    let text = ''; for (let i = 0; i < bytes.length; i += 8192) text += String.fromCharCode(...bytes.subarray(i, i + 8192));
    return btoa(text);
  }, url.href);
}

export async function collectPortal(page, login, target, input, browser, downloadDir) {
  await page.goto(target.url, { waitUntil: 'domcontentloaded' });
  await assertProperty(page, target, input);
  if (await visible(page, login.challenge) || await visible(page, login.blocked)) fail('needs_auth');
  if (target.type === 'csv-export') return collectCsv(page, target, input, browser, downloadDir);
  const documents = [], pages = new Set(), numbers = new Set(); let size = 0;
  for (let index = 0; index < 20; index++) {
    await assertProperty(page, target, input);
    await page.waitForSelector(`${target.rows}, ${target.empty}`, { timeout: 15000 }).catch(() => fail('portal_changed'));
    const rows = await page.$$eval(target.rows, (elements, t) => elements.map(e => ({
      number: e.querySelector(t.number)?.textContent.trim(), date: e.querySelector(t.date)?.textContent.trim(),
      service: t.servicePeriod ? e.querySelector(t.servicePeriod)?.textContent.trim() : null, url: e.querySelector(t.pdf)?.href,
    })), target);
    if (!rows.length && !(await visible(page, target.empty))) fail('portal_changed');
    const fingerprint = JSON.stringify(rows); if (pages.has(fingerprint)) fail('portal_changed'); pages.add(fingerprint);
    for (const row of rows) {
      if (!row.number || !row.date || !row.url) fail('portal_changed');
      const issued = invoiceDate(row.date, target.dateFormat);
      let period = issued.slice(0, 7);
      if (input.periodBasis === 'service_month') {
        if (target.servicePeriodFormat !== 'YYYY-MM' || !/^20\d{2}-(0[1-9]|1[0-2])$/.test(row.service || '')) fail('portal_changed');
        period = row.service;
      }
      if (period !== input.period || numbers.has(row.number)) continue;
      const content = await pdfContent(page, row.url, input.portal);
      if (!content) fail('invalid_document');
      size += content.length; if (size > 28 * 1024 * 1024 || documents.length >= 100) fail('document_limit');
      documents.push({ number: row.number, issued_on: issued, period, period_basis: input.periodBasis, format: 'pdf', content });
      numbers.add(row.number);
    }
    if (!target.nextPage || !(await visible(page, target.nextPage))) return documents;
    const disabled = await page.$eval(target.nextPage, e => e.disabled || e.getAttribute('aria-disabled') === 'true');
    if (disabled) return documents;
    await click(page, target.nextPage);
  }
  fail('document_limit');
}

async function collectCsv(page, target, input, browser, directory) {
  const value = target.periodValue.replaceAll('{YYYY}', input.period.slice(0, 4)).replaceAll('{MM}', input.period.slice(5));
  if (target.openExport) await click(page, target.openExport);
  await page.select(target.periodSelect, value);
  if (await page.$eval(target.periodSelect, e => e.value) !== value) fail('portal_changed');
  if (target.csvOption) await click(page, target.csvOption);
  const cdp = await browser.target().createCDPSession();
  let guid = null, completed = false, failed = false;
  const start = event => {
    try { portalUrl(input.portal, event.url); } catch { failed = true; return; }
    if (guid || !/^[a-zA-Z0-9_-]{1,100}$/.test(event.guid)) { failed = true; return; }
    guid = event.guid;
  };
  const progress = event => {
    if (event.guid !== guid) return;
    if (event.receivedBytes > 20 * 1024 * 1024 || event.state === 'canceled') failed = true;
    if (event.state === 'completed') completed = true;
  };
  cdp.on('Browser.downloadWillBegin', start); cdp.on('Browser.downloadProgress', progress);
  try {
    await cdp.send('Browser.setDownloadBehavior', { behavior: 'allowAndName', downloadPath: directory, eventsEnabled: true });
    await page.click(target.download);
    const deadline = Date.now() + 45000;
    while (!completed && !failed && Date.now() < deadline) await delay(100);
    if (failed || !completed || !guid) fail('portal_changed');
    const file = path.join(directory, guid); const stat = await fs.stat(file);
    if (stat.size > 20 * 1024 * 1024 || stat.size < 8) fail('invalid_document');
    const content = await fs.readFile(file); await fs.unlink(file);
    if (content.includes(0) || /^\s*(?:<!|<html)/i.test(content.toString('utf8'))) fail('invalid_document');
    return [{ number: `csv-${input.period}`, issued_on: null, period: input.period, period_basis: 'export_month', format: 'csv', content: content.toString('base64') }];
  } finally { await cdp.detach().catch(() => {}); }
}
