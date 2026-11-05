window.KulturhusPage.register('newsletter-builder', pageContext => {
 const {fetch, timeout: setTimeout} = pageContext;
function initEditorialnewsletter_builder(){
(() => {
 const dashboard=document;
  // The editor CSP deliberately allows only same-origin images. A saved mail may
  // use the public site's www alias; adapt its display, not its persisted address.
  function previewImageUrl(value, pageUrl, publicOrigin) {
    try {
      const image = new URL(value, pageUrl);
      const page = new URL(pageUrl);
      const site = (host) => host.toLowerCase().replace(/^www\./, '');
      if(page.origin==='http://127.0.0.1:8091'&&publicOrigin==='http://127.0.0.1:8092/'&&value.startsWith('/site/assets/')&&image.pathname.startsWith('/site/assets/'))return 'http://127.0.0.1:8092'+image.pathname+image.search+image.hash;
      if (!/^https:\/\//i.test(value) || page.protocol !== 'https:' ||
          image.username || image.password || image.port || page.port ||
          site(image.hostname) !== site(page.hostname) ||
          !image.pathname.startsWith('/site/assets/files/')) return value;
      return image.pathname + image.search + image.hash;
    } catch (_) {
      return value;
    }
  }
  const escapeHtml = (value = '') => {
    const element = document.createElement('span');
    element.textContent = value;
    return element.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  };

  dashboard.querySelectorAll('[data-newsletter-editor]').forEach((composer, composerIndex) => {
    const output = composer.querySelector('[data-nl-output]');
    const form = composer.closest('form');
    const canvas = composer.querySelector('[data-nl-canvas]');
    const workspace = composer.querySelector('[data-nl-workspace]');
    const imageInput = composer.querySelector('[data-nl-image-input]');
    const subjectInput = form?.querySelector('input[name="fields[subject]"]');
    const toolbarHint = composer.querySelector('[data-nl-toolbar-hint]');
    const toolbarContainer = composer.querySelector('#kh-newsletter-inline-toolbar');
    const dragStatus = composer.querySelector('[data-nl-drag-status]');
    if (!output || !form || !canvas || !workspace) return;

    let uploads=0;pageContext.guard(()=>uploads>0);
    let selectedBlock = null;
    let draggingBlock = null;
    let editorCounter = 0;
    let activeEditor = null;
    let activeEditorBlock = null;
    let draggedSource = null;
    let pendingInsertBefore;
    const dropMarker = document.createElement('div');
    dropMarker.className = 'kh-builder-drop-marker';
    dropMarker.setAttribute('aria-hidden', 'true');

    const previewImageSources = new WeakMap();
    const serializeContent = (content) => {
      if (!content) return '';
      const copy = content.cloneNode(true);
      const originals = Array.from(content.querySelectorAll('img'));
      copy.querySelectorAll('img').forEach((image, index) => {
        const source = previewImageSources.get(originals[index]);
        if (source && image.getAttribute('src') === source.display) image.setAttribute('src', source.original);
      });
      return copy.innerHTML.trim();
    };

    const syncOutput = () => {
      const html = Array.from(canvas.querySelectorAll(':scope > [data-nl-block]'))
        .map((block) => serializeContent(block.querySelector('[data-nl-content]')))
        .filter(Boolean)
        .join('\n');
      const next = html || '<p><br></p>';
      if (output.value !== next) {output.value = next; output.dispatchEvent(new Event('change', {bubbles: true}));}
    };

    const uploadImage = async (file) => {
      uploads++;try {
      if (!/^image\/(jpeg|png|webp)$/i.test(file.type)) throw new Error('Bitte ein JPG-, PNG- oder WebP-Bild auswählen.');
      const data = new FormData();
      form.querySelectorAll('input[type="hidden"][name]').forEach((input) => data.append(input.name, input.value));
      data.set('action','website-native');data.set('operation','newsletter/image');data.set('response_format','json');data.set('request_key',Array.from(crypto.getRandomValues(new Uint8Array(16)),b=>b.toString(16).padStart(2,'0')).join(''));
      [...data.keys()].filter(key=>key.startsWith('fields[')).forEach(key=>data.delete(key));
      data.append('image', file);
      const response = await fetch(form.action || window.location.href, {
        method: 'POST',
        body: data,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      let result = {};
      try {
        result = JSON.parse(await response.text());
      } catch (error) {
        throw new Error('Der Server hat den Bild-Upload nicht bestätigt.');
      }
      if (!response.ok || !result.ok) throw new Error(result.error || 'Der Upload ist fehlgeschlagen.');
      return result.url;
      } finally { uploads--; }
    };

    const classifyMarkup = (element) => {
      if (element.matches('figure.kh-newsletter-event')) return 'event';
      if (element.matches('figure')) return 'image';
      if (element.matches('hr')) return 'divider';
      if (element.matches('h2,h3')) return 'heading';
      if (element.matches('p') && element.querySelector(':scope > a.kh-newsletter-button')) return 'button';
      return 'text';
    };

    const selectBlock = (block) => {
      canvas.querySelectorAll(':scope > [data-nl-block].is-selected').forEach((item) => item.classList.remove('is-selected'));
      selectedBlock = block && canvas.contains(block) ? block : null;
      if (selectedBlock) selectedBlock.classList.add('is-selected');
    };

    const createBlock = (html, type = 'text') => {
      const block = document.createElement('article');
      block.className = `kh-builder-block kh-builder-block--${type}`;
      block.dataset.nlBlock = type;
      const textFormatting = ['text', 'heading', 'button'].includes(type)
        ? '<span class="kh-builder-format-controls" aria-label="Schrift gestalten"><button type="button" data-nl-block-action="smaller" title="Schrift verkleinern"><span aria-hidden="true">A−</span><small>Kleiner</small></button><button type="button" data-nl-block-action="larger" title="Schrift vergrößern"><span aria-hidden="true">A+</span><small>Größer</small></button><button type="button" data-nl-block-action="align-left" title="Linksbündig ausrichten"><span aria-hidden="true">≡</span><small>Links</small></button><button type="button" data-nl-block-action="align-center" title="Mittig ausrichten"><span aria-hidden="true">≡</span><small>Mitte</small></button><button type="button" data-nl-block-action="align-right" title="Rechtsbündig ausrichten"><span aria-hidden="true">≡</span><small>Rechts</small></button><label class="kh-block-color" title="Textfarbe auswählen"><small>Farbe</small><input type="color" value="#17221c" data-nl-block-color aria-label="Textfarbe auswählen"></label></span>'
        : '';
      block.innerHTML = `<div class="kh-builder-block-controls" aria-label="Baustein bearbeiten"><span class="kh-builder-structure-controls"><button type="button" class="kh-block-grip" data-nl-drag draggable="true" title="Mit der Maus verschieben"><span aria-hidden="true">⠿</span><small>Verschieben</small></button><button type="button" data-nl-block-action="up" title="Baustein nach oben"><span aria-hidden="true">↑</span><small>Hoch</small></button><button type="button" data-nl-block-action="down" title="Baustein nach unten"><span aria-hidden="true">↓</span><small>Runter</small></button><button type="button" data-nl-block-action="duplicate" title="Baustein kopieren"><span aria-hidden="true">⧉</span><small>Kopie</small></button><button type="button" class="kh-block-delete" data-nl-block-action="delete" title="Baustein löschen"><span aria-hidden="true">×</span><small>Löschen</small></button></span>${textFormatting}</div><div class="kh-builder-block-content" data-nl-content></div>`;
      const content = block.querySelector('[data-nl-content]');
      const fragment = document.createElement('template');
      fragment.innerHTML = html;
      fragment.content.querySelectorAll('img').forEach((image) => {
        const original = image.getAttribute('src') || '';
        const display = previewImageUrl(original, window.location.href, composer.dataset.nlPublicOrigin);
        if (display !== original) {
          previewImageSources.set(image, {original, display});
          image.setAttribute('src', display);
        }
      });
      content.append(fragment.content);
      content.id = `kh-newsletter-block-${composerIndex}-${++editorCounter}`;
      return block;
    };

    const activateBlockEditor = (block) => {
      const content=block.querySelector('[data-nl-content]');
      if(!content||block.dataset.nlBlock==='divider')return;
      content.contentEditable='true';activeEditorBlock=block;
      activeEditor={focus:()=>content.focus(),remove:()=>{},fire:syncOutput};
      if(toolbarHint)toolbarHint.hidden=true;
    };
    for(const [label,command,arg] of [['Fett','bold'],['Kursiv','italic'],['Unterstreichen','underline'],['Liste','insertUnorderedList'],['Nummerierung','insertOrderedList'],['Link','createLink'],['Format entfernen','removeFormat']]){
      const button=document.createElement('button');button.type='button';button.textContent=label;button.className='secondary';
      button.addEventListener('mousedown',event=>event.preventDefault());
      button.addEventListener('click',async()=>{if(!activeEditorBlock)return;let value=arg;if(command==='createLink'){value=await window.KulturhusDialog.prompt('Linkadresse','https://');if(!value||!/^(https?:\/\/|mailto:|\/(?!\/))/i.test(value))return;}activeEditor.focus();document.execCommand(command,false,value);syncOutput();});toolbarContainer.append(button);
    }
    const initializeBlock = (block) => {
      const type = block.dataset.nlBlock || 'text';
      const content = block.querySelector('[data-nl-content]');
      if (!content || type === 'divider') return;
      content.tabIndex = 0;
      content.addEventListener('focus', () => {
        selectBlock(block);
        activateBlockEditor(block);
      });
      content.addEventListener('click', () => {
        selectBlock(block);
        activateBlockEditor(block);
      });
      content.addEventListener('input', syncOutput);
    };

    const insertBlock = (block, before) => {
      if (before instanceof Element && before.parentNode === canvas) canvas.insertBefore(block, before);
      else if (before === null) canvas.append(block);
      else if (typeof before === 'undefined' && selectedBlock && canvas.contains(selectedBlock)) selectedBlock.after(block);
      else canvas.append(block);
      initializeBlock(block);
      selectBlock(block);
      syncOutput();
      setTimeout(() => block.querySelector('[data-nl-content]')?.focus(), 0);
    };

    const destroyBlockEditor = (block) => {
      if (activeEditorBlock !== block) return;
      if (activeEditor) activeEditor.remove();
      toolbarContainer?.querySelectorAll(':scope > .tox').forEach((toolbar) => toolbar.remove());
      activeEditor = null;
      activeEditorBlock = null;
      if (toolbarHint) toolbarHint.hidden = false;
    };

    const template = document.createElement('template');
    template.innerHTML = output.value.trim() || '<p>Hier den Newslettertext eingeben.</p>';
    Array.from(template.content.childNodes).forEach((node) => {
      if (node.nodeType === Node.TEXT_NODE) {
        const text = node.textContent.trim();
        if (!text) return;
        const block = createBlock(`<p>${escapeHtml(text)}</p>`, 'text');
        canvas.append(block);
        initializeBlock(block);
        return;
      }
      if (!(node instanceof HTMLElement)) return;
      const block = createBlock(node.outerHTML, classifyMarkup(node));
      canvas.append(block);
      initializeBlock(block);
    });
    if (!canvas.children.length) {
      const block = createBlock('<p>Hier den Newslettertext eingeben.</p>', 'text');
      canvas.append(block);
      initializeBlock(block);
    }
    selectBlock(canvas.querySelector(':scope > [data-nl-block]'));

    const addStandardBlock = async (type, before) => {
      if (type === 'text') insertBlock(createBlock('<p>Hier den Text eingeben.</p>', 'text'), before);
      if (type === 'heading') insertBlock(createBlock('<h2>Neue Überschrift</h2>', 'heading'), before);
      if (type === 'divider') insertBlock(createBlock('<hr>', 'divider'), before);
      if (type === 'image') {
        pendingInsertBefore = before;
        imageInput?.click();
      }
      if (type === 'button') {
        const label = await window.KulturhusDialog.prompt('Welcher Text soll auf der Schaltfläche stehen?', 'Mehr erfahren');
        if (label === null || !label.trim()) return;
        const url = await window.KulturhusDialog.prompt('Wohin soll die Schaltfläche führen? Bitte die vollständige Adresse eingeben.', 'https://www.kulturhus.de/');
        if (url === null) return;
        if (!/^(https?:\/\/|mailto:|\/(?!\/))/i.test(url.trim())) {
          await window.KulturhusDialog.alert('Bitte eine gültige Internetadresse mit https:// eingeben.');
          return;
        }
        insertBlock(createBlock(`<p><a class="kh-newsletter-button" href="${escapeHtml(url.trim())}">${escapeHtml(label.trim())}</a></p>`, 'button'), before);
      }
    };

    composer.querySelectorAll('[data-nl-add]').forEach((button) => button.addEventListener('click', () => {
      addStandardBlock(button.dataset.nlAdd);
    }));

    imageInput?.addEventListener('change', async () => {
      const file = imageInput.files?.[0];
      if (!file) return;
      composer.classList.add('is-uploading');
      try {
        const url = await uploadImage(file);
        if(pageContext.signal.aborted)return;
        const suggestedAlt = file.name.replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ');
        const alt = await window.KulturhusDialog.prompt('Kurze Bildbeschreibung für Menschen mit Sehbehinderung:', suggestedAlt) ?? suggestedAlt;
        if(pageContext.signal.aborted)return;
        const caption = await window.KulturhusDialog.prompt('Bildunterschrift (kann leer bleiben):', '') ?? '';
        if(pageContext.signal.aborted)return;
        const captionHtml = caption.trim() ? `<figcaption><p>${escapeHtml(caption.trim())}</p></figcaption>` : '';
        insertBlock(createBlock(`<figure class="kh-newsletter-image"><img src="${escapeHtml(url)}" alt="${escapeHtml(alt.trim())}">${captionHtml}</figure>`, 'image'), pendingInsertBefore);
      } catch (error) {
        if(pageContext.signal.aborted)return;
        await window.KulturhusDialog.alert(error.message || 'Das Bild konnte nicht hochgeladen werden.');
      } finally {
        imageInput.value = '';
        pendingInsertBefore = undefined;
        composer.classList.remove('is-uploading');
      }
    });

    const addEventBlock = (button, before) => {
      const image = button.dataset.image ? `<img src="${escapeHtml(button.dataset.image)}" alt="${escapeHtml(button.dataset.title)}">` : '';
      const venue = button.dataset.venue ? `<p><strong>Ort:</strong> ${escapeHtml(button.dataset.venue)}</p>` : '';
      const summary = button.dataset.summary ? `<p>${escapeHtml(button.dataset.summary)}</p>` : '';
      const markup = `<figure class="kh-newsletter-event">${image}<figcaption><p><strong>${escapeHtml(button.dataset.date)}</strong></p><h2>${escapeHtml(button.dataset.title)}</h2>${venue}${summary}<p><a href="${escapeHtml(button.dataset.url)}">Weitere Informationen</a></p></figcaption></figure>`;
      insertBlock(createBlock(markup, 'event'), before);
    };

    composer.querySelectorAll('[data-nl-event]').forEach((button) => button.addEventListener('click', () => {
      addEventBlock(button);
    }));

    const draggableSources = composer.querySelectorAll('[data-nl-add], [data-nl-event]');
    draggableSources.forEach((source) => {
      source.draggable = true;
      source.title = `${source.textContent.trim()} – anklicken oder in die Mail ziehen`;
      source.addEventListener('dragstart', (event) => {
        draggedSource = source;
        draggingBlock = null;
        source.classList.add('is-source-dragging');
        canvas.classList.add('is-drop-ready');
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', source.dataset.nlEvent !== undefined ? 'event' : (source.dataset.nlAdd || 'block'));
        if (dragStatus) dragStatus.textContent = 'Baustein wird verschoben. In der Mail an der gelben Einfügelinie loslassen.';
      });
      source.addEventListener('dragend', () => {
        source.classList.remove('is-source-dragging');
        draggedSource = null;
        dropMarker.remove();
        canvas.classList.remove('is-drop-ready', 'is-drop-target');
        if (dragStatus) dragStatus.textContent = '';
      });
    });

    canvas.addEventListener('click', (event) => {
      const actionButton = event.target.closest('[data-nl-block-action]');
      const block = event.target.closest('[data-nl-block]');
      if (!block || !canvas.contains(block)) return;
      selectBlock(block);
      if (!actionButton) return;
      const action = actionButton.dataset.nlBlockAction;
      if (action === 'align-left' || action === 'align-center' || action === 'align-right') {
        const content = block.querySelector('[data-nl-content]');
        const alignment = action.replace('align-', '');
        const targets = content ? Array.from(content.querySelectorAll('p,h1,h2,h3,h4,h5,h6,div,blockquote,figcaption,td,th')) : [];
        targets.forEach((target) => { target.style.textAlign = alignment; });
        activeEditor?.fire('change');
        syncOutput();
        return;
      }
      if (action === 'up' && block.previousElementSibling) canvas.insertBefore(block, block.previousElementSibling);
      if (action === 'down' && block.nextElementSibling) block.nextElementSibling.after(block);
      if (action === 'duplicate') {
        const content = block.querySelector('[data-nl-content]');
        const duplicate = createBlock(serializeContent(content), block.dataset.nlBlock || 'text');
        block.after(duplicate);
        initializeBlock(duplicate);
        selectBlock(duplicate);
      }
      if (action === 'delete') {
        const replacement = block.nextElementSibling || block.previousElementSibling;
        destroyBlockEditor(block);
        block.remove();
        selectBlock(replacement);
      }
      if (action === 'smaller' || action === 'larger') {
        const content = block.querySelector('[data-nl-content]');
        const target = block.dataset.nlBlock === 'button'
          ? content?.querySelector('a')
          : content?.firstElementChild;
        if (target) {
          const sizes = [12, 14, 16, 18, 22, 28, 36, 44];
          const current = parseFloat(target.style.fontSize || window.getComputedStyle(target).fontSize) || 16;
          const next = action === 'larger'
            ? (sizes.find((size) => size > current) || sizes[sizes.length - 1])
            : ([...sizes].reverse().find((size) => size < current) || sizes[0]);
          target.style.fontSize = `${next}px`;
        }
      }
      syncOutput();
    });

    canvas.addEventListener('input', (event) => {
      const colorInput = event.target.closest('[data-nl-block-color]');
      if (!colorInput) return;
      const block = colorInput.closest('[data-nl-block]');
      const content = block?.querySelector('[data-nl-content]');
      const target = block?.dataset.nlBlock === 'button'
        ? content?.querySelector('a')
        : content?.firstElementChild;
      if (target && /^#[0-9a-f]{6}$/i.test(colorInput.value)) {
        target.style.color = colorInput.value;
        syncOutput();
      }
    });

    canvas.addEventListener('dragstart', (event) => {
      const handle = event.target.closest('[data-nl-drag]');
      if (!handle) return;
      draggedSource = null;
      draggingBlock = handle.closest('[data-nl-block]');
      draggingBlock?.classList.add('is-dragging');
      canvas.classList.add('is-drop-ready');
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', 'newsletter-block');
      if (dragStatus) dragStatus.textContent = 'Abschnitt wird verschoben. An der gelben Einfügelinie loslassen.';
    });
    canvas.addEventListener('dragover', (event) => {
      if (!draggingBlock && !draggedSource) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = draggingBlock ? 'move' : 'copy';
      canvas.classList.add('is-drop-target');
      const blocks = Array.from(canvas.querySelectorAll(':scope > [data-nl-block]')).filter((block) => block !== draggingBlock);
      const before = blocks.find((block) => {
        const bounds = block.getBoundingClientRect();
        return event.clientY < bounds.top + bounds.height / 2;
      }) || null;
      if (before) canvas.insertBefore(dropMarker, before);
      else canvas.append(dropMarker);
    });
    canvas.addEventListener('drop', (event) => {
      if (!draggingBlock && !draggedSource) return;
      event.preventDefault();
      const before = dropMarker.nextElementSibling instanceof Element ? dropMarker.nextElementSibling : null;
      if (draggingBlock) {
        canvas.insertBefore(draggingBlock, before);
        draggingBlock.classList.remove('is-dragging');
        selectBlock(draggingBlock);
        syncOutput();
      } else if (draggedSource?.matches('[data-nl-event]')) {
        addEventBlock(draggedSource, before);
      } else if (draggedSource?.matches('[data-nl-add]')) {
        addStandardBlock(draggedSource.dataset.nlAdd, before);
      }
      draggedSource?.classList.remove('is-source-dragging');
      draggingBlock = null;
      draggedSource = null;
      dropMarker.remove();
      canvas.classList.remove('is-drop-ready', 'is-drop-target');
      if (dragStatus) dragStatus.textContent = 'Baustein wurde eingefügt.';
    });
    canvas.addEventListener('dragend', () => {
      draggingBlock?.classList.remove('is-dragging');
      draggingBlock = null;
      dropMarker.remove();
      canvas.classList.remove('is-drop-ready', 'is-drop-target');
      if (dragStatus) dragStatus.textContent = '';
    });

    composer.querySelectorAll('[data-nl-view]').forEach((button) => button.addEventListener('click', () => {
      const mobile = button.dataset.nlView === 'mobile';
      workspace.classList.toggle('is-mobile', mobile);
      workspace.classList.toggle('is-desktop', !mobile);
      composer.querySelectorAll('[data-nl-view]').forEach((item) => {
        const active = item === button;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-pressed', active ? 'true' : 'false');
      });
    }));

    const updatePreviewHeading = () => {
      const subject = subjectInput?.value.trim() || 'Kulturhus Newsletter';
      let title = 'Newsletter';
      let subtitle = subject;
      const quarterMatch = subject.match(/^Kulturhus:\s*Termine\s+(.+?)\s+bis\s+(.+?)\s+(\d{4})$/i);
      const monthMatch = subject.match(/^Kulturhus\s+im\s+(.+?)\s+(\d{4})$/i);
      const monthNames = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
      if (quarterMatch) {
        const monthIndex = monthNames.findIndex((month) => month.toLowerCase() === quarterMatch[1].toLowerCase());
        if (monthIndex >= 0) title = `${Math.floor(monthIndex / 3) + 1}. Quartal`;
        subtitle = `${quarterMatch[1]} bis ${quarterMatch[2]} ${quarterMatch[3]}`;
      } else if (monthMatch) {
        title = `${monthMatch[1]} ${monthMatch[2]}`;
      }
      const titleElement = composer.querySelector('[data-nl-preview-title]');
      const subtitleElement = composer.querySelector('[data-nl-preview-subtitle]');
      if (titleElement) titleElement.textContent = title;
      if (subtitleElement) subtitleElement.textContent = subtitle;
    };
    subjectInput?.addEventListener('input', updatePreviewHeading);
    updatePreviewHeading();

    composer.querySelector('[data-nl-open-preview]')?.addEventListener('click',()=>{
      syncOutput();const dialog=document.createElement('dialog');dialog.className='native-newsletter native-newsletter-preview';
      const close=document.createElement('button');close.type='button';close.textContent='Vorschau schließen';close.addEventListener('click',()=>dialog.close());
      const mail=composer.querySelector('.kh-builder-mail').cloneNode(true);
      mail.querySelectorAll('.kh-builder-block-controls').forEach(node=>node.remove());
      mail.querySelectorAll('[contenteditable]').forEach(node=>node.contentEditable='false');
      mail.querySelectorAll('.is-selected').forEach(node=>node.classList.remove('is-selected'));
      dialog.append(close,mail);dialog.addEventListener('close',()=>dialog.remove());document.body.append(dialog);dialog.showModal();
    });

    canvas.addEventListener('click', event=>{if(event.target.closest('a'))event.preventDefault();});
    form.addEventListener('submit', syncOutput);
    syncOutput();
  });
})();

}
initEditorialnewsletter_builder();
});
