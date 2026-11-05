window.KulturhusPage.register('invoice-purpose', pageContext => {
(()=>{'use strict';
 document.querySelectorAll('[data-invoice-purpose]').forEach(box=>{
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(box,source,values);
  const target=box.querySelector('[name=purpose]'),form=box.closest('form');
  const all=[...target.options].filter(o=>o.value).map(o=>({value:o.value,text:o.textContent}));
  const initial=target.value,metadata=JSON.parse(box.dataset.purposeOptions||'{}');
  const category=box.querySelector('[name=category]'),hint=box.querySelector('[data-purpose-hint]');
  let categoryEdited=box.dataset.posted==='1',fillingCategory=false;
  const group=document.createElement('select'),label=document.createElement('label');
  group.dataset.purposeGroup='';group.required=true;label.append(t('Vorgang verknüpfen (optional)'),group);
  for(const [value,info] of Object.entries(metadata)){
   if(all.some(o=>o.value.split('|')[0]===value))group.add(new Option(info.label,value));
  }
  group.value=initial.split('|')[0];box.prepend(label);
  const targetLabel=target.parentElement;targetLabel.firstChild.textContent=t('Veranstaltung suchen und auswählen');
  function updateCategory(type,changed){
   const info=metadata[type];hint.textContent=info?.hint||t('Gesamtbetrag genügt.');
   if(!category||!info)return;
   if(changed&&!categoryEdited){
    fillingCategory=true;category.value=info.category;category.dispatchEvent(new Event('change',{bubbles:true}));fillingCategory=false;
   }
  }
  function change(restore,changed){
   const type=group.value,rows=all.filter(o=>o.value.split('|')[0]===type),specific=['gema','artist','appointment'].includes(type);
   target.replaceChildren();
   if(specific||!rows.length)target.add(new Option(specific?t('Veranstaltung auswählen'):t('Bitte Rechnungszweck auswählen'),''));
   for(const row of rows)target.add(new Option(specific?row.text.replace(/^[^:]+: /,''):row.text,row.value));
   if(restore&&rows.some(o=>o.value===restore))target.value=restore;
   targetLabel.hidden=!specific;target.required=specific;target.dataset.selectSearch='';
   updateCategory(type,changed);if(changed||box.dataset.posted!=='1')target.dispatchEvent(new Event('change',{bubbles:true}));
  }
  category?.addEventListener('change',()=>{if(fillingCategory)return;categoryEdited=true;updateCategory(group.value,false);});
  group.addEventListener('change',()=>change('',true));change(initial,false);
   form.addEventListener('click',event=>{if(event.target.closest('a[href="#invoice-accounting-help"]')){const help=form.querySelector('[data-accounting-help]');if(help)help.open=true;}});
   form.addEventListener('invalid',event=>{const details=event.target.closest('[data-invoice-accounting]');if(details)details.open=true;},true);
 });
})();
});
