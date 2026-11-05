window.KulturhusPage.register('account-create', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
(()=>{
  'use strict';
  const form=document.querySelector('[data-account-create]');
  if(!form)return;
  const submit=form.querySelector('#account-create-submit');
  const labels=[...form.querySelectorAll('[data-account-kind-option]')];
  const help=[...document.querySelectorAll('[data-account-help]')];
  const update=()=>{
    const selected=form.querySelector('input[name="kind"]:checked')?.value||'';
    labels.forEach(label=>label.classList.toggle('is-selected',label.dataset.accountKindOption===selected));
    const known=['cash','offline-manual','offline-online'].includes(selected);
    help.forEach(panel=>{panel.hidden=known&&panel.dataset.accountHelp!==selected;});
    if(submit)submit.textContent=selected==='offline-online'?form.dataset.accountOnlineLabel:form.dataset.accountSaveLabel;
  };
  form.addEventListener('change',event=>{if(event.target.matches('input[name="kind"]'))update();});
  labels.forEach(label=>label.addEventListener('click',()=>requestAnimationFrame(update)));
  update();
})();
});
