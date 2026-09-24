import { portalUrl } from './portal.mjs';

const MAX = 80;
const simple = value => typeof value === 'string' && /^[A-Za-z][A-Za-z0-9_-]{0,48}$/.test(value)
  && !/^[a-f0-9]{16,}$/i.test(value);
export function publicLocation(portal, value) {
  const url = portalUrl(portal, value);
  const allowed = new URLSearchParams();
  if (portal === 'booking' && /^\d{1,12}$/.test(url.searchParams.get('hotel_id') || '')) {
    allowed.set('hotel_id', url.searchParams.get('hotel_id'));
  }
  const pathname = url.pathname.split('/').map(segment => {
    if (!segment) return '';
    let decoded; try { decoded = decodeURIComponent(segment); } catch { return ':redacted'; }
    return /^[A-Za-z][A-Za-z0-9_-]{0,31}$/.test(decoded) && !/^[a-f0-9]{16,}$/i.test(decoded)
      ? segment : ':redacted';
  }).join('/');
  return url.origin + pathname + (allowed.size ? '?' + allowed : '');
}
export function candidateSelector(tag, id, classes = []) {
  const name = String(tag || '').toLowerCase();
  if (!/^[a-z][a-z0-9-]{0,24}$/.test(name)) return null;
  if (simple(id)) return '#' + id;
  const stable = classes.filter(simple).slice(0, 2);
  return name + stable.map(c => '.' + c).join('');
}
/**
 * Structural hints only. This intentionally cannot create a validated connector
 * map. It never serializes field values, page text, cookies or response bodies.
 */
export async function inspectPortalPage(page, portal) {
  const location = publicLocation(portal, page.url());
  const nodes = await page.evaluate(() => {
    const eligible = 'input,select,button,a,form,table,tr,iframe';
    return [...document.querySelectorAll(eligible)].slice(0, 250).map(el => ({
      tag: el.tagName.toLowerCase(),
      id: el.id,
      classes: [...el.classList].slice(0, 4),
      type: el.tagName === 'INPUT' ? el.type : undefined,
      href: el.tagName === 'A' ? el.href : undefined,
      action: el.tagName === 'FORM' ? el.action : undefined,
    }));
  });
  if (!Array.isArray(nodes)) throw new Error('invalid_snapshot');
  const hints = [];
  for (const item of nodes) {
    if (!item || !['input','select','button','a','form','table','tr','iframe'].includes(item.tag)) continue;
    const selector = candidateSelector(item.tag, item.id, Array.isArray(item.classes) ? item.classes : []);
    if (!selector) continue;
    const hint = { kind: item.tag, selector };
    if (item.tag === 'input' && ['text','email','password','tel','number','hidden'].includes(item.type)) hint.type = item.type;
    for (const key of ['href','action']) {
      if (item[key]) {
        try { hint[key] = publicLocation(portal, item[key]); } catch { /* foreign or malformed destination */ }
      }
    }
    if (!hints.some(existing => existing.kind === hint.kind && existing.selector === selector)) hints.push(hint);
    if (hints.length === MAX) break;
  }
  const rawSignals = await page.evaluate(() => {
    const visible = el => !!el && el.getClientRects().length > 0 && !el.disabled;
    const inputs = [...document.querySelectorAll('input')].filter(visible);
    return {
      ready_state: document.readyState,
      identifier: inputs.some(el => el.id === 'loginname' || el.type === 'email' || el.autocomplete === 'username'),
      password: inputs.some(el => el.type === 'password' && el.id !== 'hidden-password'),
      otp: inputs.some(el => el.autocomplete === 'one-time-code' || (el.type === 'tel' && el.maxLength === 6)),
      form: [...document.querySelectorAll('form')].some(visible),
      alert: [...document.querySelectorAll('[role="alert"], [aria-live="assertive"]')].some(visible),
      invalid_field: inputs.some(el => el.getAttribute('aria-invalid') === 'true'),
      challenge: !!document.querySelector('iframe[src*="captcha"], [id*="captcha"], [class*="captcha"]'),
    };
  });
  const signals = rawSignals && !Array.isArray(rawSignals) && ['loading','interactive','complete'].includes(rawSignals.ready_state)
    ? { ready_state: rawSignals.ready_state,
      identifier: rawSignals.identifier === true, password: rawSignals.password === true,
      otp: rawSignals.otp === true, form: rawSignals.form === true, alert: rawSignals.alert === true,
      invalid_field: rawSignals.invalid_field === true, challenge: rawSignals.challenge === true } : null;
  let navigation = undefined;
  if (portal === 'booking') {
    const raw = await page.evaluate(() => [...document.querySelectorAll(
      '.ext-navigation-top-item__link,.ext-navigation-submenu-item__link')].slice(0, 30).map(el => {
      const visibleText = (el.innerText || '').trim().replace(/\\s+/g, ' ').toLowerCase();
      const allText = (el.textContent || '').trim().replace(/\\s+/g, ' ').toLowerCase();
      const aria = (el.getAttribute('aria-label') || '').toLowerCase();
      const finance = /finance|finanças|financial/;
      const invoices = /invoice|fatura/;
      return { top: el.classList.contains('ext-navigation-top-item__link'),
        visible: el.getClientRects().length > 0,
        visible_finance: finance.test(visibleText), full_finance: finance.test(allText),
        aria_finance: finance.test(aria), visible_invoices: invoices.test(visibleText),
        full_invoices: invoices.test(allText), aria_invoices: invoices.test(aria),
        visible_length: Math.min(visibleText.length, 200), full_length: Math.min(allText.length, 200),
        children: Math.min(el.children.length, 40) };
    }));
    if (Array.isArray(raw)) navigation = raw.map(x => ({
      top: x.top === true, visible: x.visible === true,
      visible_finance: x.visible_finance === true, full_finance: x.full_finance === true,
      aria_finance: x.aria_finance === true, visible_invoices: x.visible_invoices === true,
      full_invoices: x.full_invoices === true, aria_invoices: x.aria_invoices === true,
      visible_length: Number.isInteger(x.visible_length) ? x.visible_length : 0,
      full_length: Number.isInteger(x.full_length) ? x.full_length : 0,
      children: Number.isInteger(x.children) ? x.children : 0
    }));
  }
  return { version: 1, portal, validated: false, location, hints, signals, navigation };
}
