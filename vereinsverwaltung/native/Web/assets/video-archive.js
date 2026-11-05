/* Source archive choices never navigate away from an unfinished editorial document. */
(() => {
  'use strict';
  const roots=new WeakSet(),states=new Map();
  const mount=(scope=document,context=null)=>{
    scope.querySelectorAll('[data-video-archive]').forEach(root=>{
      const form=root.querySelector('[data-video-archive-form]');if(!form||roots.has(root))return;roots.add(root);
      let messages={};try{messages=JSON.parse(root.dataset.archiveInterface);}catch(_){}const text=s=>typeof messages[s]==='string'?messages[s]:s;
      const keyField=form.querySelector('[name=archive_request_key]'),version=form.querySelector('[name=archive_version]'),choice=form.querySelector('[name=archived]'),status=root.querySelector('[data-archive-status]');
      const save=form.querySelector('[data-archive-save]'),review=form.querySelector('[data-archive-review]'),retry=form.querySelector('[data-archive-retry]'),next=form.querySelector('[data-archive-next]'),current=root.querySelector('[data-archive-current]');
      const lifecycle=new AbortController(),on=(node,type,fn,options={})=>node.addEventListener(type,fn,{...options,signal:lifecycle.signal});
      let readOnly=root.dataset.archiveReadOnly==='1',fileAvailable=true,fileVersion=Number(root.closest('[data-video-file-version]')?.dataset.videoFileVersion||0),attempt=null,reviewKey=keyField.value,busy=false,disposed=false,request=null,confirmed=null,needsNext=false,unsaved=form.hasAttribute('data-unsaved-changes'),shownVersion=Number(version.value),observed=null,knownAbsent=false,savedPanel=root.querySelector('[data-archive-confirmed]');
      try{if(savedPanel){confirmed=JSON.parse(savedPanel.dataset.archiveConfirmed);needsNext=true;}}catch(_){}
      const newKey=()=>[...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');
      const controls=()=>{save.hidden=readOnly||needsNext;save.disabled=busy||readOnly||!fileAvailable||attempt!==null;save.textContent=text(choice.value==='1'?'Video archivieren':'Video zurückholen');review.disabled=busy;retry.hidden=attempt===null;retry.disabled=busy||readOnly||!fileAvailable;next.hidden=!needsNext||readOnly;next.disabled=busy||readOnly;form.toggleAttribute('aria-busy',busy);};
      const report=(source,error=false)=>{if(disposed||!root.isConnected)return;status.textContent=text(source);status.setAttribute('role',error?'alert':'status');if(error){status.focus();status.scrollIntoView({block:'nearest'});}};
      const validate=(result,key)=>{if(result?.found!==true||result.request_key!==key||result.item_id!==form.querySelector('[name=id]').value||result.source_id!==form.querySelector('[name=source_id]').value||!Number.isSafeInteger(result.version)||result.version<1||result.version>4294967295||typeof result.archived!=='boolean'||typeof result.actor_name!=='string'||[...result.actor_name].length>200||typeof result.changed_at!=='string'||!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(?:\.\d{1,6})?$/.test(result.changed_at)||Number.isNaN(Date.parse(result.changed_at.replace(' ','T')+'Z')))throw Error('Unconfirmed archive receipt');};
      const clock=utc=>{const parts=Object.fromEntries(new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/Berlin',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(utc.replace(' ','T')+'Z')).map(p=>[p.type,p.value]));return(document.documentElement.lang.split('-')[0]==='en'?`${parts.year}-${parts.month}-${parts.day}`:`${parts.day}.${parts.month}.${parts.year}`)+` ${parts.hour}:${parts.minute} Europe/Berlin`;};
      const catalogue=data=>{if(!data||data.item_id!==form.querySelector('[name=id]').value||!['active_total','archived_total'].every(k=>Number.isSafeInteger(data[k])&&data[k]>=0))return;const panel=root.closest('#social-videos');for(const key of ['active','archived'])panel?.querySelectorAll('[data-video-'+key+'-count]').forEach(n=>n.textContent=String(data[key+'_total']));};
      const showChoice=result=>{
        if(result.version<shownVersion)return;shownVersion=result.version;
          const card=root.closest('.social-video-card-tools');let badge=card?.querySelector('[data-archive-current-state]');if(card&&!badge){badge=document.createElement('p');badge.dataset.archiveCurrentState='';root.before(badge);}if(badge)badge.textContent=text(result.archived?'Archiviert':'Zurückgeholt');
          if(result.archived)card?.querySelectorAll('form:has(input[name=operation][value=communication-approve]) button,form:has(input[name=operation][value=social-video-renew]) button').forEach(n=>{if(!n.hasAttribute('data-archive-was-disabled'))n.dataset.archiveWasDisabled=n.disabled?'1':'0';n.disabled=true;});
          else card?.querySelectorAll('[data-archive-was-disabled]').forEach(n=>{n.disabled=n.dataset.archiveWasDisabled==='1';delete n.dataset.archiveWasDisabled;});
      };
      const applyChoice=result=>{
        if(result.version<shownVersion||result.version<Number(version.value))return;
        showChoice(result);version.value=String(result.version);choice.value=result.archived?'0':'1';
      };
      const accept=(result,key)=>{
        validate(result,key);confirmed=result;reviewKey=key;const completed=attempt?.key===key?attempt:null;if(completed){attempt=null;unsaved=false;}needsNext=false;knownAbsent=false;
        if(keyField.value===key)keyField.value=newKey();
        // A historical confirmation must not undo a later consciously accepted local choice.
        applyChoice(result);
        if(!unsaved){window.KulturhusNativeForms?.clean(form);window.KulturhusNavigation?.markClean(form);}
        if(!savedPanel){savedPanel=document.createElement('section');savedPanel.className='video-archive-saved';form.before(savedPanel);}savedPanel.replaceChildren();savedPanel.hidden=false;
        const h=document.createElement('h3'),p=document.createElement('p'),v=document.createElement('p'),time=document.createElement('time');h.textContent=text('Gespeicherte Archivwahl');p.textContent=text(result.archived?'Archiviert':'Zurückgeholt')+' · '+result.actor_name;v.textContent=text('Archivversion')+': '+result.version;time.dateTime=result.changed_at.replace(' ','T')+'Z';time.textContent=clock(result.changed_at);savedPanel.append(h,p,time,v);
        const url=new URL(current.href,location.href);url.searchParams.set('video_archive',result.archived?'1':'0');current.href=url.href;report('Archivwahl gespeichert. Der Beitrag bleibt geöffnet.');
        const panel=root.closest('#social-videos');if(panel)document.dispatchEvent(new CustomEvent('kulturhus:video-archive-confirmed',{detail:{origin:form,panel,result}}));
      };
      const run=async(action,exact=false)=>{
        if(disposed||busy||(readOnly&&action!=='review'))return;
        if(action==='next'){if(!confirmed||(attempt&&!(knownAbsent&&confirmed.version>Number(version.value))))return;validate(confirmed,confirmed.request_key);attempt=null;version.value=String(confirmed.version);choice.value=confirmed.archived?'0':'1';keyField.value=newKey();reviewKey=confirmed.request_key;needsNext=false;knownAbsent=false;unsaved=true;controls();report('Andere Archivwahl vorbereitet. Noch nicht gespeichert.');return;}
        let body,key;if(action==='review'){key=reviewKey;body=new FormData(form);body.delete('archive_next');body.set('archive_review','1');body.set('archive_request_key',key);}else{if(!attempt){if(exact||needsNext)return;body=new FormData(form);body.delete('archive_review');body.delete('archive_next');attempt={body,key:keyField.value};reviewKey=attempt.key;}body=attempt.body;key=attempt.key;}
        if(action!=='review')knownAbsent=false;if(savedPanel)savedPanel.hidden=true;busy=true;controls();request=new AbortController();const abort=request,timer=setTimeout(()=>abort.abort(),45000);let failure=text('Speicherung der Archivwahl nicht bestätigt. Ursprünglichen Vorgang behalten und gespeicherte Archivwahl prüfen.');report(action==='review'?'Gespeicherte Archivwahl prüfen':'Archivwahl wird gespeichert …');
        try{const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body,credentials:'same-origin',cache:'no-store',redirect:'error',signal:abort.signal,headers:{Accept:'application/json'}}),data=await response.json();
          if(typeof data.can_edit==='boolean')readOnly=!data.can_edit;if(typeof data.csrf==='string'&&/^[a-f0-9]{64}$/.test(data.csrf)){form.querySelector('[name=csrf]').value=data.csrf;attempt?.body.set('csrf',data.csrf);}
          if(!response.ok){if([400,403,404,409,422].includes(response.status)&&typeof data.error==='string')failure=data.error;throw Error('Unconfirmed archive save');}if(disposed||!root.isConnected)return;catalogue(data.catalogue);
          if(action==='review'&&data.video_archive_result?.found===false){knownAbsent=true;if(observed&&observed.version>Number(version.value)){confirmed=observed;needsNext=true;}report('Keine gespeicherte Archivwahl für diesen Vorgang gefunden. Unverändert erneut versuchen.');}else accept(data.video_archive_result,key);
        }catch(_){if(!disposed&&root.isConnected){status.textContent=failure;status.setAttribute('role','alert');status.focus();status.scrollIntoView({block:'nearest'});}}
        finally{clearTimeout(timer);request=null;busy=false;if(!disposed&&root.isConnected)controls();}
      };
      const dispose=()=>{if(disposed)return;disposed=true;lifecycle.abort();request?.abort();attempt=null;states.delete(form);roots.delete(root);};const guarded=()=>!disposed&&(busy||attempt!==null||unsaved);states.set(form,{guarded,dispose});
      on(document,'kulturhus:video-archive-confirmed',event=>{
        const data=event.detail,panel=root.closest('#social-videos');if(!panel||data?.panel!==panel||data.origin===form||data.result?.item_id!==form.querySelector('[name=id]').value||data.result?.source_id!==form.querySelector('[name=source_id]').value)return;
        try{validate(data.result,data.result.request_key);}catch(_){return;}
        if(!observed||data.result.version>observed.version)observed=data.result;
        const canAdvance=!busy&&!attempt&&!unsaved&&data.result.version>Number(version.value);
        if(canAdvance){applyChoice(data.result);keyField.value=newKey();reviewKey=data.result.request_key;confirmed=data.result;needsNext=false;}
        else showChoice(data.result);
        controls();
      });
      on(document,'kulturhus:video-file-state',event=>{const d=event.detail;if(!root.closest('#social-videos')||d?.panel!==root.closest('#social-videos')||d?.source!==form.querySelector('[name=source_id]').value||!Number.isSafeInteger(d.current?.version)||d.current.version<fileVersion)return;fileVersion=d.current.version;fileAvailable=d.current.state==='active';controls();});
      on(form,'submit',event=>{event.preventDefault();event.stopImmediatePropagation();void run(event.submitter===review?'review':event.submitter===next?'next':'save');},{capture:true});on(retry,'click',()=>void run('save',true));context?.cleanup(dispose);context?.guard(guarded);if(!context){on(window,'pagehide',dispose,{once:true});on(window,'beforeunload',event=>{if(guarded()){event.preventDefault();event.returnValue='';}});}controls();
    });
  };
  window.KulturhusVideoArchive=Object.freeze({mount,unmount:scope=>{for(const[form,state]of states)if(scope.contains(form))state.dispose();},guarded:scope=>[...states].some(([form,state])=>form.isConnected&&(!scope||scope.contains(form))&&state.guarded())});
  if(window.KulturhusPage)window.KulturhusPage.register('video-archive',context=>mount(context.root,context));else if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>mount(),{once:true});else mount();
})();
