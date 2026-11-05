window.KulturhusPage.register('finance-form-preferences', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
 class PageMutationObserver extends MutationObserver { constructor(callback) { super(callback); pageContext.cleanup(() => this.disconnect()); } }
(()=>{'use strict';
 for(const marker of document.querySelectorAll('[data-finance-form-preferences]')){
  const form=marker.closest('form');if(!form||form.dataset.financePreferencesReady)continue;form.dataset.financePreferencesReady='1';
  const category=form.querySelector('[name="category"]');if(!category)continue;
  let defaults;try{defaults=JSON.parse(marker.dataset.financeFormPreferences);}catch{continue;}
  let touched=new WeakSet(),automatic=new WeakMap();const filling=new WeakSet();
  const apply=()=>{for(const field of form.querySelectorAll('select[name="position_sphere[]"],select[data-field="sphere"]')){
   if(touched.has(field)||marker.dataset.preservePost==='1'||marker.dataset.existingPositions==='1')continue;
   const prior=automatic.get(field);if(field.value&&field.value!==prior)continue;
   const next=defaults[category.value]||'';if(![...field.options].some(o=>o.value===next)||field.value===next)continue;
   const accounting=field.closest('details');if(accounting)accounting.dataset.filling='1';
   field.value=next;automatic.set(field,next);filling.add(field);field.dispatchEvent(new Event('change',{bubbles:true}));filling.delete(field);
   if(accounting){delete accounting.dataset.filling;const note=accounting.querySelector('[data-accounting-notice]');if(note)note.textContent='Vorauswahl aus den Vereinseinstellungen – bitte für diese Position prüfen.';}
  }};
  form.addEventListener('change',e=>{if(e.target.matches('select[name="position_sphere[]"],select[data-field="sphere"]')&&!filling.has(e.target)&&!e.target.closest('details')?.dataset.filling)touched.add(e.target);if(e.target===category)apply();});
  new PageMutationObserver(apply).observe(form,{childList:true,subtree:true});form.addEventListener('reset',()=>{touched=new WeakSet();automatic=new WeakMap();requestAnimationFrame(apply);});apply();
 }
})();
});
