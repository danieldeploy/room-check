const {test}=require('node:test');const assert=require('node:assert/strict');const fs=require('node:fs');const {JSDOM}=require('jsdom');
const script=fs.readFileSync('admin/assets/receipt-preview.js','utf8');
const tick=()=>new Promise(r=>setTimeout(r,10));
test('native disclosure opens full PDF, closes and releases bytes; errors remain retryable',async()=>{
 const dom=new JSDOM('<details data-receipt-preview data-url="receipt-document.php?id=synthetic" data-show="Invoice" data-loading="Loading" data-error="Unavailable"><summary>Invoice</summary><div data-preview-panel><p role="status"></p><div data-document-content></div></div></details>',{runScripts:'outside-only'});
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
