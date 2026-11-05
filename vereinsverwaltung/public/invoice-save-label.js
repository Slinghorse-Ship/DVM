window.KulturhusPage.register('invoice-save-label', pageContext => {
(()=>{'use strict';
 document.querySelectorAll('[data-invoice-review-form] [data-invoice-save]').forEach(button=>{
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(button.closest('form'),source,values);
  const setLabel=()=>{button.textContent=t('Rechnung speichern');};
  setLabel();
  button.closest('form')?.addEventListener('invoice-review-update',setLabel);
 });
})();
});
