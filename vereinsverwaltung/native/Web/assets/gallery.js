(() => {
  'use strict';
  const contentText = source => {
    const read=window.KulturhusInterface?.text;if(!read)return source;
    for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}
    return source;
  };
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  if (window.KulturhusGallery) return;
  const mounted = new WeakMap();
  const mount = (root = document) => {
    const modal = root.querySelector('#gallery-lightbox');
    if (!modal) return () => {};
    if (mounted.has(modal)) return mounted.get(modal);
    const listeners = [], on = (target, type, listener, options) => { target.addEventListener(type, listener, options); listeners.push(() => target.removeEventListener(type, listener, options)); };
    const photo = modal.querySelector('img'), caption = modal.querySelector('figcaption'), counter = modal.querySelector('.lightbox-counter'), status = modal.querySelector('.lightbox-status'), reload = modal.querySelector('.lightbox-reload');
    let current = 0, collection = [], opener = null, startX = null, context = null, request = null, busy = false, disposed = false;
    const integer = (value, max = 500000) => /^\d{1,10}$/.test(value || '') && Number(value) > 0 && Number(value) <= max ? Number(value) : null;
    const readContext = element => {
      if (!element) return null;
      const d = element.dataset, total = integer(d.galleryTotal), page = integer(d.galleryPage), pages = integer(d.galleryPages), version = integer(d.galleryVersion, 4294967295);
      if (!/^[a-f0-9]{32}$/.test(d.galleryId || '') || !['public', 'private'].includes(d.galleryKind) || !total || !page || !pages || !version || page > pages || pages !== Math.ceil(total / 6)) return null;
      const url = new URL(d.galleryUrl || location.href, location.origin);
      if (url.origin !== location.origin) return null;
      url.hash = '';
      return { id: d.galleryId, version, total, page, pages, kind: d.galleryKind, url };
    };
    const validPhoto = button => {
      try {
        const url = new URL(button.dataset.full, location.origin);
        return url.origin === location.origin && (/^\/media(?:-preview)?\/[a-f0-9]{32}\/large$/.test(url.pathname) || (url.pathname === '/vereinsverwaltung/' && url.searchParams.get('native_endpoint') === 'media-preview' && /^[a-f0-9]{32}$/.test(url.searchParams.get('media') || '') && url.searchParams.get('variant') === 'large'));
      } catch { return false; }
    };
    const setBusy = value => { busy = value; modal.setAttribute('aria-busy', String(value)); modal.querySelectorAll('[data-lightbox-step]').forEach(button => button.disabled = value); };
    const clearError = () => { if (status) { status.hidden = true; status.textContent = ''; } if (reload) reload.hidden = true; };
    const show = next => {
      if (!collection.length) return;
      current = (next + collection.length) % collection.length;
      const button = collection[current];
      if (!validPhoto(button)) return;
      photo.src = new URL(button.dataset.full, location.origin).href;
      photo.alt = button.dataset.alt ?? button.dataset.caption ?? '';
      caption.textContent = button.dataset.caption || '';
      const credit = button.closest('figure,article')?.querySelector('.photo-credit');
      if (credit) caption.append(document.createTextNode(' '), credit.cloneNode(true));
      counter.textContent = `${context ? integer(button.dataset.galleryIndex) : current + 1} / ${context?.total || collection.length}`;
      modal.querySelectorAll('[data-lightbox-step]').forEach(button => button.hidden = (context?.total || collection.length) < 2);
      clearError();
    };
    const change = async step => {
      if (busy || disposed || !modal.open || !collection.length) return;
      if (!context) { show(current + step); return; }
      const ordinal = integer(collection[current].dataset.galleryIndex), desired = ((ordinal - 1 + step + context.total) % context.total) + 1;
      const local = collection.findIndex(button => integer(button.dataset.galleryIndex) === desired);
      if (local >= 0) { show(local); return; }
      setBusy(true); clearError(); const controller = new AbortController(); request = controller;
      try {
        let page = Math.floor((desired - 1) / 6) + 1;
        for (let attempt = 0; attempt < Math.min(5, context.pages); attempt++) {
          const url = new URL(context.url); url.searchParams.set(context.kind === 'public' ? 'page' : 'album_page', String(page));
          const response = await fetch(url.href, { credentials: 'same-origin', redirect: 'error', cache: 'no-store', signal: controller.signal });
          if (!response.ok || !(response.headers.get('content-type') || '').includes('text/html') || Number(response.headers.get('content-length') || 0) > 4194304) throw Error('unavailable');
          const html = await response.text();
          if (html.length > 4194304) throw Error('unavailable');
          if (disposed || !modal.open || controller.signal.aborted || request !== controller) return;
          // Template content is inert: fetched images, scripts and frames never mount.
          const inert = document.createElement('template'); inert.innerHTML = html;
          const section = inert.content.querySelector(`${context.kind === 'private' ? '#album-image-list' : '#gallery-items'}[data-gallery-id="${context.id}"]`), fresh = readContext(section);
          if (!fresh || fresh.version !== context.version || fresh.total !== context.total || fresh.pages !== context.pages || fresh.page !== page || fresh.kind !== context.kind) throw Error('changed');
          const rows = [...section.querySelectorAll(context.kind === 'private' ? '.album-image [data-lightbox]' : '.public-photo [data-lightbox]')].filter(button => {
            const index = integer(button.dataset.galleryIndex);
            return validPhoto(button) && index >= (page - 1) * 6 + 1 && index <= Math.min(page * 6, context.total);
          });
          if (rows.length > 6 || new Set(rows.map(button => button.dataset.galleryIndex)).size !== rows.length) throw Error('unavailable');
          const available = attempt === 0 ? rows.filter(button => step > 0 ? integer(button.dataset.galleryIndex) >= desired : integer(button.dataset.galleryIndex) <= desired) : rows;
          if (available.length) { collection = rows; context.page = page; show(step > 0 ? rows.indexOf(available[0]) : rows.indexOf(available.at(-1))); return; }
          page = ((page - 1 + step + context.pages) % context.pages) + 1;
        }
        throw Error('unavailable');
      } catch (error) {
        if (!disposed && modal.open && request === controller && error.name !== 'AbortError') {
          if (status) { status.textContent = contentText('Bildwechsel nicht möglich.'); status.hidden = false; }
          if (reload) { reload.href = context.url.href; reload.hidden = false; }
        }
      } finally { if (request === controller) { request = null; if (!disposed) setBusy(false); } }
    };
    on(root, 'click', event => {
      const button = event.target.closest('[data-lightbox]'); if (!button || !validPhoto(button)) return;
      opener = button; context = readContext(button.closest('[data-gallery-id]'));
      collection = [...root.querySelectorAll('[data-lightbox]')].filter(item => item.dataset.group === button.dataset.group);
      show(collection.indexOf(button)); modal.showModal(); document.documentElement.classList.add('lightbox-open');
    });
    on(modal, 'click', event => {
      if (event.target.closest('[data-lightbox-close]')) modal.close();
      const step = event.target.closest('[data-lightbox-step]'); if (step) void change(Number(step.dataset.lightboxStep));
      if (event.target === modal) { const r = modal.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) modal.close(); }
    });
    on(modal, 'keydown', event => { if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') { event.preventDefault(); void change(event.key === 'ArrowRight' ? 1 : -1); } });
    on(modal, 'touchstart', event => { startX = event.touches[0]?.clientX ?? null; }, { passive: true });
    on(modal, 'touchend', event => { if (startX !== null) { const delta = (event.changedTouches[0]?.clientX ?? startX) - startX; if (Math.abs(delta) > 55) void change(delta < 0 ? 1 : -1); } startX = null; }, { passive: true });
    on(modal, 'cancel', () => { request?.abort(); request = null; setBusy(false); });
    on(modal, 'close', () => { request?.abort(); request = null; setBusy(false); document.documentElement.classList.remove('lightbox-open'); photo.removeAttribute('src'); clearError(); if (opener?.isConnected) opener.focus(); });
    const cleanup = () => { disposed = true; request?.abort(); if (modal.open) modal.close(); listeners.forEach(remove => remove()); mounted.delete(modal); };
    mounted.set(modal, cleanup); return cleanup;
  };
  window.KulturhusGallery = Object.freeze({ mount });
  if (window.KulturhusPage) window.KulturhusPage.register('native-gallery', context => mount(context.root));
  else if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => mount(), { once: true }); else mount();
})();
