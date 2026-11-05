window.KulturhusPage.register('invoice-review-progress', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
 class PageMutationObserver extends MutationObserver { constructor(callback) { super(callback); pageContext.cleanup(() => this.disconnect()); } }
(()=>{'use strict';
 document.querySelectorAll('[data-invoice-review-form]').forEach(form=>{
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(form,source,values);
  const check=form.querySelector('[data-review-check]'),summary=form.querySelector('[data-review-summary]'),list=form.querySelector('[data-review-issues]');
  if(!check||!summary||!list)return;
  let queued=false,last='',sequence=0;const fieldIds=new WeakMap();
  const fieldId=field=>{if(!fieldIds.has(field))fieldIds.set(field,++sequence);return fieldIds.get(field);};
  const labelOf=field=>{
   if(field.matches('[data-positions-reviewed]'))return t('Artikel, Preise und Gesamtsumme am Original prüfen und bestätigen');
   if(field.matches('[data-tax-rounding]'))return t('Rundungsverteilung am Original prüfen und bestätigen');
   const label=field.closest('label');
   const text=label?[...label.childNodes].filter(n=>n.nodeType===Node.TEXT_NODE).map(n=>n.textContent).join(' ').trim():'';
   const row=field.closest('[data-position-editor] > fieldset');
   return (row?row.querySelector('legend').textContent+' · ':'')+(text||field.getAttribute('aria-label')||t('Pflichtangabe prüfen'));
  };
  const reveal=field=>{
   for(let parent=field.parentElement;parent&&parent!==form;parent=parent.parentElement)if(parent.tagName==='DETAILS')parent.open=true;
   const input=field.previousElementSibling?.matches('[data-select-search-input]')?field.previousElementSibling:field;
   input.scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'center'});
   input.focus({preventScroll:true});
  };
  function render(){
   queued=false;
   // Read validity only; this overview never changes values, review checks or save permission.
   const invalid=[...form.elements].filter(field=>field.willValidate&&!field.validity.valid&&!(field.matches('[data-select-search-input]')&&field.nextElementSibling?.matches('select')&&!field.nextElementSibling.validity.valid));
   const purposeMissing=invalid.filter(field=>field.closest('[data-review-step="purpose"]'));
   const dataMissing=invalid.filter(field=>field.closest('[data-review-step="data"]'));
   const paymentMissing=invalid.filter(field=>field.closest('[data-review-step="payment"]'));
   const paymentSource=form.querySelector('[name=payment_source]')?.value||'none';
   const enabled=form.querySelector('[data-positions-enabled]')?.checked;
   const rows=enabled?[...form.querySelectorAll('[data-position-editor] > fieldset')]:[];
   const openAccounting=rows.filter(row=>!row.querySelector('[data-field=sphere]')?.value||!row.querySelector('[data-field=account]')?.value).length;
   const state=JSON.stringify([invalid.map(field=>[fieldId(field),labelOf(field)]),purposeMissing.length,dataMissing.length,paymentMissing.length,paymentSource,rows.length,openAccounting,enabled]);
   if(state===last)return;
   last=state;check.hidden=false;
   const purposeStatus=form.querySelector('[data-review-purpose-status]'),dataStatus=form.querySelector('[data-review-data-status]'),paymentStatus=form.querySelector('[data-review-payment-status]'),saveStatus=form.querySelector('[data-review-save-status]');
   if(purposeStatus)purposeStatus.textContent=purposeMissing.length?t('Noch auswählen'):t('Ausgewählt');
   if(dataStatus)dataStatus.textContent=dataMissing.length?t(dataMissing.length===1?t('{count} Pflichtangabe noch prüfen'):t('{count} Pflichtangaben noch prüfen'),{count:dataMissing.length}):t('Angaben eingetragen');
   if(paymentStatus)paymentStatus.textContent=paymentMissing.length?t('Noch auswählen'):t(paymentSource==='private_advance'?'Privat bezahlt':paymentSource==='club'?'Vom Verein bezahlt':'Noch nicht bezahlt');
   if(saveStatus)saveStatus.textContent=invalid.length?t('Vorher Angaben ergänzen'):t('Speichern möglich');
    summary.textContent=invalid.length?t(invalid.length===1?t('{count} Pflichtfeld fehlt'):t('{count} Pflichtfelder fehlen'),{count:invalid.length})+'.':t('Bereit zum Speichern.');
   list.replaceChildren();
   for(const field of invalid){const item=document.createElement('li'),button=document.createElement('button');button.type='button';button.className='invoice-review-jump';button.textContent=labelOf(field);button.addEventListener('click',()=>reveal(field));item.append(button);list.append(item);}
   list.hidden=!invalid.length;
    form.querySelector('[data-review-accounting-note]').textContent=openAccounting?t('Buchhaltung prüft die Zusatzangaben später.'):rows.length?t('Zusatzangaben automatisch vorgeschlagen.'):t('Gesamtbetrag wird gespeichert.');
  }
  const schedule=()=>{if(!queued){queued=true;requestAnimationFrame(render);}};
  form.addEventListener('input',schedule);form.addEventListener('change',schedule);form.addEventListener('invoice-review-update',schedule);form.addEventListener('invalid',schedule,true);
  // Dynamic OCR, supplier and inventory controls remain part of the same review overview.
  new PageMutationObserver(records=>{if(records.some(record=>!record.target.closest?.('[data-review-check],.invoice-review-progress')))schedule();}).observe(form,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','required']});
  render();
 });
})();
});
