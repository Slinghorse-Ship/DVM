window.KulturhusPage.register('gallery-upload', pageContext => {
'use strict';
(() => {
 const root=document.querySelector('[data-inline-images]');if(!root)return;
 const status=root.querySelector('[data-image-status]'),progress=root.querySelector('progress'),list=root.querySelector('[data-image-list]');
 const upload=root.querySelector('form:has([value="image/upload"])'),input=upload.querySelector('[type="file"]');input.multiple=true;
 // Captions are edited on the stored image; there is no pre-submit step anymore.
 upload.querySelector('[name="fields[description]"]')?.closest('label')?.remove();
 let busy=false;const attempts=new Map();let previewUrls=[];
 pageContext.guard(()=>busy);
 const previews=document.createElement('div');previews.className='native-images';upload.before(previews);
 const drop=document.createElement('button');drop.type='button';drop.className='gallery-dropzone';drop.textContent='Bilder hier hineinziehen oder auswählen – Upload startet automatisch';upload.before(drop);
 // Keep the native input for the picker and no-JavaScript fallback, without a second visible control.
 input.closest('label').classList.add('gallery-picker-hidden');
 drop.addEventListener('click',()=>{if(!busy)input.click();});
 let dragDepth=0;
 drop.addEventListener('dragenter',event=>{event.preventDefault();dragDepth++;if(!busy)drop.classList.add('is-dragging');});
 drop.addEventListener('dragover',event=>{event.preventDefault();event.dataTransfer.dropEffect=busy?'none':'copy';});
 drop.addEventListener('dragleave',()=>{if(--dragDepth<=0){dragDepth=0;drop.classList.remove('is-dragging');}});
 drop.addEventListener('drop',event=>{
  event.preventDefault();dragDepth=0;drop.classList.remove('is-dragging');
  if(busy){status.textContent='Upload läuft. Weitere Bilder bitte anschließend hineinziehen.';return;}
  const files=Array.from(event.dataTransfer.files);if(!files.length){status.textContent='Bitte Bilddateien vom Gerät hineinziehen, keine Links oder Ordner.';return;}
  const transfer=new DataTransfer();files.forEach(file=>transfer.items.add(file));input.files=transfer.files;input.dispatchEvent(new Event('change',{bubbles:true}));
 });
 // Dropping outside the target must not replace the page while an upload is running.
 for(const type of ['dragover','drop'])pageContext.on(document, type,event=>{if(Array.from(event.dataTransfer?.types||[]).includes('Files'))event.preventDefault();});
 const uploadButton=upload.querySelector('button[type="submit"],button:not([type])');uploadButton.textContent='Upload erneut versuchen';uploadButton.hidden=true;
 const key=()=>Array.from(crypto.getRandomValues(new Uint8Array(16)),v=>v.toString(16).padStart(2,'0')).join('');
 const request=data=>new Promise((resolve,reject)=>{
  if(pageContext.signal.aborted){reject(new DOMException('Page closed','AbortError'));return;}
  const xhr=new XMLHttpRequest();pageContext.cleanup(()=>xhr.abort());xhr.onabort=()=>reject(new DOMException('Page closed','AbortError'));xhr.open('POST',upload.getAttribute('action')||location.href);xhr.timeout=120000;
  xhr.upload.onprogress=e=>{if(e.lengthComputable){progress.value=Math.round(e.loaded/e.total*100);if(e.loaded===e.total)status.textContent+=' · Übertragen, wird verarbeitet …';}};
  xhr.onload=()=>{let result;try{result=JSON.parse(xhr.responseText);}catch{reject(Error('Keine bestätigte Antwort. Anmeldung und aktuellen Bildbestand prüfen, dann erneut versuchen.'));return;}if(xhr.status>=400||!result.ok)reject(Error(result.error||'Upload nicht bestätigt.'));else resolve(result);};
  xhr.onerror=xhr.ontimeout=()=>reject(Error('Verbindung unterbrochen. Derselbe Upload kann erneut versucht werden; nicht nochmals neu auswählen.'));
  xhr.send(data);
 });
 const apply=(result)=>{
  if(pageContext.signal.aborted)return;
  // Keep text drafts intact; only advance the shared page version and image cards.
  document.querySelectorAll('form.native-form [name="version"]').forEach(el=>el.value=result.version);
  const names=new Set(result.images.map(im=>im.filename));
  for(const article of list.children)if(!names.has(article.dataset.imageName))article.remove();
  for(const im of result.images){
   let article=Array.from(list.children).find(el=>el.dataset.imageName===im.filename);
   if(!article){article=root.querySelector('template').content.firstElementChild.cloneNode(true);article.dataset.imageName=im.filename;
    article.querySelectorAll('[name="fields[filename]"]').forEach(el=>el.value=im.filename);
    article.querySelectorAll('[name="fields[field]"]').forEach(el=>el.value=im.field);
    article.querySelector('[name="fields[description]"]').value=im.description;
    article.querySelector('[name="fields[tags]"]').value=im.tags;
   }
   article.querySelector('img').src=im.url;article.querySelector('img').alt=im.description;
   article.querySelectorAll('[name="version"]').forEach(el=>el.value=result.version);
   article.querySelectorAll('[name="request_key"]').forEach(el=>el.value=key());
   list.append(article);
  }
 };
 const clearPreviews=()=>{previewUrls.forEach(url=>URL.revokeObjectURL(url));previewUrls=[];previews.replaceChildren();};
 pageContext.cleanup(clearPreviews);
 input.addEventListener('change',()=>{attempts.clear();clearPreviews();status.textContent=`${input.files.length} Bild(er) ausgewählt.`;
  for(const file of Array.from(input.files).slice(0,50)){if(!['image/jpeg','image/png','image/webp'].includes(file.type)||file.size>8000000)continue;const card=document.createElement('article'),image=document.createElement('img'),caption=document.createElement('p');const url=URL.createObjectURL(file);previewUrls.push(url);image.src=url;image.alt=file.name;caption.textContent=file.name+' · noch nicht hochgeladen';card.append(image,caption);previews.append(card);}
  if(input.files.length)upload.requestSubmit();
 });
 root.addEventListener('input',event=>{if(!busy&&event.target.form&&event.target.form!==upload)attempts.delete(event.target.form);});
 root.addEventListener('submit',async event=>{
  const form=event.target;if(!(form instanceof HTMLFormElement))return;event.preventDefault();if(busy)return;
  const operation=form.elements.operation.value;if(operation==='image/remove'&&!await window.KulturhusDialog.confirm('Dieses Bild entfernen?',{accept:'Bild entfernen',danger:true}))return;if(busy||!form.isConnected)return;
  const files=operation==='image/upload'?Array.from(input.files):[null];
  if(files.length>50){status.textContent='Bitte höchstens 50 Bilder pro Auswahl hochladen.';return;}
  if(files.some(file=>file&&(file.size>8000000||!['image/jpeg','image/png','image/webp'].includes(file.type)))){status.textContent='Bitte nur JPG, PNG oder WebP mit jeweils höchstens 8 MB auswählen.';return;}
  busy=true;uploadButton.hidden=true;root.setAttribute('aria-busy','true');root.querySelectorAll('button').forEach(b=>b.disabled=true);input.disabled=true;progress.hidden=false;
  let completed=false;
  try{
   for(let i=0;i<files.length;i++){
    if(pageContext.signal.aborted)return;
    const file=files[i];let attempt=attempts.get(file||form);
    if(attempt?.done)continue;
    if(!attempt){const data=new FormData(form);data.set('response_format','json');data.set('request_key',key());if(file)data.set('image',file,file.name);attempt={data,done:false};attempts.set(file||form,attempt);}
    status.textContent=file?`Bild ${i+1} von ${files.length}: ${file.name}`:'Bildänderung wird gespeichert …';progress.value=0;
    const result=await request(attempt.data);apply(result);attempt.done=true;status.textContent=result.message;
   }
   if(operation==='image/upload'){input.value='';attempts.clear();clearPreviews();status.textContent=`${files.length} Bild(er) gespeichert. Beschreibungen und Reihenfolge können direkt bei den Bildern bearbeitet werden.`;}
   else{attempts.delete(form);const direction=form.querySelector('[name="fields[direction]"]');if(direction)direction.value='';}
   completed=true;
  }catch(error){status.textContent=`Gestoppt: ${error.message} Bereits gespeicherte Bilder bleiben erhalten. Bei einer zwischenzeitlichen Änderung bitte den Bildbestand neu laden.`;if(operation==='image/upload')uploadButton.hidden=false;}
  finally{busy=false;root.removeAttribute('aria-busy');root.querySelectorAll('button').forEach(b=>b.disabled=false);input.disabled=false;progress.hidden=true;if(completed&&!pageContext.signal.aborted&&form.isConnected)window.KulturhusNavigation?.markClean(form);}
 });
 pageContext.on(window, 'beforeunload',event=>{if(busy){event.preventDefault();event.returnValue='';}});
})();
});
