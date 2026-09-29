import assert from 'node:assert/strict';
import { test } from 'node:test';
import os from 'node:os';
import path from 'node:path';
import fs from 'node:fs/promises';
import { writeTaskReceipt } from '../task-receipt.mjs';

test('accepted completion stores only a private atomic receipt', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'invoice-receipt-'));
  const secret = 'sensitive-' + Math.random();
  try {
    const job = { id: 42, input: { action: 'login', portal: 'booking', credentials: { password: secret } } };
    const result = { code: 'portal_changed', diagnostic: { location: secret,
      sms_prompted: true, sms_submitted: false, sms_code: secret,
      cookie_consent_rejected: true, cookie_value: secret,
      captcha_status: 'provider_widget_failed', captcha_stage: 'poll_task' }, session: { cookies: [secret] } };
    const reply = { accepted: true, state: 'failed', value: secret };
    await writeTaskReceipt(root, job, result, reply, () => new Date('2026-09-24T08:15:00.000Z'));
    const filename = path.join(root, 'task-42-receipt.json');
    const raw = await fs.readFile(filename, 'utf8');
    assert.deepEqual(JSON.parse(raw), { task_id: 42, action: 'login', code: 'portal_changed',
      state: 'failed', finished_at: '2026-09-24T08:15:00.000Z',
      cookie_consent_rejected: true, sms_prompted: true, sms_submitted: false,
      captcha_status: 'provider_widget_failed', captcha_stage: 'poll_task' });
    assert.equal(raw.includes(secret), false);
    assert.deepEqual(await fs.readdir(root), ['task-42-receipt.json']);
    if (process.platform !== 'win32') assert.equal((await fs.stat(filename)).mode & 0o077, 0);
    await assert.rejects(writeTaskReceipt(root, { ...job, id: 43 }, result, { accepted: false, state: 'failed' }));
    await assert.rejects(fs.access(path.join(root, 'task-43-receipt.json')));
    await assert.rejects(writeTaskReceipt(root, { ...job, id: 44 }, { code: secret }, reply));
    await assert.rejects(fs.access(path.join(root, 'task-44-receipt.json')));
    await writeTaskReceipt(root, { ...job, id: 45 }, { code: 'session_active',
      diagnostic: { sms_prompted: false, sms_submitted: false } },
      { accepted: true, state: 'completed' }, () => new Date('2026-09-24T08:16:00.000Z'));
    const sessionReceipt = JSON.parse(await fs.readFile(path.join(root, 'task-45-receipt.json'), 'utf8'));
    assert.equal(sessionReceipt.code, 'session_active');
    assert.equal(sessionReceipt.sms_prompted, false);
    assert.equal(sessionReceipt.sms_submitted, false);
    await writeTaskReceipt(root, { ...job, id: 46 }, { code: 'human_verification',
      diagnostic: { sms_prompted: 'true', sms_submitted: false, sms_code: secret,
        cookie_consent_rejected: secret, captcha_status: secret, captcha_stage: secret, captcha_stale_reason: secret } },
      { accepted: true, state: 'needs_auth' }, () => new Date('2026-09-24T08:17:00.000Z'));
    const invalidSms = await fs.readFile(path.join(root, 'task-46-receipt.json'), 'utf8');
    assert.equal(JSON.parse(invalidSms).state, 'needs_auth');
    assert.equal(Object.hasOwn(JSON.parse(invalidSms), 'sms_prompted'), false);
    assert.equal(Object.hasOwn(JSON.parse(invalidSms), 'cookie_consent_rejected'), false);
    assert.equal(Object.hasOwn(JSON.parse(invalidSms), 'captcha_status'), false);
    assert.equal(Object.hasOwn(JSON.parse(invalidSms), 'captcha_stage'), false);
    assert.equal(Object.hasOwn(JSON.parse(invalidSms), 'captcha_stale_reason'), false);
    assert.equal(invalidSms.includes(secret), false);
    await writeTaskReceipt(root, { ...job, id: 48 }, { code: 'ok',
      diagnostic: { sms_prompted: true, sms_submitted: true } },
      { accepted: true, state: 'completed' });
    assert.equal(JSON.parse(await fs.readFile(path.join(root, 'task-48-receipt.json'), 'utf8')).sms_submitted, true);
    await writeTaskReceipt(root, { ...job, id: 49 }, { code: 'ok',
      diagnostic: { sms_prompted: false, sms_submitted: true } },
      { accepted: true, state: 'completed' });
    assert.equal(Object.hasOwn(JSON.parse(await fs.readFile(path.join(root, 'task-49-receipt.json'), 'utf8')),
      'sms_submitted'), false);
    await writeTaskReceipt(root, { ...job, id: 50 }, { code: 'portal_changed', diagnostic: {} },
      { accepted: true, state: 'failed' }, () => new Date('2026-09-24T08:20:00.000Z'), false);
    const metadataFailure = JSON.parse(await fs.readFile(path.join(root, 'task-50-receipt.json'), 'utf8'));
    assert.equal(metadataFailure.booking_login_metadata_saved, false);
    await writeTaskReceipt(root, { ...job, id: 51 }, { code: 'human_verification', diagnostic: {} },
      { accepted: true, state: 'needs_auth' }, () => new Date('2026-09-24T08:21:00.000Z'), true);
    assert.equal(JSON.parse(await fs.readFile(path.join(root, 'task-51-receipt.json'), 'utf8'))
      .booking_login_metadata_saved, true);
    await writeTaskReceipt(root, { ...job, id: 53 }, { code: 'human_verification',
      diagnostic: { captcha_status: 'stale', captcha_stage: 'validate', captcha_stale_reason: 'widget_expired' } },
      { accepted: true, state: 'needs_auth' });
    assert.equal(JSON.parse(await fs.readFile(path.join(root, 'task-53-receipt.json'), 'utf8')).captcha_stale_reason, 'widget_expired');
    await assert.rejects(writeTaskReceipt(root, { ...job, id: 47, input: { action: 'collect' } }, result, reply));
    await assert.rejects(fs.access(path.join(root, 'task-47-receipt.json')));
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});

test('verification receipts distinguish runner failure from server rejection without invoice values', async () => {
  const root = await fs.mkdtemp(path.join(os.tmpdir(), 'invoice-receipt-'));
  try {
    const job = { id: 52, input: { action: 'verify', portal: 'booking' } };
    const result = { code: 'ok', documents: [{ content: 'PRIVATE_PDF', number: 'PRIVATE_NUMBER' }],
      collection_trace: { stage: 'complete', url: 'PRIVATE_URL', download: { status: 200, signature: true, complete: true, bytes: 100, body: 'PRIVATE_BODY' } } };
    await writeTaskReceipt(root, job, result, { accepted: true, state: 'failed' });
    const raw = await fs.readFile(path.join(root, 'task-52-receipt.json'), 'utf8');
    const receipt = JSON.parse(raw);
    assert.equal(receipt.code, 'ok'); assert.equal(receipt.state, 'failed');
    assert.equal(receipt.document_count, 1); assert.equal(receipt.stage, 'complete');
    assert.deepEqual(receipt.download, { status: 200, signature: true, complete: true, bytes: 100 });
    assert.equal(raw.includes('PRIVATE_'), false);
  } finally { await fs.rm(root, { recursive: true, force: true }); }
});
