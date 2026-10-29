// Offline only: read a local, confidential HAR and emit the same restricted
// metadata as the Windows login diagnostic. Never copy the HAR into this repo.
import fs from 'node:fs/promises';
import { sanitizeBookingLoginNameEvidence } from './booking-login-metadata.mjs';
import { validatedBookingLoginMetadata } from './booking-login-report.mjs';

try {
  if (process.argv.length !== 3) throw new Error('invalid_har');
  const file = process.argv[2];
  if ((await fs.stat(file)).size > 32 * 1024 * 1024) throw new Error('invalid_har');
  const har = JSON.parse(await fs.readFile(file, 'utf8'));
  const requests = [];
  for (const entry of har?.log?.entries || []) {
    if (requests.length === 3) break;
    const safe = sanitizeBookingLoginNameEvidence({
      url: entry?.request?.url, method: entry?.request?.method,
      status: entry?.response?.status, resourceType: entry?._resourceType,
      requestHeaders: entry?.request?.headers, responseHeaders: entry?.response?.headers,
      postData: entry?.request?.postData, responseMime: entry?.response?.content?.mimeType,
    });
    if (safe) requests.push(safe);
  }
  const report = validatedBookingLoginMetadata({ version: 1, requests,
    challenge_visible_before: null, challenge_visible_after: null });
  process.stdout.write(JSON.stringify(report) + '\n');
} catch {
  process.stderr.write('invalid_har\n');
  process.exitCode = 1;
}
