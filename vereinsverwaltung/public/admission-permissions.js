window.KulturhusPage.register('admission-permissions', pageContext => {
'use strict';
for (const form of document.querySelectorAll('[data-board-permission]')) {
  const office = form.querySelector('[data-board-office-select]');
  const enabled = form.querySelector('[data-board-enabled]');
  const confirmed = form.querySelector('[data-board-confirmed]');
  const saved = form.querySelector('[data-board-check-status]');
  const changed = form.querySelector('[data-board-check-changed]');
  const resetCheck = () => {
    confirmed.checked = false;
    confirmed.required = enabled.checked || (office.value !== '' && office.value !== form.dataset.boardOffice);
    saved.hidden = true;
    changed.hidden = false;
  };
  office.addEventListener('change', resetCheck);
  enabled.addEventListener('change', resetCheck);
}
});
