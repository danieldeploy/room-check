import { setTimeout as delay } from 'node:timers/promises';

export class AgentError extends Error {
  constructor(code, retry = false) { super(code); this.retry = retry; }
}

export function validateEndpoint(value) {
  const url = new URL(value);
  if (url.protocol !== 'https:' || url.username || url.password || url.search || url.hash
      || !url.pathname.endsWith('/invoice-agent.php')) throw new AgentError('invalid_endpoint');
  return url.href;
}

export class AgentClient {
  constructor(endpoint, token, { fetcher = fetch, sleeper = delay } = {}) {
    this.endpoint = validateEndpoint(endpoint);
    if (!/^[a-f0-9]{64}$/.test(token)) throw new AgentError('invalid_token');
    this.token = token; this.fetcher = fetcher; this.sleeper = sleeper;
  }

  async request(payload, { attempts = 3 } = {}) {
    const body = JSON.stringify(payload);
    if (Buffer.byteLength(body) > 524288) throw new AgentError('request_too_large');
    for (let attempt = 0; attempt < attempts; attempt++) {
      try {
        const response = await this.fetcher(this.endpoint, {
          method: 'POST', redirect: 'manual', signal: AbortSignal.timeout(15000),
          headers: { 'content-type': 'application/json', authorization: `Bearer ${this.token}` }, body,
        });
        // Never forward tokens to a redirect, even when it points to the same host.
        if (response.status >= 300 && response.status < 400) throw new AgentError('redirect_rejected');
        const chunks = []; let bytes = 0;
        for await (const chunk of response.body ?? []) {
          bytes += chunk.length;
          if (bytes > 2 * 1024 * 1024) throw new AgentError('response_too_large');
          chunks.push(Buffer.from(chunk));
        }
        let value;
        try { value = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { throw new AgentError('invalid_response', response.status >= 500); }
        if (!response.ok) {
          const codes = ['forbidden', 'lease_expired', 'upload_conflict', 'completion_conflict', 'document_limit', 'auth_invalid'];
          const code = codes.includes(value.error) ? value.error : 'connection_failed';
          throw new AgentError(code, response.status >= 500 || (response.status === 409 && value.error === 'worker_busy'));
        }
        if (!value || Array.isArray(value) || typeof value !== 'object' || value.error) throw new AgentError('invalid_response');
        return value;
      } catch (error) {
        const safe = error instanceof AgentError ? error : new AgentError('connection_failed', true);
        if (!safe.retry || attempt === attempts - 1) throw safe;
        await this.sleeper(1000 * 2 ** attempt);
      }
    }
  }
}

export function documentParts(document) {
  if (!document || typeof document !== 'object') throw new AgentError('invalid_document');
  const encoded = document.content ?? document.pdf;
  if (typeof encoded !== 'string' || encoded.length > 27962028 || encoded.length % 4 || /[^A-Za-z0-9+/=]/.test(encoded)) throw new AgentError('invalid_document');
  const bytes = Buffer.from(encoded, 'base64');
  if (bytes.toString('base64') !== encoded || bytes.length < 8 || bytes.length > 20971520) throw new AgentError('invalid_document');
  const { content, pdf, ...metadata } = document;
  return { bytes, metadata };
}
