window.KulturhusPage.register('inventory-validation', pageContext => {
 'use strict';
 const area=document.querySelector('.iv-content');if(!area)return;
 const text=source=>window.KulturhusInterface?.text(source)??source;
 function requirements(form){
  const note=form.querySelector('[name="data[value_note]"],[name="common[value_note]"]');if(!note)return;
  const prices=[...form.querySelectorAll('[name="data[unit_value]"],[name$="[unit_value]"],[data-unit-price]')].filter(p=>!p.closest('[data-piece-correction]'));
  const detailed=!!form.querySelector('[data-piece-rows] .iv-piece-row');
  for(const price of prices){const value=price.value.trim();price.setCustomValidity(value&&!/^\d{1,10}(?:[.,]\d{1,2})?$/.test(value)?text('Bitte einen Betrag wie 100,00 eingeben (ohne Tausendertrennzeichen).'):'');}
  note.required=prices.some(price=>{
   if(price.disabled||price.value.trim()==='')return false;
   const row=price.closest('.iv-piece-row');
   if(row)return row.querySelector('[name$="[value_note]"]').value.trim()==='';
   // A detailed opening stock uses the actual rows, not the parent value.
   return !(detailed&&price.name==='data[unit_value]');
  });
  if(!note.required)window.KulturhusFormValidation?.clear(note);
 }
 for(const form of area.querySelectorAll('form')){form.addEventListener('input',()=>requirements(form));form.addEventListener('change',()=>requirements(form));requirements(form);}
});
