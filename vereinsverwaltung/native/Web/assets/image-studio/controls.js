/* Accessible HTML layer controls; mutations pass through editor history. */
(() => {
  "use strict";
  function create({
    interfaceText = source => source,
    interfaceFormat,
    root,
    list,
    properties,
    background,
    chosen,
    getDocument,
    photoSources = () => [],
    on,
    change,
    node,
    history,
    signal,
    paint,
  }) {
    const aside = root.querySelector(".studio-controls"),
      tabs = node("div"),
      layerButtons = node("div"),
      panels = new Map();
    let panel = "tools",
      modal = null,
      placeholder = null;
    layerButtons.className="studio-layer-buttons";layerButtons.setAttribute("aria-label",interfaceText("Gestaltungsebene auswählen"));
    on(layerButtons,"click",event=>{const button=event.target.closest("[data-studio-layer]");if(!button)return;list.value=button.dataset.studioLayer;list.dispatchEvent(new Event("change"));show("properties");});
    tabs.className = "studio-tool-tabs";
    tabs.setAttribute("role", "tablist");
    tabs.setAttribute("aria-label", interfaceText("Bildstudio-Werkzeuge"));
    const prefix =
      "studio-tools-" +
      [...crypto.getRandomValues(new Uint32Array(2))]
        .map((n) => n.toString(36))
        .join("-");
    function show(key, focus = false) {
      panel = key;
      for (const [name, element] of panels) {
        element.hidden = name !== key;
        const button = tabs.querySelector("[data-studio-tab=" + name + "]");
        button.setAttribute("aria-selected", String(name === key));
        button.tabIndex = name === key ? 0 : -1;
        if (focus && name === key) button.focus();
      }
      const details = root.querySelector(".studio-export");
      if (details) details.open = key === "export";
    }
    for (const [key, title] of [
      ["tools", "Hinzufügen"],
      ["properties", "Bearbeiten"],
      ["export", "Ausgabe"],
    ]) {
      const button = node("button", interfaceText(title)),
        element = node("section");
      button.type = "button";
      button.className = "btn btn-outline theme-secondary";
      button.dataset.studioTab = key;
      button.id = prefix + "-" + key;
      button.setAttribute("role", "tab");
      button.setAttribute("aria-controls", button.id + "-panel");
      element.id = button.id + "-panel";
      element.className = "studio-tool-panel";
      element.setAttribute("role", "tabpanel");
      element.setAttribute("aria-labelledby", button.id);
      panels.set(key, element);
      tabs.append(button);
      on(button, "click", () => show(key));
    }
    if (aside) {
      const adds = [...aside.querySelectorAll(":scope > .studio-add")];
      const toolPanel = panels.get("tools"),
        propertyPanel = panels.get("properties"),
        outputPanel = panels.get("export");
      toolPanel.append(
        ...[
          adds[0],
          root.querySelector("[data-studio-photo-picker]") || root.querySelector("[data-studio-photo]")?.closest("label"),
          background.closest("label"),
        ].filter(Boolean),
      );
      if (adds[1]) propertyPanel.append(adds[1]);
      propertyPanel.append(properties);
      const exportControls = root.querySelector(".studio-export-controls");
      if (exportControls) outputPanel.append(exportControls);
      const layerLabel = list.closest("label");
      list.size = 1;
      aside.replaceChildren(
        ...[layerLabel, layerButtons, tabs, ...panels.values()].filter(Boolean),
      );
      show("tools");
      on(list, "change", () => show("properties"));
      const photo = root.querySelector("[data-studio-photo]");
      if (photo)
        on(photo, "change", () => {
          if (photo.value) show("properties");
        });
      on(root, "click", (event) => {
        if(event.target.closest('[data-studio-photo-id]'))show('properties');
        const action = event.target.closest("[data-studio]")?.dataset.studio;
        if (
          [
            "text",
            "rect",
            "ellipse",
            "line",
            "arrow",
            "path",
            "frame",
            "redact",
            "qr",
            "logo",
          ].includes(action)
        )
          show("properties");
      });
      on(root.querySelector("canvas"), "pointerdown", () => show("properties"));
      const exportDetails = root.querySelector(".studio-export");
      const exportSummary = exportDetails?.querySelector("summary");
      if (exportSummary) exportSummary.textContent = interfaceText("Ausgabe");
      if (exportDetails)
        on(exportDetails, "toggle", () => {
          if (exportDetails.open && panel !== "export") show("export");
        });
      on(tabs, "keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key))
          return;
        event.preventDefault();
        const keys = [...panels.keys()],
          index = keys.indexOf(panel);
        const next =
          event.key === "Home"
            ? 0
            : event.key === "End"
              ? keys.length - 1
              : (index + (event.key === "ArrowRight" ? 1 : -1) + keys.length) %
                keys.length;
        show(keys[next], true);
      });
      root.classList.add("studio-compact");
      for (const [action, icon, label] of [
        ["undo", "↶", "Rückgängig"],
        ["redo", "↷", "Wiederherstellen"],
      ]) {
        const button = root.querySelector("[data-studio=" + action + "]");
        button.textContent = icon;
        button.setAttribute("aria-label", interfaceText(label));
        button.title = interfaceText(label);
      }
      const large = node("button", interfaceText("Großansicht"));
      large.type = "button";
      large.className = "btn btn-outline theme-secondary studio-open";
      large.setAttribute("aria-haspopup", "dialog");
      root.querySelector(".studio-toolbar").append(large);
      const closeModal = () => {
        if (!modal) return;
        const current = modal;
        modal = null;
        current.close();
        if (placeholder?.isConnected) {
          placeholder.replaceWith(root);
          if (large.isConnected) large.focus();
        } else root.remove();
        current.remove();
        placeholder = null;
        document.documentElement.classList.remove("studio-modal-lock");
      };
      on(large, "click", () => {
        if (modal) return;
        placeholder = document.createComment("Bildstudio");
        root.before(placeholder);
        modal = node("dialog");
        modal.className = "studio-dialog";
        modal.setAttribute("aria-label", interfaceText("Bildstudio · Großansicht"));
        const header = node("header"),
          close = node("button", interfaceText("Schließen"));
        close.type = "button";
        close.className = "btn btn-outline theme-secondary";
        header.append(node("h2", interfaceText("Bildstudio")), close);
        modal.append(header, root);
        document.body.append(modal);
        document.documentElement.classList.add("studio-modal-lock");
        on(close, "click", closeModal);
        on(modal, "cancel", (event) => {
          event.preventDefault();
          closeModal();
        });
        const current = modal,
          fit = () => {
            const height =
              (window.visualViewport?.height || innerHeight) /
              (Number.parseFloat(
                getComputedStyle(document.documentElement).zoom,
              ) || 1);
            current.style.setProperty(
              "--studio-viewport-height",
              height + "px",
            );
            current.classList.toggle("studio-dialog--short", height < 520);
            // Enlarged text gets the same canvas budget as the regular editor,
            // with its own scroll area instead of collapsed artwork or overlapping tools.
            const font = Number.parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
            current.style.setProperty("--studio-min-block", (font > 16 ? font * 45 : 0) + "px");
          };
        const fontObserver = typeof ResizeObserver === "function" ? new ResizeObserver(fit) : null;
        fontObserver?.observe(root.querySelector(".studio-toolbar"));
        window.visualViewport?.addEventListener("resize", fit, { signal });
        fit();
        modal.addEventListener(
          "close",
          () => {
            window.visualViewport?.removeEventListener("resize", fit);
            fontObserver?.disconnect();
            closeModal();
          },
          { once: true },
        );
        modal.showModal();
        close.focus();
      });
      signal?.addEventListener("abort", closeModal, { once: true });
    }
    function control(label, key, type = "number", min, max, step) {
      const l = chosen(),
        wrap = node("label", interfaceText(label)),
        input = node(type === "textarea" ? "textarea" : "input");
      if (type !== "textarea") input.type = type;
      input.className = "form-control";
      input.value = l[key] ?? "";
      input.dataset.property = key;
      if (min !== undefined) input.min = min;
      if (max !== undefined) input.max = max;
      if (step !== undefined) input.step = step;
      if (type === "textarea") input.maxLength = 2000;
      on(input, "input", () => {
        let v =
          type === "textarea" || type === "color"
            ? input.value
            : Number(input.value);
        if (
          typeof v === "number" &&
          (!Number.isFinite(v) || v < min || v > max)
        )
          return;
        change(() => {
          l[key] = v;
          if (l.type === "qr" && key === "width") l.height = v;
        }, false);
      });
      wrap.append(input);
      return wrap;
    }
    function controls() {
      const cloneExpanded = properties.querySelector("[data-clone-controls]")?.open || false;
      const expanded = new Set(
        [...properties.querySelectorAll(":scope > details[open]")].map(
          (e) => e.dataset.propertyGroup,
        ),
      );
      list.replaceChildren();layerButtons.replaceChildren();
      for (const l of [...getDocument().layers].reverse()) {
        const option = node(
          "option",
          interfaceText({
            text: "Text",
            rect: "Fläche",
            ellipse: "Kreis",
            image: "Foto",
            qr: "QR-Code",
            line: "Linie",
            arrow: "Pfeil",
            path: "Zeichnung",
            frame: "Rahmen",
            redact: "Unkenntlichmachung",
          }[l.type]) + (l.type === "text" ? " · " + l.text.slice(0, 35) : ""),
        );
        option.value = l.id;
        option.selected = l.id === chosen()?.id;
        list.append(option);
        const button=node("button",option.textContent);button.type="button";button.className="studio-layer-choice btn btn-outline theme-secondary";button.dataset.studioLayer=l.id;button.setAttribute("aria-pressed",String(l.id===chosen()?.id));layerButtons.append(button);
      }
      background.value = getDocument().background;
      properties.replaceChildren();
      const l = chosen();
      if (l) {
        properties.append(
          control("Position X", "x", "number", -2160, 2160, 1),
          control("Position Y", "y", "number", -3840, 3840, 1),
          control(
            "Breite",
            "width",
            "number",
            l.type === "qr" ? 150 : 20,
            4320,
            1,
          ),
        );
        if (l.type !== "qr")
          properties.append(control("Höhe", "height", "number", 20, 7680, 1));
        if (!["qr", "redact"].includes(l.type))
          properties.append(
            control("Drehung", "rotation", "range", -180, 180, 1),
            control("Deckkraft", "opacity", "range", 0, 1, 0.05),
          );
        if ("color" in l) properties.append(control("Farbe", "color", "color"));
        if ("stroke_width" in l)
          properties.append(
            control("Linienstärke", "stroke_width", "number", 1, 120, 1),
          );
        if (l.type === "redact") {
          const label = node("label", interfaceText("Unkenntlichmachung")),
            select = node("select");
          select.className = "form-control";
          select.dataset.property = "effect";
          for (const [value, text] of Object.entries({
            cover: "Deckende Fläche",
            pixelate: "Verpixeln",
            blur: "Weichzeichnen",
          })) {
            const option = node("option", interfaceText(text));
            option.value = value;
            option.selected = value === l.effect;
            select.append(option);
          }
          on(select, "change", () =>
            change(() => (l.effect = select.value), false),
          );
          label.append(select);
          properties.append(label);
        }
        if (l.type === "text") {
          properties.append(
            control("Text auf der Ebene", "text", "textarea"),
            control("Schriftgröße", "font_size", "number", 12, 240, 1),
          );
          const label = node("label", interfaceText("Schrift")),
            select = node("select");
          select.className = "form-control";
          select.setAttribute("aria-label", interfaceText("Schrift"));
          for (const font of ["Arial", "Georgia"]) {
            const op = node(
              "option",
              interfaceText(font === "Arial" ? "Sans Serif" : "Serif"),
            );
            op.value = font;
            op.selected = font === l.font;
            select.append(op);
          }
          on(select, "change", () =>
            change(() => (l.font = select.value), false),
          );
          label.append(select);
          properties.append(label);
        }
        if (l.type === "image") {
          const label=node("label",interfaceText("Foto ersetzen")),replacement=node("select");replacement.className="form-control";replacement.dataset.property="media_ref";
          for(const source of root.querySelectorAll("[data-studio-photo] option")){if(!source.value)continue;const option=source.cloneNode(true);option.selected=source.value===l.media_ref;replacement.append(option);}
          on(replacement,"change",()=>change(()=>{l.media_ref=replacement.value;if(l.clone_stamps)l.clone_stamps=[];}));label.append(replacement);properties.append(label);
          const photoChoices=node('div');photoChoices.className='studio-photo-choices';photoChoices.setAttribute('aria-label',interfaceText('Foto ersetzen'));
          for(const source of photoSources()){const button=node('button'),image=node('img'),caption=node('span',source.name);button.type='button';button.className='btn btn-outline theme-secondary studio-photo-choice';button.dataset.propertyPhoto=source.id;image.src=source.thumb;image.alt='';image.loading='lazy';button.append(image,caption);button.setAttribute('aria-pressed',String(source.id===l.media_ref));on(button,'click',()=>change(()=>{l.media_ref=source.id;if(l.clone_stamps)l.clone_stamps=[];}));photoChoices.append(button);}
          properties.append(photoChoices);
          properties.append(
            control("Belichtung", "exposure", "range", -2, 2, 0.05),
            control("Gamma", "gamma", "range", 0.3, 3, 0.05),
            control("Temperatur", "temperature", "range", -100, 100, 1),
            control("Farbton", "tint", "range", -100, 100, 1),
            control("Schärfe", "sharpness", "range", 0, 100, 1),
          );
          for (const [key, title, options] of [
            [
              "filter",
              "Fotofilter",
              {
                original: "Original",
                mono: "Schwarzweiß",
                warm: "Warm",
                cool: "Kühl",
                sepia: "Sepia",
                vivid: "Lebendig",
                soft: "Sanft",
              },
            ],
            [
              "fit",
              "Foto einpassen",
              { cover: "Ausschnitt füllen", contain: "Ganzes Bild erhalten" },
            ],
          ]) {
            const label = node("label", interfaceText(title)),
              select = node("select");
            select.className = "form-control";
            select.dataset.property = key;
            for (const [value, text] of Object.entries(options)) {
              const op = node("option", interfaceText(text));
              op.value = value;
              op.selected = value === l[key];
              select.append(op);
            }
            on(select, "change", () =>
              change(() => (l[key] = select.value), false),
            );
            label.append(select);
            properties.append(label);
          }
          properties.append(
            control("Fotozoom", "zoom", "range", 1, 4, 0.05),
            control("Ausschnitt horizontal", "focal_x", "range", 0, 100, 1),
            control("Ausschnitt vertikal", "focal_y", "range", 0, 100, 1),
            control("Helligkeit", "brightness", "range", 0, 200, 1),
            control("Kontrast", "contrast", "range", 0, 200, 1),
            control("Sättigung", "saturation", "range", 0, 200, 1),
          );
          const b = node(
            "button",
            interfaceText(l.flip ? "Spiegelung zurücksetzen" : "Foto spiegeln"),
          );
          b.type = "button";
          b.className = "btn btn-outline theme-secondary secondary";
          on(b, "click", () => change(() => (l.flip = !l.flip)));
          properties.append(b);
          const cloneControls = window.KulturhusPhotoClones.controls({
              interfaceText,
              interfaceFormat,
              layer: l,
              node,
              on,
              change,
              getDocument,
              paint,
            });
          cloneControls.open = cloneExpanded;
          properties.append(cloneControls);
        }
      }
      function group(title, keys) {
        const fields = [...properties.children].filter((element) =>
          keys.includes(
            element.querySelector("[data-property]")?.dataset.property,
          ),
        );
        if (!fields.length) return;
        const details = node("details");
        details.className = "studio-property-group";
        details.dataset.propertyGroup = title;
        details.open = expanded.has(title)||(!expanded.size&&(title==="Ausschnitt"||(title==="Position und Größe"&&l?.type!=="image")));
        details.append(node("summary", interfaceText(title)), ...fields);
        properties.append(details);
      }
      group("Position und Größe", [
        "x",
        "y",
        "width",
        "height",
        "rotation",
        "opacity",
      ]);
      if (l?.type === "image") {
        group("Licht und Farbe", [
          "exposure",
          "gamma",
          "temperature",
          "tint",
          "sharpness",
          "filter",
          "brightness",
          "contrast",
          "saturation",
        ]);
        group("Ausschnitt", ["fit", "zoom", "focal_x", "focal_y"]);
        const clone = properties.querySelector("[data-clone-controls]");
        if (clone) {
          const details = node("details");
          details.className = "studio-property-group";
          details.dataset.propertyGroup = "Retusche";
          details.open = expanded.has("Retusche");
          details.append(node("summary", interfaceText("Retusche")), clone);
          properties.append(details);
        }
      }
      root.querySelector("[data-studio=undo]").disabled =
        !history().undo.length;
      root.querySelector("[data-studio=redo]").disabled =
        !history().redo.length;
    }

    return Object.freeze({
      refresh: controls,
      belongs: (scope) => !!placeholder && scope.contains(placeholder),
    });
  }
  window.KulturhusImageStudioControls = Object.freeze({ create });
})();
