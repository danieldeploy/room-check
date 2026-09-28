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
async function waitForControl(page, names) {
  if (typeof page.waitForFunction !== 'function') return;
  await page.waitForFunction(labels => [...document.querySelectorAll('a,button,[role="button"]')].some(el => {
    if (!el.getClientRects().length || el.disabled) return false;
    const normalize = text => (text || '').trim().replace(/\s+/g, ' ').toLowerCase();
    if (labels.includes(normalize(el.innerText || el.getAttribute('aria-label') || el.textContent))) return true;
    if (!el.matches('.ext-navigation-top-item__link,.ext-navigation-submenu-item__link')) return false;
    return [...el.querySelectorAll('*')].some(child => child.children.length === 0
      && child.getClientRects().length > 0 && child.closest('a,button,[role="button"]') === el
      && labels.includes(normalize(child.innerText || child.textContent)));
  }),
  { timeout: 15000 }, names).catch(() => {});
}
async function uniqueControl(page, names) {
  const controls = await page.$$('a,button,[role="button"]');
  const matches = [];
  for (const control of controls) {
    const info = await control.evaluate((el, labels) => {
      const normalize = text => (text || '').trim().replace(/\s+/g, ' ').toLowerCase();
      const menuLabels = el.matches?.('.ext-navigation-top-item__link,.ext-navigation-submenu-item__link')
        ? [...el.querySelectorAll('*')].filter(child => child.children.length === 0
          && child.getClientRects().length > 0 && child.closest('a,button,[role="button"]') === el)
          .map(child => normalize(child.innerText || child.textContent)).filter(label => labels.includes(label)) : [];
      return {
        visible: el.getClientRects().length > 0 && !el.disabled,
        label: normalize(el.innerText || el.getAttribute('aria-label') || el.textContent),
        menuLabel: new Set(menuLabels).size === 1 ? menuLabels[0] : null,
        href: el.tagName === 'A' ? el.href : null,
      };
    }, names);
    if (info.visible && names.includes(info.menuLabel || info.label)) matches.push({ control, info });
  }
  return matches.length === 1 ? matches[0] : null;
}
async function revealNavigationGroup(page, names) {
  // At narrow widths Booking moves Finance under a top-level overflow menu.
  // Follow the observed DOM relationship; never click a hidden submenu item.
  const parents = [];
  for (const control of await page.$$('.ext-navigation-top-item__link')) {
    const related = await control.evaluate((el, labels) => {
      const visible = node => node.getClientRects().length > 0 && !node.disabled;
      if (el.tagName !== 'BUTTON' || !visible(el)) return false;
      let container = el.parentElement;
      for (let depth = 0; container && depth < 5; depth++, container = container.parentElement) {
        const triggers = [...container.querySelectorAll('.ext-navigation-top-item__link')].filter(visible);
        if (triggers.length !== 1 || triggers[0] !== el) break;
        const targets = [...container.querySelectorAll('.ext-navigation-submenu-item__link')]
          .filter(node => labels.includes((node.innerText || node.getAttribute('aria-label') || node.textContent || '')
            .trim().replace(/\s+/g, ' ').toLowerCase()));
        if (targets.length) return targets.length === 1 && !visible(targets[0]);
      }
      return false;
    }, names);
    if (related) parents.push(control);
  }
  if (parents.length !== 1) return false;
  await clickAndSettle(page, parents[0]);
  return true;
}
export async function navigateBookingInvoices(page, input, onStep) {
  const property = String(input.property || '');
  const label = String(input.propertyLabel || '').trim();
  if (!/^\d{1,12}$/.test(property) || !label || label.length > 120) return 'property_missing';
  let url = portalUrl('booking', page.url());
  if (url.hostname === 'admin.booking.com' && !url.pathname.includes('/groups/home')
      && url.searchParams.get('hotel_id') !== property) {
    const groups = new Map();
    for (const link of await page.$$('a[href]')) {
      const href = await link.evaluate(el => el.href);
      try {
        const candidate = portalUrl('booking', href);
        if (candidate.hostname === 'admin.booking.com' && candidate.pathname.startsWith('/hotel/hoteladmin/groups/home/'))
          groups.set(candidate.href, candidate.href);
      } catch { /* Follow only a group entry observed in this authenticated page. */ }
    }
    if (groups.size !== 1) return 'property_switch_missing';
    await page.goto([...groups.values()][0], { waitUntil: 'domcontentloaded' });
    await onStep('group');
    url = portalUrl('booking', page.url());
  }
  if (url.hostname === 'admin.booking.com' && url.pathname.includes('/groups/home')) {
    // The group table is populated asynchronously after DOMContentLoaded.
    if (typeof page.waitForFunction === 'function') {
      await page.waitForFunction(values => [...document.querySelectorAll('tr,[role="row"]')]
        .some(el => el.getClientRects().length > 0
          && ((el.textContent || '').toLowerCase().includes(values.label.toLowerCase())
            || (el.textContent || '').includes(values.property)
            || el.getAttribute('data-hotel-id') === values.property)),
      { timeout: 15000 }, { label, property }).catch(() => {});
    }
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
    let target = exact.length === 1 ? exact[0]
      : matches.length === 1 && allowed.length === 1 ? allowed[0] : null;
    if (!target && matches.length === 1 && exact.length === 0) {
      const controls = [];
      for (const control of await matches[0][String.fromCharCode(36, 36)]('button,[role="button"],a')) {
        const info = await control.evaluate(el => ({
          visible: el.getClientRects().length > 0 && !el.disabled,
          label: (el.textContent || '').trim().replace(/\\s+/g, ' ').toLowerCase(),
          href: el.tagName === 'A' ? el.href : null,
        }));
        if (!info.visible || ![label.toLowerCase(), property].includes(info.label)) continue;
        if (info.href) {
          try { portalUrl('booking', info.href); } catch { continue; }
        }
        controls.push(control);
      }
      if (controls.length === 1) target = { link: controls[0] };
    }
    let observedUrl = null;
    if (!target && matches.length === 1 && Array.isArray(input.propertyEntryUrls)) {
      const observed = new Set();
      for (const candidate of input.propertyEntryUrls) {
        try {
          const u = portalUrl('booking', candidate);
          if (u.hostname === 'admin.booking.com'
              && u.pathname === '/hotel/hoteladmin/extranet_ng/manage/home.html'
              && u.searchParams.get('hotel_id') === property) observed.add(candidate);
        } catch { /* reject unexpected destination */ }
      }
      if (observed.size === 1) observedUrl = [...observed][0];
    }
    if (!target && !observedUrl) return matches.length === 0 && exact.length === 0 ? 'property_not_found'
      : matches.length > 1 || exact.length > 1 ? 'property_ambiguous' : 'property_link_missing';
    if (observedUrl) {
      await page.goto(observedUrl, { waitUntil: 'domcontentloaded' });
      await pause(600);
    } else await clickAndSettle(page, target.link);
    await onStep('property');
  }
  url = portalUrl('booking', page.url());
  if (url.hostname !== 'admin.booking.com' || url.searchParams.get('hotel_id') !== property) return 'property_navigation';
  await waitForControl(page, labels.finance);
  let finance = await uniqueControl(page, labels.finance);
  if (!finance && await revealNavigationGroup(page, labels.finance)) {
    await waitForControl(page, labels.finance);
    finance = await uniqueControl(page, labels.finance);
  }
  if (!finance) return 'finance_missing';
  if (finance.info.href) portalUrl('booking', finance.info.href);
  await clickAndSettle(page, finance.control);
  await onStep('finance');
  await waitForControl(page, labels.invoices);
  const invoices = await uniqueControl(page, labels.invoices);
  if (!invoices) return 'invoices_missing';
  if (invoices.info.href) portalUrl('booking', invoices.info.href);
  await clickAndSettle(page, invoices.control);
  await onStep('invoices');
  url = portalUrl('booking', page.url());
  if (url.hostname !== 'admin.booking.com' || url.searchParams.get('hotel_id') !== property) return 'property_navigation';
  return 'invoices_visible';
}
