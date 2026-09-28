import crypto from 'node:crypto';
import { PortalError, invoiceDate } from './booking.mjs';
import { portalUrl, pdfContent } from './portal.mjs';

const fail = code => { throw new PortalError(code); };
const hash = value => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const documentPath = '/fresa/extranet/finance/invoices/get_document';
const normal = value => String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
export function bookingStructure(headers) {
  const values = headers.map(normal);
  const numbers = values.flatMap((v, i) => ['number', 'invoice number', 'document number', 'número', 'número da fatura'].includes(v) ? [i] : []);
  const dates = values.flatMap((v, i) => ['date', 'invoice date', 'issue date', 'data', 'data de emissão'].includes(v) ? [i] : []);
  if (numbers.length !== 1 || dates.length !== 1) fail('portal_changed');
  return { headers_sha256: hash(values), number_column: numbers[0], date_column: dates[0], date_format: 'D MMM YYYY' };
}
export function validateBookingCollectionMap(map, input) {
  const target = map?.properties?.[input.property];
  if (map?.version !== 3 || map.validated !== true || map.strategy !== 'booking-finance-v1'
      || map.portal !== 'booking' || map.accountId !== input.accountId || input.accountId !== 1
      || input.periodBasis !== 'issue_month' || target?.verified !== true || !/^[a-f0-9]{64}$/.test(target.headers_sha256 || '')
      || !Number.isInteger(target.number_column) || !Number.isInteger(target.date_column)
      || target.number_column < 0 || target.number_column > 19 || target.date_column < 0 || target.date_column > 19
      || target.number_column === target.date_column
      || target.date_format !== 'D MMM YYYY') fail('connector_unconfigured');
  return target;
}
function assertProperty(page, input) {
  const url = portalUrl('booking', page.url());
  if (url.hostname !== 'admin.booking.com' || url.searchParams.get('hotel_id') !== input.property) fail('account_mismatch');
  return url;
}
export async function readBookingTable(page) {
  return page.evaluate(() => {
    const visible = el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
    const norm = text => (text || '').trim().replace(/\s+/g, ' ').toLowerCase();
    const docSelector = 'a[href*="/fresa/extranet/finance/invoices/get_document"]';
    const tables = [...document.querySelectorAll('table')].filter(t => visible(t) && t.querySelector(docSelector));
    if (tables.length !== 1) return { tables: tables.length };
    const table = tables[0];
    const controls = [...document.querySelectorAll('button,a,[role="button"]')].filter(visible);
    const nextLabels = ['next', 'next page', 'go to next page', 'seguinte', 'página seguinte', 'próxima página',
      'load more', 'show more', 'ver mais', 'carregar mais'];
    const next = controls.filter(el => nextLabels.includes(norm(el.getAttribute('aria-label') || el.getAttribute('title') || el.innerText)));
    const containers = [...document.querySelectorAll('[class*="pagination" i],nav[aria-label*="pagination" i],[role="navigation"][aria-label*="page" i]')].filter(visible);
    const cssPath = element => {
      const parts = [];
      for (let el = element; el && el.tagName !== 'BODY'; el = el.parentElement) {
        const siblings = [...el.parentElement.children].filter(x => x.tagName === el.tagName);
        parts.unshift(el.tagName.toLowerCase() + ':nth-of-type(' + (siblings.indexOf(el) + 1) + ')');
        if (parts.length > 20) return null;
      }
      return 'body > ' + parts.join(' > ');
    };
    return { tables: 1, headers: [...table.querySelectorAll('thead th,thead td')].map(el => el.innerText),
      rows: [...table.querySelectorAll('tbody tr')].filter(visible).slice(0, 1001).map(row => ({
        cells: [...row.querySelectorAll('td')].map(el => el.innerText),
        links: [...row.querySelectorAll(docSelector)].map(el => el.href),
      })),
      pagination_present: containers.length > 0, next: next.map(el => ({ selector: cssPath(el),
        disabled: el.disabled === true || el.getAttribute('aria-disabled') === 'true',
        load_more: /more|mais/.test(norm(el.getAttribute('aria-label') || el.innerText)) })),
    };
  });
}

// Full-document verification is deliberately separate from structural discovery.
// Actual documents travel only through the protected document-upload protocol.
export async function collectBookingInvoices(page, input, target = null, trace = null) {
  if (input.accountId !== 1 || input.portal !== 'booking' || input.periodBasis !== 'issue_month'
      || !/^\d{1,12}$/.test(input.property || '')) fail('connector_unconfigured');
  assertProperty(page, input);
  await page.waitForFunction(() => [...document.querySelectorAll('table')]
    .some(t => t.getClientRects().length && t.querySelector('a[href*="/fresa/extranet/finance/invoices/get_document"]')),
  { timeout: 15000 }).catch(() => fail('portal_changed'));
  const documents = [], pages = new Set(), seen = new Map();
  let structure, total = 0, paginationMode = 'single_page';
  for (let index = 0; index < 20; index++) {
    if (trace) trace.stage = 'table';
    const origin = assertProperty(page, input).origin;
    const raw = await readBookingTable(page);
    if (raw?.tables !== 1 || !Array.isArray(raw.headers) || raw.headers.length > 20
        || !Array.isArray(raw.rows) || raw.rows.length > 1000 || !raw.rows.length) fail('portal_changed');
    const current = bookingStructure(raw.headers);
    if (structure && hash(structure) !== hash(current) || target && hash({ headers_sha256: target.headers_sha256,
      number_column: target.number_column, date_column: target.date_column, date_format: target.date_format }) !== hash(current)) fail('portal_changed');
    structure = current;
    const fingerprint = hash(raw.rows);
    if (pages.has(fingerprint)) fail('portal_changed');
    pages.add(fingerprint);
    for (const row of raw.rows) {
      const number = String(row.cells?.[structure.number_column] || '').trim();
      const date = String(row.cells?.[structure.date_column] || '').trim();
      if (!number || number.length > 128 || !date || !Array.isArray(row.links) || !row.links.length) fail('portal_changed');
      const issued = invoiceDate(date, structure.date_format);
      const links = [...new Set(row.links)];
      if (links.length !== 1) fail('portal_changed');
      const url = portalUrl('booking', links[0]);
      if (url.origin !== origin || url.pathname !== documentPath || url.searchParams.get('hotel_id') !== input.property) fail('account_mismatch');
      if (seen.has(number) && seen.get(number) !== issued) fail('portal_changed');
      if (issued.slice(0, 7) !== input.period || seen.has(number)) continue;
      if (trace) trace.stage = 'pdf_download';
      const content = await pdfContent(page, url.href, 'booking', trace ? check => { trace.download = check; } : null);
      if (!content) fail('invalid_document');
      total += Buffer.byteLength(content, 'base64');
      if (total > 20 * 1024 * 1024 || documents.length >= 100) fail('document_limit');
      documents.push({ number, issued_on: issued, period: input.period, period_basis: 'issue_month', format: 'pdf', content });
      seen.set(number, issued);
    }
    if (!Array.isArray(raw.next) || raw.next.length > 1 || raw.pagination_present && !raw.next.length) fail('portal_changed');
    if (trace) trace.stage = 'pagination';
    const next = raw.next[0];
    if (!next || next.disabled) {
      if (!target && !documents.length) fail('verification_sample_missing');
      if (trace) trace.stage = 'complete';
      return { documents, verification: { version: 1, validated: false, strategy: 'booking-finance-v1',
        session_verified: true, property_verified: true, pagination_verified: true,
        pagination_mode: paginationMode, structure } };
    }
    if (!next.selector) fail('portal_changed');
    paginationMode = next.load_more ? 'load_more' : 'next_page';
    const oldRows = raw.rows.map(r => r.cells?.[structure.number_column]);
    const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 5000 }).catch(() => {});
    await page.click(next.selector); await navigation;
    await page.waitForFunction(({ numbers, column }) => {
      const tables = [...document.querySelectorAll('table')].filter(t => t.getClientRects().length
        && t.querySelector('a[href*="/fresa/extranet/finance/invoices/get_document"]'));
      if (tables.length !== 1) return false;
      const current = [...tables[0].querySelectorAll('tbody tr')].filter(r => r.getClientRects().length)
        .map(r => r.querySelectorAll('td')[column]?.innerText);
      return JSON.stringify(current) !== JSON.stringify(numbers);
    }, { timeout: 15000 }, { numbers: oldRows, column: structure.number_column }).catch(() => fail('portal_changed'));
  }
  fail('document_limit');
}
