(() => {
  'use strict';
  const mounted = new WeakSet();
  const imageId = value => /^[a-f0-9]{32}$/.test(value || '');
  const text = value => {const key = 'content:' + value, translated = window.KulturhusInterface?.text(key);return translated && translated !== key ? translated : value;};
  function listen(target, event, callback, context) {
    target.addEventListener(event, callback);
    context?.cleanup(() => target.removeEventListener(event, callback));
  }
  function address(title) {
    let value = title.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
      .normalize('NFKD').replace(/[\u0300-\u036f]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '').slice(0, 130).replace(/-$/g, '');
    if (/^(?:assets|site|media|feeds|vereinsverwaltung|login|logout|account|accounts|invite|reset|forgot|preview|media-preview|chat-file|chat-image|chat-status|model-fields|reference-options|page-photo-status|album-photo-status|frontend-edit|frontend-preview|home-preview|sections-preview|design-preview|newsletter|newsletter-confirm|newsletter-unsubscribe|publication-image|publication-video|social-image|social-video|search|suche|status|extensions|api|file|file-preview|tickets|meta-webhook)$/.test(value)) value = 'seite-' + value;
    return value;
  }
  function mountControls(root, context) {
    for (const technical of root.querySelectorAll('.website-technical-setup')) {
      const reveal = () => {let id;try {id = decodeURIComponent(location.hash.slice(1));} catch {return;}const target = document.getElementById(id);if (!target || !technical.contains(target)) return;for (let node = target; node && technical.contains(node); node = node.parentElement) if (node instanceof HTMLDetailsElement) node.open = true;technical.open = true;};
      reveal();if (!mounted.has(technical)) {mounted.add(technical);listen(window, 'hashchange', reveal, context);}
    }
    for (const status of root.querySelectorAll('[data-page-save-status]')) {
      if (mounted.has(status) || !status.closest('form')) continue;
      mounted.add(status);const form = status.closest('form');
      const changed = event => {if (event.target.closest('[data-dirty-ignore]')) return;status.textContent = text(form.elements.namedItem('sections_present') ? 'Ungespeicherte Änderungen. „Entwurf speichern“ übernimmt Text, Bilder und Inhaltsabschnitte gemeinsam.' : 'Ungespeicherte Änderungen. Mit „Entwurf speichern“ übernimmst du die Änderungen.');};
      listen(form, 'input', changed, context);listen(form, 'change', changed, context);
    }
    for (const form of root.querySelectorAll('[data-address-suggestion]')) {
      if (mounted.has(form)) continue;
      mounted.add(form);
      const title = form.elements.namedItem('title'), target = form.querySelector('[data-address-target]');
      if (!title || !target) continue;
      const prefix = target.value === 'galerie/' ? 'galerie/' : (form.elements.namedItem('template')?.value === 'event' ? 'veranstaltungen/' : '');
      let automatic = target.value === '' || target.value === prefix;
      listen(target, 'input', () => { automatic = target.value === '' || target.value === prefix; }, context);
      listen(title, 'input', () => { if (automatic) target.value = prefix + address(title.value); }, context);
    }
    for (const picker of root.querySelectorAll('[data-logo-picker]')) {
      if (mounted.has(picker)) continue;
      mounted.add(picker);
      const select = picker.querySelector('[name=logo_id]'), preview = picker.querySelector('[data-logo-preview]'), empty = picker.querySelector('[data-logo-empty]');
      function update() {
        const selected = imageId(select?.value);
        preview.hidden = !selected;
        empty.hidden = selected;
        if (selected) preview.src = picker.dataset.pageMediaPreview.replace('00000000000000000000000000000000', select.value);
        else preview.removeAttribute('src');
      }
      const upload = picker.querySelector('[name=logo_photo]'), clear = picker.querySelector('[data-logo-clear]');
      let temporary;
      function resetUpload() {picker.querySelector('[name=logo_upload_required]')?.remove();if (upload) {upload.value = '';upload.required = false;}if (temporary) {URL.revokeObjectURL(temporary);temporary = undefined;}}
      if (select) listen(select, 'change', () => {resetUpload();update();}, context);
      if (clear) {clear.hidden = false;listen(clear, 'click', () => {resetUpload();select.value = '';select.dispatchEvent(new Event('change', {bubbles:true}));update();}, context);}
      if (upload) listen(upload, 'change', () => {
        if (temporary) URL.revokeObjectURL(temporary);
        const file = upload.files[0];
        if (!file) { update(); return; }
        temporary = URL.createObjectURL(file);preview.src = temporary;preview.hidden = false;empty.hidden = true;
      }, context);
      context?.cleanup(() => { if (temporary) URL.revokeObjectURL(temporary); });
      update();
    }
    for (const modes of root.querySelectorAll('[data-content-filter-modes]')) {
      if (mounted.has(modes)) continue;
      mounted.add(modes);
      const select = modes.querySelector('[name=filter_mode]'), advanced = modes.querySelector('[data-filter-expression]'), rules = modes.querySelector('[data-filter-rules]');
      function update() {const active = select?.value === 'expression';advanced.hidden = !active;rules.hidden = active;if (active) advanced.open = true;}
      if (select) listen(select, 'change', update, context);
      update();
    }
  }
  function sections(form, main, panel, document) {
    const source = form.querySelector('[data-content-section]');
    if (!source) return;
    let container = main.querySelector('.website-content-sections');
    if (!container) {container = document.createElement('div');container.className = 'website-content-sections';const content = main.querySelector('.site-content');if (!content) return;let previous = content.querySelector(':scope > .kh-richtext');if (previous?.nextElementSibling?.matches('.website-documents')) previous = previous.nextElementSibling;if (previous) previous.after(container);else content.prepend(container);}
    container.replaceChildren();
    const rows = [...form.querySelectorAll('[data-content-section]')].map((row, index) => {
      const value = key => row.querySelector('[name$="[' + key + ']"]')?.value || '';
      return {row, index, value, position: Number(value('position')) || 0};
    }).sort((a, b) => a.position - b.position || a.index - b.index);
    for (const {row, value} of rows) {
      if (row.querySelector('[name$="[remove]"]')?.checked) continue;
      if (row.querySelector('[name$="[unused_slot]"]') && !['heading','body_html','media_id','alt','caption','credit','button_label','button_url'].some(key => value(key).trim())) continue;
      const layout = ['text','image-before','image-after'].includes(value('layout')) ? value('layout') : 'text';
      const section = document.createElement('section');section.className = 'website-content-section website-content-section--' + layout;
      const content = document.createElement('div');content.className = 'website-section-text';
      if (value('heading')) {const heading = document.createElement('h2');heading.textContent = value('heading');content.append(heading);}
      const body = document.createElement('div');body.className = 'kh-richtext';body.innerHTML = window.KulturhusRichText?.sanitize(value('body_html')) || '';content.append(body);
      const url = value('button_url');
      if (value('button_label') && (/^https:\/\//i.test(url) || /^\/(?!\/)/.test(url) || /^#[A-Za-z]/.test(url))) {const button = document.createElement('a');button.className = 'btn btn-solid theme-primary button';button.textContent = value('button_label');button.href = url;const action = document.createElement('p');action.append(button);content.append(action);}
      const figure = document.createElement('figure');
      if (layout !== 'text' && imageId(value('media_id'))) {
        const image = document.createElement('img');image.src = panel.dataset.pageLiveMedia.replace('00000000000000000000000000000000', value('media_id'));image.alt = value('alt');
        const ratios = {landscape:'16 / 9',square:'1 / 1',portrait:'3 / 4'};
        if (ratios[value('aspect')]) {image.style.aspectRatio = ratios[value('aspect')];image.style.objectFit = 'cover';image.style.objectPosition = Math.max(0, Math.min(100, Number(value('focal_x')) || 0)) + '% ' + Math.max(0, Math.min(100, Number(value('focal_y')) || 0)) + '%';}
        figure.append(image);
        if (value('caption') || value('credit')) {const caption = document.createElement('figcaption');caption.textContent = value('caption');if (value('credit')) {const credit = document.createElement('span');credit.className = 'photo-credit';credit.textContent = 'Foto: ' + value('credit');caption.append(' ', credit);}figure.append(caption);}
      }
      if (layout === 'image-before' && figure.childNodes.length) section.append(figure);
      section.append(content);
      if (layout === 'image-after' && figure.childNodes.length) section.append(figure);
      container.append(section);
    }
  }
  function mount(root = document, context = null) {
    mountControls(root, context);
    for (const panel of root.querySelectorAll('[data-page-live]')) {
      if (mounted.has(panel)) continue;
      const form = panel.closest('.page-live-layout,.news-editor-layout')?.querySelector('form');
      if (!form) continue;
      mounted.add(panel);
      const frame = panel.querySelector('iframe'), seed = new DOMParser().parseFromString(panel.querySelector('[data-page-live-html]').value, 'text/html');
      seed.querySelectorAll('script,form,iframe,object,embed,base').forEach(node => node.remove());
      for (const node of seed.querySelectorAll('*')) for (const attribute of [...node.attributes]) if (attribute.name.startsWith('on')) node.removeAttribute(attribute.name);
      const galleryFigures = new Map();
      for (const figure of seed.querySelectorAll('main .gallery figure')) {const id = figure.querySelector('img')?.getAttribute('src')?.match(/[a-f0-9]{32}/)?.[0];if (id) galleryFigures.set(id, figure.outerHTML);}
      function update() {
        const document = frame.contentDocument, main = document?.querySelector('main');if (!main) return;
        const title = main.querySelector('h1'), excerpt = main.querySelector('.lead'), body = main.querySelector('.site-content>.kh-richtext');
        if (title && form.elements.title) title.textContent = form.elements.title.value;
        if (excerpt && form.elements.excerpt) excerpt.textContent = form.elements.excerpt.value;
        const time = main.querySelector('.page-hero time');if (time && form.elements.news_date) {time.dateTime = form.elements.news_date.value;time.textContent = form.elements.news_date.value;}
        if (body && form.elements.body_html) body.innerHTML = window.KulturhusRichText?.sanitize(form.elements.body_html.value) || '';
        sections(form, main, panel, document);
        const select = form.querySelector('[data-page-media-select]'), gallery = main.querySelector('.gallery');
        if (gallery) {
          const choices = select ? [...select.selectedOptions].map(node => node.value) : [form.elements.cover_id?.value, ...form.querySelectorAll('input[name="media_ids[]"]:checked')].map(node => typeof node === 'string' ? node : node?.value);
          gallery.replaceChildren();
          for (const id of new Set(choices.filter(imageId))) {
            const known = galleryFigures.get(id);
            if (known) {const template = document.createElement('template');template.innerHTML = known;gallery.append(template.content);}
            else {const figure = document.createElement('figure'), image = document.createElement('img');image.src = panel.dataset.pageLiveMedia.replace('00000000000000000000000000000000', id);image.alt = (select ? [...select.options].find(node => node.value === id)?.textContent : form.querySelector('input[value="' + id + '"]')?.closest('label')?.textContent) || '';figure.append(image);gallery.append(figure);}
          }
        }
      }
      listen(frame, 'load', () => {update();listen(frame.contentDocument, 'click', event => {if (event.target.closest('a')) event.preventDefault();}, context);}, context);
      frame.srcdoc = '<!doctype html>' + seed.documentElement.outerHTML;
      const changed = () => {update();};
      listen(form, 'input', changed, context);listen(form, 'change', changed, context);
      for (const button of panel.querySelectorAll('[data-page-live-width]')) listen(button, 'click', () => {panel.dataset.width = button.dataset.pageLiveWidth;for (const other of panel.querySelectorAll('[data-page-live-width]')) {const active = other === button;other.setAttribute('aria-pressed', String(active));for (const name of ['btn-solid','theme-primary']) other.classList.toggle(name, active);for (const name of ['btn-outline','theme-secondary']) other.classList.toggle(name, !active);}}, context);
    }
  }
  window.KulturhusPageLive = {mount};if (!window.KulturhusPage) mount();
})();
