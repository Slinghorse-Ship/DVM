window.KulturhusPage.register('tax-preparation', pageContext => {
(()=>{'use strict';
const form=document.querySelector('[data-tax-preparation-form]');if(form){const from=form.querySelector('[name=from_year]'),to=form.querySelector('[name=to_year]'),range=form.querySelector('[data-tax-multiple-years]');from?.addEventListener('input',()=>{if(range&&!range.open)to.value=from.value;});}const save=document.querySelector('[data-tax-save]'),changed=document.querySelector('[data-tax-preview-changed]');
if(form&&save){const invalidate=e=>{if(e.target.matches('[data-tax-attachment-search],[data-annual-tax-attachment-search]'))return;save.disabled=true;if(changed)changed.hidden=false;};form.addEventListener('input',invalidate);form.addEventListener('change',invalidate);form.addEventListener('click',e=>{if(e.target.closest('[data-tax-remove-row],[data-tax-add-row],[data-tax-remove-year]'))invalidate(e);});}
const search=document.querySelector('[data-tax-attachment-search]'),list=document.querySelector('[data-tax-attachment-list]'),empty=document.querySelector('[data-tax-attachment-empty]');
if(search&&list)search.addEventListener('input',()=>{const query=search.value.trim().toLocaleLowerCase();let visible=0;for(const row of list.querySelectorAll('[data-tax-attachment-row]')){row.hidden=!row.textContent.toLocaleLowerCase().includes(query);if(!row.hidden)++visible;}if(empty)empty.hidden=visible>0;});
})();
});
