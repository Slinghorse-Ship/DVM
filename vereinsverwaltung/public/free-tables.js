window.KulturhusPage.register('free-tables', pageContext => {
 const {fetch, timeout: setTimeout} = pageContext;
 const uiText=source=>window.KulturhusInterface?.text(source)??source;
document.querySelectorAll('[data-free-column-extra]').forEach(row => { row.hidden=true; });
document.querySelectorAll('[data-free-reference]').forEach(input => input.addEventListener('focus', () => input.select()));
document.querySelectorAll('[data-free-add-column]').forEach(button => {
  button.hidden=false;
  button.disabled=!button.closest('form').querySelector('[data-free-column][hidden]');
  button.addEventListener('click', () => {
    const row = button.closest('form').querySelector('[data-free-column][hidden]');
    if (row) { row.hidden = false; row.querySelector('input').focus(); }
    button.disabled = !button.closest('form').querySelector('[data-free-column][hidden]');
  });
});
const selections = [...document.querySelectorAll('[data-free-select]')];
function updateSelected() { const label = document.querySelector('[data-free-selection]'); if(label) label.textContent = `${selections.filter(x => x.checked).length} ${uiText('ausgewählt')}`; }
document.querySelector('[data-free-select-all]')?.addEventListener('change', event => { selections.forEach(x => x.checked = event.target.checked); updateSelected(); });
selections.forEach(x => x.addEventListener('change', updateSelected));
// Serialize saves per row so typing during a request cannot overwrite newer edits.
const status = document.querySelector('[data-free-status]');
const states = [...document.querySelectorAll('[data-free-row]')].map(row => ({
  row, form: row.querySelector('form'), dirty: false, pending: null, timer: null, error: ''
})).filter(s => s.form);
pageContext.guard(()=>states.some(s=>s.dirty||s.pending));
function report() {
  if (!status) return;
  const error = states.find(s => s.error);
  status.textContent = error ? `${uiText('Nicht gespeichert: ')}${error.error}${uiText(' Eingabe korrigieren oder erneut versuchen.')}`
    : states.some(s => s.pending || s.dirty) ? uiText('Wird gespeichert …') : uiText('Alle Änderungen gespeichert.');
  status.toggleAttribute('data-error', !!error);
}
async function save(s) {
  if(pageContext.signal.aborted)return false;
  clearTimeout(s.timer);
  if (s.pending) { await s.pending; if (s.error) return false; return save(s); }
  if (!s.dirty) return !s.error;
  s.dirty = false; s.error = '';
  const data = new FormData(s.form);
  data.set('action', 'free-row'); data.set('autosave', '1');
  states.forEach(state => { const id=state.form.elements.row.value;if(id)data.append('shown_rows[]',id); });
  s.pending = (async () => {
    try {
      const response = await fetch(location.href, {method: 'POST', body: data, credentials: 'same-origin'});
      const result = await response.json();
      if (!response.ok || !result.row) throw new Error(result.error || uiText('Speichern fehlgeschlagen.'));
      s.form.elements.row.value = result.row;
      s.form.elements.version.value = result.version;
      s.row.dataset.freeId = result.row;
      for(const state of states){
        const calculated=result.calculations?.[state.form.elements.row.value];if(!calculated)continue;
        state.row.querySelectorAll('[data-free-calculation]').forEach(output=>{
          const index=Number(output.dataset.freeCalculation),error=calculated.errors[index];
          output.textContent=error ? uiText(error) : `${uiText('Ergebnis: ')}${calculated.types?.[index]==='boolean'?uiText(calculated.values[index]):calculated.values[index] || '—'}`;
          output.toggleAttribute('data-error',!!error);
        });
      }
      const selection = s.row.querySelector('[data-free-select]');
      if (selection) selection.value = `${result.row}:${result.version}`;
      if (!s.dirty) {delete s.row.dataset.dirty;window.KulturhusNavigation?.markClean(s.form);}
    } catch (error) {
      s.error = error instanceof SyntaxError || error instanceof TypeError ? uiText('Sitzung oder Verbindung prüfen.') : error.message;
      s.dirty = true;
    }
  })();
  report(); await s.pending; s.pending = null; report();
  if (s.error) return false;
  return s.dirty ? save(s) : true;
}
const submitted = new WeakSet();
for (const s of states) {
  s.row.querySelectorAll('[name="cells[]"]').forEach(input => {
    input.addEventListener('input', () => {
      s.dirty = true; s.row.dataset.dirty = '1'; clearTimeout(s.timer);
      s.timer = setTimeout(() => save(s), 650); report();
    });
    input.addEventListener('change', () => { s.dirty = true; s.row.dataset.dirty = '1'; save(s); });
  });
  s.form.addEventListener('submit', async event => {
    if(submitted.has(s.form))return;
    event.preventDefault();
    if (s.form.elements.action.value === 'free-delete' || event.submitter?.value==='delete') {
      if (!await window.KulturhusDialog.confirm(uiText('Diese Zeile löschen? Sie kann im Zeilenarchiv wiederhergestellt werden.'), {accept:uiText('Zeile löschen'),danger:true})) return;
      if (!(await flush()) || pageContext.signal.aborted || !s.form.isConnected) return;
      s.form.elements.confirmed.value = '1';
      submitted.add(s.form);
      try { s.form.requestSubmit(event.submitter || undefined); }
      finally { submitted.delete(s.form); }
    } else {
      // Add one blank row if this entry has not already been saved automatically.
      if (!s.form.elements.row.value) s.dirty = true;
      if (await flush()) await window.KulturhusNavigation.navigate(location.href,{replace:true});
    }
  });
}
async function flush() { return (await Promise.all(states.map(save))).every(Boolean); }
// Preserve pending edits when sorting, searching, exporting or changing the page.
document.querySelectorAll('.free-workspace a').forEach(link => link.addEventListener('click', async event => {
  if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  event.preventDefault(); if (await flush()) await window.KulturhusNavigation.navigate(link.href);
}));
document.querySelectorAll('.free-workspace form').forEach(form => {
  if (states.some(s => s.form === form)) return;
  const search = form.querySelector('input[type="search"]');
  const visit = async (replace=false) => {
    if (!(await flush()) || pageContext.signal.aborted) return;
    const target = new URL(form.action,location.href);target.search = new URLSearchParams(new FormData(form)).toString();
    const focus = search ? {selector:'.free-workspace input[type="search"]',start:search.selectionStart,end:search.selectionEnd,x:scrollX,y:scrollY} : null;
    await window.KulturhusNavigation.navigate(target.href,{replace,focus});
  };
  if(form.method==='get'&&search){
    let timer,composing=false;
    const schedule=()=>{clearTimeout(timer);if(!composing)timer=setTimeout(()=>visit(true),500);};
    search.addEventListener('compositionstart',()=>{composing=true;clearTimeout(timer);});
    search.addEventListener('compositionend',()=>{composing=false;schedule();});
    search.addEventListener('input',schedule);
  }
  form.addEventListener('submit', async event => {
    if(submitted.has(form))return;
    // A clean POST must retain native download semantics. Re-entering submit
    // from its own resolved microtask is ignored by the browser.
    if(form.method!=='get'&&!states.some(s=>s.dirty||s.pending||s.error))return;
    event.preventDefault();
    if(form.method==='get'){await visit();return;}
    if (!(await flush()) || pageContext.signal.aborted) return;
    const button=event.submitter;
    setTimeout(()=>{
      if(!form.isConnected||pageContext.signal.aborted)return;
      submitted.add(form);
      try { form.requestSubmit(button || undefined); }
      finally { submitted.delete(form); }
    },0);
  });
});
pageContext.on(window, 'beforeunload', event => {
  if (states.some(s => s.dirty || s.pending)) { event.preventDefault(); event.returnValue = ''; }
});
document.querySelector('.free-workspace')?.setAttribute('data-free-ready','1');
});
