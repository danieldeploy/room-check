import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { constants, createHash, createPublicKey, publicEncrypt } from 'node:crypto';

// Test fixtures only: no Hub connection and no production credentials.
if (process.argv[2] === 'seal') {
  const key = createPublicKey(await fs.readFile(process.argv[3]));
  const response = { version: 1, algorithm: 'RSA-OAEP-SHA1',
    fingerprint: createHash('sha256').update(key.export({type:'spki',format:'der'})).digest('hex'),
    ciphertext: publicEncrypt({key,padding:constants.RSA_PKCS1_OAEP_PADDING,oaepHash:'sha1'}, Buffer.from('a'.repeat(64))).toString('base64') };
  await fs.writeFile(process.argv[4], JSON.stringify(response));
} else {
  let input = ''; for await (const chunk of process.stdin) input += chunk;
  const config = JSON.parse(input);
  assert.equal(config.token, 'a'.repeat(64));
  assert.equal(config.endpoint, 'https://check.welcomehostel.pt/invoice-agent.php');
  assert.equal(config.probeOnly, true);
  assert.ok(config.privateDir.includes('\u00e3'));
  console.log('Private UTF-8 input received by test fixture.');
}
