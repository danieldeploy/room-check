import test from 'node:test';
import assert from 'node:assert/strict';
import { navigateBookingInvoices } from '../booking-discovery.mjs';

test('Booking discovery stops before clicking an ambiguous property', async () => {
  let clicks = 0;
  const row = { evaluate: async () => true };
  row[String.fromCharCode(36, 36)] = async () => [];
  const page = {
    url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/',
    $$: async selector => selector === 'tr,[role="row"]' ? [row, row] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    () => { clicks++; }), 'property_ambiguous');
  assert.equal(clicks, 0);
});

test('Booking discovery follows a unique property, Finance and Invoices control', async () => {
  let step = 0;
  const links = [{ evaluate: async () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    click: async () => { step = 1; } }];
  const row = { evaluate: async () => true, $$: async () => links };
  const control = (label, next) => ({
    evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; },
  });
  const page = {
    url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/'
      : 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    $$: async selector => selector === 'tr,[role="row"]' ? [row] : step === 1 ? [control('finance', 2)]
      : step === 2 ? [control('invoices', 3)] : [],
  };
  const stages = [];
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    async stage => { stages.push(stage); }), 'invoices_visible');
  assert.deepEqual(stages, ['property', 'finance', 'invoices']);
});

test('Booking discovery can select the unique property ID without a matching label', async () => {
  let step = 0;
  const propertyLink = { evaluate: async () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    click: async () => { step = 1; } };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = {
    url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/'
      : 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    $$: async selector => selector === 'tr,[role="row"]' ? []
      : selector === 'a[href]' ? [propertyLink]
      : step === 1 ? [control('finance', 2)] : step === 2 ? [control('invoices', 3)] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    async () => {}), 'invoices_visible');
});

test('Booking discovery ignores duplicate links to the same property entry', async () => {
  let step = 0;
  const link = () => ({ evaluate: async () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    click: async () => { step = 1; } });
  const row = { evaluate: async () => true, [String.fromCharCode(36, 36)]: async () => [link(), link()] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = {
    url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/'
      : 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    [String.fromCharCode(36, 36)]: async selector => selector === 'tr,[role="row"]' ? [row]
      : step === 1 ? [control('finance', 2)] : step === 2 ? [control('invoices', 3)] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    async () => {}), 'invoices_visible');
});

test('Booking discovery waits for asynchronously populated group rows', async () => {
  let loaded = false, step = 0;
  const link = { evaluate: async () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    click: async () => { step = 1; } };
  const row = { evaluate: async () => true, [String.fromCharCode(36, 36)]: async () => [link] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = {
    url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html'
      : 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    waitForFunction: async (_fn, options, values) => {
      assert.equal(options.timeout, 15000);
      assert.equal(values.property, '1140306');
      loaded = true;
    },
    [String.fromCharCode(36, 36)]: async selector => selector === 'tr,[role="row"]' ? (loaded ? [row] : [])
      : step === 1 ? [control('finance', 2)] : step === 2 ? [control('invoices', 3)] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    async () => {}), 'invoices_visible');
});
