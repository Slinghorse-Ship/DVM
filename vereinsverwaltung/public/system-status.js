const labels={ok:'In Ordnung',not_configured:'Nicht eingerichtet',disabled:'Deaktiviert',not_checked:'Nicht geprüft',restricted:'Eingeschränkt',down:'Nicht erreichbar',provider_error:'Anbieterstörung',application_error:'Anwendungsfehler',stale:'Nachweis veraltet'};
const dictionaries=new WeakMap();
function text(panel,source,values={}){
 if(!dictionaries.has(panel)){let messages={};try{messages=JSON.parse(panel.dataset.statusMessages||'{}');}catch{}dictionaries.set(panel,messages);}
 let result=dictionaries.get(panel)[source]??window.KulturhusInterface?.text(source)??source;
 for(const [key,value]of Object.entries(values))result=result.replaceAll('{'+key+'}',String(value));return result;
}
const locale=panel=>panel.dataset.statusLocale?.split('-')[0]==='en'?'en-GB':'de-DE';
const stamp=(panel,value)=>{if(!value)return text(panel,'Noch keine Prüfung');const date=new Date(value.replace(' ','T')+'Z');return Number.isNaN(date.getTime())?text(panel,'Zeitpunkt nicht bestätigt'):new Intl.DateTimeFormat(locale(panel),{timeZone:'Europe/Berlin',day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(date);};
document.addEventListener('submit',async event=>{
 const form=event.target;if(!(form instanceof HTMLFormElement)||!form.matches('[data-status-check]'))return;
 event.preventDefault();const panel=form.closest('[data-status-panel]'),row=form.closest('[data-status-service]'),button=form.querySelector('button[type="submit"]'),feedback=panel.querySelector('[data-status-feedback]');if(button.disabled)return;button.disabled=true;row.setAttribute('aria-busy','true');feedback.textContent=text(panel,'Prüfung läuft …');
 const timeout=new AbortController(),timer=setTimeout(()=>timeout.abort(),45000);
 try{
  const response=await fetch(form.getAttribute('action')||location.href,{method:'POST',body:new FormData(form),headers:{Accept:'application/json'},credentials:'same-origin',signal:timeout.signal});
  const data=await response.json();if(!response.ok||data.error)throw Object.assign(new Error(data.error||'Prüfung nicht bestätigt.'),{statusMessage:true});const result=data.service;
  if(!result||result.id!==row.dataset.statusService||!Object.hasOwn(labels,result.state))throw new Error('Prüfergebnis nicht bestätigt.');if(!row.isConnected)return;
  const label=text(panel,labels[result.state]),badge=row.querySelector('[data-status-state]');badge.className='status-badge status-'+result.state;badge.textContent=label;row.querySelector('[data-status-time]').textContent=stamp(panel,result.checked_at);row.querySelector('[data-status-duration]').textContent=Number.isInteger(result.duration_ms)?text(panel,'Prüfdauer {ms} ms',{ms:result.duration_ms.toLocaleString(locale(panel))}):'';row.querySelector('[data-status-reason]').textContent='';feedback.textContent=text(panel,result.label)+': '+label+(result.cached?text(panel,' · vorhandener aktueller Nachweis'):'');
 }catch(error){if(row.isConnected)feedback.textContent=text(panel,error.name==='AbortError'?'Prüfung noch nicht bestätigt. Gespeicherten Stand aktualisieren.':(error.statusMessage?error.message:'Prüfung nicht bestätigt. Gespeicherten Stand aktualisieren.'));}
 finally{clearTimeout(timer);if(row.isConnected){button.disabled=false;row.removeAttribute('aria-busy');}}
},true);

// Read stored background results only. Opening a page never starts provider probes.
const refreshing=new WeakSet();
async function refreshStored(panel){
 if(panel.dataset.statusAutomatic!=='1'||refreshing.has(panel)||document.hidden||!panel.isConnected)return;
 refreshing.add(panel);const abort=new AbortController(),timer=setTimeout(()=>abort.abort(),15000);
 try{
  const url=new URL(location.href);url.search='';url.searchParams.set('view','settings');url.searchParams.set('tab','status');url.searchParams.set('group',panel.dataset.statusGroup||'system');url.searchParams.set('status_json','1');
  const response=await fetch(url,{headers:{Accept:'application/json'},credentials:'same-origin',cache:'no-store',signal:abort.signal});if(!response.ok)return;const data=await response.json();if(!Array.isArray(data.services)||!panel.isConnected)return;
  let reasons={};try{reasons=JSON.parse(panel.dataset.statusReasons||'{}');}catch{}
  for(const result of data.services){if(!result||!Object.hasOwn(labels,result.state)||typeof result.id!=='string')continue;const row=[...panel.querySelectorAll('[data-status-service]')].find(n=>n.dataset.statusService===result.id);if(!row||row.getAttribute('aria-busy')==='true')continue;
   const badge=row.querySelector('[data-status-state]');badge.className='status-badge status-'+result.state;badge.textContent=text(panel,labels[result.state]);row.querySelector('[data-status-time]').textContent=stamp(panel,result.checked_at);row.querySelector('[data-status-duration]').textContent=Number.isInteger(result.duration_ms)?text(panel,'Prüfdauer {ms} ms',{ms:result.duration_ms.toLocaleString(locale(panel))}):'';row.querySelector('[data-status-reason]').textContent=reasons[result.reason]||'';
  }
 }catch{}finally{clearTimeout(timer);refreshing.delete(panel);}
}
setInterval(()=>document.querySelectorAll('[data-status-panel]').forEach(refreshStored),30000);
document.addEventListener('visibilitychange',()=>{if(!document.hidden)document.querySelectorAll('[data-status-panel]').forEach(refreshStored);});
