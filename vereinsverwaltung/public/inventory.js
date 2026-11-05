window.KulturhusPage.register('inventory', pageContext => {
(() => {
  document.querySelectorAll('[data-iv-print]').forEach(button => button.addEventListener('click', () => window.print()));
  const searchable=root=>window.KulturhusSelectSearch?.enhance(root);
  searchable(document);
  const quantityForm=document.querySelector('[data-inventory-quantity-create]');
  if(quantityForm){
    const quantity=quantityForm.querySelector('[name="data[initial_quantity]"]'),location=quantityForm.querySelector('[name="data[home_location]"]');
    const updateLocationRequired=()=>{location.required=Number(quantity.value)>0;};
    quantity.addEventListener('input',updateLocationRequired);updateLocationRequired();
  }
  pageContext.on(document, 'inventory-options-added', event => searchable(event.target));
  // Loan draft positions use the same server steps with and without JavaScript.
})();
});
