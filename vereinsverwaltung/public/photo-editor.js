/* Optional local photo editor. The usual file picker and upload remain immediate. */
(() => {
  "use strict";
  if (window.KulturhusPhotoEditor) return;
  const photoText = source => { const key = 'photo:' + source, value = window.KulturhusInterface?.text(key); return value === undefined || value === key ? source : value; };
  const photoFormat = (source, values) => photoText(source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token);
  const node = (tag, text) => {
    const e = document.createElement(tag);
    if (text !== undefined) e.textContent = text;
    return e;
  };
  const copy = (value) => JSON.parse(JSON.stringify(value)),
    MAX_PIXELS = 16000000;
  let opened = null;
  const initial = () => ({
    rotation: 0,
    straighten: 0,
    cropWidth: 100,
    cropHeight: 100,
    sharpness: 0,
    flipX: false,
    flipY: false,
    ratio: 0,
    zoom: 1,
    x: 50,
    y: 50,
    brightness: 100,
    contrast: 100,
    saturation: 100,
    temperature: 0,
    filter: "original",
    marks: [],
  });
  const filters = {
    original: "Original",
    mono: "Schwarzweiß",
    warm: "Warm",
    cool: "Kühl",
    sepia: "Sepia",
    vivid: "Lebendig",
  };
  const retouchTools = new Set(["blur", "pixelate", "redeye"]),
    strokeTools = new Set(["brush", "clone", ...retouchTools]);
  function strokePath(ctx, points, radius) {
    ctx.beginPath();
    for (const [x, y] of points) {
      ctx.moveTo(x + radius, y);
      ctx.arc(x, y, radius, 0, Math.PI * 2);
    }
    for (let i = 1; i < points.length; i++) {
      const [a, b] = [points[i - 1], points[i]],
        angle = Math.atan2(b[1] - a[1], b[0] - a[0]),
        dx = Math.sin(angle) * radius,
        dy = Math.cos(angle) * radius;
      ctx.moveTo(a[0] + dx, a[1] - dy);
      ctx.lineTo(b[0] + dx, b[1] - dy);
      ctx.lineTo(b[0] - dx, b[1] + dy);
      ctx.lineTo(a[0] - dx, a[1] + dy);
      ctx.closePath();
    }
  }
  // Sliding weighted kernels keep transparent PNG edges free of black fringes.
  function blurPixels(source, width, height, radius) {
    let input = source;
    for (const vertical of [false, true]) {
      const output = new Uint8ClampedArray(source.length),
        lines = vertical ? width : height,
        length = vertical ? height : width;
      for (let line = 0; line < lines; line++) {
        const index = (position) =>
          (vertical ? position * width + line : line * width + position) * 4;
        let r = 0,
          g = 0,
          b = 0,
          a = 0;
        const add = (position, direction) => {
          if (position < 0 || position >= length) return;
          const i = index(position),
            alpha = input[i + 3] * direction;
          r += input[i] * alpha;
          g += input[i + 1] * alpha;
          b += input[i + 2] * alpha;
          a += alpha;
        };
        for (let j = 0; j <= Math.min(radius, length - 1); j++) add(j, 1);
        for (let position = 0; position < length; position++) {
          const i = index(position),
            count =
              Math.min(length - 1, position + radius) -
              Math.max(0, position - radius) +
              1;
          if (a) {
            output[i] = r / a;
            output[i + 1] = g / a;
            output[i + 2] = b / a;
          }
          output[i + 3] = a / count;
          add(position - radius, -1);
          add(position + radius + 1, 1);
        }
      }
      input = output;
    }
    return input;
  }
  function retouchRegion(mark, points, width, height) {
    const radius = Math.max(1, (mark.size * width) / 1000),
      effect = Math.max(1, Math.min(100, mark.effect || 60)),
      kernel = Math.max(1, Math.min(32, Math.round((width * effect) / 12000))),
      block = Math.max(2, Math.min(64, Math.round((width * effect) / 4000))),
      xs = points.map((p) => p[0]),
      ys = points.map((p) => p[1]),
      padding = radius + (mark.type === "blur" ? kernel : 0) + 1;
    let left = Math.max(0, Math.floor(Math.min(...xs) - padding)),
      top = Math.max(0, Math.floor(Math.min(...ys) - padding)),
      right = Math.min(width, Math.ceil(Math.max(...xs) + padding)),
      bottom = Math.min(height, Math.ceil(Math.max(...ys) + padding));
    if (mark.type === "pixelate") {
      left = Math.floor(left / block) * block;
      top = Math.floor(top / block) * block;
      right = Math.min(width, Math.ceil(right / block) * block);
      bottom = Math.min(height, Math.ceil(bottom / block) * block);
    }
    const w = right - left,
      h = bottom - top;
    return { left, top, w, h, radius, effect, kernel, block };
  }
  function retouch(ctx, mark, points, width, height) {
    const { left, top, w, h, radius, effect, kernel, block } = retouchRegion(
      mark,
      points,
      width,
      height,
    );
    const pixels = ctx.getImageData(left, top, w, h),
      original = pixels.data,
      modified =
        mark.type === "blur"
          ? blurPixels(original, w, h, kernel)
          : new Uint8ClampedArray(original),
      mask = document.createElement("canvas");
    mask.width = w;
    mask.height = h;
    try {
      if (mark.type === "pixelate") {
        for (let y = 0; y < h; y += block)
          for (let x = 0; x < w; x += block) {
            let r = 0,
              g = 0,
              b = 0,
              a = 0;
            const endX = Math.min(w, x + block),
              endY = Math.min(h, y + block);
            for (let by = y; by < endY; by++)
              for (let bx = x; bx < endX; bx++) {
                const i = (by * w + bx) * 4,
                  alpha = original[i + 3];
                r += original[i] * alpha;
                g += original[i + 1] * alpha;
                b += original[i + 2] * alpha;
                a += alpha;
              }
            if (!a) continue;
            for (let by = y; by < endY; by++)
              for (let bx = x; bx < endX; bx++) {
                const i = (by * w + bx) * 4;
                modified[i] = r / a;
                modified[i + 1] = g / a;
                modified[i + 2] = b / a;
              }
          }
      } else if (mark.type === "redeye") {
        for (let i = 0; i < original.length; i += 4) {
          if (
            original[i] > 60 &&
            original[i] > Math.max(original[i + 1], original[i + 2]) * 1.4
          )
            modified[i] =
              original[i] +
              (((original[i + 1] + original[i + 2]) / 2 - original[i]) *
                effect) /
                100;
        }
      }
      const stencil = mask.getContext("2d");
      strokePath(
        stencil,
        points.map(([x, y]) => [x - left, y - top]),
        radius,
      );
      stencil.fill();
      const coverage = stencil.getImageData(0, 0, w, h).data;
      for (let i = 0; i < original.length; i += 4) {
        if (!coverage[i + 3] || !original[i + 3]) continue;
        const amount = coverage[i + 3] / 255;
        for (let channel = 0; channel < 3; channel++)
          original[i + channel] +=
            (modified[i + channel] - original[i + channel]) * amount;
        // Cosmetic retouch never fills transparency or removes original alpha.
      }
      ctx.putImageData(pixels, left, top);
    } finally {
      mask.width = mask.height = 1;
    }
  }
  async function edit(
    file,
    {
      signal,
      maxBytes = 1700000,
      allowedTypes = ["image/jpeg", "image/png", "image/webp"],
    } = {},
  ) {
    if (opened) throw Error("Zuerst die offene Bildbearbeitung schließen.");
    if (
      !Number.isInteger(maxBytes) ||
      maxBytes < 100000 ||
      maxBytes > 12000000 ||
      !Array.isArray(allowedTypes) ||
      !allowedTypes.length ||
      allowedTypes.some(
        (type) => !["image/jpeg", "image/png", "image/webp"].includes(type),
      )
    )
      throw Error("Bildausgabe prüfen.");
    const controller = new AbortController(),
      abort = () => controller.abort();
    signal?.addEventListener("abort", abort, { once: true });
    if (signal?.aborted) controller.abort();
    let bitmap,
      dialog,
      resolveResult,
      rejectResult,
      closed = false,
      renderSequence = 0,
      oriented,
      orientationKey = "";
    const result = new Promise((resolve, reject) => {
      resolveResult = resolve;
      rejectResult = reject;
    });
    const close = (value, error) => {
      if (closed) return;
      closed = true;
      controller.abort();
      signal?.removeEventListener("abort", abort);
      dialog?.close();
      dialog?.remove();
      bitmap?.close();
      if (oriented) oriented.width = oriented.height = 1;
      document.documentElement.classList.remove("photo-editor-body-lock");
      opened = null;
      error ? rejectResult(error) : resolveResult(value);
    };
    try {
      opened = controller;
      dialog = node("dialog");
      dialog.className = "photo-editor photo-editor--loading";
      dialog.setAttribute("aria-label", photoText("Foto bearbeiten"));
      const loadingHeader = node("header"),
        loading = node("p", photoText("Foto wird vorbereitet …")),
        loadingFooter = node("footer"),
        loadingCancel = node("button", photoText("Abbrechen"));
      loadingHeader.append(node("h2", photoText("Foto bearbeiten")));
      loading.setAttribute("role", "status");
      loadingCancel.type = "button";
      loadingCancel.onclick = () => close(null);
      loadingFooter.append(loadingCancel);
      dialog.append(loadingHeader, loading, loadingFooter);
      document.body.append(dialog);
      dialog.addEventListener("cancel", (event) => {
        event.preventDefault();
        close(null);
      });
      dialog.addEventListener("close", () => close(null));
      controller.signal.addEventListener("abort", () => close(null), {
        once: true,
      });
      window.addEventListener("pagehide", () => close(null), {
        once: true,
        signal: controller.signal,
      });
      document.documentElement.classList.add("photo-editor-body-lock");
      dialog.showModal();
      loadingCancel.focus();
      if (controller.signal.aborted) {
        close(null);
        return await result;
      }
      const photo = await window.KulturhusPhotos.convert(file, {
        signal: controller.signal,
      });
      if (closed) return await result;
      bitmap = await createImageBitmap(photo);
      if (bitmap.width * bitmap.height > 120000000)
        throw Error("Bild bis 120 Megapixel auswählen.");
      if (
        bitmap.width * bitmap.height > MAX_PIXELS ||
        bitmap.width > 8192 ||
        bitmap.height > 8192
      ) {
        const scale = Math.min(2400 / bitmap.width, 2400 / bitmap.height),
          smaller = await createImageBitmap(bitmap, {
            resizeWidth: Math.max(1, Math.round(bitmap.width * scale)),
            resizeHeight: Math.max(1, Math.round(bitmap.height * scale)),
            resizeQuality: "high",
          });
        bitmap.close();
        bitmap = smaller;
      }
      if (closed) {
        bitmap.close();
        return await result;
      }
      let state = initial(),
        undo = [],
        redo = [],
        tab = "crop",
        tool = "brush",
        cloneSource = null,
        chooseCloneSource = true,
        cloneCursor = [0.5, 0.5],
        cloneKeyboard = false,
        exportError = "",
        gesture = null,
        compare = false;
      const pointers = new Map();
      dialog.classList.remove("photo-editor--loading");
      const header = node("header"),
        title = node("h2", photoText("Foto bearbeiten")),
        workspace = node("div"),
        stage = node("div"),
        canvas = node("canvas"),
        aside = node("div"),
        tabs = node("div"),
        footer = node("footer"),
        status = node("span");
      workspace.className = "photo-editor-workspace";
      stage.className = "photo-editor-preview";
      aside.className = "photo-editor-tools";
      tabs.className = "photo-editor-tabs";
      tabs.setAttribute("role", "tablist");
      tabs.setAttribute("aria-label", photoText("Fotowerkzeuge"));
      canvas.tabIndex = 0;
      canvas.setAttribute("aria-label", photoText("Bildvorschau. Im Zuschnitt mit Pfeiltasten verschieben; unter Markieren direkt zeichnen."));
      status.className = "photo-editor-status";
      status.setAttribute("role", "status");
      const button = (parent, text, fn, action) => {
        const b = node("button", photoText(text));
        b.type = "button";
        if (action) b.dataset.photoAction = action;
        b.onclick = fn;
        parent.append(b);
        return b;
      };
      const undoButton = button(header, "↶", () => restore(undo, redo), "undo");
      undoButton.setAttribute("aria-label", photoText("Rückgängig"));
      const redoButton = button(header, "↷", () => restore(redo, undo), "redo");
      redoButton.setAttribute("aria-label", photoText("Wiederherstellen"));
      header.prepend(title);
      const original = button(header, "Original", () => {}, "compare");
      original.dataset.photoCompare = "";
      original.setAttribute("aria-label", photoText("Original zum Vergleich gedrückt halten"));
      const showOriginal = () => {
          compare = true;
          void render();
        },
        showEdited = () => {
          compare = false;
          void render();
        };
      original.addEventListener("pointerdown", showOriginal);
      for (const type of ["pointerup", "pointercancel", "pointerleave", "blur"])
        original.addEventListener(type, showEdited);
      original.addEventListener("keydown", (event) => {
        if (event.key === " " || event.key === "Enter") {
          event.preventDefault();
          showOriginal();
        }
      });
      original.addEventListener("keyup", (event) => {
        if (event.key === " " || event.key === "Enter") {
          event.preventDefault();
          showEdited();
        }
      });
      const panels = new Map(),
        controls = new Map();
      function selectTab(next) {
        tab = next;
        for (const [key, panel] of panels) {
          panel.hidden = key !== tab;
          const b = tabs.querySelector("[data-photo-tab=" + key + "]");
          b.setAttribute("aria-selected", String(key === tab));
          b.tabIndex = key === tab ? 0 : -1;
        }
        canvas.style.cursor =
          tab === "crop" ? "grab" : tab === "mark" ? "crosshair" : "default";
        void render();
      }
      for (const [key, label] of [
        ["crop", "Zuschnitt"],
        ["adjust", "Licht & Farbe"],
        ["filter", "Filter"],
        ["mark", "Markieren"],
        ["export", "Ausgabe"],
      ]) {
        const b = button(tabs, label, () => selectTab(key));
        b.dataset.photoTab = key;
        b.setAttribute("role", "tab");
        b.id = "photo-tool-" + key;
        const panel = node("section");
        panel.id = "photo-panel-" + key;
        panel.className = "photo-editor-panel";
        panel.setAttribute("role", "tabpanel");
        panel.setAttribute("aria-labelledby", b.id);
        b.setAttribute("aria-controls", panel.id);
        panels.set(key, panel);
        aside.append(panel);
      }
      aside.prepend(tabs);
      tabs.addEventListener("keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key))
          return;
        event.preventDefault();
        const list = [...tabs.children],
          index = list.indexOf(document.activeElement);
        const next =
          event.key === "Home"
            ? 0
            : event.key === "End"
              ? list.length - 1
              : (index + (event.key === "ArrowRight" ? 1 : -1) + list.length) %
                list.length;
        selectTab(list[next].dataset.photoTab);
        list[next].focus();
      });
      function commit(before) {
        if (JSON.stringify(before) === JSON.stringify(state)) return;
        exportError = "";
        undo.push(before);
        if (undo.length > 50) undo.shift();
        redo = [];
        sync();
      }
      function change(fn) {
        const before = copy(state);
        fn();
        commit(before);
        void render();
      }
      function restore(from, to) {
        if (!from.length) return;
        exportError = "";
        to.push(copy(state));
        state = from.pop();
        sync();
        void render();
      }
      function range(parent, label, key, min, max, step = 1) {
        label = photoText(label);
        const wrap = node("label", label),
          input = node("input"),
          value = node("output");
        input.type = "range";
        input.setAttribute("aria-label", label);
        input.min = min;
        input.max = max;
        input.step = step;
        input.dataset.photoValue = key;
        controls.set(key, { input, value });
        wrap.append(input, value);
        parent.append(wrap);
        let before;
        input.addEventListener("input", () => {
          before ??= copy(state);
          state[key] = Number(input.value);
          value.textContent = input.value;
          void render();
        });
        input.addEventListener("change", () => {
          if (before) {
            commit(before);
            before = null;
          }
        });
        return input;
      }
      function select(parent, label, key, choices, fn) {
        label = photoText(label);
        const wrap = node("label", label),
          input = node("select");
        input.setAttribute("aria-label", label);
        input.dataset.noSelectSearch = "";
        for (const [value, text] of Object.entries(choices)) {
          const option = node("option", photoText(text));
          option.value = value;
          input.append(option);
        }
        input.dataset.photoValue = key;
        wrap.append(input);
        parent.append(wrap);
        controls.set(key, { input });
        input.onchange = () =>
          fn
            ? fn(input.value)
            : change(
                () =>
                  (state[key] =
                    key === "ratio" ? Number(input.value) : input.value),
              );
        return input;
      }
      const crop = panels.get("crop"),
        cropButtons = node("div");
      cropButtons.className = "photo-editor-actions";
      crop.append(cropButtons);
      button(cropButtons, "Links drehen", () =>
        change(() => (state.rotation -= 90)),
      );
      button(cropButtons, "Rechts drehen", () =>
        change(() => (state.rotation += 90)),
      );
      button(cropButtons, "Horizontal spiegeln", () =>
        change(() => (state.flipX = !state.flipX)),
      );
      button(cropButtons, "Vertikal spiegeln", () =>
        change(() => (state.flipY = !state.flipY)),
      );
      select(crop, "Seitenverhältnis", "ratio", {
        0: "Original",
        "-1": "Freier Zuschnitt",
        1: "Quadrat · 1:1",
        1.3333333333: "Querformat · 4:3",
        1.7777777778: "Querformat · 16:9",
        0.75: "Hochformat · 3:4",
        0.5625: "Story · 9:16",
      });
      const freeCrop = node("div");
      freeCrop.className = "photo-free-crop";
      range(freeCrop, "Ausschnittbreite in Prozent", "cropWidth", 10, 100);
      range(freeCrop, "Ausschnitthöhe in Prozent", "cropHeight", 10, 100);
      crop.append(freeCrop);
      range(crop, "Ausschnitt vergrößern", "zoom", 1, 4, 0.01);
      const fineCrop = node("details");
      fineCrop.append(node("summary", photoText("Fein einstellen")));
      range(fineCrop, "Gerade ausrichten", "straighten", -45, 45, 0.1);
      range(fineCrop, "Ausschnitt horizontal", "x", 0, 100);
      range(fineCrop, "Ausschnitt vertikal", "y", 0, 100);
      crop.append(fineCrop);
      const adjust = panels.get("adjust");
      for (const [label, key, min, max] of [
        ["Helligkeit", "brightness", 0, 200],
        ["Kontrast", "contrast", 0, 200],
        ["Sättigung", "saturation", 0, 200],
        ["Temperatur", "temperature", -100, 100],
        ["Schärfe", "sharpness", 0, 100],
      ])
        range(adjust, label, key, min, max);
      select(panels.get("filter"), "Fotofilter", "filter", filters);
      const mark = panels.get("mark");
      select(
        mark,
        "Werkzeug",
        "tool",
        {
          brush: "Stift",
          clone: "Retusche · Kopierstempel",
          blur: "Weichzeichnen",
          pixelate: "Verpixeln",
          redeye: "Rote Augen",
          line: "Linie",
          arrow: "Pfeil",
          rect: "Rechteck",
          text: "Text",
          redact: "Unkenntlich machen",
        },
        (value) => {
          tool = value;
          cloneControls.hidden = value !== "clone";
          effectWrap.hidden = !retouchTools.has(value);
          colorWrap.hidden =
            value === "clone" || retouchTools.has(value) || value === "redact";
          textWrap.hidden = value !== "text";
          if (value === "clone") {
            chooseCloneSource = !cloneSource;
            status.textContent = chooseCloneSource
              ? photoText("Quellpunkt im Foto wählen.")
              : photoText("Im Foto retuschieren.");
          }
          canvas.setAttribute(
            "aria-label",
            value === "clone"
              ? photoText("Retusche: Pfeile bewegen den Punkt. Leertaste wählt die Quelle, Enter retuschiert.")
              : retouchTools.has(value)
                ? photoText("Retusche: Pfeile bewegen den Punkt. Enter oder Leertaste bearbeitet die Stelle.")
                : photoText("Bildvorschau. Im Zuschnitt mit Pfeiltasten verschieben; unter Markieren direkt zeichnen."),
          );
          void render();
        },
      );
      const cloneControls = node("div");
      cloneControls.hidden = true;
      cloneControls.className = "photo-editor-actions";
      button(cloneControls, "Quellpunkt wählen", () => {
        chooseCloneSource = true;
        status.textContent = photoText("Quellpunkt im Foto wählen.");
        canvas.focus();
      });
      mark.append(cloneControls);
      const colorWrap = node("label", photoText("Farbe")),
        color = node("input");
      color.type = "color";
      color.value = "#d33d31";
      colorWrap.append(color);
      mark.append(colorWrap);
      const sizeWrap = node("label", photoText("Stärke")),
        size = node("input");
      size.type = "range";
      size.min = 1;
      size.max = 80;
      size.value = 12;
      sizeWrap.append(size);
      mark.append(sizeWrap);
      const effectWrap = node("label", photoText("Retuscheintensität")),
        effect = node("input");
      effect.type = "range";
      effect.min = 1;
      effect.max = 100;
      effect.value = 60;
      effectWrap.hidden = true;
      effectWrap.append(effect);
      mark.append(effectWrap);
      const textWrap = node("label", photoText("Eigener Text")),
        text = node("input");
      text.type = "text";
      text.maxLength = 200;
      text.placeholder = photoText("Text eingeben");
      textWrap.append(text);
      textWrap.hidden = true;
      mark.append(textWrap);
      button(mark, "Markierungen entfernen", () =>
        change(() => (state.marks = [])),
      );
      const output = panels.get("export");
      let mime =
        photo.type === "image/png" && allowedTypes.includes("image/png")
          ? "image/png"
          : allowedTypes.includes("image/jpeg")
            ? "image/jpeg"
            : allowedTypes[0];
      const format = select(
        output,
        "Dateiformat",
        "format",
        Object.fromEntries(
          allowedTypes.map((type) => [
            type,
            { "image/jpeg": "JPEG", "image/png": "PNG", "image/webp": "WebP" }[
              type
            ],
          ]),
        ),
        (value) => {
          mime = value;
          exportError = "";
        },
      );
      format.value = mime;
      const widthLabel = node("label", photoText("Breite in Pixel")),
        widthInput = node("input");
      widthInput.type = "number";
      widthInput.min = 32;
      widthInput.max = 8192;
      widthInput.step = 1;
      widthInput.addEventListener("input", () => {
        exportError = "";
        void render();
      });
      output.append(widthLabel);
      widthLabel.append(widthInput);
      const reset = button(output, "Alles zurücksetzen", () =>
        change(() => (state = initial())),
      );
      function geometry(s) {
        const quarter = ((s.rotation % 360) + 360) % 360,
          bw = quarter % 180 ? bitmap.height : bitmap.width,
          bh = quarter % 180 ? bitmap.width : bitmap.height,
          fine = (s.straighten * Math.PI) / 180,
          cos = Math.cos(fine),
          sin = Math.sin(fine),
          c = Math.abs(cos),
          n = Math.abs(sin),
          sw = Math.ceil(bw * c + bh * n),
          sh = Math.ceil(bw * n + bh * c);
        let width = s.ratio === -1 ? (bw * s.cropWidth) / 100 : bw,
          height = s.ratio === -1 ? (bh * s.cropHeight) / 100 : bh;
        const ratio = s.ratio > 0 ? s.ratio : width / height;
        if (width / height > ratio) width = height * ratio;
        else height = width / ratio;
        // Keep the complete crop inside the rotated original, including panning.
        const fit = Math.min(
          1,
          bw / (width * c + height * n),
          bh / (width * n + height * c),
        );
        width = (width * fit) / s.zoom;
        height = (height * fit) / s.zoom;
        const mx = Math.max(0, (bw - width * c - height * n) / 2),
          my = Math.max(0, (bh - width * n - height * c) / 2),
          dx = (s.x / 50 - 1) * mx,
          dy = (s.y / 50 - 1) * my;
        return {
          angle: quarter + s.straighten,
          sw,
          sh,
          width,
          height,
          left: sw / 2 + dx * cos - dy * sin - width / 2,
          top: sh / 2 + dx * sin + dy * cos - height / 2,
        };
      }
      function sync() {
        for (const [key, { input, value }] of controls)
          if (key in state) {
            input.value = state[key];
            if (value) value.textContent = input.value;
          }
        freeCrop.hidden = state.ratio !== -1;
        undoButton.disabled = !undo.length;
        redoButton.disabled = !redo.length;
        const g = geometry(state);
        widthInput.value = Math.max(32, Math.min(2400, Math.round(g.width)));
      }
      const surface = (width, height) => {
        const c = node("canvas");
        c.width = width;
        c.height = height;
        return c;
      };
      async function draw(target, s, width) {
        const g = geometry(s),
          height = Math.max(1, Math.round((width * g.height) / g.width));
        if (
          width < 1 ||
          height < 1 ||
          width > 8192 ||
          height > 8192 ||
          width * height > MAX_PIXELS
        )
          throw Error("Ausgabe bis 8192 Pixel und 16 Megapixel wählen.");
        let retouchPixels = 0;
        for (const mark of s.marks) {
          if (!retouchTools.has(mark.type)) continue;
          const bounds = retouchRegion(
            mark,
            mark.points.map(([x, y]) => [x * width, y * height]),
            width,
            height,
          );
          retouchPixels += bounds.w * bounds.h;
          if (retouchPixels > MAX_PIXELS * 4)
            throw Error(
              "Zu viele große Retuschen. Markierungen reduzieren oder die Ausgabe verkleinern.",
            );
        }
        const key = [g.angle, s.flipX, s.flipY].join(":");
        if (!oriented || orientationKey !== key) {
          if (oriented) oriented.width = oriented.height = 1;
          oriented = surface(g.sw, g.sh);
          const r = oriented.getContext("2d");
          r.translate(g.sw / 2, g.sh / 2);
          r.rotate((g.angle * Math.PI) / 180);
          r.scale(s.flipX ? -1 : 1, s.flipY ? -1 : 1);
          r.drawImage(bitmap, -bitmap.width / 2, -bitmap.height / 2);
          orientationKey = key;
        }
        const adjusted =
          s.temperature ||
          s.brightness !== 100 ||
          s.contrast !== 100 ||
          s.saturation !== 100 ||
          s.filter !== "original";
        target.width = width;
        target.height = height;
        const ctx = target.getContext("2d", {
          willReadFrequently:
            !!adjusted || s.marks.some((m) => retouchTools.has(m.type)),
        });
        ctx.drawImage(
          oriented,
          g.left,
          g.top,
          g.width,
          g.height,
          0,
          0,
          width,
          height,
        );
        // Real pixel transforms also work in browsers without CanvasRenderingContext2D.filter.
        if (adjusted) {
          const pixels = ctx.getImageData(0, 0, width, height),
            p = pixels.data,
            brightness = s.brightness / 100,
            contrast = (s.contrast / 100) * (s.filter === "vivid" ? 1.08 : 1),
            saturation =
              (s.saturation / 100) *
              (s.filter === "warm"
                ? 1.15
                : s.filter === "cool"
                  ? 0.85
                  : s.filter === "vivid"
                    ? 1.4
                    : 1),
            temperature = s.temperature + (s.filter === "cool" ? -18 : 0),
            sepia = s.filter === "sepia" ? 1 : s.filter === "warm" ? 0.22 : 0;
          for (let i = 0; i < p.length; i += 4) {
            let r = (p[i] * brightness - 127.5) * contrast + 127.5,
              g = (p[i + 1] * brightness - 127.5) * contrast + 127.5,
              b = (p[i + 2] * brightness - 127.5) * contrast + 127.5;
            const luma = 0.2126 * r + 0.7152 * g + 0.0722 * b;
            if (s.filter === "mono") r = g = b = luma;
            else {
              r = luma + (r - luma) * saturation;
              g = luma + (g - luma) * saturation;
              b = luma + (b - luma) * saturation;
            }
            if (sepia) {
              const sr = 0.393 * r + 0.769 * g + 0.189 * b,
                sg = 0.349 * r + 0.686 * g + 0.168 * b,
                sb = 0.272 * r + 0.534 * g + 0.131 * b;
              r += (sr - r) * sepia;
              g += (sg - g) * sepia;
              b += (sb - b) * sepia;
            }
            p[i] = r + temperature * 0.35;
            p[i + 1] = g;
            p[i + 2] = b - temperature * 0.35;
          }
          ctx.putImageData(pixels, 0, 0);
        }
        if (s.sharpness) {
          const pixels = ctx.getImageData(0, 0, width, height),
            source = new Uint8ClampedArray(pixels.data),
            p = pixels.data,
            amount = s.sharpness / 200;
          for (let y = 1; y < height - 1; y++)
            for (let x = 1; x < width - 1; x++) {
              const i = (y * width + x) * 4;
              if (!source[i + 3]) continue;
              for (let channel = 0; channel < 3; channel++) {
                let sum = 0;
                for (let neighbor = 0; neighbor < 4; neighbor++) {
                  const j =
                    i +
                    (neighbor === 0
                      ? -4
                      : neighbor === 1
                        ? 4
                        : neighbor === 2
                          ? -width * 4
                          : width * 4);
                  sum += source[j + 3]
                    ? source[j + channel]
                    : source[i + channel];
                }
                p[i + channel] =
                  source[i + channel] +
                  amount * (4 * source[i + channel] - sum);
              }
            }
          ctx.putImageData(pixels, 0, 0);
        }
        let cloneOriginal;
        if (s.marks.some((m) => m.type === "clone")) {
          cloneOriginal = surface(width, height);
          cloneOriginal.getContext("2d").drawImage(target, 0, 0);
        }
        for (const m of s.marks) {
          const points = m.points.map(([x, y]) => [x * width, y * height]),
            [start, end] = [points[0], points.at(-1)];
          if (retouchTools.has(m.type)) {
            try {
              retouch(ctx, m, points, width, height);
            } catch (error) {
              if (cloneOriginal) cloneOriginal.width = cloneOriginal.height = 1;
              throw error;
            }
            continue;
          }
          ctx.save();
          ctx.strokeStyle = ctx.fillStyle = m.color;
          ctx.lineWidth = (m.size * width) / 1000;
          ctx.lineCap = ctx.lineJoin = "round";
          if (m.type === "clone") {
            const radius = Math.max(1, (m.size * width) / 1000),
              offsetX = start[0] - m.source[0] * width,
              offsetY = start[1] - m.source[1] * height;
            strokePath(ctx, points, radius);
            ctx.clip();
            ctx.drawImage(cloneOriginal, offsetX, offsetY);
          } else if (m.type === "text") {
            ctx.font = `${Math.max(12, (m.size * width) / 120)}px Arial`;
            ctx.fillText(m.text, start[0], start[1]);
          } else if (m.type === "redact") {
            ctx.fillStyle = "#181b20";
            ctx.fillRect(
              Math.min(start[0], end[0]),
              Math.min(start[1], end[1]),
              Math.abs(end[0] - start[0]),
              Math.abs(end[1] - start[1]),
            );
          } else if (m.type === "rect")
            ctx.strokeRect(
              start[0],
              start[1],
              end[0] - start[0],
              end[1] - start[1],
            );
          else {
            ctx.beginPath();
            ctx.moveTo(...start);
            for (const point of points.slice(1)) ctx.lineTo(...point);
            ctx.stroke();
            if (m.type === "arrow") {
              const angle = Math.atan2(end[1] - start[1], end[0] - start[0]),
                length = Math.max(10, ctx.lineWidth * 4);
              ctx.beginPath();
              ctx.moveTo(...end);
              ctx.lineTo(
                end[0] - length * Math.cos(angle - 0.5),
                end[1] - length * Math.sin(angle - 0.5),
              );
              ctx.moveTo(...end);
              ctx.lineTo(
                end[0] - length * Math.cos(angle + 0.5),
                end[1] - length * Math.sin(angle + 0.5),
              );
              ctx.stroke();
            }
          }
          ctx.restore();
        }
        if (cloneOriginal) cloneOriginal.width = cloneOriginal.height = 1;
        return { width, height };
      }
      async function render() {
        if (closed) return;
        const id = ++renderSequence;
        try {
          const s = compare ? initial() : copy(state),
            g = geometry(s),
            rect = stage.getBoundingClientRect(),
            scale = Math.min(
              (rect.width - 20) / g.width,
              (rect.height - 20) / g.height,
              1600 / g.width,
              1600 / g.height,
            ),
            width = Math.max(1, Math.round(g.width * scale));
          await draw(canvas, s, width);
          if (!compare && tab === "mark" && tool === "clone" && cloneSource) {
            const ctx = canvas.getContext("2d"),
              x = cloneSource[0] * canvas.width,
              y = cloneSource[1] * canvas.height;
            ctx.save();
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 2;
            ctx.beginPath();
            ctx.arc(x, y, 7, 0, Math.PI * 2);
            ctx.stroke();
            ctx.strokeStyle = "#19212b";
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.restore();
          }
          if (
            !compare &&
            tab === "mark" &&
            (tool === "clone" || retouchTools.has(tool)) &&
            cloneKeyboard
          ) {
            const ctx = canvas.getContext("2d"),
              x = cloneCursor[0] * canvas.width,
              y = cloneCursor[1] * canvas.height;
            ctx.save();
            ctx.strokeStyle = "#19212b";
            ctx.lineWidth = 3;
            ctx.beginPath();
            ctx.moveTo(x - 9, y);
            ctx.lineTo(x + 9, y);
            ctx.moveTo(x, y - 9);
            ctx.lineTo(x, y + 9);
            ctx.stroke();
            ctx.strokeStyle = "#fff";
            ctx.lineWidth = 1;
            ctx.stroke();
            ctx.restore();
          }
          if (id === renderSequence) {
            if (exportError) {
              status.classList.add("photo-editor-error");
              status.textContent = photoText(exportError);
              return;
            }
            status.classList.remove("photo-editor-error");
            status.textContent = compare
              ? photoText("Original")
              : tab === "mark" &&
                  (tool === "clone" || retouchTools.has(tool)) &&
                  cloneKeyboard
                ? photoFormat("Position {x} / {y}", {x: Math.round(cloneCursor[0] * 100), y: Math.round(cloneCursor[1] * 100)})
                : tab === "mark" && tool === "clone" && chooseCloneSource
                  ? photoText("Quellpunkt im Foto wählen.")
                  : photoFormat("{width} × {height} Pixel", {width: Math.round(g.width), height: Math.round(g.height)});
          }
        } catch (error) {
          status.textContent = photoText(error.message);
          status.classList.add("photo-editor-error");
        }
      }
      const point = (event) => {
        const rect = canvas.getBoundingClientRect();
        return [
          Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)),
          Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)),
        ];
      };
      canvas.addEventListener("pointerdown", (event) => {
        if (!["crop", "mark"].includes(tab) || (gesture && tab === "mark"))
          return;
        event.preventDefault();
        canvas.setPointerCapture(event.pointerId);
        const p = point(event);
        cloneKeyboard = false;
        if (
          tab === "mark" &&
          tool === "clone" &&
          (chooseCloneSource || !cloneSource)
        ) {
          cloneSource = p;
          chooseCloneSource = false;
          status.textContent = photoText("Im Foto retuschieren.");
          void render();
          return;
        }
        pointers.set(event.pointerId, p);
        if (gesture) {
          if (pointers.size === 2) {
            const [a, b] = [...pointers.values()];
            gesture.pinch = {
              state: copy(state),
              distance: Math.hypot(a[0] - b[0], a[1] - b[1]),
              center: [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2],
            };
          }
          return;
        }
        gesture = { id: event.pointerId, before: copy(state), point: p };
        if (tab === "mark") {
          if (state.marks.length >= 100) {
            gesture = null;
            pointers.clear();
            status.textContent = photoText("Höchstens 100 Markierungen.");
            return;
          }
          if (tool === "text" && !text.value.trim()) {
            gesture = null;
            pointers.clear();
            text.focus();
            return;
          }
          state.marks.push({
            type: tool,
            source: tool === "clone" ? [...cloneSource] : undefined,
            color: color.value,
            size: Number(size.value),
            effect: Number(effect.value),
            text: text.value.trim(),
            points: [p, p],
          });
        }
        void render();
      });
      canvas.addEventListener("pointermove", (event) => {
        if (!gesture || !pointers.has(event.pointerId)) return;
        const p = point(event);
        pointers.set(event.pointerId, p);
        if (tab === "crop") {
          if (gesture.pinch && pointers.size === 2) {
            const [a, b] = [...pointers.values()],
              pinch = gesture.pinch;
            state.zoom = Math.max(
              1,
              Math.min(
                4,
                (pinch.state.zoom * Math.hypot(a[0] - b[0], a[1] - b[1])) /
                  Math.max(0.001, pinch.distance),
              ),
            );
            state.x = Math.max(
              0,
              Math.min(
                100,
                pinch.state.x - ((a[0] + b[0]) / 2 - pinch.center[0]) * 100,
              ),
            );
            state.y = Math.max(
              0,
              Math.min(
                100,
                pinch.state.y - ((a[1] + b[1]) / 2 - pinch.center[1]) * 100,
              ),
            );
          } else if (!gesture.pinch) {
            state.x = Math.max(
              0,
              Math.min(100, gesture.before.x - (p[0] - gesture.point[0]) * 100),
            );
            state.y = Math.max(
              0,
              Math.min(100, gesture.before.y - (p[1] - gesture.point[1]) * 100),
            );
          }
        } else {
          const m = state.marks.at(-1);
          if (strokeTools.has(m.type)) {
            if (m.points.length < 1000) m.points.push(p);
          } else m.points[1] = p;
        }
        void render();
      });
      const finish = (event) => {
        if (!gesture || !pointers.has(event.pointerId)) return;
        pointers.delete(event.pointerId);
        const before = gesture.before;
        if (event.type === "pointercancel") {
          state = before;
          pointers.clear();
        }
        if (pointers.size) return;
        gesture = null;
        commit(before);
        sync();
        void render();
      };
      for (const type of ["pointerup", "pointercancel", "lostpointercapture"])
        canvas.addEventListener(type, finish);
      canvas.addEventListener("keydown", (event) => {
        if (
          tab === "mark" &&
          (tool === "clone" || retouchTools.has(tool)) &&
          [
            "ArrowLeft",
            "ArrowRight",
            "ArrowUp",
            "ArrowDown",
            " ",
            "Enter",
          ].includes(event.key)
        ) {
          event.preventDefault();
          cloneKeyboard = true;
          if (event.key.startsWith("Arrow")) {
            const axis = /Left|Right/.test(event.key) ? 0 : 1;
            cloneCursor[axis] = Math.max(
              0,
              Math.min(
                1,
                cloneCursor[axis] +
                  (/Left|Up/.test(event.key) ? -1 : 1) *
                    (event.shiftKey ? 0.1 : 0.01),
              ),
            );
            status.textContent =
              photoFormat("Position {x} / {y}", {x: Math.round(cloneCursor[0] * 100), y: Math.round(cloneCursor[1] * 100)});
          } else if (
            tool === "clone" &&
            (event.key === " " || chooseCloneSource || !cloneSource)
          ) {
            cloneSource = [...cloneCursor];
            chooseCloneSource = false;
            status.textContent =
              photoText("Quellpunkt gewählt. Mit Pfeilen verschieben und Enter retuschieren.");
          } else if (state.marks.length < 100) {
            change(() =>
              state.marks.push({
                type: tool,
                source: tool === "clone" ? [...cloneSource] : undefined,
                color: color.value,
                size: Number(size.value),
                effect: Number(effect.value),
                points: [[...cloneCursor], [...cloneCursor]],
              }),
            );
          }
          void render();
          return;
        }
        if (
          tab !== "crop" ||
          !["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(
            event.key,
          )
        )
          return;
        event.preventDefault();
        change(() => {
          const key = /Left|Right/.test(event.key) ? "x" : "y",
            delta = /Left|Up/.test(event.key) ? -1 : 1;
          state[key] = Math.max(
            0,
            Math.min(100, state[key] + delta * (event.shiftKey ? 10 : 1)),
          );
        });
      });
      footer.append(status);
      button(footer, "Abbrechen", () => close(null), "cancel");
      const apply = button(
        footer,
        "Übernehmen",
        async () => {
          let output;
          try {
            exportError = "";
            apply.disabled = true;
            status.textContent = photoText("Bild wird vorbereitet …");
            const width = Number(widthInput.value);
            if (!Number.isInteger(width) || !widthInput.checkValidity())
              throw Error("Gültige Ausgabebreite wählen.");
            output = surface(1, 1);
            await draw(output, copy(state), width);
            if (mime === "image/jpeg") {
              const ctx = output.getContext("2d");
              ctx.globalCompositeOperation = "destination-over";
              ctx.fillStyle = "#fff";
              ctx.fillRect(0, 0, output.width, output.height);
              ctx.globalCompositeOperation = "source-over";
            }
            let blob,
              quality = 0.92;
            for (let attempt = 0; attempt < 12; attempt++) {
              blob = await new Promise((resolve) =>
                output.toBlob(resolve, mime, quality),
              );
              if (!blob || blob.type !== mime)
                throw Error("Gewähltes Dateiformat ist nicht verfügbar.");
              if (blob.size <= maxBytes) break;
              if (mime !== "image/png" && quality > 0.77) quality -= 0.06;
              else {
                const smaller = surface(
                  Math.max(32, Math.round(output.width * 0.8)),
                  Math.max(32, Math.round(output.height * 0.8)),
                );
                smaller
                  .getContext("2d")
                  .drawImage(output, 0, 0, smaller.width, smaller.height);
                output.width = smaller.width;
                output.height = smaller.height;
                output.getContext("2d").drawImage(smaller, 0, 0);
                smaller.width = smaller.height = 1;
              }
            }
            output.width = output.height = 1;
            if (closed) return;
            if (blob.size > maxBytes)
              throw Error(
                "Bild konnte nicht in passender Größe gespeichert werden.",
              );
            const extension = {
              "image/jpeg": "jpg",
              "image/png": "png",
              "image/webp": "webp",
            }[mime];
            close(
              new File(
                [blob],
                photo.name.replace(/\.[^.]*$/, "") + "-bearbeitet." + extension,
                { type: mime, lastModified: Date.now() },
              ),
            );
          } catch (error) {
            exportError = error.message;
            status.textContent = photoText(error.message);
            status.classList.add("photo-editor-error");
            apply.disabled = false;
          } finally {
            if (output) output.width = output.height = 1;
          }
        },
        "apply",
      );
      stage.append(canvas);
      workspace.append(stage, aside);
      dialog.replaceChildren(header, workspace, footer);
      dialog.addEventListener("keydown", (event) => {
        if (
          (event.ctrlKey || event.metaKey) &&
          event.key.toLowerCase() === "z" &&
          !["INPUT", "TEXTAREA"].includes(event.target.tagName)
        ) {
          event.preventDefault();
          restore(event.shiftKey ? redo : undo, event.shiftKey ? undo : redo);
        }
      });
      const observer = new ResizeObserver(() => void render());
      observer.observe(stage);
      controller.signal.addEventListener("abort", () => observer.disconnect(), {
        once: true,
      });
      const viewport = window.visualViewport,
        fitViewport = () => {
          const height =
            (viewport?.height || innerHeight) /
            (Number.parseFloat(
              getComputedStyle(document.documentElement).zoom,
            ) || 1);
          dialog.style.setProperty("--photo-viewport-height", height + "px");
          dialog.classList.toggle("photo-editor--short", height < 520);
        };
      viewport?.addEventListener("resize", fitViewport, {
        signal: controller.signal,
      });
      window.addEventListener("resize", fitViewport, {
        signal: controller.signal,
      });
      fitViewport();
      sync();
      selectTab("crop");
      canvas.focus();
      await render();
      dialog.photoEditor = {
        snapshot: () => copy(state),
        render: () => render(),
        cancel: () => close(null),
      };
    } catch (error) {
      close(null, error);
    }
    return await result;
  }
  window.KulturhusPhotoEditor = { edit };
})();
