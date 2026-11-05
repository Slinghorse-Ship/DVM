(() => {
  "use strict";
  const groups = new WeakMap(),
    editors = new WeakSet(),
    limits = new WeakMap();
  let nextId = 0;
  const allowedOperators = (definition) => definition && (definition.multiple || ['repeater','pagetable','password'].includes(definition.type)) ? ['empty','filled'] : [
    "eq",
    "ne",
    "empty",
    "filled",
    ...(definition &&
    ["text", "textarea", "richtext", "url", "email", "select"].includes(
      definition.type,
    )
      ? ["contains"]
      : []),
    ...(definition && ["integer", "decimal", "date"].includes(definition.type)
      ? ["gt", "gte", "lt", "lte"]
      : []),
  ];
  const trim = (value) => value.replace(/^[ \t\n\r\0\v]+|[ \t\n\r\0\v]+$/g, "");
  const decimal = (value) => {
    const match = /^(-?)([0-9]{1,18})(?:\.([0-9]{1,6}))?$/.exec(
      value.replace(",", "."),
    );
    if (!match) return null;
    const units =
      BigInt(match[2]) * 1000000n + BigInt((match[3] || "").padEnd(6, "0"));
    return match[1] === "-" ? -units : units;
  };
  const normalized = (type, raw) => {
    raw = trim(raw.replace(/\r\n?/g, "\n"));
    if (raw === "") return null;
    if (type === "boolean")
      return raw === "1" ? true : raw === "0" ? false : null;
    if (type === "integer") return /^-?[0-9]+$/.test(raw) ? Number(raw) : null;
    if (type === "decimal") return decimal(raw);
    if (type === "richtext") return window.KulturhusRichText.plain(raw) || null;
    return raw;
  };
  const matches = (rule, definitions, values) => {
    const results = rule.conditions.map((condition) => {
      const value = values[condition.field],
        op = condition.operator;
      if (op === "empty") return value === null;
      if (op === "filled") return value !== null;
      const expected =
        condition.value === null
          ? null
          : definitions[condition.field].type === "richtext"
            ? trim(String(condition.value))
            : normalized(
                definitions[condition.field].type,
                typeof condition.value === "boolean"
                  ? condition.value
                    ? "1"
                    : "0"
                  : String(condition.value),
              );
      if (op === "contains")
        return value !== null && expected !== null && value.includes(expected);
      if (value === null || expected === null)
        return op === "eq"
          ? value === expected
          : op === "ne"
            ? value !== expected
            : false;
      return {
        eq: () => value === expected,
        ne: () => value !== expected,
        gt: () => value > expected,
        gte: () => value >= expected,
        lt: () => value < expected,
        lte: () => value <= expected,
      }[op]();
    });
    return rule.match === "all"
      ? results.every(Boolean)
      : results.some(Boolean);
  };
  const update = (group, announce = false) => {
    const state = groups.get(group),
      values = {},
      changes = [];
    const prefix = group.dataset.fieldPrefix || 'custom_fields';
    for (const [key, field] of Object.entries(state.definitions)) {
      const wrapper = group.querySelector('[data-model-field="' + key + '"]');
      if (wrapper?.matches('[data-typed-collection]')) {
        const stored = [...wrapper.querySelectorAll('input[type=hidden][name$="[id]"]')].some(input => input.value !== '');
        const filled = [...wrapper.querySelectorAll('[data-field-type]')].some(control => normalized(control.dataset.fieldType, control.value || '') !== null);
        values[key] = stored || filled ? 'filled' : null;
      } else {
        const control = group.querySelector('[name="' + prefix + '[' + key + ']"]') || group.querySelector('[name="' + prefix + '[' + key + '][value]"]');
        const cleared = field.type === 'password' && wrapper.querySelector('[data-password-clear]')?.checked;
        values[key] = cleared ? null : field.type === 'password' && control?.dataset.passwordStored === '1' && control.value === '' ? 'stored' : normalized(field.type, control?.value || '');
      }
    }
    const emptyFresh = group.dataset.emptyRepeater === '1' && Object.values(values).every(value => value === null);
    for (const [key, field] of Object.entries(state.definitions)) {
      const wrapper = group.querySelector('[data-model-field="' + key + '"]');
      const visible =
        field.display !== 'hidden' && (!field.show_when || matches(field.show_when, state.definitions, values));
      const required =
        visible && !emptyFresh &&
        (field.required ||
          (!!field.required_when &&
            matches(field.required_when, state.definitions, values)));
      const previous = state.previous.get(key);
      wrapper.hidden = !visible;
      if (!visible && wrapper.contains(document.activeElement))
        group
          .querySelector(
            '[name="' + prefix + '[' +
              (field.show_when?.conditions[0].field || key) +
              ']"]',
          )
          ?.focus();
      for (const control of wrapper.querySelectorAll(
        '[name="' + prefix + '[' + key + ']"],[name="' + prefix + '[' + key + '][value]"]',
      )) {
        if (control.type !== "hidden") control.required = required && !(control.type === 'password' && control.dataset.passwordStored === '1');
        if (!limits.has(control))
          limits.set(
            control,
            Object.fromEntries(
              ["min", "max", "minlength", "maxlength", "step", "pattern"]
                .filter((name) => control.hasAttribute(name))
                .map((name) => [name, control.getAttribute(name)]),
            ),
          );
        for (const [name, value] of Object.entries(limits.get(control)))
          visible
            ? control.setAttribute(name, value)
            : control.removeAttribute(name);
        if (!visible) control.setCustomValidity?.("");
      }
      const rich = wrapper.querySelector("[data-kh-richtext]");
      if (rich) {
        rich.dataset.richtextRequired = required ? "1" : "0";
        rich
          .querySelector("[data-richtext-surface]")
          .setAttribute("aria-required", String(required));
      }
      const hint = wrapper.querySelector("#native-custom-" + key + "-hint");
      if (hint)
        hint.textContent = hint.textContent.replace(
          / · (Pflichtfeld|optional)(?=\s·|$)/,
          required ? " · Pflichtfeld" : " · optional",
        );
      if (
        !previous ||
        previous.visible !== visible ||
        previous.required !== required
      ) {
        rich?.dispatchEvent(new Event("dvm-field-state"));
        if (previous)
          changes.push(
            wrapper.dataset.fieldLabel +
              (visible
                ? required
                  ? " ist jetzt Pflichtfeld."
                  : " ist jetzt optional."
                : " ist jetzt ausgeblendet."),
          );
      }
      state.previous.set(key, { visible, required });
    }
    if (announce && changes.length)
      group.querySelector("[data-field-condition-status]").textContent = changes
        .slice(0, 3)
        .join(" ");
    group.dispatchEvent(
      new CustomEvent("dvm-fields-updated", { bubbles: true }),
    );
  };
  const setEditor = (editor) => {
    const enabled = editor.querySelector("[data-condition-enabled]").checked;
    editor.querySelector("[data-condition-body]").hidden = !enabled;
    editor
      .querySelector("[data-condition-body]")
      .querySelectorAll("input,select,button,textarea")
      .forEach((control) => (control.disabled = !enabled));
    if (enabled)
      editor.querySelectorAll("[data-condition-row]").forEach((row) => {
        const source = row.querySelector('[name$="[field]"]'),
          catalog = JSON.parse(
            source.closest("[data-condition-catalog]").dataset.conditionCatalog,
          );
        const allowed = allowedOperators(catalog[source.value]);
        for (const option of row.querySelector('[name$="[operator]"]').options)
          option.disabled = !allowed.includes(option.value);
        setValueVisibility(row);
      });
    editor.querySelector("[data-condition-add]").disabled =
      !enabled || editor.querySelectorAll("[data-condition-row]").length >= 10;
  };
  const setValueVisibility = (row) => {
    const operator = row.querySelector('[name$="[operator]"]').value;
    const hidden = ["empty", "filled"].includes(operator),
      container = row.querySelector("[data-condition-value]");
    container.hidden = hidden;
    container
      .querySelectorAll("input,select,button,textarea")
      .forEach(
        (control) =>
          (control.disabled =
            hidden ||
            !row
              .closest("[data-condition-editor]")
              .querySelector("[data-condition-enabled]").checked ||
            (control.dataset.referenceAction === "previous" &&
              Number(
                control.closest("[data-reference-picker]").dataset
                  .referencePage,
              ) <= 1) ||
            (control.dataset.referenceAction === "next" &&
              Number(
                control.closest("[data-reference-picker]").dataset
                  .referencePage,
              ) >=
                Number(
                  control.closest("[data-reference-picker]").dataset
                    .referenceLast,
                ))),
      );
  };
  const labelInput = (name, type = "text") => {
    const label = document.createElement("label"),
      input = document.createElement("input");
    label.append("Vergleichswert");
    input.className = "form-control";
    input.name = name;
    input.type = type;
    input.maxLength = 2000;
    label.append(input);
    return label;
  };
  const changeSource = (row, preserve = false) => {
    const source = row.querySelector('[name$="[field]"]'),
      catalog = JSON.parse(
        source.closest("[data-condition-catalog]").dataset.conditionCatalog,
      ),
      definition = catalog[source.value];
    const operator = row.querySelector('[name$="[operator]"]');
    const allowed = allowedOperators(definition);
    for (const option of operator.options)
      option.disabled = !allowed.includes(option.value);
    if (!allowed.includes(operator.value)) operator.value = "eq";
    const container = row.querySelector("[data-condition-value]"),
      name = source.name.replace(/\[field\]$/, "[value]");
    const previous = container.querySelector('[name$="[value]"]'),
      oldValue = preserve ? previous?.value || "" : "",
      oldLabel =
        previous?.selectedOptions?.[0]?.textContent ||
        "Gespeicherter Vergleichswert";
    if (
      definition &&
      ["page-reference", "media-reference"].includes(definition.type)
    ) {
      const kind = definition.type === "page-reference" ? "page" : "media";
      const template = source
        .closest("[data-condition-catalog]")
        .querySelector('[data-condition-reference-template="' + kind + '"]');
      const fragment = template.content.cloneNode(true),
        id = "dvm-condition-reference-" + ++nextId;
      const select = fragment.querySelector("[data-reference-select]");
      select.name = name;
      select.id = id;
      const search = fragment.querySelector("[data-reference-query]");
      search.id = id + "-search";
      fragment.querySelector("label").htmlFor = search.id;
      const label = document.createElement("label");
      label.htmlFor = id;
      label.textContent = "Vergleichswert";
      container.replaceChildren(label, fragment);
    } else if (
      definition &&
      operator.value !== "contains" &&
      ["boolean", "select"].includes(definition.type)
    ) {
      const label = document.createElement("label"),
        select = document.createElement("select");
      label.append("Vergleichswert");
      select.className = "form-control";
      select.name = name;
      select.append(new Option("Leer", ""));
      for (const [value, text] of definition.type === "boolean"
        ? [
            ["1", "Ja"],
            ["0", "Nein"],
          ]
        : definition.options.map((value) => [value, value]))
        select.append(new Option(text, value));
      label.append(select);
      container.replaceChildren(label);
    } else {
      const label = labelInput(
        name,
        definition?.type === "date" ? "date" : "text",
      );
      if (definition?.type === "decimal")
        label.querySelector("input").inputMode = "decimal";
      container.replaceChildren(label);
    }
    if (preserve) {
      const control = container.querySelector('[name$="[value]"]');
      if (
        control.tagName === "SELECT" &&
        oldValue &&
        ![...control.options].some((option) => option.value === oldValue)
      )
        control.append(new Option(oldLabel, oldValue));
      control.value = oldValue;
    }
    setValueVisibility(row);
  };
  const mount = (root = document) => {
    const own = [];
    if (root instanceof Element) own.push(root);
    const nodes = [
      ...own,
      ...root.querySelectorAll(
        "[data-field-conditions],[data-condition-editor]",
      ),
    ];
    for (const node of nodes) {
      if (node.matches("[data-field-conditions]") && !groups.has(node)) {
        groups.set(node, {
          definitions: JSON.parse(node.dataset.fieldConditions),
          previous: new Map(),
        });
        update(node);
      }
      if (node.matches("[data-condition-editor]") && !editors.has(node)) {
        editors.add(node);
        setEditor(node);
      }
    }
  };
  for (const type of ["input", "change"])
    document.addEventListener(type, (event) => {
      const group = event.target.closest("[data-field-conditions]");
      if (group) update(group, true);
      if (type !== "change") return;
      const editor = event.target.closest("[data-condition-editor]");
      if (!editor) return;
      if (event.target.matches("[data-condition-enabled]")) setEditor(editor);
      else if (event.target.matches('[name$="[field]"]'))
        changeSource(event.target.closest("[data-condition-row]"));
      else if (event.target.matches('[name$="[operator]"]'))
        changeSource(event.target.closest("[data-condition-row]"), true);
    });
  document.addEventListener("click", (event) => {
    const button = event.target.closest(
      "[data-condition-add],[data-condition-remove]",
    );
    if (!button || button.disabled) return;
    const editor = button.closest("[data-condition-editor]"),
      rows = editor.querySelector("[data-condition-rows]");
    if (button.matches("[data-condition-remove]")) {
      button.closest("[data-condition-row]").remove();
    } else {
      const used = new Set(
        [...rows.querySelectorAll('[name$="[field]"]')].map((control) =>
          Number(/\[conditions\]\[(\d+)\]/.exec(control.name)[1]),
        ),
      );
      const index = Array.from({ length: 10 }, (_, index) => index).find(
        (index) => !used.has(index),
      );
      if (index === undefined) return;
      const fragment = editor
        .querySelector("[data-condition-row-template]")
        .content.cloneNode(true);
      for (const element of fragment.querySelectorAll("[name]"))
        element.name = element.name.replace("__INDEX__", String(index));
      rows.append(fragment);
    }
    setEditor(editor);
    rows.querySelector("[data-condition-row]:last-child select")?.focus();
  });
  window.KulturhusFieldConditions = Object.freeze({mount});
  new MutationObserver((records) => {
    for (const record of records)
      for (const node of record.addedNodes)
        if (node instanceof Element) mount(node);
  }).observe(document.documentElement, { childList: true, subtree: true });
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", () => mount(), {
      once: true,
    });
  else mount();
})();
