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
export async function extractPdf(bytes, numbers) {
  if(bytes.length>10*1024*1024 || Buffer.from(bytes).subarray(0,5).toString()!=='%PDF-') throw new Error('receipt_split_failed');
  const source=await PDFDocument.load(bytes,{updateMetadata:false,throwOnInvalidObject:true});
  const count=source.getPageCount();
  if(source.isEncrypted || count<1 || count>MAX_PAGES || !Array.isArray(numbers) || !numbers.length
    || numbers.some((n,i)=>!Number.isInteger(n)||n<1||n>count||(i>0&&n<=numbers[i-1]))) throw new Error('receipt_split_failed');
  let data=Buffer.from(bytes);
  if(numbers.length!==count) {
    const target=await PDFDocument.create({updateMetadata:false});
    for(const page of await target.copyPages(source,numbers.map(n=>n-1))) target.addPage(page);
    data=Buffer.from(await target.save({useObjectStreams:true,addDefaultPage:false}));
  }
  if(data.length>10*1024*1024) throw new Error('receipt_split_limit');
  return {pages:numbers,bytes:data,sha256:createHash('sha256').update(data).digest('hex'),md5Checksum:createHash('md5').update(data).digest('hex'),size:data.length};
}
/** Bounded assembly; callers supply only pinned private parts, never filesystem paths. */
export async function assemblePdf(parts, order=[]) {
  if(!Array.isArray(parts)||parts.length<1||parts.length>20) throw new Error('receipt_split_limit');
  const target=await PDFDocument.create({updateMetadata:false}); let size=0;
  for(const part of parts){
    size+=part.bytes.length;if(size>10*1024*1024)throw new Error('receipt_split_limit');
    if(part.format==='pdf'){
      const source=await PDFDocument.load(part.bytes,{updateMetadata:false,throwOnInvalidObject:true});
      if(source.isEncrypted)throw new Error('receipt_split_failed');
      if(target.getPageCount()+source.getPageCount()>MAX_PAGES)throw new Error('receipt_split_limit');
      for(const p of await target.copyPages(source,source.getPageIndices()))target.addPage(p);
    }else if(part.format==='jpg'){
      const image=await target.embedJpg(part.bytes);const page=target.addPage([image.width,image.height]);page.drawImage(image,{x:0,y:0,width:image.width,height:image.height});
    }else throw new Error('receipt_split_failed');
  }
  const count=target.getPageCount();if(count<1||count>MAX_PAGES)throw new Error('receipt_split_limit');
  const pages=order.length?order:Array.from({length:count},(_,i)=>i+1);
  if(pages.length!==count||new Set(pages).size!==count||pages.some(n=>!Number.isInteger(n)||n<1||n>count))throw new Error('receipt_split_failed');
  const final=await PDFDocument.create({updateMetadata:false});
  for(const p of await final.copyPages(target,pages.map(n=>n-1)))final.addPage(p);
  const bytes=Buffer.from(await final.save({useObjectStreams:true,addDefaultPage:false}));
  if(bytes.length>10*1024*1024)throw new Error('receipt_split_limit');
  return {pages,count,bytes,size:bytes.length,sha256:createHash('sha256').update(bytes).digest('hex'),md5Checksum:createHash('md5').update(bytes).digest('hex')};
}
async function main() {
  let input=''; for await (const chunk of process.stdin) { input+=chunk; if(input.length>8192) throw new Error('receipt_split_failed'); }
  const request=JSON.parse(input); const dir=await fs.realpath(request.directory);
  if (dir!==request.directory || !path.isAbsolute(dir) || dir.split(path.sep).includes('public_html')) throw new Error('receipt_split_failed');
  const stat=await fs.lstat(dir); if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error('receipt_split_failed');
  if(request.operation==='assemble'){
    if(!Array.isArray(request.parts)||request.parts.length<1||request.parts.length>20)throw new Error('receipt_split_limit');
    const parts=[];let total=0;
    for(const [i,format] of request.parts.entries()){
      const file=path.join(dir,'part-'+i);const info=await fs.lstat(file);total+=info.size;
      if(!info.isFile()||info.isSymbolicLink()||total>10*1024*1024)throw new Error('receipt_split_limit');
      parts.push({bytes:await fs.readFile(file),format});
    }
    const result=await assemblePdf(parts,request.pages);
    await fs.writeFile(path.join(dir,'document.pdf'),result.bytes,{flag:'wx',mode:0o600});delete result.bytes;
    process.stdout.write(JSON.stringify(result));return;
  }
  const file=path.join(dir,'source.pdf'); const source=await fs.lstat(file);
  if (!source.isFile() || source.isSymbolicLink() || source.size>10*1024*1024) throw new Error('receipt_split_failed');
  const bytes=await fs.readFile(file);
  if(request.operation==='inspect') {
    const {inspect}=await import('./receipt-tools/analyse.mjs');
    process.stdout.write(JSON.stringify(await inspect(bytes,request.format)));return;
  }
  if(request.operation==='extract') {
    const result=await extractPdf(bytes,request.pages);
    await fs.writeFile(path.join(dir,'document.pdf'),result.bytes,{flag:'wx',mode:0o600});delete result.bytes;
    process.stdout.write(JSON.stringify(result));return;
  }
  const result=await splitPdf(bytes);
  for (const page of result.pages) {
    await fs.writeFile(path.join(dir,'page-'+page.number+'.pdf'),page.bytes,{flag:'wx',mode:0o600}); delete page.bytes;
  }
  process.stdout.write(JSON.stringify(result));
}
export function runtimeFailure(error) {
  let text=''; const seen=new Set();
  for(let e=error;e && !seen.has(e) && seen.size<8;e=e.cause){seen.add(e);text+=' '+String(e.message??'').slice(0,4096);}
  if(/WebAssembly/i.test(text) && /memory|allocat/i.test(text))return 'wasm_memory';
  if(/failed to map segment|cannot allocate memory|std::bad_alloc|Fatal process out of memory/i.test(text))return 'memory_mapping';
  if(/GLIBC|GLIBCXX|CXXABI|cannot open shared object|invalid ELF/i.test(text))return 'native_abi';
  if(/Cannot find (module|package|native binding)|ERR_MODULE_NOT_FOUND|Failed to load native binding/i.test(text))return 'missing_dependency';
  if(/getBuiltinModule|Unexpected token|Unsupported engine/i.test(text))return 'node_version';
  if(/EACCES|Permission denied/i.test(text))return 'permission';
  return 'unclassified';
}
if (process.argv[1] && import.meta.url===pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch(e=>{ process.stdout.write(JSON.stringify({error:e.message==='receipt_split_limit'?e.message:'receipt_split_failed',diagnostic:runtimeFailure(e),node_version:process.versions.node})); process.exitCode=1; });
}
