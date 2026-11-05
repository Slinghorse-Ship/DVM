window.KulturhusPage.register('bank-journal', pageContext => {
(() => {
  'use strict';

  document.querySelectorAll('[data-bank-journal]').forEach(root => {
    // A paginated catalogue searches the complete ledger on the server.
    if (root.hasAttribute('data-bank-journal-paged')) return;
    const form = root.querySelector('[data-bank-journal-filter]');
    const search = root.querySelector('[data-bank-journal-search]');
    const status = root.querySelector('[data-bank-journal-status]');
    const rows = [...root.querySelectorAll('[data-bank-journal-row]')];
    const empty = root.querySelector('[data-bank-journal-empty]');
    const count = root.querySelector('[data-bank-journal-count]');
    const submit = root.querySelector('[data-bank-journal-submit]');
    if (!form || !search || !status || !count) return;

    const normal = value => String(value || '')
      .toLocaleLowerCase('de-DE')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '');
    const quantity = value => value === 1 ? 'Umsatz' : 'Umsätze';
    const update = () => {
      const query = normal(search.value.trim());
      const selected = status.value;
      let visible = 0;
      rows.forEach(row => {
        const matchesText = !query || normal(row.textContent).includes(query);
        const matchesStatus = !selected || row.dataset.assignment === selected;
        row.hidden = !(matchesText && matchesStatus);
        if (!row.hidden) visible += 1;
      });
      count.textContent = visible + ' ' + quantity(visible) + ' angezeigt';
      if (empty) empty.hidden = visible !== 0;
    };

    // The button remains a fallback for browsers without JavaScript.
    if (submit) submit.hidden = true;
    form.addEventListener('submit', event => { event.preventDefault(); update(); });
    search.addEventListener('input', update);
    status.addEventListener('change', update);
    update();
  });
})();
});
