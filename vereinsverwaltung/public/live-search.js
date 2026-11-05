'use strict';
// Search uses the same authorized response and module lifecycle as a menu change.
window.KulturhusPage.register('live-search', context => {
 const uiText = source => window.KulturhusInterface?.text(source) ?? source;
 for (const form of context.root.querySelectorAll('form[method="get"]')) {
  // These modules own their debounce, focus or result fragment.
  if (form.matches('[data-inventory-live-filter],[data-donation-source-search],[data-voucher-search-form]') || form.closest('.free-workspace')) continue;
  const input = [...form.querySelectorAll('input[name]')].find(field => !field.disabled && field.type !== 'hidden' && (field.type === 'search' || /^(?:q|\w+_q)$/.test(field.name)) && !field.matches('[data-bank-journal-search],[data-select-search-input]'));
  if (!input || !form.querySelector('[name="view"]')) continue;
  const destination = () => {
   const url = new URL(form.action, location.href);
   if (url.origin !== location.origin || url.pathname !== location.pathname) return null;
   url.search = new URLSearchParams(new FormData(form)).toString();url.hash = '';
   const ownPage = form.dataset.liveSearchPage;
   for (const key of [...url.searchParams.keys()]) if (key !== 'per_page' && (key === 'page' || key.endsWith('_page')) && (!ownPage || key === ownPage)) url.searchParams.delete(key);
   return url;
  };
  if (!destination()) continue;
  const status = document.createElement('span');status.className = 'muted';status.setAttribute('role','status');form.append(status);
  input.autocomplete = 'off';
  let timer, composing = false;
  const cancel = () => clearTimeout(timer);
  const search = async (automatic = true) => {
   if (composing || context.signal.aborted) return;
   if (automatic && window.KulturhusNavigation.isDirty()) {status.textContent = uiText('Bitte ungespeicherte Änderungen zuerst speichern.');return;}
   const target = destination();if (!target) return;
   const focus = {selector:'form[method="get"] input[name="'+CSS.escape(input.name)+'"]',start:input.selectionStart,end:input.selectionEnd,x:scrollX,y:scrollY};
   status.textContent = uiText('Suche läuft …');
   const changed = await window.KulturhusNavigation.navigate(target.href,{replace:true,focus});
   if (!changed && !context.signal.aborted) status.textContent = uiText('Suche nicht aktualisiert. Bitte erneut suchen.');
  };
  const schedule = () => {cancel();if(!composing) timer=context.timeout(search,500);};
  context.on(input,'compositionstart',()=>{composing=true;cancel();});
  context.on(input,'compositionend',()=>{composing=false;schedule();});
  context.on(input,'input',schedule);context.on(input,'search',schedule);
  for (const select of form.querySelectorAll('select')) context.on(select,'change',schedule);
  context.on(form,'submit',event=>{event.preventDefault();cancel();void search(false);});
 }
});
