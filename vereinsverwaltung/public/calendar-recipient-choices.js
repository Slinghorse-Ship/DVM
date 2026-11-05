window.KulturhusPage.register('calendar-recipient-choices',pageContext=>{
'use strict';
for(const panel of document.querySelectorAll('[data-calendar-recipient]')){
 const form=panel.closest('form');
 const count=()=>{const selected=new Set(JSON.parse(form.elements.recipient_selected.value));for(const id of JSON.parse(form.elements.recipient_visible.value))selected.delete(id);for(const input of panel.querySelectorAll('[name="recipients[]"]:checked'))selected.add(input.value);panel.querySelector('[data-recipient-count]').textContent=(window.KulturhusInterface?.format?.('{selected} ausgewählt · {total} Suchtreffer',{selected:selected.size,total:panel.dataset.recipientTotal})??(selected.size+' ausgewählt · '+panel.dataset.recipientTotal+' Suchtreffer'));};
 for(const input of panel.querySelectorAll('[name="recipients[]"]'))input.addEventListener('change',count);
}
});
