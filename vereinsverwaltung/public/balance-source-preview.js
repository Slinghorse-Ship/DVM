window.KulturhusPage.register('balance-source-preview', pageContext => {
(() => {
  'use strict';
  const init = () => {
    const section = document.getElementById('balance-sources');
    if (!section || section.dataset.sourcePreviewReady) return;
    section.dataset.sourcePreviewReady = '1';
    const changed = event => {
      const field = event.target;
      if (!['recognised_on', 'private_paid_on', 'donation_use', 'classify_purchases'].includes(field.name)
          && !/^purchase_uses\[[1-9][0-9]?\]$/.test(field.name || '')) return;
      const form = field.closest('form[method="post"]');
      if (!form) return;
      const notice = form.querySelector('[data-source-stale]');
      if (notice) notice.hidden = false;
      form.querySelectorAll('button[value="balance-source-import"],button[value="balance-source-correct"]').forEach(button => button.disabled = true);
      const reviewed = form.querySelector('input[name="reviewed"]');
      if (reviewed) reviewed.checked = false;
    };
    section.addEventListener('input', changed);
    section.addEventListener('change', changed);
  };
  init();
})();
});
