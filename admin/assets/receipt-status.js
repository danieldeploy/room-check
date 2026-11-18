(() => {
  'use strict';
  document.getElementById('receipt-request-error')?.focus();
  document.addEventListener('change',event=>{
    const select=event.target.closest('[data-receipt-filter]');if(!select?.form)return;
    if(select.hasAttribute('data-receipt-period')){const process=select.form.elements.namedItem('process');if(process)process.disabled=true;}
    select.form.requestSubmit();
  });
  function updateBatchActions(form){
    const selected=form.querySelector('[data-batch-row]:checked:not(:disabled)');
    const verified=form.elements.checked_toc.checked;
    const others=form.querySelector('[data-batch-others]');
    if(others){
      others.hidden=selected?.dataset.resend!=='1';
      if(others.hidden)form.elements.others_present.checked=false;
    }
    for(const button of form.querySelectorAll('button[name="choice"]')){
      const eligible=button.value==='all_present' ? !selected
        : selected?.dataset[button.value==='resend_missing'?'resend':'assign']==='1';
      button.disabled=!verified || !eligible;
    }
  }
  const initializeBatches=()=>document.querySelectorAll('[data-batch-review]').forEach(updateBatchActions);
  document.addEventListener('change',event=>{
    const form=event.target.closest('[data-batch-review]');
    if(!form)return;
    if(event.target.matches('[data-batch-row]') && form.elements.others_present)form.elements.others_present.checked=false;
    if(event.target.matches('[data-batch-row]:checked')){
      for(const row of form.querySelectorAll('[data-batch-row]:checked'))row.checked=row===event.target;
    }
    updateBatchActions(form);
  });
  initializeBatches();
  window.addEventListener('receipt-history-updated',initializeBatches);
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
  function replaceHistory(history,markup){
    const drafts=new Map();
    const focus=document.activeElement;
    for(const form of history.querySelectorAll('[data-batch-review]')){
      const selected=form.querySelector('[data-batch-row]:checked');
      drafts.set(form.elements.batch.value,{version:form.elements.version.value,selected:selected?.value,
        verified:form.elements.checked_toc.checked,others:form.elements.others_present?.checked,focus:form.contains(focus)?[focus.name,focus.value]:null});
    }
    history.innerHTML=markup;
    for(const form of history.querySelectorAll('[data-batch-review]')){
      const draft=drafts.get(form.elements.batch.value);
      if(!draft)continue;
      const row=[...form.querySelectorAll('[data-batch-row]:not(:disabled)')].find(el=>el.value===draft.selected);
      if(row)row.checked=true;
      form.elements.checked_toc.checked=draft.verified && draft.version===form.elements.version.value;
      if(form.elements.others_present)form.elements.others_present.checked=draft.others && draft.version===form.elements.version.value;
      updateBatchActions(form);
      if(draft.focus){
        const control=[...form.elements].find(el=>el.name===draft.focus[0] && el.value===draft.focus[1] && !el.disabled);
        control?.focus({preventScroll:true});
      }
    }
    window.dispatchEvent(new Event('receipt-history-updated'));
  }
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
      const retryWorker=document.querySelector('[data-receipt-worker-retry]');
      if(retryWorker)retryWorker.hidden=!result.worker_blocked;
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
      const active=document.activeElement;
      const preserveFocus=history.contains(active) && !active.matches('[data-batch-review] input, [data-batch-review] button');
      if(result.history!==null && result.revision!==revision && !previewBusy() && !preserveFocus && !history.dataset.editing){
        replaceHistory(history,result.history);revision=result.revision;
      }
      success=true;warning.hidden=true;delete status.dataset.statusFailed;refreshButtons.forEach(b=>{b.hidden=true;});
    }catch{status.dataset.statusFailed='true';warning.textContent=status.dataset.unavailable;warning.hidden=false;refreshButtons.forEach(b=>{b.hidden=false;});}
    finally{window.clearTimeout(timeout);inFlight=false;refreshButtons.forEach(b=>{b.disabled=false;});if(success)schedule();}
  }
  document.getElementById('receipt-history')?.addEventListener('input',event=>{if(event.target.closest('form') && !event.target.closest('[data-batch-review]'))document.getElementById('receipt-history').dataset.editing='true';});
  refreshButtons.forEach(button=>button.addEventListener('click',poll));
  refreshButtons.forEach(button=>{button.hidden=true;});countdown();schedule();
})();
