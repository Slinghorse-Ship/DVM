window.KulturhusPage.register('invoice-reimbursement', pageContext => {
 const {timeout: setTimeout, interval: setInterval} = pageContext;
(()=>{'use strict';
 document.querySelectorAll('[data-invoice-review-form]').forEach(form=>{
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(form,source,values);
  const source=form.querySelector('[name=payment_source]'),recipient=form.querySelector('[data-invoice-recipient]'),panel=form.querySelector('[data-reimbursement-panel]'),summary=form.querySelector('[data-reimbursement-summary-text]'),note=form.querySelector('[data-reimbursement-note]');
  if(!source||!panel)return;
  const sync=()=>{const value=source.value||'none',contact=form.querySelector('[name=reimbursement_contact]'),member=contact?.value?contact.selectedOptions?.[0]?.textContent?.trim():'';panel.hidden=value!=='private_advance';if(summary){summary.textContent='';if(value==='private_advance'){summary.append(t('Privat bezahlt'));if(member){summary.append(t(' von '));const strong=document.createElement('strong');strong.textContent=member;summary.append(strong);}else summary.append(t(': Mitglied auswählen'));}else if(value==='club')summary.textContent=t('Vom Verein bezahlt');else summary.textContent=t('Noch nicht bezahlt');}if(value==='private_advance'&&note)note.textContent=recipient?.value==='club'?t('Rechnung auf den Verein.'):t('Rechnung nicht auf den Verein: brutto erfassen, kein Vorsteuerabzug.');};
  form.addEventListener('change',event=>{if(event.target===source||event.target===recipient||event.target.matches('[name=reimbursement_contact]'))sync();},true);
  form.addEventListener('input',event=>{if(event.target===source||event.target.matches('[name=reimbursement_contact]'))sync();},true);
  source.addEventListener('click',()=>setTimeout(sync,0));
  let last=source.value;const watcher=setInterval(()=>{if(!document.contains(form)){window.clearInterval(watcher);return;}if(source.value!==last){last=source.value;sync();}},150);
  sync();
 });
})();
});
