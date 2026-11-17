const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const markup=`<form data-receipt-correction data-url="receipts.php?correction_files=one" data-preview-url="receipt-document.php?id=one" data-max="19" data-loading="Loading" data-error="Failed" data-empty="No files"><button type="button" data-correction-load>Select files from Drive</button><select name="selected_files[]" multiple hidden data-correction-files disabled></select><ul data-correction-selected hidden></ul><p data-correction-status role="status"></p><input name="missing_added" type="checkbox" data-correction-confirm required><button type="submit" data-correction-submit disabled>Process again</button><template data-correction-picker><dialog data-folder-empty="Empty folder" data-count="Selected"><button type="button" data-picker-cancel>Cancel</button><nav data-picker-path></nav><p data-picker-status></p><div data-picker-list></div><span data-picker-count></span><button type="button" data-picker-apply disabled>Use selection</button></dialog></template><template data-correction-preview><details data-receipt-preview data-show="Document" data-loading="Loading" data-error="Unavailable"><summary>Show document</summary><div data-preview-panel><p role="status"></p><div data-document-content></div></div></details></template></form>`;
const folders=[{id:'month',parent:'',name:'temp_scan/April'},{id:'a4',parent:'month',name:'A4'},{id:'taloes',parent:'month',name:'Talões'}];
const file=(token,folder='month',name=token+'.pdf')=>({token,preview:'preview-'+token,name,path:folder==='month'?'temp_scan/April':'temp_scan/April/'+folder,folder,reason:''});
function setup(reply,max=19){
 const dom=new JSDOM(`<div id="receipt-history">${markup}</div>`,{runScripts:'outside-only',url:'https://check.welcomehostel.pt/admin/receipts.php'});let calls=0;
 dom.window.HTMLDialogElement.prototype.showModal=function(){this.open=true;};dom.window.HTMLDialogElement.prototype.close=function(){this.open=false;};
 dom.window.document.querySelector('form').dataset.max=String(max);
 dom.window.fetch=async(url,options)=>{calls++;assert.equal(options.cache,'no-store');assert.equal(options.credentials,'same-origin');return {ok:true,json:async()=>typeof reply==='function'?reply(calls,options):reply};};
 dom.window.eval(fs.readFileSync('admin/assets/receipt-correction.js','utf8'));
 const doc=dom.window.document,flush=()=>new Promise(resolve=>setTimeout(resolve,0));
 return {dom,doc,calls:()=>calls,flush,open:async()=>{doc.querySelector('[data-correction-load]').click();await flush();},pick:i=>doc.querySelectorAll('[data-picker-choice]')[i].click(),apply:()=>doc.querySelector('[data-picker-apply]').click(),folder:name=>Array.from(doc.querySelectorAll('.receipt-picker-folder')).find(b=>b.textContent.includes(name)).click(),cancel:()=>doc.querySelector('[data-picker-cancel]').click()};
}
test('fresh modal loads each opening; no external link or refresh; excluded files explain and preview safely',async()=>{
 const known={...file(''),name:'pending <test>.pdf',reason:'Pending document',preview:'preview-known'};
 const x=setup({files:[known],folders});try{
 await x.open();assert.equal(x.calls(),1);assert.equal(x.doc.querySelector('dialog').open,true);
 assert.match(x.doc.querySelector('.receipt-picker-file').textContent,/pending <test>\.pdf.*Pending document/);
 assert.equal(x.doc.querySelector('test'),null);assert.equal(x.doc.querySelector('a'),null);
 assert.equal(x.doc.querySelector('.receipt-picker-file input').disabled,true);
 assert.equal(x.doc.querySelector('[data-receipt-preview]').dataset.url,'receipt-document.php?id=one&selection=preview-known');
 assert.equal(x.doc.querySelector('[data-picker-status]').textContent,'No files');assert.equal(x.doc.querySelector('[data-picker-apply]').disabled,true);
 x.cancel();await x.open();assert.equal(x.calls(),2);x.cancel();assert.equal(x.doc.querySelector('[data-correction-submit]').disabled,true);
 }finally{x.dom.window.close();}
});
test('navigation retains choices across folders and returns exact files; confirmation and repeat-submit gates remain',async()=>{
 const x=setup({files:[file('root'),file('extra','a4','<img src=x onerror=bad()>')],folders});try{
 await x.open();x.pick(0);x.folder('A4');x.pick(0);
 assert.equal(x.doc.querySelector('img'),null);assert.equal(x.doc.querySelector('[data-picker-count]').textContent,'Selected: 2 / 19');
 x.doc.querySelector('[data-picker-path] button').click();assert.equal(x.doc.querySelector('[data-picker-choice]').checked,true);
 x.apply();assert.equal(x.doc.querySelector('dialog'),null);assert.equal(x.doc.querySelector('select').selectedOptions.length,2);
 assert.match(x.doc.querySelector('[data-correction-selected]').textContent,/temp_scan\/April\/a4 \/ <img/);
 const submit=x.doc.querySelector('[data-correction-submit]');assert.equal(submit.disabled,true);
 x.doc.querySelector('[data-correction-confirm]').click();assert.equal(submit.disabled,false);
 assert.equal(x.doc.getElementById('receipt-history').dataset.editing,'true');
 const event=new x.dom.window.Event('submit',{bubbles:true,cancelable:true});x.doc.querySelector('form').dispatchEvent(event);
 assert.equal(event.defaultPrevented,false);assert.equal(submit.disabled,true);
 }finally{x.dom.window.close();}
});
test('single replacement uses radios and never carries a previous folder selection',async()=>{
 const x=setup({files:[file('root'),file('extra','a4')],folders},1);try{
 await x.open();assert.equal(x.doc.querySelector('[data-picker-choice]').type,'radio');x.pick(0);x.folder('A4');x.pick(0);x.apply();
 assert.deepEqual(Array.from(x.doc.querySelector('select').selectedOptions,o=>o.value),['extra']);
 }finally{x.dom.window.close();}
});
test('cancel and Escape discard draft choices, preserve committed selection, and restore focus',async()=>{
 const x=setup({files:[file('one'),file('two')],folders});try{
 await x.open();x.pick(0);x.apply();x.doc.querySelector('[data-correction-confirm]').click();
 await x.open();x.pick(1);x.doc.querySelector('dialog').dispatchEvent(new x.dom.window.Event('cancel',{cancelable:true}));
 assert.deepEqual(Array.from(x.doc.querySelector('select').selectedOptions,o=>o.value),['one']);
 assert.equal(x.doc.querySelector('[data-correction-confirm]').checked,true);assert.equal(x.doc.querySelector('[data-correction-submit]').disabled,false);
 assert.equal(x.doc.activeElement,x.doc.querySelector('[data-correction-load]'));
 }finally{x.dom.window.close();}
});
test('reopening invalidates moved/changed selection and reads newly added files',async()=>{
 const x=setup(n=>({files:[file(n===1?'old':'new')],folders}));try{
 await x.open();x.pick(0);x.apply();x.doc.querySelector('[data-correction-confirm]').click();
 await x.open();assert.equal(x.calls(),2);assert.equal(x.doc.querySelector('select').selectedOptions.length,0);
 assert.equal(x.doc.querySelector('[data-correction-confirm]').checked,false);assert.match(x.doc.querySelector('.receipt-picker-file').textContent,/new.pdf/);
 x.cancel();assert.equal(x.doc.querySelector('[data-correction-submit]').disabled,true);
 }finally{x.dom.window.close();}
});
test('empty subfolders and selection cap are usable',async()=>{
 const x=setup({files:[file('one'),file('two'),file('three')],folders},2);try{
 await x.open();x.folder('A4');assert.equal(x.doc.querySelector('[data-picker-status]').textContent,'Empty folder');
 x.doc.querySelector('[data-picker-path] button').click();x.pick(0);x.pick(1);
 assert.equal(x.doc.querySelectorAll('[data-picker-choice]')[2].disabled,true);x.pick(0);
 assert.equal(x.doc.querySelectorAll('[data-picker-choice]')[2].disabled,false);x.cancel();
 }finally{x.dom.window.close();}
});
test('late response after closing cannot mutate form or reopen modal',async()=>{
 let resolve,signal;const x=setup((n,options)=>{signal=options.signal;return new Promise(r=>{resolve=r;});});try{
 await x.open();x.cancel();assert.equal(signal.aborted,true);resolve({files:[file('late')],folders});await x.flush();
 assert.equal(x.doc.querySelector('dialog'),null);assert.equal(x.doc.querySelector('select').options.length,0);
 assert.equal(x.doc.querySelector('[data-correction-load]').disabled,false);
 }finally{x.dom.window.close();}
});
test('polled forms, auth/errors, malformed trees and foreign folders fail closed',async()=>{
 for(const reply of [{error:'Not permitted'},{files:[],folders:[]},{files:[file('outside','other')],folders},{files:[],folders:[{id:'loop',parent:'loop',name:'Loop'}]}]){
 const x=setup(reply);try{x.doc.getElementById('receipt-history').innerHTML=markup;await x.open();
 assert.equal(x.doc.querySelector('[data-picker-status]').textContent,reply.error||'Failed');
 assert.equal(x.doc.querySelector('[data-picker-apply]').disabled,true);x.cancel();assert.equal(x.doc.querySelector('[data-correction-submit]').disabled,true);
 }finally{x.dom.window.close();}}
});

test('existing preview renderer loads candidates and releases bytes when navigating or closing',async()=>{
 const x=setup({files:[file('one')],folders});try{
 let revoked=0,signal;x.dom.window.URL.createObjectURL=()=> 'blob:synthetic';x.dom.window.URL.revokeObjectURL=()=>revoked++;
 x.dom.window.eval(fs.readFileSync('admin/assets/receipt-preview.js','utf8'));
 await x.open();x.dom.window.fetch=async(url,options)=>{assert.match(url,/receipt-document.php.*selection=preview-one/);signal=options.signal;return {ok:true,headers:{get:()=> 'application/pdf'},blob:async()=>({type:'application/pdf'})};};
 x.doc.querySelector('summary').click();await new Promise(r=>setTimeout(r,15));assert.ok(x.doc.querySelector('iframe'));
 x.folder('A4');assert.equal(signal.aborted,true);assert.equal(revoked,1);
 x.doc.querySelector('[data-picker-path] button').click();x.doc.querySelector('summary').click();await new Promise(r=>setTimeout(r,15));
 assert.ok(x.doc.querySelector('iframe'));x.cancel();assert.equal(signal.aborted,true);assert.equal(revoked,2);
 }finally{x.dom.window.close();}
});

test('replacement submits independently of invalid old details elsewhere on the same invoice',async()=>{
 const x=setup({files:[file('new-scan')],folders},1);try{
 x.doc.getElementById('receipt-history').insertAdjacentHTML('beforeend','<form data-receipt-review><input name="issued_on" required><input name="issuer_name" required><input name="buyer_confirmed" type="checkbox" required></form>');
 assert.equal(x.doc.querySelector('[data-receipt-review]').checkValidity(),false);
 await x.open();x.pick(0);x.apply();x.doc.querySelector('[data-correction-confirm]').click();
 assert.equal(x.doc.querySelector('[data-correction-submit]').disabled,false);
 const event=new x.dom.window.Event('submit',{bubbles:true,cancelable:true});x.doc.querySelector('[data-receipt-correction]').dispatchEvent(event);
 assert.equal(event.defaultPrevented,false);
 }finally{x.dom.window.close();}
});
