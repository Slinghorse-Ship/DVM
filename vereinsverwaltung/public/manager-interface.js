/* Fixed server-owned labels, used only at explicit interface call sites. No DOM/content rewriting. */
(() => {
 'use strict';
 if (window.KulturhusInterface) return;
 let original, messages = Object.create(null);
 function text(source) {
  const current = document.querySelector('meta[name="manager-interface"]')?.content || '';
  if (current !== original) {
   original = current; messages = Object.create(null);
   try {
    const pack = JSON.parse(current);
    if (pack && typeof pack === 'object' && !Array.isArray(pack)) {
     for (const [key,value] of Object.entries(pack)) if (typeof value === 'string' && [...value].length <= 500) messages[key] = value;
    }
   } catch { /* A missing/invalid pack retains the explicit source label. */ }
  }
  return Object.hasOwn(messages,source) ? messages[source] : source;
 }
 function format(source,values={}) {
  return text(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)&&['string','number'].includes(typeof values[key])?String(values[key]):token);
 }
 window.KulturhusInterface = Object.freeze({text,format});
})();
