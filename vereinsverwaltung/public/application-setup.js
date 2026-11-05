(()=>{
 if(window.dvmApplicationSetupInit){window.dvmApplicationSetupInit();return;}
 const init=()=>{for(const wizard of document.querySelectorAll('[data-application-setup-wizard]')){
  if(wizard.dataset.setupReady)continue;wizard.dataset.setupReady='1';const sections=[...wizard.querySelectorAll('section[data-setup-step]')];let step=0;
  const show=next=>{step=Math.max(0,Math.min(sections.length-1,next));sections.forEach((section,index)=>section.hidden=index!==step);wizard.querySelectorAll('[data-setup-go]').forEach(button=>{if(Number(button.dataset.setupGo)===step)button.setAttribute('aria-current','step');else button.removeAttribute('aria-current');});};
  const next=()=>{const invalid=sections[step].querySelector('input:invalid,select:invalid,textarea:invalid');if(invalid){invalid.reportValidity();invalid.focus();return;}show(step+1);sections[step].querySelector('h3')?.focus();};
  wizard.querySelectorAll('[data-setup-enhanced]').forEach(control=>control.hidden=false);
  wizard.addEventListener('click',event=>{if(event.target.closest('[data-setup-next]'))next();else if(event.target.closest('[data-setup-back]'))show(step-1);else{const button=event.target.closest('[data-setup-go]');if(button)show(Number(button.dataset.setupGo));}});
  wizard.addEventListener('invalid',event=>{const section=event.target.closest('section[data-setup-step]');if(section)show(Number(section.dataset.setupStep));},true);
  wizard.addEventListener('keydown',event=>{if(event.key==='Enter'&&event.target.tagName==='INPUT'&&step<sections.length-1){event.preventDefault();next();}});
  show(Number(wizard.dataset.setupStep)||0);
 }};
 window.dvmApplicationSetupInit=init;document.addEventListener('manager:navigated',init);init();
})();
