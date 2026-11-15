const {test}=require('node:test');const assert=require('node:assert/strict');const {JSDOM}=require('jsdom');
const labels={show:'Invoice',loading:'Loading',error:'Unavailable',pdfPrevious:'Previous',pdfNext:'Next',pdfZoomIn:'Zoom in',pdfZoomOut:'Zoom out',pdfPage:'Page {page} of {pages}'};
const tick=()=>new Promise(r=>setTimeout(r,20));
function fixture(){
 const dom=new JSDOM('<div id="preview"></div>');const container=dom.window.document.querySelector('div');
 Object.defineProperty(container,'clientWidth',{value:360});dom.window.HTMLCanvasElement.prototype.getContext=()=>({});
 const controller=new dom.window.AbortController();const calls=[];let destroyed=0;
 const pdfjs={getDocument(options){assert.equal(options.isEvalSupported,false);assert.ok(options.data instanceof Uint8Array);
  return {promise:Promise.resolve({numPages:3,getPage:async number=>({getViewport:({scale})=>({width:600*scale,height:900*scale}),
   render(options){calls.push({number,options});return {promise:Promise.resolve(),cancel(){}};},cleanup(){}})}),destroy(){destroyed++;return Promise.resolve();}};
 }};
 return {dom,container,controller,pdfjs,calls,destroyed:()=>destroyed};
}
test('mobile viewer renders one page, navigates, zooms and releases the worker',async()=>{
 const {openReceiptPdf}=await import('../../admin/assets/receipt-pdf.mjs');const f=fixture();
 try {
  const close=await openReceiptPdf(f.pdfjs,'https://hub.example/assets/',new Blob(['%PDF-synthetic']),f.container,labels,f.controller.signal);
  assert.deepEqual(f.calls.map(c=>c.number),[1]);assert.equal(f.container.querySelector('span').textContent,'Page 1 of 3');
  const buttons=f.container.querySelectorAll('button');assert.equal(buttons[0].disabled,true);
  buttons[1].click();await tick();assert.equal(f.calls.at(-1).number,2);
  buttons[3].click();await tick();assert.equal(f.calls.at(-1).options.viewport.width,540);
  const canvas=f.container.querySelector('canvas');assert.ok(canvas.width*canvas.height<=8000000);
  f.controller.abort();close();assert.equal(f.destroyed(),1);assert.equal(f.container.children.length,0);
 }finally{f.dom.window.close();}
});
test('closing while PDF loads destroys it and cannot restore a canvas',async()=>{
 const {openReceiptPdf}=await import('../../admin/assets/receipt-pdf.mjs');const f=fixture();let resolve,destroyed=0;
 try {
  f.pdfjs.getDocument=()=>({promise:new Promise(r=>resolve=r),destroy(){destroyed++;return Promise.resolve();}});
  const pending=openReceiptPdf(f.pdfjs,'/',new Blob(['x']),f.container,labels,f.controller.signal);
  await tick();f.controller.abort();resolve({numPages:1});await assert.rejects(pending,{name:'AbortError'});
  assert.equal(destroyed,1);assert.equal(f.container.children.length,0);
 }finally{f.dom.window.close();}
});
test('page limits and invalid PDFs fail without retaining the worker',async()=>{
 const {openReceiptPdf}=await import('../../admin/assets/receipt-pdf.mjs');
 for(const invalid of [true,false]) {const f=fixture();let destroyed=0;
  try {f.pdfjs.getDocument=()=>({promise:invalid?Promise.reject(Error('invalid')):Promise.resolve({numPages:51}),destroy(){destroyed++;return Promise.resolve();}});
   await assert.rejects(openReceiptPdf(f.pdfjs,'/',new Blob(['x']),f.container,labels,f.controller.signal));assert.equal(destroyed,1);assert.equal(f.container.children.length,0);
  }finally{f.dom.window.close();}
 }
});
