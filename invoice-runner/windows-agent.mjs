import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';
import { spawn, execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { setTimeout as delay } from 'node:timers/promises';
import { AgentClient, AgentError, documentParts } from './agent-client.mjs';
import { assertPrivateDirectory } from './private-storage.mjs';
import { writeTaskReceipt } from './task-receipt.mjs';

const runner = fileURLToPath(new URL('./runner.mjs', import.meta.url));
let child; let stopping = false;
const stop = () => { stopping = true; void terminateChild(); };
process.once('SIGINT', stop); process.once('SIGTERM', stop);

async function terminateChild() {
  const processToStop = child;
  if (!processToStop || processToStop.exitCode !== null) return;
  if (process.platform === 'win32') {
    await promisify(execFile)('taskkill.exe', ['/PID', String(processToStop.pid), '/T', '/F'], { windowsHide: true }).catch(() => {});
  } else {
    processToStop.kill('SIGTERM');
    const timer = setTimeout(() => { if (processToStop.exitCode === null) processToStop.kill('SIGKILL'); }, 5000);
    timer.unref();
  }
}

function runChild(input) {
  child = spawn(process.execPath, [runner], { cwd: path.dirname(runner), stdio: ['pipe', 'pipe', 'ignore'], windowsHide: true });
  const current = child;
  let size = 0; const chunks = [];
  const promise = new Promise((resolve, reject) => {
    current.stdout.on('data', chunk => {
      size += chunk.length;
      if (size > 40 * 1024 * 1024) { void terminateChild(); reject(new AgentError('invalid_document')); }
      else chunks.push(chunk);
    });
    current.once('error', () => reject(new AgentError('worker_unavailable')));
    current.once('close', () => {
      if (child === current) child = null;
      try {
        const result = JSON.parse(Buffer.concat(chunks).toString('utf8'));
        if (!result || typeof result !== 'object') throw new Error();
        resolve(result);
      } catch { reject(new AgentError('worker_failed')); }
    });
    current.stdin.on('error', () => {});
    current.stdin.end(JSON.stringify(input));
  });
  return promise;
}

async function privateWrite(file, data) {
  const temporary = `${file}.${crypto.randomBytes(8).toString('hex')}.tmp`;
  await fs.writeFile(temporary, data, { mode: 0o600, flag: 'wx' });
  await fs.rename(temporary, file);
}

async function probe(client, root, runtime) {
  const result = await runChild({ action: 'preflight', privateDir: root, runtime });
  const code = result.code === 'ok' ? 'ok' : 'browser_unavailable';
  await client.request({ action: 'probe', code });
  if (code !== 'ok') throw new AgentError(code);
}

async function execute(client, root, runtime, job) {
  if (!Number.isSafeInteger(job.id) || !/^[a-f0-9]{64}$/.test(job.lease) || !job.input
      || !Number.isSafeInteger(job.expires) || !Number.isSafeInteger(job.server_time)) throw new AgentError('invalid_response');
  // Monotonic deadline: an incorrect or adjusted Windows wall clock cannot prolong a lease.
  const budget = Math.min(1100, job.expires - job.server_time - 30);
  if (budget < 30) throw new AgentError('lease_expired');
  const deadline = performance.now() + budget * 1000;
  const identity = { task_id: job.id, lease: job.lease };
  const request = async payload => {
    if (stopping || performance.now() >= deadline) throw new AgentError('lease_expired');
    const reply = await client.request({ ...identity, ...payload });
    if (reply.mode && reply.mode !== 'windows') throw new AgentError('lease_expired');
    return reply;
  };
  const exchange = await fs.mkdtemp(path.join(root, 'exchange-'));
  let completed = false; let result; let runnerError; let leaseError;
  const processResult = runChild({ ...job.input, privateDir: root, exchangeDir: exchange, runtime })
    .then(value => { result = value; }, error => { runnerError = error; })
    .finally(() => { completed = true; });
  let challenge; let nextPulse = 0;
  try {
    while (!completed) {
      if (stopping || performance.now() >= deadline) throw new AgentError('lease_expired');
      const raw = await fs.readFile(path.join(exchange, 'challenge.json'), 'utf8').catch(() => null);
      if (raw) {
        const current = JSON.parse(raw);
        if (!/^[a-f0-9]{32}$/.test(current.id)) throw new AgentError('auth_invalid');
        if (!challenge || !challenge.delivered) {
          const reply = await request({ action: 'challenge', challenge: current });
          challenge ??= { id: current.id, delivered: false };
          if (challenge.id !== current.id || reply.ready !== true) throw new AgentError('auth_invalid');
          if (!await fs.stat(path.join(exchange, `ready-${current.id}`)).catch(() => null)) {
            await privateWrite(path.join(exchange, `ready-${current.id}`), 'ready');
          }
          if (reply.value !== null && reply.value !== undefined) {
            if (!/^\d{6}$/.test(reply.value)) throw new AgentError('auth_invalid');
            await privateWrite(path.join(exchange, `response-${current.id}.json`), JSON.stringify({ value: reply.value }));
            await request({ action: 'challenge', challenge: current, ack: true });
            challenge.delivered = true;
          }
          nextPulse = performance.now() + 20000;
        }
      }
      if (performance.now() >= nextPulse) {
        await request({ action: 'pulse' }); nextPulse = performance.now() + 20000;
      }
      await delay(challenge && !challenge.delivered ? 500 : 1000);
    }
    await processResult;
    if (runnerError) throw runnerError;
    const documents = result.documents ?? [];
    if (!Array.isArray(documents) || documents.length > 100) throw new AgentError('invalid_document');
    let total = 0; const ids = [];
    if (['ok', 'no_invoices'].includes(result.code)) {
      for (let id = 0; id < documents.length; id++) {
        const { bytes, metadata } = documentParts(documents[id]); total += bytes.length;
        if (total > 20971520) throw new AgentError('document_limit');
        const sha256 = crypto.createHash('sha256').update(bytes).digest('hex');
        for (let offset = 0; offset < bytes.length; offset += 196608) {
          const chunk = bytes.subarray(offset, offset + 196608);
          const reply = await request({ action: 'upload', id, offset, size: bytes.length, sha256, metadata, chunk: chunk.toString('base64') });
          if (reply.offset !== offset + chunk.length) throw new AgentError('invalid_response');
        }
        ids.push(id);
      }
    }
    const payload = { code: result.code, documents: ids };
    if (result.session) payload.session = result.session;
    if (['discover','login'].includes(job.input.action) && result.diagnostic) payload.diagnostic = result.diagnostic;
    const reply = await request({ action: 'complete', result: payload });
    if (reply.accepted !== true) throw new AgentError('invalid_response');
    // Hub completion remains authoritative even if the local receipt cannot be written.
    if (job.input.action === 'login') await writeTaskReceipt(root, job, result, reply).catch(() => {});
  } catch (error) {
    leaseError = error;
    await terminateChild(); await processResult;
    const allowed = ['worker_failed', 'worker_unavailable', 'invalid_document', 'document_limit', 'auth_invalid'];
    const code = allowed.includes(error.message) ? error.message : 'network_error';
    // If a completion reply was lost, never replace it with a different result. Let the
    // existing receipt / fixed lease expiry recover it instead of creating a second outcome.
    if (!completed || runnerError || !result) {
      const fallback = { code };
      const reply = await request({ action: 'complete', result: fallback }).catch(() => null);
      if (reply?.accepted === true && job.input.action === 'login') {
        await writeTaskReceipt(root, job, fallback, reply).catch(() => {});
      }
    }
  } finally {
    await terminateChild(); await processResult;
    await fs.rm(exchange, { recursive: true, force: true, maxRetries: 5, retryDelay: 500 });
  }
  if (leaseError) throw leaseError;
}

async function main() {
  if (process.env.NODE_TLS_REJECT_UNAUTHORIZED === '0') throw new AgentError('invalid_configuration');
  process.umask(0o077);
  let raw = '';
  process.stdin.setEncoding('utf8');
  for await (const chunk of process.stdin) {
    raw += chunk; if (raw.length > 16384) throw new AgentError('invalid_configuration');
  }
  const config = JSON.parse(raw.replace(/^\uFEFF/, '')); raw = '';
  const client = new AgentClient(config.endpoint, config.token);
  const root = await assertPrivateDirectory(config.privateDir);
  const runtime = { executablePath: config.executablePath || undefined };
  if (runtime.executablePath && !path.isAbsolute(runtime.executablePath)) throw new AgentError('invalid_configuration');
  const connected = await client.request({ action: 'ping' });
  if (connected.protocol !== 1) throw new AgentError('invalid_response');
  await probe(client, root, runtime);
  process.stdout.write('HTTPS authentication and Chrome check passed.\n');
  if (config.probeOnly === true) return;
  let failures = 0; let lastProbe = performance.now();
  while (!stopping) {
    try {
      if (performance.now() - lastProbe > 3600000) { await probe(client, root, runtime); lastProbe = performance.now(); }
      const reply = await client.request({ action: 'claim', claim_id: crypto.randomBytes(16).toString('hex') });
      if (reply.job) await execute(client, root, runtime, reply.job);
      failures = 0;
      if (config.oneShot === true) return;
      await delay(reply.job ? 1000 : 15000);
    } catch (error) {
      // Fixed status codes only. Do not log URLs, response bodies, file paths or raw errors.
      if (error instanceof AgentError && ['forbidden', 'invalid_token', 'redirect_rejected'].includes(error.message)) throw error;
      process.stderr.write('Connection or task interrupted; retry scheduled.\n');
      if (config.oneShot === true) throw error;
      await delay(Math.min(60000, 5000 * 2 ** Math.min(failures++, 4)));
    }
  }
}

try { await main(); }
catch { process.stderr.write('Agent stopped. Verify pairing, network, private storage and Chrome.\n'); process.exitCode = 1; }
finally { await terminateChild(); }
