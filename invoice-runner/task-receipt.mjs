import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';

const loginCodes = new Set(['ok', 'session_active', 'needs_auth', 'human_verification',
  'connector_unconfigured', 'portal_changed', 'browser_unavailable', 'document_limit',
  'auth_unconfigured', 'auth_timeout', 'auth_invalid', 'account_mismatch',
  'invalid_document', 'network_error', 'worker_failed', 'worker_unavailable']);

// A local receipt is a small summary, never a copy of the job or portal result.
export async function writeTaskReceipt(root, job, result, reply, now = () => new Date()) {
  if (reply?.accepted !== true || !Number.isSafeInteger(job?.id) || job.id < 1
      || job?.input?.action !== 'login'
      || !loginCodes.has(result?.code)
      || !['completed', 'failed', 'needs_auth', 'retry', 'cancelled', 'waiting_auth'].includes(reply?.state)) {
    throw new Error('invalid task receipt');
  }
  const receipt = {
    task_id: job.id,
    action: job.input.action,
    code: result.code,
    state: reply.state,
    finished_at: now().toISOString(),
  };
  const filename = path.join(root, `task-${job.id}-receipt.json`);
  const temporary = `${filename}.${crypto.randomBytes(8).toString('hex')}.tmp`;
  try {
    await fs.writeFile(temporary, JSON.stringify(receipt) + '\n', { mode: 0o600, flag: 'wx' });
    await fs.rename(temporary, filename);
  } finally {
    await fs.rm(temporary, { force: true }).catch(() => {});
  }
}
