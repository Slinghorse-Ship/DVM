window.KulturhusPage.register('document-checks', context => {
 'use strict';
 const form=document.querySelector('[data-check-form]');if(!form)return;
 let changed=form.dataset.checkPending==='1',submitting=false;
 context.guard(()=>changed&&!submitting);
 const business=new Set(['title','category','document_id','contact_id','event_id','responsible','valid_until','review_on','reminder_days','notes','state','check_file']);
 context.on(form,'input',event=>{if(business.has(event.target.name))changed=true;});
 context.on(form,'change',event=>{if(business.has(event.target.name))changed=true;});
 context.on(form,'submit',event=>{submitting=true;queueMicrotask(()=>{if(event.defaultPrevented)submitting=false;});});
 context.on(form,'keydown',event=>{
  const source=event.target.dataset.checkChoiceQuery;
  if(event.key!=='Enter'||!source)return;
  const button=[...form.querySelectorAll('button[name=check_pick]')].find(node=>node.value===source+':search');
  if(button){event.preventDefault();form.requestSubmit(button);}
 });
});
