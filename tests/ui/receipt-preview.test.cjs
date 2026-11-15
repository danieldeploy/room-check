const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const script=fs.readFileSync('admin/assets/receipt-preview.js','utf8');
const tick=()=>new Promise(r=>setTimeout(r,10));
test('native disclosure opens full PDF, closes and releases bytes; errors remain retryable',async()=>{
 const dom=new JSDOM('<details data-receipt-preview data-url="receipt-document.php?id=synthetic" data-show="Invoice" data-loading="Loading" data-error="Unavailable"><summary>Invoice</summary><div data-preview-panel><p role="status"></p><div data-document-content></div></div></details>',{runScripts:'outside-only',url:'https://hub.example/admin/receipts.php'});
 try{
  let requests=0,revoked=0;dom.window.URL.createObjectURL=()=> 'blob:synthetic';dom.window.URL.revokeObjectURL=()=>revoked++;
  dom.window.fetch=async()=>{requests++;return {ok:requests!==2,headers:{get:()=> 'application/pdf'},blob:async()=>({type:'application/pdf'})};};
  dom.window.eval(script);const root=dom.window.document.querySelector('details'),summary=root.querySelector('summary');
  summary.click();await tick();assert.equal(root.open,true);assert.ok(root.querySelector('iframe'));assert.equal(requests,1);
  summary.click();await tick();assert.equal(root.open,false);assert.equal(root.querySelector('iframe'),null);assert.equal(revoked,1);
  summary.click();await tick();assert.equal(root.querySelector('[role=status]').textContent,'Unavailable');
  summary.click();await tick();summary.click();await tick();assert.ok(root.querySelector('iframe'));assert.equal(requests,3);
 }finally{dom.window.close();}
});

function previewFixture(agent, type='application/pdf') {
 const dom=new JSDOM('<details data-receipt-preview data-url="receipt-document.php?id=synthetic" data-show="Invoice" data-loading="Loading" data-error="Unavailable"><summary>Invoice</summary><div data-preview-panel><p role="status"></p><div data-document-content></div></div></details>',{runScripts:'outside-only',url:'https://hub.example/admin/receipts.php'});
 Object.defineProperty(dom.window.navigator,'userAgent',{value:agent});
 dom.window.URL.createObjectURL=()=> 'blob:synthetic';dom.window.URL.revokeObjectURL=()=>{};
 dom.window.fetch=async()=>({ok:true,headers:{get:()=>type},blob:async()=>({type})});
 dom.window.eval(script.replace('await loadReceiptPdfRenderer()', 'await window.loadReceiptPdfRenderer()'));
 return dom;
}
test('mobile PDF uses inline renderer; close cancels it without a blob iframe',async()=>{
 const dom=previewFixture('Mozilla/5.0 (Linux; Android 16) Chrome/157 Mobile');
 try {
  let opened=0,destroyed=0,signal;
  dom.window.loadReceiptPdfRenderer=async()=>({open:async(blob,container,labels,pending)=>{
   opened++;signal=pending;container.appendChild(dom.window.document.createElement('canvas'));return ()=>destroyed++;
  }});
  const root=dom.window.document.querySelector('details');root.querySelector('summary').click();await tick();
  assert.equal(opened,1);assert.ok(root.querySelector('canvas'));assert.equal(root.querySelector('iframe'),null);
  root.querySelector('summary').click();await tick();assert.equal(signal.aborted,true);assert.equal(destroyed,1);
  assert.equal(root.querySelector('canvas'),null);
 } finally {dom.window.close();}
});
test('desktop including small windows keeps native PDF; JPEG keeps image on both devices',async()=>{
 for(const [agent,type,tag] of [['Windows Chrome/157','application/pdf','iframe'],['Windows Chrome/157','image/jpeg','img'],['Android Chrome/157 Mobile','image/jpeg','img']]) {
  const dom=previewFixture(agent,type);
  try {dom.window.innerWidth=360;dom.window.loadReceiptPdfRenderer=async()=>{throw Error('renderer must stay unloaded');};
   dom.window.document.querySelector('summary').click();await tick();assert.ok(dom.window.document.querySelector(tag));
  }finally{dom.window.close();}
 }
});
test('closing during lazy import does not open a mobile PDF; a load failure remains retryable',async()=>{
 const dom=previewFixture('Android Mobile');
 try {
  let resolve,opened=0;
  dom.window.loadReceiptPdfRenderer=()=>new Promise(r=>resolve=r);
  const root=dom.window.document.querySelector('details'),button=root.querySelector('summary');button.click();await tick();button.click();await tick();
  resolve({open:async()=>{opened++;return ()=>{};}});await tick();assert.equal(opened,0);
  dom.window.loadReceiptPdfRenderer=async()=>{throw Error('network');};button.click();await tick();assert.equal(root.querySelector('[role=status]').textContent,'Unavailable');
  button.click();await tick();dom.window.loadReceiptPdfRenderer=async()=>({open:async()=>{opened++;return ()=>{};}});button.click();await tick();assert.equal(opened,1);
 }finally{dom.window.close();}
});
