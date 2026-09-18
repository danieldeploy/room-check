import test from 'node:test';
import assert from 'node:assert/strict';
import {totp, extractCode} from '../second-factor.mjs';
import {matchesEmail,extractMagicLink,publicAddress} from '../email.mjs';
import {portalUrl,validatePortalMap,safeCookies} from '../portal.mjs';
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
