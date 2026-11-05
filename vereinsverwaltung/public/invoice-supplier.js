window.KulturhusPage.register('invoice-supplier', pageContext => {
 const {fetch} = pageContext;
(()=>{'use strict';
 document.querySelectorAll('[data-invoice-supplier]').forEach(box=>{
 const t=(source,values={})=>window.KulturhusInvoiceEntryText(box,source,values);
  const select=box.querySelector('[name=supplier_contact]'),version=box.querySelector('[name=supplier_version]');
  const name=box.closest('form').querySelector('[name=counterparty]'),status=box.querySelector('[data-supplier-status]'),bankStatus=box.querySelector('[data-supplier-bank-status]');
  let proposalRead=0;pageContext.cleanup(()=>{proposalRead++;});
  const updateBankStatus=()=>{if(!bankStatus)return;const option=select.selectedOptions[0],iban=option?.dataset.iban||'',bic=option?.dataset.bic||'';bankStatus.textContent=select.value?(iban?'IBAN '+iban+(bic?' · BIC '+bic:'')+t(' prüfen.'):t('IBAN fehlt – am Original prüfen.')):t('Firma auswählen. IBAN am Original prüfen.');};
  function clearChangedSelection(message){select.value='';version.value='0';name.value='';name.readOnly=true;select.dispatchEvent(new Event('change',{bubbles:true}));status.textContent=message+t(' Ihre übrigen Eingaben bleiben erhalten.');}
  name.readOnly=true;
  if(select.value&&version.value!==select.selectedOptions[0].dataset.version)clearChangedSelection(t('Die Firma wurde inzwischen geändert. Bitte erneut auswählen.'));
  select.addEventListener('change',()=>{
   proposalRead++;
   const option=select.selectedOptions[0];version.value=option?.dataset.version||'0';name.readOnly=true;
   name.value=select.value?option.dataset.name:'';
   status.textContent=select.value?t('Firma ausgewählt.'):t('Firma auswählen.');updateBankStatus();
  });
  // Refresh submits the complete original form to the read-only server path.

  box.addEventListener('invoice-supplier-proposal',async event=>{
   const proposed=String(event.detail?.name||'').trim(),request=++proposalRead;
   if(!proposed||[...proposed].length>150){status.textContent=t('Den erkannten Firmennamen im vollständigen Bestand suchen und bewusst auswählen.');return;}
   let matches=[];
   try{
    const url=new URL(box.dataset.optionsUrl,location.href);url.searchParams.set('q',proposed);url.searchParams.set('exact','1');url.searchParams.set('page','1');
    status.textContent=t('Firma wird im vollständigen Bestand gesucht …');
    const response=await fetch(url.href,{credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();if(request!==proposalRead)return;
    if(!response.ok||!Array.isArray(result.suppliers)||!Number.isInteger(result.total))throw Error('lookup');
    matches=result.suppliers.filter(company=>company.name.trim().toLocaleLowerCase('de')===proposed.toLocaleLowerCase('de'));
    if(result.total>1){status.textContent=t('Mehrere Firmen passen zum Namen. Bitte die richtige Firma in der Suche auswählen.');return;}
    if(result.total===1){if(matches.length!==1)throw Error('lookup');const company=matches[0];let option=[...select.options].find(o=>o.value===company.id);if(!option){option=new Option(company.name,company.id);select.append(option);}option.dataset.name=company.name;option.dataset.version=String(company.version);option.dataset.iban=company.iban||'';option.dataset.bic=company.bic||'';select.value=company.id;select.dispatchEvent(new Event('change',{bubbles:true}));status.textContent=t('Passende vorhandene Firma ausgewählt. Bitte am Original prüfen.');return;}
   }catch{if(request===proposalRead)status.textContent=t('Die vollständige Firmensuche ist gerade nicht verfügbar. Die ursprüngliche Auswahl bleibt erhalten. Bitte erneut suchen.');return;}
   const create=box.querySelector('[data-supplier-create]');if(!create){status.textContent=t('Keine passende Firma gefunden. Bitte die Kontaktverwaltung um Anlage bitten.');return;}
   const previous=box._recognitionSupplier||{},sameName=String(previous.name||'').trim().toLocaleLowerCase('de')===proposed.toLocaleLowerCase('de');
   box._recognitionSupplier=sameName?{...previous,name:proposed}:{name:proposed};
   if(!sameName&&box._supplierDialog){box._supplierDialog.close();box._supplierDialog.remove();delete box._supplierDialog;}
   create.click();
  });
  if(box.querySelector('[data-supplier-create]'))box.querySelector('[data-supplier-create]').hidden=false;
  box.querySelector('[data-supplier-create]')?.addEventListener('click',()=>{
   proposalRead++;
   const opener=box.querySelector('[data-supplier-create]');
   if(box._supplierDialog){box._supplierDialog._refreshRecognition();box._supplierDialog.showModal();return;}
   const dialog=document.createElement('dialog');dialog.className='live-upload-review';dialog.setAttribute('aria-label',t('Neue Firma anlegen'));
   const form=document.createElement('form');
   const heading=document.createElement('h2');heading.textContent=t('Neue Firma anlegen');form.append(heading);
   const introduction=document.createElement('p');introduction.textContent=t('Nur ein Firmenkontakt wird angelegt. Die Rechnung bleibt unverändert geöffnet; es entsteht keine Mitgliedschaft und keine Mail.');form.append(introduction);
   for(const[key,title,type,max]of [['name','Firmenname *','text',200],['email','Geschäftliche E-Mail (optional)','email',254],['phone','Geschäftliche Telefonnummer (optional)','tel',80],['address','Geschäftsanschrift (optional)','textarea',1000],['iban','IBAN (optional)','text',42],['bic','BIC (optional)','text',11]]){
    const label=document.createElement('label'),input=document.createElement(type==='textarea'?'textarea':'input');label.append(document.createTextNode(t(title)));input.name=key;input.maxLength=max;if(type==='textarea')input.rows=3;else input.type=type;if(key==='name')input.required=true;if(['iban','bic'].includes(key))input.autocomplete='off';label.append(input);form.append(label);
   }
   const bankNotice=document.createElement('p');bankNotice.textContent=t('Bankangaben am Original prüfen. Sie werden nur bei dieser neuen Firma hinterlegt; es wird keine Zahlung ausgelöst und keine vorhandene Bankverbindung geändert.');const failure=document.createElement('p');failure.dataset.createError='';failure.setAttribute('role','status');const actions=document.createElement('div');actions.className='actions';const save=document.createElement('button');save.type='submit';save.textContent=t('Firma anlegen und auswählen');const cancelButton=document.createElement('button');cancelButton.type='button';cancelButton.dataset.createCancel='';cancelButton.className='secondary';cancelButton.textContent=t('Abbrechen');actions.append(save,cancelButton);form.append(bankNotice,failure,actions);
   dialog.append(form);document.body.append(dialog);pageContext.cleanup(()=>{dialog.close();dialog.remove();});box._supplierDialog=dialog;
   const requestKey=Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join('');
   const submit=form.querySelector('[type=submit]'),cancel=form.querySelector('[data-create-cancel]'),error=form.querySelector('[data-create-error]');let busy=false;
   dialog.addEventListener('cancel',e=>{if(busy)e.preventDefault();});
   cancel.addEventListener('click',()=>dialog.close());dialog.addEventListener('close',()=>opener.focus());
   form.addEventListener('submit',async event=>{
    event.preventDefault();if(busy)return;busy=true;submit.disabled=cancel.disabled=true;error.textContent=t('Firma wird angelegt …');
    const data=new FormData(form);data.set('csrf',box.closest('form').querySelector('[name=csrf]').value);data.set('request_key',requestKey);
    try{
     const response=await fetch(opener.dataset.createUrl,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});const result=await response.json();
     if(!response.ok||!result.supplier)throw Error(result.error||t('Firma konnte nicht angelegt werden. Bitte erneut versuchen.'));
     const company=result.supplier;let option=Array.from(select.options).find(o=>o.value===company.id);if(!option){option=new Option(company.name,company.id);select.append(option);}option.textContent=company.name;option.dataset.name=company.name;option.dataset.version=String(company.version);option.dataset.iban=company.iban||'';option.dataset.bic=company.bic||'';select.value=company.id;select.dispatchEvent(new Event('change',{bubbles:true}));
     status.textContent=t('Firma angelegt und ausgewählt. Ihre übrigen Rechnungsangaben sind erhalten. Erst beim Speichern der Rechnung wird die Firma mit der Ausgabe verknüpft.');
     dialog.close();dialog.remove();delete box._supplierDialog;
    }catch(e){error.textContent=e.message||t('Verbindung unterbrochen. Ihre Rechnungsangaben bleiben erhalten. Bitte erneut versuchen.');}
    finally{busy=false;submit.disabled=cancel.disabled=false;}
   });
   const edited=new Set();let filling=false,banks=[],bankSignature='';
   for(const type of ['input','change'])form.addEventListener(type,event=>{if(!filling&&event.target.name)edited.add(event.target.name);});
   const bankLabel=document.createElement('label'),choice=document.createElement('select'),bankHint=document.createElement('p');
   bankLabel.append(t('Bankverbindung aus der Rechnung auswählen'),choice);choice.dataset.supplierBankChoice='';choice.dataset.selectSearch='';
   form.querySelector('[name=iban]').closest('label').before(bankLabel,bankHint);
   choice.addEventListener('change',()=>{const bank=banks[Number(choice.value)];for(const key of ['iban','bic']){edited.add(key);form.querySelector('[name='+key+']').value=choice.value!==''&&bank?bank[key]:'';}form.querySelector('[name=iban]').dispatchEvent(new Event('change',{bubbles:true}));});
   // An improved extraction may arrive after the first dialog was cancelled.
   // Refresh only untouched fields; deliberate edits and clearing remain final.
   dialog._refreshRecognition=()=>{
    const proposed=box._recognitionSupplier||{};filling=true;
    try{
     for(const key of ['name','email','phone','address'])if(!edited.has(key))form.querySelector('[name='+key+']').value=typeof proposed[key]==='string'?proposed[key]:'';
     if(!select.value&&!form.querySelector('[name=name]').value&&!edited.has('name'))form.querySelector('[name=name]').value=name.value;
     const seen=new Set(),next=[];for(const candidate of(Array.isArray(proposed.bank_accounts)?proposed.bank_accounts:[]).slice(0,20)){if(!candidate||typeof candidate.iban!=='string'||seen.has(candidate.iban))continue;seen.add(candidate.iban);next.push({iban:candidate.iban,bic:typeof candidate.bic==='string'?candidate.bic:'',bank_name:typeof candidate.bank_name==='string'?candidate.bank_name:''});}
     const signature=JSON.stringify(next);if(signature!==bankSignature){bankSignature=signature;banks=next;choice.replaceChildren(new Option(t('Keine Bank ausgewählt – bitte am Original prüfen'),''));for(const [i,bank]of banks.entries())choice.append(new Option((bank.bank_name?bank.bank_name+' · ':'')+bank.iban+(bank.bic?' · '+bank.bic:''),String(i)));}
     const separatePayment=/\b(?:Klarna|PayPal)\b/i.test(String(proposed.payment_method||''));
     if(!edited.has('iban')&&!edited.has('bic')){
      // The presence of one footer account is not a payment instruction.
      const explicit=separatePayment?'':String(proposed.iban||'');
      const bank=banks.find(candidate=>candidate.iban===explicit);
      form.querySelector('[name=iban]').value=explicit;
      form.querySelector('[name=bic]').value=explicit?String(proposed.bic||bank?.bic||''):'';
     }
     const current=form.querySelector('[name=iban]').value,index=banks.findIndex(bank=>bank.iban===current);choice.value=index<0?'':String(index);
     bankLabel.hidden=!banks.length;choice.disabled=!banks.length;
     bankHint.hidden=!banks.length&&!separatePayment;
     bankHint.textContent=separatePayment?t('Zahlungsart laut Rechnung: ')+proposed.payment_method+t('. Separate Zahlungsinformationen beachten. Allgemeine Bankverbindungen sind nicht automatisch ausgewählt; dies bestätigt keine Zahlung.'):t(banks.length===1?t('{count} Bankverbindung erkannt. Prüfen Sie die passende Bank laut Zahlungsanweisung; die Anzahl allein bestimmt keine Auswahl.'):t('{count} Bankverbindungen erkannt. Prüfen Sie die passende Bank laut Zahlungsanweisung; die Anzahl allein bestimmt keine Auswahl.'),{count:banks.length});
     window.KulturhusSelectSearch?.enhance(choice);
    }finally{filling=false;}
   };
   dialog._refreshRecognition();
   dialog.showModal();form.querySelector('[name=name]').focus();
  });

  if(box._recognitionSupplier){const proposed=String(box._recognitionSupplier.name||'').trim(),matches=[...select.options].filter(o=>o.value&&o.dataset.name?.trim().toLocaleLowerCase('de')===proposed.toLocaleLowerCase('de'));if(matches.length===1&&!select.value){select.value=matches[0].value;select.dispatchEvent(new Event('change',{bubbles:true}));status.textContent=t('Passende Firma aus der Rechnung ausgewählt. Bitte am Original prüfen.');}else if(matches.length!==1&&proposed&&!select.value)status.textContent=t('Erkannter Lieferant: ')+proposed+t('. Bitte vorhandene Firma auswählen oder als Kontakt übernehmen.');}
  updateBankStatus();

 });
})();
});
