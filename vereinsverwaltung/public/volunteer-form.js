window.KulturhusPage.register('volunteer-form', pageContext => {
// A changed draft must receive a fresh complete PDF review before it can be stored.
'use strict';
const volunteerInput = document.querySelector('[data-volunteer-input]');
const volunteerSave = document.querySelector('[data-volunteer-save]');
if (volunteerInput && volunteerSave) {
  const staleNotice = document.querySelector('[data-volunteer-dirty]');
  let dirty = false;
  const markChanged = () => {
    dirty = true;
    volunteerSave.querySelectorAll('button, input[type="checkbox"]').forEach(control => { control.disabled = true; });
    if (staleNotice) staleNotice.hidden = false;
  };
  volunteerInput.addEventListener('input', markChanged);
  volunteerInput.addEventListener('change', markChanged);
  volunteerSave.addEventListener('submit', event => {
    if (dirty) { event.preventDefault(); staleNotice?.scrollIntoView({ block: 'center' }); }
  });
}
});
