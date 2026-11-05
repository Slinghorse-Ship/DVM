window.KulturhusPage.register('tax-guided', pageContext => {
(()=>{'use strict';
 const init=()=>{
  const root=document.querySelector('[data-tax-guided]');if(!root||root.dataset.taxReady)return;root.dataset.taxReady='1';
  const openTarget=id=>{const target=document.getElementById(id);if(!target||!root.contains(target))return;let step=null;for(let p=target;p&&p!==root;p=p.parentElement){if(p.tagName==='DETAILS')p.open=true;if(p.hasAttribute('data-tax-step'))step=p;}
   if(step){root.querySelectorAll('[data-tax-step]').forEach(p=>{if(p!==step)p.open=false;});const marker=root.querySelector('[name="tax_step"]');if(marker)marker.value=step.id;}
   target.scrollIntoView({block:'start',behavior:'auto'});const focus=target.matches('details')?target.querySelector('summary'):target.querySelector('input:not([type=hidden]),select,textarea,summary');focus?.focus({preventScroll:true});
  };
  root.addEventListener('click',event=>{
   const link=event.target.closest('a[href^="#tax-"]');if(link){event.preventDefault();const id=link.getAttribute('href').slice(1);openTarget(id);window.KulturhusNavigation.updateUrl('#'+id);}
   const apply=event.target.closest('[data-tax-use-setting]');if(apply){const field=apply.closest('.tax-question').querySelector('select');if(!field.disabled){field.value=apply.dataset.taxUseSetting;field.dispatchEvent(new Event('change',{bubbles:true}));apply.hidden=true;field.focus();}}
  });
  root.querySelectorAll('[data-tax-use-setting]').forEach(button=>button.hidden=false);
  root.addEventListener('invalid',event=>{for(let p=event.target.parentElement;p&&p!==root;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;},true);
  root.querySelectorAll('[data-tax-step]').forEach(step=>step.addEventListener('toggle',()=>{if(step.open){const marker=root.querySelector('[name="tax_step"]');if(marker)marker.value=step.id;}}));
  const updateGuide=box=>{const select=box.querySelector('select'),body=box.querySelector('[data-tax-guide-note]');if(!select||!body)return;body.hidden=select.value==='unknown'||(select.value==='no'&&box.dataset.taxGuideWhen!=='always'&&!body.querySelector('textarea').value.trim());};
  root.querySelectorAll('[data-tax-guide-when]').forEach(box=>{updateGuide(box);box.querySelector('select')?.addEventListener('change',()=>updateGuide(box));});
  let dirty=false,submitting=false;const markDirty=e=>{if(!e.target.closest('form[method="post"]')||e.target.matches('[type=search]'))return;dirty=true;root.querySelectorAll('form').forEach(form=>{if(['tax-auto-create','tax-auto-package'].includes(form.querySelector('[name=action]')?.value))form.querySelectorAll('button').forEach(button=>button.disabled=true);});root.querySelectorAll('[data-tax-unsaved]').forEach(n=>n.hidden=false);};root.addEventListener('input',markDirty);root.addEventListener('change',markDirty);root.addEventListener('click',e=>{if(e.target.closest('[data-tax-add-row],[data-tax-remove-row],[data-tax-remove-year]'))markDirty(e);});
  root.addEventListener('submit',e=>{if((e.target.getAttribute('method')||'get').toLowerCase()==='post')submitting=true;});
  pageContext.guard(()=>dirty&&!submitting);
  pageContext.on(window, 'beforeunload',e=>{if(dirty&&!submitting){e.preventDefault();e.returnValue='';}});
  if(location.hash.startsWith('#tax-'))openTarget(location.hash.slice(1));
 };
 init();
})();
});
