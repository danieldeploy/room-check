// Render authenticated bytes in-page; no external viewer, links or PDF scripting.
export async function openReceiptPdf(pdfjs, assets, blob, container, labels, signal) {
  const doc=container.ownerDocument, win=doc.defaultView;
  let loading, pdf, rendering, observer, disposed=false, pageNumber=1, zoom=1;
  let serial=Promise.resolve(), revision=0, lastWidth=0;
  const panel=doc.createElement('div');panel.className='receipt-pdf';
  const toolbar=doc.createElement('div');toolbar.className='receipt-pdf-toolbar';
  const status=doc.createElement('p');status.setAttribute('role','status');
  const counter=doc.createElement('span');counter.setAttribute('aria-live','polite');
  const viewport=doc.createElement('div');viewport.className='receipt-pdf-viewport';
  viewport.tabIndex=0;viewport.setAttribute('aria-label',labels.show);
  const canvas=doc.createElement('canvas');canvas.setAttribute('role','img');
  canvas.setAttribute('aria-label',labels.show);viewport.append(canvas);
  const button=(label,action)=>{
    const item=doc.createElement('button');item.type='button';item.className='primary-button';
    item.textContent=label;item.addEventListener('click',action);return item;
  };
  const previous=button(labels.pdfPrevious,()=>{if(pageNumber>1){pageNumber--;schedule();}});
  const next=button(labels.pdfNext,()=>{if(pageNumber<pdf.numPages){pageNumber++;schedule();}});
  const smaller=button(labels.pdfZoomOut,()=>{zoom=Math.max(1,zoom-0.5);schedule();});
  const larger=button(labels.pdfZoomIn,()=>{zoom=Math.min(3,zoom+0.5);schedule();});
  toolbar.append(previous,counter,next,smaller,larger);panel.append(toolbar,status,viewport);
  function controls(busy) {
    previous.disabled=busy || pageNumber<=1;next.disabled=busy || pageNumber>=pdf.numPages;
    smaller.disabled=busy || zoom<=1;larger.disabled=busy || zoom>=3;
    counter.textContent=labels.pdfPage.replace('{page}',pageNumber).replace('{pages}',pdf.numPages);
  }
  function destroy() {
    if(disposed)return;disposed=true;revision++;
    signal.removeEventListener('abort',destroy);win.removeEventListener('resize',resize);
    observer?.disconnect();rendering?.cancel();
    // LoadingTask owns the document and worker, including a partially loaded PDF.
    if(loading) Promise.resolve(loading.destroy()).catch(()=>{});
    canvas.width=canvas.height=1;panel.remove();
  }
  function checkOpen() {if(disposed || signal.aborted)throw new win.DOMException('Closed','AbortError');}
  async function draw(current) {
    checkOpen();if(current!==revision)return;
    controls(true);status.textContent=labels.loading;
    const page=await pdf.getPage(pageNumber);
    try {
      checkOpen();if(current!==revision)return;
      const width=Math.max(1,viewport.clientWidth || container.clientWidth || 320);
      lastWidth=width;
      const base=page.getViewport({scale:1});
      const view=page.getViewport({scale:width/base.width*zoom});
      const pixels=Math.min(win.devicePixelRatio || 1,2,Math.sqrt(8000000/(view.width*view.height)));
      canvas.width=Math.max(1,Math.floor(view.width*pixels));canvas.height=Math.max(1,Math.floor(view.height*pixels));
      canvas.style.width=view.width+'px';canvas.style.height=view.height+'px';
      canvas.setAttribute('aria-label',labels.show+' — '+counter.textContent);
      rendering=page.render({canvasContext:canvas.getContext('2d'),viewport:view,transform:[pixels,0,0,pixels,0,0]});
      await rendering.promise;checkOpen();
      if(current===revision)status.textContent='';
    } finally {rendering=null;page.cleanup();if(!disposed && current===revision)controls(false);}
  }
  function schedule() {
    if(disposed)return Promise.resolve();
    const current=++revision;rendering?.cancel();
    serial=serial.catch(()=>{}).then(()=>draw(current));
    serial.catch(error=>{if(!disposed && current===revision && error.name!=='RenderingCancelledException')status.textContent=labels.error;});
    return serial;
  }
  function resize() {if(!disposed && viewport.clientWidth && viewport.clientWidth!==lastWidth)schedule();}
  signal.addEventListener('abort',destroy,{once:true});
  try {
    checkOpen();const data=new Uint8Array(await blob.arrayBuffer());checkOpen();
    loading=pdfjs.getDocument({data,cMapUrl:assets+'cmaps/',cMapPacked:true,
      standardFontDataUrl:assets+'standard_fonts/',wasmUrl:assets+'wasm/',isEvalSupported:false});
    pdf=await loading.promise;checkOpen();
    if(pdf.numPages<1 || pdf.numPages>50)throw new Error('page_limit');
    container.replaceChildren(panel);await schedule();checkOpen();
    win.addEventListener('resize',resize);
    if(win.ResizeObserver){observer=new win.ResizeObserver(resize);observer.observe(viewport);}
    return destroy;
  } catch(error) {destroy();throw error;}
}
