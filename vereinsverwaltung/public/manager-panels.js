/* Read-only manager panels preserve the open form, its files and its request keys. */
(() => {
 'use strict';
 if(window.KulturhusManagerPanels)return;window.KulturhusManagerPanels=true;
 let dialog,controller,trigger;
 const text=(de,en)=>document.documentElement.lang.startsWith('en')?en:de;
 function close(){controller?.abort();dialog?.close();}
 async function help(url,body){
  controller?.abort();controller=new AbortController();body.replaceChildren();const status=document.createElement('p');status.setAttribute('role','status');status.textContent=text('Hilfe wird geladen …','Loading help …');body.append(status);
  try{const r=await fetch(url,{credentials:'same-origin',signal:controller.signal,redirect:'error'});if(!r.ok)throw new Error();const page=new DOMParser().parseFromString(await r.text(),'text/html'),content=page.querySelector('[data-task-help]');if(!content)throw new Error();content.querySelectorAll('script,iframe,form,object,embed').forEach(n=>n.remove());body.replaceChildren(document.importNode(content,true));}
  catch(error){if(error.name==='AbortError')return;status.setAttribute('role','alert');status.textContent=text('Hilfe konnte nicht geladen werden. Bitte erneut versuchen.','Help could not be loaded. Please try again.');const retry=document.createElement('button');retry.type='button';retry.textContent=text('Erneut versuchen','Try again');retry.addEventListener('click',()=>help(url,body));body.append(retry);}
 }
 document.addEventListener('click',event=>{
  if(event.defaultPrevented||event.button!==0||event.ctrlKey||event.metaKey||event.shiftKey||event.altKey)return;
  const a=event.target.closest('a[href]');if(!a)return;const url=new URL(a.href,location.href);
  if(dialog?.open&&dialog.contains(a)){
   if(url.origin===location.origin&&url.pathname===location.pathname&&url.searchParams.get('view')==='help'){event.preventDefault();event.stopPropagation();help(url,dialog.querySelector('[data-manager-panel-body]'));return;}
   close();return;
  }
  const isHelp=a.hasAttribute('data-task-help-entry')&&url.searchParams.get('view')==='help';
  const isPreview=a.hasAttribute('data-manager-preview');if((!isHelp&&!isPreview)||url.origin!==location.origin)return;
  event.preventDefault();event.stopPropagation();if(dialog?.open)return;trigger=a;
  dialog=document.createElement('dialog');dialog.className='manager-panel';dialog.dataset.managerPanel=isHelp?'help':'preview';dialog.setAttribute('aria-labelledby','manager-panel-title');
  const heading=document.createElement('h2');heading.id='manager-panel-title';heading.textContent=isHelp?text('Hilfe','Help'):text('Vorschau','Preview');
  const button=document.createElement('button');button.type='button';button.className='btn btn-outline theme-secondary';button.textContent=text('Zurück zur Bearbeitung','Back to editing');button.addEventListener('click',close);
  const header=document.createElement('header');header.append(heading,button);const body=document.createElement('div');body.dataset.managerPanelBody='';dialog.append(header,body);
  dialog.addEventListener('close',()=>{controller?.abort();dialog.remove();dialog=null;trigger?.focus({preventScroll:true});},{once:true});document.body.append(dialog);dialog.showModal();
  if(isHelp)help(url,body);else{const frame=document.createElement('iframe');frame.title=text('Gespeicherte Vorschau','Saved preview');frame.sandbox='allow-same-origin';body.append(frame);controller=new AbortController();fetch(url,{credentials:'same-origin',signal:controller.signal,redirect:'error'}).then(async r=>{if(!r.ok||!(r.headers.get('content-type')||'').includes('text/html'))throw new Error();const page=new DOMParser().parseFromString(await r.text(),'text/html');page.querySelectorAll('script,form,iframe,object,embed,base').forEach(n=>n.remove());if(frame.isConnected)frame.srcdoc='<!doctype html>'+page.documentElement.outerHTML;}).catch(e=>{if(e.name!=='AbortError'&&body.isConnected)body.textContent=text('Vorschau konnte nicht geladen werden.','Preview could not be loaded.');});}
 },true);
})();
