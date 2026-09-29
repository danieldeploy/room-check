import { setTimeout as delay } from 'node:timers/promises';

export class CaptchaError extends Error {}
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
  fetchImpl = fetch, sleep = delay, now = Date.now,
} = {}) {
  if (typeof apiKey !== 'string' || !/^[a-f0-9]{32}$/i.test(apiKey)) fail('unconfigured');
  const task = amazonTask(challenge);
  const deadline = now() + 120000;
  const post = async (method, data) => {
    const remaining = deadline - now();
    if (remaining <= 0) fail('timeout');
    try {
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
      if (!result || result.errorId !== 0) fail('provider_error');
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
