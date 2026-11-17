(() => {
  'use strict';
  const update=form=>{
    const select=form.querySelector('[data-correction-files]'),button=form.querySelector('[data-correction-submit]');
    button.disabled=!!form.dataset.loadingFiles || !!form.dataset.submitted || select.disabled || !form.checkValidity();
  };
  const preserve=form=>{const history=form.closest('#receipt-history');if(history)history.dataset.editing='true';};
  document.addEventListener('change',event=>{
    const form=event.target.closest('[data-receipt-correction]');if(form){if(event.target.matches('[data-correction-files]'))form.querySelector('input[type=checkbox]').checked=false;preserve(form);update(form);}
  });
  document.addEventListener('submit',event=>{
    const form=event.target.closest('[data-receipt-correction]');if(!form)return;
    update(form);if(form.querySelector('[data-correction-submit]').disabled){event.preventDefault();return;}
    form.dataset.submitted='true';update(form);
  });
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-correction-load]'),form=button?.closest('[data-receipt-correction]');
    if(!form || form.dataset.loadingFiles || form.dataset.submitted)return;
    preserve(form);form.dataset.loadingFiles='true';button.disabled=true;
    const select=form.querySelector('[data-correction-files]'),status=form.querySelector('[data-correction-status]');
    let excluded=form.querySelector('[data-correction-excluded]');
    if(!excluded){excluded=document.createElement('ul');excluded.dataset.correctionExcluded='';status.after(excluded);}
    excluded.replaceChildren();excluded.hidden=true;
    select.replaceChildren();select.disabled=true;form.querySelector('input[type=checkbox]').checked=false;status.textContent=form.dataset.loading;update(form);
    const controller=new AbortController(),timeout=window.setTimeout(()=>controller.abort(),15000);
    try {
      const response=await fetch(form.dataset.url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
      const result=await response.json();
      if(typeof result.error==='string'){status.textContent=result.error;return;}
      if(!response.ok || !Array.isArray(result.files) || result.files.length>500)throw new Error('files');
      for(const file of result.files){
        if(typeof file.token!=='string' || typeof file.name!=='string' || typeof file.path!=='string'
          || (file.reason!==undefined && typeof file.reason!=='string') || (!file.token && !file.reason) || (file.token && file.reason))throw new Error('files');
      }
      for(const file of result.files){
        const label=file.path+' / '+file.name;
        if(file.reason){const item=document.createElement('li');item.textContent=label+' — '+file.reason;excluded.append(item);}
        else{const option=document.createElement('option');option.value=file.token;option.textContent=label;select.append(option);}
      }
      excluded.hidden=!excluded.children.length;
      select.selectedIndex=-1;select.disabled=!select.options.length;status.textContent=select.options.length?'':form.dataset.empty;
    }catch{select.replaceChildren();select.disabled=true;excluded.replaceChildren();excluded.hidden=true;status.textContent=form.dataset.error;}
    finally{window.clearTimeout(timeout);delete form.dataset.loadingFiles;button.disabled=false;update(form);}
  });
})();
