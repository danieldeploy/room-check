import test from 'node:test';
import assert from 'node:assert/strict';
import { navigateBookingInvoices } from '../booking-discovery.mjs';

for (const mode of ['recover', 'ambiguous', 'hidden', 'changed_page', 'timeout']) {
  test(`a non-clickable Finance control uses a fresh native keyboard target only when safe: ${mode}`, async () => {
    let lookups = 0, opened = false, pressed = 0, changed = false;
    const clickError = new Error(mode === 'timeout' ? 'Protocol timeout' : 'Node is either not clickable or not an Element');
    const first = { evaluate: async () => ({ visible: true, label: 'finance' }),
      click: async () => { changed = mode === 'changed_page'; throw clickError; },
      press: async () => { throw new Error('must re-resolve the control'); } };
    const fresh = { evaluate: async (_fn, names) => names
      ? { visible: true, label: 'finance' } : mode !== 'hidden',
      press: async key => { assert.equal(key, 'Enter'); pressed++; opened = true; } };
    const invoices = { evaluate: async () => ({ visible: true, label: 'invoices' }), click: async () => {} };
    const page = {
      url: () => `https://admin.booking.com/hotel/home?hotel_id=${changed ? '539828' : '1140306'}`,
      waitForNavigation: async () => {},
      $$: async () => opened ? [invoices] : ++lookups === 1 ? [first]
        : mode === 'ambiguous' ? [fresh, fresh] : [fresh],
    };
    const run = () => navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'One' }, async () => {});
    if (mode === 'recover') assert.equal(await run(), 'invoices_visible');
    else await assert.rejects(run, error => error === clickError);
    assert.equal(pressed, mode === 'recover' ? 1 : 0);
  });
}

test('public entry cannot lead to Finance while the wrong property remains selected', async () => {
  const visits = [];
  const page = { url: () => 'https://admin.booking.com/manage/home.html?hotel_id=539828',
    $$: async () => [], goto: async url => { visits.push(url); } };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'One' }, async () => {}),
    'property_navigation');
  assert.deepEqual(visits, ['https://admin.booking.com/']);
});

for (const hasGroupLink of [true, false]) test(`switching properties uses ${hasGroupLink ? 'the observed group link' : 'the public entry'} before the exact property link`, async () => {
  let step = 0;
  const group = 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html?observed=fixture';
  const target = 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=539828';
  const entry = { evaluate: async () => target, click: async () => { step = 2; } };
  const row = { evaluate: async () => true, $$: async () => [entry] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = { url: () => step === 0 ? target.replace('539828', '1140306') : step === 1 ? group : target,
    goto: async href => {
      assert.equal(href, step === 0 ? (hasGroupLink ? group : 'https://admin.booking.com/') : target);
      step = step === 0 ? 1 : 2;
    }, waitForNavigation: async () => {},
    $$: async selector => step === 0 ? (hasGroupLink ? [{ evaluate: async () => group }] : [])
      : selector === 'tr,[role="row"]' ? [row] : step === 2 ? [control('finance', 3)] : [control('invoices', 4)] };
  const stages = [];
  assert.equal(await navigateBookingInvoices(page, { property: '539828', propertyLabel: 'Two' },
    async stage => stages.push(stage)), 'invoices_visible');
  assert.deepEqual(stages, ['group', 'property', 'finance', 'invoices']);
});

test('ambiguous group entries are never resolved by choosing one or returning to the public entry', async () => {
  const page = { url: () => 'https://admin.booking.com/manage/home.html?hotel_id=1140306',
    $$: async () => ['one', 'two'].map(id => ({ evaluate: async () =>
      `https://admin.booking.com/hotel/hoteladmin/groups/home/index.html?fixture=${id}` })),
    goto: async () => { throw new Error('must not navigate'); } };
  assert.equal(await navigateBookingInvoices(page, { property: '539828', propertyLabel: 'Two' }, async () => {}),
    'property_switch_ambiguous');
});

test('the observed index entry stays in the controlled tab despite other row links and new-tab clicks', async () => {
  let step = 0;
  const index = 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/index.html?hotel_id=539828';
  const entry = { evaluate: async () => index,
    click: async () => { throw new Error('a new-tab link must be followed in the controlled page'); } };
  const messaging = { evaluate: async () => index.replace('index.html', 'messaging/inbox.html'),
    click: async () => { throw new Error('must not enter messages'); } };
  const wrongProperty = { evaluate: async () => index.replace('539828', '1140306'),
    click: async () => { throw new Error('must not enter another property'); } };
  const row = { evaluate: async () => true, $$: async () => [messaging, entry, entry, wrongProperty] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = { url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html' : index,
    goto: async href => { assert.equal(href, index); step = 1; },
    waitForNavigation: async () => {},
    $$: async selector => selector === 'tr,[role="row"]' ? [row]
      : step === 1 ? [control('finance', 2)] : [control('invoices', 3)] };
  assert.equal(await navigateBookingInvoices(page, { property: '539828', propertyLabel: 'Two' },
    async () => {}), 'invoices_visible');
});

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
    goto: async href => { assert.equal(href, await links[0].evaluate()); step = 1; },
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
    goto: async href => { assert.equal(href, await propertyLink.evaluate()); step = 1; },
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
    goto: async href => { assert.equal(href, await link().evaluate()); step = 1; },
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
    goto: async href => { assert.equal(href, await link.evaluate()); step = 1; },
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

test('Booking discovery follows a unique named control in its matched row', async () => {
  let step = 0;
  const propertyButton = { evaluate: async () => ({ visible: true, label: 'welcome guest house', href: null }),
    click: async () => { step = 1; } };
  const row = { evaluate: async () => true,
    [String.fromCharCode(36, 36)]: async selector => selector === 'a[href]' ? [] : [propertyButton] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = {
    url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html'
      : 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    [String.fromCharCode(36, 36)]: async selector => selector === 'tr,[role="row"]' ? [row]
      : step === 1 ? [control('finance', 2)] : step === 2 ? [control('invoices', 3)] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    async () => {}), 'invoices_visible');
});

for (const entryFile of ['home.html', 'index.html']) test(`Booking discovery accepts a unique observed ${entryFile} URL for the matched property`, async () => {
  let step = 0;
  const url = `https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/${entryFile}?hotel_id=1140306`;
  const row = { evaluate: async () => true, [String.fromCharCode(36, 36)]: async () => [] };
  const control = (label, next) => ({ evaluate: async () => ({ visible: true, label, href: null }),
    click: async () => { step = next; } });
  const page = {
    url: () => step === 0 ? 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html' : url,
    goto: async destination => { assert.equal(destination, url); step = 1; },
    waitForNavigation: async () => {},
    [String.fromCharCode(36, 36)]: async selector => selector === 'tr,[role="row"]' ? [row]
      : step === 1 ? [control('finance', 2)] : step === 2 ? [control('invoices', 3)] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House',
    propertyEntryUrls: [url, url] }, async () => {}), 'invoices_visible');
});

test('Booking discovery rejects observed URL for another property', async () => {
  const row = { evaluate: async () => true, [String.fromCharCode(36, 36)]: async () => [] };
  const page = { url: () => 'https://admin.booking.com/hotel/hoteladmin/groups/home/index.html',
    goto: async () => { throw new Error('Unexpected navigation'); },
    [String.fromCharCode(36, 36)]: async selector => selector === 'tr,[role="row"]' ? [row] : [] };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House',
    propertyEntryUrls: ['https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=539828'] },
  async () => {}), 'property_link_missing');
});

test('Booking discovery uses visible navigation text instead of hidden submenu text', async () => {
  let step = 0;
  const control = (visibleLabel, next) => ({
    evaluate: async fn => fn({ getClientRects: () => [1], disabled: false,
      innerText: visibleLabel, textContent: visibleLabel + ' hidden submenu',
      getAttribute: () => null, tagName: 'BUTTON' }),
    click: async () => { step = next; },
  });
  const page = {
    url: () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    [String.fromCharCode(36, 36)]: async () => step === 0 ? [control('Finance', 1)]
      : step === 1 ? [control('Invoices', 2)] : [],
  };
  assert.equal(await navigateBookingInvoices(page, { property: '1140306', propertyLabel: 'Welcome Guest House' },
    async () => {}), 'invoices_visible');
});

async function overflowScenario(parentCount = 1, sharedContainer = false) {
  let open = false, invoices = false;
  const clicks = [];
  const node = (label, visible) => ({ tagName: 'BUTTON', innerText: label, textContent: label,
    getAttribute: () => null, getClientRects: () => visible() ? [1] : [], disabled: false });
  const financeNode = node('Finance', () => open);
  const finance = { evaluate: async fn => fn(financeNode), click: async () => {
    assert.equal(open, true, 'the hidden Finance item cannot be clicked');
    clicks.push('finance'); invoices = true;
  } };
  const invoiceNode = node('Invoices', () => invoices);
  const invoice = { evaluate: async fn => fn(invoiceNode), click: async () => clicks.push('invoices') };
  const topNodes = Array.from({ length: parentCount }, () => node('More', () => true));
  const parents = topNodes.map(top => {
    top.parentElement = { parentElement: null, querySelectorAll: selector =>
      selector === '.ext-navigation-top-item__link' ? (sharedContainer ? topNodes : [top]) : [financeNode] };
    return { evaluate: async (fn, arg) => fn(top, arg), click: async () => { clicks.push('more'); open = true; } };
  });
  const page = {
    url: () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    $$: async selector => selector === '.ext-navigation-top-item__link' ? parents
      : invoices ? [invoice] : [...parents, finance],
  };
  const result = await navigateBookingInvoices(page,
    { property: '1140306', propertyLabel: 'Welcome Guest House' }, async () => {});
  return { result, clicks };
}

test('Booking discovery opens the unique visible parent of hidden Finance', async () => {
  assert.deepEqual(await overflowScenario(),
    { result: 'invoices_visible', clicks: ['more', 'finance', 'invoices'] });
});

test('Booking discovery does not choose between ambiguous overflow menus', async () => {
  assert.deepEqual(await overflowScenario(2), { result: 'finance_missing', clicks: [] });
});

test('Booking discovery does not mistake the whole navigation bar for a menu group', async () => {
  assert.deepEqual(await overflowScenario(2, true), { result: 'finance_missing', clicks: [] });
});

async function menuBadgeScenario({ hidden = false, nested = false } = {}) {
  let step = 0;
  const clicks = [];
  const financeNode = { tagName: 'BUTTON', innerText: 'Finance\nNew', textContent: 'FinanceNew',
    getClientRects: () => [1], disabled: false, getAttribute: () => null,
    matches: () => true };
  financeNode.querySelectorAll = () => [{ children: [], innerText: 'Finance',
    getClientRects: () => hidden ? [] : [1], closest: () => nested ? {} : financeNode }];
  const finance = { evaluate: async (fn, arg) => fn(financeNode, arg),
    click: async () => { clicks.push('finance'); step = 1; } };
  const invoices = { evaluate: async () => ({ visible: true, label: 'invoices and documents', href: null }),
    click: async () => clicks.push('invoices') };
  const page = {
    url: () => 'https://admin.booking.com/hotel/hoteladmin/extranet_ng/manage/home.html?hotel_id=1140306',
    waitForNavigation: async () => {},
    $$: async selector => selector === '.ext-navigation-top-item__link' ? [] : step ? [invoices] : [finance],
  };
  const result = await navigateBookingInvoices(page,
    { property: '1140306', propertyLabel: 'Welcome Guest House' }, async () => {});
  return { result, clicks };
}

test('Booking menu labels remain identifiable beside a notification badge', async () => {
  assert.deepEqual(await menuBadgeScenario(), { result: 'invoices_visible', clicks: ['finance', 'invoices'] });
});

test('Booking menu label matching ignores hidden text and another interactive control', async () => {
  for (const options of [{ hidden: true }, { nested: true }])
    assert.deepEqual(await menuBadgeScenario(options), { result: 'finance_missing', clicks: [] });
});
