import { PortalError } from './booking.mjs';

const selector = '#onetrust-reject-all-handler';
function visibleRejectButton() {
  return [...document.querySelectorAll('#onetrust-reject-all-handler')].some(el =>
    el.tagName === 'BUTTON' && !el.disabled && el.getClientRects().length > 0
      && getComputedStyle(el).visibility !== 'hidden');
}

/** Reject only the observed Booking OneTrust control, never a generic dialog. */
export async function rejectBookingOptionalCookies(page, { waitMs = 0 } = {}) {
  const url = new URL(page.url());
  if (url.protocol !== 'https:' || url.username || url.password || (url.port && url.port !== '443')
      || !['account.booking.com', 'auth.booking.com', 'admin.booking.com'].includes(url.hostname)) return false;
  let visible = await page.evaluate(visibleRejectButton) === true;
  if (!visible && waitMs > 0 && typeof page.waitForFunction === 'function') {
    try {
      const handle = await page.waitForFunction(visibleRejectButton, { timeout: waitMs });
      await handle?.dispose();
    } catch (error) {
      if (error?.name !== 'TimeoutError') throw error;
    }
    visible = await page.evaluate(visibleRejectButton) === true;
  }
  if (!visible) return false;
  const usable = [];
  for (const button of await page.$$(selector)) {
    if (await button.evaluate(el => el.tagName === 'BUTTON' && !el.disabled
        && el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden') === true) usable.push(button);
  }
  if (usable.length !== 1) throw new PortalError('portal_changed');
  // One real click. Never fall back to accepting cookies or removing the banner.
  await usable[0].click();
  const handle = await page.waitForFunction(() =>
    ![...document.querySelectorAll('#onetrust-banner-sdk, #onetrust-pc-sdk, .onetrust-pc-dark-filter, #onetrust-reject-all-handler')]
      .some(el => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden'),
  { timeout: 5000 });
  await handle?.dispose();
  return true;
}
