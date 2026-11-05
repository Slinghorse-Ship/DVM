window.KulturhusPage.register('inventory-pieces', pageContext => {
 'use strict';
 document.querySelectorAll('[data-piece-editor]').forEach(box => {
  if(box.dataset.ready)return;box.dataset.ready='1';
  const toggle=box.querySelector('[data-piece-toggle]'),rows=box.querySelector('[data-piece-rows]'),message=box.querySelector('[data-piece-message]');
  // Both modes prepare rows on the server. Never hide or disable entered values.
  toggle.addEventListener('change',()=>{
   message.textContent=rows.children.length&&!toggle.checked?window.KulturhusInterface.text('Vorbereitete Stückangaben bleiben erhalten. Zum Entfernen ausdrücklich „Alle Stückangaben verwerfen“ wählen.') : '';
  });
 });
});
