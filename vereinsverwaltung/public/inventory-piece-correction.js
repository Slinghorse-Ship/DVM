window.KulturhusPage.register('inventory-piece-correction', pageContext => {
 'use strict';const text=source=>window.KulturhusInterface?.text(source)??source;
 document.querySelectorAll('[data-piece-correction]').forEach(box=>{
  const fields=box.querySelector('[data-piece-correction-fields]');
  function update(){
   if(fields.disabled)return;
   for(const price of fields.querySelectorAll('input[name$="[unit_value]"]')){
    const note=price.closest('fieldset').querySelector('textarea[name$="[value_note]"]'),value=price.value.trim();
    note.required=value!=='';price.setCustomValidity(value&&!/^\d{1,10}(?:[.,]\d{1,2})?$/.test(value)?text('Bitte einen Betrag wie 100,00 eingeben (ohne Tausendertrennzeichen).'):'');
    if(!note.required)window.KulturhusFormValidation?.clear(note);
   }
  }
  fields.addEventListener('input',update);update();
  // Opening, paging and discarding are explicit server steps in both modes.
 });
});
