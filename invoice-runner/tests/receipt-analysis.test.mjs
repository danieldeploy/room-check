import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { pathToFileURL } from 'node:url';
import pdfLib from '../vendor/pdf-lib/pdf-lib.cjs';
// CI installs the bounded receipt runtime separately; no browser dependency.
const runtime = new URL('../receipt-tools/node_modules/',import.meta.url);
const {writeBarcode,prepareZXingModule}=await import(new URL('zxing-wasm/dist/es/writer/index.js',runtime));
prepareZXingModule({overrides:{wasmBinary:await fs.readFile(new URL('zxing-wasm/dist/writer/zxing_writer.wasm',runtime))}});
const qr='A:500000000*B:500000000*C:PT*D:FT*E:N*F:20260408*G:FT TEST/1*H:TEST-1*I1:PT*I2:100.00*N:23.00*O:123.00*Q:abcd*R:1234';
test('local QR reads original scan and rotated PDF without network',async()=>{
  const {inspect}=await import('../receipt-tools/analyse.mjs');
  const result=await writeBarcode(qr,{format:'QRCode',scale:6});
  const doc=await pdfLib.PDFDocument.create({updateMetadata:false});
  const png=await doc.embedPng(await result.image.arrayBuffer());
  for(let n=0;n<2;n++) { const p=doc.addPage([600,800]);p.drawImage(png,{x:50,y:200,width:350,height:350});p.setRotation(pdfLib.degrees(n*90)); }
  const out=await inspect(Buffer.from(await doc.save()),'pdf');
  assert.equal(out.count,2); assert.deepEqual(out.pages.map(p=>p.qr),[[qr],[qr]]);
});
test('extract keeps related pages in original order and original dimensions',async()=>{
  const {extractPdf}=await import('../receipt-pdf-split.mjs');
  const doc=await pdfLib.PDFDocument.create({updateMetadata:false});
  for(let n=0;n<4;n++) doc.addPage([200+n*10,300]);
  const bytes=await doc.save(); const out=await extractPdf(bytes,[2,3]);
  assert.equal(out.pages.length,2); const check=await pdfLib.PDFDocument.load(out.bytes);
  assert.equal(check.getPageCount(),2); assert.equal(check.getPage(0).getWidth(),210); assert.equal(check.getPage(1).getWidth(),220);
  assert.deepEqual(await extractPdf(bytes,[2,3]),out);
  for(const pages of [[3,2],[1,1],[0],[5],[]]) await assert.rejects(extractPdf(bytes,pages));
});
