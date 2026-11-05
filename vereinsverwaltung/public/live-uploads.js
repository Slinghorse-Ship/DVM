window.KulturhusPage.register('live-uploads', pageContext => {
'use strict';
const text=source=>window.KulturhusInterface?.text(source)??source;
const format=(source,values)=>text(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
(() => {
 const purposes={
  'create-entry':['receipt_file'],
  'document-upload-many':['document_files'],'document-upload':['document_file'],'document-version':['document_file'],
  'gema-upload-invoice':['gema_file'],'inventory-file':['inventory_file'],'member-import-upload':['member_file'],
  'free-import-preview':['csv_file'],'document-check-save':['check_file'],'minutes-save':['minute_pdf'],
  'settings-save':['logo','signature'],'settings-signature':['signature'],'chat-send':['chat_file']
 };
 const automatic=new Set(['document-upload','gema-upload-invoice','inventory-file','member-import-upload','free-import-preview']);
 let restored={};try{restored=JSON.parse(document.querySelector('meta[name="live-upload-restore"]')?.content||'{}');}catch{}
 const key=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
 const request=(url,data,progress)=>new Promise((resolve,reject)=>{
  if(pageContext.signal.aborted){reject(new DOMException('Page closed','AbortError'));return;}
  const xhr=new XMLHttpRequest();pageContext.cleanup(()=>xhr.abort());xhr.onabort=()=>reject(new DOMException('Page closed','AbortError'));xhr.open('POST',url);xhr.timeout=180000;xhr.upload.onprogress=e=>{if(e.lengthComputable)progress?.(Math.round(e.loaded/e.total*100));};
  xhr.onload=()=>{let r;try{r=JSON.parse(xhr.responseText);}catch{reject(Error(text('Upload nicht bestätigt. Anmeldung prüfen und erneut versuchen.')));return;}if(xhr.status>=200&&xhr.status<300&&r.ok)resolve(r);else reject(Error(r.error||text('Upload fehlgeschlagen.')));};
  xhr.onerror=()=>reject(Error(text('Verbindung unterbrochen. Erneut versuchen.')));xhr.ontimeout=()=>reject(Error(text('Zeitüberschreitung. Erneut versuchen.')));xhr.send(data);
 });
 for(const form of document.querySelectorAll('form')){
  const action=form.querySelector('input[name="action"]')?.value;if(form.hasAttribute('data-native-attachment')||!purposes[action]||(action==='document-upload-many'&&!window.KulturhusDocumentGroups))continue;
  const endpoint=new URL(form.getAttribute('action')||location.href,location.href).href;
  const all=[];let busy=false,committing=false;form.querySelector('[data-document-native-uploads]')?.setAttribute('hidden','');
  const appendField=node=>{const footer=form.hasAttribute('data-inventory-invoice-form')?form.querySelector('[name=inventory_invoice_complete]'):null;if(footer)form.insertBefore(node,footer);else form.append(node);};
  pageContext.guard(()=>busy||committing);
  const csrf=form.querySelector('[name="csrf"]')?.value;if(!csrf)continue;
  const submit=Array.from(form.querySelectorAll('button[type="submit"],button:not([type])')).find(button=>button.name!=='document_browse');
  if(action==='document-upload-many'){const common=form.querySelector('[name="title"]');if(common){common.required=false;common.closest('label').hidden=true;}const commonDate=form.querySelector('[name="document_date"]');if(commonDate){commonDate.required=false;commonDate.closest('label').hidden=true;}if(submit)submit.textContent=text(form.dataset.invoiceInbox==='1'?'Dateien prüfen und speichern':'Zuordnung prüfen und speichern');}
  let assignmentApproved=false;
  const groupManager=action==='document-upload-many'?window.KulturhusDocumentGroups?.create(form,restored,pageContext):null;
  const reviewAssignment=()=>groupManager?.review(all,()=>{assignmentApproved=true;let marker=form.querySelector('[name="document_confirm"]');if(!marker){marker=document.createElement('input');marker.type='hidden';marker.name='document_confirm';appendField(marker);}marker.value='1';form.requestSubmit(submit||undefined);});
  form.addEventListener('submit',event=>{if(event.submitter?.name==='document_browse'){if(busy||committing){event.preventDefault();}return;}if(busy||committing||all.some(r=>r.state==='failed')){event.preventDefault();all.find(r=>r.state==='failed')?.li.scrollIntoView({block:'nearest'});}else if(action==='document-upload-many'&&!assignmentApproved){event.preventDefault();reviewAssignment();}else{committing=true;assignmentApproved=false;queueMicrotask(()=>{if(event.defaultPrevented)committing=false;});}});
  const complete=()=>{
   if(pageContext.signal.aborted)return;
   busy=false;for(const row of all)row.progress.hidden=row.state==='ready';
   if(all.length&&all.every(r=>r.state==='ready')&&automatic.has(action)&&!committing){
    const title=form.querySelector('[name="title"]');if(title&&!title.value.trim())title.value=all.length===1?all[0].name:text('Dokumente');
    if(form.checkValidity()){form.requestSubmit(submit||undefined);}else{if(submit)submit.hidden=false;form.reportValidity();}
   }
  };
  for(const input of form.querySelectorAll('input[type="file"]')){
   const requiredAtStart=input.required;const field=input.name.replace(/\[\]$/,'');if(!purposes[action].includes(field))continue;
   for(const retained of form.querySelectorAll('[data-live-upload-retained]'))if(retained.dataset.liveUploadRetained===field)retained.hidden=true;
   const label=input.closest('label');const area=document.createElement('div');area.className='live-upload';
   const picker=document.createElement('button');picker.type='button';picker.className='live-upload-picker';picker.textContent=text(input.multiple?'Dateien hineinziehen oder auswählen':'Datei hineinziehen oder auswählen');
   const hint=document.createElement('p');hint.className='live-upload-status';hint.textContent=text(action==='document-upload-many'?'Dateien werden sofort übertragen. Titel, Datum und Ablageziel je Datei prüfen. Anschließend die Zuordnungen bestätigen und speichern.':automatic.has(action)?'Gewünschte Zuordnung zuerst einstellen. Die Datei wird nach Auswahl sofort hochgeladen und zugeordnet.':action==='chat-send'?'Upload startet sofort. Anschließend das Formular speichern bzw. die Nachricht senden.':'Upload startet sofort. Anschließend das Formular speichern.');
   const list=document.createElement('ul');list.className='live-upload-list';list.setAttribute('aria-live','polite');const formats=document.createElement('p');formats.className='live-upload-status';formats.textContent=(label?.querySelector('small')?.textContent||label?.innerText||'').trim();area.append(picker,formats,hint,list);(label||input).after(area);(label||input).classList.add('live-upload-native');
   if(automatic.has(action)&&action!=='free-import-preview'&&submit)submit.hidden=!(restored.action===action&&restored.tokens?.[field]?.length);
   picker.addEventListener('click',()=>{if(!busy)input.click();});
   const remove=async row=>{if(busy)return;busy=true;try{if(row.token){const data=new FormData();data.set('csrf',csrf);data.set('action','live-upload-remove');data.set('token',row.token);await request(endpoint,data);}row.hidden?.remove();groupManager?.remove(row);row.li.remove();all.splice(all.indexOf(row),1);if(!all.some(r=>r.field===field))input.required=row.required;}catch(e){row.status.textContent=e.message;}finally{busy=false;}};
   const ready=(row,token)=>{if(pageContext.signal.aborted)return;row.token=token;row.state='ready';row.status.textContent=format('Hochgeladen · {name}',{name:row.name});row.status.className='upload-ready';row.hidden=[...form.querySelectorAll('input[data-live-upload-token]')].find(node=>node.name===('live_uploads['+field+'][]')&&node.value===token)??document.createElement('input');row.hidden.type='hidden';row.hidden.name=`live_uploads[${field}][]`;row.hidden.value=token;appendField(row.hidden);input.required=false;row.retry.hidden=true;if(action==='document-upload-many'){row.title.name=`document_titles[${token}]`;row.title.value=restored.titles?.[token]??row.title.value;row.date.name=`document_dates[${token}]`;row.date.value=restored.dates?.[token]??row.date.value;groupManager?.bind(row,token);}if(form.dataset.inventoryInvoice){row.key=restored.files?.[token]?.key||row.key;row.li._inventoryOcr={row,form,action,field};document.dispatchEvent(new CustomEvent('inventory-invoice-upload-ready',{detail:row.li._inventoryOcr}));}};
   const send=async row=>{row.state='uploading';row.progress.hidden=false;row.retry.hidden=true;row.status.textContent=text('Wird übertragen …');
    const data=new FormData();data.set('csrf',csrf);data.set('action','live-upload-stage');data.set('upload_action',action);data.set('upload_field',field);data.set('upload_key',row.key);data.set('live_file',row.file,row.name);for(const name of ['chat','sheet']){const value=form.querySelector(`[name="${name}"]`)?.value;if(value)data.set(name,value);}
    try{const result=await request(endpoint,data,p=>{row.progress.value=p;row.status.textContent=p<100?format('{percent} % übertragen',{percent:p}):text('Datei wird geprüft …');});ready(row,result.token);}catch(e){row.state='failed';row.status.className='upload-error';row.status.textContent=e.message;row.retry.hidden=false;}
   };
   const rowFor=(name,file=null)=>{const li=document.createElement('li'),status=document.createElement('span'),progress=document.createElement('progress'),retry=document.createElement('button'),discard=document.createElement('button');progress.max=100;progress.value=0;retry.type=discard.type='button';retry.textContent=text('Erneut versuchen');discard.textContent=text('Entfernen');retry.hidden=true;li.append(status,progress,retry,discard);list.append(li);const row={name,file,field,li,status,progress,retry,key:key(),required:requiredAtStart,state:'pending'};if(action==='document-upload-many'){const titleLabel=document.createElement('label');titleLabel.textContent=format('Titel für {name}',{name});row.title=document.createElement('input');row.title.type='text';row.title.maxLength=300;row.title.required=true;row.title.value=name.replace(/\.[^.]+$/,'')||name;titleLabel.append(row.title);li.insertBefore(titleLabel,progress);const dateLabel=document.createElement('label');dateLabel.textContent=format('Dokumentdatum für {name}',{name});row.date=document.createElement('input');row.date.type='date';row.date.required=true;row.date.value=form.querySelector('[name="document_date"]')?.value||new Date().toLocaleDateString('sv-SE');dateLabel.append(row.date);li.insertBefore(dateLabel,progress);}groupManager?.register(row);all.push(row);retry.addEventListener('click',async()=>{if(busy)return;busy=true;await send(row);complete();});discard.addEventListener('click',()=>remove(row));return row;};
   if(restored.action===action)for(const token of restored.tokens?.[field]||[]){const saved=restored.files?.[token];if(saved){const row=rowFor(saved.name);ready(row,token);row.progress.hidden=true;}}
   const upload=async files=>{
    if(busy){hint.textContent=text('Bitte den laufenden Upload abwarten.');return;}if(!files.length)return;if((!input.multiple&&files.length>1)||(input.multiple&&files.length+all.filter(r=>r.field===field).length>10)){hint.textContent=input.multiple?text('Höchstens zehn Dateien auswählen.'):text('Bitte genau eine Datei auswählen.');return;}
    if(!input.multiple){for(const row of [...all].filter(r=>r.field===field))await remove(row);if(all.some(r=>r.field===field))return;}
    busy=true;for(const file of files){if(pageContext.signal.aborted)return;const row=rowFor(file.name,file);await send(row);}input.value='';complete();
   };
   input.addEventListener('change',()=>upload(Array.from(input.files||[])));
   area.addEventListener('dragover',e=>{e.preventDefault();area.classList.add('dragging');});area.addEventListener('dragleave',()=>area.classList.remove('dragging'));area.addEventListener('drop',e=>{e.preventDefault();area.classList.remove('dragging');if(busy)return;const transfer=new DataTransfer();for(const file of Array.from(e.dataTransfer?.files||[]))transfer.items.add(file);input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));});
   form.querySelector(`[data-remove-asset="${field}"]`)?.addEventListener('click',async event=>{if(busy){event.preventDefault();event.stopImmediatePropagation();return;}for(const row of [...all].filter(r=>r.field===field))await remove(row);},true);
  }
 }
 for(const type of ['dragover','drop'])pageContext.on(document, type,event=>{if(Array.from(event.dataTransfer?.types||[]).includes('Files'))event.preventDefault();});
})();
});
