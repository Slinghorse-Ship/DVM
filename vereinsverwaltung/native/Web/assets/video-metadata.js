/* A metadata save owns only its own inputs and immutable personal receipt. */
(() => {
  'use strict';
  const roots = new WeakSet(), states = new Map();
  const mount = (scope = document, context = null) => {
    scope.querySelectorAll('[data-video-metadata]').forEach(root => {
      const form = root.querySelector('[data-video-metadata-form]');
      if (!form || roots.has(root)) return;
      roots.add(root);
      let messages = {};
      try {messages = JSON.parse(root.dataset.metadataInterface);} catch (_) {}
      const text = source => typeof messages[source] === 'string' ? messages[source] : source;
      const keyField = form.querySelector('[name=metadata_request_key]'), versionField = form.querySelector('[name=metadata_version]');
      const status = root.querySelector('[data-metadata-status]'), fields = form.querySelector('[data-metadata-fields]');
      const save = form.querySelector('[data-metadata-save]'), review = form.querySelector('[data-metadata-review]'), retry = form.querySelector('[data-metadata-retry]'), next = form.querySelector('[data-metadata-next]');
      const lifecycle = new AbortController(), on = (node, type, handler, options = {}) => node.addEventListener(type, handler, {...options,signal:lifecycle.signal});
      let readOnly = root.dataset.metadataReadOnly === '1', canProof = root.dataset.metadataCanProof === '1';
      let attempt = null, reviewKey = keyField.value, epoch = 0, cleanEpoch = form.hasAttribute('data-unsaved-changes') ? -1 : 0, knownAbsent = false, busy = false, disposed = false, request = null;
      let confirmed = null, needsNext = false, savedPanel = root.querySelector('[data-metadata-confirmed]');
      try {if (savedPanel) {confirmed = JSON.parse(savedPanel.dataset.metadataConfirmed); needsNext = true;}} catch (_) {}
      const controls = () => {
        fields.disabled = readOnly;
        form.querySelectorAll('.video-metadata-proof input,.video-metadata-proof textarea').forEach(node => node.disabled = readOnly || !canProof);
        if (!readOnly) form.querySelectorAll('[data-metadata-readonly-copy]').forEach(node => node.remove());
        save.hidden = readOnly || needsNext;
        save.disabled = busy || readOnly || (attempt !== null && !(knownAbsent && epoch !== attempt.epoch));
        review.disabled = busy; retry.hidden = attempt === null; retry.disabled = busy || readOnly || (attempt?.proof && !canProof);
        next.hidden = !needsNext || readOnly; next.disabled = busy || readOnly;
        form.toggleAttribute('aria-busy', busy);
      };
      const report = (source, error = false) => {
        if (disposed || !root.isConnected) return;
        status.textContent = text(source); status.setAttribute('role', error ? 'alert' : 'status');
        if (error) {status.focus(); status.scrollIntoView({block:'nearest'});}
      };
      const newKey = () => [...crypto.getRandomValues(new Uint8Array(16))].map(n => n.toString(16).padStart(2,'0')).join('');
      const validate = (result, key) => {
        const data = result?.metadata, limits = {alt:500,credit:500,source_name:500,source_url:2048,license_name:200,license_url:2048,expires_on:10,rights_state:40,consent_state:40};
        if (result?.found !== true || result.request_key !== key || result.item_id !== form.querySelector('[name=id]').value || result.source_id !== form.querySelector('[name=source_id]').value || !Number.isSafeInteger(result.version) || result.version < 1 || result.version > 4294967295 || typeof result.has_proof !== 'boolean' || !data || !Object.entries(limits).every(([field,max]) => typeof data[field] === 'string' && [...data[field]].length <= max) || !Array.isArray(data.channels) || data.channels.length > 4 || !data.channels.every(v => ['website','newsletter','social','press'].includes(v))) throw new Error('Unconfirmed metadata receipt');
        return data;
      };
      const showOriginal = result => {
        if (!savedPanel) {savedPanel = document.createElement('section'); savedPanel.className = 'video-metadata-saved'; form.before(savedPanel);}
        savedPanel.replaceChildren(); savedPanel.hidden = false;
        const heading = document.createElement('h3'); heading.textContent = text('Gespeicherte Videoangaben'); savedPanel.append(heading);
        const list = document.createElement('dl'); list.className = 'video-metadata-values';
        for (const [key,label] of Object.entries({alt:'Beschreibung',credit:'Credit',source_name:'Quelle',source_url:'Quellenlink',license_name:'Lizenz',license_url:'Lizenzlink'})) if (result.metadata[key]) {
          const term = document.createElement('dt'), value = document.createElement('dd'); term.textContent = text(label); value.textContent = result.metadata[key]; list.append(term,value);
        }
        savedPanel.append(list);
      };
      const accept = (result, key) => {
        validate(result,key); confirmed = result; reviewKey = key;
        const completed = attempt?.key === key ? attempt : null;
        if (completed) attempt = null;
        knownAbsent = false; needsNext = false;
        if (keyField.value === key) keyField.value = newKey();
        if (Number(versionField.value) <= result.version) versionField.value = String(result.version);
        // Only the unchanged inputs of this confirmed request can be marked saved.
        if (completed && completed.epoch === epoch) {
          if (completed.proof) {const proof = form.querySelector('[name=private_proof]'), replace = form.querySelector('[name=replace_proof]'); if (proof && replace) {proof.value = ''; replace.checked = false;}}
          // An unchecked or currently disabled proof is still an unsaved local draft.
          if (!form.querySelector('[name=private_proof]')?.value) {cleanEpoch = epoch; window.KulturhusNativeForms?.clean(form); window.KulturhusNavigation?.markClean(form);}
        }
        showOriginal(result); report(form.querySelector('[name=private_proof]')?.value && !form.querySelector('[name=replace_proof]')?.checked ? 'Videoangaben gespeichert. Der nicht ausgewählte Nachweistext bleibt als Entwurf erhalten.' : 'Videoangaben gespeichert. Der Beitrag bleibt geöffnet.');
      };
      const prepareNext = () => {
        if (!confirmed || readOnly || busy || attempt) return;
        validate(confirmed, confirmed.request_key); versionField.value = String(confirmed.version); keyField.value = newKey(); needsNext = false; knownAbsent = false; controls();
      };
      const run = async (action, exactRetry = false) => {
        if (disposed || busy || (readOnly && action !== 'review')) return;
        if (action === 'next') {prepareNext(); return;}
        let body, key;
        if (action === 'review') {
          key = reviewKey; body = new FormData(form); body.delete('metadata_next'); body.set('metadata_review','1'); body.set('metadata_request_key',key);
          // Disabled controls still belong to the local draft. A lookup submits only public correction values.
          for (const name of ['alt','credit','source_name','source_url','license_name','license_url','rights_state','consent_state','expires_on']) body.set('metadata[' + name + ']',form.querySelector('[name="metadata[' + name + ']"]').value);
          body.delete('metadata[channels][]'); form.querySelectorAll('[name="metadata[channels][]"]:checked').forEach(node => body.append('metadata[channels][]',node.value));
          body.delete('private_proof'); body.delete('replace_proof');
        }
        else {
          if (attempt && !exactRetry && knownAbsent && epoch !== attempt.epoch) attempt = null;
          if (!attempt) {if (exactRetry || needsNext) return; body = new FormData(form); body.delete('metadata_review'); body.delete('metadata_next'); attempt = {body,key:keyField.value,epoch,proof:body.get('replace_proof') === '1'}; reviewKey = attempt.key;}
          body = attempt.body; key = attempt.key; knownAbsent = false;
        }
        if (savedPanel) savedPanel.hidden = true;
        busy = true; controls(); request = new AbortController(); const abort = request, timer = setTimeout(() => abort.abort(),45000);
        let failure = text('Speicherung der Videoangaben nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Angaben prüfen.');
        report(action === 'review' ? 'Gespeicherte Angaben prüfen' : 'Videoangaben werden gespeichert …');
        try {
          const response = await fetch(form.getAttribute('action') || location.href,{method:'POST',body,credentials:'same-origin',cache:'no-store',redirect:'error',signal:abort.signal,headers:{Accept:'application/json'}}), data = await response.json();
          if (typeof data.can_edit === 'boolean') readOnly = !data.can_edit;
          if (typeof data.can_proof === 'boolean') canProof = data.can_proof;
          if (typeof data.csrf === 'string' && /^[a-f0-9]{64}$/.test(data.csrf)) {form.querySelector('[name=csrf]').value = data.csrf; attempt?.body.set('csrf',data.csrf);}
          if (!response.ok) {if ([400,403,404,409,422].includes(response.status) && typeof data.error === 'string') failure = data.error; throw new Error('Unconfirmed metadata save');}
          if (disposed || !root.isConnected) return;
          if (action === 'review' && data.video_metadata_result?.found === false) {knownAbsent = true; report('Keine gespeicherten Angaben für diesen Vorgang gefunden. Unverändert erneut speichern oder Eingaben bewusst korrigieren.');}
          else accept(data.video_metadata_result,key);
        } catch (_) {
          if (!disposed && root.isConnected) {status.textContent = failure; status.setAttribute('role','alert'); status.focus(); status.scrollIntoView({block:'nearest'});}
        } finally {clearTimeout(timer); request = null; busy = false; if (!disposed && root.isConnected) controls();}
      };
      const dispose = () => {if (disposed) return; disposed = true; lifecycle.abort(); request?.abort(); attempt = null; states.delete(form); roots.delete(root);};
      const guarded = () => !disposed && (busy || attempt !== null || epoch !== cleanEpoch);
      states.set(form,{root,guarded,dispose});
      on(form,'input',() => {epoch++; controls();}); on(form,'change',() => {epoch++; controls();});
      on(form,'submit',event => {event.preventDefault(); event.stopImmediatePropagation(); void run(event.submitter === review ? 'review' : event.submitter === next ? 'next' : 'save');},{capture:true});
      on(retry,'click',() => void run('save',true)); context?.cleanup(dispose); context?.guard(guarded);
      if (!context) {on(window,'pagehide',dispose,{once:true}); on(window,'beforeunload',event => {if (guarded()) {event.preventDefault(); event.returnValue = '';}});}
      controls();
    });
  };
  window.KulturhusVideoMetadata = Object.freeze({mount,unmount:scope => {for (const [form,state] of states) if (scope.contains(form)) state.dispose();},guarded:scope => [...states].some(([form,state]) => form.isConnected && (!scope || scope.contains(form)) && state.guarded())});
  if (window.KulturhusPage) window.KulturhusPage.register('video-metadata',context => mount(context.root,context));
  else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',() => mount(),{once:true});
  else mount();
})();
