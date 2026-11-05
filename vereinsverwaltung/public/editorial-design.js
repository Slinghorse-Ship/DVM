window.KulturhusPage.register('editorial-design', pageContext => {
function initEditorialDesign(){
 const form=document.querySelector('[data-editorial-preferences]');if(!form||form.dataset.bound)return;form.dataset.bound='1';
 const preview=document.querySelector('[data-editorial-preview]');
 const sync=()=>{if(!preview)return;preview.className='editorial-preview theme-'+form.elements['module_preferences[theme]'].value+' format-'+form.elements['module_preferences[format]'].value;preview.querySelector('[data-editorial-headline]').textContent=form.elements['module_preferences[headline]'].value;preview.querySelector('[data-editorial-text]').textContent=form.elements['module_preferences[text]'].value;};
 form.addEventListener('input',sync);form.addEventListener('change',sync);
 document.querySelectorAll('[data-editorial-theme]').forEach(button=>button.addEventListener('click',()=>{form.elements['module_preferences[theme]'].value=button.dataset.editorialTheme;form.elements['module_preferences[theme]'].dispatchEvent(new Event('change',{bubbles:true}));form.scrollIntoView({behavior:'smooth',block:'center'});}));
 sync();
}
initEditorialDesign();
});
