(() => {
  'use strict';
  let active=null;
  const update=form=>{
    const select=form.querySelector('[data-correction-files]'),count=select.selectedOptions.length;
    form.querySelector('[data-correction-submit]').disabled=!!form.dataset.pickerOpen || !!form.dataset.submitted
      || select.disabled || !count || count>Number(form.dataset.max) || !form.checkValidity();
  };
  const preserve=form=>{const history=form.closest('#receipt-history');if(history)history.dataset.editing='true';};
  const previews=()=>window.dispatchEvent(new Event('receipt-history-updated'));
  const commit=(form,files)=>{
    const select=form.querySelector('[data-correction-files]'),list=form.querySelector('[data-correction-selected]');
    select.replaceChildren();list.replaceChildren();
    for(const file of files){
      const label=file.path+' / '+file.name,option=document.createElement('option'),item=document.createElement('li');
      option.value=file.token;option.textContent=label;option.selected=true;select.append(option);
      item.textContent=label;list.append(item);
    }
    select.disabled=!files.length;list.hidden=!files.length;
    form.querySelector('[data-correction-confirm]').checked=false;
    form.querySelector('[data-correction-status]').textContent='';update(form);
  };
  document.addEventListener('change',event=>{
    const form=event.target.closest('[data-receipt-correction]');if(form){preserve(form);update(form);}
  });
  document.addEventListener('submit',event=>{
    const form=event.target.closest('[data-receipt-correction]');if(!form)return;
    update(form);if(form.querySelector('[data-correction-submit]').disabled){event.preventDefault();return;}
    form.dataset.submitted='true';update(form);
  });
  document.addEventListener('click',async event=>{
    const button=event.target.closest('[data-correction-load]'),form=button?.closest('[data-receipt-correction]');
    if(!form || active || form.dataset.submitted)return;
    preserve(form);form.dataset.pickerOpen='true';button.disabled=true;update(form);
    const dialog=form.querySelector('[data-correction-picker]').content.firstElementChild.cloneNode(true);
    const status=dialog.querySelector('[data-picker-status]'),list=dialog.querySelector('[data-picker-list]');
    const apply=dialog.querySelector('[data-picker-apply]'),controller=new AbortController();
    let files=[],folders=[],chosen=new Set(),closed=false;
    const max=Number(form.dataset.max),old=Array.from(form.querySelector('[data-correction-files]').selectedOptions,o=>o.value);
    const close=()=>{
      if(closed)return;closed=true;controller.abort();window.clearTimeout(timeout);
      dialog.close();dialog.remove();previews();delete form.dataset.pickerOpen;button.disabled=false;active=null;update(form);button.focus();
    };
    const timeout=window.setTimeout(()=>controller.abort(),30000);
    active=close;
    dialog.querySelector('[data-picker-cancel]').addEventListener('click',close);
    dialog.addEventListener('cancel',event=>{event.preventDefault();close();});
    const count=()=>{
      dialog.querySelector('[data-picker-count]').textContent=dialog.dataset.count+': '+chosen.size+' / '+max;
      apply.disabled=!chosen.size || chosen.size>max;
      if(max>1)for(const input of list.querySelectorAll('[data-picker-choice]'))input.disabled=!input.checked && chosen.size>=max;
    };
    const render=(folderId,focus=false)=>{
      list.replaceChildren();const path=dialog.querySelector('[data-picker-path]');path.replaceChildren();
      let current=folders.find(f=>f.id===folderId),trail=[];
      while(current){trail.unshift(current);current=folders.find(f=>f.id===current.parent);}
      for(const folder of trail){
        const link=document.createElement('button');link.type='button';link.textContent=folder.name;
        if(folder.id===folderId)link.setAttribute('aria-current','location');
        link.addEventListener('click',()=>render(folder.id,true));path.append(link);
      }
      for(const folder of folders.filter(f=>f.parent===folderId).sort((a,b)=>a.name.localeCompare(b.name))){
        const link=document.createElement('button');link.type='button';link.className='receipt-picker-folder';link.textContent='📁 '+folder.name;link.dataset.i18nSkip='';
        link.addEventListener('click',()=>render(folder.id,true));list.append(link);
      }
      for(const file of files.filter(f=>f.folder===folderId).sort((a,b)=>a.name.localeCompare(b.name))){
        const row=document.createElement('div'),label=document.createElement('label'),input=document.createElement('input'),name=document.createElement('span');
        row.className='receipt-picker-file';input.type=max===1?'radio':'checkbox';input.name='receipt-picker-choice';input.checked=chosen.has(file.token);
        name.textContent=file.name;name.dataset.i18nSkip='';label.append(input,name);row.append(label);
        if(file.token){
          input.dataset.pickerChoice='';input.addEventListener('change',()=>{
            if(max===1)chosen.clear();
            if(input.checked)chosen.add(file.token);else chosen.delete(file.token);count();
          });
        }else{input.disabled=true;const reason=document.createElement('p');reason.textContent=file.reason;row.append(reason);}
        if(file.preview){
          const preview=form.querySelector('[data-correction-preview]').content.firstElementChild.cloneNode(true);
          preview.dataset.url=form.dataset.previewUrl+'&selection='+encodeURIComponent(file.preview);row.append(preview);
        }
        list.append(row);
      }
      status.textContent=!files.some(f=>f.token)?form.dataset.empty:(!list.children.length?dialog.dataset.folderEmpty:'');
      count();previews();if(focus)path.lastElementChild?.focus();
    };
    apply.addEventListener('click',()=>{
      if(apply.disabled)return;commit(form,files.filter(f=>f.token && chosen.has(f.token)));close();
    });
    document.body.append(dialog);dialog.showModal();status.textContent=form.dataset.loading;
    try {
      const response=await fetch(form.dataset.url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:controller.signal});
      const result=await response.json();if(closed)return;
      if(typeof result.error==='string'){commit(form,[]);status.textContent=result.error;return;}
      if(!response.ok || !Array.isArray(result.files) || result.files.length>500 || !Array.isArray(result.folders)
        || !result.folders.length || result.folders.length>40)throw new Error('files');
      const seen=new Set();
      for(const folder of result.folders){
        if(typeof folder.id!=='string' || !folder.id || seen.has(folder.id) || typeof folder.name!=='string'
          || typeof folder.parent!=='string' || (seen.size? !seen.has(folder.parent):folder.parent!==''))throw new Error('folders');
        seen.add(folder.id);
      }
      for(const file of result.files){
        if(typeof file.token!=='string' || typeof file.name!=='string' || typeof file.path!=='string'
          || typeof file.reason!=='string' || typeof file.preview!=='string' || !seen.has(file.folder)
          || (!file.token && !file.reason) || (file.token && file.reason))throw new Error('files');
      }
      files=result.files;folders=result.folders;
      chosen=new Set(old.filter(token=>files.some(f=>f.token===token)));
      if(chosen.size!==old.length)commit(form,files.filter(f=>f.token && chosen.has(f.token)));
      render(folders[0].id);
    }catch{if(!closed){commit(form,[]);status.textContent=form.dataset.error;}}
    finally{window.clearTimeout(timeout);}
  });
  window.addEventListener('pagehide',()=>active?.());
})();
