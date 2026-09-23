import { portalUrl } from './portal.mjs';

const labels = {
  finance: ['finance', 'finanças', 'financial'],
  invoices: ['invoices', 'faturas', 'invoices and payments', 'invoices and documents'],
};
const pause = ms => new Promise(resolve => setTimeout(resolve, ms));
async function clickAndSettle(page, element) {
  const navigation = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 6000 }).catch(() => {});
  await element.click();
  await navigation;
  await pause(600);
}
async function uniqueControl(page, names) {
  const controls = await page.$$('a,button,[role="button"]');
  const matches = [];
  for (const control of controls) {
    const info = await control.evaluate(el => ({
      visible: el.getClientRects().length > 0 && !el.disabled,
      label: (el.textContent || '').trim().replace(/\s+/g, ' ').toLowerCase(),
      href: el.tagName === 'A' ? el.href : null,
    }));
    if (info.visible && names.includes(info.label)) matches.push({ control, info });
  }
  return matches.length === 1 ? matches[0] : null;
}
export async function navigateBookingInvoices(page, input, onStep) {
  const property = String(input.property || '');
  const label = String(input.propertyLabel || '').trim();
  if (!/^\d{1,12}$/.test(property) || !label || label.length > 120) return 'property_missing';
  let url = portalUrl('booking', page.url());
  if (url.hostname === 'admin.booking.com' && url.pathname.includes('/groups/home')) {
    const rows = await page.$$('tr,[role="row"]');
    const matches = [];
    for (const row of rows) {
      const related = await row.evaluate((el, values) => el.getClientRects().length > 0
        && ((el.textContent || '').toLowerCase().includes(values.label.toLowerCase())
          || (el.textContent || '').includes(values.property)
          || el.getAttribute('data-hotel-id') === values.property),
        { label, property });
      if (related) matches.push(row);
    }
    const allowed = [];
    for (const row of matches) {
      for (const link of await row.$$('a[href]')) {
        const href = await link.evaluate(el => el.href);
        try { portalUrl('booking', href); allowed.push({ link, href }); } catch { /* reject foreign destination */ }
      }
    }
    if (!allowed.length) {
      for (const link of await page.$$('a[href]')) {
        const href = await link.evaluate(el => el.href);
        try { portalUrl('booking', href); if (href.includes(property)) allowed.push({ link, href }); }
        catch { /* reject foreign destination */ }
      }
    }
    const entry = allowed.filter(item => {
      const u = new URL(item.href);
      return u.hostname === 'admin.booking.com' && u.pathname === '/hotel/hoteladmin/extranet_ng/manage/home.html'
        && u.searchParams.get('hotel_id') === property;
    });
    const exact = [...new Map(entry.map(item => [item.href, item])).values()];
    const target = exact.length === 1 ? exact[0]
      : matches.length === 1 && allowed.length === 1 ? allowed[0] : null;
    if (!target) return matches.length === 0 && exact.length === 0 ? 'property_not_found'
      : matches.length > 1 || exact.length > 1 ? 'property_ambiguous' : 'property_link_missing';
    await clickAndSettle(page, target.link);
    await onStep('property');
  }
  url = portalUrl('booking', page.url());
  if (url.hostname !== 'admin.booking.com') return 'property_navigation';
  const finance = await uniqueControl(page, labels.finance);
  if (!finance) return 'finance_missing';
  if (finance.info.href) portalUrl('booking', finance.info.href);
  await clickAndSettle(page, finance.control);
  await onStep('finance');
  const invoices = await uniqueControl(page, labels.invoices);
  if (!invoices) return 'invoices_missing';
  if (invoices.info.href) portalUrl('booking', invoices.info.href);
  await clickAndSettle(page, invoices.control);
  await onStep('invoices');
  return 'invoices_visible';
}
