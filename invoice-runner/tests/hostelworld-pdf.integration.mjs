import test from 'node:test';
import assert from 'node:assert/strict';
import { readHostelworldPdf } from '../hostelworld-collection.mjs';
const lines=['Hostelworld.com','Hostel Number: H305209','Date: 3 August 2026','Period: July 2026'];
let stream='BT /F1 12 Tf 40 780 Td 16 TL\n'+lines.map(x=>'('+x+') Tj T*\n').join('')+'ET\n';
const objects=['<< /Type /Catalog /Pages 2 0 R >>','<< /Type /Pages /Kids [3 0 R] /Count 1 >>','<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>','<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>','<< /Length '+stream.length+' >>\nstream\n'+stream+'endstream'];
let pdf='%PDF-1.4\n';const offsets=[];
for(const [i,obj] of objects.entries()){offsets.push(pdf.length);pdf+=(i+1)+' 0 obj\n'+obj+'\nendobj\n';}
const xref=pdf.length;pdf+='xref\n0 6\n0000000000 65535 f \n'+offsets.map(x=>String(x).padStart(10,'0')+' 00000 n \n').join('')+'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n'+xref+'\n%%EOF\n';
test('locked PDF.js extracts a generated document and rejects wrong property or truncation',async()=>{
 const content=Buffer.from(pdf).toString('base64');
 assert.deepEqual(await readHostelworldPdf(content,'305209'),{issued_on:'2026-08-03'});
 await assert.rejects(readHostelworldPdf(content,'77759'),/account_mismatch/);
 await assert.rejects(readHostelworldPdf(Buffer.from(pdf.slice(0,-10)).toString('base64'),'305209'),/invalid_document/);
});
