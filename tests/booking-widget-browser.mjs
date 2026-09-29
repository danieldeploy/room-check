import assert from 'node:assert/strict';
import { createBookingCaptchaTest } from '../invoice-runner/booking-captcha.mjs';
import { createAwsWidgetObserver } from '../invoice-runner/booking-aws-widget.mjs';
import { discoverPortal } from '../invoice-runner/discover-portal.mjs';

const scriptUrl = 'https://a1b2c3.edge.captcha-sdk.awswaf.com/a1b2c3/jsapi.js';
const input = { action: 'login', portal: 'booking', accountId: 1, loginOnly: true,
  browserProfile: 'fresh_login', authMethod: 'password',
  credentials: { identifier: 'synthetic-user', password: 'synthetic-password' },
  automation: { captcha_mode: 'test', captcha_provider: 'anti-captcha', captcha_api_key: 'a'.repeat(32) } };
const sdk = `window.fixtureOriginal = function(container, config) {
  window.fixtureConfig = config; container.textContent = 'Synthetic widget';
}; window.AwsWafCaptcha = { renderCaptcha: window.fixtureOriginal };`;
const form = `<form class="nw-signin" action="https://auth.booking.com/u/login/password"><input autocomplete="username"><button>Continue</button></form>`;
const widgetHtml = `<h1>Let's make sure you're human</h1><script src="${scriptUrl}"></script><div id="captcha"></div><script>
  window.fixtureRender = () => AwsWafCaptcha.renderCaptcha(document.getElementById('captcha'), {
    apiKey: 'synthetic-widget-key', onSuccess(token) {
      window.fixtureCallbacks = (window.fixtureCallbacks || 0) + 1;
      if (token !== 'fixture-token') throw new Error('invalid fixture token');
      sessionStorage.setItem('synthetic-solved', 'yes'); location.assign('/sign-in');
    }
  });
  if (sessionStorage.getItem('synthetic-solved')) document.body.innerHTML = ${JSON.stringify(form)};
  else fixtureRender();
</script>`;

async function fixture(browser, afterPassword = false, renderDelay = 0) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  let passwordSubmitted = false, signIns = 0;
  await page.setRequestInterception(true);
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.href === scriptUrl) {
      void request.respond({ status: 200, contentType: 'application/javascript', body: sdk }); return;
    }
    let body = '<title>Synthetic extranet</title>';
    if (url.hostname === 'account.booking.com') {
      if (request.resourceType() === 'document') signIns++;
      body = afterPassword && !passwordSubmitted ? form : renderDelay
        ? widgetHtml.replace('else fixtureRender();', `else setTimeout(fixtureRender, ${renderDelay});`)
        : widgetHtml;
    }
    if (url.hostname === 'auth.booking.com') body = `<form action="https://admin.booking.com/hotel/home"><input type="password"><button>Login</button></form>`;
    if (url.hostname === 'admin.booking.com' && afterPassword && !passwordSubmitted) {
      passwordSubmitted = true;
      void request.respond({ status: 302, headers: { location: 'https://account.booking.com/sign-in' }, body: '' }); return;
    }
    void request.respond({ status: 200, contentType: 'text/html', body });
  });
  return { page, context, signIns: () => signIns };
}

export async function testBookingAwsWidget(browser) {
  const cases = [{ lateAttach: false, afterPassword: false, renderDelay: 150 },
    ...[false, true].flatMap(lateAttach => [false, true].map(afterPassword => ({ lateAttach, afterPassword })))];
  for (const { lateAttach, afterPassword, renderDelay } of cases) {
      const { page, context, signIns } = await fixture(browser, afterPassword, renderDelay);
      let calls = 0;
      const controller = createBookingCaptchaTest(input, { solve: async challenge => {
        calls++;
        assert.equal(challenge.wafType, 'widget');
        assert.equal(challenge.websiteURL, 'https://account.booking.com/');
        assert.equal(challenge.websiteKey, 'synthetic-widget-key');
        assert.equal(challenge.jsapiScript, scriptUrl);
        return 'fixture-token';
      } });
      try {
        if (!lateAttach) await controller.prepare(page);
        await page.goto('https://account.booking.com/sign-in?op_token=fixture-private', { waitUntil: 'load' });
        if (lateAttach) await controller.prepare(page);
        const result = await discoverPortal(page, input, { captchaTest: controller });
        assert.equal(result.authenticated_session, true, JSON.stringify(result));
        assert.equal(result.login_attempted, true);
        assert.equal(result.captcha_status, 'challenge_cleared');
        assert.equal(calls, 1);
        assert.equal(signIns(), 2 + (lateAttach && !afterPassword ? 1 : 0) + (afterPassword ? 1 : 0));
        for (const secret of ['fixture-private', 'fixture-token', 'synthetic-widget-key', 'synthetic-password'])
          assert.equal(JSON.stringify(result).includes(secret), false);
        // Cleanup restores the original SDK and removes future-document hooks.
        await controller.release();
        await page.goto('https://account.booking.com/sign-in', { waitUntil: 'load' });
        assert.equal(await page.evaluate(() => AwsWafCaptcha.renderCaptcha === fixtureOriginal), true);
      } finally { await controller.release(); await context.close(); }
  }

  for (const invalidation of ['rerender', 'same-url-navigation', 'human-success', 'timeout', 'error', 'removed']) {
    const { page, context } = await fixture(browser);
    const observer = createAwsWidgetObserver();
    const controller = createBookingCaptchaTest(input, { widget: observer, solve: async () => {
      if (invalidation === 'same-url-navigation') await page.goto(page.url(), { waitUntil: 'load' });
      else await page.evaluate(kind => {
        if (kind === 'rerender') fixtureRender();
        if (kind === 'human-success') fixtureConfig.onSuccess('fixture-token');
        if (kind === 'timeout') fixtureConfig.onPuzzleTimeout();
        if (kind === 'error') fixtureConfig.onError({ kind: 'network_error' });
        if (kind === 'removed') document.getElementById('captcha').remove();
      }, invalidation);
      return 'fixture-token';
    } });
    try {
      await controller.prepare(page);
      await page.goto('https://account.booking.com/sign-in', { waitUntil: 'load' });
      assert.equal(await controller.attempt(page, async () => true), false);
      assert.equal(controller.status(), 'stale', invalidation);
      if (invalidation !== 'human-success')
        assert.equal(await page.evaluate(() => window.fixtureCallbacks || 0), 0);
    } finally { await controller.release(); await context.close(); }
  }

  const { page, context } = await fixture(browser);
  const observer = createAwsWidgetObserver();
  try {
    await observer.prepare(page);
    await page.goto('https://unrelated.test/', { waitUntil: 'load' });
    assert.equal(await page.evaluate(() => 'AwsWafCaptcha' in window), false);
    assert.equal(await observer.read(page), null);
  } finally { await observer.release(); await context.close(); }
}
