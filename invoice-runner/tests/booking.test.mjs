import test from 'node:test';
import assert from 'node:assert/strict';
import { bookingUrl, invoiceDate, validateMap, authenticate, collect } from '../booking.mjs';

const target = { url: 'https://admin.booking.com/fixture?hotel_id=1140306', rows: '.row', number: '.number', date: '.date', pdf: '.pdf', empty: '.empty', propertyMarker: '.property', dateFormat: 'YYYY-MM-DD' };
const login = { url: 'https://account.booking.com/', identifier: '#user', password: '#password', submit: '#submit', challenge: '.challenge', authenticated: '.authenticated' };
const map = { version: 1, validated: true, login, properties: { '1140306': target } };

test('only HTTPS Booking destinations and the correct property are accepted', () => {
  assert.equal(bookingUrl(target.url).hostname, 'admin.booking.com');
  for (const url of ['http://admin.booking.com/', 'https://booking.com.evil.example/', 'https://127.0.0.1/', 'https://user:secret@booking.com/', 'https://booking.com:8080/']) assert.throws(() => bookingUrl(url));
  assert.equal(validateMap(map, '1140306').target, target);
  assert.throws(() => validateMap({ ...map, validated: false }, '1140306'));
  assert.throws(() => validateMap(map, '539828'));
});

test('invalid or ambiguous dates are rejected', () => {
  assert.equal(invoiceDate('29/02/2024', 'DD/MM/YYYY'), '2024-02-29');
  assert.equal(invoiceDate('2026-09-18', 'YYYY-MM-DD'), '2026-09-18');
  for (const value of ['2026-02-30', '09/18/2026', 'September 18']) assert.throws(() => invoiceDate(value, 'YYYY-MM-DD'));
});

test('English invoice dates observed in the portal retain strict calendar validation', () => {
  assert.equal(invoiceDate('3 Sept 2026', 'D MMM YYYY'), '2026-09-03');
  assert.equal(invoiceDate('3 Aug 2026', 'D MMM YYYY'), '2026-08-03');
  assert.equal(invoiceDate('29 Feb 2024', 'D MMM YYYY'), '2024-02-29');
  for (const value of ['29 Feb 2026', '31 Apr 2026', '3 Setembro 2026', '3 Aug 26', '0 Aug 2026']) {
    assert.throws(() => invoiceDate(value, 'D MMM YYYY'));
  }
  assert.equal(validateMap({ ...map, properties: { '1140306': { ...target, dateFormat: 'D MMM YYYY' } } }, '1140306').target.dateFormat, 'D MMM YYYY');
});

test('challenge stops login before any credential is typed', async () => {
  let typed = 0;
  const page = { goto: async () => {}, url: () => login.url, $eval: async selector => selector === '.challenge', type: async () => { typed++; } };
  await assert.rejects(authenticate(page, login, { identifier: 'fixture', password: 'fixture' }), /needs_auth/);
  assert.equal(typed, 0);
});

function fixturePage(rows, property = '1140306', empty = false) {
  return {
    goto: async () => {}, url: () => target.url, waitForSelector: async () => {},
    $eval: async selector => selector === '.property' ? `Property ${property}` : selector === '.empty' ? empty : false,
    $$eval: async () => rows,
    evaluate: async () => Buffer.from('%PDF-1.7\nfixture').toString('base64'),
  };
}

test('collection filters by issue month and deduplicates repeated rows', async () => {
  const row = { number: 'INV1', date: '2026-08-03', url: 'https://admin.booking.com/fixture.pdf' };
  const documents = await collect(fixturePage([row, row, { ...row, number: 'INV2', date: '2026-07-01' }]), login, target, '1140306', '2026-08');
  assert.equal(documents.length, 1);
  assert.equal(documents[0].number, 'INV1');
});

test('wrong property, foreign download and missing table do not become success', async () => {
  await assert.rejects(collect(fixturePage([], '539828'), login, target, '1140306', '2026-08'), /portal_changed/);
  await assert.rejects(collect(fixturePage([]), login, target, '1140306', '2026-08'), /portal_changed/);
  await assert.rejects(collect(fixturePage([{ number: 'A', date: '2026-08-01', url: 'https://evil.example/invoice' }]), login, target, '1140306', '2026-08'), /connector_unconfigured/);
  assert.deepEqual(await collect(fixturePage([], '1140306', true), login, target, '1140306', '2026-08'), []);
});

test('pagination loops fail instead of reporting a complete collection', async () => {
  const page = fixturePage([{ number: 'A', date: '2026-08-01', url: 'https://admin.booking.com/fixture.pdf' }]);
  page.$eval = async (selector, fn) => selector === '.property' ? '1140306' : selector === '.next' ? !String(fn).includes('disabled') : false;
  page.click = async () => {};
  page.waitForNavigation = async () => {};
  await assert.rejects(collect(page, login, { ...target, nextPage: '.next' }, '1140306', '2026-08'), /portal_changed/);
});
