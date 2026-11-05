window.KulturhusPage.register('financial-investment-profile', pageContext => {
(() => {
 const init=() => document.querySelectorAll('[data-financial-profile]').forEach(panel => {
  if(panel.dataset.financialBound) return;
  const account=panel.closest('form')?.querySelector('[name="account"]');
  if(!account) return;
  const profiles=JSON.parse(panel.dataset.financialOptions), defaults=JSON.parse(panel.dataset.financialDefaults), labels=JSON.parse(panel.dataset.financialLabels);
  const update=() => {
   const selected=panel.querySelector('[name="financial_profile"]:checked')?.value || '', choices=['',...(profiles[account.value] || defaults)], field=panel.querySelector('fieldset');
   const pension=choices.includes('pension_reinsurance_unoffset');
   const generalNote=panel.querySelector('[data-financial-general-note]'), pensionNote=panel.querySelector('[data-financial-pension-note]');
   if(generalNote) generalNote.hidden=pension;
   if(pensionNote) pensionNote.hidden=!pension;
   const legend=field.querySelector('legend');
   if(legend) legend.textContent=pension?'Welche ursprüngliche Vertragszuordnung ist belegt?':'Welche Art und welcher Sitz sind belegt?';
   field.querySelectorAll('label').forEach(label => label.remove());
   for(const value of choices){
    const label=document.createElement('label'), input=document.createElement('input');
    label.className='check';input.type='radio';input.name='financial_profile';input.value=value;
    input.checked=value===(choices.includes(selected)?selected:'');
    label.append(input,document.createTextNode(labels[value]));field.append(label);
   }
  };
  panel.dataset.financialBound='1';account.addEventListener('input',update);account.addEventListener('change',update);update();
 });
 init();
})();
});
