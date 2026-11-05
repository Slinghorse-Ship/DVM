(() => {
  'use strict';
  const contentText=source=>{const read=window.KulturhusInterface?.text;if(!read)return source;for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}return source;};
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  const pending = new WeakSet();
  const count = picker => {
    const ids = new Set([...picker.querySelectorAll('[name="recipient_ids[]"]')].map(input => input.value));
    picker.querySelectorAll('[name="press_choice[]"]').forEach(input => input.checked ? ids.add(input.value) : ids.delete(input.value));
    picker.querySelector('[data-press-count]').textContent = contentFormat('{count} ausgewählt',{count:ids.size});
    picker.querySelector('[value=selected]').textContent = contentText('Ausgewählt ·')+' '+ids.size;
  };
  document.addEventListener('change', event => {
    const picker = event.target.closest('[data-press-picker]');
    if (picker && event.target.name === 'press_choice[]') count(picker);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Enter' && event.target.matches('[data-press-picker] [name=press_query]')) {
      event.preventDefault();event.target.form.requestSubmit(event.target.closest('[data-press-picker]').querySelector('[value=search]'));
    }
  });
  document.addEventListener('submit', async event => {
    const form = event.target, button = event.submitter;
    if (!(form instanceof HTMLFormElement) || button?.name !== 'press_browse' || !form.querySelector('[data-press-picker]')) return;
    event.preventDefault();event.stopImmediatePropagation();
    if (pending.has(form)) return;
    const picker = form.querySelector('[data-press-picker]'), error = picker.querySelector('[data-press-error]');
    // Freeze only the contact chooser. The text editor and its dirty state stay intact.
    const body = new FormData(), fields = new Set(['action','csrf','operation','id','version','title','summary','body','body_html','kind','template','blocks_json','recipient_ids[]','press_picker_present','press_visible[]','press_choice[]','press_mode','press_page','press_query']);
    for (const [name,value] of new FormData(form)) if (fields.has(name) && typeof value === 'string') body.append(name,value);
    body.set('press_browse', button.value);
    const target = new URL(form.getAttribute('action') || location.href, location.href);
    if (target.origin !== location.origin) return;
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 30000);
    pending.add(form);picker.inert = true;picker.setAttribute('aria-busy','true');error.hidden = true;
    try {
      const response = await fetch(target.href, {method:'POST',body,credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
      const data = await response.json();
      if (!response.ok || typeof data.picker !== 'string') throw new Error(data.error || contentText('Empfänger konnten nicht geladen werden.'));
      if (!picker.isConnected) return;
      const template = document.createElement('template');template.innerHTML = data.picker;
      const next = template.content.querySelector('[data-press-picker]');
      if (!next) throw new Error(contentText('Empfänger konnten nicht geladen werden.'));
      picker.replaceWith(next);next.querySelector('[data-press-result]').focus({preventScroll:true});
    } catch (failure) {
      if (picker.isConnected) {error.textContent = failure.name === 'AbortError' ? contentText('Suche nicht bestätigt. Erneut suchen.') : (failure instanceof TypeError ? contentText('Empfänger konnten nicht geladen werden.') : contentText(failure.message));error.hidden = false;error.focus({preventScroll:true});}
    } finally {clearTimeout(timer);pending.delete(form);picker.inert=false;picker.removeAttribute('aria-busy');}
  }, true);
})();
