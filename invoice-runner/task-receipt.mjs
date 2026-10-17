import { CAPTCHA_STATUSES, CAPTCHA_STAGES, CAPTCHA_STALE_REASONS } from './booking-captcha.mjs';
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
  if (job.input.portal === 'booking') {
    for (const key of ['aws_waf_allowed', 'aws_waf_blocked'])
      if (Number.isSafeInteger(diagnostic?.[key]) && diagnostic[key] >= 0 && diagnostic[key] <= 10000)
        receipt[key] = diagnostic[key];
    if (['blocked', 'disabled', 'unavailable'].includes(diagnostic?.loopback_permission))
      receipt.loopback_permission = diagnostic.loopback_permission;
    if (CAPTCHA_STATUSES.includes(diagnostic?.captcha_status)) receipt.captcha_status = diagnostic.captcha_status;
    if (CAPTCHA_STAGES.includes(diagnostic?.captcha_stage)) receipt.captcha_stage = diagnostic.captcha_stage;
    if (CAPTCHA_STALE_REASONS.includes(diagnostic?.captcha_stale_reason)) receipt.captcha_stale_reason = diagnostic.captcha_stale_reason;
  }
  if (job.input.action === 'verify') {
    receipt.document_count = Array.isArray(result.documents) ? Math.min(result.documents.length, 100) : 0;
    const trace = result.collection_trace;
    if (job.input.portal === 'hostelworld' && trace?.hostelworld) {
      const h = trace.hostelworld;
      const safe = {};
      if (['account_check','invoice_navigation','invoices_visible'].includes(h.stage)) safe.stage = h.stage;
      for (const key of ['authenticated','waited','completed','expected_property','link_page','ready',
        'confirmation_offered','confirmation_recognized','confirmation_blocked','confirmation_clicked'])
        if (typeof h[key] === 'boolean') safe[key] = h[key];
      if ([0,1,2].includes(h.property_count)) safe.property_count = h.property_count;
      receipt.hostelworld = safe;
    }
    if (job.input.portal === 'hostelworld' && trace?.table) {
      const safe = {};
      for (const [key,max] of Object.entries({tables:3,headers:21,rows:401,next_links:3,max_next_links:3})) {
        const value = trace.table[key];
        if (Number.isInteger(value) && value >= 0 && value <= max) safe[key] = value;
      }
      receipt.table = safe;
    }
    if (job.input.portal === 'hostelworld' && trace?.pdf_identity) {
      const safe = {};
      for (const key of ['issuer_verified','property_label_found','property_verified','property_zero_padded'])
        if (typeof trace.pdf_identity[key] === 'boolean') safe[key] = trace.pdf_identity[key];
      receipt.pdf_identity = safe;
    }
    if (job.input.portal === 'hostelworld' && ['verified','missing_date','unsupported_format',
      'ambiguous_numeric','invalid_calendar','conflicting_dates'].includes(trace?.pdf_date?.reason)) {
      receipt.pdf_date = {reason:trace.pdf_date.reason};
      for (const key of ['labels','candidates'])
        if (Number.isInteger(trace.pdf_date[key]) && trace.pdf_date[key] >= 0 && trace.pdf_date[key] <= 3)
          receipt.pdf_date[key] = trace.pdf_date[key];
      const shape = trace.pdf_date.shape;
      if (trace.pdf_date.reason === 'unsupported_format' && Array.isArray(shape) && shape.length <= 20
          && shape.every(token => typeof token === 'string' && /^(?:digits_[1-5]|letters_(?:[1-9]|10)|space|month|ordinal|weekday|slash|dash|dot|comma|colon|other)$/.test(token)))
        receipt.pdf_date.shape = shape;
    }
    if (job.input.portal === 'hostelworld'
        && ['table_shape','headers','headers_changed','repeated_page','row_shape','pdf_link'].includes(trace?.table_failure?.reason)) {
      receipt.table_failure = {reason:trace.table_failure.reason};
      if (Number.isInteger(trace.table_failure.row_index) && trace.table_failure.row_index >= 1
          && trace.table_failure.row_index <= 400) receipt.table_failure.row_index = trace.table_failure.row_index;
    }
    if (job.input.portal === 'hostelworld' && Number.isInteger(trace?.scanned_documents)
        && trace.scanned_documents >= 0 && trace.scanned_documents <= 401)
      receipt.scanned_documents = trace.scanned_documents;
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
