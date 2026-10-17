import crypto from 'node:crypto';
import { PortalError, invoiceDate } from './booking.mjs';
import { pdfContent } from './portal.mjs';
import { hostelworldBinding, hostelworldIdentity } from './hostelworld-discovery.mjs';

const ORIGIN = 'https://inbox.hostelworld.com';
const ROOT = '/reports/vatinvoices';
const fail = code => { throw new PortalError(code); };
const digest = value => crypto.createHash('sha256').update(JSON.stringify(value)).digest('hex');
const months = ['january','february','march','april','may','june','july','august','september','october','november','december'];

export function hostelworldPdfIdentity(text, property, trace = null) {
  if (typeof text !== 'string' || text.length > 1024 * 1024 || !/^\d{1,12}$/.test(property)) fail('invalid_document');
  const issuer = /hostelworld\.com/i.test(text);
  const labels = [...text.matchAll(/\bHostel\s+Number\s*:\s*H?(\d{1,12})(?!\d)/gi)].map(m => m[1]);
  const numbers = new Set(labels.map(n => n.replace(/^0+/, '') || '0'));
  const matched = numbers.size === 1 && numbers.has(property);
  if (trace) trace.pdf_identity = { issuer_verified: issuer, property_label_found: labels.length > 0,
    property_verified: matched, property_zero_padded: matched && labels.some(n => n.startsWith('0')) };
  if (!issuer || !matched) fail('account_mismatch');
  const dates = new Set();
  for (const match of text.matchAll(/\b(?:(due\s+date|payment\s+date)|invoice\s+date|date\s+of\s+issue|issue\s+date|date)\s*:\s*(\d{1,2})\s+([A-Za-z]{3,9})\s+(20\d{2})\b/gi)) {
    if (match[1]) continue;
    const month = months.findIndex(m => m === match[3].toLowerCase() || m.slice(0,3) === match[3].toLowerCase());
    if (month < 0) fail('invalid_document');
    try { dates.add(invoiceDate(`${match[4]}-${String(month+1).padStart(2,'0')}-${match[2].padStart(2,'0')}`, 'YYYY-MM-DD')); }
    catch { fail('invalid_document'); }
  }
  if (dates.size !== 1) fail('invalid_document');
  return { issued_on: [...dates][0] };
}

export async function readHostelworldPdf(content, property, trace = null) {
  const bytes = Buffer.from(content, 'base64');
  if (bytes.length < 8 || bytes.length > 2 * 1024 * 1024 || bytes.subarray(0,5).toString() !== '%PDF-'
      || !bytes.subarray(-2048).includes(Buffer.from('%%EOF'))) fail('invalid_document');
  const { getDocument } = await import('pdfjs-dist/legacy/build/pdf.mjs');
  const loading = getDocument({ data: Uint8Array.from(bytes), verbosity: 0, isEvalSupported: false,
    disableFontFace: true, useSystemFonts: false, useWorkerFetch: false, enableXfa: false, stopAtErrors: true });
  let timer;
  try {
    return await Promise.race([(async () => {
      const document = await loading.promise;
      if (document.numPages < 1 || document.numPages > 10) fail('invalid_document');
      let text = '';
      for (let n=1; n<=document.numPages; n++) {
        const page = await document.getPage(n);
        const result = await page.getTextContent();
        for (const item of result.items) if (typeof item.str === 'string') text += item.str + (item.hasEOL ? '\n' : ' ');
        text += '\n';
        if (text.length > 1024 * 1024) fail('invalid_document');
        page.cleanup();
      }
      return hostelworldPdfIdentity(text, property, trace);
    })(), new Promise((_, reject) => { timer = setTimeout(() => reject(new PortalError('invalid_document')), 15000); })]);
  } catch (error) {
    if (error instanceof PortalError) throw error;
    fail('invalid_document');
  } finally { clearTimeout(timer); await loading.destroy().catch(() => {}); }
}

export function hostelworldStructure(headers) {
  const normalized = headers.map(x => String(x).trim().replace(/\s+/g,' ').toLowerCase());
  if (normalized.length < 6 || normalized.length > 20 || normalized[0] !== 'invoice #'
      || !normalized.includes('period starting') || !normalized.includes('period ending')) fail('portal_changed');
  return { headers_sha256: digest(normalized), date_source: 'pdf-date-v1', number_source: 'invoice-row-id' };
}

export function validateHostelworldCollectionMap(map, input) {
  const target = map?.properties?.[input.property];
  if (!hostelworldBinding(input) || input.periodBasis !== 'issue_month' || map?.version !== 4
      || map.validated !== true || map.strategy !== 'hostelworld-vat-v1' || map.portal !== 'hostelworld'
      || map.accountId !== input.accountId || target?.verified !== true
      || !/^[a-f0-9]{64}$/.test(target.headers_sha256 || '') || target.date_source !== 'pdf-date-v1'
      || target.number_source !== 'invoice-row-id') fail('connector_unconfigured');
  return target;
}

export async function readHostelworldTable(page) {
  return page.evaluate(() => {
    const regions = [...document.querySelectorAll('.vat-invoices')];
    if (regions.length !== 1) return { tables: 0 };
    // Some accounts render the heading and rows together; others split them.
    const headers = [...regions[0].querySelectorAll('table.table-block')].filter(t =>
      t.querySelector('th')?.textContent.trim().replace(/\s+/g,' ').toLowerCase() === 'invoice #');
    if (headers.length !== 1) return { tables: 0 };
    const tables = [...regions[0].querySelectorAll('table.table-block')].filter(t => t.querySelector('tr[id^="invoice-"]'));
    if (tables.length !== 1) return { tables: tables.length };
    const table = tables[0];
    return { tables: 1, headers: [...headers[0].querySelectorAll('th')].map(x => x.textContent),
      rows: [...table.querySelectorAll('tr[id^="invoice-"]')].slice(0,401).map(row => ({
        id: row.id.replace(/^invoice-/,''), number: row.querySelector('td')?.textContent.trim(),
        links: [...row.querySelectorAll('a[href*="/reports/vatinvoices/pdf/"]')].map(a => a.href),
      })),
      next: [...new Set([...table.querySelectorAll('tr.show-more-buttons a[href]')].map(a => a.href))],
    };
  });
}

export async function collectHostelworldInvoices(page, input, target = null, dependencies = {}) {
  if (!hostelworldBinding(input) || input.periodBasis !== 'issue_month'
      || !/^20\d{2}-(0[1-9]|1[0-2])$/.test(input.period)) fail('connector_unconfigured');
  const readTable = dependencies.readTable || readHostelworldTable;
  const readPdf = dependencies.readPdf || readHostelworldPdf;
  const download = dependencies.download || ((p,url) => pdfContent(p,url,'hostelworld'));
  const identity = dependencies.identity || hostelworldIdentity;
  const pages = new Set(), seen = new Set(), documents = [];
  let structure, scanned = 0, downloaded = 0, selectedBytes = 0;
  for (let index=0; index<20; index++) {
    const location = new URL(page.url());
    if (location.origin !== ORIGIN || !/^\/reports\/vatinvoices(?:\/\d+)?$/.test(location.pathname)
        || location.search || !await identity(page,input.property)) fail('account_mismatch');
    if (dependencies.trace) dependencies.trace.stage = 'table';
    const raw = await readTable(page);
    if (dependencies.trace) dependencies.trace.table = {
      tables: Number.isInteger(raw?.tables) ? Math.min(Math.max(raw.tables,0),3) : 0,
      headers: Array.isArray(raw?.headers) ? Math.min(raw.headers.length,21) : 0,
      rows: Array.isArray(raw?.rows) ? Math.min(raw.rows.length,401) : 0,
      next_links: Array.isArray(raw?.next) ? Math.min(raw.next.length,3) : 0,
      max_next_links: Math.max(dependencies.trace.table?.max_next_links || 0,
        Array.isArray(raw?.next) ? Math.min(raw.next.length,3) : 0),
    };
    if (raw?.tables !== 1 || !Array.isArray(raw.rows) || !raw.rows.length || raw.rows.length > 400
        || !Array.isArray(raw.headers) || !Array.isArray(raw.next) || raw.next.length > 2) fail('portal_changed');
    const current = hostelworldStructure(raw.headers);
    if (structure && structure.headers_sha256 !== current.headers_sha256
        || target && target.headers_sha256 !== current.headers_sha256) fail('portal_changed');
    structure = current;
    const fingerprint = digest(raw.rows.map(r=>r.id));
    if (pages.has(fingerprint)) fail('portal_changed');
    pages.add(fingerprint);
    for (const row of raw.rows) {
      if (!/^\d{1,12}$/.test(row.id || '') || row.number !== row.id || !Array.isArray(row.links)
          || new Set(row.links).size !== 1) fail('portal_changed');
      const url = new URL(row.links[0]);
      if (url.origin !== ORIGIN || url.pathname !== `${ROOT}/pdf/${row.id}` || url.search || url.hash) fail('portal_changed');
      if (seen.has(row.id)) continue;
      seen.add(row.id); scanned++;
      if (dependencies.trace) dependencies.trace.scanned_documents = Math.min(scanned,401);
      if (scanned > 400) fail('document_limit');
      if (dependencies.trace) { dependencies.trace.stage = 'pdf_download'; delete dependencies.trace.pdf_identity; }
      const content = await download(page,url.href);
      if (typeof content !== 'string') fail('invalid_document');
      const size = Buffer.byteLength(content,'base64'); downloaded += size;
      if (downloaded > 100 * 1024 * 1024) fail('document_limit');
      const pdf = await readPdf(content,input.property,dependencies.trace);
      if (pdf.issued_on.slice(0,7) !== input.period) continue;
      selectedBytes += size;
      if (selectedBytes > 20 * 1024 * 1024 || documents.length >= 100) fail('document_limit');
      documents.push({ number: row.id, portal_document_id: row.id, issued_on: pdf.issued_on,
        period: input.period, period_basis: 'issue_month', format: 'pdf', content });
    }
    if (dependencies.trace) dependencies.trace.stage = 'pagination';
    if (!raw.next.length) {
      if (!target && !documents.length) fail('verification_sample_missing');
      if (dependencies.trace) dependencies.trace.stage = 'complete';
      return { documents, verification: { version: 1, validated: false, strategy: 'hostelworld-vat-v1',
        session_verified: true, property_verified: true, pagination_verified: true,
        pagination_mode: pages.size > 1 ? 'next_page' : 'single_page', pages: pages.size,
        scanned_documents: scanned, structure } };
    }
    // Show More and Show All can point to different cumulative page counts.
    // Validate both, then advance exactly one page so no invoice is skipped.
    const candidates = raw.next.map(value => {
      const next = new URL(value);
      if (next.origin !== ORIGIN || !/^\/reports\/vatinvoices\/[1-9]\d{0,2}$/.test(next.pathname)
          || next.search || (next.hash && !/^#invoice-\d{1,12}$/.test(next.hash))) fail('portal_changed');
      next.hash = '';
      return next;
    }).sort((a,b) => Number(a.pathname.split('/').at(-1)) - Number(b.pathname.split('/').at(-1)));
    const next = candidates[0];
    const currentPage = location.pathname === ROOT ? 1 : Number(location.pathname.split('/').at(-1));
    if (Number(next.pathname.split('/').at(-1)) !== currentPage + 1) fail('portal_changed');
    await page.goto(next.href,{waitUntil:'domcontentloaded'});
  }
  fail('document_limit');
}
