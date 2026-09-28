import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';

const loginCodes = new Set(['ok', 'session_active', 'needs_auth', 'human_verification',
  'connector_unconfigured', 'portal_changed', 'browser_unavailable', 'document_limit',
  'auth_unconfigured', 'auth_timeout', 'auth_invalid', 'account_mismatch',
  'invalid_document', 'network_error', 'worker_failed', 'worker_unavailable', 'verification_sample_missing']);

// A local receipt is a small summary, never a copy of the job or portal result.
export async function writeTaskReceipt(root, job, result, reply, now = () => new Date(), loginMetadataSaved = null) {
  if (reply?.accepted !== true || !Number.isSafeInteger(job?.id) || job.id < 1
      || !['login', 'verify'].includes(job?.input?.action)
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
  const diagnostic = result.diagnostic;
  if (job.input.portal === 'booking' && typeof diagnostic?.cookie_consent_rejected === 'boolean') {
    receipt.cookie_consent_rejected = diagnostic.cookie_consent_rejected;
  }
  if (job.input.action === 'verify') {
    receipt.document_count = Array.isArray(result.documents) ? Math.min(result.documents.length, 100) : 0;
    const trace = result.collection_trace;
    if (['setup', 'session', 'navigation', 'table', 'pdf_download', 'pagination', 'complete'].includes(trace?.stage)) {
      receipt.stage = trace.stage;
      const d = trace.download;
      if (Number.isInteger(d?.status) && d.status >= 100 && d.status <= 599
          && typeof d.signature === 'boolean' && typeof d.complete === 'boolean'
          && Number.isSafeInteger(d.bytes) && d.bytes >= 0 && d.bytes <= 21 * 1024 * 1024) {
        receipt.download = { status: d.status, signature: d.signature, complete: d.complete, bytes: d.bytes };
      }
    }
  }
  if (job.input.portal === 'booking' && diagnostic && !Array.isArray(diagnostic)
      && Object.hasOwn(diagnostic, 'sms_prompted') && Object.hasOwn(diagnostic, 'sms_submitted')
      && typeof diagnostic.sms_prompted === 'boolean' && typeof diagnostic.sms_submitted === 'boolean'
      && (!diagnostic.sms_submitted || diagnostic.sms_prompted)) {
    receipt.sms_prompted = diagnostic.sms_prompted;
    receipt.sms_submitted = diagnostic.sms_submitted;
  }
  if (job.input.portal === 'booking' && typeof loginMetadataSaved === 'boolean') {
    receipt.booking_login_metadata_saved = loginMetadataSaved;
  }
  const filename = path.join(root, `task-${job.id}-receipt.json`);
  const temporary = `${filename}.${crypto.randomBytes(8).toString('hex')}.tmp`;
  try {
    await fs.writeFile(temporary, JSON.stringify(receipt) + '\n', { mode: 0o600, flag: 'wx' });
    await fs.rename(temporary, filename);
  } finally {
    await fs.rm(temporary, { force: true }).catch(() => {});
  }
}
