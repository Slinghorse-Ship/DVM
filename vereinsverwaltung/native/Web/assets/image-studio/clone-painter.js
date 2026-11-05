/* Local photo retouching. Draft strokes become one normal editor history entry. */
(() => {
  "use strict";
  function open({
    interfaceText = source => source,
    interfaceFormat,
    root,
    layer,
    settings,
    image,
    commit,
    maximum,
    signal,
    trigger,
  }) {
    const make = (tag, text) => {
      const element = document.createElement(tag);
      if (text !== undefined) element.textContent = interfaceText(text);
      return element;
    };
    const format = interfaceFormat || ((source, values) => interfaceText(source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token));
    const controller = new AbortController(),
      dialog = make("dialog"),
      title = make("h2", "Foto retuschieren"),
      body = make("div"),
      toolbar = make("div"),
      sourceButton = make("button", "Quelle wählen"),
      paintButton = make("button", "Stempeln"),
      stage = make("div"),
      canvas = make("canvas"),
      original = make("canvas"),
      preview = make("canvas"),
      status = make("p", "Foto wird geladen …"),
      footer = make("div"),
      cancel = make("button", "Verwerfen"),
      undo = make("button", "Strich zurück"),
      apply = make("button", "Anwenden"),
      fields = new Map(),
      base = structuredClone(layer.clone_stamps || []),
      strokes = [];
    let closed = false,
      ready = false,
      source = null,
      mode = "source",
      current = null,
      cursor = { x: 50, y: 50 },
      frame = 0;
    dialog.className = "studio-clone-dialog";
    dialog.setAttribute("aria-label", interfaceText("Foto retuschieren"));
    body.className = "studio-clone-body";
    toolbar.className = "studio-clone-modes";
    stage.className = "studio-clone-stage";
    footer.className = "studio-clone-footer";
    status.setAttribute("role", "status");
    canvas.tabIndex = 0;
    canvas.setAttribute(
      "aria-label",
      interfaceText("Retuschefoto. Pfeiltasten bewegen den Cursor; Enter wählt die Quelle oder stempelt."),
    );
    canvas.style.touchAction = "none";
    for (const button of [sourceButton, paintButton, cancel, undo, apply]) {
      button.type = "button";
      button.className = "btn btn-outline theme-secondary";
    }
    apply.className = "btn btn-solid theme-primary";
    const toolLabel = make("label", "Werkzeug"),
      tool = make("select");
    tool.className = "form-select";
    tool.setAttribute("form", "");
    tool.dataset.paintMode = "";
    for (const [value, title] of [
      ["clone", "Klonstempel"],
      ["heal", "Reparaturpinsel"],
    ]) {
      const option = make("option", title);
      option.value = value;
      tool.append(option);
    }
    tool.value = settings.mode === "heal" ? "heal" : "clone";
    toolLabel.append(tool);
    toolbar.append(toolLabel);
    toolbar.append(sourceButton, paintButton);
    const controls = make("div");
    controls.className = "studio-clone-fields";
    for (const [key, label, min, max] of [
      ["radius", "Radius (%)", 1, 25],
      ["softness", "Randweichheit (%)", 0, 100],
    ]) {
      const wrap = make("label", label),
        input = make("input");
      Object.assign(input, {
        type: "number",
        min: String(min),
        max: String(max),
        step: "0.1",
        value: String(settings[key]),
      });
      input.setAttribute("form", "");
      input.className = "form-control";
      input.dataset.paintField = key;
      wrap.append(input);
      controls.append(wrap);
      fields.set(key, input);
    }
    stage.append(canvas);
    body.append(toolbar, controls, stage, status);
    footer.append(cancel, undo, apply);
    dialog.append(title, body, footer);
    root.append(dialog);
    const on = (target, type, fn) =>
      target.addEventListener(type, fn, { signal: controller.signal });
    const stamps = () => [
      ...base,
      ...strokes.flat(),
      ...(current?.stamps || []),
    ];
    const count = () => stamps().length - base.length;
    const values = () => {
      for (const input of fields.values())
        if (!input.value.trim() || !input.checkValidity())
          throw Error("Gültigen Radius und Randweichheit angeben.");
      const result = Object.fromEntries(
        [...fields].map(([key, input]) => [key, Number(input.value)]),
      );
      if (tool.value === "heal") result.mode = "heal";
      return result;
    };
    const inside = (point, radius) =>
      point &&
      [point.x, point.y].every(
        (n) => Number.isFinite(n) && n >= radius && n <= 100 - radius,
      );
    const rounded = (point) => ({
      x: Math.round(point.x * 10) / 10,
      y: Math.round(point.y * 10) / 10,
    });
    const message = (text) => {
      if (!closed) status.textContent = interfaceText(text);
    };
    const update = () => {
      sourceButton.setAttribute("aria-pressed", String(mode === "source"));
      paintButton.setAttribute("aria-pressed", String(mode === "paint"));
      sourceButton.disabled = !ready || !!current;
      tool.disabled = !ready || !!current;
      paintButton.textContent =
        interfaceText(tool.value === "heal" ? "Reparieren" : "Stempeln");
      paintButton.disabled = !ready || !source || !!current;
      undo.disabled = !strokes.length || !!current;
      apply.disabled = !count() || !!current;
      for (const input of fields.values()) input.disabled = !!current;
    };
    function draw() {
      if (closed || !ready) return;
      const context = preview.getContext("2d");
      context.clearRect(0, 0, preview.width, preview.height);
      context.drawImage(original, 0, 0);
      window.KulturhusPhotoClones.apply(preview, stamps());
      const visible = canvas.getContext("2d");
      visible.clearRect(0, 0, canvas.width, canvas.height);
      visible.drawImage(preview, 0, 0);
      visible.lineWidth = 2;
      if (source) {
        const x = (canvas.width * source.x) / 100,
          y = (canvas.height * source.y) / 100;
        visible.strokeStyle = "#fff";
        visible.beginPath();
        visible.moveTo(x - 8, y);
        visible.lineTo(x + 8, y);
        visible.moveTo(x, y - 8);
        visible.lineTo(x, y + 8);
        visible.stroke();
      }
      const radius =
        (Math.min(canvas.width, canvas.height) *
          (current?.settings.radius ||
            Number(fields.get("radius").value) ||
            10)) /
        100;
      visible.strokeStyle = "#f5c542";
      visible.beginPath();
      visible.arc(
        (canvas.width * cursor.x) / 100,
        (canvas.height * cursor.y) / 100,
        radius,
        0,
        Math.PI * 2,
      );
      visible.stroke();
      update();
    }
    const schedule = () => {
      if (!frame && !closed)
        frame = requestAnimationFrame(() => {
          frame = 0;
          draw();
        });
    };
    function close() {
      if (closed) return;
      closed = true;
      signal.removeEventListener("abort", close);
      controller.abort();
      if (frame) cancelAnimationFrame(frame);
      dialog.close();
      dialog.remove();
      for (const surface of [original, preview, canvas])
        surface.width = surface.height = 0;
      strokes.length = 0;
      current = source = null;
      const focus = trigger?.isConnected
        ? trigger
        : root.querySelector("[data-studio=undo]");
      if (focus?.isConnected && !focus.disabled) focus.focus();
    }
    const fit = () => {
      const height =
        (window.visualViewport?.height || innerHeight) /
        (parseFloat(getComputedStyle(document.documentElement).zoom) || 1);
      dialog.style.setProperty("--clone-viewport-height", height + "px");
      if (ready) {
        const ratio = canvas.width / canvas.height,
          available = Math.min(
            Math.max(112, Math.min(384, height * 0.35)),
            stage.clientWidth / ratio,
          ),
          width = Math.min(stage.clientWidth, available * ratio);
        stage.style.height = available + "px";
        canvas.style.width = width + "px";
        canvas.style.height = width / ratio + "px";
      }
    };
    signal.addEventListener("abort", close, { once: true });
    on(dialog, "cancel", (event) => {
      event.preventDefault();
      close();
    });
    on(dialog, "close", close);
    on(cancel, "click", close);
    on(window, "resize", fit);
    if (window.visualViewport) on(window.visualViewport, "resize", fit);
    on(sourceButton, "click", () => {
      mode = "source";
      message("Quelle im Foto antippen.");
      update();
      canvas.focus();
    });
    on(paintButton, "click", () => {
      mode = "paint";
      message("Über das Foto ziehen oder mit Enter stempeln.");
      update();
      canvas.focus();
    });
    on(undo, "click", () => {
      strokes.pop();
      message("Letzter Strich zurückgenommen.");
      draw();
    });
    on(apply, "click", () => {
      if (current || !count()) return;
      try {
        const result = window.KulturhusPhotoClones.validate(stamps());
        commit(structuredClone(result));
        close();
      } catch (error) {
        message(error.message);
      }
    });
    on(tool, "change", update);
    for (const input of fields.values())
      on(input, "input", () => {
        schedule();
      });
    const point = (event) => {
      const box = canvas.getBoundingClientRect();
      return rounded({
        x: ((event.clientX - box.left) * 100) / box.width,
        y: ((event.clientY - box.top) * 100) / box.height,
      });
    };
    function add(target) {
      const state = current;
      const sample = rounded({
        x: state.source.x + target.x - state.anchor.x,
        y: state.source.y + target.y - state.anchor.y,
      });
      if (
        !inside(target, state.settings.radius) ||
        !inside(sample, state.settings.radius)
      ) {
        state.last = null;
        state.notice = "Quell- oder Zielbereich liegt außerhalb des Fotos.";
        message(state.notice);
        return false;
      }
      if (count() >= maximum) {
        state.notice =
          "Stempelgrenze erreicht. Strich zurücknehmen oder Gestaltung anwenden.";
        message(state.notice);
        return false;
      }
      state.stamps.push({
        source_x: sample.x,
        source_y: sample.y,
        target_x: target.x,
        target_y: target.y,
        ...state.settings,
      });
      state.last = target;
      return true;
    }
    function move(target, final = false) {
      cursor = target;
      if (!current) {
        schedule();
        return;
      }
      const last = current.last;
      if (!last) {
        add(target);
        schedule();
        return;
      }
      const dx = ((target.x - last.x) * canvas.width) / 100,
        dy = ((target.y - last.y) * canvas.height) / 100,
        distance = Math.hypot(dx, dy),
        spacing = Math.max(
          1,
          (Math.min(canvas.width, canvas.height) * current.settings.radius) /
            200,
        ),
        steps = Math.min(maximum - count(), Math.floor(distance / spacing));
      for (let i = 1; i <= steps; i++) {
        const part = (i * spacing) / distance;
        if (
          !add(
            rounded({
              x: last.x + (target.x - last.x) * part,
              y: last.y + (target.y - last.y) * part,
            }),
          )
        )
          break;
      }
      if (
        final &&
        current.last &&
        Math.hypot(
          ((target.x - current.last.x) * canvas.width) / 100,
          ((target.y - current.last.y) * canvas.height) / 100,
        ) > 0.5
      )
        add(target);
      schedule();
    }
    function start(target, pointer = null) {
      if (!ready || current) return;
      try {
        const options = values();
        cursor = target;
        if (!inside(target, options.radius))
          throw Error(
            "Mitte mindestens einen Radius vom Fotorand entfernt wählen.",
          );
        if (mode === "source") {
          source = target;
          mode = "paint";
          message(
            "Quelle gewählt. Über das Foto ziehen oder mit Enter stempeln.",
          );
        } else {
          if (!inside(source, options.radius))
            throw Error("Quelle für diesen Radius erneut wählen.");
          current = {
            pointer,
            source: { ...source },
            anchor: target,
            settings: options,
            stamps: [],
            last: null,
          };
          add(target);
          if (pointer !== null) canvas.setPointerCapture(pointer);
        }
        update();
        schedule();
      } catch (error) {
        message(error.message);
      }
    }
    function end(cancelled = false) {
      if (!current) return;
      const finished = current;
      current = null;
      if (!cancelled && finished.stamps.length) strokes.push(finished.stamps);
      if (
        finished.pointer !== null &&
        canvas.hasPointerCapture(finished.pointer)
      )
        canvas.releasePointerCapture(finished.pointer);
      message(
        cancelled
          ? "Strich verworfen."
          : finished.notice ||
              format("{count} neue Stempel. Anwenden übernimmt die Retusche.", {count: count()}),
      );
      update();
      schedule();
    }
    on(canvas, "pointerdown", (event) => {
      if (!event.isPrimary || event.button !== 0 || current) return;
      event.preventDefault();
      canvas.focus();
      start(point(event), event.pointerId);
    });
    on(canvas, "pointermove", (event) => {
      if (current?.pointer !== event.pointerId) return;
      move(point(event));
    });
    on(canvas, "pointerup", (event) => {
      if (current?.pointer !== event.pointerId) return;
      move(point(event), true);
      end();
    });
    on(canvas, "pointercancel", (event) => {
      if (current?.pointer === event.pointerId) end(true);
    });
    on(canvas, "lostpointercapture", (event) => {
      if (current?.pointer === event.pointerId) end(true);
    });
    on(canvas, "keydown", (event) => {
      if (current) return;
      const step = event.shiftKey ? 5 : 1;
      if (
        ["ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown"].includes(event.key)
      ) {
        event.preventDefault();
        if (event.key === "ArrowLeft") cursor.x = Math.max(0, cursor.x - step);
        if (event.key === "ArrowRight")
          cursor.x = Math.min(100, cursor.x + step);
        if (event.key === "ArrowUp") cursor.y = Math.max(0, cursor.y - step);
        if (event.key === "ArrowDown")
          cursor.y = Math.min(100, cursor.y + step);
        schedule();
      } else if (["Enter", " "].includes(event.key)) {
        event.preventDefault();
        start({ ...cursor });
        end();
      }
    });
    fit();
    update();
    dialog.showModal();
    cancel.focus();
    void image()
      .then((photo) => {
        if (closed) return;
        const width = photo.naturalWidth || photo.width,
          height = photo.naturalHeight || photo.height,
          scale = Math.min(1, 1024 / Math.max(width, height));
        if (!width || !height) throw Error("Foto ist nicht lesbar.");
        for (const surface of [original, preview, canvas]) {
          surface.width = Math.max(1, Math.round(width * scale));
          surface.height = Math.max(1, Math.round(height * scale));
        }
        original
          .getContext("2d")
          .drawImage(photo, 0, 0, original.width, original.height);
        ready = true;
        fit();
        message("Quelle im Foto antippen.");
        draw();
        sourceButton.focus();
      })
      .catch((error) => {
        message(error.message);
      });
    return Object.freeze({ close });
  }
  window.KulturhusPhotoClonePainter = Object.freeze({ open });
})();
