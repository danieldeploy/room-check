import { BOOKING_AUTH_ORIGINS } from './booking-permissions.mjs';
import { amazonTask, solveAmazonCaptcha, CaptchaError, PROVIDER_ERROR_STATUSES } from './captcha-provider.mjs';
import { createAwsWidgetObserver } from './booking-aws-widget.mjs';
import { setTimeout as delay } from 'node:timers/promises';

export const CAPTCHA_STATUSES = Object.freeze(['disabled', 'unconfigured', 'unsupported',
  'provider_error', 'browser_error', 'browser_timeout', 'browser_context_lost',
  'browser_cookie_conflict', 'browser_cookie_unavailable', 'browser_callback_error',
  'browser_page_closed', 'browser_navigation_aborted', 'timeout', 'stale', 'not_accepted', 'challenge_cleared', 'not_needed',
  ...PROVIDER_ERROR_STATUSES]);
export const CAPTCHA_STAGES = Object.freeze(['not_started', 'capture', 'provider',
  'capture_read', 'capture_restart_check', 'capture_reload', 'capture_ready',
  'capture_reload_changed_url', 'capture_reload_inspect', 'capture_reload_loading',
  'capture_reload_interactive', 'capture_reload_unknown_state',
  'capture_verify', 'capture_wait', 'create_task', 'poll_task', 'validate', 'apply', 'verify',
  'apply_after_puzzle_timeout', 'verify_after_puzzle_timeout']);
export const CAPTCHA_STALE_REASONS = Object.freeze(['page_changed', 'page_unavailable',
  'challenge_cleared', 'challenge_changed', 'observer_missing', 'widget_replaced',
  'widget_completed', 'widget_expired', 'widget_removed', 'widget_hidden', 'widget_error',
  'widget_internal_error', 'widget_network_error', 'widget_token_error', 'widget_client_error']);

// Only categorical results escape this boundary: never return browser messages,
// stacks, URLs, or error objects, which may contain the private sign-in token.
function captureErrorStatus(error) {
  if (error?.name === 'TimeoutError') return 'browser_timeout';
  const message = typeof error?.message === 'string' ? error.message : '';
  if (message.startsWith('Execution context was destroyed')
      || message.includes('Cannot find context with specified id')
      || message.startsWith('Attempted to use detached Frame')) return 'browser_context_lost';
  if (error?.name === 'TargetCloseError' || message.includes('Target closed')
      || message.includes('Session closed')) return 'browser_page_closed';
  if (message.startsWith('net::ERR_ABORTED')) return 'browser_navigation_aborted';
  return 'browser_error';
}

function applyErrorStatus(error) {
  const message = typeof error?.message === 'string' ? error.message : '';
  if (message === 'widget token cookie conflict') return 'browser_cookie_conflict';
  if (message === 'widget token cookie unavailable') return 'browser_cookie_unavailable';
  if (message === 'widget callback failed') return 'browser_callback_error';
  return captureErrorStatus(error);
}

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
  let stage = 'not_started';
  let staleReason = null;
  let status = input.portal === 'booking' && input.action === 'login' && options.captcha_mode === 'test' ? 'not_needed' : 'disabled';
  const readChallenge = async page => {
    const location = new URL(page.url());
    if (!BOOKING_AUTH_ORIGINS.includes(location.origin)) return null;
    const raw = await widget.read(page);
    if (raw) return { ...amazonTask({ ...raw, websiteURL: location.origin + '/' }), widgetId: raw.widgetId };
    return read(page);
  };
  const stale = reason => {
    staleReason = CAPTCHA_STALE_REASONS.includes(reason) ? reason : 'challenge_changed';
    status = 'stale'; return false;
  };
  const changedReason = async (page, challenge) => challenge.wafType === 'widget'
    && widget.invalidReason ? widget.invalidReason(page, challenge.widgetId) : 'challenge_changed';
  return {
    status: () => status,
    stage: () => stage,
    staleReason: () => staleReason,
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
        stage = 'capture';
        const originalUrl = page.url();
        stage = 'capture_read';
        let challenge = await readChallenge(page);
        stage = 'capture_restart_check';
        if (!challenge && await widget.canRestart(page)) {
          const url = new URL(originalUrl);
          // Existing persistent tabs may have rendered before this job attached.
          // One explicit GET installs the observer before a new render; never reload a POST.
          if (url.hostname === 'account.booking.com' && url.pathname === '/sign-in'
              || url.hostname === 'auth.booking.com' && url.pathname === '/u/login/password') {
            stage = 'capture_reload';
            try { await page.goto(originalUrl, { waitUntil: 'domcontentloaded' }); }
            catch (error) {
              // Chrome can time out its navigation lifecycle after the expected
              // document has loaded. Check that document; never issue another GET.
              if (error?.name !== 'TimeoutError') throw error;
              if (page.url() !== originalUrl) {
                stage = 'capture_reload_changed_url';
                throw error;
              }
              stage = 'capture_reload_inspect';
              const readyState = await page.evaluate(() => document.readyState);
              if (readyState !== 'complete') {
                stage = readyState === 'loading' ? 'capture_reload_loading'
                  : readyState === 'interactive' ? 'capture_reload_interactive'
                    : 'capture_reload_unknown_state';
                throw error;
              }
            }
            stage = 'capture_ready';
            await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 });
            stage = 'capture_verify';
            if (BOOKING_AUTH_ORIGINS.includes(new URL(page.url()).origin) && !await hasChallenge(page)) {
              status = 'not_needed'; return true;
            }
            stage = 'capture_read';
            challenge = await readChallenge(page);
          }
        }
        if (!challenge) {
          stage = 'capture_wait';
          await widget.waitForRender(page);
          stage = 'capture_read';
          challenge = await readChallenge(page);
        }
        if (!challenge) { status = 'unsupported'; return false; }
        stage = 'capture_verify';
        if (page.url() !== originalUrl) return stale('page_changed');
        if (!await hasChallenge(page)) return stale('challenge_cleared');
        stage = 'provider';
        const token = await solve(challenge, options.captcha_api_key, {
          onStage: value => { if (['create_task', 'poll_task'].includes(value)) stage = value; },
        });
        tokenReceived = true;
        stage = 'validate';
        // A human or the site may have moved on while the provider was solving.
        if (page.url() !== originalUrl) return stale('page_changed');
        if (!await hasChallenge(page)) return stale('challenge_cleared');
        if (JSON.stringify(await readChallenge(page)) !== JSON.stringify(challenge)) {
          const reason = await changedReason(page, challenge);
          const url = new URL(originalUrl);
          const safeGet = url.hostname === 'account.booking.com' && url.pathname === '/sign-in'
            || url.hostname === 'auth.booking.com' && url.pathname === '/u/login/password';
          if (reason !== 'widget_expired' || !safeGet || !widget.storeAfterPuzzleTimeout)
            return stale(reason);
          applying = true;
          stage = 'apply_after_puzzle_timeout';
          // Re-check the same document, widget, URL and visible container while
          // storing the newly received token. Never revive an expired callback.
          if (!await widget.storeAfterPuzzleTimeout(page, challenge.widgetId, token))
            return stale(await changedReason(page, challenge));
          await page.goto(originalUrl, { waitUntil: 'domcontentloaded' });
          await page.waitForFunction(() => document.readyState === 'complete', { timeout: 15000 });
          stage = 'verify_after_puzzle_timeout';
          status = await hasChallenge(page) ? 'not_accepted' : 'challenge_cleared';
          return status === 'challenge_cleared';
        }
        applying = true;
        stage = 'apply';
        if (challenge.wafType === 'widget') {
          if (!await widget.complete(page, challenge.widgetId, token)) return stale(await changedReason(page, challenge));
          stage = 'verify';
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
        stage = 'verify';
        status = await hasChallenge(page) ? 'not_accepted' : 'challenge_cleared';
        return status === 'challenge_cleared';
      } catch (error) {
        if (tokenReceived && !applying && !(error instanceof CaptchaError)) return stale('page_unavailable');
        status = error instanceof CaptchaError && CAPTCHA_STATUSES.includes(error.message)
          ? error.message : tokenReceived ? applying ? applyErrorStatus(error) : 'stale'
            : stage === 'capture' || stage.startsWith('capture_') ? captureErrorStatus(error) : 'provider_error';
        return false;
      }
    },
  };
}
