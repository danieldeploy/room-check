(() => {
  'use strict';
  document.querySelectorAll('[data-receipt-filter]').forEach(select=>select.addEventListener('change',()=>select.form.requestSubmit()));
  const status=document.getElementById('receipt-status');if(!status)return;
  const label=status.querySelector('[data-status-label]'),warning=status.querySelector('[data-status-warning]');
  let offset=Number(status.dataset.server)*1000-Date.now(),revision='',failures=0;
  const countdown=()=>{
    if(status.dataset.state!=='receipt_retry_wait')return;
    const seconds=Math.max(0,Math.ceil((Number(status.dataset.next)*1000-Date.now()-offset)/1000));
    label.textContent=seconds?status.dataset.retry.replace('{seconds}',String(seconds)):status.dataset.due;
  };
  const previewBusy=()=>document.querySelector('[data-receipt-preview][open], [data-receipt-preview] button[aria-expanded="true"]');
  async function poll(){
    try{
      const response=await fetch(status.dataset.url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
      if(!response.ok)throw new Error('status');const result=await response.json();
      status.dataset.state=result.progress.state;status.dataset.next=result.progress.next_at;
      offset=result.progress.server_time*1000-Date.now();label.textContent=result.label;countdown();
      const globalError=document.getElementById('receipt-global-error');
      if(globalError){globalError.textContent=result.error||'';globalError.hidden=!result.error;}
      document.getElementById('receipt-report').innerHTML=result.report;
      const history=document.getElementById('receipt-history');
      if(result.history!==null && result.revision!==revision && !previewBusy() && !history.contains(document.activeElement) && !history.dataset.editing){
        history.innerHTML=result.history;revision=result.revision;window.dispatchEvent(new Event('receipt-history-updated'));
      }
      failures=0;warning.hidden=true;
    }catch{if(++failures>=3){warning.textContent=status.dataset.unavailable;warning.hidden=false;}}
    finally{window.setTimeout(poll,5000);}
  }
  document.getElementById('receipt-history')?.addEventListener('input',event=>{if(event.target.closest('form'))document.getElementById('receipt-history').dataset.editing='true';});
  window.setInterval(countdown,1000);countdown();window.setTimeout(poll,5000);
})();
