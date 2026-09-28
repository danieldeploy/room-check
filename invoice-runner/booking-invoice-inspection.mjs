import { invoiceDate } from './booking.mjs';
import { portalUrl } from './portal.mjs';
import { publicLocation } from './map-diagnostics.mjs';

const normalize = value => String(value || '').trim().replace(/\s+/g, ' ').toLowerCase();
const columnNames = {
  issue_date: ['invoice date', 'issue date', 'date issued', 'data de emissão', 'data da fatura'],
  date: ['date', 'data'],
  number: ['invoice number', 'document number', 'invoice no.', 'número da fatura', 'número do documento'],
  period: ['invoice period', 'period', 'período', 'período da fatura'],
  document: ['document', 'documents', 'download', 'documento', 'documentos'],
  amount: ['amount', 'total', 'invoice amount', 'valor'],
  status: ['status', 'payment status', 'estado'],
};
const classify = value => Object.entries(columnNames).find(([, names]) => names.includes(normalize(value)))?.[0] || 'unknown';
const dateShape = value => {
  for (const format of ['YYYY-MM-DD', 'DD/MM/YYYY', 'D MMM YYYY']) {
    try { return { format, iso: invoiceDate(String(value || '').trim(), format) }; } catch {}
  }
  return null;
};

// Values needed for a read-only check stay in memory. Only categorical structure,
// allowlisted paths and a PDF signature result enter the private diagnostic.
export async function inspectBookingInvoices(page, input) {
  const url = portalUrl('booking', page.url());
  const result = { validated: false, property_verified: url.hostname === 'admin.booking.com'
    && url.searchParams.get('hotel_id') === String(input.property), tables: [], sample_pdf: 'not_checked' };
  if (!result.property_verified) return result;
  if (typeof page.waitForFunction === 'function') await page.waitForFunction(() =>
    [...document.querySelectorAll('table')].some(el => el.getClientRects().length > 0),
  { timeout: 15000 }).catch(() => {});
  const raw = await page.evaluate(() => {
    const visible = el => el.getClientRects().length > 0;
    return {
      tables: [...document.querySelectorAll('table')].filter(visible).slice(0, 5).map(table => ({
        headers: [...table.querySelectorAll('thead th,thead td')].slice(0, 20).map(el => el.innerText),
        rows: [...table.querySelectorAll('tbody tr')].filter(visible).slice(0, 100).map(row => ({
          cells: [...row.querySelectorAll('td')].slice(0, 20).map(el => el.innerText),
          document: [...row.querySelectorAll('a[href]')].map(el => el.href).find(href => {
            try { const u = new URL(href); return u.origin === location.origin
              && u.pathname === '/fresa/extranet/finance/invoices/get_document'; } catch { return false; }
          }),
        })),
      })),
      pagination: [...document.querySelectorAll('button,a,[role="button"]')].filter(visible).map(el => ({
        label: (el.getAttribute('aria-label') || el.innerText || '').trim().replace(/\s+/g, ' ').toLowerCase(),
        disabled: el.disabled === true || el.getAttribute('aria-disabled') === 'true',
      })).filter(item => ['next', 'next page', 'go to next page', 'seguinte', 'página seguinte'].includes(item.label)),
    };
  });
  let sample = null;
  for (const table of Array.isArray(raw?.tables) ? raw.tables.slice(0, 5) : []) {
    const headers = (Array.isArray(table.headers) ? table.headers.slice(0, 20) : []).map(classify);
    const rows = Array.isArray(table.rows) ? table.rows.slice(0, 100) : [];
    const issueIndexes = headers.flatMap((kind, index) => kind === 'issue_date' ? [index] : []);
    const summary = { columns: headers.map((kind, index) => ({ index: index + 1, kind })),
      date_columns: [], issue_date_unambiguous: issueIndexes.length === 1 };
    const formats = new Set();
    for (const row of rows) {
      const cells = Array.isArray(row.cells) ? row.cells.slice(0, 20) : [];
      for (let index = 0; index < cells.length; index++) {
        const date = dateShape(cells[index]);
        if (date) formats.add(`${index + 1}:${date.format}`);
      }
      const issueDate = issueIndexes.length === 1 ? dateShape(cells[issueIndexes[0]]) : null;
      const matches = issueDate?.iso.slice(0, 7) === input.period;
      try {
        const document = portalUrl('booking', row.document);
        if (document.origin !== url.origin || document.pathname !== '/fresa/extranet/finance/invoices/get_document'
          || document.searchParams.get('hotel_id') !== String(input.property)) continue;
        if (!sample || matches && !sample.matches) sample = { url: document.href, matches };
      } catch { /* Unexpected links never become download candidates. */ }
    }
    summary.date_columns = [...formats].map(key => {
      const [index, format] = key.split(':'); return { index: Number(index), format };
    });
    result.tables.push(summary);
  }
  result.pagination = { next_controls: Array.isArray(raw?.pagination) ? Math.min(raw.pagination.length, 20) : 0,
    next_enabled: Array.isArray(raw?.pagination) && raw.pagination.some(p => p.disabled === false), tested: false };
  result.location = publicLocation('booking', page.url());
  if (!sample) { result.sample_pdf = 'no_link'; return result; }
  result.sample_matches_issue_month = sample.matches === true;
  try {
    const check = await page.evaluate(async href => {
      const response = await fetch(href, { credentials: 'same-origin', redirect: 'error', signal: AbortSignal.timeout(30000) });
      if (!response.ok || Number(response.headers.get('content-length')) > 20 * 1024 * 1024) return false;
      const reader = response.body.getReader(); const prefix = [];
      try {
        while (prefix.length < 5) {
          const { value, done } = await reader.read(); if (done) break;
          for (const byte of value) { if (prefix.length >= 5) break; prefix.push(byte); }
        }
        return String.fromCharCode(...prefix) === '%PDF-';
      } finally { await reader.cancel().catch(() => {}); }
    }, sample.url);
    result.sample_pdf = check === true ? 'verified_pdf_signature' : 'not_pdf';
  } catch { result.sample_pdf = 'failed'; }
  return result;
}
