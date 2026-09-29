import { BOOKING_AUTH_ORIGINS } from './booking-permissions.mjs';
import { amazonTask, solveAmazonCaptcha, CaptchaError } from './captcha-provider.mjs';
import { createAwsWidgetObserver } from './booking-aws-widget.mjs';
import { setTimeout as delay } from 'node:timers/promises';

export const CAPTCHA_STATUSES = Object.freeze(['disabled', 'unconfigured', 'unsupported',
  'provider_error', 'timeout', 'stale', 'not_accepted', 'challenge_cleared', 'not_needed']);

export async function readBookingAwsChallenge(page) {
  const location = new URL(page.url());
  if (!BOOKING_AUTH_ORIGINS.includes(location.origin)) return null;
  // Only the documented interstitial contract. Do not guess private widget callbacks.
  const raw = await page.evaluate(() => {
    const props = window.gokuProps;
    if (!props || typeof props !== 'object') return null;
    const keys = ['key', 'iv', 'context'];
    if (keys.some(key => typeof props[key] !== 'string' || props[key].length > 16384)) return null;
    const scripts = [...document.scripts].map(script => script.src).slice(0, 100);
    return { websiteKey: props.key, iv: props.iv, context: props.context,
      captchaScript: scripts.find(src => src.endsWith('/captcha.js')),
      challengeScript: scripts.find(src => src.endsWith('/challenge.js')) };
  });
  if (!raw) return null;
  try { return amazonTask({ ...raw, websiteURL: location.origin + '/' }); }
  catch { return null; }
}

// One controller per runner job, shared across discovery/login calls.
export function createBookingCaptchaTest(input, { solve = solveAmazonCaptcha,
  read = readBookingAwsChallenge, widget = createAwsWidgetObserver() } = {}) {
  const options = input.automation ?? {};
  let used = false;
  let status = input.portal === 'booking' && input.action === 'login' && options.captcha_mode === 'test' ? 'not_needed' : 'disabled';
  const readChallenge = async page => {
    const location = new URL(page.url());
    if (!BOOKING_AUTH_ORIGINS.includes(location.origin)) return null;
    const raw = await widget.read(page);
    if (raw) return { ...amazonTask({ ...raw, websiteURL: location.origin + '/' }), widgetId: raw.widgetId };
    return read(page);
  };
  return {
    status: () => status,
    prepare: page => status !== 'disabled' && options.captcha_provider === 'anti-captcha' && options.captcha_api_key
      ? widget.prepare(page) : Promise.resolve(),
    release: () => widget.release(),
    async attempt(page, hasChallenge) {
      if (status === 'disabled' || used) return false;
      used = true;
      if (options.captcha_provider !== 'anti-captcha' || !options.captcha_api_key) {
        status = 'unconfigured'; return false;
      }
      let tokenReceived = false, applying = false;
      try {
        const originalUrl = page.url();
        let challenge = await readChallenge(page);
        if (!challenge && await widget.canRestart(page)) {
          const url = new URL(originalUrl);
          // Existing persistent tabs may have rendered before this job attached.
          // One explicit GET installs the observer before a new render; never reload a POST.
          if (url.hostname === 'account.booking.com' && url.pathname === '/sign-in'
              || url.hostname === 'auth.booking.com' && url.pathname === '/u/login/password') {
            await page.goto(originalUrl, { waitUntil: 'domcontentloaded' });
            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 });
            if (BOOKING_AUTH_ORIGINS.includes(new URL(page.url()).origin) && !await hasChallenge(page)) {
              status = 'not_needed'; return true;
            }
            challenge = await readChallenge(page);
          }
        }
        if (!challenge) {
          await widget.waitForRender(page);
          challenge = await readChallenge(page);
        }
        if (!challenge) { status = 'unsupported'; return false; }
        if (page.url() !== originalUrl || !await hasChallenge(page)) { status = 'stale'; return false; }
        const token = await solve(challenge, options.captcha_api_key);
        tokenReceived = true;
        // A human or the site may have moved on while the provider was solving.
        if (page.url() !== originalUrl || !await hasChallenge(page)
            || JSON.stringify(await readChallenge(page)) !== JSON.stringify(challenge)) {
          status = 'stale'; return false;
        }
        applying = true;
        if (challenge.wafType === 'widget') {
          if (!await widget.complete(page, challenge.widgetId, token)) { status = 'stale'; return false; }
          const deadline = Date.now() + 15000;
          do {
            try {
              if (BOOKING_AUTH_ORIGINS.includes(new URL(page.url()).origin) && !await hasChallenge(page)) {
                status = 'challenge_cleared'; return true;
              }
            } catch { /* The callback may be navigating to the next login step. */ }
            await delay(250);
          } while (Date.now() < deadline);
          status = 'not_accepted'; return false;
        }
        const hostname = new URL(challenge.websiteURL).hostname;
        await page.browserContext().setCookie({ name: 'aws-waf-token', value: token,
          domain: hostname, path: '/', secure: true, httpOnly: false, sameSite: 'Lax' });
        // Explicit GET, not reload: never replay a credential-bearing POST.
        await page.goto(originalUrl, { waitUntil: 'domcontentloaded' });
        await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 });
        status = await hasChallenge(page) ? 'not_accepted' : 'challenge_cleared';
        return status === 'challenge_cleared';
      } catch (error) {
        status = error instanceof CaptchaError && CAPTCHA_STATUSES.includes(error.message)
          ? error.message : tokenReceived ? applying ? 'not_accepted' : 'stale' : 'provider_error';
        return false;
      }
    },
  };
}
