window.KulturhusPage.register('balance-clearings', pageContext => {
(() => {
  'use strict';
  document.querySelectorAll('[data-clearing-form]').forEach(form => {
    if (!window.crypto?.getRandomValues) return;
    // Repayments can refer to an advance entered above before either is saved.
    const refreshAdvances = () => {
      const choices = new Map();
      form.querySelectorAll('[data-clearing-rows="advances"] > details').forEach(row => {
        const field = key => row.querySelector(`[name$="[${key}]"]`);
        const id = field('id').value, from = field('from'), to = field('to');
        if (!field('voucher').value || !from.value || !to.value || !field('amount').value) return;
        choices.set(id, `${from.selectedOptions[0].textContent} → ${to.selectedOptions[0].textContent} · ${field('amount').value} € · ${form.elements.balance_year.value} · ${id.slice(0, 8)}`);
      });
      form.querySelectorAll('[data-clearing-rows="repayments"] select[name$="[advance]"]').forEach(select => {
        const selected = select.value;
        select.querySelectorAll('option[data-unsaved-advance]').forEach(option => option.remove());
        choices.forEach((label, id) => {
          let option = Array.from(select.options).find(candidate => candidate.value === id);
          if (!option) { option = document.createElement('option'); option.value = id; option.dataset.unsavedAdvance = '1'; select.append(option); }
          option.textContent = label;
        });
        // Keep an incomplete choice visible instead of silently selecting another case.
        if (selected && !Array.from(select.options).some(option => option.value === selected)) {
          const option = document.createElement('option'); option.value = selected;
          option.textContent = form.dataset.clearingCompleteAdvance || 'Vorschuss oben vervollständigen'; option.dataset.unsavedAdvance = '1'; select.append(option);
        }
        select.value = selected;
      });
    };
    form.addEventListener('input', refreshAdvances);
    form.addEventListener('change', refreshAdvances);
    form.querySelectorAll('[data-clearing-add]').forEach(button => {
      button.hidden = false;
      button.addEventListener('click', () => {
        const type = button.dataset.clearingAdd;
        const rows = form.querySelector(`[data-clearing-rows="${type}"]`);
        const template = form.querySelector(`[data-clearing-template="${type}"]`);
        const index = Number(rows.dataset.next);
        if (!Number.isInteger(index) || index >= 200) return;
        const id = Array.from(crypto.getRandomValues(new Uint8Array(16)), b => b.toString(16).padStart(2, '0')).join('');
        const fragment = template.content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(el => { el.name = el.name.replace('__row__', String(index)); });
        fragment.querySelector('[value="__id__"]').value = id;
        fragment.querySelector('details').open = true;
        const first = fragment.querySelector('select');
        rows.append(fragment); rows.dataset.next = String(index + 1); refreshAdvances(); first.focus();
      });
    });
    refreshAdvances();
  });
})();
});
