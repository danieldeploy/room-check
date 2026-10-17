const {test}=require('node:test');
const assert=require('node:assert/strict');
const {JSDOM}=require('jsdom');
const html=`<div class="vat-invoices"><section class="table-header up"><div class="row"><div class="small-12 columns"><table class="table-block"><thead><tr><th>Invoice #</th><th>Invoice Type</th><th>Period Starting</th><th>Period Ending</th><th>Net Fee</th><th>VAT Amount</th></tr></thead></table></div></div></section><div class="row"><div class="small-12 columns"><table class="table-block"><tbody><tr id="invoice-901"><td>901</td><td>Invoice</td><td><a href="/reports/vatinvoices/pdf/901">PDF</a></td></tr><tr class="show-more-buttons"><td><a href="/reports/vatinvoices/2#invoice-901">Show More</a><a href="/reports/vatinvoices/2#invoice-901">Show All</a></td></tr></tbody></table></div></div></div>`;
function fixture(markup){const dom=new JSDOM(markup,{url:'https://inbox.hostelworld.com/reports/vatinvoices',runScripts:'outside-only'});return {dom,page:{evaluate:async fn=>JSON.parse(JSON.stringify(dom.window.eval('('+fn.toString()+')()')))}};}
test('Hostelworld reads the separate header and body tables in one VAT region',async()=>{
 const {readHostelworldTable,hostelworldStructure}=await import('../../invoice-runner/hostelworld-collection.mjs');
 const f=fixture(html);
 try {const table=await readHostelworldTable(f.page);assert.equal(table.tables,1);assert.equal(table.headers[0],'Invoice #');assert.equal(table.rows[0].number,'901');assert.deepEqual(table.rows[0].links,['https://inbox.hostelworld.com/reports/vatinvoices/pdf/901']);assert.deepEqual(table.next,['https://inbox.hostelworld.com/reports/vatinvoices/2#invoice-901']);assert.equal(hostelworldStructure(table.headers).date_source,'pdf-date-v1');}finally{f.dom.window.close();}
});
test('ambiguous or missing VAT invoice headings are rejected',async()=>{
 const {readHostelworldTable}=await import('../../invoice-runner/hostelworld-collection.mjs');
 for(const markup of [html+html,html.replace('Invoice #','Unrelated'),html.replace('</section>','<table class="table-block"><th>Invoice #</th></table></section>')]){
  const f=fixture(markup);try{assert.equal((await readHostelworldTable(f.page)).tables,0);}finally{f.dom.window.close();}
 }
});
test('a combined VAT table and distinct Show All link retain the same invoice structure',async()=>{
 const {readHostelworldTable,hostelworldStructure}=await import('../../invoice-runner/hostelworld-collection.mjs');
 const combined=html.replace('</table></div></div></section><div class="row"><div class="small-12 columns"><table class="table-block">','').replace('href="/reports/vatinvoices/2#invoice-901">Show All','href="/reports/vatinvoices/7#invoice-901">Show All');
 const f=fixture(combined);
 try {const table=await readHostelworldTable(f.page);assert.equal(table.tables,1);assert.equal(table.rows.length,1);assert.equal(table.next.length,2);assert.equal(hostelworldStructure(table.headers).date_source,'pdf-date-v1');}finally{f.dom.window.close();}
});
