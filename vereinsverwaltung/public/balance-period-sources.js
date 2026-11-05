window.KulturhusPage.register('balance-period-sources', pageContext => {
(() => {
  'use strict';
  const init = () => {
    const section = document.getElementById('period-sources');
    if (!section || section.dataset.sourceReady) return;
    section.dataset.sourceReady = '1';
    const result = section.querySelector('[data-period-source-result]');
    const notice = section.querySelector('[data-period-source-stale]');
    if (!result || !notice) return;
    const changed = event => {
      if (!event.target.closest('form[method="get"]')) return;
      result.hidden = true;
      notice.hidden = false;
      result.querySelectorAll('button,input,select,textarea').forEach(control => control.disabled = true);
    };
    section.addEventListener('input', changed);
    section.addEventListener('change', changed);
  };
  init();
})();
});
