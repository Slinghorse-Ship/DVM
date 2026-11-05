/* An MP4 save owns its file and original request; post drafts and local edits stay in place. */
(() => {
  'use strict';
  const mounted = new WeakSet(), forms = new Map();
  const mount = (scope = document, context = null) => {
    scope.querySelectorAll('[data-video-upload]').forEach(root => {
      const form = root.querySelector('[data-native-video-form]');
      if (!form || mounted.has(root)) return;
      mounted.add(root);
      let messages = {};
      try {messages = JSON.parse(root.dataset.videoInterface);} catch (_) {}
      const text = (source, values = {}) => (typeof messages[source] === 'string' ? messages[source] : source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token);
      const file = form.querySelector('[data-native-video-file]'), keyField = form.querySelector('[name=video_request_key]');
      const status = root.querySelector('[data-video-upload-status]'), original = root.querySelector('[data-video-upload-original]');
      const player = root.querySelector('[data-video-saved-player]'), openPost = root.querySelector('[data-video-upload-open-post]');
      const save = form.querySelector('[data-video-upload-save]'), review = form.querySelector('[data-video-upload-review]'), retry = form.querySelector('[data-video-upload-retry]');
      const lifecycle = new AbortController();
      let readOnly = root.dataset.videoReadOnly === '1';
      let attempt = null, reviewKey = keyField.value, selection = 0, knownAbsent = false, busy = false, disposed = false, request = null;
      const on = (node, type, listener, options = {}) => node.addEventListener(type, listener, {...options, signal:lifecycle.signal});
      const controls = () => {
        file.disabled = busy || readOnly; review.disabled = busy;
        save.hidden = readOnly;
        save.disabled = busy || readOnly || (attempt !== null && !(knownAbsent && selection !== attempt.selection));
        retry.hidden = attempt === null; retry.disabled = busy || readOnly;
        form.toggleAttribute('aria-busy', busy);
        const studio = root.closest('[data-video-studio]'), studioSave = studio?.querySelector('[data-video-save]');
        if (studioSave) {
          studioSave.textContent = text(attempt ? 'Ursprüngliche Videofassung erneut speichern' : 'Videofassung speichern');
          studioSave.disabled = busy || readOnly || studio.querySelector('[data-video-export]').disabled;
        }
        const mirror = root.closest('[data-video-studio]')?.querySelector('[data-video-status]');
        if (mirror && status.textContent) {mirror.textContent = status.textContent; mirror.hidden = false;}
      };
      const report = (source, values = {}, error = false) => {
        if (disposed || !root.isConnected) return;
        status.textContent = text(source, values); status.setAttribute('role', error ? 'alert' : 'status');
        if (error) {status.focus(); status.scrollIntoView({block:'nearest'});}
      };
      const hideOriginal = () => {
        original.hidden = true; original.removeAttribute('href'); player.hidden = true;
        player.pause(); player.removeAttribute('src'); player.load();
      };
      const saved = (result, key, action) => {
        if (result?.found !== true || result.request_key !== key || !/^[a-f0-9]{32}$/.test(result.id || '') || result.item_id !== form.querySelector('[name=id]').value || !Number.isSafeInteger(result.item_version) || result.item_version !== Number(form.querySelector('[name=version]').value) || !Number.isSafeInteger(result.identity_version) || result.identity_version !== Number(form.querySelector('[name=identity_version]').value) || typeof result.name !== 'string' || !/^[a-f0-9]{64}$/.test(result.sha256 || '') || !Number.isSafeInteger(result.bytes) || result.bytes < 32 || result.bytes > 100 * 1024 * 1024) throw new Error('Unconfirmed video receipt');
        const url = new URL(result.original_url, location.href);
        const native = url.pathname === '/social-video/' + result.id && !url.search;
        const integrated = url.pathname === '/vereinsverwaltung/' && url.searchParams.get('native_endpoint') === 'social-video' && url.searchParams.get('video') === result.id;
        if (url.origin !== location.origin || (!native && !integrated)) throw new Error('Unconfirmed original video URL');
        const completed = attempt?.key === key ? attempt : null;
        if (completed) attempt = null;
        knownAbsent = false; reviewKey = key;
        if (keyField.value === key) keyField.value = [...crypto.getRandomValues(new Uint8Array(16))].map(n => n.toString(16).padStart(2, '0')).join('');
        // A positive POST binds exactly these bytes. A lookup leaves the current file choice intact.
        if (action === 'save' && completed && !completed.generated && completed.selection === selection) file.value = '';
        if (!file.files?.length) {window.KulturhusNativeForms?.clean(form); window.KulturhusNavigation?.markClean(form);}
        original.href = url.href; original.hidden = false; player.src = url.href; player.hidden = false; openPost.hidden = false;
        report('Video gespeichert. Beitrag und Bearbeitung bleiben erhalten.');
        if(action==='save'&&completed&&(completed.generated||completed.selection===selection))root.dispatchEvent(new CustomEvent('kulturhus:social-save-state',{bubbles:true,detail:{videoSaved:true}}));
      };
      const run = async (action, exactRetry = false, generated = null) => {
        if (disposed || busy || (readOnly && action !== 'review')) return;
        let body, key;
        if (action === 'review') {
          key = reviewKey; body = new FormData(form); body.delete('video_file'); body.set('video_review', '1'); body.set('video_request_key', key);
        } else {
          if (attempt && !exactRetry && knownAbsent && selection !== attempt.selection) attempt = null;
          if (!attempt) {
            if (exactRetry || (!generated && !file.files?.[0])) return;
            const input = generated || file.files[0];
            body = new FormData(form); body.delete('video_review'); body.set('original_name', input.name); body.set('video_file', input, input.name);
            attempt = {body, key:keyField.value, selection, generated:generated !== null}; reviewKey = attempt.key;
          }
          body = attempt.body; key = attempt.key; knownAbsent = false;
        }
        hideOriginal(); busy = true; controls(); request = new AbortController();
        const abort = request, timer = setTimeout(() => abort.abort(), 45000);
        let failure = text('Speicherung nicht bestätigt. Originaldatei und ursprünglichen Vorgang behalten. Gespeichertes Video prüfen.');
        report(action === 'review' ? 'Gespeichertes Video prüfen' : 'Videofassung wird gespeichert …');
        try {
          const response = await fetch(form.getAttribute('action') || location.href, {method:'POST',body,credentials:'same-origin',cache:'no-store',redirect:'error',signal:abort.signal,headers:{Accept:'application/json'}});
          const data = await response.json();
          if (typeof data.can_edit === 'boolean') readOnly = !data.can_edit;
          if (typeof data.csrf === 'string' && /^[a-f0-9]{64}$/.test(data.csrf)) {form.querySelector('[name=csrf]').value = data.csrf; attempt?.body.set('csrf', data.csrf);}
          if (!response.ok) {
            if ([400,403,404,409,422].includes(response.status) && typeof data.error === 'string') failure = data.error;
            throw new Error('Unconfirmed video save');
          }
          if (disposed || !root.isConnected) return;
          if (action === 'review' && data.video_result?.found === false) {
            knownAbsent = true; report('Kein gespeichertes Video gefunden. Ursprünglichen Upload prüfen oder unverändert erneut versuchen.');
          } else {saved(data.video_result, key, action); document.dispatchEvent(new CustomEvent('kulturhus:video-saved',{detail:data.usage}));}
        } catch (_) {
          if (!disposed && root.isConnected) {status.textContent = failure; status.setAttribute('role', 'alert'); status.focus(); status.scrollIntoView({block:'nearest'}); openPost.hidden = false;}
        } finally {
          clearTimeout(timer); request = null; busy = false;
          if (!disposed && root.isConnected) controls();
        }
      };
      const dispose = () => {
        if (disposed) return;
        disposed = true; lifecycle.abort(); request?.abort(); attempt = null;
        player.pause(); player.removeAttribute('src'); player.load(); forms.delete(form); mounted.delete(root);
      };
      forms.set(form, {root, pending:() => busy || attempt !== null, guarded:() => busy || attempt !== null || file.files?.length > 0, writable:() => !readOnly, generated:input => run('save', false, input), retry:() => run('save', true), dispose});
      on(file, 'change', () => {selection++; controls();});
      on(form, 'submit', event => {
        event.preventDefault(); event.stopImmediatePropagation();
        void run(event.submitter === review ? 'review' : 'save');
      }, {capture:true});
      on(retry, 'click', () => void run('save', true));
      context?.cleanup(dispose); context?.guard(() => !disposed && (busy || attempt !== null || file.files?.length > 0));
      if (!context) on(window, 'pagehide', dispose, {once:true});
      controls();
    });
  };
  window.KulturhusVideoUploads = Object.freeze({
    mount,
    unmount:scope => {for (const [form, state] of forms) if (scope.contains(form)) state.dispose();},
    pending:form => forms.get(form)?.pending() ?? false,
    retry:form => forms.get(form)?.retry(),
    writable:form => forms.get(form)?.writable() ?? false,
    saveGenerated:(form, input) => forms.get(form)?.generated(input),
    guarded:scope => [...forms].some(([form, state]) => form.isConnected && (!scope || scope.contains(form)) && state.guarded()),
  });
  if (window.KulturhusPage) window.KulturhusPage.register('video-uploads', context => mount(context.root, context));
  else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => mount(), {once:true});
  else mount();
})();
