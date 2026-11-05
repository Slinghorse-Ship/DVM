window.KulturhusPage.register('partnership-review', pageContext => {
/* Add original fiscal movement rows without guessing amounts or confirming evidence. */
(() => {
  for (const root of document.querySelectorAll('[data-partnership-movements]')) {
    const list = root.querySelector('[data-partnership-movement-list]');
    const button = root.querySelector('[data-partnership-add-movement]');
    const status = root.querySelector('[data-partnership-movement-status]');
    if (!list || !button) continue;
    button.addEventListener('click', () => {
      const rows = list.querySelectorAll('[data-partnership-movement-row]');
      if (rows.length >= 200) {
        status.textContent = 'Höchstens 200 ursprüngliche Kapitalbewegungen je Jahresnachweis.';
        return;
      }
      const template = rows[rows.length - 1];
      if (!template) return;
      const index = rows.length;
      const row = template.cloneNode(true);
      const bytes = new Uint8Array(16);
      crypto.getRandomValues(bytes);
      const id = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
      for (const field of row.querySelectorAll('input, select, textarea')) {
        field.name = field.name.replace(/\[capital_movements\]\[\d+\]/, `[capital_movements][${index}]`);
        if (field.type === 'checkbox') field.checked = false;
        else if (field.type === 'hidden') field.value = id;
        else field.value = '';
      }
      row.querySelector('summary').textContent = `Steuerliche Kapitalbewegung ${index + 1}`;
      row.open = true;
      list.append(row);
      for (const select of root.querySelectorAll('[data-partnership-tax-event]')) {
        const option = document.createElement('option');
        option.value = id;
        option.textContent = `Steuerliche Kapitalbewegung ${index + 1}`;
        select.append(option);
      }
      status.textContent = `Kapitalbewegung ${index + 1} ergänzt. Originalbeträge und Nachweis noch eingeben und prüfen.`;
      row.querySelector('select').focus();
    });
  }
  for (const root of document.querySelectorAll('[data-partnership-book-events]')) {
    const list = root.querySelector('[data-partnership-book-event-list]');
    const button = root.querySelector('[data-partnership-add-book-event]');
    const status = root.querySelector('[data-partnership-book-event-status]');
    if (!list || !button) continue;
    button.addEventListener('click', () => {
      const rows = list.querySelectorAll('[data-partnership-book-event-row]');
      if (rows.length >= 200) {
        status.textContent = 'Höchstens 200 ursprüngliche HGB-Ereignisse je Jahresnachweis.';
        return;
      }
      const template = rows[rows.length - 1];
      if (!template) return;
      const index = rows.length;
      const row = template.cloneNode(true);
      const bytes = new Uint8Array(16);
      crypto.getRandomValues(bytes);
      const id = Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
      for (const field of row.querySelectorAll('input, select, textarea')) {
        field.name = field.name.replace(/^(partnership_book_events)\[\d+\]/, `$1[${index}]`);
        if (field.type === 'checkbox') field.checked = false;
        else if (field.type === 'hidden') field.value = id;
        else field.value = '';
      }
      row.querySelector('legend').textContent = `Bewertungsereignis ${index + 1}`;
      for (const details of row.querySelectorAll('details')) details.open = false;
      list.append(row);
      status.textContent = `HGB-Ereignis ${index + 1} ergänzt. Originalbeträge und Nachweis noch eingeben und prüfen.`;
      row.querySelector('select').focus();
    });
  }
})();
});
