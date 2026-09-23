import { inspectPortalPage, publicLocation } from './map-diagnostics.mjs';
import { portalUrl } from './portal.mjs';
import { SecondFactor } from './second-factor.mjs';
import { PortalError } from './booking.mjs';

const fail = code => { throw new PortalError(code); };
async function uniqueInput(page, predicate) {
  const inputs = await page.$$('input');
  const matches = [];
  for (const input of inputs) {
    const info = await input.evaluate(el => ({
      visible: el.getClientRects().length > 0 && !el.disabled,
      type: el.type, autocomplete: el.autocomplete, maxLength: el.maxLength,
      action: el.form?.action || null,
    }));
    if (info.visible && predicate(info)) matches.push({ input, info });
  }
  return matches.length === 1 ? matches[0] : null;
}
async function submit(page, field, portal) {
  if (!field.info.action) fail('auth_unconfigured');
  portalUrl(portal, field.info.action);
  await field.input.press('Enter');
  await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {});
  portalUrl(portal, page.url());
}
export async function discoverPortal(page, input) {
  const { portal, credentials = {}, authMethod } = input;
  const start = portal === 'booking' ? 'https://admin.booking.com/' : input.map?.login?.url;
  if (!start) fail('connector_unconfigured');
  portalUrl(portal, start);
  const broker = new SecondFactor(input, value => portalUrl(portal, value));
  const snapshots = [];
  let identifierSent = false, passwordSent = false, otpSent = false;
  try {
    if (authMethod === 'sms') await broker.prepare();
    await page.goto(start, { waitUntil: 'domcontentloaded' });
    for (let step = 0; step < 6; step++) {
      portalUrl(portal, page.url());
      snapshots.push(await inspectPortalPage(page, portal));
      const password = await uniqueInput(page, x => x.type === 'password');
      if (password && !passwordSent) {
        if (!credentials.password) fail('auth_unconfigured');
        await password.input.type(credentials.password); passwordSent = true;
        await submit(page, password, portal);
        continue;
      }
      const otp = await uniqueInput(page, x => x.autocomplete === 'one-time-code' || (x.type === 'tel' && x.maxLength === 6));
      if (otp && !otpSent) {
        if (authMethod !== 'sms') fail('needs_auth');
        const code = await broker.value({ method: 'sms', digits: 6 });
        await otp.input.type(code); otpSent = true;
        await submit(page, otp, portal);
        continue;
      }
      const identifier = await uniqueInput(page, x => x.autocomplete === 'username' || x.type === 'email');
      if (identifier && !identifierSent) {
        if (!credentials.identifier) fail('auth_unconfigured');
        await identifier.input.type(credentials.identifier); identifierSent = true;
        await submit(page, identifier, portal);
        continue;
      }
      break;
    }
    const current = publicLocation(portal, page.url());
    // A structural diagnostic never establishes account identity or a validated map.
    return { version: 1, portal, validated: false, login_attempted: identifierSent && passwordSent,
      location: current, snapshots: snapshots.slice(0, 6) };
  } finally { await broker.close(); }
}
