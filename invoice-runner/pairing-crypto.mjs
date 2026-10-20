import { constants, createHash, createPublicKey, generateKeyPairSync, privateDecrypt } from 'node:crypto';
import { pathToFileURL } from 'node:url';

export function preparePairing() {
  const keys = generateKeyPairSync('rsa', {
    modulusLength: 3072,
    publicKeyEncoding: { type: 'spki', format: 'pem' },
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
  });
  return { ...keys, created: Date.now() };
}

export function openPairing(pending, sealed) {
  if (!pending || !Number.isSafeInteger(pending.created) || Date.now() - pending.created > 86400000
      || pending.created > Date.now() + 300000 || sealed?.version !== 1
      || sealed.algorithm !== 'RSA-OAEP-SHA1' || typeof sealed.ciphertext !== 'string') throw new Error('pairing_invalid');
  const publicKey = createPublicKey(pending.privateKey);
  const fingerprint = createHash('sha256').update(publicKey.export({ type: 'spki', format: 'der' })).digest('hex');
  if (sealed.fingerprint !== fingerprint || sealed.ciphertext.length !== 512
      || !/^[A-Za-z0-9+/]+={0,2}$/.test(sealed.ciphertext)) throw new Error('pairing_invalid');
  const cipher = Buffer.from(sealed.ciphertext, 'base64');
  if (cipher.toString('base64') !== sealed.ciphertext) throw new Error('pairing_invalid');
  const plain = privateDecrypt({ key: pending.privateKey, padding: constants.RSA_PKCS1_OAEP_PADDING, oaepHash: 'sha1' }, cipher);
  try {
    const token = plain.toString('utf8');
    if (!/^[a-f0-9]{64}$/.test(token)) throw new Error('pairing_invalid');
    return { token };
  } finally { plain.fill(0); }
}

// Called only by the PowerShell wrapper through private stdin/stdout pipes.
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    let raw = '';
    process.stdin.setEncoding('utf8');
    for await (const chunk of process.stdin) {
      raw += chunk;
      if (raw.length > 32768) throw new Error('pairing_invalid');
    }
    const input = JSON.parse(raw.replace(/^\uFEFF/, ''));
    const result = input.action === 'prepare' ? preparePairing()
      : input.action === 'open' ? openPairing(input.pending, input.sealed) : null;
    if (!result) throw new Error('pairing_invalid');
    process.stdout.write(JSON.stringify(result));
  } catch (error) {
    const code = typeof error?.code === 'string' && /^[A-Z0-9_]+$/.test(error.code) ? error.code
      : ['TypeError','SyntaxError','RangeError'].includes(error?.name) ? error.name : 'Error';
    process.stderr.write(`pairing_invalid:${code}\n`);
    process.exitCode = 1;
  }
}
