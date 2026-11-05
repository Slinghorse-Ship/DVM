/* Kulturhus page lifecycle. Application code, no navigation framework. */
(() => {
 'use strict';
 if (window.KulturhusPage) return;
 const modules = new Map();
 let current = null;
 const report = (error, module) => {
  console.error('Kulturhus module failed:', module, error);
  document.dispatchEvent(new CustomEvent('manager:module-error', {detail:{module}}));
 };
 function unmount() {
  const page = current;
  if (!page) return;
  current = null;
  page.controller.abort();
  for (const dispose of page.cleanups.reverse()) {
   try { dispose(); } catch (error) { report(error, 'cleanup'); }
  }
  // Native dialogs and media belong to their page, including those opened by modules.
  page.root.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
  page.root.querySelectorAll('video,audio').forEach(media => {
   media.pause(); media.srcObject?.getTracks?.().forEach(track => track.stop());
  });
 }
 function mount(root = document.getElementById('main')) {
  unmount();
  if (!root) return;
  const page = {root, controller:new AbortController(), cleanups:[], guards:[]};
  current = page;
  const signal = page.controller.signal;
  const cleanup = fn => { if (signal.aborted) fn(); else page.cleanups.push(fn); return fn; };
  const timers=new Set(), intervals=new Set(), frames=new Set();
  cleanup(() => {timers.forEach(clearTimeout);intervals.forEach(clearInterval);frames.forEach(cancelAnimationFrame);timers.clear();intervals.clear();frames.clear();});
  const context = Object.freeze({
   root, signal, cleanup,
   on(target, type, listener, options = {}) {
    const opts = typeof options === 'boolean' ? {capture:options} : options;
    target?.addEventListener(type, listener, {...opts, signal});
   },
   timeout(callback, delay = 0) {
    if (signal.aborted) return 0;
    const id = setTimeout(() => {timers.delete(id);if (!signal.aborted) callback();}, delay);
    timers.add(id);return id;
   },
   interval(callback, delay) {
    if (signal.aborted) return 0;
    const id = setInterval(() => {if (!signal.aborted) callback();}, delay);
    intervals.add(id);return id;
   },
   frame(callback) {
    if (signal.aborted) return 0;
    const id = requestAnimationFrame(time => {frames.delete(id);if (!signal.aborted) callback(time);});
    frames.add(id);return id;
   },
   fetch(input, options = {}) {
    return fetch(input, {...options, signal:options.signal ? AbortSignal.any([signal, options.signal]) : signal});
   },
   guard(check) {page.guards.push(check);}
  });
  for (const [name, initialize] of modules) {
   try { const dispose = initialize(context); if (typeof dispose === 'function') cleanup(dispose); }
   catch (error) { report(error, name); }
  }
  document.dispatchEvent(new CustomEvent('manager:mounted', {detail:{root,signal}}));
 }
 window.KulturhusPage = Object.freeze({
  register(name, initialize) {
   if (modules.has(name)) return;
   if (typeof initialize !== 'function') throw new TypeError('Page initializer required');
   modules.set(name, initialize);
  },
  mount, unmount,
  guarded() {return current?.guards.some(check => {try {return Boolean(check());} catch {return true;}}) || false;}
 });
 window.addEventListener('pagehide', unmount);
 // A bfcache document contains already enhanced controls, unlike a fresh page fragment.
 window.addEventListener('pageshow', event => {if (event.persisted) location.reload();});
})();
