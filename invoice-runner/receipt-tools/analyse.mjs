import fs from 'node:fs/promises';
import { createCanvas, loadImage } from '@napi-rs/canvas';
import { readBarcodes, prepareZXingModule } from 'zxing-wasm/reader';
import { getDocument } from 'pdfjs-dist/legacy/build/pdf.mjs';
prepareZXingModule({overrides:{wasmBinary:await fs.readFile(new URL('./node_modules/zxing-wasm/dist/reader/zxing_reader.wasm',import.meta.url))}});
const MAX_PIXELS=12_000_000;
async function codes(canvas) {
  const image=canvas.getContext('2d').getImageData(0,0,canvas.width,canvas.height);
  const read=await readBarcodes(image,{formats:['QRCode'],tryHarder:true,tryRotate:true,tryInvert:true,maxNumberOfSymbols:8});
  return [...new Set(read.map(x=>x.text).filter(x=>typeof x==='string' && x.length<=4096 && /^A:\d{9}\*B:/.test(x)))];
}
export async function inspect(bytes,format) {
  if(bytes.length>10*1024*1024) throw new Error('receipt_split_limit');
  if(format==='jpg') {
    const img=await loadImage(bytes); if(img.width*img.height>40_000_000) throw new Error('receipt_split_limit');
    const scale=Math.min(1,Math.sqrt(MAX_PIXELS/(img.width*img.height)));
    const c=createCanvas(Math.ceil(img.width*scale),Math.ceil(img.height*scale));c.getContext('2d').drawImage(img,0,0,c.width,c.height);
    const qr=await codes(c);c.width=c.height=1;return {count:1,pages:[{number:1,qr,continuation:false}]};
  }
  if(format!=='pdf' || bytes.subarray(0,5).toString()!=='%PDF-') throw new Error('receipt_split_failed');
  const task=getDocument({data:new Uint8Array(bytes),isEvalSupported:false,useSystemFonts:false,disableFontFace:true,
    useWasm:false,verbosity:0,stopAtErrors:true,maxImageSize:40_000_000});
  const doc=await task.promise;
  try {
    if(doc.numPages<1 || doc.numPages>50) throw new Error('receipt_split_limit');
    const pages=[];
    for(let n=1;n<=doc.numPages;n++) {
      const page=await doc.getPage(n); const base=page.getViewport({scale:1});let qr=[];
      for(const dpi of [180,280]) {
        const scale=Math.min(dpi/72,Math.sqrt(MAX_PIXELS/(base.width*base.height)));
        const v=page.getViewport({scale});const canvas=createCanvas(Math.ceil(v.width),Math.ceil(v.height));
        await page.render({canvas,canvasContext:canvas.getContext('2d'),viewport:v}).promise;
        qr=await codes(canvas);canvas.width=canvas.height=1;if(qr.length)break;
      }
      const text=(await page.getTextContent()).items.map(x=>x.str??'').join(' ').slice(0,20000);
      pages.push({number:n,qr,structure_unknown:text.trim().length<30,continuation:/a\s+transportar|transportado|brought\s+forward|carried\s+forward|p[aá]g(?:ina)?\.?\s*\d+\s*(?:de|\/)\s*[2-9]/iu.test(text)});
      page.cleanup();
    }
    return {count:doc.numPages,pages};
  } finally { await task.destroy(); }
}
