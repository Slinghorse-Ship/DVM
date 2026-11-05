window.KulturhusPage.register('invoice-document-year', pageContext => {
 const {frame: requestAnimationFrame} = pageContext;
(()=>{'use strict';
 for(const note of document.querySelectorAll('[data-invoice-document-year]')){
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(note,source,values);
  const date=note.closest('form')?.querySelector('input[name="document_date"]');if(!date)continue;
  let catalogs;try{catalogs=JSON.parse(note.dataset.taxYearOptions||'{}');}catch{catalogs={};}let previousYear=Number(date.form.querySelector('[data-invoice-recognition]')?.dataset.accountingYear||0);
  const update=()=>{const year=date.validity.valid?Number(/^(\d{4})-\d{2}-\d{2}$/.exec(date.value)?.[1]||0):0,exact=Object.hasOwn(catalogs,String(year)),fallback=year>0&&!exact,options=exact?catalogs[String(year)]:fallback?(catalogs.fallback||{}):{sphere:JSON.parse(note.dataset.taxSpheres||'{}'),account:{},booking_key:{},account_help:''};
   let text=year?t('Rechnungsjahr ')+year+' · ':t('Bitte das Rechnungsdatum angeben. ');
   text+=year?(fallback?t('Für die Zuordnung wird der aktuelle geprüfte SKR42-Katalog ')+(options.catalog_year||'')+t(' angeboten. Das Rechnungsjahr bleibt erhalten; ein Export für dieses historische Jahr wird separat geprüft.'):t('Steuerkonten werden aus dem geprüften SKR42-Katalog ')+year+t(' angeboten.')):t('Das Jahr wird automatisch übernommen.');
   if(exact&&!Object.keys(options.booking_key||{}).length)text+=t(' Für dieses Jahr sind keine geprüften BU-Schlüssel hinterlegt. Eine erforderliche Steuerbehandlung vor dem Export klären.');
   note.textContent=text;
   if(previousYear!==year){previousYear=year;const box=date.form.querySelector('[data-invoice-recognition]');if(box)box.dataset.accountingOptions=JSON.stringify(options);date.form.dispatchEvent(new CustomEvent('invoice-tax-year-change',{detail:{year,supported:exact,fallback,options}}));}
  };
  date.addEventListener('input',update);date.addEventListener('change',update);date.form.addEventListener('reset',()=>requestAnimationFrame(update));update();
 }
})();
});
