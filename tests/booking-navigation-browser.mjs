import assert from 'node:assert/strict';
import { navigateBookingInvoices } from '../invoice-runner/booking-discovery.mjs';

// Reproduce a rendered menu outside Chrome's clickable viewport. All requests
// are answered locally; no Booking account or live portal is used in this test.
export async function testBookingNavigation(browser) {
  const page = await browser.newPage();
  await page.setRequestInterception(true);
  page.on('request', request => {
    const url = new URL(request.url());
    const property = url.searchParams.get('hotel_id');
    const body = url.pathname.endsWith('invoices.html') ? '<h1>Fixture invoices</h1>' : `
      <style>button,a { position:fixed; left:200vw; top:0; }</style>
      <button onclick="document.querySelector('a').hidden=false">Finance</button>
      <a hidden href="https://admin.booking.com/hotel/invoices.html?hotel_id=${property}">Invoices</a>`;
    void request.respond({ status: 200, contentType: 'text/html', body });
  });
  try {
    for (let run = 0; run < 2; run++) for (const property of ['1140306', '539828']) {
      await page.goto(`https://admin.booking.com/hotel/home.html?hotel_id=${property}`);
      const phases = [];
      const result = await navigateBookingInvoices(page, { property, propertyLabel: 'Fixture property' },
        async () => {}, { onPhase: phase => phases.push(phase) });
      assert.equal(result, 'invoices_visible');
      assert.equal(new URL(page.url()).searchParams.get('hotel_id'), property);
      assert.match(new URL(page.url()).pathname, /invoices\.html$/);
      assert.ok(phases.includes('finance_keyboard'), 'the fixture must exercise the real non-clickable error');
      assert.ok(phases.includes('invoices_keyboard'));
      assert.equal(await page.$eval('h1', el => el.textContent), 'Fixture invoices');
    }
  } finally { await page.close(); }
}
