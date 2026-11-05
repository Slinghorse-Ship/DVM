/* All mutation/review steps also work with scripts disabled. */
window.KulturhusPage.register('roles-native', () => {
 'use strict';
 for (const form of document.querySelectorAll('[data-native-role-editor]')) {
  const custom=form.querySelector('[name="role_input[data][website_custom]"]');
  const status=form.querySelector('[data-native-role-status]');
  if (!custom||!status) continue;
  const describe=()=>{status.textContent=custom.value==='1'?status.dataset.custom:status.dataset.default;};
  for(const field of form.querySelectorAll('select[name^="role_input[data][website_levels]"]'))field.addEventListener('change',()=>{custom.value='1';describe();});
  custom.addEventListener('change',describe);describe();
 }
});
