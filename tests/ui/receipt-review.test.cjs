const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const scriptPath='admin/assets/receipt-review.js';
function setup(){
 const dom=new JSDOM(`<div id="receipt-history"><form data-receipt-review data-date-max="2026-11-14"><input name="issued_on" data-review-date required placeholder="DD/MM/AAAA"><input name="issuer_name" maxlength="160" required placeholder="Input company name"><input name="invoice_number" maxlength="128" required placeholder="Input invoice number"><input name="issuer_nif" pattern="[0-9]{9}" required placeholder="Input NIF"><input name="buyer_confirmed" type="checkbox" required><input name="confirmed" type="checkbox" required><button type="submit" data-review-submit disabled>Confirm and continue</button></form></div>`,{runScripts:'outside-only'});
 assert.ok(fs.existsSync(scriptPath),'Combined review validation script must exist');dom.window.eval(fs.readFileSync(scriptPath,'utf8'));
 const form=dom.window.document.querySelector('form');let submits=0;form.addEventListener('submit',()=>submits++);
 const edit=(name,value)=>{const e=form.elements.namedItem(name);if(e.type==='checkbox')e.checked=value;else e.value=value;e.dispatchEvent(new dom.window.Event('input',{bubbles:true}));return e;};
 return {dom,form,edit,button:form.querySelector('button'),submits:()=>submits};
}
test('all mandatory data and both confirmations enable only the explicit continue action',()=>{
 const x=setup();try{
  for(const [name,value] of Object.entries({issued_on:'02/04/2026',issuer_name:'Test supplier',invoice_number:'FT TEST/1',issuer_nif:'123456789',buyer_confirmed:true})) {x.edit(name,value);assert.equal(x.button.disabled,true);}
  x.edit('confirmed',true);assert.equal(x.button.disabled,false);assert.equal(x.submits(),0,'Typing or ticking must never submit');
  x.edit('issuer_nif','');assert.equal(x.button.disabled,true);assert.equal(x.form.elements.issuer_nif.placeholder,'Input NIF');
 }finally{x.dom.window.close();}
});
test('invalid calendar dates, future dates and blank names cannot enable continuation',()=>{
 const x=setup();try{
  for(const [name,value] of Object.entries({issuer_name:'Test supplier',invoice_number:'FT TEST/1',issuer_nif:'123456789',buyer_confirmed:true,confirmed:true})) x.edit(name,value);
  for(const value of ['31/02/2026','29/02/2026','11/31/2026','15/11/2026','2026-04-02']) {x.edit('issued_on',value);assert.equal(x.button.disabled,true,value);}
  x.edit('issued_on','02/04/2026');assert.equal(x.button.disabled,false);x.edit('issuer_name','   ');assert.equal(x.button.disabled,true);
 }finally{x.dom.window.close();}
});
test('polled forms get validation and successful submit prevents a second click',()=>{
 const x=setup();try{
  for(const [name,value] of Object.entries({issued_on:'02/04/2026',issuer_name:'Test supplier',invoice_number:'FT TEST/1',issuer_nif:'123456789',buyer_confirmed:true,confirmed:true})) x.edit(name,value);
  x.dom.window.dispatchEvent(new x.dom.window.Event('receipt-history-updated'));assert.equal(x.button.disabled,false);
  const first=new x.dom.window.Event('submit',{bubbles:true,cancelable:true});x.form.dispatchEvent(first);assert.equal(first.defaultPrevented,false);assert.equal(x.button.disabled,true);
  const second=new x.dom.window.Event('submit',{bubbles:true,cancelable:true});x.form.dispatchEvent(second);assert.equal(second.defaultPrevented,true);
 }finally{x.dom.window.close();}
});
