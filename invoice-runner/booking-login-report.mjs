import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';

const KEYS = ['as_token', 'client_id', 'login_name', 'op_token', 'scope', 'state'];
const MIME = ['application/json', 'application/x-www-form-urlencoded',
  'application/problem+json', 'text/html', 'text/plain', 'other'];
const CATEGORIES = {
  site: ['absent', 'none', 'same-origin', 'same-site', 'cross-site', 'other'],
  mode: ['absent', 'navigate', 'cors', 'no-cors', 'same-origin', 'websocket', 'other'],
  dest: ['absent', 'empty', 'document', 'iframe', 'script', 'style', 'image', 'font', 'worker', 'serviceworker', 'other'],
  user: ['absent', '?1', 'other'],
};
const value = (candidate, allowed) => {
  if (!allowed.includes(candidate)) throw new Error('invalid_login_metadata');
  return candidate;
};
const exact = (candidate, keys) => {
  if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)
    || Object.keys(candidate).sort().join('|') !== [...keys].sort().join('|')) {
    throw new Error('invalid_login_metadata');
  }
  return candidate;
};

// Reconstruct the entire report from known fields before touching disk, so
// unknown fields can never be smuggled into a private file or future upload.
export function validatedBookingLoginMetadata(metadata) {
  exact(metadata, ['version', 'requests', 'challenge_visible_before', 'challenge_visible_after']);
  if (metadata.version !== 1 || !Array.isArray(metadata.requests) || metadata.requests.length > 3)
    throw new Error('invalid_login_metadata');
  const requestKeys = ['version', 'method', 'status', 'resource_type', 'request_content_type',
    'response_content_type', 'origin_host', 'referer_host', 'body_keys', 'sec_fetch', 'x_requested_with_present'];
  const requests = metadata.requests.map(item => {
    exact(item, requestKeys);
    if (item.version !== 1 || !Number.isInteger(item.status) || item.status < 100 || item.status > 599)
      throw new Error('invalid_login_metadata');
    const body = item.body_keys === 'unavailable' ? 'unavailable'
      : Object.fromEntries(KEYS.map(key => {
        exact(item.body_keys, KEYS);
        return [key, value(item.body_keys[key], ['absent', 'null', 'array', 'object',
          'string', 'number', 'boolean', 'other'])];
      }));
    exact(item.sec_fetch, Object.keys(CATEGORIES));
    if (typeof item.x_requested_with_present !== 'boolean') throw new Error('invalid_login_metadata');
    return {
      version: 1,
      method: value(item.method, ['GET', 'POST', 'other']),
      status: item.status,
      resource_type: value(item.resource_type, ['document', 'xhr', 'fetch', 'other']),
      request_content_type: value(item.request_content_type, MIME),
      response_content_type: value(item.response_content_type, MIME),
      origin_host: value(item.origin_host, [null, 'account.booking.com']),
      referer_host: value(item.referer_host, [null, 'account.booking.com']),
      body_keys: body,
      sec_fetch: Object.fromEntries(Object.entries(CATEGORIES)
        .map(([key, allowed]) => [key, value(item.sec_fetch[key], allowed)])),
      x_requested_with_present: item.x_requested_with_present,
    };
  });
  return { version: 1, requests,
    challenge_visible_before: value(metadata.challenge_visible_before, [null, true, false]),
    challenge_visible_after: value(metadata.challenge_visible_after, [null, true, false]) };
}

export async function writeBookingLoginReport(root, taskId, metadata) {
  if (!Number.isSafeInteger(taskId) || taskId < 1) throw new Error('invalid_login_metadata');
  const clean = validatedBookingLoginMetadata(metadata);
  const filename = path.join(root, `task-${taskId}-booking-login-metadata.json`);
  const temporary = `${filename}.${crypto.randomBytes(8).toString('hex')}.tmp`;
  try {
    await fs.writeFile(temporary, JSON.stringify(clean) + '\n', { mode: 0o600, flag: 'wx' });
    await fs.rename(temporary, filename);
  } finally { await fs.rm(temporary, { force: true }).catch(() => {}); }
  return filename;
}

export function removePrivateBookingLoginMetadata(result) {
  if (!result?.diagnostic || !Object.hasOwn(result.diagnostic, 'private_login_metadata')) return null;
  const metadata = result.diagnostic.private_login_metadata;
  delete result.diagnostic.private_login_metadata;
  return metadata;
}
