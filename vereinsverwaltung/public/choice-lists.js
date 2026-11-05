window.KulturhusPage.register('choice-lists', pageContext => {
'use strict';
// Progressive enhancement: selection is always explicit and scoped to visible matches.
for (const fieldset of document.querySelectorAll('[data-choice-list]')) {
 const rows = Array.from(fieldset.querySelectorAll('label')).filter(row => row.querySelector('input[type="checkbox"]'));
 const label = fieldset.dataset.choiceList;
 if (!rows.length) { const empty = document.createElement('p'); empty.textContent = 'Keine Einträge verfügbar.'; fieldset.append(empty); continue; }
 const normal = value => value.toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g, '');
 const toolbar = document.createElement('div'); toolbar.className = 'choice-toolbar';
 const searchLabel = document.createElement('label'); searchLabel.textContent = label + ' suchen';
 const search = document.createElement('input'); search.type = 'search'; search.setAttribute('aria-label', label + ' suchen'); searchLabel.append(search);
 const actions = document.createElement('div'); actions.className = 'doc-actions';
 const button = (text, fn) => { const b = document.createElement('button'); b.type = 'button'; b.className = 'secondary'; b.textContent = text; b.addEventListener('click', fn); actions.append(b); return b; };
 const matching = () => rows.filter(row => !row.hidden);
 const set = checked => { for (const row of matching()) row.querySelector('input').checked = checked; fieldset.dispatchEvent(new Event('change', {bubbles:true})); };
 const choose = button('Treffer auswählen', () => set(true));
 const clear = button('Treffer abwählen', () => set(false));
 const reset = button('Suche zurücksetzen', () => { search.value = ''; update(); search.focus(); });
 const status = document.createElement('p'); status.setAttribute('role', 'status'); status.className = 'muted';
 toolbar.append(searchLabel, actions, status); fieldset.querySelector('legend').after(toolbar);
 function update() {
  const query = normal(search.value.trim());
  for (const row of rows) row.hidden = !normal(row.textContent).includes(query);
  const visible = matching(), selected = rows.filter(row => row.querySelector('input').checked);
  const hidden = selected.filter(row => row.hidden).length;
  const limit = Number(fieldset.dataset.choiceLimit || 0);
  const wouldSelect = selected.length + visible.filter(row => !row.querySelector('input').checked).length;
  choose.disabled = !visible.length || (limit > 0 && wouldSelect > limit);
  clear.disabled = !visible.some(row => row.querySelector('input').checked);
  reset.hidden = !query;
  status.textContent = visible.length + ' von ' + rows.length + ' angezeigt · ' + selected.length + ' ausgewählt' + (hidden ? ' (davon ' + hidden + ' außerhalb der Suche)' : '') + '. ' + (!visible.length ? 'Suche ändern oder zurücksetzen.' : limit > 0 && wouldSelect > limit ? 'Höchstens ' + limit + ' auswählen; Suche weiter eingrenzen.' : 'Die Auswahl bleibt beim Suchen erhalten.');
 }
 search.addEventListener('input', update); fieldset.addEventListener('change', update); update();
}
});
