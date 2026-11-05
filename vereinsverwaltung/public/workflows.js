window.KulturhusPage.register('workflows', pageContext => {
'use strict';
 const uiText = source => window.KulturhusInterface?.text(source) ?? source;
// The server repeats this distinction; no-JS forms remain usable.
for (const mode of document.querySelectorAll('[data-transfer-mode]')) {
 const form = mode.closest('form');
 const date = form.querySelector('[data-transfer-date]');
 const input = date?.querySelector('input');
 const note = form.querySelector('[data-transfer-instant-note]');
 const update = () => {
  const instant = mode.checked;
  if (date) date.hidden = instant;
  if (input) { input.disabled = instant; input.required = !instant; }
  if (note) note.hidden = !instant;
 };
 mode.addEventListener('change', update); update();
}
// Show only the fields needed for the selected path; keep normal HTML fallback.
for (const form of document.querySelectorAll('form')) {
 const mode = form.querySelector('[name="create_as"]');
 const membership = form.querySelector('[data-new-membership]');
 if (mode && membership) {
  const update = () => {
   const member = mode.value === 'member'; membership.hidden = !member;
   for (const input of membership.querySelectorAll('input,select,textarea')) input.disabled = !member;
   const button = document.querySelector('button[form="profile-form"]');
   if (button) button.textContent = uiText(member ? 'Mitglied anlegen' : 'Kontakt anlegen');
   const heading = document.querySelector('.page-heading h1');
   if (heading) heading.textContent = uiText(member ? 'Mitglied hinzufügen' : 'Kontakt hinzufügen');
   for (const selector of membership.querySelectorAll('[name="start_precision"],[name="family"]')) selector.dispatchEvent(new Event('change'));
  };
  mode.addEventListener('change', update);
  // Dependency handlers are installed below before the initial update.
  queueMicrotask(update);
 }
 const precision = form.querySelector('[name="start_precision"]');
 if (precision) {
  const update = () => {
   for (const name of ['start_year','start']) {
    const input = form.querySelector(`[name="${name}"]`); if (!input) continue;
    const visible = name === (precision.value === 'year' ? 'start_year' : 'start');
    input.closest('label').hidden = !visible; input.disabled = precision.disabled || !visible;
    input.required = visible && !precision.disabled && input.hasAttribute('data-membership-date-required');
   }
  }; precision.addEventListener('change', update); update();
 }
 const family = form.querySelector('[name="family"]');
 const familyName = form.querySelector('[name="family_name"]');
 if (family && familyName) {
  const update = () => { const visible = family.value === 'new'; familyName.closest('label').hidden = !visible; familyName.disabled = family.disabled || !visible; };
  family.addEventListener('change', update); update();
 }
}
const search = document.querySelector('[data-menu-search]');
if (search) {
 const groups = Array.from(document.querySelectorAll('.nav-group'));
 const results = document.getElementById('menu-search-results');
 const links = Array.from(results?.querySelectorAll('a') || []);
 const normal = text => text.toLocaleLowerCase('de').normalize('NFD').replace(/[\u0300-\u036f]/g,'');
 const synonyms = [['tab=account','passwort vergessen zurücksetzen ändern konto anmeldung zwei faktor password reset forgot change account login two factor'],['tab=gallery','bild bilder foto fotos hochladen upload album reihenfolge image images photo photos upload album order'],['view=members','person familie familienzahler eintritt mitgliedsnummer person family payer join membership number'],['tab=newsletter','rundmail email e-mail empfänger versand mail email recipients delivery'],['view=chat','nachricht unterhaltung mitteilungen message conversation notifications'],['tab=tax','steuer finanzamt jahresabschluss elster körperschaftsteuer tax year end accounts elster corporation'],['tab=receipts','rechnung beleg einnahme ausgabe invoice document income expense'],['view=documents','datei dokument upload ablage file document upload storage'],['tab=checks','hygiene versicherung genehmigung erste hilfe hygiene insurance permission first aid'],['tab=paper','logo unterschrift briefpapier logo signature stationery'],['view=messages','rundmail brief versand mail letter correspondence delivery'],['tab=bank','zahlung zuordnung konto kasse payment assignment account cash'],['view=gema','musik setlist lizenz music setlist licence']];
 search.addEventListener('input', () => {
  const query = normal(search.value.trim()); const terms = query.split(/[\s-]+/).filter(Boolean); let count = 0;
  for (const group of groups) group.hidden = Boolean(query);
  if (results) results.hidden = !query;
   for (const link of links) {
    const extra = synonyms.filter(([part]) => link.href.includes(part)).map(([,words]) => words).join(' ');
    const text = normal(link.textContent+' '+extra); const matches = Boolean(query) && terms.every(term => text.includes(term));
    link.hidden = !matches; if (matches) count++;
   }
  document.querySelector('[data-menu-search-status]').textContent = query ? (count ? count + ' ' + uiText('passende Funktionen') : uiText('Keine passende Funktion. Kürzeren Suchbegriff versuchen oder Suche löschen.')) : '';
 });
}
document.querySelector('main > section.error[role="alert"]')?.focus();
// These actions finish with a redirect. Keep downloads and preview forms reusable.
const redirectActions=new Set(['save','join','save-event','create-entry','create-transaction','create-goods','allocate','record-payment','mailing-save','chat-create']);
for(const form of document.querySelectorAll('form'))if(redirectActions.has(form.querySelector('[name="action"]')?.value)){
 form.addEventListener('submit',event=>{
  if(form.dataset.saving==='1'){event.preventDefault();return;}
  form.dataset.saving='1';
  queueMicrotask(()=>{if(event.defaultPrevented){delete form.dataset.saving;return;}form.setAttribute('aria-busy','true');});
 });
}
pageContext.on(window, 'pageshow',()=>{for(const form of document.querySelectorAll('form[data-saving]')){delete form.dataset.saving;form.removeAttribute('aria-busy');}});

// Choose one recipient group before selecting individual invitation recipients.
for (const fieldset of document.querySelectorAll('[data-invitation-groups]')) {
 const boxes = Array.from(fieldset.querySelectorAll('input[name="groups[]"]'));
 const people = fieldset.querySelector('[data-invitation-people]');
 const selected = boxes.filter(box => box.checked).map(box => box.value);
 const label = document.createElement('label'); label.textContent = uiText('Einladung senden an');
 const picker = document.createElement('select'); picker.setAttribute('aria-label', uiText('Einladung senden an'));
 if (selected.length > 1) { const option = document.createElement('option'); option.value = 'combined'; option.textContent = boxes.filter(box => box.checked).map(box => box.closest('label').textContent.trim()).join(' + '); picker.append(option); }
 for (const box of boxes) { const option = document.createElement('option'); option.value = box.value; option.textContent = box.closest('label').textContent.trim(); picker.append(option); box.closest('label').hidden = true; }
 picker.value = selected.length > 1 ? 'combined' : selected[0] || 'members'; label.append(picker); fieldset.querySelector('legend').after(label);
 const update = () => { for (const box of boxes) box.checked = picker.value === 'combined' ? selected.includes(box.value) : box.value === picker.value;
  const individual = boxes.some(box => box.value === 'selected' && box.checked); people.hidden = !individual; people.open = individual;

 }; picker.addEventListener('change', update); update();
}
});
