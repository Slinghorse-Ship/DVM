window.KulturhusPage.register('website', pageContext => {
function initEditorialwebsite(){
document.querySelectorAll('[data-website-title],[data-website-summary],[data-website-body]').forEach(input=>{
    const part=input.hasAttribute('data-website-title')?'title':input.hasAttribute('data-website-summary')?'summary':'body';
    input.addEventListener('input',()=>{
        const preview=document.querySelector('[data-website-preview-'+part+']');
        if(preview)preview.textContent=input.value;
    });
});

}
initEditorialwebsite();
});
