const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const script=fs.readFileSync('admin/assets/receipt-status.js','utf8');
function setup({state='receipt_processing',delay=5,next=0,extra=''}={}){
 const now=Math.floor(Date.now()/1000);
 const dom=new JSDOM(`${extra}<section id="receipt-status" data-url="receipts.php?status=1" data-state="${state}" data-poll="${delay?now+delay:0}" data-next="${next?now+next:0}" data-server="${now}" data-retry="Retry in {seconds} seconds" data-due="Waiting for worker" data-unavailable="Status check failed"><strong data-status-label></strong><button type="button" data-receipt-refresh hidden>Refresh</button><p data-status-warning hidden></p></section><div id="receipt-report"></div><div id="receipt-history"><form><input value="unsaved"></form></div>`,{runScripts:'outside-only',url:'https://check.welcomehostel.pt/admin/receipts.php'});
 dom.window.Date.now=()=>now*1000;
 const calls=[],timers=new Map();let id=0;
 dom.window.setTimeout=(fn,ms)=>{timers.set(++id,[fn,ms]);return id;};dom.window.clearTimeout=id=>timers.delete(id);dom.window.setInterval=()=>++id;dom.window.clearInterval=()=>{};
 const response=(progress={})=>({progress:{state:'receipt_finished_pending',next_at:0,server_time:now,poll_at:0,...progress},label:'Awaiting user action',report:'<strong>25</strong>',history:'<p>updated</p>',revision:'v2'});
 dom.window.fetch=async(url,options)=>{calls.push([url,options]);return {ok:true,json:async()=>response()};};
 dom.window.eval(script);
 const tick=async()=>{const [key,[fn]]=timers.entries().next().value;timers.delete(key);await fn();};
 return {dom,calls,timers,now,response,tick,button:dom.window.document.querySelector('[data-receipt-refresh]')};
}
test('active work polls at five seconds and stops for manual-only pending state',async()=>{
 const x=setup();try{assert.equal([...x.timers.values()][0][1],5000);assert.equal(x.button.hidden,true);await x.tick();assert.equal(x.calls.length,1);assert.equal(x.calls[0][1].method,undefined);assert.equal(x.calls[0][1].credentials,'same-origin');assert.equal(x.timers.size,0);assert.equal(x.button.hidden,true);}finally{x.dom.window.close();}
});
test('scheduled retries wait until due and provider replies use sixty seconds',()=>{
 for(const [state,delay,next] of [['receipt_retry_wait',360,360],['receipt_quota_wait',86400,86400],['receipt_toc_wait',60,0]]){const x=setup({state,delay,next});try{assert.equal([...x.timers.values()][0][1],delay*1000);if(next)assert.match(x.dom.window.document.querySelector('[data-status-label]').textContent,/Retry in/);}finally{x.dom.window.close();}}
});
test('completed and human-only pending pages never start a timer or offer Refresh',()=>{
 for(const state of ['receipt_finished','receipt_finished_pending','receipt_interrupted']){const x=setup({state,delay:0});try{assert.equal(x.timers.size,0);assert.equal(x.button.hidden,true);assert.equal(x.calls.length,0);}finally{x.dom.window.close();}}
});
test('failed status check stops automatic polling and reveals a single manual recovery control',async()=>{
 const x=setup();try{x.dom.window.fetch=async()=>{throw new Error('offline');};await x.tick();assert.equal(x.timers.size,0);assert.equal(x.button.hidden,false);assert.equal(x.dom.window.document.querySelector('[data-status-warning]').textContent,'Status check failed');
 x.dom.window.fetch=async()=>({ok:true,json:async()=>x.response({state:'receipt_processing',poll_at:x.now+5})});x.button.click();await new Promise(resolve=>setImmediate(resolve));assert.equal(x.button.hidden,true);assert.equal(x.dom.window.document.querySelector('[data-status-warning]').hidden,true);assert.equal([...x.timers.values()][0][1],5000);
 }finally{x.dom.window.close();}
});
test('successful Refresh returning manual state hides recovery and does not restart polling',async()=>{
 const x=setup();try{x.dom.window.fetch=async()=>({ok:false});await x.tick();x.dom.window.fetch=async()=>({ok:true,json:async()=>x.response()});x.button.click();await new Promise(resolve=>setImmediate(resolve));assert.equal(x.button.hidden,true);assert.equal(x.timers.size,0);}finally{x.dom.window.close();}
});
test('open PDF and unsaved edits survive successful status reads',async()=>{
 for(const editing of [true,false]){const x=setup();try{const h=x.dom.window.document.getElementById('receipt-history');if(editing)h.querySelector('input').dispatchEvent(new x.dom.window.Event('input',{bubbles:true}));else h.insertAdjacentHTML('beforeend','<details data-receipt-preview open><summary>PDF</summary></details>');await x.tick();assert.equal(h.querySelector('input').value,'unsaved');assert.equal(x.dom.window.document.getElementById('receipt-report').textContent,'25');}finally{x.dom.window.close();}}
});
test('global activity may update start availability while the selected run stays pending',async()=>{
 const x=setup({state:'receipt_finished_pending',extra:'<button data-receipt-start>Start</button>'});try{let canStart=false;x.dom.window.fetch=async()=>({ok:true,json:async()=>({...x.response({poll_at:canStart?0:x.now+5}),can_start:canStart})});await x.tick();assert.equal(x.dom.window.document.querySelector('[data-receipt-start]').disabled,true);canStart=true;await x.tick();assert.equal(x.dom.window.document.querySelector('[data-receipt-start]').disabled,false);assert.equal(x.timers.size,0);}finally{x.dom.window.close();}
});
test('status errors and request errors remain distinct; no unsafe HTML is inserted',async()=>{
 const x=setup({extra:'<div id="receipt-request-error" role="alert" tabindex="-1">Request refused</div><div id="receipt-global-error"></div>'});try{x.dom.window.fetch=async()=>({ok:true,json:async()=>({...x.response(),error:'<safe error>'})});await x.tick();const e=x.dom.window.document.getElementById('receipt-global-error');assert.equal(e.textContent,'<safe error>');assert.equal(e.children.length,0);assert.equal(x.dom.window.document.getElementById('receipt-request-error').textContent,'Request refused');assert.equal(x.button.hidden,true);}finally{x.dom.window.close();}
});

test('a hung status read times out once, exposes Refresh and never overlaps requests',async()=>{
 const x=setup();try{let calls=0;x.dom.window.fetch=async(_url,{signal})=>{calls++;return new Promise((_resolve,reject)=>signal.addEventListener('abort',()=>reject(new Error('timeout'))));};const pending=x.tick();await Promise.resolve();x.button.dispatchEvent(new x.dom.window.Event('click'));assert.equal(calls,1);const timeout=[...x.timers.values()].find(v=>v[1]===15000);assert.ok(timeout);timeout[0]();await pending;assert.equal(x.button.hidden,false);assert.equal(x.timers.size,0);}finally{x.dom.window.close();}
});

test('automatic state locks all intake controls, updates run labels and removes the banner at stop',async()=>{
 const x=setup({extra:'<div id="receipt-processing-message"></div><fieldset data-receipt-intake><input name="year"><input type="checkbox" name="months[]"><button data-receipt-start>Start</button></fieldset><select name="process"><option value="run">Old Pending</option></select><select name="process_year"><option value="2026" selected>2026</option></select>'});
 try{let running=true;x.dom.window.fetch=async()=>({ok:true,json:async()=>({...x.response({state:running?'receipt_toc_wait':'receipt_finished',poll_at:running?x.now+60:0}),can_start:!running,processing:running,process_labels:{run:running?'Run Running':'Run Completed'}})});
  await x.tick();const d=x.dom.window.document;assert.equal(d.querySelector('[data-receipt-intake]').disabled,true);assert.equal(d.getElementById('receipt-processing-message').hidden,false);assert.equal(d.querySelector('[name="process"] option').textContent,'Run Running');
  running=false;await x.tick();assert.equal(d.querySelector('[data-receipt-intake]').disabled,false);assert.equal(d.getElementById('receipt-processing-message').hidden,true);assert.equal(d.querySelector('[name="process"] option').textContent,'Run Completed');assert.equal(d.querySelector('[name="process_year"]').value,'2026');
 }finally{x.dom.window.close();}
});

test('selected-run filter and banner update even with an open preview',async()=>{
 const x=setup({extra:'<div id="receipt-processing-message"></div>'});
 try{const h=x.dom.window.document.getElementById('receipt-history');h.innerHTML='<div data-receipt-document-filter><select name="filter"><option value="success">Processed</option><option value="problems">Pending</option></select></div><details data-receipt-preview open><summary>Open PDF</summary></details>';
 let running=true;x.dom.window.fetch=async()=>({ok:true,json:async()=>({...x.response({poll_at:running?x.now+5:0}),selected_running:running,processing:running,filter:'problems',processing_message:'Processing June 2026 in the background.'})});
 await x.tick();assert.equal(h.querySelector('[data-receipt-document-filter]').hidden,true);assert.equal(x.dom.window.document.getElementById('receipt-processing-message').textContent,'Processing June 2026 in the background.');
 running=false;await x.tick();assert.equal(h.querySelector('[data-receipt-document-filter]').hidden,false);assert.equal(h.querySelector('select').value,'problems');assert.ok(h.querySelector('details').open);
 }finally{x.dom.window.close();}
});
