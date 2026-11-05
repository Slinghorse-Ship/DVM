window.KulturhusPage.register('own-intangible-register', pageContext => {
(() => {
  'use strict';
  document.querySelectorAll('[data-own-register]').forEach(form => {
    form.addEventListener('click', event => {
      const button = event.target.closest('[data-own-add]');
      if (!button || !form.contains(button)) return;
      const group = button.closest('[data-own-rows]');
      const list = group.querySelector('[data-own-row-list]');
      const rows = Array.from(list.children);
      if (rows.length >= 200) return;
      const clone = rows[rows.length - 1].cloneNode(true);
      const index = rows.length;
      clone.querySelector('legend').textContent = (group.dataset.ownRows === 'cost' ? 'Kostenzeile ' : 'Jahresbewegung ') + (index + 1);
      clone.querySelectorAll('input, select, textarea').forEach(control => {
        control.name = control.name.replace(/\[\d+\]/, '[' + index + ']');
        if (control.type === 'hidden' && control.name.endsWith('[id]')) control.value = crypto.randomUUID().replaceAll('-', '');
        else if (control.type === 'checkbox') control.checked = false;
        else control.value = '';
      });
      list.appendChild(clone);
      clone.querySelector('select, input:not([type=hidden]), textarea').focus();
    });
  });
})();
});
