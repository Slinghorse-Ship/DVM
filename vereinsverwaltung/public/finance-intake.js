window.KulturhusPage.register('finance-intake', pageContext => {
'use strict';
(() => {
 const manualTexts=element=>{try{return JSON.parse(element.closest('.manual-finance-form')?.dataset.manualFinanceText||'{}');}catch{return{};}};
 const manualText=(element,source,values={})=>{const messages=manualTexts(element);return String(Object.hasOwn(messages,source)?messages[source]:source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);};
 const reveal = () => {
  let id;try{id=decodeURIComponent(location.hash.slice(1));}catch{return;}
  const target=id&&document.getElementById(id);if(!target)return;
  if(target.matches('details.intake-secondary'))target.open=true;
  for(let parent=target.parentElement;parent;parent=parent.parentElement)if(parent.matches('details.intake-secondary'))parent.open=true;
 };
 pageContext.on(window, 'hashchange',reveal);reveal();
 pageContext.on(document, 'change',event=>{
  const panel=event.target.closest('.manual-position-tax');if(!panel)return;
  const sphere=panel.querySelector('[name="position_sphere[]"]'),account=panel.querySelector('[name="position_account[]"]'),summary=panel.querySelector(':scope > summary small');
  if(summary&&sphere&&account)summary.textContent=(sphere.value?sphere.selectedOptions[0].textContent:manualText(panel,'Sphäre noch offen'))+' · '+(account.value?manualText(panel,'Konto {code}',{code:account.value}):manualText(panel,'Steuerkonto noch offen'));
 });
 const reimbursement = () => {
  document.querySelectorAll('[data-reimbursement-source]').forEach(source => {
   const panel = source.closest('fieldset')?.querySelector('[data-reimbursement-panel]');
   const member = panel?.querySelector('[data-reimbursement-contact]');
   if(!panel)return;
   const active = source.value === 'private_advance';
   panel.hidden = !active;
   if(member)member.required = active;
  });
 };
 pageContext.on(document, 'change',event=>{if(event.target.matches('[data-reimbursement-source]'))reimbursement();});
 reimbursement();
})();
});
