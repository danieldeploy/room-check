(() => {
  'use strict';
  const history=document.getElementById('receipt-history');if(!history)return;
  function valid(form){
    const date=form.querySelector('[data-review-date]');
    if(date){
      const m=/^(\d{2})\/(\d{2})\/(\d{4})$/.exec(date.value);
      const iso=m?`${m[3]}-${m[2]}-${m[1]}`:'';
      const parsed=iso?new Date(`${iso}T00:00:00Z`):null;
      const ok=parsed && !Number.isNaN(parsed.valueOf()) && parsed.toISOString().slice(0,10)===iso && iso>='2000-01-01' && iso<=form.dataset.dateMax;
      date.setCustomValidity(ok?'':date.placeholder);
    }
    return [...form.querySelectorAll('input[required]')].every(input=>input.validity.valid && (input.type==='checkbox'?input.checked:input.value.trim()!==''));
  }
  function update(form){
    const button=form.querySelector('[data-review-submit]');
    if(button)button.disabled=!!form.dataset.submitting || form.dataset.blocked==='true' || !valid(form);
  }
  function initialise(){history.querySelectorAll('[data-receipt-review]').forEach(update);}
  history.addEventListener('input',event=>{const form=event.target.closest('[data-receipt-review]');if(form)update(form);});
  history.addEventListener('change',event=>{const form=event.target.closest('[data-receipt-review]');if(form)update(form);});
  history.addEventListener('submit',event=>{
    const form=event.target.closest('[data-receipt-review]');if(!form)return;
    if(form.dataset.submitting || form.dataset.blocked==='true' || !valid(form)){event.preventDefault();update(form);return;}
    form.dataset.submitting='true';update(form);
  });
  window.addEventListener('receipt-history-updated',initialise);
  window.addEventListener('pageshow',()=>{history.querySelectorAll('[data-receipt-review]').forEach(form=>delete form.dataset.submitting);initialise();});
  initialise();
})();
