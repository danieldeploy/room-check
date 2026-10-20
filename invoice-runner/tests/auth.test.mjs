import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import {totp, extractCode, SecondFactor} from '../second-factor.mjs';
import {matchesEmail,extractMagicLink,publicAddress} from '../email.mjs';
import {portalUrl,validatePortalMap,safeCookies} from '../portal.mjs';
test('Hostelworld email 2FA uses the filtered challenge exchange without IMAP', async () => {
 const exchangeDir=await fs.mkdtemp(path.join(os.tmpdir(),'hostelworld-auth-'));
 const broker=new SecondFactor({portal:'hostelworld',authMethod:'email',credentials:{},exchangeDir},()=>{});
 try {
  const prepared=broker.prepare();
  let challenge=null;
  for(let i=0;i<100 && !challenge;i++) {
   challenge=await fs.readFile(path.join(exchangeDir,'challenge.json'),'utf8').then(JSON.parse).catch(()=>null);
   if(!challenge) await new Promise(resolve=>setTimeout(resolve,10));
  }
  assert.equal(challenge?.method,'email');
  await fs.writeFile(path.join(exchangeDir,`ready-${challenge.id}`),'ready',{mode:0o600});
  await prepared;
  const valuePromise=broker.value({method:'email'});
  await fs.writeFile(path.join(exchangeDir,`response-${challenge.id}.json`),JSON.stringify({value:'047291'}),{mode:0o600});
  assert.equal(await valuePromise,'047291');
 } finally {
  await broker.close();
  await fs.rm(exchangeDir,{recursive:true,force:true});
 }
});

test('TOTP RFC 6238 known vectors and leading zero handling',()=>{
 const key='GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
 for(const [time,result] of [[59,'94287082'],[1111111109,'07081804'],[1111111111,'14050471'],[1234567890,'89005924'],[2000000000,'69279037']]) assert.equal(totp(key,time,{digits:8}),result);
 assert.equal(totp(key,59),'287082');
 assert.throws(()=>totp('bad-key'));
});
test('ambiguous or absent OTP is rejected',()=>{
 assert.equal(extractCode('Booking code: 001234'), '001234');
 assert.throws(()=>extractCode('001234 654321')); assert.throws(()=>extractCode('12345'));
});
test('email matching requires exact sender recipient subject and freshness',()=>{
 const c={email_sender:'a@example.com',email_recipient:'b@example.com',email_subject:'Booking'};
 const mail={from:{value:[{address:c.email_sender}]},to:{value:[{address:c.email_recipient}]},subject:'Booking code'};
 assert.equal(matchesEmail(mail,c,Date.now(),Date.now()-1000),true);
 assert.equal(matchesEmail(mail,c,Date.now()-10000,Date.now()-1000),false);
 assert.equal(matchesEmail(mail,{...c,email_sender:'other@example.com'},Date.now(),Date.now()-1000),false);
});
test('magic links must match platform host and exact configured path',()=>{
 const challenge={linkHost:'secure.hostelworld.com',linkPath:'/confirm'};
 assert.equal(extractMagicLink({text:'https://secure.hostelworld.com/confirm?token=test'},challenge,u=>portalUrl('hostelworld',u)), 'https://secure.hostelworld.com/confirm?token=test');
 assert.throws(()=>extractMagicLink({text:'https://evil.example/confirm'},challenge,u=>portalUrl('hostelworld',u)));
});
test('account maps and cookies cannot cross platforms',()=>{
 assert.throws(()=>validatePortalMap({version:2,validated:true,accountId:2,portal:'airbnb'},{portal:'booking',accountId:1}));
 assert.throws(()=>portalUrl('booking','https://booking.com.evil.example/'));
 assert.equal(safeCookies('airbnb',[{secure:true,domain:'.booking.com'}]).length,0);
 assert.equal(publicAddress('127.0.0.1'),false); assert.equal(publicAddress('10.0.0.1'),false);
});

test('filtered Hostelworld link uses exact mapped destination and one consumption',async()=>{
 const exchangeDir=await fs.mkdtemp(path.join(os.tmpdir(),'hostelworld-link-'));
 const challenge={method:'email',kind:'link',linkHost:'inbox.hostelworld.com',linkPath:'/inbox/verify'};
 try {
  for(const [value,valid] of [
   ['https://inbox.hostelworld.com/inbox/verify?token=fixture',true],
   ['https://evil.example/inbox/verify?token=fixture',false],
   ['https://inbox.hostelworld.com/other?token=fixture',false],
   ['https://user@inbox.hostelworld.com/inbox/verify?token=fixture',false],
   ['https://inbox.hostelworld.com/inbox/verify?token=fixture#fragment',false],
   ['123456',false]
  ]) {
   const broker=new SecondFactor({portal:'hostelworld',authMethod:'email',exchangeDir},u=>portalUrl('hostelworld',u));
   broker.id='fixture'; broker.started=Date.now(); broker.filteredEmail=true;
   await fs.writeFile(path.join(exchangeDir,'response-fixture.json'),JSON.stringify({value}),{mode:0o600});
   if(valid) assert.equal(await broker.value(challenge),value);
   else await assert.rejects(broker.value(challenge));
   await assert.rejects(broker.value(challenge));
   await assert.rejects(fs.stat(path.join(exchangeDir,'response-fixture.json')));
  }
 } finally { await fs.rm(exchangeDir,{recursive:true,force:true}); }
});

test('Hostelworld path tokens accept only the observed login shape', async()=>{
 const exchangeDir=await fs.mkdtemp(path.join(os.tmpdir(),'hostelworld-path-'));
 const challenge={method:'email',kind:'link',linkHost:'inbox.hostelworld.com',linkPath:'/login/'};
 const base='https://inbox.hostelworld.com/login/'+'a'.repeat(32);
 try {
  for(const [value,valid] of [[base,true],[base+'?Language=English',true],
   [base+'?token=other',false],[base+'?Language=English&Language=French',false],
   [base+'?Language=',false],[base+'/extra',false],[base.slice(0,-1),false],
   [base.replace('/login/','/other/'),false],[base.replace('inbox.hostelworld.com','evil.example'),false]]) {
   const broker=new SecondFactor({portal:'hostelworld',authMethod:'email',exchangeDir},u=>portalUrl('hostelworld',u));
   broker.id='fixture'; broker.started=Date.now(); broker.filteredEmail=true;
   await fs.writeFile(path.join(exchangeDir,'response-fixture.json'),JSON.stringify({value}),{mode:0o600});
   if(valid) assert.equal(await broker.value(challenge),value);
   else await assert.rejects(broker.value(challenge));
  }
 } finally { await fs.rm(exchangeDir,{recursive:true,force:true}); }
});
