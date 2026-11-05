(() => {
  "use strict";
  const editors = new WeakSet(),
    requirements = new WeakMap();
  const rows = (editor) => [
    ...editor.querySelector("[data-group-rows]").children,
  ];
  const reveal = (element) => {
    for (
      let group = element.closest("[data-field-group]");
      group;
      group = group.parentElement.closest("[data-field-group]")
    )
      group.open = true;
  };
  const syncLayout = (layout) => {
    for (const group of layout.querySelectorAll("[data-field-group]"))
      group.hidden = ![...group.querySelectorAll("[data-model-field]")].some(
        (field) => !field.hidden,
      );
    for (const field of layout.querySelectorAll("[data-model-field]")) {
      const input = field.querySelector("[name^='custom_fields[']"),
        rich = field.querySelector("[data-richtext-surface]");
      const required =
        !field.hidden &&
        (rich ? rich.getAttribute("aria-required") === "true" : input.required);
      if (
        required &&
        !requirements.get(field) &&
        (rich
          ? !window.KulturhusRichText.plain(input.value)
          : input.value === "")
      )
        reveal(field);
      requirements.set(field, required);
    }
  };
  const syncEditor = (editor) => {
    const groups = rows(editor),
      byKey = new Map(groups.map((row) => [row.dataset.groupKey, row]));
    const parent = (row) => row.querySelector('[name$="[parent]"]').value;
    const label = (row) =>
      row.querySelector("[data-group-label]").value.trim() || "Neue Gruppe";
    const path = (row, seen = new Set()) => {
      if (seen.has(row)) return label(row);
      seen.add(row);
      return byKey.has(parent(row))
        ? path(byKey.get(parent(row)), seen) + " / " + label(row)
        : label(row);
    };
    const descendant = (row, key) => {
      const seen = new Set();
      while (row && !seen.has(row)) {
        if (row.dataset.groupKey === key) return true;
        seen.add(row);
        row = byKey.get(parent(row));
      }
      return false;
    };
    const depth = (row) => {
      let level = 1;
      const seen = new Set();
      while (byKey.has(parent(row)) && !seen.has(row)) {
        seen.add(row);
        row = byKey.get(parent(row));
        level++;
      }
      return level;
    };
    const replace = (select, options, empty) => {
      const selected = select.value;
      select.replaceChildren(
        new Option(empty, ""),
        ...options.map((row) => new Option(path(row), row.dataset.groupKey)),
      );
      select.value = [...select.options].some(
        (option) => option.value === selected,
      )
        ? selected
        : "";
    };
    const height = (row, seen = new Set()) => {
      if (seen.has(row)) return 4;
      const branch = new Set(seen);
      branch.add(row);
      return (
        1 +
        Math.max(
          0,
          ...groups
            .filter((child) => parent(child) === row.dataset.groupKey)
            .map((child) => height(child, branch)),
        )
      );
    };
    groups.forEach((row, index) => {
      for (const control of row.querySelectorAll("[name]"))
        control.name = control.name.replace(
          /^groups\[[0-9]+\]/,
          "groups[" + index + "]",
        );
      replace(
        row.querySelector('[name$="[parent]"]'),
        groups.filter(
          (candidate) =>
            !descendant(candidate, row.dataset.groupKey) &&
            depth(candidate) + height(row) <= 3,
        ),
        "Keine übergeordnete Gruppe",
      );
      for (const button of row.querySelectorAll("[data-group-move]")) {
        button.disabled =
          button.dataset.groupMove === "up"
            ? index === 0
            : index === groups.length - 1;
        button.setAttribute(
          "aria-label",
          label(row) +
            (button.dataset.groupMove === "up" ? " nach oben" : " nach unten"),
        );
      }
    });
    const fields = [
      ...editor.querySelector("[data-group-fields]").children,
    ].filter((row) => row.matches("[data-group-field]"));
    fields.forEach((row, index) => {
      replace(row.querySelector("select"), groups, "Ohne Gruppe");
      for (const button of row.querySelectorAll("[data-field-move]"))
        button.disabled =
          button.dataset.fieldMove === "up"
            ? index === 0
            : index === fields.length - 1;
    });
    editor.querySelector("[data-group-add]").disabled = groups.length >= 20;
  };
  const mount = (root = document) => {
    const nodes = [
      ...(root instanceof Element &&
      root.matches("[data-group-editor],.model-fields")
        ? [root]
        : []),
      ...root.querySelectorAll("[data-group-editor],.model-fields"),
    ];
    for (const node of nodes) {
      if (node.matches(".model-fields")) syncLayout(node);
      else if (!editors.has(node)) {
        editors.add(node);
        syncEditor(node);
      }
    }
  };
  document.addEventListener("dvm-fields-updated", (event) =>
    syncLayout(event.target),
  );
  document.addEventListener("invalid", (event) => reveal(event.target), true);
  document.addEventListener("dvm-field-invalid", (event) =>
    reveal(event.target),
  );
  document.addEventListener("input", (event) => {
    const editor = event.target.closest("[data-group-editor]");
    if (editor && event.target.matches("[data-group-label]"))
      syncEditor(editor);
  });
  document.addEventListener("change", (event) => {
    const editor = event.target.closest("[data-group-editor]");
    if (editor && event.target.matches('[name$="[parent]"]'))
      syncEditor(editor);
  });
  document.addEventListener("click", (event) => {
    const button = event.target.closest(
      "[data-group-add],[data-group-remove],[data-group-move],[data-field-move]",
    );
    if (!button || button.disabled) return;
    const editor = button.closest("[data-group-editor]"),
      status = editor.querySelector("[data-group-status]");
    if (button.matches("[data-group-add]")) {
      const fragment = editor
          .querySelector("[data-group-template]")
          .content.cloneNode(true),
        row = fragment.querySelector("[data-group-row]");
      row.dataset.groupKey = "g" + crypto.randomUUID().replaceAll("-", "");
      row.querySelector('[name$="[key]"]').value = row.dataset.groupKey;
      editor.querySelector("[data-group-rows]").append(fragment);
      syncEditor(editor);
      row.querySelector("[data-group-label]").focus();
      status.textContent =
        "Neue Gruppe hinzugefügt. Bitte einen Namen vergeben.";
    } else if (button.matches("[data-group-remove]")) {
      const row = button.closest("[data-group-row]"),
        key = row.dataset.groupKey,
        parent = row.querySelector('[name$="[parent]"]').value;
      for (const select of editor.querySelectorAll(
        '[name$="[parent]"],[name^="field_groups["]',
      ))
        if (select.value === key) select.value = parent;
      row.remove();
      syncEditor(editor);
      editor.querySelector("[data-group-add]").focus();
      status.textContent = "Gruppe entfernt. Ihre Angaben bleiben erhalten.";
    } else {
      const kind = button.matches("[data-group-move]") ? "group" : "field",
        row = button.closest(
          kind === "group" ? "[data-group-row]" : "[data-group-field]",
        ),
        up = button.dataset[kind + "Move"] === "up",
        neighbor = up ? row.previousElementSibling : row.nextElementSibling;
      if (!neighbor) return;
      up ? neighbor.before(row) : neighbor.after(row);
      syncEditor(editor);
      button.focus();
      status.textContent = "Reihenfolge geändert.";
    }
    editor.dispatchEvent(new Event("change", { bubbles: true }));
  });
  window.KulturhusFieldGroups = Object.freeze({mount});
  new MutationObserver((records) => {
    for (const record of records)
      for (const node of record.addedNodes)
        if (node instanceof Element) mount(node);
  }).observe(document.documentElement, { childList: true, subtree: true });
  mount();
})();
