'use strict';
(()=>{if(window.KulturhusFormValidation)return;
 const accountPacks=new WeakMap();
 const uiText=(field,source)=>{const root=field?.closest('[data-account-settings]');if(!root)return (field?.closest('.free-tools,.free-workspace,[data-calendar-interface],[data-gema-interface],[data-document-interface]')||(field?.matches('form')&&field.querySelector('[data-document-interface]')))?(window.KulturhusInterface?.text(source)??source):source;if(!accountPacks.has(root)){let pack={};try{pack=JSON.parse(root.dataset.accountMessages||'{}');}catch{}accountPacks.set(root,pack);}const pack=accountPacks.get(root);return Object.hasOwn(pack,source)&&typeof pack[source]==='string'?pack[source]:source;};
 const errors=new Map(),summaries=new WeakMap();let sequence=0,queued=false,first=null;
 const source=f=>f?.matches?.('[data-select-search-input]')&&f.nextElementSibling?.matches('.select-search-native')?f.nextElementSibling:f;
 const display=f=>f.classList?.contains('select-search-native')?f.previousElementSibling:f;
 const eligible=f=>f&&f.form&&f.matches('input,select,textarea')&&!f.disabled&&!f.readOnly&&f.type!=='hidden'&&f.willValidate;
 const label=f=>display(f).getAttribute('aria-label')||Array.from(f.labels||[]).map(l=>Array.from(l.childNodes).filter(n=>n.nodeType===Node.TEXT_NODE||n.nodeType===Node.ELEMENT_NODE&&!n.matches('input,select,textarea,.field-validation-error')).map(n=>n.textContent).join(' ').replace(/\s+/g,' ').trim()).find(Boolean)||uiText(f,'Dieses Feld');
 function messageFor(f){const v=f.validity;if(v.customError)return f.validationMessage;if(v.valueMissing)return f.type==='checkbox'?uiText(f,'Bitte dieses Feld bestätigen.'):f.type==='file'?uiText(f,'Bitte eine Datei auswählen.'):f.tagName==='SELECT'?uiText(f,'Bitte einen Eintrag auswählen.'):uiText(f,'Bitte dieses Feld ausfüllen.');if(v.typeMismatch)return f.type==='email'?uiText(f,'Bitte eine gültige E-Mail-Adresse eingeben.'):uiText(f,'Bitte eine gültige Angabe eingeben.');if(v.rangeUnderflow)return uiText(f,'Bitte mindestens {value} eingeben.').replace('{value}',f.min);if(v.rangeOverflow)return uiText(f,'Bitte höchstens {value} eingeben.').replace('{value}',f.max);if(v.tooLong)return uiText(f,'Bitte höchstens {value} Zeichen eingeben.').replace('{value}',f.maxLength);if(v.tooShort)return uiText(f,'Bitte mindestens {value} Zeichen eingeben.').replace('{value}',f.minLength);if(v.badInput||v.stepMismatch)return uiText(f,'Bitte eine zulässige Zahl oder ein gültiges Datum eingeben.');if(v.patternMismatch)return f.title||uiText(f,'Bitte das angegebene Format beachten.');return f.validationMessage||uiText(f,'Bitte diese Angabe prüfen.');}
 function focus(f){const target=display(f);for(let el=target.parentElement;el;el=el.parentElement)if(el.tagName==='DETAILS')el.open=true;target.focus();target.scrollIntoView({block:'center',behavior:'smooth'});}
 function summary(form){let box=summaries.get(form);const rows=[...errors].filter(([f])=>f.form===form&&f.isConnected);if(!rows.length){box?.remove();summaries.delete(form);return;}if(!box){box=document.createElement('div');box.className='form-validation-summary';box.setAttribute('role','alert');box.tabIndex=-1;form.prepend(box);summaries.set(form,box);}box.replaceChildren();const title=document.createElement('strong');title.textContent=uiText(form,'Bitte die markierten Angaben prüfen:');box.append(title);const list=document.createElement('ul');for(const[f,entry]of rows){const li=document.createElement('li'),a=document.createElement('a');a.href='#'+display(f).id;a.textContent=label(f)+': '+entry.message;a.addEventListener('click',e=>{e.preventDefault();focus(f);});li.append(a);list.append(li);}box.append(list);}
 function queue(f){first??=f;if(queued)return;queued=true;requestAnimationFrame(()=>{queued=false;const initial=first;first=null;const forms=new Set([...errors.keys()].map(f=>f.form));for(const form of forms)if(form)summary(form);if(initial?.isConnected&&eligible(initial))focus(initial);});}
 function show(field,message,options={}){const f=source(field);if(!eligible(f))return;const target=display(f);message=message&&message!==f.validationMessage?message:messageFor(f);let entry=errors.get(f);if(!entry){const note=document.createElement('span');note.className='field-validation-error';note.id='field-validation-error-'+(++sequence);f.after(note);entry={note,target,message,form:f.form};errors.set(f,entry);if(!target.id)target.id='validation-field-'+sequence;const ids=new Set((target.getAttribute('aria-describedby')||'').split(/\s+/).filter(Boolean));ids.add(note.id);target.setAttribute('aria-describedby',[...ids].join(' '));}entry.message=message;entry.note.textContent=message;target.setAttribute('aria-invalid','true');if(options.focus!==false)queue(f);else summary(f.form);}
 function clear(field){const f=source(field),entry=errors.get(f);if(!entry)return;const ids=(entry.target.getAttribute('aria-describedby')||'').split(/\s+/).filter(id=>id&&id!==entry.note.id);if(ids.length)entry.target.setAttribute('aria-describedby',ids.join(' '));else entry.target.removeAttribute('aria-describedby');entry.target.removeAttribute('aria-invalid');entry.note.remove();errors.delete(f);if(entry.form)summary(entry.form);}
 function validate(form,options={}){let valid=true;for(const f of form.elements){if(!eligible(f)){clear(f);continue;}if(source(f)!==f)continue;if(!f.validity.valid){show(f,f.validationMessage,{focus:options.focus!==false});valid=false;}else clear(f);}return valid;}
 document.addEventListener('invalid',event=>{const f=source(event.target);if(!eligible(f))return;event.preventDefault();show(f,event.target.validationMessage||f.validationMessage);},true);
 const corrected=event=>{const f=source(event.target);if(!eligible(f))return;queueMicrotask(()=>{if(f.validity.valid)clear(f);else if(errors.has(f))show(f,f.validationMessage,{focus:false});});};document.addEventListener('input',corrected);document.addEventListener('change',corrected);
 document.addEventListener('reset',event=>{setTimeout(()=>{for(const f of [...errors.keys()])if(f.form===event.target)clear(f);},0);});
 let cleanupQueued=false;
 new MutationObserver(records=>{for(const record of records)if(record.type==='attributes'&&errors.has(record.target)&&record.target.validity?.valid)clear(record.target);if(!errors.size||cleanupQueued)return;cleanupQueued=true;queueMicrotask(()=>{cleanupQueued=false;for(const [field,entry] of [...errors]){if(!field.isConnected||!eligible(field)||field.form!==entry.form)clear(field);}});}).observe(document.documentElement,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','readonly','required','min','max','pattern','type','form']});
 window.KulturhusFormValidation={show,clear,validate,focus};
 window.KulturhusPage?.register?.('form-validation-restore', pageContext=>{
 const requestAnimationFrame=pageContext.frame;
 const restore=document.querySelector('meta[name="form-validation-post"]');if(restore){let data;try{data=JSON.parse(restore.content);}catch{return;}if(data.fields&&data.action)requestAnimationFrame(()=>{for(const form of document.forms){if(form.querySelector('[name="action"]')?.value!==data.action)continue;const matches=Object.entries(data.identity||{}).every(([key,value])=>{const field=[...form.elements].find(f=>f.type==='hidden'&&f.name===key);return !field||field.value===value;});if(matches)validate(form);}});}
 });
})();

/* Only an explicit conflict-resolution click selects the server-rendered current CAS.
   The target and submitted values stay bound to the same visible editor. */
(()=>{'use strict';
 const enable=()=>document.querySelectorAll('[data-form-recovery-current]').forEach(button=>button.hidden=false);
 if(window.KulturhusPage?.register)window.KulturhusPage.register('form-recovery',enable);
 else if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',enable,{once:true});else enable();
 document.addEventListener('manager:mounted',enable);
 document.addEventListener('click',event=>{
  const button=event.target.closest?.('[data-form-recovery-current]');if(!button)return;
  const form=document.getElementById(button.dataset.recoveryForm);if(!(form instanceof HTMLFormElement))return;
  const target=[...form.elements].find(field=>field.type==='hidden'&&field.name===button.dataset.recoveryTargetField);
  if(!target||target.value!==button.dataset.recoveryTarget)return;
  let versions;try{versions=JSON.parse(button.dataset.recoveryVersions);}catch{return;}
  const changes=[];for(const[name,value]of Object.entries(versions)){
   if(!['version','custom_fields_version'].includes(name)||typeof value!=='string'||!/^\d+$/.test(value))return;
   const field=[...form.elements].find(node=>node.type==='hidden'&&node.name===name);if(!field)return;changes.push([field,value]);
  }
  if(!changes.length)return;event.preventDefault();for(const[field,value]of changes){field.value=value;field.dispatchEvent(new Event('input',{bubbles:true}));}
  const status=button.parentElement.querySelector('[data-form-recovery-status]');if(status)status.textContent=button.dataset.recoveryMessage;
  button.disabled=true;const first=[...form.elements].find(field=>!field.disabled&&field.type!=='hidden'&&field.matches('input,textarea,select'));first?.focus();
 });
})();
