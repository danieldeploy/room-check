import test, { mock } from 'node:test';
import assert from 'node:assert/strict';
import { inspectBookingInvoices } from '../booking-invoice-inspection.mjs';

const input = { property: '1140306', period: '2026-08' };
const location = 'https://admin.booking.com/manage/invoices.html?hotel_id=1140306&ses=fixture-secret';
const pdf = (property = '1140306', token = 'fixture-pdf-secret') =>
  `https://admin.booking.com/fresa/extranet/finance/invoices/get_document?hotel_id=${property}&token=${token}`;
const row = (date, href = pdf()) => ({ cells: ['PRIVATE-INVOICE-NUMBER', date, 'PRIVATE-AMOUNT'], document: href });
function pageFor(headers, rows, verified = true) {
  const fetched = [];
  return { fetched, url: () => location,
    evaluate: async (_fn, href) => {
      if (href) { fetched.push(href); return verified; }
      return { tables: [{ headers, rows }], pagination: [{ label: 'next', disabled: true }] };
    } };
}
test('invoice evidence selects an issue-month sample and never serializes source values', async () => {
  const page = pageFor(['Invoice number', 'Issue date', 'Amount'],
    [row('2026-09-01', pdf('1140306', 'other-month')), row('2026-08-01')]);
  const result = await inspectBookingInvoices(page, input);
  assert.equal(result.validated, false);
  assert.equal(result.property_verified, true);
  assert.equal(result.sample_pdf, 'verified_pdf');
  assert.equal(result.sample_matches_issue_month, true);
  assert.equal(result.tables[0].matching_issue_month, 1);
  assert.deepEqual(result.tables[0].date_columns, [{ index: 2, format: 'YYYY-MM-DD', count: 2 }]);
  assert.deepEqual(page.fetched, [pdf()]);
  assert.equal(result.pagination.tested, false);
  for (const value of ['PRIVATE', 'fixture-secret', 'fixture-pdf-secret', '2026-08-01', '2026-09-01'])
    assert.ok(!JSON.stringify(result).includes(value), value);
});
test('generic or ambiguous date headers cannot establish an issue month', async () => {
  for (const headers of [['Invoice number', 'Date', 'Amount'], ['Issue date', 'Issue date', 'Amount']]) {
    const result = await inspectBookingInvoices(pageFor(headers, [row('01/08/2026')]), input);
    assert.equal(result.tables[0].issue_date_unambiguous, false);
    assert.equal(result.tables[0].matching_issue_month, 0);
    assert.equal(result.sample_matches_issue_month, false);
  }
});
test('a mismatched property never reads table values or retrieves a document', async () => {
  const result = await inspectBookingInvoices({ url: () => location.replace('1140306', '539828'),
    evaluate: async () => { throw new Error('must not read another property'); } }, input);
  assert.equal(result.property_verified, false);
  assert.equal(result.sample_pdf, 'not_checked');
});
test('foreign origins, other properties and unexpected document paths are rejected', async () => {
  const page = pageFor(['Number', 'Issue date'], [
    row('2026-08-01', pdf('539828')), row('2026-08-01', pdf().replace('admin.booking.com', 'auth.booking.com')),
    row('2026-08-01', pdf().replace('get_document', 'unobserved')), row('2026-08-01', 'https://evil.example/doc.pdf'),
  ]);
  const result = await inspectBookingInvoices(page, input);
  assert.equal(result.sample_pdf, 'no_link');
  assert.equal(result.tables[0].document_links, 0);
  assert.deepEqual(page.fetched, []);
});
test('non-PDF samples and retrieval failures stay unvalidated', async () => {
  const page = pageFor(['Invoice number', 'Issue date'], [row('2026-08-01')], false);
  assert.equal((await inspectBookingInvoices(page, input)).sample_pdf, 'not_pdf');
  const evaluate = page.evaluate;
  page.evaluate = async (fn, href) => { if (href) throw new Error('private transport detail'); return evaluate(fn, href); };
  const result = await inspectBookingInvoices(page, input);
  assert.equal(result.sample_pdf, 'failed');
  assert.equal(result.validated, false);
  assert.ok(!JSON.stringify(result).includes('private transport detail'));
});

test('the document probe reads a bounded response and checks its actual PDF signature', async () => {
  const page = pageFor(['Invoice number', 'Issue date'], [row('2026-08-01')]);
  const evaluate = page.evaluate;
  page.evaluate = async (fn, href) => href ? fn(href) : evaluate(fn);
  for (const [body, status] of [['%PDF-fixture', 'verified_pdf'], ['<html>login</html>', 'not_pdf'], ['', 'not_pdf']]) {
    const fetch = mock.method(globalThis, 'fetch', async (href, options) => {
      assert.equal(href, pdf());
      assert.equal(options.redirect, 'error');
      assert.equal(options.credentials, 'same-origin');
      return new Response(body);
    });
    try { assert.equal((await inspectBookingInvoices(page, input)).sample_pdf, status); }
    finally { fetch.mock.restore(); }
  }
  const oversized = mock.method(globalThis, 'fetch', async () => new Response('%PDF-fixture', {
    headers: { 'content-length': String(21 * 1024 * 1024) },
  }));
  try { assert.equal((await inspectBookingInvoices(page, input)).sample_pdf, 'not_pdf'); }
  finally { oversized.mock.restore(); }
});
