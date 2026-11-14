const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const script=fs.readFileSync('admin/assets/receipt-status.js','utf8');
function setup(extra=''){
 const now=Math.floor(Date.now()/1000);
 const dom=new JSDOM(`${extra}<section id="receipt-status" data-url="receipts.php?status=1" data-state="receipt_retry_wait" data-next="${now+45}" data-server="${now}" data-retry="Retry in {seconds} seconds" data-due="Waiting for worker" data-unavailable="Unavailable"><strong data-status-label></strong><p data-status-warning hidden></p></section><div id="receipt-report"></div><div id="receipt-history"><form><input value="unsaved"></form></div>`,{runScripts:'outside-only',url:'https://check.welcomehostel.pt/admin/receipts.php'});
 const calls=[],timeouts=[];dom.window.setTimeout=(fn,ms)=>{timeouts.push([fn,ms]);return 1;};dom.window.setInterval=()=>1;
 dom.window.fetch=async(url,options)=>{calls.push([url,options]);return {ok:true,json:async()=>({progress:{state:'receipt_finished_pending',next_at:0,server_time:now},label:'Completed with pending items',report:'<strong>25</strong>',history:'<p>updated</p>',revision:'v2'})};};
 dom.window.eval(script);return {dom,calls,timeouts};
}
test('five second polling is read-only and displays persisted countdown then final state',async()=>{
 const {dom,calls,timeouts}=setup();try{
  assert.match(dom.window.document.querySelector('[data-status-label]').textContent,/Retry in 4[45] seconds/);assert.equal(timeouts[0][1],5000);
  await timeouts.shift()[0]();assert.equal(calls.length,1);assert.equal(calls[0][1].method,undefined);assert.equal(calls[0][1].credentials,'same-origin');assert.equal(calls[0][1].cache,'no-store');
  assert.equal(dom.window.document.querySelector('[data-status-label]').textContent,'Completed with pending items');assert.equal(dom.window.document.getElementById('receipt-history').textContent,'updated');assert.equal(timeouts[0][1],5000);
 }finally{dom.window.close();}
});
test('report refresh never discards an edited form or an open document',async()=>{
 for(const editing of [true,false]){const {dom,timeouts}=setup();try{
  const h=dom.window.document.getElementById('receipt-history');
  if(editing)h.querySelector('input').dispatchEvent(new dom.window.Event('input',{bubbles:true}));else h.insertAdjacentHTML('beforeend','<details data-receipt-preview open><summary>PDF</summary></details>');
  await timeouts.shift()[0]();assert.ok(h.querySelector('input'));assert.equal(h.querySelector('input').value,'unsaved');assert.equal(dom.window.document.getElementById('receipt-report').textContent,'25');
 }finally{dom.window.close();}}
});
test('polling clears resolved connection errors and displays new safe errors as text',async()=>{
 const {dom,timeouts}=setup();try{
  const alert=dom.window.document.createElement('div');alert.id='receipt-global-error';alert.textContent='Old missing key';dom.window.document.body.prepend(alert);
  await timeouts.shift()[0]();assert.equal(alert.textContent,'');assert.equal(alert.hidden,true);
  dom.window.fetch=async()=>({ok:true,json:async()=>({progress:{state:'receipt_interrupted',next_at:0,server_time:Math.floor(Date.now()/1000)},label:'Interrupted',report:'',history:null,revision:'v3',error:'<safe error>'})});
  await timeouts.shift()[0]();assert.equal(alert.hidden,false);assert.equal(alert.textContent,'<safe error>');assert.equal(alert.children.length,0);
 }finally{dom.window.close();}
});

test('failed submission stays visible and focused while workflow polling continues',async()=>{
 const {dom,timeouts}=setup('<div id="receipt-request-error" role="alert" tabindex="-1">Request refused</div><div id="receipt-global-error"></div>');try{
  const alert=dom.window.document.getElementById('receipt-request-error');
  assert.equal(dom.window.document.activeElement,alert);
  await timeouts.shift()[0]();await timeouts.shift()[0]();
  assert.equal(alert.textContent,'Request refused');assert.equal(alert.hidden,false);
 }finally{dom.window.close();}
});
test('start control follows global activity even when viewing an older pending run',async()=>{
 const {dom,timeouts}=setup('<button data-receipt-start>Start</button><p data-receipt-start-blocked hidden>Another run is active</p>');try{
  const button=dom.window.document.querySelector('[data-receipt-start]'),notice=dom.window.document.querySelector('[data-receipt-start-blocked]');
  for(const canStart of [false,true]){
   dom.window.fetch=async()=>({ok:true,json:async()=>({progress:{state:'receipt_finished_pending',next_at:0,server_time:Math.floor(Date.now()/1000)},label:'Pending',report:'',history:null,revision:'v3',can_start:canStart})});
   await timeouts.shift()[0]();assert.equal(button.disabled,!canStart);assert.equal(notice.hidden,canStart);
  }
 }finally{dom.window.close();}
});
