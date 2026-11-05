(() => {
  "use strict";
  const contentText = source => {
    const read=window.KulturhusInterface?.text;if(!read)return source;
    for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}
    return source;
  };
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  const mounted = new WeakSet();
  const uploads = new Set();
  const stopUploads = (scope) => {
    for (const job of uploads)
      if (!scope || scope.contains(job.root)) job.controller.abort();
  };
  window.addEventListener("pagehide", () => stopUploads());
  new MutationObserver(() => {
    for (const job of uploads)
      if (!job.root.isConnected) job.controller.abort();
  }).observe(document.documentElement, { childList: true, subtree: true });
  const labels = {
    richtext: contentText("Text"),
    text: contentText("Text"),
    heading: contentText("Überschrift"),
    image: contentText("Bild"),
    button: contentText("Schaltfläche"),
    divider: contentText("Trennlinie"),
    event: contentText("Veranstaltung"),
    slide: contentText("Story-Folie"),
  };
  const definitions = {
    richtext:[{key:"html",label:contentText("Text"),multiline:true,max:10000,required:true}],
    text: [
      {
        key: "text",
        label: contentText("Text"),
        multiline: true,
        max: 10000,
        required: true,
      },
    ],
    heading: [{ key: "text", label: contentText("Überschrift"), max: 300, required: true }],
    image: [
      {
        key: "media_ref",
        label: contentText("Bild aus der Galerie"),
        max: 32,
        pattern: "[a-f0-9]{32}",
        required: true,
        hint: contentText("Ein eigenes Foto in der Bildauswahl wählen."),
      },
      {
        key: "alt",
        label: contentText("Bildbeschreibung"),
        max: 500,
        required: true,
        hint: contentText("Beschreiben, was auf dem Bild zu sehen ist."),
      },
    ],
    button: [
      { key: "text", label: contentText("Beschriftung"), max: 100, required: true },
      {
        key: "url",
        label: contentText("Zieladresse (HTTPS)"),
        max: 2000,
        input: "url",
        pattern: "https://.*",
        required: true,
      },
    ],
    divider: [],
    event: [
      { key: "title", label: contentText("Veranstaltungstitel"), max: 300, required: true },
      {
        key: "date",
        label: contentText("Datum und Uhrzeit"),
        max: 100,
        required: true,
        hint: contentText("Zum Beispiel: 15. Oktober 2026, 19:30 Uhr"),
      },
      { key: "venue", label: contentText("Veranstaltungsort"), max: 300 },
      { key: "text", label: contentText("Beschreibung"), multiline: true, max: 5000 },
      { key: "media_ref", label: contentText("Veranstaltungsfoto"), max: 32, pattern: "[a-f0-9]{32}" },
      { key: "url", label: contentText("Link zur Veranstaltung"), max: 2000, input: "url", pattern: "https://.*" },
    ],
    slide: [
      { key: "title", label: contentText("Folientitel"), max: 200, required: true },
      { key: "text", label: contentText("Folientext"), multiline: true, max: 1200 },
      {
        key: "media_ref",
        label: contentText("Bild aus der Galerie (optional)"),
        max: 32,
        pattern: "[a-f0-9]{32}",
        hint: contentText("Ohne Bild bleibt die Folie eine Textfolie."),
      },
    ],
  };

  const el = (tag, className, text) => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  };

  function initialize(root) {
    if (mounted.has(root)) return;
    const hidden = root.querySelector("[data-editor-value]");
    const list = root.querySelector("[data-editor-list]");
    const status = root.querySelector("[data-editor-status]");
    const count = root.querySelector("[data-editor-count]");
    const undo = root.querySelector("[data-editor-undo]");
    if (!hidden || !list || !status || !count || !undo) return;
    const newsletter = root.hasAttribute("data-editor-newsletter");
    const eventSource = root.querySelector("[data-editor-events]");
    const upcoming = eventSource ? JSON.parse(eventSource.content.textContent) : [];
    const carousel = root.dataset.khEditor === "carousel";
    const story = carousel || root.dataset.khEditor === "story";
    const catalogue = root.querySelector("[data-editor-media]");
    const photo = (id, alt) => {
      const choice = catalogue?.content.querySelector(
        `[data-media-id="${/^[a-f0-9]{32}$/.test(id) ? id : ""}"] img`,
      );
      if (!choice) return null;
      const image = choice.cloneNode(true);
      image.alt = alt || choice.alt;
      image.loading = "lazy";
      if(newsletter){const url=new URL(image.src,location.href);if(url.searchParams.get("variant")==="thumb")url.searchParams.set("variant","medium");else url.pathname=url.pathname.replace(/\/thumb$/,"/medium");image.src=url.href;}
      return image;
    };
    if(newsletter){
      const form=root.closest('form'),title=form?.querySelector('input[name=title]'),summary=form?.querySelector('input[name=summary]'),rich=form?.querySelector('[data-kh-richtext]');
      if(title){root.querySelector('[data-live-mail-title]').append(title.closest('label'));title.placeholder=contentText('Titel');}
      if(summary){root.querySelector('[data-live-mail-summary]').append(summary.closest('label'));summary.placeholder=contentText('Einleitung');}
      if(rich)root.querySelector('[data-live-mail-intro]').append(rich);
      const workspace=root.querySelector('[data-newsletter-workspace]'),layout=el('div','newsletter-live-layout'),palette=el('aside','newsletter-live-palette');
      for(const child of [...root.children])if(child!==workspace&&!child.matches('h3,input,template,.newsletter-live-tools'))palette.append(child);
      workspace.before(layout);layout.append(palette,workspace);
      for(const button of root.querySelectorAll('[data-mail-size]'))button.addEventListener('click',()=>{root.querySelector('[data-newsletter-workspace]').classList.toggle('is-mobile',button.dataset.mailSize==='mobile');for(const other of root.querySelectorAll('[data-mail-size]'))other.setAttribute('aria-pressed',String(other===button));});
    }
    const maximum = story ? 10 : 100;
    const unit = carousel ? contentText("Bild") : story ? contentText("Folie") : contentText("Baustein");
    const navigation = root.querySelector("[data-editor-navigation]"),
      selection = root.querySelector("[data-editor-select]");
    let selectedIndex = 0;
    let items;
    try {
      items = JSON.parse(hidden.value);
      if (
        !Array.isArray(items) ||
        items.length > maximum ||
        items.some(
          (item) =>
            !item ||
            typeof item !== "object" ||
            Array.isArray(item) ||
            (!story && !definitions[item.type]),
        )
      )
        throw new Error("Invalid editor value");
      items = items.map((item) => {
        const type = story ? "slide" : item.type;
        const result = story ? {} : { type };
        definitions[type].forEach((field) => {
          if (
            item[field.key] !== undefined &&
            typeof item[field.key] !== "string"
          )
            throw new Error("Invalid editor field");
          result[field.key] = item[field.key] || "";
        });
        return result;
      });
    } catch {
      status.textContent =
        contentText("Die gespeicherten Bausteine konnten nicht geöffnet werden. Bitte die Seite neu laden; die gespeicherten Daten bleiben erhalten.");
      status.classList.add("kh-editor-error");
      return;
    }
    mounted.add(root);
    let removed = null;
    const addButtons = [...root.querySelectorAll("[data-editor-add]")];
    const announce = (message) => {
      status.textContent = message;
    };
    const signal = () => {
      hidden.value = JSON.stringify(items);
      // Native document-level dirty tracking and FormData observe this exact field.
      hidden.dispatchEvent(new Event("input", { bubbles: true }));
      hidden.dispatchEvent(new Event("change", { bubbles: true }));
    };
    const upload = root.querySelector("[data-editor-upload]");
    const cancelUpload = root.querySelector("[data-editor-upload-cancel]");
    let uploadJob = null;
    if (upload && cancelUpload) {
      cancelUpload.addEventListener("click", () =>
        uploadJob?.controller.abort(),
      );
      root.closest("form")?.addEventListener(
        "submit",
        (event) => {
          if (uploadJob) {
            event.preventDefault();
            event.stopImmediatePropagation();
          }
        },
        true,
      );
      upload.addEventListener("change", async () => {
        if (uploadJob || !upload.files.length) return;
        const files = [...upload.files];
        if (files.length > 50 || files.length > maximum - items.length) {
          announce(
            contentFormat("Bis zu {count} weitere Fotos auswählen.",{count:Math.min(50,maximum-items.length)}),
          );
          upload.value = "";
          return;
        }
        const form = root.closest("form"),
          controller = new AbortController();
        if (!form || !catalogue) return;
        const job = { root, controller };
        uploadJob = job;
        uploads.add(job);
        const buttons = [
          ...form.querySelectorAll(
            "button[type=submit],button:not([type]),input[type=submit]",
          ),
        ].map((button) => [button, button.disabled]);
        buttons.forEach(([button]) => {
          button.disabled = true;
        });
        upload.disabled = true;
        cancelUpload.hidden = false;
        let added = 0,
          failed = 0,
          uncertain = false,
          requestPending = false;
        const rejectedNames = [];
        try {
          for (const file of files) {
            if (
              controller.signal.aborted ||
              !root.isConnected ||
              items.length >= maximum
            )
              break;
            announce(
              contentFormat("Foto {index} von {count} wird hochgeladen …",{index:added+failed+1,count:files.length}),
            );
            const body = new FormData();
            for (const name of ["csrf", "action", "id"]) {
              const field = form.elements.namedItem(name);
              if (field instanceof HTMLInputElement)
                body.set(name, field.value);
            }
            body.set("operation", "communication-photo-upload");
            body.set("photo", file, file.name);
            requestPending = true;
            const response = await fetch(location.href, {
              method: "POST",
              body,
              headers: { Accept: "application/json" },
              credentials: "same-origin",
              signal: controller.signal,
            });
            if (!response.ok) {
              if (
                response.status >= 500 ||
                response.status === 401 ||
                response.status === 403
              ) {
                uncertain = true;
                break;
              }
              failed++;
              requestPending = false;
              if (rejectedNames.length < 3)
                rejectedNames.push(file.name.slice(0, 80));
              continue;
            }
            const data = await response.json(),
              image = data.image;
            if (
              !data.ok ||
              !image ||
              !/^[a-f0-9]{32}$/.test(image.id) ||
              typeof image.alt !== "string" ||
              typeof image.url !== "string"
            ) {
              uncertain = true;
              break;
            }
            const url = new URL(image.url, location.origin);
            if (
              url.origin !== location.origin ||
              url.username ||
              url.password
            ) {
              uncertain = true;
              break;
            }
            if (!root.isConnected || controller.signal.aborted) {
              uncertain = true;
              break;
            }
            const choice = el(
              "button",
              "btn btn-outline theme-secondary secondary kh-media-choice",
            );
            choice.type = "button";
            choice.dataset.mediaId = image.id;
            const preview = el("img");
            preview.src = url.href;
            preview.alt = image.alt;
            preview.loading = "lazy";
            preview.decoding = "async";
            choice.append(preview, el("span", "", image.alt));
            catalogue.content.append(choice);
            items.push(
              story
                ? { title: "", text: "", media_ref: image.id }
                : { type: "image", media_ref: image.id, alt: image.alt },
            );
            added++;
            requestPending = false;
            render();
            signal();
          }
        } catch (error) {
          if (error.name !== "AbortError" || requestPending) uncertain = true;
        } finally {
          uploads.delete(job);
          uploadJob = null;
          upload.disabled = false;
          upload.value = "";
          cancelUpload.hidden = true;
          buttons.forEach(([button, disabled]) => {
            button.disabled = disabled;
          });
          if (root.isConnected) {
            announce(
              contentFormat("{count} {unit} eingefügt.",{count:added,unit:contentText(added===1?contentText("Foto"):contentText("Fotos"))})+(failed?" "+contentFormat("{count} nicht hochgeladen: {names}.",{count:failed,names:rejectedNames.join(" · ")}):"")+(controller.signal.aborted?" "+contentText("Weitere Uploads gestoppt."):"")+(uncertain?" "+contentText("Letzten Upload in der Mediathek prüfen."):""),
            );
          }
        }
      });
    }

    function updatePreview(card, item, type) {
      const preview = card.querySelector("[data-editor-preview]");
      preview.replaceChildren();
      const text = (tag, value, className) =>
        preview.append(el(tag, className || "", value));
      const editable=(tag,value,key,className='')=>{
        const n=el(tag,className,value);if(newsletter){n.contentEditable='true';n.setAttribute('role','textbox');n.setAttribute('aria-label',definitions[type].find(d=>d.key===key)?.label||contentText('Text'));n.dataset.liveBlockField=key;
          n.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));});
          n.addEventListener('input',()=>{item[key]=n.innerText;const input=card.querySelector('[data-editor-field="'+key+'"]');if(input)input.value=item[key];signal();});}
        preview.append(n);return n;
      };
      if(type==='richtext'){
        const toolbar=el('div','newsletter-inline-toolbar');for(const [label,command]of[['Fett','bold'],['Kursiv','italic'],['Unterstreichen','underline'],['Liste','insertUnorderedList'],['Nummerierung','insertOrderedList']]){const button=el('button','btn btn-outline theme-secondary',contentText(label));button.type='button';button.addEventListener('mousedown',e=>e.preventDefault());button.addEventListener('click',()=>{surface.focus();document.execCommand(command,false);surface.dispatchEvent(new Event('input',{bubbles:true}));});toolbar.append(button);}
        const surface=el('div','kh-richtext newsletter-block-richtext');surface.innerHTML=item.html||'<p>Text eingeben …</p>';surface.contentEditable='true';surface.setAttribute('role','textbox');surface.setAttribute('aria-label',contentText('Text'));surface.addEventListener('paste',event=>{event.preventDefault();document.execCommand('insertText',false,event.clipboardData.getData('text/plain'));});surface.addEventListener('input',()=>{item.html=surface.innerHTML;const input=card.querySelector('[data-editor-field=html]');if(input)input.value=item.html;signal();});preview.append(toolbar,surface);
      }else if (type === "divider") preview.append(el("hr"));
      else if (type === "heading") editable("h4", item.text || contentText("Überschrift"),"text");
      else if (type === "text")
        editable("p", item.text || contentText("Hier erscheint Ihr Text."),"text");
      else if (type === "image") {
        const image = photo(item.media_ref, item.alt);
        if (image) preview.append(image);
        else text("p", contentText("Noch kein verfügbares Bild ausgewählt."));
        text("p", item.alt || contentText("Bildbeschreibung"));
      } else if (type === "button") {
        editable("span", item.text || contentText("Schaltfläche"), "text", "kh-editor-button-preview");
        text("small", item.url || "Zieladresse");
      } else {
        if (type === "slide") {
          const image = photo(item.media_ref, contentText("Gewähltes Storyfoto"));
          if (image) preview.append(image);
          text("small", carousel ? contentText("Bildinhalt · finales Layout nach dem Erzeugen") : contentText("Folieninhalt · finales Layout nach dem Erzeugen"));
        }
        editable(
          "h4",
          item.title || (type === "slide" ? (carousel ? contentText("Bildtitel") : contentText("Folientitel")) : contentText("Veranstaltung")),"title",
        );
        if (type === "event") {
          const image = item.media_ref ? photo(item.media_ref, item.title) : null;
          if (image) preview.prepend(image);
          editable("p",item.date||"","date");editable("p",item.venue||"","venue");
        }
        editable("p", item.text || "","text");
        if(type === "event" && item.url) {const link=el("a","communication-button",contentText("Weitere Informationen"));link.href=item.url;link.addEventListener("click",e=>e.preventDefault());preview.append(link);}
        if (type === "slide" && item.media_ref)
          text("small", contentText("Bild zugeordnet"));
      }
    }

    function field(item, definition, index) {
      const wrapper = el("div", "kh-editor-field");
      const id = `${root.id}-item-${index}-${definition.key}`;
      const label = el("label", "", carousel ? ({title:contentText("Bildtitel"),text:contentText("Bildtext")}[definition.key] || definition.label) : definition.label);
      label.htmlFor = id;
      const input = el(definition.multiline ? "textarea" : "input");
      input.id = id;
      input.dataset.editorField = definition.key;
      if (!definition.multiline) input.type = definition.input || "text";
      else input.rows = story ? 4 : 5;
      input.value = item[definition.key];
      input.maxLength = definition.max;
      input.required = Boolean(definition.required);
      input.setCustomValidity(
        new TextEncoder().encode(input.value).length > definition.max
          ? contentText("Dieser Text ist für das Feld zu lang. Bitte kürzen.")
          : "",
      );
      if (definition.pattern) input.pattern = definition.pattern;
      if (definition.key === "media_ref") {
        input.autocomplete = "off";
        input.spellcheck = false;
      }
      wrapper.append(label, input);
      if (definition.key === "media_ref" && catalogue) {
        const choose = el("button", "secondary", contentText("Foto auswählen"));
        choose.type = "button";
        choose.addEventListener("click", () => {
          const dialog = el("dialog", "kh-media-dialog"),
            heading = el("h2", "", contentText("Eigene Fotos"));
          heading.id = id + "-gallery-title";
          dialog.setAttribute("aria-labelledby", heading.id);
          const close = el("button", "secondary", contentText("Schließen"));
          close.type = "button";
          close.addEventListener("click", () => dialog.close());
          const searchLabel = el("label", "", contentText("Fotos durchsuchen")),
            search = el("input");
          search.type = "search";
          searchLabel.append(search);
          const choices = el("div", "kh-media-grid");
          choices.append(catalogue.content.cloneNode(true));
          if (!choices.childElementCount)
            choices.append(el("p", "", contentText("Noch keine Fotos vorhanden.")));
          choices.addEventListener("click", (event) => {
            const button = event.target.closest("[data-media-id]");
            if (!button) return;
            input.value = button.dataset.mediaId;
            if (!story && !item.alt) {
              item.alt = button.querySelector("img")?.alt || "";
              const alt = wrapper
                .closest("[data-editor-index]")
                ?.querySelector("[data-editor-field=alt]");
              if (alt) alt.value = item.alt;
            }
            input.dispatchEvent(new Event("input", { bubbles: true }));
            dialog.close();
          });
          search.addEventListener("input", () => {
            for (const button of choices.children)
              button.hidden = !button.textContent
                .toLocaleLowerCase()
                .includes(search.value.toLocaleLowerCase());
          });
          dialog.append(heading, close, searchLabel, choices);
          dialog.addEventListener(
            "close",
            () => {
              dialog.remove();
              choose.focus();
            },
            { once: true },
          );
          root.append(dialog);
          dialog.showModal();
          search.focus();
        });
        wrapper.append(choose);
        input.hidden = true;
        input.required = false;
      }
      if (definition.hint) {
        const hint = el("small", "hint", definition.hint);
        hint.id = `${id}-hint`;
        input.setAttribute("aria-describedby", hint.id);
        wrapper.append(hint);
      }
      return wrapper;
    }

    function render(focusIndex = null, focusAction = null) {
      const active = document.activeElement;
      const caret =
        focusIndex === null &&
        list.contains(active) &&
        active?.dataset.editorField
          ? {
              key: active.dataset.editorField,
              start: active.selectionStart,
              end: active.selectionEnd,
            }
          : null;
      if (focusIndex !== null) selectedIndex = focusIndex;
      selectedIndex = Math.max(0, Math.min(selectedIndex, items.length - 1));
      list.replaceChildren();
      if (items.length === 0)
        list.append(
          el(
            "p",
            "kh-editor-empty",
            story
              ? (carousel ? contentText("Noch keine Bilder. Mit „Bild hinzufügen“ beginnen.") : contentText("Noch keine Folien. Mit „Folie hinzufügen“ beginnen."))
              : contentText("Noch keine Bausteine. Oben den gewünschten Baustein auswählen."),
          ),
        );
      if (navigation && selection) {
        selection.replaceChildren();
        items.forEach((item, index) => {
          const title = (
              item.title ||
              item.text ||
              item.alt ||
              labels[story ? "slide" : item.type]
            )
              .replace(/\s+/g, " ")
              .slice(0, 70),
            option = el("option", "", `${index + 1}. ${title}`);
          option.value = String(index);
          selection.append(option);
        });
        selection.value = String(selectedIndex);
        navigation.hidden = items.length === 0;
        navigation
          .querySelectorAll("[data-editor-step]")
          .forEach(
            (button) =>
              (button.disabled =
                Number(button.dataset.editorStep) < 0
                  ? selectedIndex === 0
                  : selectedIndex >= items.length - 1),
          );
      }
      items.forEach((item, index) => {
        if (!newsletter && index !== selectedIndex) return;
        const type = story ? "slide" : item.type;
        const card = el("fieldset", "kh-editor-card");
        card.dataset.editorIndex = String(index);
        const legend = el("legend", "", `${index + 1}. ${carousel ? contentText("Karussell-Bild") : labels[type]}`);
        legend.tabIndex = -1;
        card.append(legend);
        const controls = el("div", "kh-editor-controls");
        controls.setAttribute("role", "group");
        controls.setAttribute(
          "aria-label",
          contentFormat("{unit} {index} verschieben oder entfernen",{unit,index:index+1}),
        );
        [
          ["up", contentText("↑ Nach oben"), index === 0],
          ["down", contentText("↓ Nach unten"), index === items.length - 1],
          ["remove", contentText("Entfernen"), false],
        ].forEach(([action, label, disabled]) => {
          const button = el("button", "secondary", label);
          button.type = "button";
          button.dataset.editorAction = action;
          button.disabled = disabled;
          button.setAttribute(
            "aria-label",
            `${unit} ${index + 1}: ${label.replace(/[↑↓] /, "")}`,
          );
          controls.append(button);
        });
        card.append(controls);
        const body = el(
          "div",
          story ? "kh-editor-body kh-editor-story-body" : "kh-editor-body",
        );
        const inputs = el("div", "kh-editor-fields");
        definitions[type].forEach((definition) =>
          inputs.append(field(item, definition, index)),
        );
        if (type === "divider")
          inputs.append(
            el(
              "p",
              "hint",
              contentText("Die Trennlinie gliedert den Beitrag zwischen zwei Abschnitten."),
            ),
          );
        const preview = el(
          "div",
          story
            ? "kh-editor-preview kh-editor-story-preview"
            : "kh-editor-preview",
        );
        preview.dataset.editorPreview = "";
        preview.setAttribute("aria-label", contentFormat("{unit} {index}: Vorschau",{unit,index:index+1}));
        const previewPanel = el("details", "kh-editor-preview-panel");
        previewPanel.open = type === "image" || newsletter;
        previewPanel.append(el("summary", "", "Vorschau"), preview);
        if(newsletter){const edit=el("details","kh-newsletter-edit");edit.append(el("summary","",contentText("Weitere Angaben")),inputs);body.append(preview,edit);}else body.append(inputs, previewPanel);
        card.append(body);
        list.append(card);
        updatePreview(card, item, type);
      });
      count.textContent = `${items.length} / ${maximum} ${carousel ? contentText("Bilder") : story ? contentText("Folien") : contentText("Bausteine")}`;
      addButtons.forEach((button) => {
        button.disabled = items.length >= maximum;
      });
      undo.hidden = removed === null;
      undo.disabled = items.length >= maximum;
      if (focusIndex !== null) {
        const card = list.querySelector(`[data-editor-index="${focusIndex}"]`);
        const preferred = focusAction
          ? card?.querySelector(
              `[data-editor-action="${focusAction}"]:not(:disabled)`,
            )
          : card?.querySelector("input, textarea");
        (preferred || card?.querySelector("legend") || addButtons[0])?.focus({
          preventScroll: false,
        });
      } else if (caret) {
        const field = list.querySelector(`[data-editor-field="${caret.key}"]`);
        if (field && !field.hidden) {
          field.focus({ preventScroll: true });
          if (caret.start !== null)
            field.setSelectionRange(caret.start, caret.end);
        }
      }
    }

    const updateField = (event) => {
      if (
        !(
          event.target instanceof HTMLInputElement ||
          event.target instanceof HTMLTextAreaElement
        ) ||
        !event.target.dataset.editorField
      )
        return;
      const card = event.target.closest("[data-editor-index]");
      const index = Number(card.dataset.editorIndex);
      const item = items[index];
      const key = event.target.dataset.editorField;
      if (!item || !Object.prototype.hasOwnProperty.call(item, key)) return;
      item[key] = event.target.value;
      const byteLength = new TextEncoder().encode(event.target.value).length;
      event.target.setCustomValidity(
        byteLength > event.target.maxLength
          ? contentText("Dieser Text ist für das Feld zu lang. Bitte kürzen.")
          : "",
      );
      updatePreview(card, item, story ? "slide" : item.type);
      if (selection?.options[index])
        selection.options[index].textContent =
          `${index + 1}. ${(item.title || item.text || item.alt || labels[story ? "slide" : item.type]).replace(/\s+/g, " ").slice(0, 70)}`;
      signal();
    };
    root.addEventListener("input", updateField);
    root.addEventListener("change", updateField);
    selection?.addEventListener("input", (event) => event.stopPropagation());
    selection?.addEventListener("change", (event) => {
      event.stopPropagation();
      selectedIndex = Number(selection.value);
      render();
      announce(contentFormat("{unit} {index} von {count}.",{unit,index:selectedIndex+1,count:items.length}));
    });
    root.closest("form")?.addEventListener(
      "submit",
      (event) => {
        if(event.submitter?.name==='save_review')return;
        if (root.closest("[data-story-panel], [data-carousel-panel]")?.hidden) return;
        for (let index = 0; index < items.length; index++) {
          const item = items[index],
            type = story ? "slide" : item.type;
          for (const definition of definitions[type]) {
            const value = item[definition.key] || "";
            let invalid =
              (definition.required && !value.trim()) ||
              new TextEncoder().encode(value).length > definition.max;
            if (
              value &&
              definition.pattern &&
              !new RegExp(`^(?:${definition.pattern})$`).test(value)
            )
              invalid = true;
            if (value && definition.input === "url") {
              try {
                if (new URL(value).protocol !== "https:") invalid = true;
              } catch {
                invalid = true;
              }
            }
            if (!invalid) continue;
            event.preventDefault();
            event.stopImmediatePropagation();
            render(index);
            const input = list.querySelector(
              `[data-editor-field="${definition.key}"]`,
            );
            announce(contentFormat("{unit} {index}: {field} prüfen.",{unit,index:index+1,field:definition.label}));
            if (input && !input.hidden) {
              input.setCustomValidity(contentText("Dieses Feld prüfen."));
              input.focus();
              input.reportValidity();
            } else input?.parentElement.querySelector("button")?.focus();
            return;
          }
        }
      },
      true,
    );

    root.addEventListener("click", (event) => {
      const button = event.target.closest("button");
      if (!button || !root.contains(button) || button.disabled) return;
      if (button.hasAttribute("data-editor-step")) {
        selectedIndex = Math.max(
          0,
          Math.min(
            items.length - 1,
            selectedIndex + Number(button.dataset.editorStep),
          ),
        );
        render();
        const next = root.querySelector(
          `[data-editor-step="${button.dataset.editorStep}"]`,
        );
        (next?.disabled ? selection : next)?.focus();
        announce(contentFormat("{unit} {index} von {count}.",{unit,index:selectedIndex+1,count:items.length}));
      } else if (button.hasAttribute("data-editor-upcoming")) {
        const known=new Set(items.filter(i=>i.type==='event').map(i=>JSON.stringify([i.title,i.date,i.url||''])));
        const selected=upcoming.filter(i=>!known.has(JSON.stringify([i.title,i.date,i.url||'']))).slice(0,maximum-items.length);
        if(!selected.length){announce(contentText("Die kommenden Veranstaltungen sind bereits enthalten."));return;}
        items.push(...selected.map(i=>JSON.parse(JSON.stringify(i))));render(items.length-selected.length);signal();announce(contentText("Veranstaltungen mit Fotos und Links eingefügt."));
      } else if (button.hasAttribute("data-editor-add")) {
        if (items.length >= maximum) return;
        const type = newsletter && button.dataset.editorAdd==='text' ? 'richtext' : button.dataset.editorAdd;
        if (
          !definitions[type] ||
          (story && type !== "slide") ||
          (!story && type === "slide")
        )
          return;
        if(newsletter && type==='event'){
          const known=new Set(items.filter(i=>i.type==='event').map(i=>JSON.stringify([i.title,i.date,i.url||''])));
          const next=upcoming.find(i=>!known.has(JSON.stringify([i.title,i.date,i.url||''])));
          if(!next){announce(contentText("Die kommenden Veranstaltungen sind bereits enthalten."));return;}
          items.push(JSON.parse(JSON.stringify(next)));render(items.length-1);signal();announce(contentText("Veranstaltung mit Foto und Link eingefügt."));return;
        }
        const item = story ? {} : { type };
        definitions[type].forEach((definition) => {
          item[definition.key] = type==='richtext'?'<p>Text eingeben …</p>':"";
        });
        items.push(item);
        render(items.length - 1);
        signal();
        announce(contentFormat("{unit} hinzugefügt.",{unit:labels[type]}));
      } else if (button.hasAttribute("data-editor-undo")) {
        if (!removed || items.length >= maximum) return;
        const index = Math.min(removed.index, items.length);
        items.splice(index, 0, removed.item);
        removed = null;
        render(index);
        signal();
        announce(contentFormat("{unit} wiederhergestellt.",{unit}));
      } else if (button.dataset.editorAction) {
        const card = button.closest("[data-editor-index]");
        if (!card) return;
        const index = Number(card.dataset.editorIndex);
        if (!Number.isInteger(index) || !items[index]) return;
        const action = button.dataset.editorAction;
        if (action === "remove") {
          removed = { index, item: items.splice(index, 1)[0] };
          render(Math.min(index, items.length - 1));
          signal();
          announce(
            contentFormat("{unit} entfernt. Mit „Entfernen rückgängig machen“ wiederherstellen.",{unit}),
          );
        } else {
          const destination =
            action === "up" ? index - 1 : action === "down" ? index + 1 : -1;
          if (destination < 0 || destination >= items.length) return;
          [items[index], items[destination]] = [
            items[destination],
            items[index],
          ];
          render(destination, action);
          signal();
          announce(contentFormat("{unit} auf Position {position} verschoben.",{unit,position:destination+1}));
        }
      }
    });

    render();
  }

  const mount = (root = document) => {
    if (root instanceof Element && root.matches("[data-kh-editor]"))
      initialize(root);
    root.querySelectorAll("[data-kh-editor]").forEach(initialize);
  };
  window.KulturhusEditor = Object.freeze({ mount, unmount: stopUploads });
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", () => mount(), {
      once: true,
    });
  else mount();
})();
