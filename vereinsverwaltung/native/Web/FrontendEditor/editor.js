/* Warn before discarding a local field edit; this script never sends or stores a draft. */
(() => {
  const form = document.querySelector('.fe-field-form');
  if (!form) return;
  let changed = false;
  form.addEventListener('input', () => { changed = true; });
  form.addEventListener('change', () => { changed = true; });
  form.addEventListener('submit', () => { changed = false; });
  window.addEventListener('beforeunload', event => {
    if (!changed) return;
    event.preventDefault();
    event.returnValue = '';
  });
})();
