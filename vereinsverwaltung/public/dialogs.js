'use strict';
(() => {
 const uiText = source => window.KulturhusInterface?.text(source) ?? source;
  if (window.KulturhusDialog) return;
  let active = false, sequence = 0, dismiss = null;
  function show(message, options = {}) {
    const cancelled = options.input ? null : false;
    // Repeated clicks must never queue multiple destructive confirmations.
    if (active) return Promise.resolve(cancelled);
    active = true;
    return new Promise(resolve => {
      const opener = document.activeElement;
      const selection = window.getSelection();
      const range = selection?.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
      const dialog = document.createElement('dialog');
      dialog.className = 'kh-dialog';
      const title = document.createElement('h2'), body = document.createElement('p');
      title.id = 'kh-dialog-title-' + (++sequence); body.id = 'kh-dialog-body-' + sequence;
      title.textContent = options.title || (options.input ? uiText('Angabe eingeben') : uiText('Bitte bestätigen'));
      body.textContent = String(message);
      dialog.setAttribute('aria-labelledby', title.id);
      dialog.setAttribute('aria-describedby', body.id);
      dialog.append(title, body);
      let input;
      if (options.input) {
        input = document.createElement('input'); input.type = 'text';
        input.setAttribute('aria-label', String(message)); input.value = options.value || '';
        input.maxLength = 2000; dialog.append(input);
      }
      const actions = document.createElement('div'); actions.className = 'kh-dialog-actions';
      const cancel = document.createElement('button'), accept = document.createElement('button');
      cancel.type = accept.type = 'button'; cancel.className = 'secondary';
      cancel.textContent = uiText('Abbrechen'); accept.textContent = options.accept || (options.input ? uiText('Übernehmen') : uiText('Bestätigen'));
      if (options.danger) accept.className = 'kh-dialog-danger';
      if (!options.notice) actions.append(cancel);
      actions.append(accept); dialog.append(actions);
      let settled = false;
      const finish = value => {
        if (settled) return; settled = true;
        if (dialog.open) dialog.close(); dialog.remove(); active = false; dismiss = null;
        if (opener?.isConnected) opener.focus({preventScroll:true});
        if (range?.commonAncestorContainer.isConnected) { selection.removeAllRanges(); selection.addRange(range); }
        resolve(value);
      };
      dismiss = () => finish(cancelled);
      cancel.addEventListener('click', () => finish(cancelled));
      accept.addEventListener('click', () => finish(input ? input.value : true));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish(cancelled); });
      dialog.addEventListener('close', () => finish(cancelled));
      dialog.addEventListener('keydown', event => {
        // Do not let sidebar Escape/Tab handlers interfere with the native modal focus trap.
        event.stopPropagation();
        if (input && event.key === 'Enter') { event.preventDefault(); finish(input.value); }
      });
      document.body.append(dialog);
      try { dialog.showModal(); (input || (options.notice ? accept : cancel)).focus(); input?.select(); }
      catch { finish(cancelled); } // Unsupported/broken modal: never proceed without confirmation.
    });
  }
  window.KulturhusDialog = Object.freeze({
    closeAll: () => dismiss?.(),
    confirm: (message, options = {}) => show(message, options),
    prompt: (message, value = '') => show(message, {input:true,value,accept:uiText('Übernehmen')}),
    alert: message => show(message, {notice:true,title:uiText('Hinweis'),accept:uiText('Schließen')})
  });

  const waiting = new WeakSet(), approved = new WeakSet();
  const snapshot = (form, button) => JSON.stringify([
    ...Array.from(new FormData(form), ([key, value]) => [key, typeof value === 'string' ? value : [value.name,value.size,value.type,value.name === '' && value.size === 0 ? 0 : value.lastModified]]),
    ['submitter',button?.name || '',button?.value || '',button?.formAction || '',button?.formMethod || ''],
    ['target',form.action,form.method,form.target]
  ]);
  document.addEventListener('submit', async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-confirm],[data-minutes-confirm]')) return;
    if (approved.has(form)) { approved.delete(form); return; }
    event.preventDefault(); event.stopImmediatePropagation();
    if (waiting.has(form)) return;
    if (!form.reportValidity()) return;
    waiting.add(form);
    const button = event.submitter;
    try {
      const original = snapshot(form, button);
      const label = form.dataset.confirmAction || button?.textContent?.trim() || uiText('Bestätigen');
      const accepted = await show(form.dataset.confirm || form.dataset.minutesConfirm, {
        title: form.dataset.confirmTitle || uiText('Bitte bestätigen'), accept:label,
        danger:button?.classList.contains('danger') || /löschen|entfernen|verwerfen|delete|remove|discard/i.test(label)
      });
      if (!accepted || !form.isConnected || (button && (!button.isConnected || button.form !== form || button.disabled))) return;
      if (snapshot(form, button) !== original) {
        await window.KulturhusDialog.alert(uiText('Die Angaben wurden inzwischen geändert. Bitte erneut prüfen und bestätigen.')); return;
      }
      approved.add(form);
      try { HTMLFormElement.prototype.requestSubmit.call(form, button || undefined); }
      finally { approved.delete(form); }
    } finally { waiting.delete(form); }
  }, true);
})();
