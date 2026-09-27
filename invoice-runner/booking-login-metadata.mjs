// A deliberately small, value-free view of Booking's identifier request.
// This report is stored only on the Windows agent; never transmit the HAR,
// postData, full headers or URL to the Hub.
const LOGIN_PATH = '/account/sign-in/login_name';
const HOST = 'account.booking.com';
const BODY_KEYS = ['as_token', 'client_id', 'login_name', 'op_token', 'scope', 'state'];
const MIME = new Set(['application/json', 'application/x-www-form-urlencoded',
  'application/problem+json', 'text/html', 'text/plain']);
const FETCH = {
  site: new Set(['none', 'same-origin', 'same-site', 'cross-site']),
  mode: new Set(['navigate', 'cors', 'no-cors', 'same-origin', 'websocket']),
  dest: new Set(['empty', 'document', 'iframe', 'script', 'style', 'image', 'font', 'worker', 'serviceworker']),
  user: new Set(['?1']),
};

function header(headers, name) {
  if (Array.isArray(headers)) {
    const found = headers.find(item => typeof item?.name === 'string' && item.name.toLowerCase() === name);
    return typeof found?.value === 'string' ? found.value : null;
  }
  if (!headers || typeof headers !== 'object') return null;
  const found = Object.entries(headers).find(([key]) => key.toLowerCase() === name);
  return typeof found?.[1] === 'string' ? found[1] : null;
}

function mime(headers, fallback) {
  const value = header(headers, 'content-type') || fallback;
  const type = typeof value === 'string' ? value.split(';', 1)[0].trim().toLowerCase() : '';
  return MIME.has(type) ? type : 'other';
}

function bookingHost(value) {
  if (typeof value !== 'string') return null;
  try {
    const url = new URL(value);
    return url.protocol === 'https:' && url.hostname === HOST ? HOST : null;
  } catch { return null; }
}

function category(headers, field, allowed) {
  const value = header(headers, `sec-fetch-${field}`);
  if (value === null) return 'absent';
  return allowed.has(value.toLowerCase()) ? value.toLowerCase() : 'other';
}

function type(value) {
  if (value === null) return 'null';
  if (Array.isArray(value)) return 'array';
  return typeof value === 'object' ? 'object'
    : ['string', 'number', 'boolean'].includes(typeof value) ? typeof value : 'other';
}

function bodyKeys(data, requestMime) {
  let values;
  const raw = typeof data === 'string' ? data : data?.text;
  if (typeof raw === 'string' && raw.length <= 65536) {
    try {
      if (requestMime === 'application/json') {
        const parsed = JSON.parse(raw);
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) values = parsed;
      } else if (requestMime === 'application/x-www-form-urlencoded') {
        const parsed = new URLSearchParams(raw);
        values = Object.fromEntries(BODY_KEYS.filter(key => parsed.has(key)).map(key => [key, '']));
      }
    } catch { /* malformed input remains unavailable, never serialized */ }
  } else if (Array.isArray(data?.params) && requestMime === 'application/x-www-form-urlencoded') {
    // HAR exports sometimes omit text. Inspect only the allowlisted names.
    values = Object.fromEntries(BODY_KEYS.filter(key => data.params.some(item => item?.name === key))
      .map(key => [key, '']));
  }
  if (!values) return 'unavailable';
  return Object.fromEntries(BODY_KEYS.map(key => [key, Object.hasOwn(values, key) ? type(values[key]) : 'absent']));
}

export function sanitizeBookingLoginNameEvidence({ url, method, status, resourceType,
  requestHeaders, responseHeaders, postData, responseMime } = {}) {
  try {
    const target = new URL(url);
    if (target.protocol !== 'https:' || target.hostname !== HOST || target.pathname !== LOGIN_PATH) return null;
  } catch { return null; }
  if (!Number.isInteger(status) || status < 100 || status > 599) return null;
  const requestMime = mime(requestHeaders, postData?.mimeType);
  return {
    version: 1,
    method: ['GET', 'POST'].includes(method) ? method : 'other',
    status,
    resource_type: ['document', 'xhr', 'fetch'].includes(resourceType) ? resourceType : 'other',
    request_content_type: requestMime,
    response_content_type: mime(responseHeaders, responseMime),
    origin_host: bookingHost(header(requestHeaders, 'origin')),
    referer_host: bookingHost(header(requestHeaders, 'referer')),
    body_keys: bodyKeys(postData, requestMime),
    sec_fetch: Object.fromEntries(Object.entries(FETCH)
      .map(([field, allowed]) => [field, category(requestHeaders, field, allowed)])),
    x_requested_with_present: header(requestHeaders, 'x-requested-with') !== null,
  };
}
