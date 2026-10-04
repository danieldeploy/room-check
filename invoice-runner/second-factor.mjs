import crypto from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { PortalError } from './booking.mjs';

export function totp(secret, seconds = Math.floor(Date.now() / 1000), { digits = 6, period = 30, algorithm = 'sha1' } = {}) {
  if (![6, 8].includes(digits) || ![30, 60].includes(period) || !['sha1', 'sha256', 'sha512'].includes(algorithm)
      || !Number.isSafeInteger(seconds) || seconds < 0) throw new PortalError('auth_unconfigured');
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  const value = String(secret || '').toUpperCase().replace(/\s/g, '').replace(/=+$/, '');
  if (!/^[A-Z2-7]{16,128}$/.test(value)) throw new PortalError('auth_unconfigured');
  let bits = 0, accumulator = 0; const bytes = [];
  for (const char of value) {
    accumulator = (accumulator << 5) | alphabet.indexOf(char); bits += 5;
    if (bits >= 8) { bits -= 8; bytes.push((accumulator >>> bits) & 255); }
  }
  const counter = Buffer.alloc(8); counter.writeBigUInt64BE(BigInt(Math.floor(seconds / period)));
  const digest = crypto.createHmac(algorithm, Buffer.from(bytes)).update(counter).digest();
  const offset = digest[digest.length - 1] & 15;
  return String((digest.readUInt32BE(offset) & 0x7fffffff) % 10 ** digits).padStart(digits, '0');
}

export function extractCode(text, digits = 6) {
  if (![6, 8].includes(digits)) throw new PortalError('auth_unconfigured');
  const values = [...new Set(String(text).match(new RegExp(`(?<!\\d)\\d{${digits}}(?!\\d)`, 'g')) || [])];
  if (values.length !== 1) throw new PortalError('auth_invalid');
  return values[0];
}

export class SecondFactor {
  constructor(input, urlCheck) { this.input = input; this.urlCheck = urlCheck; this.used = false; }
  async prepare() {
    this.started = Date.now();
    const { authMethod, credentials, exchangeDir } = this.input;
    if (authMethod === 'sms') {
      if (!credentials.sms_sender || !credentials.sms_keyword || !exchangeDir) throw new PortalError('auth_unconfigured');
      this.id = crypto.randomBytes(16).toString('hex');
      const tmp = path.join(exchangeDir, 'challenge.tmp');
      await fs.writeFile(tmp, JSON.stringify({ id: this.id, method: 'sms', created: Math.floor(this.started / 1000) }), { mode: 0o600 });
      await fs.rename(tmp, path.join(exchangeDir, 'challenge.json'));
      const deadline = Date.now() + 10000;
      while (Date.now() < deadline) {
        if (await fs.stat(path.join(exchangeDir, `ready-${this.id}`)).then(() => true).catch(() => false)) return;
        await delay(100);
      }
      throw new PortalError('auth_unconfigured');
    }
    if (authMethod === 'email') {
      const { EmailChallenge } = await import('./email.mjs');
      this.email = new EmailChallenge(credentials);
      await this.email.prepare();
    }
  }
  async value(challenge) {
    if (this.used) throw new PortalError('auth_invalid');
    this.used = true;
    if (challenge.method !== this.input.authMethod) throw new PortalError('needs_auth');
    if (challenge.method === 'totp') {
      const period = challenge.period || 30;
      const remaining = period - (Math.floor(Date.now() / 1000) % period);
      if (remaining < 5) await delay((remaining + 1) * 1000);
      return totp(this.input.credentials.totp_secret, Math.floor(Date.now() / 1000), challenge);
    }
    if (challenge.method === 'email' && this.email) return this.email.value(challenge, this.urlCheck);
    if (challenge.method === 'sms' && this.id && (challenge.digits || 6) === 6) {
      const file = path.join(this.input.exchangeDir, `response-${this.id}.json`);
      const deadline = this.started + 145000;
      while (Date.now() < deadline) {
        const value = await fs.readFile(file, 'utf8').catch(() => null);
        if (value) {
          await fs.unlink(file);
          return extractCode(JSON.parse(value).value);
        }
        await delay(500);
      }
      throw new PortalError('auth_timeout');
    }
    throw new PortalError('auth_unconfigured');
  }
  async close() { if (this.email) await this.email.close(); }
}
