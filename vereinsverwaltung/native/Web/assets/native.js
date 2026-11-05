(() => {
  'use strict';
  const uiText = source => window.KulturhusInterface?.text(source) ?? source;
  let dirty = false, busy = false, nativeSubmit = false, current = location.href, request = 0, editEpoch = 0, pendingDiscard = null;
  const dirtyForms = new Set();
  const restoreUnsaved = root => {root.querySelectorAll('form[data-unsaved-changes]').forEach(form=>dirtyForms.add(form));dirty=dirtyForms.size>0;};
  const menuButtons = root => root.querySelectorAll('[data-navigation-editor] .native-menu-list').forEach(list=>{const rows=[...list.querySelectorAll('[data-menu-row]')];rows.forEach((row,index)=>row.querySelectorAll('[data-menu-step]').forEach(button=>button.disabled=Boolean(button.closest('fieldset')?.disabled)||(Number(button.dataset.menuStep)<0?index===0:index===rows.length-1)));});
  const storyDisabled = new WeakMap();
  const storyState = form => {
    for (const panel of form.querySelectorAll('[data-story-panel], [data-carousel-panel]')) {
    panel.hidden = form.querySelector('[name=kind]').value !== (panel.hasAttribute('data-carousel-panel') ? 'carousel' : 'story');
    panel.querySelectorAll('input,textarea,select,button').forEach(field => {
      if(panel.hidden){if(!storyDisabled.has(field))storyDisabled.set(field,field.disabled);field.disabled=true;}
      else if(storyDisabled.has(field)){field.disabled=storyDisabled.get(field);storyDisabled.delete(field);}
    });
    }
  };
  const mount = root => { window.KulturhusEditor?.mount(root); window.KulturhusRichText?.mount(root); window.KulturhusFieldConditions?.mount(root); window.KulturhusFieldGroups?.mount(root); window.KulturhusNativeChat?.mount(); window.KulturhusImageStudio?.mount(root); window.KulturhusVideoRecoveries?.mount(root); window.KulturhusVideoReuse?.mount(root); window.KulturhusVideoFiles?.mount(root); window.KulturhusVideoArchive?.mount(root); window.KulturhusVideoPolicy?.mount(root); window.KulturhusVideoMetadata?.mount(root); window.KulturhusVideoUploads?.mount(root); window.KulturhusVideoStudio?.mount(root); window.KulturhusPublications?.mount(root); root.querySelectorAll('form').forEach(storyState); };
  mount(document);
  restoreUnsaved(document);menuButtons(document);
  document.addEventListener('toggle',event=>{
    const details=event.target;
    if(details instanceof HTMLDetailsElement&&!details.open&&details.matches('.social-video-card-tools,.social-video-player,.social-video-versions'))details.querySelectorAll('video').forEach(video=>video.pause());
  },true);
  const notice = document.getElementById('notice');
  const dialog = document.getElementById('discard');
  const report = (text, error = false) => { notice.textContent = text; notice.classList.toggle('error', error); notice.setAttribute('role', error ? 'alert' : 'status'); };
  const discard = () => !dirty && !window.KulturhusImageStudio?.guarded() && !window.KulturhusVideoStudio?.guarded() && !window.KulturhusVideoRecoveries?.guarded(document) && !window.KulturhusVideoReuse?.guarded(document) && !window.KulturhusVideoFiles?.guarded(document) && !window.KulturhusVideoArchive?.guarded(document) && !window.KulturhusVideoPolicy?.guarded(document) && !window.KulturhusVideoMetadata?.guarded(document) && !window.KulturhusVideoUploads?.guarded(document) && !window.KulturhusPhotoLibrary?.guarded(document) ? Promise.resolve(true) : pendingDiscard || (pendingDiscard = new Promise(resolve => {
    dialog.returnValue = 'stay';
    const done = () => { dialog.removeEventListener('close', done); pendingDiscard = null; resolve(dialog.returnValue === 'discard'); };
    dialog.addEventListener('close', done); dialog.showModal();
  }));
  dialog.addEventListener('click', event => { const choice = event.target.closest('[data-choice]'); if (choice) dialog.close(choice.dataset.choice); });
  const replace = (data, mode, fragment = '') => {
    const doc = new DOMParser().parseFromString(data.html, 'text/html');
    const main = doc.getElementById('workspace');
    if (!main) throw new Error(uiText('Die Seite konnte nicht geladen werden.'));
    const url = new URL(data.url, location.origin);
    if (url.origin !== location.origin) throw new Error(uiText('Die Seite konnte nicht geladen werden.'));
    if (fragment) url.hash = fragment;
    // A locale changed in another account tab needs the complete frame in that locale.
    // Navigation already checked the exact unsaved inputs before accepting this response.
    if (main.dataset.interfaceLocale && main.dataset.interfaceLocale !== document.documentElement.lang) {
      dirty = false; dirtyForms.clear(); location.assign(url.href); return;
    }
    const previous = document.getElementById('workspace');
    window.KulturhusEditor?.unmount(previous);
    window.KulturhusImageStudio?.unmount(previous);
    window.KulturhusVideoStudio?.unmount(previous);
    window.KulturhusVideoRecoveries?.unmount(previous); window.KulturhusVideoReuse?.unmount(previous);
    window.KulturhusVideoFiles?.unmount(previous);
    window.KulturhusVideoArchive?.unmount(previous);
    window.KulturhusVideoPolicy?.unmount(previous);
    window.KulturhusVideoMetadata?.unmount(previous);
    window.KulturhusVideoUploads?.unmount(previous);
    previous.replaceWith(main);
    mount(main);
    if (mode === 'push' && url.href !== location.href) history.pushState({}, '', url);
    else if (mode === 'replace') history.replaceState({}, '', url);
    current = location.href; dirty = false; dirtyForms.clear();restoreUnsaved(main);menuButtons(main); report(data.notice || '');
    if(!main.querySelector('[data-publication-focus]'))main.querySelector('h1')?.focus({ preventScroll: true });
    if (url.hash) { try { const anchor = document.getElementById(decodeURIComponent(url.hash.slice(1))); if (anchor && main.contains(anchor)) { anchor.focus({ preventScroll: true }); anchor.scrollIntoView({ block: 'start' }); } } catch { /* Invalid encoded fragment. */ } }
  };
  const navigate = async (url, mode = 'push', signal) => {
    if (busy || !(await discard())) return false;
    const ticket = ++request, epoch = editEpoch;
    try {
      const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal });
      const data = await response.json(); if (!response.ok) throw new Error(data.error || uiText('Seite konnte nicht geladen werden.'));
      if (ticket !== request) return false;
      if (editEpoch !== epoch && !(await discard())) return false;
      if (ticket === request) replace(data, mode, new URL(url, location.href).hash);
      return true;
    } catch (error) { report(error.name === 'AbortError' ? uiText('Die Inhalte antworten nicht rechtzeitig. Deine Eingaben bleiben erhalten. Bitte erneut versuchen.') : error.message, true); return false; }
  };
  const markDirty = event => { const form = event.target.closest('#workspace form'); if (form && form.method.toLowerCase() !== 'get' && !event.target.closest('[data-dirty-ignore]')) { dirty = true; dirtyForms.add(form); editEpoch++; } };
  window.KulturhusNativeForms = Object.freeze({clean:form => {dirtyForms.delete(form); dirty=dirtyForms.size>0;}});
  document.addEventListener('input', markDirty);
  document.addEventListener('change', markDirty);
  document.addEventListener('change',async event=>{
    const select=event.target;if(!select.matches('[data-model-template]'))return;
    const form=select.form, target=form.querySelector('[data-model-fields]'), chosen=select.value;
    const controls=[...form.querySelectorAll('button[type=submit],button:not([type])')];controls.forEach(button=>button.disabled=true);
    try{const response=await fetch('/model-fields?template='+encodeURIComponent(chosen),{headers:{Accept:'application/json'}});const data=await response.json();if(!response.ok)throw new Error(data.error||'Vorlagenfelder nicht verfügbar.');if(select.isConnected&&select.value===chosen)target.innerHTML=data.html;}
    catch(error){report(error.message,true);}
    finally{controls.forEach(button=>button.disabled=false);}
  });
  document.addEventListener('change', event => { if (event.target.matches('[name=kind]')) {
    const form = event.target.form; const template = form.querySelector('[name=template]');
    if (template && ['social-post','social-story','social-reel','social-carousel'].includes(template.value)) template.value = ['post','story','reel','carousel'].includes(event.target.value)?'social-'+event.target.value:template.value;
    storyState(form);
  } });
  for(const name of ['dragenter','dragover'])document.addEventListener(name,event=>{const drop=event.target.closest('[data-album-drop]');if(drop){event.preventDefault();drop.classList.add('dragging');}});
  document.addEventListener('dragleave',event=>event.target.closest('[data-album-drop]')?.classList.remove('dragging'));
  document.addEventListener('drop',event=>{const drop=event.target.closest('[data-album-drop]');if(drop){event.preventDefault();drop.classList.remove('dragging');const input=drop.querySelector('[data-album-upload]');if(!input||input.disabled)return;const files=new DataTransfer();for(const file of event.dataTransfer?.files||[])files.items.add(file);input.files=files.files;input.dispatchEvent(new Event('change',{bubbles:true}));}});
  document.addEventListener('click', event => {
    const button=event.target.closest('[data-navigation-editor] [data-menu-step]');
    if(button){
      if(button.matches(':disabled'))return;
      const row=button.closest('[data-menu-row]'),list=row?.parentElement,form=button.form;
      if(!list||!form?.matches('[data-navigation-editor]')||!list.closest('#workspace'))return;
      const up=Number(button.dataset.menuStep)<0,other=up?row.previousElementSibling:row.nextElementSibling;
      if(!other?.matches('[data-menu-row]'))return;
      if(up)list.insertBefore(row,other);else list.insertBefore(other,row);
      list.querySelectorAll('[data-menu-position]').forEach((field,index)=>{field.value=String(index);field.dispatchEvent(new Event('input',{bubbles:true}));});
      menuButtons(form);(button.disabled?row.querySelector('[data-menu-step]:not(:disabled)'):button)?.focus();return;
    }
    const link = event.target.closest('a[data-nav]');
    if (!link || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault(); navigate(link.href);
  });
  document.addEventListener('submit', async event => {
    const form = event.target; if (!(form instanceof HTMLFormElement)) return;
    if (form.matches('[data-navigation-native]') || form.closest('[data-video-reuse-request]')) {
      if (form.matches('[data-check-plan-form],[data-event-draft-form]') && ![...dirtyForms].some(changed => changed !== form)) queueMicrotask(() => {if (!event.defaultPrevented) {nativeSubmit=true;setTimeout(()=>{nativeSubmit=false;},1000);}});
      return;
    }
    if (form.matches('[data-contact-management]')) { dirtyForms.delete(form); dirty=dirtyForms.size>0; return; }
    event.preventDefault(); if (busy) return;
    if (form.method.toLowerCase() === 'get') {
      const target = new URL(form.action || location.href, location.origin);
      if (target.origin !== location.origin) return;
      target.search = new URLSearchParams([...new FormData(form,event.submitter)].filter(([, value]) => typeof value === 'string')).toString();
      const list = form.closest('.content-lister'), focus = event.submitter || document.activeElement;
      if (list?.inert) return;
      const previousNotice = {text: notice.textContent, error: notice.classList.contains('error')};
      if (list) {list.inert = true; list.setAttribute('aria-busy', 'true'); report(uiText('Inhalte werden geladen.'));}
      const controller = list ? new AbortController() : null, timer = controller ? setTimeout(() => controller.abort(), 20000) : null;
      let completed = false;
      try {completed = await navigate(target.href, 'push', controller?.signal);}
      finally {
        if (timer !== null) clearTimeout(timer);
        if (list) {
          list.inert = false; list.removeAttribute('aria-busy');
          if (!completed && list.isConnected) {
            if (notice.textContent === uiText('Inhalte werden geladen.')) report(previousNotice.text, previousNotice.error);
            if (focus?.isConnected) focus.focus({preventScroll:true});
          }
        }
      }
      return;
    }
    // A submit only owns its own inputs. Other dirty forms need one confirmation.
    if ([...dirtyForms].some(changed => changed !== form) && !(await discard())) return;
    const mediaError = form.querySelector('[data-media-removal-error]'), mediaCheck = form.querySelector('[data-media-removal-check]');
    const videoError = form.querySelector('[data-video-lifecycle-error]'), videoCheck = form.querySelector('[data-video-lifecycle-check]');
    if(videoError){videoError.hidden=true;videoError.textContent='';}if(form.matches('[data-video-recovery-form],[data-video-reuse-form]'))delete form.dataset.videoRecoveryUncertain;if(videoCheck)videoCheck.hidden=true;
    if(mediaError){mediaError.hidden=true;mediaError.textContent='';}if(mediaCheck)mediaCheck.hidden=true;
    const body = new FormData(form,event.submitter); busy = true; ++request;
    const lockedMain = document.getElementById('workspace'), lockedHeader = document.querySelector('.app-header');
    lockedMain.inert = true; lockedMain.setAttribute('aria-busy', 'true'); lockedHeader.inert = true;
    const buttons = [...form.querySelectorAll('button')]; buttons.forEach(button => button.disabled = true);
    try {
      const response = await fetch(location.href, { method: 'POST', body, headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await response.json(); if (!response.ok) throw new Error(data.error || uiText('Speichern fehlgeschlagen.'));
      replace(data, 'push');
    } catch (error) { if(form.querySelector('[data-newsletter-save]')&&['TypeError','SyntaxError'].includes(error.name)){const source='Speicherung nicht bestätigt. Deine Angaben bleiben erhalten. Gespeicherten Stand prüfen oder unverändert erneut speichern.',key='content:'+source,value=window.KulturhusInterface?.text(key);error=new Error(value&&value!==key?value:source);}if(form.matches('[data-video-recovery-form],[data-video-reuse-form]')&&['TypeError','SyntaxError'].includes(error.name)){form.dataset.videoRecoveryUncertain='1';error=new Error('Ergebnis nicht bestätigt. Vorgang prüfen.');}if(['media-purge','media-purge-resume'].includes(body.get('operation')))form.querySelectorAll('[name=confirm_purge]').forEach(input=>input.checked=false);if(videoError){form.querySelectorAll('[name=confirm_video]').forEach(input=>input.checked=false);dirtyForms.delete(form);dirty=dirtyForms.size>0;videoError.textContent=error.name==='TypeError'?'Ergebnis nicht bestätigt. Vorgang prüfen.':error.message;videoError.hidden=false;if(videoCheck)videoCheck.hidden=false;}else if(mediaError){mediaError.textContent=error.message;mediaError.hidden=false;if(mediaCheck)mediaCheck.hidden=false;}else report(error.message, true); }
    finally { busy = false; lockedMain.inert = false; lockedMain.removeAttribute('aria-busy'); lockedHeader.inert = false; buttons.forEach(button => button.disabled = form.dataset.videoRecoveryUncertain === '1');const issue=videoError?.isConnected&&!videoError.hidden?videoError:mediaError?.isConnected&&!mediaError.hidden?mediaError:null;if(issue){issue.focus();issue.scrollIntoView({block:'nearest'});} }
  });
  window.addEventListener('popstate', async () => {
    const target = location.href;
    const previous = new URL(current), next = new URL(target);
    if (previous.origin === next.origin && previous.pathname === next.pathname && previous.search === next.search) {
      current = target;
      return;
    }
    if (!(await navigate(target, 'none'))) { history.pushState({}, '', current); }
  });
  window.addEventListener('beforeunload', event => { if (!nativeSubmit && (dirty || busy || window.KulturhusImageStudio?.guarded() || window.KulturhusVideoStudio?.guarded() || window.KulturhusVideoRecoveries?.guarded(document) || window.KulturhusVideoReuse?.guarded(document) || window.KulturhusVideoFiles?.guarded(document) || window.KulturhusVideoArchive?.guarded(document) || window.KulturhusVideoPolicy?.guarded(document) || window.KulturhusVideoMetadata?.guarded(document) || window.KulturhusVideoUploads?.guarded(document))) { event.preventDefault(); event.returnValue = ''; } });
  // Reloading is user-directed; no external service or background publication worker is installed.
})();
