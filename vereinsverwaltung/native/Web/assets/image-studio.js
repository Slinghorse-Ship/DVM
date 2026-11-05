/* Own local image editor. No network service, AI, third-party editor or executable saved layout. */
(() => {
  "use strict";
  document.addEventListener('click',event=>{const link=event.target.closest('a[href="#social-compose"]');if(link){const details=document.querySelector('#social-compose')?.closest('[data-story-editor-details]');if(details)details.open=true;}},true);
  const mounted = new WeakMap(),
    live = new Set(),
    copy = (o) => JSON.parse(JSON.stringify(o));
  const node = (tag, text) => {
    const n = document.createElement(tag);
    if (text !== undefined) n.textContent = text;
    return n;
  };
  function mount(root = document, context = null) {
    root
      .querySelectorAll("[data-canvas-editor]")
      .forEach((editor) => initialize(editor, context));
  }
  function initialize(root, context) {
    if (mounted.has(root)) return;
    mounted.set(root, null);
    live.add(root);
    let controlPanel;
    const seed = JSON.parse(root.querySelector("[data-canvas-seed]").value),
      form = root.querySelector("[data-canvas-form]"),
      canvas = root.querySelector("canvas"),
      ctx = canvas.getContext("2d"),
      status = root.querySelector("[data-studio-status]"),
      list = root.querySelector("[data-studio-layers]"),
      properties = root.querySelector("[data-studio-properties]"),
      field = root.querySelector("[data-canvas-document]"),
      background = root.querySelector("[data-studio-background]"),
      slide = form.elements.slide;
    const interfaceText = source => Object.hasOwn(seed.interface || {}, source) ? seed.interface[source] : source;
    const interfaceFormat = (source, values) => interfaceText(source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token);
    if (!ctx) {
      live.delete(root);
      status.textContent =
        interfaceText("Die Zeichenfläche ist in diesem Browser nicht verfügbar.");
      form.querySelectorAll("button").forEach((b) => (b.disabled = true));
      return;
    }
    const fieldset = root.querySelector("[data-canvas-controls]");
    if (fieldset) fieldset.disabled = false;
    form.hidden = false;
    root.querySelector("[data-canvas-recovery]").hidden = false;
    canvas.tabIndex = 0;
    canvas.removeAttribute("aria-disabled");
    const fallback = root.querySelector("[data-canvas-fallback]");
    if (fallback) fallback.hidden = true;
    const sources = new Map(seed.media.map((m) => [m.id, m.url]));
    sources.set("qr", seed.qr);
    for (const m of seed.media)
      if (m.credit?.url) sources.set("credit-" + m.id, m.credit.url);
    let selected = "",
      pendingSave = null,
      pendingPhoto = null,
      canvasReviewKey = form.elements.canvas_request_key.value,
      undo = [],
      redo = [],
      drag = null,
      busy = false,
      drawingMode = false,
      failed = "",
      sequence = 0;
    const base = (type) => ({
      id: "layer-" + Date.now().toString(36) + "-" + ++sequence,
      type,
      x: 80,
      y: 180,
      width: 900,
      height: 240,
      rotation: 0,
      opacity: 1,
    });
    function initial() {
      if(seed.initial_documents?.[Number(slide.value)])return copy(seed.initial_documents[Number(slide.value)]);
      const s = seed.slides?.[Number(slide.value)];
      return {
        schema: 1,
        width: 1080,
        height: seed.height,
        background: "#163f33",
        layers: [
          {
            ...base("text"),
            text: s?.title || seed.title,
            font: "Georgia",
            font_size: 90,
            color: "#ffffff",
            height: 540,
          },
          {
            ...base("text"),
            text: s?.text || seed.summary || seed.name,
            font: "Arial",
            font_size: 38,
            color: "#d4e99c",
            y: 780,
            height: 350,
          },
        ],
      };
    }
    for (const saved of Object.values(seed.documents))
      for (const l of saved.layers)
        if (l.type === "image")
          Object.assign(l, {
            filter: "original",
            fit: "cover",
            exposure: 0,
            gamma: 1,
            temperature: 0,
            tint: 0,
            sharpness: 0,
            ...l,
          });
    function storyDocument(document, index) {
      if (!seed.story_series || index !== 0) return document;
      if (!document.layers.some(layer => layer.id === "reminder-label") && document.layers.length <= 28) {
        document.layers = document.layers.filter(layer => !["reminder-background", "reminder-label"].includes(layer.id));
        document.layers.push(...copy(seed.story_series.reminder_layers));
      }
      for (const layer of document.layers) if (layer.id === "reminder-label" && layer.type === "text") layer.text = seed.story_series.labels[seed.story_series.slot];
      return document;
    }
    let doc = storyDocument(copy(seed.documents[0] || initial()), 0);
    const templatePhotoIds = new Map();
    // Remember the original layer, never choose by the size of later additions.
    const templatePhoto = document => document.layers.find(layer => layer.type === 'image' && layer.id === 'event-photo') || document.layers.find(layer => layer.type === 'image' && (layer.media_ref !== seed.logo || layer.fit !== 'contain') && !String(layer.media_ref).startsWith('credit-') && layer.media_ref !== 'qr');
    for(const [index,document] of Object.entries({...seed.initial_documents,...seed.documents})) {const photo=templatePhoto(document);if(photo)templatePhotoIds.set(String(index),photo.id);}
    if(!templatePhotoIds.has('0')){const photo=templatePhoto(doc);if(photo)templatePhotoIds.set('0',photo.id);}
    selected = doc.layers[0]?.id || "";
    const controller = new AbortController(),
      urls = new Set();
    let disposed = false;
    const originals = new Map([[Number(slide.value), JSON.stringify(doc)]]);
    const on = (target, type, listener, options = {}) =>
      target.addEventListener(type, listener, {
        ...(typeof options === "boolean" ? { capture: options } : options),
        signal: controller.signal,
      });
    const active = () => {
      if (disposed || !root.isConnected)
        throw new DOMException("Editor geschlossen.", "AbortError");
    };
    const media = window.KulturhusImageStudioMedia.create({
      sources,
      signal: controller.signal,
      active,
    });
    const lock = () => {
      busy = true;
      root.inert = true;
      root.setAttribute("aria-busy", "true");
    };
    const unlock = () => {
      busy = false;
      if (!disposed) {
        root.inert = false;
        root.removeAttribute("aria-busy");
        root.dispatchEvent(new CustomEvent('kulturhus:social-save-state',{bubbles:true}));
      }
    };
    const dispose = () => {
      if (disposed) return;
      disposed = true;
      controller.abort();
      urls.forEach((url) => URL.revokeObjectURL(url));
      urls.clear();
      media.dispose();
      undo = [];
      redo = [];
      drag = null;
      canvas.width = canvas.height = 0;
      mounted.delete(root);
      live.delete(root);
    };
    context?.cleanup(dispose);
    const guarded = () =>
      !disposed &&
      (busy || pendingSave !== null || pendingPhoto !== null ||
        [...originals].some(
          ([index, original]) =>
            JSON.stringify(
              index === Number(slide.value) ? doc : seed.documents[index],
            ) !== original,
        ));
    mounted.set(root, {
      dispose,
      guarded,
      belongs: (scope) => scope.contains(root) || controlPanel?.belongs(scope),
    });
    context?.guard(guarded);
    if (!context)
      window.addEventListener("pagehide", dispose, {
        once: true,
        signal: controller.signal,
      });
    const chosen = () => doc.layers.find((l) => l.id === selected);
    const report = (t) => {
      if (!disposed) status.textContent = interfaceText(t);
    };
    function signal() {
      field.value = JSON.stringify(doc);
      field.dispatchEvent(new Event("input", { bubbles: true }));
    }
    function remember(before) {
      if (before === JSON.stringify(doc)) return;
      undo.push(before);
      if (undo.length > 50) undo.shift();
      redo = [];
      signal();
      root.querySelector("[data-studio=undo]").disabled = false;
      root.querySelector("[data-studio=redo]").disabled = true;
    }
    function change(fn, refresh = true) {
      if (busy || disposed) return;
      const before = JSON.stringify(doc);
      fn();
      remember(before);
      if (refresh) controls();
      void render();
    }
    const { image, assets, photo } = media;
    const renderer = window.KulturhusImageStudioRenderer.create({
      canvas,
      ctx,
      image,
      photo,
      active,
      credits: new Map(
        seed.media.map((m) => [m.id, m.credit || { lines: [] }]),
      ),
      attribution: seed.attribution,
    });
    async function draw(guides, attribution = true) {
      failed = await renderer.draw(doc, {
        guides,
        attribution,
        hideReminder: !!seed.story_series && !seed.story_series.show_hint,
        selected: chosen(),
        pen: drag?.pen,
        safeArea: root.querySelector("[data-safe-area]").checked,
      });
    }
    let pending = Promise.resolve();
    function render() {
      if (disposed) return Promise.resolve();
      pending = pending
        .catch(() => {})
        .then(() => (disposed ? undefined : draw(true)))
        .then(() => {
          if (failed) report(failed);
          else report("Bildstudio bereit.");
        })
        .catch((error) => {
          if (!disposed) report(error.message);
        });
      return pending;
    }
    controlPanel = window.KulturhusImageStudioControls.create({
      interfaceText,
      interfaceFormat,
      root,
      list,
      properties,
      background,
      chosen,
      getDocument: () => doc,
      photoSources: () => seed.media,
      on,
      change,
      node,
      history: () => ({ undo, redo }),
      signal: controller.signal,
      paint: ({ layer, settings, trigger }) => {
        if (busy || disposed) return;
        const total = doc.layers.reduce(
          (count, item) => count + (item.clone_stamps || []).length,
          0,
        );
        window.KulturhusPhotoClonePainter.open({
          interfaceText,
          interfaceFormat,
          root,
          layer,
          settings,
          trigger,
          signal: controller.signal,
          maximum: Math.min(
            64 - (layer.clone_stamps || []).length,
            256 - total,
          ),
          image: () => photo({ ...layer, clone_stamps: [] }),
          commit: (stamps) => {
            active();
            if (busy || chosen() !== layer || !doc.layers.includes(layer))
              throw Error("Foto erneut öffnen.");
            const count = doc.layers.reduce(
              (n, item) =>
                n +
                (item === layer
                  ? stamps.length
                  : (item.clone_stamps || []).length),
              0,
            );
            if (count > 256)
              throw Error(
                "Höchstens 256 Klonstempel in einer Bildfassung erlaubt.",
              );
            change(() => {
              layer.clone_stamps = stamps;
            });
          },
        });
      },
    });
    const controls = () => controlPanel.refresh();
    function add(type, media, logo = false) {
      if (doc.layers.length >= 30) {
        report("Höchstens 30 Ebenen erlaubt.");
        return;
      }
      change(() => {
        const l = base(type);
        if (type === "text")
          Object.assign(l, {
            text: interfaceText("Dein Text"),
            color: "#ffffff",
            font: "Arial",
            font_size: 60,
          });
        if (type === "rect" || type === "ellipse")
          Object.assign(l, { color: "#d4e99c", width: 360, height: 360 });
        if (["line", "arrow", "path", "frame"].includes(type))
          Object.assign(l, { color: "#d4e99c", stroke_width: 12 });
        if (type === "path") {
          l.points = [];
          drawingMode = true;
        }
        if (type === "frame")
          Object.assign(l, {
            x: 24,
            y: 24,
            width: 1032,
            height: doc.height - 48,
          });
        if (type === "redact")
          Object.assign(l, {
            color: "#000000",
            effect: "cover",
            width: 400,
            height: 250,
          });
        if (type === "qr")
          Object.assign(l, {
            width: 240,
            height: 240,
            x: 760,
            y: doc.height - 520,
          });
        if (type === "image")
          Object.assign(l, {
            media_ref: media,
            width: logo ? 240 : 900,
            height: logo ? 240 : 700,
            focal_x: 50,
            focal_y: 50,
            zoom: 1,
            flip: false,
            brightness: 100,
            contrast: 100,
            saturation: 100,
            filter: "original",
            fit: logo ? "contain" : "cover",
            exposure: 0,
            gamma: 1,
            temperature: 0,
            tint: 0,
            sharpness: 0,
          });
        doc.layers.push(l);
        selected = l.id;
      });
      report("Ebene hinzugefügt.");
    }
    async function blob(mime, dimensions = null, attribution = true) {
      active();
      if (doc.layers.some((l) => l.type === "path" && l.points.length < 2))
        throw Error(
          "Freihandzeichnung zuerst auf der Zeichenfläche zeichnen oder die leere Ebene entfernen.",
        );
      if (new TextEncoder().encode(JSON.stringify(doc)).length > 100000)
        throw Error(
          "Gestaltung ist zu umfangreich. Zeichnungen oder Ebenen reduzieren.",
        );
      await pending;
      active();
      await assets(doc);
      active();
      await draw(false, attribution);
      active();
      if (failed) {
        await draw(true);
        throw Error(failed);
      }
      try {
        const result = await window.KulturhusImageExport.encode(
          canvas,
          {
            width: dimensions?.width ?? doc.width,
            height: dimensions?.height ?? doc.height,
            mime,
          },
          controller.signal,
        );
        active();
        if (!result || result.type !== mime || result.size > 5 * 1024 * 1024)
          throw Error("Bildexport nicht verfügbar oder größer als 5 MiB.");
        return result;
      } finally {
        if (!disposed) await draw(true);
      }
    }
    on(root, "click", async (e) => {
      const action = e.target.closest("[data-studio]")?.dataset.studio;
      if (!action || busy || disposed) return;
      try {
        if (
          [
            "text",
            "rect",
            "ellipse",
            "qr",
            "line",
            "arrow",
            "path",
            "frame",
            "redact",
          ].includes(action)
        )
          add(action);
        else if (action === "logo") add("image", seed.logo, true);
        else if (action === "undo" || action === "redo") {
          const from = action === "undo" ? undo : redo,
            to = action === "undo" ? redo : undo;
          if (!from.length) return;
          to.push(JSON.stringify(doc));
          doc = JSON.parse(from.pop());
          selected = doc.layers.at(-1)?.id || "";
          signal();
          controls();
          await render();
          report(
            action === "undo"
              ? "Änderung rückgängig gemacht."
              : "Änderung wiederhergestellt.",
          );
        } else if (action.startsWith("download-")) {
          lock();
          try {
            const mime = {
                "download-png": "image/png",
                "download-jpeg": "image/jpeg",
                "download-webp": "image/webp",
              }[action],
              selection = window.KulturhusImageExport.configuration(
                exportWidth.value,
                exportHeight.value,
                mime,
              ),
              result = await blob(mime, selection),
              url = URL.createObjectURL(result),
              a = node("a");
            active();
            urls.add(url);
            a.href = url;
            a.download =
              interfaceText("vereinsbild") + "-" +
              selection.width +
              "x" +
              selection.height +
              "." +
              selection.extension;
            a.click();
            (context ? context.timeout : setTimeout)(() => {
              URL.revokeObjectURL(url);
              urls.delete(url);
            }, 30000);
            report("Echte Bilddatei exportiert.");
          } finally {
            unlock();
          }
        } else if (chosen()) {
          change(() => {
            const i = doc.layers.findIndex((l) => l.id === selected);
            if (action === "remove") {
              doc.layers.splice(i, 1);
              selected = doc.layers.at(-1)?.id || "";
            } else if (action === "duplicate" && doc.layers.length < 30) {
              const l = {
                ...copy(chosen()),
                id: base("text").id,
                x: Math.min(2160, chosen().x + 20),
                y: Math.min(3840, chosen().y + 20),
              };
              doc.layers.splice(i + 1, 0, l);
              selected = l.id;
            } else if (action === "up" && i < doc.layers.length - 1)
              [doc.layers[i], doc.layers[i + 1]] = [
                doc.layers[i + 1],
                doc.layers[i],
              ];
            else if (action === "down" && i > 0)
              [doc.layers[i], doc.layers[i - 1]] = [
                doc.layers[i - 1],
                doc.layers[i],
              ];
          });
        }
      } catch (error) {
        report(error.message);
      }
    });
    on(list, "change", () => {
      if (busy || disposed) return;
      selected = list.value;
      controls();
      void render();
    });
    on(background, "input", () =>
      change(() => (doc.background = background.value), false),
    );
    function usePhoto(id) {
      if (!sources.has(id) || busy || disposed) return;
      const mode = root.querySelector('[data-studio-photo-mode]:checked')?.value || 'replace';
      const photo = mode === 'replace' ? doc.layers.find(layer => layer.type === 'image' && layer.id === templatePhotoIds.get(String(slide.value))) : null;
      if (photo) change(() => {const previous=photo.media_ref;photo.media_ref = id; if (photo.clone_stamps) photo.clone_stamps = [];for(const layer of doc.layers)if(photo.id==='event-photo'&&layer.id==='event-backdrop'&&layer.type==='image'&&layer.media_ref===previous){layer.media_ref=id;if(layer.clone_stamps)layer.clone_stamps=[];} selected = photo.id; });
      else {add('image', id);if(mode==='replace')templatePhotoIds.set(String(slide.value),selected);}
    }
    on(root.querySelector("[data-studio-photo]"), "change", (e) => {
      if (e.target.value) usePhoto(e.target.value);
      e.target.value = "";
    });
    on(root, 'click', event => { const button = event.target.closest('[data-studio-photo-id]'); if (button) usePhoto(button.dataset.studioPhotoId); });
    on(root.querySelector('[data-studio-photo-search]'), 'input', event => {
      const query = event.target.value.trim().toLocaleLowerCase();
      for (const button of root.querySelectorAll('[data-studio-photo-id]')) button.hidden = !button.textContent.toLocaleLowerCase().includes(query);
    });
    on(root.querySelector('[data-studio-photo-search]'), 'keydown', event => { if(event.key==='Enter')event.preventDefault(); });
    on(root.querySelector("[data-safe-area]"), "change", () => void render());
    const exportWidth = root.querySelector("[data-export-width]"),
      exportHeight = root.querySelector("[data-export-height]"),
      exportAspect = root.querySelector("[data-export-aspect]");
    const syncExport = (changed) => {
      if (!exportAspect.checked) return;
      const value = Number(changed.value);
      if (!Number.isInteger(value) || value < 32 || value > 8192) return;
      if (changed === exportWidth)
        exportHeight.value = Math.round((value * doc.height) / doc.width);
      else exportWidth.value = Math.round((value * doc.width) / doc.height);
    };
    on(exportWidth, "input", () => syncExport(exportWidth));
    on(exportHeight, "input", () => syncExport(exportHeight));
    on(exportAspect, "change", () => syncExport(exportWidth));
    on(slide, "change", () => {
      if (busy || disposed) return;
      seed.documents[slide.dataset.previous || "0"] = copy(doc);
      doc = storyDocument(copy(seed.documents[slide.value] || initial()), Number(slide.value));
      if (!originals.has(Number(slide.value)))
        originals.set(Number(slide.value), JSON.stringify(doc));
      slide.dataset.previous = slide.value;
      selected = doc.layers[0]?.id || "";
      undo = [];
      redo = [];
      signal();
      controls();
      void render();
      report("Andere Folie geöffnet. Jede Bildfolie einzeln speichern.");
    });
    const point = (e) => {
      const r = canvas.getBoundingClientRect();
      return {
        x: ((e.clientX - r.left) * canvas.width) / r.width,
        y: ((e.clientY - r.top) * canvas.height) / r.height,
      };
    };
    on(canvas, "pointerdown", (e) => {
      if (busy || disposed || e.button !== 0) return;
      const p = point(e);
      if (drawingMode && chosen()?.type === "path") {
        const l = chosen();
        drag = { pen: true, before: JSON.stringify(doc) };
        l.x = p.x;
        l.y = p.y;
        l.points = [[0, 0]];
        canvas.setPointerCapture(e.pointerId);
        void render();
        return;
      }
      selected =
        [...doc.layers].reverse().find((l) => {
          const a = (-l.rotation * Math.PI) / 180,
            x = p.x - l.x - l.width / 2,
            y = p.y - l.y - l.height / 2;
          return (
            Math.abs(x * Math.cos(a) - y * Math.sin(a)) <= l.width / 2 &&
            Math.abs(x * Math.sin(a) + y * Math.cos(a)) <= l.height / 2
          );
        })?.id || "";
      const l = chosen();
      if (l) {
        drag = { ...p, x0: l.x, y0: l.y, before: JSON.stringify(doc) };
        canvas.setPointerCapture(e.pointerId);
      }
      controls();
      void render();
    });
    on(canvas, "pointermove", (e) => {
      if (busy || disposed || !drag || !chosen()) return;
      const p = point(e),
        l = chosen();
      if (drag.pen) {
        if (l.points.length < 1000)
          l.points.push([
            Math.round((p.x - l.x) * 10) / 10,
            Math.round((p.y - l.y) * 10) / 10,
          ]);
        void render();
        return;
      }
      l.x = Math.max(-2160, Math.min(2160, drag.x0 + p.x - drag.x));
      l.y = Math.max(-3840, Math.min(3840, drag.y0 + p.y - drag.y));
      void render();
    });
    const end = () => {
      if (busy || disposed || !drag) return;
      if (drag.pen) {
        const l = chosen();
        if (l.points.length < 2) l.points.push([1, 1]);
        const xs = l.points.map((p) => p[0]),
          ys = l.points.map((p) => p[1]),
          minX = Math.min(...xs),
          minY = Math.min(...ys);
        l.x += minX;
        l.y += minY;
        l.width = Math.max(20, Math.max(...xs) - minX);
        l.height = Math.max(20, Math.max(...ys) - minY);
        l.points = l.points.map((p) => [p[0] - minX, p[1] - minY]);
        drawingMode = false;
      }
      remember(drag.before);
      drag = null;
      controls();
      void render();
    };
    on(canvas, "pointerup", end);
    on(canvas, "pointercancel", end);
    on(canvas, "keydown", (e) => {
      if (!chosen() || busy || disposed) return;
      const d = e.shiftKey ? 10 : 1;
      if (["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(e.key)) {
        e.preventDefault();
        change(() => {
          const l = chosen();
          if (e.key === "ArrowLeft") l.x = Math.max(-2160, l.x - d);
          if (e.key === "ArrowRight") l.x = Math.min(2160, l.x + d);
          if (e.key === "ArrowUp") l.y = Math.max(-3840, l.y - d);
          if (e.key === "ArrowDown") l.y = Math.min(3840, l.y + d);
        });
      }
    });
    const saveButton = form.querySelector('button[type="submit"]'),
      reviewButton = root.querySelector('[data-canvas-review]'),
      originalLink = root.querySelector('[data-canvas-original]');
    const requestKey = () => [...crypto.getRandomValues(new Uint8Array(16))].map(v => v.toString(16).padStart(2, "0")).join("");
    const uncertainMessage = "Bildspeicherung nicht bestätigt. Gestaltung und ursprünglichen Vorgang behalten. Gespeicherte Bildfassung prüfen.";
    const sendCanvas = async data => {
      const timeout = new AbortController(), timer = setTimeout(() => timeout.abort(), 20000);
      try {
        const response = await fetch(form.getAttribute("action") || location.href, {
          method: "POST", body: data, credentials: "same-origin", cache: "no-store",
          headers: { Accept: "application/json" },
          signal: AbortSignal.any([controller.signal, timeout.signal]),
        });
        let answer;
        try { answer = await response.json(); } catch { throw new Error(interfaceText(uncertainMessage)); }
        if (!response.ok) throw new Error(typeof answer.error === "string" ? answer.error : interfaceText(uncertainMessage));
        if (!answer.canvas_result || typeof answer.canvas_result.found !== "boolean") throw new Error(interfaceText(uncertainMessage));
        return answer.canvas_result;
      } finally { clearTimeout(timer); }
    };
    const accepted = (answer, attempt) => {
      if (!answer.found || !/^[a-f0-9]{32}$/.test(answer.id) || answer.item_id !== form.elements.id.value ||
          (attempt && (answer.item_version !== attempt.version || answer.slide !== attempt.slide))) throw new Error(interfaceText(uncertainMessage));
      const target = new URL(answer.url, location.href);
      if (target.origin !== location.origin || target.username || target.password) throw new Error(interfaceText(uncertainMessage));
      originalLink.href = target.href; originalLink.hidden = false;
      if (attempt) {
        originals.set(attempt.slide, JSON.stringify(attempt.document));
        seed.documents[attempt.slide] = copy(attempt.document);
        canvasReviewKey = attempt.key; pendingSave = null;
        form.elements.canvas_request_key.value = requestKey();
      }
      saveButton.textContent = interfaceText("Bildfassung speichern");
      report("Bildfassung gespeichert. Ungespeicherte Beitragstexte und weitere Gestaltungen bleiben erhalten.");
    };
    on(reviewButton, "click", async () => {
      if (busy || disposed) return;
      lock(); report("Gespeicherte Bildfassung wird geprüft …");
      try {
        const data = new FormData();
        for (const name of ["csrf", "id", "action"]) if (form.elements[name]) data.set(name, form.elements[name].value);
        data.set("operation", "social-canvas-review");
        data.set("canvas_request_key", pendingSave?.key || canvasReviewKey);
        const answer = await sendCanvas(data); active();
        if (answer.found) accepted(answer, pendingSave);
        else {
          pendingSave = null; originalLink.hidden = true; saveButton.textContent = interfaceText("Bildfassung speichern");
          // Keep this key: a delayed original and a corrected save must still compete for one receipt.
          report("Keine gespeicherte Bildfassung gefunden. Gestaltung behalten und bewusst erneut speichern.");
        }
      } catch (error) { report(error instanceof TypeError || error.name === "AbortError" ? uncertainMessage : error.message); }
      finally { unlock(); }
    });
    on(form, "submit", async e => {
      e.preventDefault(); e.stopImmediatePropagation();
      if (busy || disposed) return;
      lock(); report("Bilddatei wird erzeugt …");
      try {
        if (!pendingSave) {
          const document = copy(doc), index = Number(slide.value), result = await blob("image/png", null, false);
          active(); field.value = JSON.stringify(document);
          const data = new FormData(form); data.set("editor_document", JSON.stringify(document));
          data.set("canvas_image", new File([result], "canvas.png", { type: "image/png" }));
          if (seed.story_series && index === 0) {
            report("Story-Vorlage wird für die Tagesvarianten gespeichert …");
            try {
              for (const slot of ["base", "initial", "d14", "d7", "d3", "d1", "d0"]) {
                const variant = copy(document);
                if (slot === "base") variant.layers = variant.layers.filter(l => !["reminder-background", "reminder-label"].includes(l.id));
                else {
                  if (!variant.layers.some(l => l.id === "reminder-label")) {
                    variant.layers = variant.layers.filter(l => !["reminder-background", "reminder-label"].includes(l.id));
                    variant.layers.push(...copy(seed.story_series.reminder_layers));
                  }
                  if (variant.layers.length > 30) throw Error("Für den Tageshinweis höchstens 28 weitere Gestaltungsebenen verwenden.");
                  for (const layer of variant.layers) if (layer.id === "reminder-label" && layer.type === "text") layer.text = seed.story_series.labels[slot];
                }
                await assets(variant); active();
                const failure = await renderer.draw(variant, { guides: false, attribution: false }); active();
                if (failure) throw Error(failure);
                const rendered = await window.KulturhusImageExport.encode(canvas, { width: variant.width, height: variant.height, mime: "image/png" }, controller.signal); active();
                if (!rendered || rendered.type !== "image/png" || rendered.size > 5 * 1024 * 1024) throw Error("Story-Vorlage ist größer als 5 MiB je Bild.");
                data.set("story_template_" + slot, new File([rendered], "story-" + slot + ".png", { type: "image/png" }));
              }
              data.set("story_template_complete", "1");
              data.set("story_template_hint", seed.story_series.show_hint ? "1" : "0");
            } finally { if (!disposed) await draw(true); }
          }
          pendingSave = { data, document, slide: index, version: Number(form.elements.version.value), key: form.elements.canvas_request_key.value };
          canvasReviewKey = pendingSave.key; originalLink.hidden = true;
        }
        const attempt = pendingSave, answer = await sendCanvas(attempt.data); active(); accepted(answer, attempt);
      } catch (error) {
        if (pendingSave) saveButton.textContent = interfaceText("Ursprüngliche Bildfassung erneut speichern");
        report(error instanceof TypeError || error.name === "AbortError" ? uncertainMessage : error.message);
      } finally { unlock(); }
    }, true);
    const photoPanel = root.querySelector('[data-photo-upload]');
    if (photoPanel) {
      const photoForm = photoPanel.querySelector('[data-social-photo-form]'),
        photoStatus = photoPanel.querySelector('[data-photo-status]'),
        photoLink = photoPanel.querySelector('[data-photo-original]'),
        photoButton = photoPanel.querySelector('[data-photo-save]');
      let selectionVersion = 0, photoReviewKey = photoForm.elements.photo_request_key.value;
      const photoReport = source => { if (!disposed) photoStatus.textContent = interfaceText(source); };
      const unconfirmedPhoto = 'Fotoupload nicht bestätigt. Datei, Beschreibung und ursprünglichen Vorgang behalten. Gespeichertes Foto prüfen.';
      on(photoForm.elements.photo, 'change', () => { selectionVersion++; if (!pendingPhoto) photoReviewKey = photoForm.elements.photo_request_key.value; });
      async function sendPhoto(data) {
        const timeout = new AbortController(), timer = setTimeout(() => timeout.abort(), 20000);
        try {
          const response = await fetch(photoForm.getAttribute('action') || location.href, {method:'POST',body:data,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'},signal:AbortSignal.any([controller.signal,timeout.signal])});
          let result; try { result = await response.json(); } catch { throw Error(interfaceText(unconfirmedPhoto)); }
          if (!response.ok) throw Error(typeof result.error === 'string' ? result.error : interfaceText(unconfirmedPhoto));
          if (!result.photo_result || typeof result.photo_result.found !== 'boolean') throw Error(interfaceText(unconfirmedPhoto));
          return result.photo_result;
        } finally { clearTimeout(timer); }
      }
      function photoAccepted(answer, attempt) {
        if (!answer.found || !/^[a-f0-9]{32}$/.test(answer.id) || answer.item_id !== photoForm.elements.id.value || typeof answer.name !== 'string' || (attempt && answer.item_version !== attempt.version)) throw Error(interfaceText(unconfirmedPhoto));
        for (const key of ['url','thumb','original_url']) { if (typeof answer[key] !== 'string') throw Error(interfaceText(unconfirmedPhoto)); const url = new URL(answer[key],location.href); if (url.origin !== location.origin || url.username || url.password) throw Error(interfaceText(unconfirmedPhoto)); }
        if (!sources.has(answer.id)) {
          sources.set(answer.id, answer.url);
          seed.media.push({id:answer.id,name:answer.name,url:answer.url,thumb:answer.thumb,credit:{lines:[]}});
          const option = node('option',answer.name); option.value = answer.id; root.querySelector('[data-studio-photo]').append(option);
          const button = node('button'), image = node('img'), caption = node('span',answer.name);button.type = 'button';button.className = 'btn btn-outline theme-secondary studio-photo-choice';button.dataset.studioPhotoId = answer.id;image.src = answer.thumb;image.alt = '';image.loading = 'lazy';button.append(image,caption);root.querySelector('[data-studio-photo-choices]')?.append(button);
        }
        photoLink.href = answer.original_url; photoLink.hidden = false;
        if (attempt) {
          if (selectionVersion === attempt.selection) photoForm.elements.photo.value = '';
          photoReviewKey = attempt.key; pendingPhoto = null; photoForm.elements.photo_request_key.value = requestKey();
        }
        photoForm.noValidate = false; photoButton.textContent = interfaceText('Foto in Galerie speichern');
        photoStatus.textContent = interfaceFormat('Foto gespeichert: {name}. Beitrag und Gestaltung bleiben unverändert.', {name:answer.name});
      }
      on(photoForm, 'submit', async event => {
        event.preventDefault(); event.stopImmediatePropagation(); if (busy || disposed) return;
        const review = event.submitter?.name === 'photo_review'; lock(); photoReport(review ? 'Gespeichertes Foto wird geprüft …' : 'Foto wird gespeichert …');
        try {
          if (review) {
            const data = new FormData(); for (const name of ['csrf','operation','photo_window','id','version','alt','action']) if (photoForm.elements[name]) data.set(name,photoForm.elements[name].value);
            data.set('photo_request_key',pendingPhoto?.key || photoReviewKey); data.set('photo_review','1');
            const answer = await sendPhoto(data); active();
            if (answer.found) photoAccepted(answer,pendingPhoto);
            else { pendingPhoto = null; photoLink.hidden = true; photoForm.noValidate = false; photoButton.textContent = interfaceText('Foto in Galerie speichern'); photoReport('Kein gespeichertes Foto gefunden. Datei bewusst erneut auswählen und speichern.'); }
          } else {
            if (!pendingPhoto) {
              const file = photoForm.elements.photo.files[0]; if (!file) throw Error(interfaceText('Foto auswählen.'));
              pendingPhoto = {data:new FormData(photoForm),key:photoForm.elements.photo_request_key.value,version:Number(photoForm.elements.version.value),selection:selectionVersion};
              photoReviewKey = pendingPhoto.key; photoLink.hidden = true;
            }
            photoForm.noValidate = true;
            const attempt = pendingPhoto, answer = await sendPhoto(attempt.data); active(); photoAccepted(answer,attempt);
          }
        } catch(error) { if (pendingPhoto) photoButton.textContent = interfaceText('Ursprünglichen Fotoupload erneut versuchen'); photoReport(error instanceof TypeError || error.name === 'AbortError' ? unconfirmedPhoto : error.message); }
        finally { unlock(); }
      }, true);
    }
    controls();
    void render().then(() => {
      if (!failed)
        report(
          "Bildstudio bereit. Ebene wählen oder direkt auf der Zeichenfläche verschieben.",
        );
    });
    root.studio = {
      snapshot: () => copy(doc),
      state: () => ({
        disposed,
        busy,
        objectUrls: urls.size,
        sourceImages: media.count(),
      }),
    };
  }
  const unmount = (root = document) =>
    [...live].forEach((editor) => {
      const instance = mounted.get(editor);
      if (instance?.belongs(root)) instance.dispose();
    });
  const guarded = (root = document) =>
    [...live].some((editor) => {
      const instance = mounted.get(editor);
      return instance?.belongs(root) && instance.guarded();
    });
  window.KulturhusImageStudio = Object.freeze({ mount, unmount, guarded });
  if (window.KulturhusPage)
    window.KulturhusPage.register("image-studio", (context) =>
      mount(context.root, context),
    );
  if (!window.KulturhusPage) {
    if (document.readyState === "loading")
      document.addEventListener("DOMContentLoaded", () => mount(), {
        once: true,
      });
    else mount();
  }
})();
