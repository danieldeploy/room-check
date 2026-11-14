(() => {
  'use strict';
  document.getElementById('receipt-request-error')?.focus();
  document.addEventListener('change',event=>{
    const select=event.target.closest('[data-receipt-filter]');if(!select?.form)return;
    if(select.hasAttribute('data-receipt-period')){const process=select.form.elements.namedItem('process');if(process)process.disabled=true;}
    select.form.requestSubmit();
  });
  const sizeRunSelect=()=>{
    const select=document.querySelector('select[name="process"]');if(!select)return;
    const style=window.getComputedStyle(select),measure=document.createElement('span');
    measure.style.cssText='position:absolute;visibility:hidden;white-space:pre;pointer-events:none';
    measure.style.font=style.font;measure.style.letterSpacing=style.letterSpacing;document.body.append(measure);
    let width=0;for(const option of select.options){measure.textContent=option.textContent;width=Math.max(width,measure.getBoundingClientRect().width);}
    measure.remove();if(width)select.closest('.receipt-run-select')?.style.setProperty('--receipt-run-width',Math.ceil(width+(parseFloat(style.paddingLeft)||0)+(parseFloat(style.paddingRight)||0)+4)+'px');
  };
  sizeRunSelect();document.fonts?.ready.then(sizeRunSelect);window.addEventListener('resize',sizeRunSelect);
  const status=document.getElementById('receipt-status');if(!status)return;
  const statusLabel=()=>document.querySelector('[data-status-label]'),warning=status.querySelector('[data-status-warning]');
  let timer=null,countdownTimer=null,inFlight=false;
  const refreshButtons=document.querySelectorAll('[data-receipt-refresh]');
  let offset=Number(status.dataset.server)*1000-Date.now(),revision='';
  const countdown=()=>{
    if(!['receipt_retry_wait','receipt_quota_wait'].includes(status.dataset.state))return;
    const seconds=Math.max(0,Math.ceil((Number(status.dataset.next)*1000-Date.now()-offset)/1000));
    const label=statusLabel();if(!label)return;
    label.textContent=seconds?status.dataset.retry.replace('{seconds}',String(seconds)):status.dataset.due;
  };
  const previewBusy=()=>document.querySelector('[data-receipt-preview][open], [data-receipt-preview] button[aria-expanded="true"]');
  const schedule=()=>{
    window.clearTimeout(timer);window.clearInterval(countdownTimer);timer=null;countdownTimer=null;
    const at=Number(status.dataset.poll);if(!Number.isFinite(at)||at<=0)return;
    if(['receipt_retry_wait','receipt_quota_wait'].includes(status.dataset.state))countdownTimer=window.setInterval(countdown,1000);
    countdown();timer=window.setTimeout(poll,Math.max(1000,at*1000-Date.now()-offset));
  };
  async function poll(){
    if(inFlight)return;inFlight=true;window.clearTimeout(timer);window.clearInterval(countdownTimer);refreshButtons.forEach(b=>{b.disabled=true;});
    const controller=new AbortController(),timeout=window.setTimeout(()=>controller.abort(),15000);let success=false;
    try{
      const response=await fetch(status.dataset.url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
      if(!response.ok)throw new Error('status');const result=await response.json();
      if(!result.progress || !Number.isFinite(result.progress.poll_at))throw new Error('status');
      status.dataset.state=result.progress.state;status.dataset.next=result.progress.next_at;status.dataset.poll=result.progress.poll_at;
      offset=result.progress.server_time*1000-Date.now();const label=statusLabel();if(label)label.textContent=result.label;
      const globalError=document.getElementById('receipt-global-error');
      if(globalError){globalError.textContent=result.error||'';globalError.hidden=!result.error;}
      if(typeof result.can_start==='boolean'){
        document.querySelectorAll('[data-receipt-intake]').forEach(fieldset=>{fieldset.disabled=!result.can_start;});
        document.querySelectorAll('[data-receipt-start]').forEach(button=>{button.disabled=!result.can_start;});
        document.querySelectorAll('[data-receipt-start-blocked]').forEach(notice=>{notice.hidden=result.can_start;});
      }
      if(typeof result.processing==='boolean'){
        const message=document.getElementById('receipt-processing-message');if(message){message.hidden=!result.processing;if(typeof result.processing_message==='string')message.textContent=result.processing_message;}
      }
      if(result.process_labels && typeof result.process_labels==='object'){
        document.querySelectorAll('select[name="process"] option').forEach(option=>{
          if(typeof result.process_labels[option.value]==='string')option.textContent=result.process_labels[option.value];
        });
      }
      sizeRunSelect();
      document.getElementById('receipt-report').innerHTML=result.report;countdown();
      const history=document.getElementById('receipt-history');
      if(result.history!==null && result.revision!==revision && !previewBusy() && !history.contains(document.activeElement) && !history.dataset.editing){
        history.innerHTML=result.history;revision=result.revision;window.dispatchEvent(new Event('receipt-history-updated'));
      }
      if(typeof result.selected_running==='boolean'){
        document.querySelectorAll('[data-receipt-document-filter]').forEach(box=>{
          box.hidden=result.selected_running;const select=box.querySelector('select');if(!select)return;
          select.disabled=result.selected_running;
          if(typeof result.filter==='string')select.value=result.filter;
        });
      }
      success=true;warning.hidden=true;delete status.dataset.statusFailed;refreshButtons.forEach(b=>{b.hidden=true;});
    }catch{status.dataset.statusFailed='true';warning.textContent=status.dataset.unavailable;warning.hidden=false;refreshButtons.forEach(b=>{b.hidden=false;});}
    finally{window.clearTimeout(timeout);inFlight=false;refreshButtons.forEach(b=>{b.disabled=false;});if(success)schedule();}
  }
  document.getElementById('receipt-history')?.addEventListener('input',event=>{if(event.target.closest('form'))document.getElementById('receipt-history').dataset.editing='true';});
  refreshButtons.forEach(button=>button.addEventListener('click',poll));
  refreshButtons.forEach(button=>{button.hidden=true;});countdown();schedule();
})();
