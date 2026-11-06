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
