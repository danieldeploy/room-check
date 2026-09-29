import { setTimeout as delay } from 'node:timers/promises';

export class CaptchaError extends Error {}
// Fixed categories only: provider descriptions may contain request values.
const providerErrors = new Map([
  ['ERROR_KEY_DOES_NOT_EXIST', [1, 'provider_key_invalid']],
  ['ERROR_NO_SLOT_AVAILABLE', [2, 'provider_no_capacity']],
  ['ERROR_ZERO_BALANCE', [10, 'provider_zero_balance']],
  ['ERROR_IP_NOT_ALLOWED', [11, 'provider_ip_denied']],
  ['ERROR_CAPTCHA_UNSOLVABLE', [12, 'provider_unsolvable']],
  ['ERROR_NO_SUCH_CAPCHA_ID', [16, 'provider_task_missing']],
  ['ERROR_IP_BLOCKED', [21, 'provider_ip_denied']],
  ['ERROR_TASK_NOT_SUPPORTED', [23, 'provider_task_unsupported']],
  ['ERROR_INCORRECT_SESSION_DATA', [24, 'provider_session_invalid']],
  ['ERROR_TOKEN_EXPIRED', [34, 'provider_token_expired']],
  ['ERROR_FAILED_LOADING_WIDGET', [52, 'provider_widget_failed']],
  ['ERROR_ACCOUNT_SUSPENDED', [55, 'provider_account_suspended']],
]);
export const PROVIDER_ERROR_STATUSES = Object.freeze([...new Set(
  [...providerErrors.values()].map(([, status]) => status))]);
const fail = code => { throw new CaptchaError(code); };
const textValue = value => typeof value === 'string' && value.length > 0 && value.length <= 16384
  && !/[\u0000-\u001f\u007f]/.test(value);

// Explicit allowlist: never forward arbitrary page objects or private URL queries.
export function amazonTask(challenge) {
  let url;
  try { url = new URL(challenge.websiteURL); } catch { fail('unsupported'); }
  if (url.protocol !== 'https:' || url.username || url.password || url.port
      || url.search || url.hash || url.pathname !== '/') fail('unsupported');
  const task = { type: 'AmazonTaskProxyless', websiteURL: url.href };
  if (challenge.wafType !== undefined && challenge.wafType !== 'widget') fail('unsupported');
  for (const key of challenge.wafType === 'widget' ? ['websiteKey'] : ['websiteKey', 'iv', 'context']) {
    if (!textValue(challenge[key])) fail('unsupported');
    task[key] = challenge[key];
  }
  if (challenge.wafType === 'widget') {
    let script;
    try { script = new URL(challenge.jsapiScript); } catch { fail('unsupported'); }
    if (script.protocol !== 'https:' || script.username || script.password || script.port
        || script.search || script.hash || script.href.length > 2048
        || !/^[a-f0-9]+\.edge\.captcha-sdk\.awswaf\.com$/i.test(script.hostname)
        || script.pathname !== '/' + script.hostname.split('.')[0] + '/jsapi.js') fail('unsupported');
    return { ...task, wafType: 'widget', jsapiScript: script.href };
  }
  for (const [key, subdomain, file] of [['captchaScript', 'captcha', 'captcha.js'],
    ['challengeScript', 'token', 'challenge.js']]) {
    if (!challenge[key]) continue;
    let script;
    try { script = new URL(challenge[key]); } catch { fail('unsupported'); }
    if (script.protocol !== 'https:' || script.username || script.password || script.port
        || script.search || script.hash || script.href.length > 2048
        || !script.hostname.endsWith(`.${subdomain}.awswaf.com`)
        || !script.pathname.endsWith('/' + file)) fail('unsupported');
    task[key] = script.href;
  }
  return task;
}

// No automatic createTask retries: a lost response may already have incurred a charge.
export async function solveAmazonCaptcha(challenge, apiKey, {
  fetchImpl = fetch, sleep = delay, now = Date.now, onStage = () => {},
} = {}) {
  if (typeof apiKey !== 'string' || !/^[a-f0-9]{32}$/i.test(apiKey)) fail('unconfigured');
  const task = amazonTask(challenge);
  const deadline = now() + 120000;
  const post = async (method, data) => {
    const remaining = deadline - now();
    if (remaining <= 0) fail('timeout');
    try {
      onStage(method === 'createTask' ? 'create_task' : 'poll_task');
      const response = await fetchImpl(`https://api.anti-captcha.com/${method}`, {
        method: 'POST', redirect: 'error', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ clientKey: apiKey, ...data }),
        signal: AbortSignal.timeout(Math.min(15000, remaining)),
      });
      if (!response.ok) fail('provider_error');
      // Bound untrusted replies before parsing. Never surface provider descriptions.
      const reader = response.body.getReader();
      const chunks = []; let size = 0;
      try {
        while (true) {
          const { done, value } = await reader.read();
          if (done) break;
          size += value.byteLength;
          if (size > 65536) fail('provider_error');
          chunks.push(Buffer.from(value));
        }
      } finally { await reader.cancel().catch(() => {}); }
      const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
      if (!result || result.errorId !== 0) {
        const known = providerErrors.get(result?.errorCode);
        fail(known && result.errorId === known[0] ? known[1] : 'provider_error');
      }
      return result;
    } catch (error) {
      if (error instanceof CaptchaError) throw error;
      fail(error?.name === 'TimeoutError' || error?.name === 'AbortError' ? 'timeout' : 'provider_error');
    }
  };
  const created = await post('createTask', { task });
  if (!Number.isSafeInteger(created.taskId) || created.taskId <= 0) fail('provider_error');
  for (let poll = 0; poll < 24; poll++) {
    if (deadline - now() <= 5000) fail('timeout');
    await sleep(5000);
    const result = await post('getTaskResult', { taskId: created.taskId });
    if (result.status === 'processing') continue;
    if (result.status !== 'ready' || !textValue(result.solution?.token)
        || /[\s;,]/.test(result.solution.token)) fail('provider_error');
    return result.solution.token;
  }
  fail('timeout');
}
