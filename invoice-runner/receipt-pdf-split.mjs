import fs from 'node:fs/promises';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { createHash } from 'node:crypto';
import pdfLib from './vendor/pdf-lib/pdf-lib.cjs';
const { PDFDocument } = pdfLib;
export const MAX_PAGES = 50;
export async function splitPdf(bytes) {
  if (bytes.length > 10 * 1024 * 1024 || Buffer.from(bytes).subarray(0,5).toString() !== '%PDF-') throw new Error('receipt_split_failed');
  const source = await PDFDocument.load(bytes, { updateMetadata:false, throwOnInvalidObject:true });
  const count = source.getPageCount();
  if (source.isEncrypted || count < 1 || count > MAX_PAGES) throw new Error('receipt_split_limit');
  if (count === 1) return { count, pages:[] }; // Preserve original single-page bytes/signature.
  const pages=[]; let total=0;
  for (let n=0;n<count;n++) {
    const target=await PDFDocument.create({ updateMetadata:false });
    const [page]=await target.copyPages(source,[n]); target.addPage(page);
    const data=Buffer.from(await target.save({ useObjectStreams:true, addDefaultPage:false }));
    total+=data.length;
    if (data.length>10*1024*1024 || total>50*1024*1024) throw new Error('receipt_split_limit');
    const check=await PDFDocument.load(data,{ updateMetadata:false, throwOnInvalidObject:true });
    if (check.getPageCount()!==1) throw new Error('receipt_split_failed');
    pages.push({ number:n+1, bytes:data, sha256:createHash('sha256').update(data).digest('hex'),
      md5Checksum:createHash('md5').update(data).digest('hex'), size:data.length });
  }
  return { count, pages };
}
async function main() {
  let input=''; for await (const chunk of process.stdin) { input+=chunk; if(input.length>8192) throw new Error('receipt_split_failed'); }
  const request=JSON.parse(input); const dir=await fs.realpath(request.directory);
  if (dir!==request.directory || !path.isAbsolute(dir) || dir.split(path.sep).includes('public_html')) throw new Error('receipt_split_failed');
  const stat=await fs.lstat(dir); if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error('receipt_split_failed');
  const file=path.join(dir,'source.pdf'); const source=await fs.lstat(file);
  if (!source.isFile() || source.isSymbolicLink() || source.size>10*1024*1024) throw new Error('receipt_split_failed');
  const result=await splitPdf(await fs.readFile(file));
  for (const page of result.pages) {
    await fs.writeFile(path.join(dir,'page-'+page.number+'.pdf'),page.bytes,{flag:'wx',mode:0o600}); delete page.bytes;
  }
  process.stdout.write(JSON.stringify(result));
}
if (process.argv[1] && import.meta.url===pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch(e=>{ process.stdout.write(JSON.stringify({error:e.message==='receipt_split_limit'?e.message:'receipt_split_failed'})); process.exitCode=1; });
}
