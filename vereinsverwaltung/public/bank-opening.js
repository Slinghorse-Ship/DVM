window.KulturhusPage.register('bank-opening', () => {
 'use strict';
 document.querySelectorAll('[data-account-create],[data-account-settings]').forEach(form=>{
  const opening=form.querySelector('[name="opening"]'),toggle=form.querySelector('[data-opening-sign-toggle]');
  if(!opening||!toggle||form.dataset.openingSignReady)return;
  form.dataset.openingSignReady='1';
  const update=()=>{
   const select=form.querySelector('select[name="kind"]'),radio=form.querySelector('input[name="kind"]');
   const kind=select?select.value:radio?(form.querySelector('input[name="kind"]:checked')?.value||''):(form.dataset.openingKind||'');
   const bank=['offline','offline-manual','offline-online'].includes(kind),cash=kind==='cash';
   toggle.hidden=!bank||opening.matches(':disabled');
   opening.inputMode=bank||cash?'decimal':'text';
   toggle.setAttribute('aria-pressed',String(opening.value.trimStart().startsWith('-')));
  };
  toggle.addEventListener('click',()=>{const raw=opening.value.trimStart();opening.value=raw.startsWith('-')?raw.slice(1):'-'+raw;opening.dispatchEvent(new Event('input',{bubbles:true}));opening.focus();});
  opening.addEventListener('input',update);form.addEventListener('change',update);update();
 });
});
