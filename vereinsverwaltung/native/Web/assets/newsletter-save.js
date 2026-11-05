(() => {
  'use strict';
  if(window.KulturhusNewsletterSaveCheck)return;
  window.KulturhusNewsletterSaveCheck=true;
  const contentText=source=>{
    const read=window.KulturhusInterface?.text;if(!read)return source;
    for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}
    return source;
  };
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  const pending=new WeakSet(),saved=new WeakMap();
  document.addEventListener('submit',async event=>{
    const form=event.target,button=event.submitter;
    if(!(form instanceof HTMLFormElement)||button?.name!=='save_review'||!form.querySelector('[data-newsletter-save]'))return;
    event.preventDefault();event.stopImmediatePropagation();if(pending.has(form))return;
    const module=form.querySelector('[data-newsletter-save]').dataset.saveModule||'newsletter';if(!['newsletter','press','social'].includes(module))return;
    const status=form.querySelector('[data-save-status]'),link=form.querySelector('[data-save-original]'),body=new FormData();
    for(const[name,value]of new FormData(form))if(typeof value==='string')body.append(name,value);
    body.set('save_review','1');
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),20000);
    pending.add(form);button.disabled=true;status.hidden=false;status.textContent=contentText('Wird geprüft …');link.hidden=true;
    try{
      const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body,credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok)throw new Error(typeof data.error==='string'?data.error:contentText('Prüfung nicht bestätigt.'));
      const result=data.save_lookup;if(!result||typeof result.found!=='boolean')throw new Error(contentText('Prüfung nicht bestätigt.'));
      if(result.found){
        if(!/^[a-f0-9]{32}$/.test(result.id)||!Number.isSafeInteger(result.version)||result.version<1)throw new Error(contentText('Prüfung nicht bestätigt.'));
        const url=new URL(result.url,location.origin);if(url.origin!==location.origin||!['/preview','/vereinsverwaltung/'].includes(url.pathname)||url.searchParams.get('module')!==module||url.searchParams.get('item')!==result.id||url.searchParams.get('revision')!==String(result.version))throw new Error(contentText('Prüfung nicht bestätigt.'));
        link.href=url.href;link.hidden=false;status.textContent=contentFormat('Fassung {version} gespeichert.',{version:result.version});
      }else status.textContent=contentText('Kein gespeicherter Vorgang gefunden.');
    }catch(error){if(form.isConnected)status.textContent=error.name==='Error'?contentText(error.message):contentText('Prüfung nicht bestätigt.');}
    finally{clearTimeout(timer);pending.delete(form);button.disabled=false;}
  });
  // Only idempotent draft saves in the integrated manager use this path. Review,
  // publication, delivery and the ordinary no-JavaScript form keep their own actions.
  document.addEventListener('submit',async event=>{
    const form=event.target,button=event.submitter;
    if(!(form instanceof HTMLFormElement)||!form.closest('.native-manager-embedded')||!window.KulturhusNavigation||button?.name||form.elements.namedItem('operation')?.value!=='communication-save')return;
    const module=form.querySelector('[data-newsletter-save]')?.dataset.saveModule,key=form.elements.namedItem('save_request_key')?.value;
    if(!['newsletter','press','social'].includes(module)||!/^[a-f0-9]{32}$/.test(key||''))return;
    event.preventDefault();event.stopImmediatePropagation();if(pending.has(form))return;
    if(saved.has(form)){await window.KulturhusNavigation.navigate(saved.get(form),{replace:true});return;}
    const expectedId=form.elements.namedItem('id')?.value,body=new FormData(form),status=form.querySelector('[data-save-status]');
    const progress=document.createElement('p');progress.setAttribute('role','status');progress.textContent=contentText('Wird gespeichert …');form.before(progress);
    const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000),wasInert=form.inert;
    pending.add(form);form.inert=true;form.setAttribute('aria-busy','true');let destination=null;
    try{
      const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body,credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
      const data=await response.json();if(!response.ok)throw new Error(typeof data.error==='string'?data.error:contentText('Speicherung nicht bestätigt. Gespeicherten Stand prüfen.'));
      const url=new URL(data.url,location.href);
      if(data.ok!==true||url.origin!==location.origin||url.pathname!=='/vereinsverwaltung/'||url.searchParams.get('view')!==module||!/^[a-f0-9]{32}$/.test(url.searchParams.get('native_item')||'')||(expectedId&&url.searchParams.get('native_item')!==expectedId))throw new Error(contentText('Speicherung nicht bestätigt. Gespeicherten Stand prüfen.'));
      destination=url.href;saved.set(form,destination);window.KulturhusNavigation.markClean(form);
    }catch(error){
      if(form.isConnected){status.hidden=false;status.textContent=error.name==='Error'?contentText(error.message):contentText('Speicherung nicht bestätigt. Deine Angaben bleiben erhalten. Gespeicherten Stand prüfen oder unverändert erneut speichern.');}
    }finally{clearTimeout(timer);pending.delete(form);form.inert=wasInert;form.removeAttribute('aria-busy');progress.remove();}
    if(!destination||!form.isConnected)return;
    if(!(await window.KulturhusNavigation.navigate(destination,{replace:true}))&&form.isConnected){
      status.hidden=false;status.textContent=contentText('Entwurf gespeichert. Aktuellen Entwurf öffnen.');
      const link=document.createElement('a');link.href=destination;link.dataset.nav='';link.className='btn btn-outline theme-secondary';link.textContent=contentText('Aktuellen Entwurf öffnen');status.append(' ',link);
    }
  },true);
})();
