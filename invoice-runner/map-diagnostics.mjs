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
  return { version: 1, portal, validated: false, location, hints };
}
