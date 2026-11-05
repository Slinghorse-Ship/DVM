window.KulturhusPage.register('catalog-import', pageContext => {
'use strict';
document.querySelectorAll('[data-catalog-import]').forEach(form => {
  const base = form.querySelector('[data-catalog-base]');
  if (base) base.addEventListener('change', () => { form.querySelector('[data-catalog-year]').value = Number(base.value) + 1; });
  form.addEventListener('submit', event => {
    if (event.defaultPrevented) return;
    if (form.dataset.busy === '1') { event.preventDefault(); return; }
    form.dataset.busy = '1';
    const progress = form.querySelector('[data-catalog-progress]');
    if (progress) progress.hidden = false;
    form.querySelectorAll('button').forEach(button => { button.disabled = true; });
  });
});
// Returning from a download or the back/forward cache must not strand the form.
pageContext.on(window, 'pageshow', () => {
  document.querySelectorAll('[data-catalog-import][data-busy="1"]').forEach(form => {
    delete form.dataset.busy;
    const progress = form.querySelector('[data-catalog-progress]');
    if (progress) progress.hidden = true;
    form.querySelectorAll('button').forEach(button => { button.disabled = false; });
  });
});
});
