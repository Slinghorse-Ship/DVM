/* Apply the trusted current main's preference after either router's normal navigation. */
(()=>{'use strict';
 const views=new Set(['studio','focus','compact']),colors=new Set(['system','light','dark']),densities=new Set(['comfortable','compact']);
 const apply=()=>{const source=document.querySelector('main[data-admin-view]');if(!source)return;const {adminView,adminColor,adminDensity}=source.dataset;if(!views.has(adminView)||!colors.has(adminColor)||!densities.has(adminDensity))return;Object.assign(document.body.dataset,{adminView,adminColor,adminDensity});};
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',apply,{once:true});else apply();
 document.addEventListener('manager:mounted',apply);
 const focusError=()=>document.querySelector('[data-admin-appearance] .appearance-result[role=alert]')?.focus({preventScroll:false});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',focusError,{once:true});else focusError();
 const watch=()=>new MutationObserver(records=>{if(records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1&&node.matches('main[data-admin-view]'))))apply();}).observe(document.body,{childList:true});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',watch,{once:true});else watch();
})();

/* One compact area picker at every screen size, using the existing permitted links. */
(()=>{'use strict';
 const selector='main nav.section-main-nav,main nav.finance-main-nav,main nav.workspace-tabs,main nav.people-nav,main nav.inventory-navigation,main nav.module-tabs,main nav.editorial-hub-nav,main nav.dwf-tabs';
 function enhance(){
  document.querySelectorAll(selector).forEach(nav=>{
   if(nav.closest('.module-navigation')||nav.closest('#navigation'))return;
   const adjacent=nav.nextElementSibling,finance=adjacent?.matches('details.finance-area-menu')?adjacent:null;
   const hub=nav.parentElement?.matches('.editorial-hub-bar')?nav.parentElement:null;
   const hubChild=hub?.nextElementSibling?.matches('nav.editorial-hub-secondary')?hub.nextElementSibling:null;
   const child=finance?.querySelector(':scope > nav.finance-sub-nav')||hubChild||(adjacent?.matches('nav.section-sub-nav,nav.finance-sub-nav,nav.module-tabs')?adjacent:null);
   const isScope=child?.matches('nav.document-scope-nav');
   const current=(!isScope&&child?.querySelector('a[aria-current]'))||nav.querySelector('a[aria-current]')||nav.querySelector('a');if(!current)return;
   const existing=nav.parentElement?.matches('details.communication-area-menu,details.website-area-menu')?nav.parentElement:null;
   const picker=existing||document.createElement('details'),summary=document.createElement('summary'),text=document.createElement('span'),label=document.createElement('span'),selected=document.createElement('strong'),links=document.createElement('div');
   picker.classList.add('module-navigation');picker.open=false;label.className='module-nav-label';label.textContent=nav.getAttribute('aria-label')||(document.documentElement.lang.startsWith('en')?'Choose area':'Bereich auswählen');selected.className='module-nav-current';selected.textContent=current.textContent.trim();if(isScope){const scope=child.querySelector('a[aria-current]');if(scope)selected.textContent+=' · '+scope.textContent.trim();}text.append(label,selected);summary.append(text);links.className='module-navigation-links';if(existing)existing.querySelector(':scope > summary')?.remove();else nav.before(picker);picker.append(summary,links);links.append(nav);if(child)links.append(child);if(finance)finance.remove();
   summary.addEventListener('keydown',event=>{if(event.key==='Escape'){picker.open=false;summary.focus();}});
   picker.addEventListener('keydown',event=>{if(event.key==='Escape'){picker.open=false;summary.focus();}});
  });
 }
 let queued=false;const update=()=>{if(queued)return;queued=true;queueMicrotask(()=>{queued=false;enhance();});};
 const start=()=>{enhance();new MutationObserver(records=>{if(records.some(record=>[...record.addedNodes].some(node=>node.nodeType===1&&(node.matches('main,nav,.native-manager-embedded')||node.querySelector('nav')))))update();}).observe(document.body,{childList:true,subtree:true});};
 document.addEventListener('manager:mounted',update);
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
