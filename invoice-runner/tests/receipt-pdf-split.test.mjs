import test from 'node:test';
import assert from 'node:assert/strict';
import { splitPdf, MAX_PAGES } from '../receipt-pdf-split.mjs';
import pdfLib from '../vendor/pdf-lib/pdf-lib.cjs';
const { PDFDocument, degrees, StandardFonts }=pdfLib;
test('PDF pages preserve size, rotation and content, with stable individual checksums',async()=>{
  const source=await PDFDocument.create({updateMetadata:false});
  const font=await source.embedFont(StandardFonts.Helvetica);
  for(const [n,size] of [[0,[200,300]],[1,[500,200]],[2,[250,400]]]) {
    const page=source.addPage(size); page.drawText('Synthetic invoice '+(n+1),{x:15,y:100,font,size:12});
    page.setRotation(degrees(n*90));
  }
  const bytes=await source.save(); const result=await splitPdf(bytes);
  assert.equal(result.count,3);
  assert.deepEqual(await splitPdf(bytes),result);
  for (const [n,p] of result.pages.entries()) {
    const doc=await PDFDocument.load(p.bytes); assert.equal(doc.getPageCount(),1);
    assert.deepEqual(doc.getPage(0).getSize(),source.getPage(n).getSize());
    assert.equal(doc.getPage(0).getRotation().angle,n*90);
    assert.ok(doc.getPage(0).node.Contents());
    assert.deepEqual(await splitPdf(p.bytes),{count:1,pages:[]});
  }
});
test('Rejects malformed, oversized and excessive-page PDFs',async()=>{
  await assert.rejects(splitPdf(Buffer.from('%PDF-1.4 broken')));
  await assert.rejects(splitPdf(Buffer.alloc(10*1024*1024+1)),/receipt_split_failed/);
  const doc=await PDFDocument.create(); for(let n=0;n<=MAX_PAGES;n++) doc.addPage();
  await assert.rejects(splitPdf(await doc.save()),/receipt_split_limit/);
});

test('assembly preserves source pages and can restore printed order across files',async()=>{
 const {assemblePdf}=await import('../receipt-pdf-split.mjs');
 const a=await PDFDocument.create({updateMetadata:false});a.addPage([200,300]);
 const b=await PDFDocument.create({updateMetadata:false});b.addPage([400,500]).setRotation(degrees(90));
 const first=Buffer.from(await a.save()),second=Buffer.from(await b.save());
 const result=await assemblePdf([{bytes:first,format:'pdf'},{bytes:second,format:'pdf'}],[2,1]);
 const merged=await PDFDocument.load(result.bytes);
 assert.equal(merged.getPageCount(),2);assert.deepEqual(merged.getPage(0).getSize(),{width:400,height:500});
 assert.equal(merged.getPage(0).getRotation().angle,90);
 assert.deepEqual(result,await assemblePdf([{bytes:first,format:'pdf'},{bytes:second,format:'pdf'}],[2,1]));
 await assert.rejects(assemblePdf([{bytes:first,format:'pdf'},{bytes:second,format:'pdf'}],[1,1]),/receipt_split_failed/);
 assert.equal((await PDFDocument.load(first)).getPageCount(),1);
});
