'use strict';
window.KulturhusPage.register('inventory-where-filter', context => {
 const text=source=>window.KulturhusInterface?.text(source)??source;
 const form=context.root.querySelector('[data-inventory-live-filter]'),status=context.root.querySelector('[data-inventory-filter-status]');
 if(!form||!status)return;
 const tab=form.elements.namedItem('tab')?.value;
 if(!['where','items'].includes(tab))return;
 const fields=tab==='items'?['q','category','status','group']:['q','member','location','state'];
 let timer,composing=false;
 const cancel=()=>clearTimeout(timer);
 const refresh=async()=>{
  if(context.signal.aborted||composing)return;
  if(window.KulturhusNavigation.isDirty()){status.textContent=text('Bitte ungespeicherte Änderungen zuerst speichern.');return;}
  const url=new URL(form.action,location.href);url.search=new URLSearchParams(new FormData(form)).toString();url.hash='';
  for(const key of [...url.searchParams.keys()])if(key==='page'||key.endsWith('_page'))url.searchParams.delete(key);
  const active=form.contains(document.activeElement)?document.activeElement:null;
  const focus=active?.name?{selector:'[data-inventory-live-filter] [name="'+CSS.escape(active.name)+'"]',start:active.selectionStart,end:active.selectionEnd,x:scrollX,y:scrollY}:null;
  status.textContent=text('Bestände werden gefiltert …');
  const changed=await window.KulturhusNavigation.navigate(url.href,{replace:true,focus});
  if(!changed&&!context.signal.aborted)status.textContent=text('Die Auswahl konnte nicht aktualisiert werden. Bitte erneut filtern.');
 };
 const schedule=delay=>{cancel();timer=context.timeout(refresh,delay);};
 context.on(form,'input',event=>{if(!composing&&fields.includes(event.target.name)&&event.target.tagName!=='SELECT')schedule(250);});
 context.on(form,'change',event=>{if(fields.includes(event.target.name)&&event.target.tagName==='SELECT')schedule(0);});
 context.on(form,'compositionstart',()=>{composing=true;cancel();});
 context.on(form,'compositionend',()=>{composing=false;schedule(250);});
 context.on(form,'submit',event=>{event.preventDefault();cancel();void refresh();});
});
