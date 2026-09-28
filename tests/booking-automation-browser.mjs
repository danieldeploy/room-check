import assert from 'node:assert/strict';
import { blockBookingLoopback } from '../invoice-runner/booking-permissions.mjs';
import { createBookingCaptchaTest } from '../invoice-runner/booking-captcha.mjs';
import { discoverPortal } from '../invoice-runner/discover-portal.mjs';

// No network requests leave this synthetic browser test, including provider calls.
export async function testBookingAutomation(browser) {
  const context = await browser.createBrowserContext();
  // The production helper targets the default profile. Use a new default page.
  const page = await browser.newPage();
  let sent = 0;
  await page.setRequestInterception(true);
  page.on('request', request => {
    const url = new URL(request.url());
    let body = '<title>Synthetic extranet</title>';
    if (url.hostname === 'account.booking.com') {
      const solved = (request.headers().cookie || '').includes('aws-waf-token=fixture-token');
      body = solved ? `<form class="nw-signin" action="https://auth.booking.com/u/login/password"><input autocomplete="username"><button>Continue</button></form>`
        : `<h1>Let's make sure you're human</h1><div id="captcha">Synthetic challenge</div><script>window.gokuProps={key:'fixture-key',iv:'fixture-iv',context:'fixture-context'};</script>`;
    }
    if (url.hostname === 'auth.booking.com') body = `<form action="https://admin.booking.com/hotel/home"><input type="password"><button>Login</button></form>`;
    void request.respond({ status: 200, contentType: 'text/html', body });
  });
  try {
    assert.equal(await blockBookingLoopback(browser), 'blocked');
    await page.goto('https://account.booking.com/sign-in?op_token=fixture-private');
    assert.equal(await page.evaluate(async () => (await navigator.permissions.query({ name: 'loopback-network' })).state), 'denied');
    // An unrelated origin must not inherit Booking's override.
    const unrelated = await context.newPage();
    await unrelated.setRequestInterception(true);
    unrelated.on('request', request => void request.respond({ status: 200, body: '<title>Fixture</title>' }));
    await unrelated.goto('https://example.test/');
    assert.equal(await unrelated.evaluate(async () => (await navigator.permissions.query({ name: 'loopback-network' })).state), 'prompt');
    const input = { action: 'login', portal: 'booking', accountId: 1, loginOnly: true,
      browserProfile: 'fresh_login', authMethod: 'password',
      credentials: { identifier: 'synthetic-user', password: 'synthetic-password' },
      automation: { captcha_mode: 'test', captcha_provider: 'anti-captcha', captcha_api_key: 'a'.repeat(32) } };
    const controller = createBookingCaptchaTest(input, { solve: async challenge => {
      sent++; assert.equal(challenge.websiteURL, 'https://account.booking.com/'); return 'fixture-token';
    } });
    const result = await discoverPortal(page, input, { captchaTest: controller, loopbackPermission: 'blocked' });
    assert.equal(result.authenticated_session, true, JSON.stringify(result));
    assert.equal(result.login_attempted, true);
    assert.equal(result.captcha_status, 'challenge_cleared');
    assert.equal(sent, 1);
    for (const secret of ['fixture-private', 'fixture-token', 'fixture-key', 'synthetic-password'])
      assert.equal(JSON.stringify(result).includes(secret), false);
  } finally { await page.close(); await context.close(); }
}
