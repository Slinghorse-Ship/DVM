window.KulturhusPage.register('gema-checklist',()=>{
 'use strict';
 for(const form of document.querySelectorAll('[data-gema-checklist]')){
  const choice=form.elements.needed,reason=form.elements.reason,label=reason.closest('[data-gema-reason]');
  const update=()=>{const needed=choice.value==='no';form.elements.done.disabled=choice.value!=='yes';label.hidden=!needed;reason.disabled=!needed;reason.required=needed;reason.setCustomValidity(needed&&!reason.value.trim()?(window.KulturhusInterface?.text('Begründung für die nicht erforderliche Anmeldung eingeben (höchstens 5000 Zeichen).')??'Begründung für die nicht erforderliche Anmeldung eingeben (höchstens 5000 Zeichen).'):'');};
  choice.addEventListener('change',update);reason.addEventListener('input',update);update();form.dataset.gemaReady='1';
 }
});
