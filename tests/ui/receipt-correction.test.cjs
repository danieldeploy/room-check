const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const markup=`<form data-receipt-correction data-url="receipts.php?correction_files=one" data-loading="Loading" data-error="Failed" data-empty="No files"><button type="button" data-correction-load>Load</button><select name="selected_files[]" multiple required data-correction-files disabled></select><p data-correction-status role="status"></p><input name="missing_added" type="checkbox" required><button type="submit" data-correction-submit disabled>Process again</button></form>`;
function setup(reply){const dom=new JSDOM(`<div id="receipt-history">${markup}</div>`,{runScripts:'outside-only',url:'https://check.welcomehostel.pt/admin/receipts.php'});let calls=0;dom.window.fetch=async()=>{calls++;return {ok:true,json:async()=>reply};};assert.ok(fs.existsSync('admin/assets/receipt-correction.js'),'Explicit correction picker exists');dom.window.eval(fs.readFileSync('admin/assets/receipt-correction.js','utf8'));return {dom,calls:()=>calls,flush:()=>new Promise(resolve=>setTimeout(resolve,0))};}
test('known pending files are visible with an explanation but cannot enable repair',async()=>{
 const x=setup({files:[{token:'',name:'pending <test>.pdf',path:'temp_scan/April/A4',reason:'This is the pending document. Add the missing pages in a new file.'}]});
 try{const doc=x.dom.window.document;doc.querySelector('[data-correction-load]').click();await x.flush();
 assert.match(doc.querySelector('[data-correction-excluded]').textContent,/pending <test>\.pdf.*This is the pending document/);
 assert.equal(doc.querySelector('test'),null);assert.equal(doc.querySelector('select').options.length,0);
 assert.equal(doc.querySelector('select').disabled,true);assert.equal(doc.querySelector('[data-correction-status]').textContent,'No files');
 doc.querySelector('input').checked=true;doc.querySelector('input').dispatchEvent(new x.dom.window.Event('change',{bubbles:true}));
 assert.equal(doc.querySelector('[type=submit]').disabled,true);
 }finally{x.dom.window.close();}
});
test('only new pages enter the picker when recorded files are present',async()=>{
 const x=setup({files:[{token:'',name:'original.pdf',path:'temp_scan/April',reason:'Recorded source'},
 {token:'new-page',name:'missing.pdf',path:'temp_scan/April/A4',reason:''}]});
 try{const doc=x.dom.window.document;doc.querySelector('button').click();await x.flush();
 assert.equal(doc.querySelector('select').options.length,1);assert.equal(doc.querySelector('select').options[0].value,'new-page');
 assert.equal(doc.querySelector('select').disabled,false);assert.equal(doc.querySelector('[data-correction-status]').textContent,'');
 assert.match(doc.querySelector('[data-correction-excluded]').textContent,/original\.pdf — Recorded source/);
 doc.querySelector('button').click();await x.flush();assert.equal(doc.querySelectorAll('[data-correction-excluded] li').length,1);
 assert.equal(doc.querySelector('[type=submit]').disabled,true);
 }finally{x.dom.window.close();}
});
test('load explicit filenames and paths as text; selection and confirmation gate submit',async()=>{const x=setup({files:[{token:'opaque-token',name:'<img src=x onerror=bad()>',path:'temp_scan/11/Talões'}]});try{const doc=x.dom.window.document;doc.querySelector('[data-correction-load]').click();await x.flush();const select=doc.querySelector('select'),submit=doc.querySelector('[type=submit]');assert.equal(select.options[0].value,'opaque-token');assert.equal(select.options[0].textContent,'temp_scan/11/Talões / <img src=x onerror=bad()>');assert.equal(doc.querySelector('img'),null);assert.equal(submit.disabled,true);select.options[0].selected=true;select.dispatchEvent(new x.dom.window.Event('change',{bubbles:true}));assert.equal(submit.disabled,true);doc.querySelector('input').checked=true;doc.querySelector('input').dispatchEvent(new x.dom.window.Event('change',{bubbles:true}));assert.equal(submit.disabled,false);assert.equal(doc.getElementById('receipt-history').dataset.editing,'true');const event=new x.dom.window.Event('submit',{bubbles:true,cancelable:true});doc.querySelector('form').dispatchEvent(event);assert.equal(event.defaultPrevented,false);assert.equal(submit.disabled,true);}finally{x.dom.window.close();}});
test('delegated loading handles polled forms and reports failures without enabling submit',async()=>{for(const reply of [{error:'Selection changed'},{files:[]}]){const x=setup(reply);try{const doc=x.dom.window.document;doc.getElementById('receipt-history').innerHTML=markup;doc.querySelector('button').click();await x.flush();assert.equal(x.calls(),1);assert.equal(doc.querySelector('[data-correction-status]').textContent,reply.error||'No files');assert.equal(doc.querySelector('[type=submit]').disabled,true);}finally{x.dom.window.close();}}});
