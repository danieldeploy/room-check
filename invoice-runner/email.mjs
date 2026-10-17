import dns from 'node:dns/promises';
import net from 'node:net';
import { setTimeout as delay } from 'node:timers/promises';
import { PortalError } from './booking.mjs';
import { extractCode } from './second-factor.mjs';

export function publicAddress(ip) {
  if (net.isIP(ip) === 6) return /^[23][0-9a-f]{3}:/i.test(ip);
  if (net.isIP(ip) !== 4) return false;
  const [a, b] = ip.split('.').map(Number);
  return !(a === 0 || a === 10 || a === 127 || a >= 224 || (a === 169 && b === 254)
    || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || (a === 198 && b >= 18 && b <= 19));
}
export async function openMailbox(c) {
  if (!c.imap_host || !c.imap_user || !c.imap_password || !/^[a-z0-9.-]+$/i.test(c.imap_host) || net.isIP(c.imap_host)) throw new PortalError('auth_unconfigured');
  const addresses = await dns.lookup(c.imap_host, { all: true });
  if (!addresses.length || addresses.some(a => !publicAddress(a.address))) throw new PortalError('auth_unconfigured');
  const { ImapFlow } = await import('imapflow');
  // Pin the resolved public address while validating the original TLS hostname.
  const client = new ImapFlow({ host: addresses[0].address, port: 993, secure: true, servername: c.imap_host,
    tls: { servername: c.imap_host, rejectUnauthorized: true }, auth: { user: c.imap_user, pass: c.imap_password },
    logger: false, emitLogs: false, logRaw: false, disableAutoIdle: true, connectionTimeout: 15000, socketTimeout: 20000 });
  client.on('error', () => {});
  try {
    await client.connect();
    await client.mailboxOpen(c.imap_mailbox || 'INBOX', { readOnly: true });
    return client;
  } catch { client.close(); throw new PortalError('network_error'); }
}

export function matchesEmail(mail, c, received, since) {
  const address = (field, value) => (field?.value || []).some(a => a.address?.toLowerCase() === value.toLowerCase());
  return Number.isFinite(received) && received >= since && received <= Date.now() + 30000
    && !!c.email_sender && !!c.email_recipient && !!c.email_subject
    && address(mail.from, c.email_sender) && address(mail.to, c.email_recipient)
    && (mail.subject || '').toLowerCase().includes(c.email_subject.toLowerCase());
}

export function extractMagicLink(mail, challenge, checkUrl) {
  if (!challenge.linkPath || !challenge.linkHost) throw new PortalError('auth_unconfigured');
  const content = [mail.text || '', mail.html || ''].join('\n').replaceAll('&amp;', '&');
  const urls = [...new Set(content.match(/https:\/\/[^\s<>"']+/g) || [])].filter(value => {
    try { const url = checkUrl(value); return url.hostname === challenge.linkHost && url.pathname === challenge.linkPath; } catch { return false; }
  });
  if (urls.length !== 1) throw new PortalError('auth_invalid');
  return urls[0];
}

export class EmailChallenge {
  constructor(credentials) { this.credentials = credentials; this.seen = new Set(); }
  async prepare() {
    const c = this.credentials;
    if (!c.email_sender || !c.email_recipient || !c.email_subject) throw new PortalError('auth_unconfigured');
    this.client = await openMailbox(c);
    this.uidNext = this.client.mailbox.uidNext;
    this.validity = String(this.client.mailbox.uidValidity);
    this.started = Date.now();
  }
  async value(challenge, checkUrl) {
    const { simpleParser } = await import('mailparser');
    const deadline = this.started + 145000;
    while (Date.now() < deadline) {
      await this.client.noop();
      if (String(this.client.mailbox.uidValidity) !== this.validity) throw new PortalError('auth_invalid');
      const uids = await this.client.search({ uid: `${this.uidNext}:*`, from: this.credentials.email_sender, to: this.credentials.email_recipient,
        subject: this.credentials.email_subject, since: new Date(this.started) }, { uid: true });
      for (const uid of uids || []) {
        if (uid < this.uidNext || this.seen.has(uid)) continue;
        this.seen.add(uid);
        const meta = await this.client.fetchOne(uid, { size: true, internalDate: true }, { uid: true });
        if (!meta || meta.size > 512 * 1024 || +meta.internalDate < this.started - 1000) continue;
        const message = await this.client.fetchOne(uid, { source: true }, { uid: true });
        const mail = await simpleParser(message.source, { skipImageLinks: true, skipTextToHtml: true, maxHtmlLengthToParse: 512 * 1024 });
        if (!matchesEmail(mail, this.credentials, +meta.internalDate, this.started - 1000)) continue;
        return challenge.kind === 'link' ? extractMagicLink(mail, challenge, checkUrl) : extractCode(mail.text, challenge.digits || 6);
      }
      await delay(2000);
    }
    throw new PortalError('auth_timeout');
  }
  async close() { if (this.client) { await this.client.logout().catch(() => this.client.close()); this.client = null; } }
}
