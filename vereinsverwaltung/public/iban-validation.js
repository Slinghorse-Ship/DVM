(()=>{'use strict';
 let lengths,patterns;try{lengths=JSON.parse(document.querySelector('meta[name="iban-country-lengths"]')?.content||'{}');patterns=JSON.parse(document.querySelector('meta[name="iban-country-patterns"]')?.content||'{}');}catch{return;}
 const touched=new WeakSet(),owned=new WeakSet();
 const isIban=f=>f instanceof HTMLInputElement&&f.form&&!f.disabled&&!f.readOnly&&f.type!=='hidden'&&!f.hasAttribute('data-iban-preserve-input')&&(f.hasAttribute('data-iban')||/(?:^|\[|_)iban\]?$/.test(f.name));
 function inspect(raw){
  const value=raw.replace(/[\s\p{Z}]+/gu,'');if(!value)return {value:'',error:''};
  if(!/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9]+$/.test(value))return {value,error:'IBAN darf nur lateinische Buchstaben, Ziffern und Leerzeichen enthalten.'};
  const normalized=value.toUpperCase(),country=normalized.slice(0,2),length=lengths[country];
  if(!Number.isInteger(length))return {value:normalized,error:'IBAN-Länderkennzeichen wird nicht erkannt. Bitte die ersten zwei Buchstaben prüfen.'};
  if(normalized.length!==length)return {value:normalized,error:'IBAN für '+country+' muss '+length+' Zeichen ohne Leerzeichen enthalten.'};
  if(patterns[country]&&!new RegExp(patterns[country]).test(normalized.slice(4)))return {value:normalized,error:'IBAN für '+country+' darf nach Länderkennung und Prüfziffer nur Ziffern enthalten.'};
  let rest=0;for(const char of normalized.slice(4)+normalized.slice(0,4)){const digits=/[A-Z]/.test(char)?String(char.charCodeAt(0)-55):char;for(const digit of digits)rest=(rest*10+Number(digit))%97;}
  return {value:normalized,error:rest===1?'':'IBAN-Prüfziffer stimmt nicht. Bitte die IBAN mit dem Original vergleichen.'};
 }
 function message(source){let values={},match=source.match(/^IBAN für ([A-Z]{2}) muss ([0-9]{2}) Zeichen ohne Leerzeichen enthalten\.$/);if(match){source='IBAN für {country} muss {length} Zeichen ohne Leerzeichen enthalten.';values={country:match[1],length:match[2]};}else if(match=source.match(/^IBAN für ([A-Z]{2}) darf nach Länderkennung und Prüfziffer nur Ziffern enthalten\.$/)){source='IBAN für {country} darf nach Länderkennung und Prüfziffer nur Ziffern enthalten.';values={country:match[1]};}let text=window.KulturhusInterface?.text(source)||source;for(const [key,value]of Object.entries(values))text=text.replaceAll('{'+key+'}',value);return text;}
 function validate(f,normalize=false){if(!isIban(f))return true;const result=inspect(f.value);f.setAttribute('autocapitalize','characters');f.spellcheck=false;
  if(normalize&&!result.error)f.value=result.value;
  if(result.error){const error=message(result.error);f.setCustomValidity(error);owned.add(f);window.KulturhusFormValidation?.show(f,error,{focus:false});}
  else if(owned.has(f)){f.setCustomValidity('');owned.delete(f);window.KulturhusFormValidation?.clear(f);}
  return !result.error;
 }
 for(const event of ['input','change'])document.addEventListener(event,e=>{if(isIban(e.target)){touched.add(e.target);validate(e.target,event==='change');}},true);
 document.addEventListener('focusout',e=>{if(isIban(e.target)&&touched.has(e.target))validate(e.target,true);},true);
 document.addEventListener('click',e=>{const button=e.target.closest?.('button,input[type=submit]');if(!button?.form||button.type!=='submit')return;for(const f of button.form.elements)if(isIban(f)&&(touched.has(f)||f.value!==f.defaultValue))validate(f,true);},true);
 document.addEventListener('reset',e=>{setTimeout(()=>{for(const f of e.target.elements){if(owned.has(f)){f.setCustomValidity('');owned.delete(f);window.KulturhusFormValidation?.clear(f);}touched.delete(f);}},0);});
 window.KulturhusIban={inspect,validate};
})();
