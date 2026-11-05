/* A club rule owns its own version and receipt; editorial drafts remain in place. */
(() => {
  'use strict';
  const roots = new WeakSet(), states = new Map();
  const mount = (scope = document, context = null) => {
    scope.querySelectorAll('[data-video-policy]').forEach(root => {
      const form = root.querySelector('[data-video-policy-form]'); if (!form || roots.has(root)) return; roots.add(root);
      let messages = {}; try {messages = JSON.parse(root.dataset.policyInterface);} catch (_) {}
      const text = source => typeof messages[source] === 'string' ? messages[source] : source;
      const kind = root.dataset.videoPolicy, value = form.querySelector('[data-policy-value]'), field = form.querySelector(kind === 'quota' ? '[name=quota_version]' : '[name=retention_version]'), keyField = form.querySelector('[name=policy_request_key]');
      const status = root.querySelector('[data-policy-status]'), fields = form.querySelector('[data-policy-fields]'), save = form.querySelector('[data-policy-save]'), review = form.querySelector('[data-policy-review]'), retry = form.querySelector('[data-policy-retry]'), next = form.querySelector('[data-policy-next]');
      const lifecycle = new AbortController(), on = (node,type,handler,options={}) => node.addEventListener(type,handler,{...options,signal:lifecycle.signal});
      let readOnly = root.dataset.policyReadOnly === '1', attempt = null, reviewKey = keyField.value, epoch = 0, cleanEpoch = form.hasAttribute('data-unsaved-changes') ? -1 : 0, knownAbsent = false, busy = false, disposed = false, request = null;
      let confirmed = null, needsNext = false, savedPanel = root.querySelector('[data-policy-confirmed]'); try {if (savedPanel) {confirmed = JSON.parse(savedPanel.dataset.policyConfirmed); needsNext = true;}} catch (_) {}
      const newKey = () => [...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');
      const controls = () => {
        fields.disabled = readOnly; if (!readOnly) form.querySelectorAll('[data-policy-readonly-copy]').forEach(n=>n.remove());
        save.hidden = readOnly || needsNext; save.disabled = busy || readOnly || (attempt !== null && !(knownAbsent && epoch !== attempt.epoch));
        review.disabled = busy; retry.hidden = attempt === null; retry.disabled = busy || readOnly; next.hidden = !needsNext || readOnly; next.disabled = busy || readOnly; form.toggleAttribute('aria-busy',busy);
      };
      const report = (source,error=false) => {if (disposed || !root.isConnected) return; status.textContent = text(source); status.setAttribute('role',error?'alert':'status'); if (error) {status.focus(); status.scrollIntoView({block:'nearest'});}};
      const validate = (result,key) => {if (result?.found !== true || result.kind !== kind || result.request_key !== key || result.item_id !== form.querySelector('[name=id]').value || !Number.isSafeInteger(result.version) || result.version < 1 || result.version > 4294967295 || !Number.isSafeInteger(result.value) || result.value < 0 || result.value > (kind==='quota'?102400:3650)) throw Error('Unconfirmed policy receipt');};
      const usage = data => {
        if (kind !== 'quota' || !data || !['source_count','used_bytes','limit_bytes'].every(k=>Number.isSafeInteger(data[k])&&data[k]>=0) || !(data.remaining_bytes===null||Number.isSafeInteger(data.remaining_bytes)&&data.remaining_bytes>=0)) return;
        const target = root.closest('.social-video-quota')?.querySelector('[data-video-policy-usage]'); if (!target) return;
        const number = bytes => (bytes/1048576).toLocaleString(text('Videospeicher')==='Video storage'?'en-GB':'de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})+' MiB';
        const a = document.createElement('p'), b = document.createElement('p'); a.textContent = text('{count} Quellen · {used} belegt').replace('{count}',data.source_count).replace('{used}',number(data.used_bytes)); b.textContent = data.limit_bytes===0?text('Unbegrenzt'):text('{free} frei · {total} gesamt').replace('{free}',number(data.remaining_bytes)).replace('{total}',number(data.limit_bytes)); target.replaceChildren(a,b);
      };
      const accept = (result,key) => {
        validate(result,key); confirmed = result; reviewKey = key; const completed = attempt?.key===key?attempt:null; if (completed) attempt = null;
        knownAbsent = false; needsNext = false; if (keyField.value===key) keyField.value=newKey(); if (Number(field.value)<=result.version) field.value=String(result.version);
        if (completed && completed.epoch===epoch) {cleanEpoch=epoch; window.KulturhusNativeForms?.clean(form); window.KulturhusNavigation?.markClean(form);}
        if (!savedPanel) {savedPanel=document.createElement('section'); savedPanel.className='video-policy-saved'; form.before(savedPanel);} savedPanel.replaceChildren(); savedPanel.hidden=false;
        const h=document.createElement('h3'), p=document.createElement('p'), v=document.createElement('p'); h.textContent=text('Gespeicherte Regel'); p.textContent=text(kind==='quota'?'Speichergrenze (MiB)':'Mindestaufbewahrung (Tage)')+': '+result.value; v.textContent=text('Regelversion')+': '+result.version; savedPanel.append(h,p,v); report('Regel gespeichert. Der Beitrag bleibt geöffnet.');
      };
      const run = async (action,exact=false) => {
        if (disposed || busy || (readOnly && action!=='review')) return;
        if (action==='next') {if (confirmed && !attempt) {validate(confirmed,confirmed.request_key); field.value=String(confirmed.version); keyField.value=newKey(); needsNext=false; controls();} return;}
        let body,key;
        if (action==='review') {key=reviewKey;body=new FormData(form);body.delete('policy_next');body.set('policy_review','1');body.set('policy_request_key',key);body.set(value.name,value.value);}
        else {if (attempt && !exact && knownAbsent && epoch!==attempt.epoch) attempt=null;if (!attempt) {if (exact || needsNext) return;body=new FormData(form);body.delete('policy_review');body.delete('policy_next');attempt={body,key:keyField.value,epoch};reviewKey=attempt.key;}body=attempt.body;key=attempt.key;knownAbsent=false;}
        if (savedPanel) savedPanel.hidden=true;busy=true;controls();request=new AbortController();const abort=request,timer=setTimeout(()=>abort.abort(),45000);
        let failure=text('Speicherung der Regel nicht bestätigt. Deine Eingaben bleiben erhalten. Gespeicherte Regel prüfen.');report(action==='review'?'Gespeicherte Regel prüfen':'Regel wird gespeichert …');
        try {
          const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body,credentials:'same-origin',cache:'no-store',redirect:'error',signal:abort.signal,headers:{Accept:'application/json'}}),data=await response.json();
          if (typeof data.can_manage==='boolean') readOnly=!data.can_manage;
          if (typeof data.csrf==='string'&&/^[a-f0-9]{64}$/.test(data.csrf)) {form.querySelector('[name=csrf]').value=data.csrf;attempt?.body.set('csrf',data.csrf);}
          if (!response.ok) {if ([400,403,404,409,422].includes(response.status)&&typeof data.error==='string') failure=data.error;throw Error('Unconfirmed rule save');}
          if (disposed||!root.isConnected) return;usage(data.usage);
          if (action==='review'&&data.video_policy_result?.found===false) {knownAbsent=true;report('Keine gespeicherte Regel für diesen Vorgang gefunden. Unverändert erneut speichern oder Eingaben bewusst korrigieren.');} else accept(data.video_policy_result,key);
        } catch (_) {if (!disposed&&root.isConnected) {status.textContent=failure;status.setAttribute('role','alert');status.focus();status.scrollIntoView({block:'nearest'});}}
        finally {clearTimeout(timer);request=null;busy=false;if (!disposed&&root.isConnected) controls();}
      };
      const dispose=()=>{if(disposed)return;disposed=true;lifecycle.abort();request?.abort();attempt=null;states.delete(form);roots.delete(root);};const guarded=()=>!disposed&&(busy||attempt!==null||epoch!==cleanEpoch);states.set(form,{guarded,dispose});
      on(form,'input',()=>{epoch++;controls();});on(form,'change',()=>{epoch++;controls();});on(form,'submit',event=>{event.preventDefault();event.stopImmediatePropagation();void run(event.submitter===review?'review':event.submitter===next?'next':'save');},{capture:true});on(retry,'click',()=>void run('save',true));
      on(document,'kulturhus:video-saved',event=>usage(event.detail));on(document,'kulturhus:video-file-state',event=>{if(event.detail?.panel===root.closest('#social-videos'))usage(event.detail?.usage);});context?.cleanup(dispose);context?.guard(guarded);if(!context){on(window,'pagehide',dispose,{once:true});on(window,'beforeunload',event=>{if(guarded()){event.preventDefault();event.returnValue='';}});}controls();
    });
  };
  window.KulturhusVideoPolicy=Object.freeze({mount,unmount:scope=>{for(const[form,state]of states)if(scope.contains(form))state.dispose();},guarded:scope=>[...states].some(([form,state])=>form.isConnected&&(!scope||scope.contains(form))&&state.guarded())});
  if(window.KulturhusPage)window.KulturhusPage.register('video-policy',context=>mount(context.root,context));else if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>mount(),{once:true});else mount();
})();
