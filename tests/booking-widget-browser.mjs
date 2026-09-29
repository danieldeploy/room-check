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
      fetch('/fixture-proof-check', { method: 'POST' }).then(response => {
        if (!response.ok) return;
        sessionStorage.setItem('synthetic-solved', 'yes'); location.assign('/sign-in');
      });
    }
  });
  if (sessionStorage.getItem('synthetic-solved')) document.body.innerHTML = ${JSON.stringify(form)};
  else fixtureRender();
</script>`;

async function fixture(browser, afterPassword = false, renderDelay = 0, resumeWithCookie = false) {
  const context = await browser.createBrowserContext();
  const page = await context.newPage();
  let passwordSubmitted = false, signIns = 0, verifiedRequests = 0, resumedRequests = 0, callbackRequests = 0;
  await page.setRequestInterception(true);
  page.on('request', request => {
    const url = new URL(request.url());
    if (url.origin === 'https://account.booking.com' && url.pathname === '/fixture-proof-check') {
      callbackRequests++;
      const values = (request.headers().cookie || '').split(';').map(value => value.trim());
      const accepted = values.filter(value => value.startsWith('aws-waf-token=')).length === 1
        && values.includes('aws-waf-token=fixture-token');
      if (accepted) verifiedRequests++;
      void request.respond({ status: accepted ? 200 : 405, contentType: 'text/plain', body: '' }); return;
    }
    if (url.href === scriptUrl) {
      void request.respond({ status: 200, contentType: 'application/javascript', body: sdk }); return;
    }
    let body = '<title>Synthetic extranet</title>';
    if (url.hostname === 'account.booking.com') {
      if (request.resourceType() === 'document') signIns++;
      const cookieValues = (request.headers().cookie || '').split(';').map(value => value.trim());
      const acceptedResume = resumeWithCookie && request.resourceType() === 'document' && request.method() === 'GET'
        && cookieValues.filter(value => value.startsWith('aws-waf-token=')).length === 1
        && cookieValues.includes('aws-waf-token=fixture-token');
      if (acceptedResume) resumedRequests++;
      body = acceptedResume || afterPassword && !passwordSubmitted ? form : renderDelay
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
  return { page, context, signIns: () => signIns, verifiedRequests: () => verifiedRequests,
    resumedRequests: () => resumedRequests, callbackRequests: () => callbackRequests };
}

export async function testBookingAwsWidget(browser) {
  // Reproduce a lifecycle timeout after a real intercepted GET has completed.
  // Recovery reads that document rather than issuing a second navigation.
  {
    const { page, context, signIns, verifiedRequests } = await fixture(browser);
    let calls = 0, reloads = 0;
    const controller = createBookingCaptchaTest(input, { solve: async () => { calls++; return 'fixture-token'; } });
    try {
      await page.goto('https://account.booking.com/sign-in', { waitUntil: 'load' });
      await controller.prepare(page);
      const goto = page.goto.bind(page);
      page.goto = async (...args) => {
        const response = await goto(...args);
        if (++reloads === 1) throw Object.assign(new Error('synthetic navigation timeout'), { name: 'TimeoutError' });
        return response;
      };
      assert.equal(await controller.attempt(page,
        async current => current.evaluate(() => !!document.getElementById('captcha'))), true);
      assert.equal(controller.status(), 'challenge_cleared');
      assert.equal(calls, 1);
      assert.equal(reloads, 1);
      assert.equal(signIns(), 3); // initial document, recovery GET, normal callback continuation
      assert.equal(verifiedRequests(), 1);
    } finally { await controller.release(); await context.close(); }
  }
  const cases = [{ lateAttach: false, afterPassword: false, renderDelay: 150 },
    ...[false, true].flatMap(lateAttach => [false, true].map(afterPassword => ({ lateAttach, afterPassword })))];
  for (const { lateAttach, afterPassword, renderDelay } of cases) {
      const { page, context, signIns, verifiedRequests } = await fixture(browser, afterPassword, renderDelay);
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
        await context.setCookie({ name: 'unrelated-session', value: 'preserve-fixture',
          domain: 'account.booking.com', path: '/', secure: true });
        if (!lateAttach) await controller.prepare(page);
        await page.goto('https://account.booking.com/sign-in?op_token=fixture-private', { waitUntil: 'load' });
        if (lateAttach) await controller.prepare(page);
        const result = await discoverPortal(page, input, { captchaTest: controller });
        assert.equal(result.authenticated_session, true, JSON.stringify(result));
        assert.equal(result.login_attempted, true);
        assert.equal(result.captcha_status, 'challenge_cleared');
        assert.equal(calls, 1);
        assert.equal(verifiedRequests(), 1);
        const cookies = await context.cookies();
        assert.equal(cookies.find(cookie => cookie.name === 'unrelated-session')?.value, 'preserve-fixture');
        const wafCookies = cookies.filter(cookie => cookie.name === 'aws-waf-token');
        assert.equal(wafCookies.length, 1);
        assert.equal(wafCookies[0].domain, 'account.booking.com');
        assert.equal(wafCookies[0].secure, true);
        assert.equal(wafCookies[0].sameSite, 'Lax');
        assert.equal(signIns(), 2 + (lateAttach && !afterPassword ? 1 : 0) + (afterPassword ? 1 : 0));
        for (const secret of ['fixture-private', 'fixture-token', 'synthetic-widget-key', 'synthetic-password'])
          assert.equal(JSON.stringify(result).includes(secret), false);
        // Cleanup restores the original SDK and removes future-document hooks.
        await controller.release();
        await page.goto('https://account.booking.com/sign-in', { waitUntil: 'load' });
        assert.equal(await page.evaluate(() => AwsWafCaptcha.renderCaptcha === fixtureOriginal), true);
      } finally { await controller.release(); await context.close(); }
  }

  for (const accepted of [true, false]) {
    const { page, context, signIns, resumedRequests, callbackRequests } = await fixture(browser, false, 0, accepted);
    let calls = 0;
    const controller = createBookingCaptchaTest(input, { solve: async () => {
      calls++;
      await page.evaluate(() => fixtureConfig.onPuzzleTimeout());
      return 'fixture-token';
    } });
    try {
      await controller.prepare(page);
      await page.goto('https://account.booking.com/sign-in?op_token=fixture-private', { waitUntil: 'load' });
      assert.equal(await controller.attempt(page,
        async current => current.evaluate(() => !!document.getElementById('captcha'))), accepted);
      assert.equal(controller.status(), accepted ? 'challenge_cleared' : 'not_accepted');
      assert.equal(controller.stage(), 'verify_after_puzzle_timeout');
      assert.equal(calls, 1);
      assert.equal(signIns(), 2);
      assert.equal(resumedRequests(), accepted ? 1 : 0);
      assert.equal(callbackRequests(), 0, 'never invoke the expired callback');
      assert.equal(await controller.attempt(page, async () => true), false);
    } finally { await controller.release(); await context.close(); }
  }

  for (const invalidation of ['rerender', 'same-url-navigation', 'human-success', 'error', 'removed',
    'timeout-rerender', 'timeout-removed', 'timeout-hidden', 'timeout-same-url-navigation']) {
    const { page, context } = await fixture(browser);
    const observer = createAwsWidgetObserver();
    const controller = createBookingCaptchaTest(input, { widget: observer, solve: async () => {
      const kind = invalidation.replace(/^timeout-/, '');
      if (invalidation.startsWith('timeout-')) await page.evaluate(() => fixtureConfig.onPuzzleTimeout());
      if (kind === 'same-url-navigation') await page.goto(page.url(), { waitUntil: 'load' });
      else await page.evaluate(kind => {
        if (kind === 'rerender') fixtureRender();
        if (kind === 'human-success') {
          document.cookie = 'aws-waf-token=fixture-token; Path=/; Secure; SameSite=Lax';
          fixtureConfig.onSuccess('fixture-token');
        }
        if (kind === 'error') fixtureConfig.onError({ kind: 'network_error' });
        if (kind === 'removed') document.getElementById('captcha').remove();
        if (kind === 'hidden') document.getElementById('captcha').style.display = 'none';
      }, kind);
      if (kind === 'human-success') await page.waitForSelector('form.nw-signin');
      return 'fixture-token';
    } });
    try {
      await controller.prepare(page);
      await page.goto('https://account.booking.com/sign-in', { waitUntil: 'load' });
      assert.equal(await controller.attempt(page, async () => true), false);
      assert.equal(controller.status(), 'stale', invalidation);
      const expected = { rerender: 'widget_replaced', 'same-url-navigation': 'widget_replaced',
        'human-success': 'observer_missing', error: 'widget_network_error', removed: 'widget_removed',
        'timeout-rerender': 'widget_replaced', 'timeout-removed': 'widget_expired',
        'timeout-hidden': 'widget_expired', 'timeout-same-url-navigation': 'widget_replaced' };
      assert.equal(controller.staleReason(), expected[invalidation], invalidation);
      if (invalidation !== 'human-success')
        assert.equal(await page.evaluate(() => window.fixtureCallbacks || 0), 0);
      if (invalidation.startsWith('timeout-'))
        assert.equal((await context.cookies()).some(cookie => cookie.name === 'aws-waf-token'), false);
    } finally { await controller.release(); await context.close(); }
  }

  for (const conflict of ['http-only', 'parent-domain']) {
    const { page, context, verifiedRequests } = await fixture(browser);
    const controller = createBookingCaptchaTest(input, { solve: async () => 'fixture-token' });
    try {
      await context.setCookie({ name: 'aws-waf-token', value: 'existing-fixture', path: '/', secure: true,
        domain: conflict === 'parent-domain' ? '.booking.com' : 'account.booking.com',
        httpOnly: conflict === 'http-only' });
      await controller.prepare(page);
      await page.goto('https://account.booking.com/sign-in', { waitUntil: 'load' });
      assert.equal(await controller.attempt(page, async () => true), false);
      assert.equal(controller.status(), 'not_accepted', conflict);
      assert.equal(verifiedRequests(), 0);
      assert.equal(await page.evaluate(() => window.fixtureCallbacks || 0), 0);
      assert.ok((await context.cookies()).some(cookie => cookie.value === 'existing-fixture'));
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
