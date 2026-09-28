import assert from 'node:assert/strict';
import { rejectBookingOptionalCookies } from '../invoice-runner/booking-cookie-consent.mjs';
import { discoverPortal } from '../invoice-runner/discover-portal.mjs';

// Entirely synthetic: intercept every request, including Booking-looking URLs.
export async function testBookingCookieConsent(browser) {
  const page = await browser.newPage();
  const cookieMarkup = `<div id="onetrust-banner-sdk" style="position:fixed;inset:0;background:white">
    <button id="onetrust-accept-btn-handler" onclick="document.body.dataset.accepted='true'">Accept all</button>
    <button id="onetrust-reject-all-handler" onclick="document.body.dataset.rejected='true';document.getElementById('onetrust-banner-sdk').remove()">Rejeitar opcionais</button></div>`;
  await page.setRequestInterception(true);
  page.on('request', request => {
    const url = new URL(request.url());
    let body = '<title>Synthetic extranet</title>';
    if (url.hostname === 'account.booking.com') body = `<!doctype html><title>Synthetic Booking login</title>
      <form class="nw-signin" action="https://auth.booking.com/u/login/password">
        <input id="loginname" autocomplete="username"><button type="submit">Continue</button>
      </form><script>setTimeout(() => {
        document.body.insertAdjacentHTML('beforeend', ${JSON.stringify(cookieMarkup)});
        document.getElementById('onetrust-reject-all-handler').focus();
      }, 100);</script>`;
    if (url.hostname === 'auth.booking.com') body = `<!doctype html><title>Synthetic password</title>
      <form action="https://admin.booking.com/hotel/hoteladmin/groups/home/">
        <input type="password" autocomplete="current-password"><button type="submit">Sign in</button>
      </form>`;
    void request.respond({ status: 200, contentType: 'text/html', body });
  });
  try {
    await page.goto('https://account.booking.com/sign-in');
    const diagnostic = await discoverPortal(page, { portal: 'booking', accountId: 1,
      browserProfile: 'fresh_login', loginOnly: true, authMethod: 'password',
      credentials: { identifier: 'synthetic-user', password: 'synthetic-password' } });
    assert.equal(diagnostic.cookie_consent_rejected, true);
    assert.equal(diagnostic.authenticated_session, true, JSON.stringify(diagnostic));
    assert.equal(diagnostic.login_attempted, true);
    assert.equal(JSON.stringify(diagnostic).includes('synthetic-password'), false);
    // Hidden rejection controls, even with a visible accept button, are ignored.
    await page.goto('https://admin.booking.com/hotel/hoteladmin/groups/home/');
    await page.setContent('<button id="onetrust-reject-all-handler" hidden>Reject</button><button id="onetrust-accept-btn-handler">Accept</button>');
    assert.equal(await rejectBookingOptionalCookies(page), false);
    await page.setContent('<button id="onetrust-reject-all-handler" disabled>Reject</button>');
    assert.equal(await rejectBookingOptionalCookies(page), false);
  } finally { await page.close(); }
}
