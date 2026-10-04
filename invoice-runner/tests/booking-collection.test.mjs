import test from 'node:test';
import assert from 'node:assert/strict';
import { bookingStructure, collectBookingInvoices, validateBookingCollectionMap } from '../booking-collection.mjs';
import { pdfContent } from '../portal.mjs';

const input = { accountId: 1, portal: 'booking', property: '1140306', period: '2026-08', periodBasis: 'issue_month' };
const headers = ['Type', 'Number', 'Date', 'Period', 'Due date', 'Paid', 'Status', 'Amount'];
const url = number => `https://admin.booking.com/fresa/extranet/finance/invoices/get_document?hotel_id=1140306&doc=${number}&token=PRIVATE_TOKEN`;
const row = (number, date, link = url(number)) => ({ cells: ['Invoice', number, date, 'Jul 2026', '15 Sep 2026', '', 'Paid', 'PRIVATE_AMOUNT'], links: [link] });
const table = (rows, next = []) => ({ tables: 1, headers, rows, pagination_present: !!next.length, next });
function pageFor(pages, repeat = false) {
  let index = 0; const downloads = [], clicks = [];
  return { downloads, clicks, url: () => 'https://admin.booking.com/hotel/invoices.html?hotel_id=1140306',
    waitForFunction: async () => {}, waitForNavigation: async () => {},
    click: async selector => { clicks.push(selector); if (!repeat) index++; },
    evaluate: async (_fn, href) => {
      if (typeof href === 'string') { downloads.push(href); return Buffer.from('%PDF-1.7 fixture\n%%EOF').toString('base64'); }
      return pages[index];
    },
  };
}
const approved = () => ({ ...bookingStructure(headers), verified: true });
test('all pages are read and only issue-month rows download, regardless of service and due dates', async () => {
  const page = pageFor([
    table([row('JUL', '31 Jul 2026'), row('AUG1', '1 Aug 2026')], [{ selector: 'button.next', disabled: false }]),
    table([row('AUG2', '31 Aug 2026'), row('SEP', '1 Sep 2026')], [{ selector: 'button.next', disabled: true }]),
  ]);
  const result = await collectBookingInvoices(page, input);
  assert.deepEqual(result.documents.map(d => d.number), ['AUG1', 'AUG2']);
  assert.deepEqual(result.documents.map(d => d.issued_on), ['2026-08-01', '2026-08-31']);
  assert.deepEqual(page.downloads, [url('AUG1'), url('AUG2')]);
  assert.deepEqual(page.clicks, ['button.next']);
  assert.equal(result.verification.pagination_verified, true);
  assert.equal(result.verification.pagination_mode, 'next_page');
  assert.equal(result.verification.validated, false);
  for (const privateValue of ['PRIVATE_TOKEN','PRIVATE_AMOUNT','AUG1','2026-08-01'])
    assert.equal(JSON.stringify(result.verification).includes(privateValue), false);
});
test('an unrecognized paginator and repeated page cannot produce a complete result', async () => {
  await assert.rejects(collectBookingInvoices(pageFor([{ ...table([row('A', '1 Aug 2026')]), pagination_present: true }]), input), /portal_changed/);
  const page = pageFor([table([row('A', '1 Aug 2026')], [{ selector: 'button.next', disabled: false }])], true);
  await assert.rejects(collectBookingInvoices(page, input), /portal_changed/);
  assert.equal(page.downloads.length, 1);
});
test('wrong property, foreign origin and foreign document path stop before a download', async () => {
  for (const link of [url('A').replace('1140306','539828'), url('A').replace('admin.booking.com','auth.booking.com'), url('A').replace('get_document','different')]) {
    const page = pageFor([table([row('A','1 Aug 2026',link)])]);
    await assert.rejects(collectBookingInvoices(page,input), /account_mismatch/);
    assert.deepEqual(page.downloads,[]);
  }
});
test('a changed table and ambiguous date columns stop collection', async () => {
  const page = pageFor([{ ...table([row('A','1 Aug 2026')]), headers: headers.map(h=>h==='Amount'?'Balance':h) }]);
  await assert.rejects(collectBookingInvoices(page,input,approved()),/portal_changed/);
  assert.deepEqual(page.downloads,[]);
  assert.throws(()=>bookingStructure(['Number','Date','Issue date']),/portal_changed/);
});
test('verification requires a real month sample; an approved map permits a verified empty month', async () => {
  const pages=[table([row('SEP','1 Sep 2026')])];
  await assert.rejects(collectBookingInvoices(pageFor(pages),input),/verification_sample_missing/);
  const result=await collectBookingInvoices(pageFor(pages),input,approved());
  assert.deepEqual(result.documents,[]);
  assert.equal(result.verification.pagination_mode,'single_page');
});
test('load-more overlap downloads each invoice only once', async () => {
  const page=pageFor([table([row('A','1 Aug 2026')],[{selector:'button.more',disabled:false,load_more:true}]),
    table([row('A','1 Aug 2026'),row('B','2 Aug 2026')])]);
  const result=await collectBookingInvoices(page,input);
  assert.deepEqual(result.documents.map(d=>d.number),['A','B']);
  assert.equal(result.verification.pagination_mode,'load_more');
});
test('map approval is bound to account, portal, property and issue-month semantics', () => {
  const map={version:3,validated:true,strategy:'booking-finance-v1',portal:'booking',accountId:1,properties:{'1140306':approved()}};
  assert.deepEqual(validateBookingCollectionMap(map,input),approved());
  for (const changed of [{...map,validated:false},{...map,accountId:2},{...map,portal:'other'},{...map,properties:{}}])
    assert.throws(()=>validateBookingCollectionMap(changed,input),/connector_unconfigured/);
  assert.throws(()=>validateBookingCollectionMap(map,{...input,periodBasis:'service_month'}),/connector_unconfigured/);
});

test('full-download trace identifies rejected responses without retaining their body', async () => {
  const original = globalThis.fetch;
  const page = { url: () => 'https://admin.booking.com/invoices', evaluate: (fn, ...args) => fn(...args) };
  try {
    for (const [status, body, signature] of [[200, '%PDF-1.7 fixture\n%%EOF', true], [200, 'PRIVATE_ERROR_BODY', false], [403, 'PRIVATE_ERROR_BODY', false]]) {
      globalThis.fetch = async () => new Response(body, { status });
      let trace;
      const content = await pdfContent(page, url('fixture'), 'booking', value => { trace = value; });
      assert.equal(trace.status, status); assert.equal(trace.signature, signature);
      assert.equal(content, signature ? Buffer.from(body).toString('base64') : null);
      assert.equal(JSON.stringify(trace).includes(body), false);
      assert.equal(trace.complete, status === 200);
    }
    globalThis.fetch = async () => new Response(new Uint8Array(20 * 1024 * 1024 + 1));
    let oversized;
    assert.equal(await pdfContent(page, url('fixture'), 'booking', value => { oversized = value; }), null);
    assert.equal(oversized.complete, false);
    assert.equal(oversized.bytes, 20 * 1024 * 1024 + 1);
  } finally { globalThis.fetch = original; }
});
