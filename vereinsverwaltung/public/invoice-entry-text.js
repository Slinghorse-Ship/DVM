(()=>{'use strict';
 const maps=new WeakMap();
 window.KulturhusInvoiceEntryText=(host,source,values={})=>{
  const element=host?.closest?.('form[data-invoice-entry-messages]')||host;
  let messages={};
  if(element){if(!maps.has(element)){let parsed={};try{parsed=JSON.parse(element.dataset.invoiceEntryMessages||'{}');}catch{}maps.set(element,parsed);}messages=maps.get(element);}
  let text=messages[source]??source;
  for(const[key,value]of Object.entries(values))text=text.split('{'+key+'}').join(String(value));
  return text;
 };
})();
