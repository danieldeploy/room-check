import test from 'node:test';
import assert from 'node:assert/strict';
import { AgentClient, documentParts, validateEndpoint } from '../agent-client.mjs';

const endpoint = 'https://hub.example.test/invoice-agent.php';
const token = 'a'.repeat(64);
const response = (value, status = 200) => new Response(JSON.stringify(value), { status, headers: { 'content-type': 'application/json' } });

test('agent requires HTTPS, separate token, no credentials or query in URL', () => {
  assert.equal(validateEndpoint(endpoint), endpoint);
  for (const url of ['http://hub.test/invoice-agent.php', 'https://user:pass@hub.test/invoice-agent.php',
    `${endpoint}?token=secret`, `${endpoint}#secret`, 'https://hub.test/login.php']) assert.throws(() => validateEndpoint(url));
  assert.throws(() => new AgentClient(endpoint, 'weak'));
});

test('transport authenticates by header and never follows a redirect', async () => {
  let calls = 0;
  const client = new AgentClient(endpoint, token, { fetcher: async (url, options) => {
    calls++;
    assert.equal(url, endpoint); assert.equal(options.redirect, 'manual');
    assert.equal(options.headers.authorization, `Bearer ${token}`);
    assert.equal(JSON.parse(options.body).action, 'ping');
    return new Response(null, { status: 302, headers: { location: 'https://elsewhere.test/' } });
  } });
  await assert.rejects(client.request({ action: 'ping' }), /redirect_rejected/);
  assert.equal(calls, 1);
});

test('lost response retries the identical claim identifier and chunk payload', async () => {
  const bodies = []; let sleeps = 0;
  const client = new AgentClient(endpoint, token, {
    sleeper: async () => { sleeps++; },
    fetcher: async (_url, options) => {
      bodies.push(options.body);
      if (bodies.length === 1) throw new Error('sensitive transport detail');
      return response({ job: null });
    },
  });
  await client.request({ action: 'claim', claim_id: 'b'.repeat(32) });
  assert.equal(bodies.length, 2); assert.equal(bodies[0], bodies[1]); assert.equal(sleeps, 1);
});

test('revoked and stale lease responses are terminal and do not expose raw errors', async () => {
  for (const [status, error] of [[403, 'forbidden'], [409, 'lease_expired']]) {
    let calls = 0;
    const client = new AgentClient(endpoint, token, { fetcher: async () => { calls++; return response({ error, detail: 'secret' }, status); } });
    await assert.rejects(client.request({ action: 'pulse' }), new RegExp(error));
    assert.equal(calls, 1);
  }
});

test('worker contention retries; authentication failures never retry', async () => {
  let calls = 0;
  const client = new AgentClient(endpoint, token, { sleeper: async () => {}, fetcher: async () => {
    calls++; return calls === 1 ? response({ error: 'worker_busy' }, 409) : response({ accepted: true });
  } });
  assert.equal((await client.request({ action: 'ping' })).accepted, true); assert.equal(calls, 2);
});

test('UTF-8 account data remains intact across network chunks', async () => {
  const bytes = Buffer.from(JSON.stringify({ label: 'Faturas — João çã' }));
  const body = new ReadableStream({ start(controller) { for (const byte of bytes) controller.enqueue(Uint8Array.of(byte)); controller.close(); } });
  const client = new AgentClient(endpoint, token, { fetcher: async () => new Response(body) });
  assert.equal((await client.request({ action: 'ping' })).label, 'Faturas — João çã');
});

test('bounded responses and requests reject excess before sensitive data is processed', async () => {
  let calls = 0;
  const client = new AgentClient(endpoint, token, { fetcher: async () => { calls++; return new Response('x'.repeat(2097153)); } });
  await assert.rejects(client.request({ data: 'x'.repeat(524289) }), /request_too_large/);
  assert.equal(calls, 0);
  await assert.rejects(client.request({ action: 'ping' }), /response_too_large/);
});

test('document transfer separates bytes and metadata and supports maximum-size PDF', () => {
  const pdf = Buffer.from('%PDF-1.7 fixture');
  const doc = documentParts({ number: '123', pdf: pdf.toString('base64') });
  assert.deepEqual(doc.metadata, { number: '123' }); assert.deepEqual(doc.bytes, pdf);
  assert.throws(() => documentParts({ pdf: 'invalid!' }), /invalid_document/);
  const large = Buffer.alloc(20971520, 42);
  assert.equal(documentParts({ content: large.toString('base64') }).bytes.length, large.length);
});
