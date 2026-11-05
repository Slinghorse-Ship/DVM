/* Manual clone stamps sample one immutable, corrected photo surface. */
(() => {
  "use strict";
  const LIMIT = 64,
    DOCUMENT_LIMIT = 256;
  function validate(stamps) {
    if (!Array.isArray(stamps) || stamps.length > LIMIT)
      throw Error("Höchstens 64 Klonstempel je Foto erlaubt.");
    const keys = ["source_x", "source_y", "target_x", "target_y", "radius"];
    for (const stamp of stamps) {
      if (
        !stamp ||
        typeof stamp !== "object" ||
        Array.isArray(stamp) ||
        Object.keys(stamp).some(
          (key) => ![...keys, "softness", "mode"].includes(key),
        ) ||
        keys.some(
          (key) =>
            !Object.hasOwn(stamp, key) ||
            !Number.isFinite(stamp[key]) ||
            stamp[key] < 0 ||
            stamp[key] > 100,
        )
      )
        throw Error("Vollständigen Klonstempel mit Prozentwerten angeben.");
      if (stamp.radius < 1 || stamp.radius > 25)
        throw Error("Stempelradius zwischen 1 und 25 Prozent angeben.");
      if (
        keys
          .slice(0, 4)
          .some(
            (key) =>
              stamp[key] < stamp.radius || stamp[key] > 100 - stamp.radius,
          )
      )
        throw Error(
          "Quell- und Zielmitte müssen mindestens einen Stempelradius vom Bildrand entfernt liegen.",
        );
      if (
        Object.hasOwn(stamp, "softness") &&
        (!Number.isFinite(stamp.softness) ||
          stamp.softness < 0 ||
          stamp.softness > 100)
      )
        throw Error("Randweichheit zwischen 0 und 100 Prozent angeben.");
    }
    for (const stamp of stamps)
      if (Object.hasOwn(stamp, "mode") && stamp.mode !== "heal")
        throw Error("Gültigen Reparaturpinsel wählen.");
    return stamps;
  }
  function drawPatch(target, patch, left, top, repair) {
    if (!repair) {
      target.drawImage(patch, left, top);
      return;
    }
    target.save();
    try {
      target.globalCompositeOperation = "source-atop";
      target.drawImage(patch, left, top);
    } finally {
      target.restore();
    }
  }
  function matchColor(patch, original, left, top, cx, cy, radius) {
    const context = patch.getContext("2d");
    const sample = context.getImageData(0, 0, patch.width, patch.height);
    const reference = original
      .getContext("2d")
      .getImageData(left, top, patch.width, patch.height);
    const from = sample.data,
      to = reference.data;
    const delta = [0, 0, 0];
    let weight = 0;
    const outer = radius * radius,
      inner = outer * 0.49;
    const step = Math.max(1, Math.ceil(radius / 64));
    for (let y = 0; y < patch.height; y += step)
      for (let x = 0; x < patch.width; x += step) {
        const distance = (x + 0.5 - cx) ** 2 + (y + 0.5 - cy) ** 2;
        if (distance < inner || distance > outer) continue;
        const offset = (y * patch.width + x) * 4;
        const alpha = (from[offset + 3] * to[offset + 3]) / (255 * 255);
        if (!alpha) continue;
        weight += alpha;
        for (let channel = 0; channel < 3; channel++)
          delta[channel] +=
            (to[offset + channel] - from[offset + channel]) * alpha;
      }
    if (!weight) return;
    for (let channel = 0; channel < 3; channel++) delta[channel] /= weight;
    const red = delta[0],
      green = delta[1],
      blue = delta[2];
    // Canvas returns Uint8ClampedArray; alpha stays exact and channel assignment clamps once.
    for (let offset = 0; offset < from.length; offset += 4) {
      from[offset] += red;
      from[offset + 1] += green;
      from[offset + 2] += blue;
    }
    context.putImageData(sample, 0, 0);
  }
  function apply(surface, stamps) {
    validate(stamps);
    if (!stamps.length) return;
    const source = document.createElement("canvas");
    source.width = surface.width;
    source.height = surface.height;
    const sample = source.getContext("2d");
    sample.drawImage(surface, 0, 0);
    const target = surface.getContext("2d");
    try {
      for (const stamp of stamps) {
        const x = (surface.width * stamp.target_x) / 100,
          y = (surface.height * stamp.target_y) / 100,
          radius =
            (Math.min(surface.width, surface.height) * stamp.radius) / 100;
        if (
          stamp.mode === "heal" ||
          (Object.hasOwn(stamp, "softness") && stamp.softness > 0)
        ) {
          const left = Math.floor(x - radius),
            top = Math.floor(y - radius),
            patch = document.createElement("canvas");
          patch.width = Math.ceil(x + radius) - left;
          patch.height = Math.ceil(y + radius) - top;
          try {
            const context = patch.getContext("2d"),
              cx = x - left,
              cy = y - top;
            context.drawImage(
              source,
              left - x + (surface.width * stamp.source_x) / 100,
              top - y + (surface.height * stamp.source_y) / 100,
              patch.width,
              patch.height,
              0,
              0,
              patch.width,
              patch.height,
            );
            if (stamp.mode === "heal")
              matchColor(patch, source, left, top, cx, cy, radius);
            context.globalCompositeOperation = "destination-in";
            if (!(stamp.softness > 0)) {
              context.beginPath();
              context.arc(cx, cy, radius, 0, Math.PI * 2);
              context.fillStyle = "#fff";
              context.fill();
              drawPatch(target, patch, left, top, stamp.mode === "heal");
              continue;
            }
            const mask = context.createRadialGradient(
              cx,
              cy,
              radius * (1 - stamp.softness / 100),
              cx,
              cy,
              radius,
            );
            mask.addColorStop(0, "rgba(255,255,255,1)");
            mask.addColorStop(1, "rgba(255,255,255,0)");
            context.fillStyle = mask;
            context.fillRect(0, 0, patch.width, patch.height);
            drawPatch(target, patch, left, top, stamp.mode === "heal");
          } finally {
            patch.width = patch.height = 0;
          }
          continue;
        }
        target.save();
        try {
          target.beginPath();
          target.arc(x, y, radius, 0, Math.PI * 2);
          target.clip();
          target.drawImage(
            source,
            x - (surface.width * stamp.source_x) / 100,
            y - (surface.height * stamp.source_y) / 100,
          );
        } finally {
          target.restore();
        }
      }
    } finally {
      source.width = source.height = 0;
    }
  }
  function controls({ layer, node, on, change, getDocument, paint, interfaceText = source => source, interfaceFormat }) {
    const uiNode = (tag, text) => node(tag, text === undefined ? undefined : interfaceText(text));
    const format = interfaceFormat || ((source, values) => interfaceText(source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token));
    const detail = uiNode("details"),
      summary = uiNode("summary", "Retuschepinsel"),
      hint = uiNode("p", "Randweichheit: 0 = hart, 100 = weich."),
      count = uiNode(
        "p",
        format("{count} von {maximum} Stempeln gespeichert.", {count: (layer.clone_stamps || []).length, maximum: LIMIT}),
      ),
      inputs = new Map();
    detail.dataset.cloneControls = "";
    detail.append(summary, hint, count);
    const fields = uiNode("div");
    fields.className = "studio-clone-fields";
    const modeLabel = uiNode("label", "Werkzeug"),
      tool = uiNode("select");
    tool.className = "form-select";
    tool.setAttribute("form", "");
    tool.dataset.cloneMode = "";
    for (const [value, title] of [
      ["clone", "Klonstempel"],
      ["heal", "Reparaturpinsel"],
    ]) {
      const option = uiNode("option", title);
      option.value = value;
      tool.append(option);
    }
    tool.value = layer.clone_stamps?.at(-1)?.mode === "heal" ? "heal" : "clone";
    modeLabel.append(tool);
    detail.append(modeLabel);
    detail.append(fields);
    for (const [key, label, value] of [
      ["source_x", "Quelle horizontal (%)", 25],
      ["source_y", "Quelle vertikal (%)", 50],
      ["target_x", "Ziel horizontal (%)", 75],
      ["target_y", "Ziel vertikal (%)", 50],
      ["radius", "Stempelradius (%)", 10],
      ["softness", "Randweichheit (%)", 50],
    ]) {
      const wrap = uiNode("label", label),
        input = uiNode("input");
      Object.assign(input, {
        type: "number",
        min: key === "radius" ? "1" : "0",
        max: key === "radius" ? "25" : "100",
        step: "0.1",
        value: String(layer.clone_stamps?.at(-1)?.[key] ?? value),
      });
      input.className = "form-control";
      // Draft values are validated by Apply, not by the saved composition form.
      input.setAttribute("form", "");
      input.dataset.cloneField = key;
      wrap.append(input);
      fields.append(wrap);
      inputs.set(key, input);
    }
    const error = uiNode("p");
    error.setAttribute("role", "alert");
    error.tabIndex = -1;
    detail.append(error);
    const add = uiNode("button", "Klonstempel anwenden"),
      reset = uiNode("button", "Alle Klonstempel dieses Fotos entfernen");
    add.type = reset.type = "button";
    add.className = "btn btn-solid theme-primary";
    reset.className = "btn btn-outline theme-secondary";
    add.disabled = (layer.clone_stamps || []).length >= LIMIT;
    reset.disabled = !(layer.clone_stamps || []).length;
    on(add, "click", () => {
      try {
        for (const input of inputs.values())
          if (!input.value.trim() || !input.checkValidity())
            throw Error("Gültige Prozentwerte für den Klonstempel angeben.");
        const stamp = Object.fromEntries(
          [...inputs].map(([key, input]) => [key, Number(input.value)]),
        );
        if (tool.value === "heal") stamp.mode = "heal";
        const stamps = validate([...(layer.clone_stamps || []), stamp]);
        if (
          getDocument().layers.reduce(
            (total, l) => total + (l.clone_stamps || []).length,
            0,
          ) >= DOCUMENT_LIMIT
        )
          throw Error(
            "Höchstens 256 Klonstempel in einer Bildfassung erlaubt.",
          );
        change(() => {
          layer.clone_stamps = stamps;
        });
      } catch (cause) {
        error.textContent = interfaceText(cause.message);
        error.focus();
        error.scrollIntoView({ block: "nearest" });
      }
    });
    on(reset, "click", () =>
      change(() => {
        delete layer.clone_stamps;
      }),
    );
    const direct = uiNode("button", "Direkt auf dem Foto stempeln");
    direct.type = "button";
    direct.className = "btn btn-outline theme-secondary";
    direct.disabled = add.disabled;
    on(direct, "click", () => {
      try {
        const settings = {};
        for (const key of ["radius", "softness"]) {
          const input = inputs.get(key);
          if (!input.value.trim() || !input.checkValidity())
            throw Error("Gültigen Radius und Randweichheit angeben.");
          settings[key] = Number(input.value);
        }
        if (tool.value === "heal") settings.mode = "heal";
        paint({ layer, settings, trigger: direct });
      } catch (cause) {
        error.textContent = interfaceText(cause.message);
        error.focus();
        error.scrollIntoView({ block: "nearest" });
      }
    });
    const labels = () => {
      add.textContent =
        interfaceText(tool.value === "heal" ? "Reparatur anwenden" : "Klonstempel anwenden");
      direct.textContent =
        tool.value === "heal"
          ? interfaceText("Direkt auf dem Foto reparieren")
          : interfaceText("Direkt auf dem Foto stempeln");
      reset.textContent = (layer.clone_stamps || []).some(
        (stamp) => stamp.mode === "heal",
      )
        ? interfaceText("Alle Retuschen dieses Fotos entfernen")
        : interfaceText("Alle Klonstempel dieses Fotos entfernen");
    };
    on(tool, "change", labels);
    labels();
    detail.append(direct, add, reset);
    return detail;
  }
  window.KulturhusPhotoClones = Object.freeze({ apply, controls, validate });
})();
