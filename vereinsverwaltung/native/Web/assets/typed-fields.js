/* Typed collections use ordinary successful form controls; no draft is sent by adding/reordering. */
(() => {
  if (window.dvmTypedFieldsReady) return;
  window.dvmTypedFieldsReady = true;
  const direct = (node, selector) => [...node.children].find(child => child.matches(selector));
  document.addEventListener('click', event => {
    const button = event.target.closest('[data-collection-add],[data-collection-up],[data-collection-down]');
    if (!button) return;
    const collection = button.closest('[data-typed-collection]');
    if (!collection) return;
    const items = direct(collection, '[data-collection-items]');
    const message = direct(collection, '[data-collection-message]');
    if (button.matches('[data-collection-add]')) {
      if (items.children.length >= Number(collection.dataset.maxItems)) {
        message.textContent = 'Eintragsgrenze erreicht. Einen vorhandenen Eintrag bearbeiten oder beim Speichern entfernen.';
        return;
      }
      const template = direct(collection, 'template[data-collection-template]');
      const next = Number(collection.dataset.nextIndex || items.children.length);
      collection.dataset.nextIndex = String(next + 1);
      const holder = document.createElement('template');
      holder.innerHTML = template.innerHTML.split(collection.dataset.collectionToken).join(String(next));
      const added = holder.content.firstElementChild;
      items.append(holder.content);
      added.querySelector('input:not([type=hidden]),select,textarea,button')?.focus();
      message.textContent = 'Neuer Eintrag. Änderungen mit der Seite speichern.';
    } else {
      const item = button.closest('[data-collection-item]');
      const sibling = button.matches('[data-collection-up]') ? item.previousElementSibling : item.nextElementSibling;
      if (!sibling) return;
      if (button.matches('[data-collection-up]')) items.insertBefore(item, sibling);
      else items.insertBefore(sibling, item);
      button.focus();
      message.textContent = 'Reihenfolge geändert. Änderungen mit der Seite speichern.';
    }
    collection.dispatchEvent(new Event('input', { bubbles: true }));
  });
})();
