/* Same-origin manager navigation. Fetched HTML never supplies executable code. */
(() => {
 'use strict';
 const uiText = source => window.KulturhusInterface?.text(source) ?? source;
 if (window.KulturhusNavigation) return;
 const configNode = document.querySelector('meta[name="manager-navigation"]');
 if (!configNode || !window.KulturhusPage) return;
 const config = JSON.parse(configNode.content);
 const base = new URL('.', location.href), path = location.pathname;
 const views = new Set(config.views);
 const assets = new Map(Object.entries(config.assets).map(([name, asset]) => [new URL(name, base).pathname, asset]));
 const scripts = new Map(), styles = new Map(), styleLoads = new Map();
 const session = crypto.randomUUID();
 let current = location.href, position = 0, generation = 0, revision = 0;
 let pending, traversal, committing = false, nativeSubmit = false;
 let baselines = new WeakMap(), editStates = new WeakMap();
 const dirtyForms = new Set();
 const approvedPageSubmits = new WeakSet();
 const state = (index, url, scroll = [0,0]) => ({...history.state, khManager:{session,index,url,scroll}});
 history.replaceState(state(position, current, [scrollX,scrollY]), '', current);
 history.scrollRestoration = 'manual';
 // Server-refreshed hidden cursors carry no human edit; visible fields always remain protected.
 const signature = form => JSON.stringify([...new FormData(form)]
  .filter(([name]) => !['csrf','version','request_key'].includes(name) && ![...form.querySelectorAll('input[type=hidden][data-navigation-metadata]')].some(input => input.name === name))
  .map(([name,value]) => [name, typeof value === 'string' ? value : [value.name,value.size,value.type,value.name === '' && value.size === 0 ? 0 : value.lastModified]]));
 const editingSignature = form => signature(form) + JSON.stringify([...form.querySelectorAll('[contenteditable]')].map(node => node.innerHTML));
 function baseline() {
  baselines = new WeakMap(); editStates = new WeakMap(); dirtyForms.clear();
  document.querySelectorAll('main form').forEach(form => {editStates.set(form,editingSignature(form));if (form.method === 'post') {baselines.set(form, signature(form));if(form.hasAttribute('data-unsaved-changes'))dirtyForms.add(form);}});
 }
 const postForms = () => [...document.querySelectorAll('main form')].filter(form => form.method === 'post');
 const pageSignature = () => JSON.stringify(postForms().map(signature));
 const dirty = () => [...dirtyForms].some(form => form.isConnected)
  || postForms().some(form => baselines.has(form) && baselines.get(form) !== signature(form))
  || window.KulturhusPage.guarded();
 function eligible(url) {
  if (url.origin !== location.origin || url.pathname !== path || url.username || url.password) return false;
  const view = url.searchParams.get('view');
  if (view && (!views.has(view) || view === 'handbook')) return false;
  // Downloads, callbacks and API/standalone responses retain their native semantics.
  if (url.searchParams.has('state') && (url.searchParams.has('code') || url.searchParams.has('error'))) return false;
  return ![...url.searchParams.keys()].some(key => /(?:^|_)(?:file|download|export|print|preview|asset|updates|poll|json|ajax)(?:_|$)/i.test(key)
   || /^(?:action|login|logout|enable_|invoice_mail.*original|portal_document|tax_eric_|balance_pdf$|voucher_pdf$|own_voucher_pdf$|voucher_manager_pdf$|voucher_original$|voucher_mail_original$|donation_original$)/.test(key));
 }
 function assetFor(node) {
  const url = new URL(node.getAttribute(node.tagName === 'SCRIPT' ? 'src' : 'href'), base);
  const asset = assets.get(url.pathname);
  if (url.origin !== base.origin || !asset || (node.tagName === 'SCRIPT' ? asset.kind !== 'script' : asset.kind !== 'style')) throw new Error('Unregistered page asset');
  return {url:new URL(asset.url, base).href, asset};
 }
 for (const script of document.querySelectorAll('script[src]')) {
  try {const {url} = assetFor(script);scripts.set(url, Promise.resolve());} catch { /* Not a manager asset. */ }
 }
 for (const link of document.querySelectorAll('link[rel="stylesheet"]')) {
  try {const {url} = assetFor(link);if(styles.has(url))link.remove();else styles.set(url, link);} catch { /* Not a manager asset. */ }
 }
 function loadScript(url, asset) {
  if (!scripts.has(url)) {
   const promise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = url; script.async = false;
    if (asset.module) script.type = 'module';
    const timer = setTimeout(() => {script.remove();reject(new Error('Script timeout'));}, 15000);
    script.onload = () => {clearTimeout(timer);resolve();};
    script.onerror = () => {clearTimeout(timer);script.remove();reject(new Error('Script unavailable'));};
    document.head.append(script);
   });
   scripts.set(url, promise);
   promise.catch(() => scripts.delete(url));
  }
  return scripts.get(url);
 }
 async function prepareAssets(doc) {
  const needed = new Map(), readyStyles = [];
  let previous;
  for (const node of doc.querySelectorAll('link[rel="stylesheet"]')) {
   if (node.closest('noscript')) continue;
   const {url} = assetFor(node); if (needed.has(url)) continue;
   let link = styles.get(url);
   // Keep loaded head links in place: moving one detaches its sheet temporarily.
   // Body links would disappear with #main. Stage a head copy for those and for
   // order changes, leaving the current page's CSS active until commit.
   if (!link || link.parentNode !== document.head || (previous && !(previous.compareDocumentPosition(link) & Node.DOCUMENT_POSITION_FOLLOWING))) {
    link = document.createElement('link'); link.rel = 'stylesheet'; link.href = url;
    // Load without changing the current page's cascade before the transaction commits.
    link.media = 'not all';
    styles.set(url,link);
    const ready = new Promise((resolve,reject) => {
     const timer = setTimeout(() => {link.remove();reject(new Error('Stylesheet timeout'));},15000);
     link.onload = () => {clearTimeout(timer);resolve();};
     link.onerror = () => {clearTimeout(timer);link.remove();reject(new Error('Stylesheet unavailable'));};
     document.head.append(link);
    });
    styleLoads.set(url,ready);
    ready.catch(() => {if(styles.get(url) === link) {styles.delete(url);styleLoads.delete(url);}});
   }
   if (styleLoads.has(url)) readyStyles.push(styleLoads.get(url));
   needed.set(url,link);
   previous = link;
  }
  await Promise.all(readyStyles);
  for (const node of doc.querySelectorAll('script')) {
   if (node.closest('noscript')) continue;
   if (!node.src) {if (!node.type || /(?:java|ecma)script|module/.test(node.type)) throw new Error('Inline page script rejected');continue;}
   const {url,asset} = assetFor(node); await loadScript(url,asset);
  }
  return needed;
 }
 function clean(root) {
  root.querySelectorAll('script,link[rel="stylesheet"],base,meta[http-equiv]').forEach(node => node.remove());
  for (const node of [...(root.nodeType === Node.ELEMENT_NODE ? [root] : []),...root.querySelectorAll('*')]) {
   if (node instanceof HTMLTemplateElement) clean(node.content);
   for (const attribute of [...node.attributes]) {
    let scriptUrl=false;
    if (['href','src','action','formaction','xlink:href'].includes(attribute.name)) {
     try {scriptUrl=new URL(attribute.value,base).protocol === 'javascript:';} catch { /* An invalid URL cannot execute. */ }
    }
    if (/^on/i.test(attribute.name) || attribute.name === 'srcdoc' || scriptUrl) node.removeAttribute(attribute.name);
   }
  }
  return root;
 }
 function failure(message = uiText('Bereich konnte nicht geöffnet werden. Deine Eingaben bleiben erhalten. Bitte erneut versuchen.')) {
  const main = document.getElementById('main');
  let status = main.querySelector('[data-manager-navigation-error]');
  if (!status) {status = document.createElement('p');status.dataset.managerNavigationError = '';status.className = 'error';status.setAttribute('role','alert');main.prepend(status);}
  status.textContent = message;
 }
 function restoreFocus(options, url) {
  const main = document.getElementById('main'), focus = options.focus;
  const modal = main.querySelector('dialog[open]');
  if (focus?.selector) {
   let input;try {input=document.querySelector(focus.selector);} catch { /* Invalid caller selector falls back to the page. */ }
   if (input) {
    input.focus({preventScroll:true});
    if (typeof input.setSelectionRange === 'function' && Number.isInteger(focus.start)) {try {input.setSelectionRange(focus.start,focus.end ?? focus.start);} catch { /* Control does not support a text range. */ }}
   } else if (!modal) main.focus({preventScroll:true});
  } else if (!modal) main.focus({preventScroll:true});
  const hash = new URL(url).hash;
  if (hash) {try {document.getElementById(decodeURIComponent(hash.slice(1)))?.scrollIntoView();} catch { /* Invalid encoded fragment. */ }}
  else window.scrollTo(focus?.x ?? options.scroll?.[0] ?? 0, focus?.y ?? options.scroll?.[1] ?? 0);
 }
 function refreshChrome(doc) {
  const navigation = document.getElementById('navigation');
  const scroll = [navigation.scrollLeft,navigation.scrollTop];
  const groupKey = group => group.querySelector('.subnav a[href]')?.getAttribute('href') || group.querySelector('summary')?.textContent;
  const groups = new Map([...navigation.querySelectorAll('.nav-group')].map(group => [groupKey(group),group.open]));
  const search = navigation.querySelector('[data-menu-search]')?.value || '';
  // Keep both shell elements in place. Permissions, active links, names and help
  // still come from the authenticated response; only their children change.
  for (const selector of ['#navigation','.topbar']) {
   const current = document.querySelector(selector), next = document.importNode(clean(doc.querySelector(selector)),true);
   current.replaceChildren(...next.childNodes);
  }
  return () => {
   navigation.querySelectorAll('.nav-group').forEach(group => {if(groups.has(groupKey(group)))group.open=groups.get(groupKey(group));});
   const field = navigation.querySelector('[data-menu-search]');
   if(field && search) {field.value=search;field.dispatchEvent(new Event('input',{bubbles:true}));}
   navigation.scrollLeft=scroll[0];navigation.scrollTop=scroll[1];
  };
 }
 function commit(doc, needed, url, options) {
  committing = true;
  window.KulturhusDialog?.closeAll();
  document.dispatchEvent(new CustomEvent('manager:before-unmount'));
  window.KulturhusPage.unmount();
  // Activate prepared styles without detaching them, then retire old copies.
  for (const link of needed.values()) link.media = '';
  for (const link of document.querySelectorAll('link[rel="stylesheet"]')) {
   try {const {url} = assetFor(link);if (needed.get(url) !== link) link.remove();} catch { /* Not a manager stylesheet. */ }
  }
  for (const [url] of styles) {if (!needed.has(url)) {styles.delete(url);styleLoads.delete(url);}}
  const restoreChrome = refreshChrome(doc);
  document.getElementById('main').replaceWith(document.importNode(clean(doc.querySelector('#main')),true));
  for (const name of ['manager-interface','form-validation-post','live-upload-restore','iban-country-lengths','iban-country-patterns']) {
   const next = doc.querySelector(`meta[name="${name}"]`), old = document.querySelector(`meta[name="${name}"]`);
   if (next) {if (old) old.content = next.content;else document.head.append(document.importNode(next,true));} else old?.remove();
  }
  // An empty brand is the server's original design, not an omitted default.
  if (doc.body.hasAttribute('data-brand')) document.body.dataset.brand = doc.body.dataset.brand;
  else document.body.removeAttribute('data-brand');
  document.documentElement.lang = doc.documentElement.lang; document.title = doc.title; current = url;
  window.KulturhusPage.mount(); restoreChrome(); baseline();
  document.dispatchEvent(new CustomEvent('manager:navigated',{detail:{url,view:new URL(url).searchParams.get('view') || 'dashboard'}}));
  restoreFocus(options,url); committing = false;
 }
 async function navigate(target, options = {}) {
  const url = new URL(target, current);
  if (!eligible(url) || traversal) return false;
  pending?.abort(); const controller = new AbortController();pending = controller;
  const request = ++generation, before = revision, beforeSignature = pageSignature();
  const discardForm=options.discardForm;
  const explicitDiscard=discardForm?.isConnected&&discardForm.matches('form[data-appearance-form]')&&url.searchParams.has('refresh')
   && !postForms().some(form=>form!==discardForm&&(dirtyForms.has(form)||(baselines.has(form)&&baselines.get(form)!==signature(form))))
   && !window.KulturhusPage.guarded();
  if (dirty() && !explicitDiscard) {
   const accepted = await window.KulturhusDialog.confirm(uiText('Ungespeicherte Änderungen oder laufende Vorgänge verwerfen und den Bereich öffnen?'),{accept:uiText('Änderungen verwerfen'),danger:true});
   if (!accepted || request !== generation || revision !== before || pageSignature() !== beforeSignature) return false;
  }
  // A native-only current URL must retain a full document in browser history.
  // Keep the existing edit confirmation above and target eligibility unchanged.
  if (!eligible(new URL(current))) {
   nativeSubmit=true;
   if (options.replace || url.href === current) location.replace(url.href);
   else location.assign(url.href);
   return true;
  }
  const main = document.getElementById('main');main.setAttribute('aria-busy','true');
  const timer = setTimeout(() => controller.abort(),20000);
  try {
   const response = await fetch(url.href,{credentials:'same-origin',cache:'no-store',signal:controller.signal,headers:{Accept:'text/html','X-Kulturhus-Navigation':'1'}});
   // Authenticated validation pages retain the submitted search and its specific errors.
   if ((!response.ok && response.status !== 422) || !eligible(new URL(response.url)) || !response.headers.get('content-type')?.includes('text/html') || response.headers.get('X-Kulturhus-Page') !== 'manager-v1') throw new Error('Page unavailable');
   const doc = new DOMParser().parseFromString(await response.text(),'text/html');
   const next = JSON.parse(doc.querySelector('meta[name="manager-navigation"]')?.content || 'null');
   if (!next || next.protocol !== config.protocol || next.identity !== config.identity || !doc.querySelector('#main') || !doc.querySelector('#navigation') || !doc.querySelector('.topbar')) throw new Error('Session or page changed');
   // A changed build needs one fresh document, not a dead-end error banner.
   // The dirty-form confirmation above and the final edit checks still apply.
   const updated = JSON.stringify(next.assets) !== JSON.stringify(config.assets);
   const needed = updated ? null : await prepareAssets(doc);
   if (request !== generation || before !== revision || controller.signal.aborted || pageSignature() !== beforeSignature) return false;
   const finalUrl = new URL(response.url);if (url.hash) finalUrl.hash = url.hash;
   const destination = finalUrl.href;
   if (options.pop) {
    return await new Promise(resolve => {
     traversal = {kind:'commit',index:options.pop.index,run:() => {
      if (request !== generation || before !== revision || controller.signal.aborted || pageSignature() !== beforeSignature) {
       traversal={kind:'restore',index:position,run:()=>resolve(false)};
       history.go(position-options.pop.index);return;
      }
      position=options.pop.index;history.replaceState(state(position,destination,options.pop.scroll),'',destination);
      if (updated) {nativeSubmit=true;location.replace(destination);}
      else commit(doc,needed,destination,{...options,scroll:options.pop.scroll});
      resolve(true);
     }};
     history.go(options.pop.index-position);
    });
   }
   if (updated) {
    // Reaching this point means the form is unchanged, or discarding its exact
    // current values was confirmed. Avoid a second beforeunload confirmation.
    nativeSubmit=true;
    if (options.replace || destination === current) location.replace(destination);
    else location.assign(destination);
    return true;
   }
   if (options.replace) history.replaceState(state(position,destination), '', destination);
   else {history.replaceState(state(position,current,[scrollX,scrollY]),'',current);history.pushState(state(++position,destination),'',destination);}
   commit(doc,needed,destination,options);return true;
  } catch (error) {
   if (request !== generation || before !== revision) return false;
   failure(error.name === 'AbortError' ? uiText('Der Bereich antwortet nicht rechtzeitig. Deine Eingaben bleiben erhalten. Bitte erneut versuchen.') : undefined);
   return false;
  } finally {clearTimeout(timer);main.removeAttribute('aria-busy');}
 }
 const markClean = form => {if (form instanceof HTMLFormElement) {baselines.set(form,signature(form));editStates.set(form,editingSignature(form));if(form.hasAttribute('data-unsaved-changes'))dirtyForms.add(form);else dirtyForms.delete(form);}};
 const updateUrl = target => {
  const url = new URL(target,current);
  if (!eligible(url) || traversal || committing) return false;
  current=url.href;history.replaceState(state(position,current,[scrollX,scrollY]),'',current);return true;
 };
 window.KulturhusNavigation = Object.freeze({navigate,eligible,dirty,isDirty:dirty,markClean,updateUrl});
 function edited(event) {
  if (committing || !document.getElementById('main').contains(event.target)) return;
  const form = event.target.form || event.target.closest('form');
  const subject = form || event.target;
  const value = form ? editingSignature(form) : JSON.stringify([event.target.value,event.target.checked,event.target.isContentEditable ? event.target.innerHTML : null]);
  // Opening a modal blurs the focused field and may emit change after input.
  // Only a new value invalidates an in-flight navigation/confirmation.
  if (editStates.get(subject) === value) return;
  editStates.set(subject,value);
  revision++; generation++;pending?.abort();
  if (!form || form.method !== 'post' || event.target.closest('[data-dirty-ignore]')) return;
  if (!form.hasAttribute('data-unsaved-changes') && signature(form) === baselines.get(form) && !event.target.isContentEditable) dirtyForms.delete(form);else dirtyForms.add(form);
 }
 document.addEventListener('input',edited);document.addEventListener('change',edited);
 document.addEventListener('manager:form-saved',event => markClean(event.target));
 window.addEventListener('beforeunload',event => {if (!nativeSubmit && dirty()) {event.preventDefault();event.returnValue='';}});
 document.addEventListener('submit',event => {
  const form = event.target;
  if (!(form instanceof HTMLFormElement) || event.defaultPrevented) return;
  if (form.hasAttribute('data-navigation-native')) {
   if (form.matches('[data-check-plan-form],[data-event-draft-form]') && ![...dirtyForms].some(changed => changed !== form)) queueMicrotask(() => {if (!event.defaultPrevented) {nativeSubmit=true;setTimeout(()=>{nativeSubmit=false;},1000);}});
   return;
  }
  const button = event.submitter;
  if ((button?.getAttribute('formmethod') || form.method).toLowerCase() === 'get' && (!form.target || form.target === '_self') && !button?.hasAttribute('formtarget')) {
   const target = new URL(button?.getAttribute('formaction') || form.action,current);
   target.search = new URLSearchParams(new FormData(form,button)).toString();
   if (eligible(target)) {
    event.preventDefault();
    const list = form.closest('.content-lister');
    if (!list) {navigate(target.href);return;}
    if (list.inert) return;
    const focus = button || document.activeElement, status = document.createElement('p');
    status.setAttribute('role','status');status.textContent=uiText('Inhalte werden geladen.');
    list.before(status);list.inert=true;list.setAttribute('aria-busy','true');
    let completed=false;
    navigate(target.href).then(result => {completed=result;}).catch(() => failure()).finally(() => {
     list.inert=false;list.removeAttribute('aria-busy');status.remove();
     if (!completed && list.isConnected && focus?.isConnected) focus.focus({preventScroll:true});
    });
    return;
   }
  }
  // Independent page forms must not silently discard each other's inputs.
  const operation = form.elements.namedItem('operation')?.value;
  const pageSave = form.closest('.native-page-editor') && ['page-save','page-sections-save'].includes(operation);
  const otherDirty = [...dirtyForms].some(changed => changed !== form && changed.isConnected)
   || postForms().some(changed => changed !== form && baselines.has(changed) && baselines.get(changed) !== signature(changed));
  if ((pageSave || form.hasAttribute('data-confirm-other-drafts')) && otherDirty && !approvedPageSubmits.has(form)) {
   event.preventDefault();
   queueMicrotask(async () => {
    if (!form.isConnected) return;
    const before = revision, beforeGeneration = generation, beforeUrl = current, beforeSignature = pageSignature();
    const accepted = await window.KulturhusDialog.confirm(uiText('Andere ungespeicherte Eingaben verwerfen und nur dieses Formular speichern?'),{accept:uiText('Andere Eingaben verwerfen'),danger:true});
    if (!accepted || !form.isConnected || revision !== before || generation !== beforeGeneration || current !== beforeUrl || pageSignature() !== beforeSignature) return;
    approvedPageSubmits.add(form);
    try {form.requestSubmit(button?.form === form && !button.disabled ? button : undefined);}
    finally {approvedPageSubmits.delete(form);}
   });
   return;
  }
  queueMicrotask(() => {if (!event.defaultPrevented) {nativeSubmit=true;setTimeout(()=>{nativeSubmit=false;},1000);}});
 });
 document.addEventListener('click',event => {
  if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
  const link = event.target.closest('a[href]');
  if (!link || link.hasAttribute('download') || link.hasAttribute('data-navigation-native') || (link.target && link.target !== '_self')) return;
  const target = new URL(link.href,current);
  if (!eligible(target)) return;
  if (traversal) {event.preventDefault();return;}
  if (target.hash && target.search === new URL(current).search) {
   event.preventDefault();
   const oldURL=current;
   history.replaceState(state(position,current,[scrollX,scrollY]),'',current);
   current=target.href;history.pushState(state(++position,current),'',current);
   window.dispatchEvent(new HashChangeEvent('hashchange',{oldURL,newURL:current}));
   let anchor;try {anchor=document.getElementById(decodeURIComponent(target.hash.slice(1)));} catch {return;}
   anchor?.focus({preventScroll:true});anchor?.scrollIntoView();return;
  }
  event.preventDefault();navigate(target.href,{discardForm:link.hasAttribute('data-appearance-discard')?link.closest('form[data-appearance-form]'):null});
 });
 window.addEventListener('popstate',event => {
  const target = event.state?.khManager;
  if (!target || target.session !== session) {location.reload();return;}
  if (traversal) {
   if (target.index !== traversal.index) {history.go(traversal.index-target.index);return;}
   const task = traversal;traversal = null;task.run();return;
  }
  if (target.index === position) return;
  if (new URL(target.url).search === new URL(current).search) {
   pending?.abort();generation++;position=target.index;current=target.url;
   window.scrollTo(...(target.scroll || [0,0]));return;
  }
  // Restore the displayed page's URL before prompting or fetching. Failed/cancelled
  // traversal keeps the original history entries instead of overwriting them.
  traversal = {kind:'restore',index:position,run:()=>navigate(target.url,{pop:target})};
  history.go(position-target.index);
 });
 let scrollFrame;
 window.addEventListener('scroll',() => {
  if (scrollFrame || committing || traversal) return;
  scrollFrame = requestAnimationFrame(() => {scrollFrame=null;if (history.state?.khManager?.index===position && !traversal) history.replaceState(state(position,current,[scrollX,scrollY]),'',current);});
 },{passive:true});
 const start = () => {window.KulturhusPage.mount();baseline();};
 if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
