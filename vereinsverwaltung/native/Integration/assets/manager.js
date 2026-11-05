(() => {
  'use strict';
  if (!window.KulturhusPage) return;
  window.KulturhusPage.register('native-manager', context => {
    const root = context.root.querySelector('.native-manager-embedded');
    if (!root) return;
    window.KulturhusEditor?.mount(root); window.KulturhusRichText?.mount(root); window.KulturhusComposePreview?.mount(root,context); window.KulturhusPageLive?.mount(root,context);
    // Finish initial field enablement before navigation records successful form controls.
    window.KulturhusFieldConditions?.mount(root); window.KulturhusFieldGroups?.mount(root); window.KulturhusNativeChat?.mount();
    context.cleanup(()=>window.KulturhusEditor?.unmount(root));
    const status = document.createElement('p'); status.dataset.nativeStatus = ''; status.setAttribute('role','status'); root.prepend(status);
    let busy = false;
    const storyDisabled = new WeakMap();
    const report = text => { if (!context.signal.aborted) status.textContent = text; };
    const story = form => { for(const panel of form.querySelectorAll('[data-story-panel], [data-carousel-panel]')){panel.hidden=form.elements.kind.value!==(panel.hasAttribute('data-carousel-panel')?'carousel':'story');panel.querySelectorAll('input,textarea,select,button').forEach(field=>{if(panel.hidden){if(!storyDisabled.has(field))storyDisabled.set(field,field.disabled);field.disabled=true;}else if(storyDisabled.has(field)){field.disabled=storyDisabled.get(field);storyDisabled.delete(field);}});} };
    root.querySelectorAll('form').forEach(story);
    const menuButtons = () => root.querySelectorAll('.native-menu-list').forEach(list => {
      const rows=[...list.querySelectorAll('[data-menu-row]')];
      rows.forEach((row,index)=>row.querySelectorAll('[data-menu-step]').forEach(button=>button.disabled=Number(button.dataset.menuStep)<0?index===0:index===rows.length-1));
    });
    menuButtons();
    context.on(root,'click',event=>{
      const button=event.target.closest('[data-menu-step]');if(!button||button.matches(':disabled'))return;
      const row=button.closest('[data-menu-row]'),list=row?.parentElement;if(!list)return;
      const up=Number(button.dataset.menuStep)<0,other=up?row.previousElementSibling:row.nextElementSibling;
      if(!other?.matches('[data-menu-row]'))return;
      if(up)list.insertBefore(row,other);else list.insertBefore(other,row);
      list.querySelectorAll('[data-menu-position]').forEach((field,index)=>{field.value=String(index);field.dispatchEvent(new Event('input',{bubbles:true}));});
      menuButtons();(button.disabled?row.querySelector('[data-menu-step]:not(:disabled)'):button)?.focus();
    });
    context.on(root,'change',async event=>{
      const select=event.target;
      if(select.matches('[name=kind]')){const template=select.form.querySelector('[name=template]');if(template&&['social-post','social-story','social-reel','social-carousel'].includes(template.value)&&['post','story','reel','carousel'].includes(select.value))template.value='social-'+select.value;story(select.form);}
      if(!select.matches('[data-model-template]'))return;
      const chosen=select.value,target=select.form.querySelector('[data-model-fields]');
      try{const response=await context.fetch(root.dataset.modelUrl+'&template='+encodeURIComponent(chosen),{headers:{Accept:'application/json'}});if(!response.ok)throw new Error('Vorlagenfelder nicht verfügbar.');const data=await response.json();if(!context.signal.aborted&&select.value===chosen)target.innerHTML=data.html;}
      catch(error){if(error.name!=='AbortError')report(error.message);}
    });
    context.guard(()=>busy||window.KulturhusPhotoLibrary?.guarded(root));
    // Save the page and its PDF batch together; a rejected upload keeps the actual form intact.
    let pageSaved = false;
    context.on(root,'submit',async event=>{
      const form=event.target;
      if(!(form instanceof HTMLFormElement)||!form.matches('[data-video-recovery-form],[data-video-reuse-form]'))return;
      event.preventDefault();if(busy)return;
      const body=new FormData(form),issue=form.querySelector('[data-video-lifecycle-error]'),check=form.querySelector('[data-video-lifecycle-check]'),buttons=[...form.querySelectorAll('button')];
      const controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
      busy=true;root.inert=true;root.setAttribute('aria-busy','true');buttons.forEach(button=>button.disabled=true);issue.hidden=true;
      let destination=null,uncertain=true,failure='Ergebnis nicht bestätigt. Vorgang prüfen.';
      try{
        const response=await context.fetch(form.getAttribute('action')||location.href,{method:'POST',body,credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
        const data=await response.json();
        if(!response.ok){if([400,403,404,409,422].includes(response.status)&&typeof data.error==='string'){uncertain=false;failure=data.error;}throw new Error('Rejected recovery');}
        const url=new URL(data.url,location.href);
        if(data.ok!==true||url.origin!==location.origin||url.pathname!=='/vereinsverwaltung/'||url.searchParams.get('view')!=='social'||url.searchParams.get('native_item')!==body.get('id')||(form.hasAttribute('data-video-reuse-form')?url.searchParams.get('video_use_result')!==new URL(check.href).searchParams.get('video_use_result'):url.searchParams.get('video_recovery_source')!==body.get('source_id')))throw new Error('Unconfirmed recovery');
        uncertain=false;destination=url.href;window.KulturhusNavigation.markClean(form);
      }catch(error){if(!context.signal.aborted){issue.textContent=failure;issue.hidden=false;check.hidden=false;window.KulturhusNavigation.markClean(form);}}
      finally{clearTimeout(timer);busy=false;root.inert=false;root.removeAttribute('aria-busy');buttons.forEach(button=>button.disabled=uncertain);if(!issue.hidden&&!context.signal.aborted){issue.focus();issue.scrollIntoView({block:'nearest'});}}
      if(context.signal.aborted||!destination)return;
      if(!(await window.KulturhusNavigation.navigate(destination,{replace:true}))&&!context.signal.aborted){issue.textContent='Videofassung gespeichert. Vorgang prüfen.';issue.hidden=false;check.hidden=false;}
    });
    context.on(root,'submit',async event=>{
      const form=event.target;
      if(!(form instanceof HTMLFormElement)||form.querySelector('[name=operation]')?.value!=='page-save'||!form.querySelector('.website-documents'))return;
      event.preventDefault();if(busy||pageSaved)return;
      const body=new FormData(form),controller=new AbortController(),timer=setTimeout(()=>controller.abort(),45000);
      busy=true;root.inert=true;root.setAttribute('aria-busy','true');report('Wird gespeichert …');
      let destination=null,failure='Speicherung nicht bestätigt. Bitte den gespeicherten Seitenstand prüfen.';
      try{
        const response=await context.fetch(form.getAttribute('action')||location.href,{method:'POST',body,credentials:'same-origin',redirect:'error',signal:controller.signal,headers:{Accept:'application/json'}});
        const data=await response.json();
        if(!response.ok){if([400,403,404,409,422].includes(response.status)&&typeof data.error==='string')failure=data.error;throw new Error('Rejected save');}
        const url=new URL(data.url,location.href);
        if(data.ok!==true||url.origin!==location.origin||url.pathname!=='/vereinsverwaltung/'||url.searchParams.get('view')!=='website'||url.searchParams.get('tab')!=='pages'||!/^[a-f0-9]{32}$/.test(url.searchParams.get('native_item')||''))throw new Error('Speicherung nicht bestätigt. Bitte den gespeicherten Seitenstand prüfen.');
        pageSaved=true;destination=url.href;window.KulturhusNavigation.markClean(form);
      }catch(error){if(!context.signal.aborted)report(failure);}
      finally{clearTimeout(timer);busy=false;root.inert=false;root.removeAttribute('aria-busy');}
      if(context.signal.aborted||!destination)return;
      if(!(await window.KulturhusNavigation.navigate(destination,{replace:true}))&&!context.signal.aborted){
        report('Gespeichert.');const link=document.createElement('a');link.href=destination;link.dataset.navigationNative='';link.textContent='Seite öffnen';status.append(' ',link);
      }
    });
    for(const type of ['dragenter','dragover'])context.on(root,type,event=>{const drop=event.target.closest('[data-album-drop]');if(drop){event.preventDefault();drop.classList.add('dragging');}});
    context.on(root,'dragleave',event=>event.target.closest('[data-album-drop]')?.classList.remove('dragging'));
    context.on(root,'drop',event=>{const drop=event.target.closest('[data-album-drop]');if(!drop)return;event.preventDefault();drop.classList.remove('dragging');const input=drop.querySelector('[data-album-upload]');if(!input||input.disabled)return;const files=new DataTransfer();for(const file of event.dataTransfer?.files||[])files.items.add(file);input.files=files.files;input.dispatchEvent(new Event('change',{bubbles:true}));});
  });
})();
