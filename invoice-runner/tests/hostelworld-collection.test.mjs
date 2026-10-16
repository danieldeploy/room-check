import test from 'node:test';
import assert from 'node:assert/strict';
import { hostelworldPdfIdentity, hostelworldStructure, validateHostelworldCollectionMap, collectHostelworldInvoices } from '../hostelworld-collection.mjs';
const input = {authMethod:'email',credentials:{hostel_number:'305209'},hostelworldAuthTargets:[{host:'inbox.hostelworld.com',path:'/login/'}],accountId:2,portal:'hostelworld',property:'305209',periodBasis:'issue_month',period:'2026-08'};
const headers=['Invoice #','Invoice Type','Period Starting','Period Ending','Net Fee','VAT Amount'];
const root='https://inbox.hostelworld.com/reports/vatinvoices';
const text='Hostelworld.com\nHostel Number: H305209\nDate: 3 August 2026\nPeriod: July 2026\nDue date: 8 September 2026';
test('PDF issue date is distinct from service month and due date',()=>{
 assert.deepEqual(hostelworldPdfIdentity(text,'305209'),{issued_on:'2026-08-03'});
 for(const value of [text.replace('3 August','31 February'),text+'\nInvoice date: 4 August 2026',text.replace('Date: 3 August 2026',''),text.replace('Hostelworld.com','Other issuer')]) assert.throws(()=>hostelworldPdfIdentity(value,'305209'));
 assert.throws(()=>hostelworldPdfIdentity(text,'77759'),/account_mismatch/);
 assert.throws(()=>hostelworldPdfIdentity(text,'.*'));
});
test('collection requires exact validated account, property and extraction strategy',()=>{
 const map={version:4,validated:true,strategy:'hostelworld-vat-v1',portal:'hostelworld',accountId:2,properties:{'305209':{...hostelworldStructure(headers),verified:true}}};
 assert.equal(validateHostelworldCollectionMap(map,input).verified,true);
 for(const patch of [{validated:false},{version:3},{accountId:3},{strategy:'other'}]) assert.throws(()=>validateHostelworldCollectionMap({...map,...patch},input));
 assert.throws(()=>validateHostelworldCollectionMap(map,{...input,periodBasis:'service_month'}));
});
function row(id) {return {id,number:id,links:[root+'/pdf/'+id]};}
function fixture(pages,dates) {
 let n=0,url=root; const downloaded=[];
 const page={url:()=>url,goto:async next=>{url=next;n++;}};
 const dependencies={identity:async()=>true,readTable:async()=>pages[n],download:async(_p,url)=>{const id=url.split('/').at(-1);downloaded.push(id);return Buffer.from(id).toString('base64');},readPdf:async content=>({issued_on:dates[Buffer.from(content,'base64').toString()]})};
 return {page,dependencies,downloaded};
}
const table=(ids,next=[])=>({tables:1,headers,rows:ids.map(row),next});
test('all pages are scanned by PDF date, cumulative rows downloaded once',async()=>{
 const f=fixture([table(['901','902'],[root+'/2#invoice-902']),table(['901','902','903'])],{'901':'2026-09-03','902':'2026-07-03','903':'2026-08-03'});
 const result=await collectHostelworldInvoices(f.page,input,null,f.dependencies);
 assert.deepEqual(result.documents.map(d=>d.number),['903']);
 assert.deepEqual(f.downloaded,['901','902','903']);
 assert.equal(result.verification.pages,2); assert.equal(result.verification.pagination_verified,true);
 assert.equal(result.documents[0].period_basis,'issue_month');
});
test('loops, foreign links, account changes and ambiguous rows fail without partial success',async()=>{
 const good=table(['901'],[root+'/2']);
 for(const pages of [[good,good],[table(['901'],['https://other.example/reports/vatinvoices/2'])],[{...table(['901']),rows:[{...row('901'),number:'902'}]}],[{...table(['901']),rows:[{...row('901'),links:['https://other.example/pdf/901']}]}]]) {
  const f=fixture(pages,{'901':'2026-08-03'});await assert.rejects(collectHostelworldInvoices(f.page,input,null,f.dependencies),/portal_changed/);
 }
 const f=fixture([table(['901'])],{'901':'2026-08-03'});f.dependencies.identity=async()=>false;
 await assert.rejects(collectHostelworldInvoices(f.page,input,null,f.dependencies),/account_mismatch/);
});
test('verification needs a PDF from the selected issue month; validated collection may be empty',async()=>{
 const f=fixture([table(['901'])],{'901':'2026-07-03'});
 await assert.rejects(collectHostelworldInvoices(f.page,input,null,f.dependencies),/verification_sample_missing/);
 const result=await collectHostelworldInvoices(f.page,input,hostelworldStructure(headers),f.dependencies);
 assert.deepEqual(result.documents,[]);
});
