import test from 'node:test';
import assert from 'node:assert/strict';
import { constants, createHash, createPublicKey, publicEncrypt } from 'node:crypto';
import { openPairing, preparePairing } from '../pairing-crypto.mjs';

const pending=preparePairing();
const token='a'.repeat(64);
const publicKey=createPublicKey(pending.publicKey);
const sealed={version:1,algorithm:'RSA-OAEP-SHA1',
  fingerprint:createHash('sha256').update(publicKey.export({type:'spki',format:'der'})).digest('hex'),
  ciphertext:publicEncrypt({key:publicKey,padding:constants.RSA_PKCS1_OAEP_PADDING,oaepHash:'sha1'},Buffer.from(token)).toString('base64')};

test('only the requesting computer can open the response',()=>{
  assert.deepEqual(openPairing(pending,sealed),{token});
  assert.throws(()=>openPairing(preparePairing(),sealed));
  assert.ok(!JSON.stringify(sealed).includes(token));
});
test('changed ciphertext, recipient, format and expired requests are rejected',()=>{
  assert.throws(()=>openPairing(pending,{...sealed,ciphertext:'A'.repeat(512)}));
  assert.throws(()=>openPairing(pending,{...sealed,fingerprint:'b'.repeat(64)}));
  assert.throws(()=>openPairing(pending,{...sealed,algorithm:'RSA-PKCS1'}));
  assert.throws(()=>openPairing({...pending,created:Date.now()-86400001},sealed));
  assert.throws(()=>openPairing(pending,{...sealed,ciphertext:sealed.ciphertext+'='}));
});
