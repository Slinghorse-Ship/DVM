window.KulturhusPage.register('balance-period-spheres', pageContext => {
(() => {
  'use strict';
  const init = () => document.querySelectorAll('[data-period-sphere]').forEach(form => {
    if (form.dataset.sphereReady) return;
    const choice = form.querySelector('[name="sphere_case"]');
    if (!choice) return;
    form.dataset.sphereReady = '1';
    const update = () => {
      for (const [selector, show] of [
        ['[data-sphere-value]', ['taxable_value', 'taxable_return', 'exempt_book', 'exempt_value'].includes(choice.value)],
        ['[data-sphere-life]', choice.value === 'taxable_value'],
      ]) {
        const group = form.querySelector(selector);
        if (!group) continue;
        group.hidden = !show;
        group.querySelectorAll('input').forEach(input => {
          input.disabled = !show;
          input.required = show;
        });
      }
    };
    choice.addEventListener('change', update);
    update();
  });
  init();
})();
});
