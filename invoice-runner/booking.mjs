// A site map is configured from the authenticated Booking UI on the server.
// No guessed selectors, hidden endpoints, stealth plugins or CAPTCHA solving.
export const properties = new Set(['1140306', '539828']);
export class PortalError extends Error {}
const fail = code => { throw new PortalError(code); };

export function bookingUrl(value) {
  let url;
  try { url = new URL(value); } catch { fail('connector_unconfigured'); }
  if (url.protocol !== 'https:' || url.username || url.password || (url.port && url.port !== '443')
      || !(url.hostname === 'booking.com' || url.hostname.endsWith('.booking.com'))) fail('connector_unconfigured');
  return url;
}

export function validateMap(map, property) {
  if (!properties.has(property) || map?.version !== 1 || map?.validated !== true) fail('connector_unconfigured');
  const login = map.login;
  const target = map.properties?.[property];
  if (!login || !target) fail('connector_unconfigured');
  bookingUrl(login.url);
  const targetUrl = bookingUrl(target.url);
  if (targetUrl.hostname !== 'admin.booking.com' || targetUrl.searchParams.get('hotel_id') !== property) fail('connector_unconfigured');
  for (const key of ['identifier', 'password', 'submit', 'challenge', 'authenticated']) {
    if (typeof login[key] !== 'string' || !login[key].trim()) fail('connector_unconfigured');
  }
  for (const key of ['rows', 'number', 'date', 'pdf', 'empty', 'propertyMarker']) {
    if (typeof target[key] !== 'string' || !target[key].trim()) fail('connector_unconfigured');
  }
  if (!['YYYY-MM-DD', 'DD/MM/YYYY'].includes(target.dateFormat)) fail('connector_unconfigured');
  return { login, target };
}

export function invoiceDate(value, format) {
  let match;
  let iso;
  if (format === 'YYYY-MM-DD' && (match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value.trim()))) iso = match[0];
  else if (format === 'DD/MM/YYYY' && (match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value.trim()))) iso = `${match[3]}-${match[2]}-${match[1]}`;
  else fail('portal_changed');
  const date = new Date(`${iso}T00:00:00Z`);
  if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== iso) fail('portal_changed');
  return iso;
}

async function visible(page, selector) {
  if (!selector) return false;
  return page.$eval(selector, element => element.getClientRects().length > 0).catch(() => false);
}

async function checkChallenge(page, login) {
  bookingUrl(page.url());
  if (await visible(page, login.challenge)) fail('needs_auth');
}

async function clickAndWait(page, selector) {
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {}),
    page.click(selector),
  ]);
}

export async function authenticate(page, login, credentials) {
  await page.goto(login.url, { waitUntil: 'domcontentloaded' });
  await checkChallenge(page, login);
  if (await visible(page, login.authenticated)) return;
  if (!credentials?.identifier || !credentials?.password) fail('needs_auth');
  if (!(await visible(page, login.identifier))) fail('needs_auth');
  await page.type(login.identifier, credentials.identifier);
  if (login.next) {
    await clickAndWait(page, login.next);
    await checkChallenge(page, login);
  }
  if (!(await visible(page, login.password))) fail('needs_auth');
  // Recheck the destination immediately before entering a secret.
  bookingUrl(page.url());
  await page.type(login.password, credentials.password);
  await clickAndWait(page, login.submit);
  await checkChallenge(page, login);
  if (!(await visible(page, login.authenticated))) fail('needs_auth');
}

async function propertyContext(page, target, property) {
  const url = bookingUrl(page.url());
  if (url.hostname !== 'admin.booking.com' || url.searchParams.get('hotel_id') !== property) fail('portal_changed');
  const text = await page.$eval(target.propertyMarker, el => (el.textContent || '').trim()).catch(() => '');
  if (!new RegExp(`(^|\\D)${property}(\\D|$)`).test(text)) fail('portal_changed');
}

export async function collect(page, login, target, property, period) {
  if (!properties.has(property) || !/^20\d{2}-(0[1-9]|1[0-2])$/.test(period)) fail('portal_changed');
  await page.goto(target.url, { waitUntil: 'domcontentloaded' });
  await checkChallenge(page, login);
  const documents = [];
  const seenPages = new Set();
  const seenNumbers = new Set();
  let totalBytes = 0;
  for (let pageIndex = 0; pageIndex < 20; pageIndex++) {
    await propertyContext(page, target, property);
    await page.waitForSelector(`${target.rows}, ${target.empty}`, { timeout: 20000 }).catch(() => fail('portal_changed'));
    const rows = await page.$$eval(target.rows, (elements, selectors) => elements.map(row => ({
      number: row.querySelector(selectors.number)?.textContent?.trim(),
      date: row.querySelector(selectors.date)?.textContent?.trim(),
      url: row.querySelector(selectors.pdf)?.href,
    })), target);
    if (!rows.length && !(await visible(page, target.empty))) fail('portal_changed');
    const fingerprint = JSON.stringify(rows);
    if (seenPages.has(fingerprint)) fail('portal_changed');
    seenPages.add(fingerprint);
    for (const row of rows) {
      if (!row.number || !row.date || !row.url || row.number.length > 128) fail('portal_changed');
      const issuedOn = invoiceDate(row.date, target.dateFormat);
      if (!issuedOn.startsWith(period) || seenNumbers.has(row.number)) continue;
      const url = bookingUrl(row.url);
      if (url.origin !== new URL(page.url()).origin) fail('portal_changed');
      const pdf = await page.evaluate(async url => {
        const response = await fetch(url, { credentials: 'same-origin', redirect: 'error', signal: AbortSignal.timeout(30000) });
        if (!response.ok || Number(response.headers.get('content-length') || 0) > 10 * 1024 * 1024) return null;
        const reader = response.body.getReader();
        const chunks = [];
        let length = 0;
        while (true) {
          const { value, done } = await reader.read();
          if (done) break;
          length += value.length;
          if (length > 10 * 1024 * 1024) { await reader.cancel(); return null; }
          chunks.push(value);
        }
        const bytes = new Uint8Array(length);
        let offset = 0;
        for (const chunk of chunks) { bytes.set(chunk, offset); offset += chunk.length; }
        if (new TextDecoder().decode(bytes.slice(0, 5)) !== '%PDF-') return null;
        let binary = '';
        for (let i = 0; i < bytes.length; i += 8192) binary += String.fromCharCode(...bytes.subarray(i, i + 8192));
        return btoa(binary);
      }, url.href);
      if (!pdf) fail('portal_changed');
      totalBytes += pdf.length;
      if (totalBytes > 28 * 1024 * 1024 || documents.length >= 100) fail('document_limit');
      documents.push({ number: row.number, issued_on: issuedOn, pdf });
      seenNumbers.add(row.number);
    }
    if (!target.nextPage || !(await visible(page, target.nextPage))) return documents;
    const disabled = await page.$eval(target.nextPage, el => el.disabled || el.getAttribute('aria-disabled') === 'true');
    if (disabled) return documents;
    await clickAndWait(page, target.nextPage);
    await checkChallenge(page, login);
  }
  fail('document_limit');
}
