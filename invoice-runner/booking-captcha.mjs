import { BOOKING_AUTH_ORIGINS } from './booking-permissions.mjs';
import { amazonTask, solveAmazonCaptcha, CaptchaError } from './captcha-provider.mjs';

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
  read = readBookingAwsChallenge } = {}) {
  const options = input.automation ?? {};
  let used = false;
  let status = input.portal === 'booking' && input.action === 'login' && options.captcha_mode === 'test' ? 'not_needed' : 'disabled';
  return {
    status: () => status,
    async attempt(page, hasChallenge) {
      if (status === 'disabled' || used) return false;
      used = true;
      if (options.captcha_provider !== 'anti-captcha' || !options.captcha_api_key) {
        status = 'unconfigured'; return false;
      }
      try {
        const originalUrl = page.url();
        const challenge = await read(page);
        if (!challenge) { status = 'unsupported'; return false; }
        const token = await solve(challenge, options.captcha_api_key);
        // A human or the site may have moved on while the provider was solving.
        if (page.url() !== originalUrl || !await hasChallenge(page)
            || JSON.stringify(await read(page)) !== JSON.stringify(challenge)) {
          status = 'stale'; return false;
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
          ? error.message : 'provider_error';
        return false;
      }
    },
  };
}
