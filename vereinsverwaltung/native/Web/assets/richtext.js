(() => {
  "use strict";
  const contentText = source => {
    const read=window.KulturhusInterface?.text;if(!read)return source;
    for(const prefix of ['content:','media:']){const key=prefix+source,value=read(key);if(value!==key)return value;}
    return source;
  };
  const contentFormat=(source,values)=>contentText(source).replace(/\{([a-z_]+)\}/g,(token,key)=>Object.hasOwn(values,key)?String(values[key]):token);
  const mounted = new WeakSet();
  const tags = new Set([
    "p",
    "h2",
    "h3",
    "h4",
    "strong",
    "em",
    "u",
    "s",
    "blockquote",
    "ul",
    "ol",
    "li",
    "br",
    "span",
    "a",
    "table",
    "thead",
    "tbody",
    "tr",
    "th",
    "td",
  ]);
  const drop = new Set([
    "script",
    "style",
    "iframe",
    "object",
    "embed",
    "svg",
    "math",
    "img",
    "picture",
    "video",
    "audio",
    "source",
    "track",
    "link",
    "meta",
    "base",
    "form",
    "input",
    "button",
    "textarea",
    "select",
    "option",
    "template",
    "noscript",
  ]);
  const classes = new Set([
    "kh-rt-size-small",
    "kh-rt-size-normal",
    "kh-rt-size-large",
    "kh-rt-size-xlarge",
    "kh-rt-align-left",
    "kh-rt-align-center",
    "kh-rt-align-right",
    "kh-rt-align-justify",
  ]);
  const blockTags = new Set([
    "div",
    "p",
    "h2",
    "h3",
    "h4",
    "blockquote",
    "ul",
    "ol",
    "table",
  ]);
  const color = (value) => {
    value = value.trim().toLowerCase();
    if (/^#[a-f0-9]{6}$/.test(value)) return value;
    if (/^#[a-f0-9]{3}$/.test(value))
      return "#" + [...value.slice(1)].map((c) => c + c).join("");
    const rgb = /^rgb\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*\)$/.exec(
      value,
    );
    if (rgb && rgb.slice(1).every((n) => Number(n) <= 255))
      return (
        "#" +
        rgb
          .slice(1)
          .map((n) => Number(n).toString(16).padStart(2, "0"))
          .join("")
      );
    return null;
  };
  const href = (value) => {
    value = value.trim();
    if (
      !value ||
      value.length > 2000 ||
      /[\u0000-\u0020\u007f\\]/.test(value) ||
      /%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i.test(value)
    )
      return null;
    if (
      value.startsWith("/") &&
      !value.startsWith("//") &&
      !/^\/%2f/i.test(value)
    )
      return value;
    if (/^mailto:/i.test(value)) {
      try {
        const url = new URL(value);
        if (
          url.search ||
          url.hash ||
          url.host ||
          url.username ||
          url.password ||
          url.port ||
          /%(?![0-9a-f]{2})/i.test(value)
        )
          return null;
        const email = decodeURIComponent(value.slice(7)),
          local = email.split("@")[0];
        const control = document.createElement("input");
        control.type = "email";
        control.value = email;
        return email.length <= 254 &&
          local.length <= 64 &&
          !/[\u0000-\u0020\u007f\\"]/.test(email) &&
          !local.startsWith(".") &&
          !local.endsWith(".") &&
          !local.includes("..") &&
          email.split("@").length === 2 &&
          email.split("@")[1].includes(".") &&
          control.checkValidity()
          ? value
          : null;
      } catch {
        return null;
      }
    }
    if (/^tel:\+?[0-9][0-9().-]{0,38}[0-9](?:;ext=[0-9]{1,10})?$/i.test(value))
      return value;
    try {
      const url = new URL(value);
      return url.protocol === "https:" && !url.username && !url.password
        ? value
        : null;
    } catch {
      return null;
    }
  };

  // Parsing inside a template keeps pasted nodes inert. Only newly created allowlisted
  // nodes are ever attached to the document; pasted images/iframes/styles never get inserted.
  function sanitize(html) {
    if (html.length > 1000000)
      throw new Error("Der eingefügte Inhalt ist zu groß.");
    const source = document.createElement("template");
    source.innerHTML = html;
    const output = document.createElement("div");
    let count = 0;
    function copy(node, parent, depth) {
      if (++count > 10000 || depth > 64)
        throw new Error("Der Inhalt enthält zu viele verschachtelte Elemente.");
      if (node.nodeType === Node.TEXT_NODE) {
        parent.append(document.createTextNode(node.textContent));
        return;
      }
      if (!(node instanceof Element)) return;
      const original = node.localName.toLowerCase();
      if (drop.has(original)) return;
      const mapped = {
        b: "strong",
        i: "em",
        strike: "s",
        del: "s",
        font: "span",
        div: "p",
      };
      const tag = mapped[original] || original;
      if (
        !tags.has(tag) ||
        (original === "div" &&
          [...node.children].some((child) => blockTags.has(child.localName)))
      ) {
        [...node.childNodes].forEach((child) => copy(child, parent, depth + 1));
        return;
      }
      const safe = document.createElement(tag);
      const keep = [...node.classList].filter((c) => classes.has(c));
      const styles = {};
      (node.getAttribute("style") || "").split(";").forEach((declaration) => {
        const split = declaration.indexOf(":");
        if (split < 0) return;
        const key = declaration.slice(0, split).trim().toLowerCase();
        const value = declaration
          .slice(split + 1)
          .trim()
          .toLowerCase();
        if (key === "color" || key === "background-color") {
          const valid = color(value);
          if (valid) styles[key] = valid;
        } else if (
          key === "text-align" &&
          ["left", "center", "right", "justify"].includes(value)
        )
          keep.push("kh-rt-align-" + value);
      });
      const align = (node.getAttribute("align") || "").toLowerCase();
      if (["left", "center", "right", "justify"].includes(align))
        keep.push("kh-rt-align-" + align);
      if (original === "font") {
        const size = node.getAttribute("size");
        if (/^[1-7]$/.test(size || ""))
          keep.push(
            "kh-rt-size-" +
              (Number(size) <= 2
                ? "small"
                : size === "3"
                  ? "normal"
                  : size === "4"
                    ? "large"
                    : "xlarge"),
          );
        const valid = color(node.getAttribute("color") || "");
        if (valid) styles.color = valid;
      }
      if (keep.length) safe.className = [...new Set(keep)].sort().join(" ");
      if (Object.keys(styles).length)
        safe.setAttribute(
          "style",
          Object.keys(styles)
            .sort()
            .map((key) => key + ":" + styles[key])
            .join(";"),
        );
      if (tag === "a") {
        const valid = href(node.getAttribute("href") || "");
        if (valid) {
          safe.setAttribute("href", valid);
          safe.setAttribute("rel", "noopener noreferrer");
        }
      }
      if (tag === "td" || tag === "th")
        ["colspan", "rowspan"].forEach((key) => {
          const value = node.getAttribute(key);
          if (/^(?:[1-9]|1[0-9]|20)$/.test(value || ""))
            safe.setAttribute(key, value);
        });
      parent.append(safe);
      [...node.childNodes].forEach((child) => copy(child, safe, depth + 1));
    }
    [...source.content.childNodes].forEach((node) => copy(node, output, 0));
    if ([...output.textContent].length > 100000)
      throw new Error(
        "Der Inhalt darf höchstens 100.000 Textzeichen enthalten.",
      );
    return output.innerHTML.trim();
  }

  function plain(html) {
    const value = document.createElement("template");
    value.innerHTML = sanitize(html)
      .replace(/<br\s*\/?\s*>|<\/(?:p|h[2-4]|blockquote|li|tr)>/gi, "\n")
      .replace(/<\/(?:td|th)>/gi, "\t");
    return (value.content.textContent || "")
      .replace(/[ \t]+\n/g, "\n")
      .replace(/\n{3,}/g, "\n\n")
      .trim();
  }

  function initialize(root) {
    if (mounted.has(root)) return;
    const surface = root.querySelector("[data-richtext-surface]");
    const hidden = root.querySelector("[data-richtext-value]");
    const status = root.querySelector("[data-richtext-status]");
    if (!surface || !hidden || !status) return;
    // Inert navigation parsing may materialize noscript controls; the active editor owns its value.
    for(const fallback of root.closest('form')?.querySelectorAll('[data-richtext-fallback-for]')||[])
      if(fallback.dataset.richtextFallbackFor===hidden.name)fallback.disabled=true;
    mounted.add(root);
    surface.contentEditable = "true";
    surface.setAttribute("aria-readonly", "false");
    let savedRange = null;
    let valid = true;
    let history = [hidden.value];
    let historyIndex = 0;
    const report = (message, error = false) => {
      status.textContent = contentText(message);
      status.classList.toggle("kh-rte-error", error);
    };
    const capture = (force = false) => {
      // A focused toolbar/select/summary can leave a collapsed browser selection
      // inside the editor. It is not a new editor selection and must not replace
      // the range saved before the focus transfer.
      if (force !== true && document.activeElement !== surface) return;
      const selection = window.getSelection();
      if (
        selection?.rangeCount &&
        surface.contains(selection.getRangeAt(0).commonAncestorContainer)
      )
        savedRange = selection.getRangeAt(0).cloneRange();
      root
        .querySelectorAll("[aria-pressed][data-rt-command]")
        .forEach((button) => {
          let pressed = false;
          try {
            pressed = document.queryCommandState(button.dataset.rtCommand);
          } catch {
            /* unsupported command is simply not selected */
          }
          button.setAttribute("aria-pressed", String(pressed));
        });
    };
    const restore = () => {
      // Focusing contenteditable itself fires focus/selection handlers. Preserve
      // the intended range before those events can alter the browser selection.
      const intended =
        savedRange && surface.contains(savedRange.commonAncestorContainer)
          ? savedRange.cloneRange()
          : null;
      surface.focus({ preventScroll: true });
      const selection = window.getSelection();
      if (!selection) return;
      const range = intended || document.createRange();
      if (!intended) {
        range.selectNodeContents(surface);
        range.collapse(false);
      }
      selection.removeAllRanges();
      selection.addRange(range);
      savedRange = range.cloneRange();
    };
    const sync = (notify = true) => {
      if (root.closest("[data-model-field]")?.hidden) {
        valid = true;
        surface.setAttribute("aria-invalid", "false");
        report("");
        return true;
      }
      try {
        hidden.value = sanitize(surface.innerHTML);
        valid = true;
        const text = plain(hidden.value);
        const length = [...text].length;
        if (root.dataset.richtextRequired === "1" && length === 0)
          throw new Error("Bitte dieses Pflichtfeld ausfüllen.");
        if (
          length > 0 &&
          root.dataset.richtextMin !== undefined &&
          length < Number(root.dataset.richtextMin)
        )
          throw new Error(
            contentFormat("Mindestens {count} sichtbare Textzeichen erforderlich.",{count:root.dataset.richtextMin}),
          );
        if (
          root.dataset.richtextMax !== undefined &&
          length > Number(root.dataset.richtextMax)
        )
          throw new Error(
            contentFormat("Höchstens {count} sichtbare Textzeichen erlaubt.",{count:root.dataset.richtextMax}),
          );
        if (surface.getAttribute("aria-invalid") === "true") report("");
        surface.setAttribute("aria-invalid", "false");
        if (notify && history[historyIndex] !== hidden.value) {
          history = history.slice(0, historyIndex + 1);
          history.push(hidden.value);
          if (history.length > 40) history.shift();
          historyIndex = history.length - 1;
        }
        if (notify) {
          hidden.dispatchEvent(new Event("input", { bubbles: true }));
          hidden.dispatchEvent(new Event("change", { bubbles: true }));
        }
        return true;
      } catch (error) {
        valid = false;
        surface.setAttribute("aria-invalid", "true");
        report(error.message, true);
        return false;
      }
    };
    const command = (name, value = null) => {
      if (name === "undo" || name === "redo") {
        const destination = historyIndex + (name === "undo" ? -1 : 1);
        if (destination < 0 || destination >= history.length) return;
        historyIndex = destination;
        surface.innerHTML = history[historyIndex];
        hidden.value = history[historyIndex];
        savedRange = null;
        restore();
        capture();
        hidden.dispatchEvent(new Event("input", { bubbles: true }));
        hidden.dispatchEvent(new Event("change", { bubbles: true }));
        report(
          name === "undo"
            ? "Änderung rückgängig gemacht."
            : "Änderung wiederholt.",
        );
        return;
      }
      restore();
      document.execCommand("styleWithCSS", false, false);
      document.execCommand(name, false, value);
      capture();
      sync();
    };
    const caret = (node) => {
      const range = document.createRange();
      range.selectNodeContents(node);
      range.collapse(false);
      savedRange = range;
      restore();
      capture();
    };
    const currentCell = () => {
      restore();
      const selected = window.getSelection();
      const node = selected?.anchorNode;
      const cell = (
        node instanceof Element ? node : node?.parentElement
      )?.closest("td,th");
      return cell && surface.contains(cell) ? cell : null;
    };
    const changedTable = (focus, message) => {
      if (focus) caret(focus);
      capture();
      sync();
      report(message);
    };

    function tableAction(action) {
      if (action === "insert") {
        const rows = Number(root.querySelector("[data-rt-rows]").value);
        const cols = Number(root.querySelector("[data-rt-cols]").value);
        if (
          !Number.isInteger(rows) ||
          !Number.isInteger(cols) ||
          rows < 1 ||
          rows > 20 ||
          cols < 1 ||
          cols > 10
        ) {
          report("Tabellen benötigen 1–20 Zeilen und 1–10 Spalten.", true);
          return;
        }
        let table = "<table><tbody>";
        for (let r = 0; r < rows; r++) {
          table += "<tr>";
          for (let c = 0; c < cols; c++)
            table += r === 0 ? "<th>Überschrift</th>" : "<td>Text</td>";
          table += "</tr>";
        }
        command("insertHTML", table + "</tbody></table><p><br></p>");
        report("Tabelle eingefügt. Zum Bearbeiten in eine Zelle klicken.");
        return;
      }
      const cell = currentCell();
      if (!cell) {
        report("Zuerst eine Tabellenzelle auswählen.", true);
        return;
      }
      const table = cell.closest("table");
      const row = cell.closest("tr");
      if (!table || !row) return;
      if (action === "remove") {
        const p = document.createElement("p");
        p.append(document.createElement("br"));
        table.replaceWith(p);
        changedTable(p, "Tabelle entfernt.");
        return;
      }
      // Merged imported cells remain preserved. Editing their grid needs an explicit
      // merge-aware algorithm; do not silently corrupt rows or spans.
      if (
        table.querySelector(
          '[rowspan]:not([rowspan="1"]),[colspan]:not([colspan="1"])',
        )
      ) {
        report(
          "Zeilen und Spalten mit verbundenen Zellen zunächst in einer einfachen Tabelle bearbeiten.",
          true,
        );
        return;
      }
      const index = cell.cellIndex;
      if (action === "row-add") {
        if (table.rows.length >= 20) {
          report("Höchstens 20 Zeilen pro Tabelle.", true);
          return;
        }
        const next = document.createElement("tr");
        [...row.cells].forEach(() => {
          const td = document.createElement("td");
          td.append(document.createElement("br"));
          next.append(td);
        });
        row.after(next);
        changedTable(
          next.cells[Math.min(index, next.cells.length - 1)],
          "Zeile hinzugefügt.",
        );
      } else if (action === "row-remove") {
        if (table.rows.length === 1) {
          tableAction("remove");
          return;
        }
        const next =
          row.nextElementSibling ||
          row.previousElementSibling ||
          [...table.rows].find((r) => r !== row);
        row.remove();
        changedTable(
          next?.cells[Math.min(index, (next?.cells.length || 1) - 1)],
          "Zeile entfernt.",
        );
      } else if (action === "col-add") {
        if ([...table.rows].some((r) => r.cells.length >= 10)) {
          report("Höchstens 10 Spalten pro Tabelle.", true);
          return;
        }
        let focus = null;
        [...table.rows].forEach((r) => {
          const adjacent = r.cells[Math.min(index, r.cells.length - 1)];
          if (!adjacent) return;
          const td = document.createElement(adjacent.localName);
          td.append(document.createElement("br"));
          adjacent.after(td);
          if (r === row) focus = td;
        });
        changedTable(focus, "Spalte hinzugefügt.");
      } else if (action === "col-remove") {
        if ([...table.rows].some((r) => r.cells.length < 2)) {
          tableAction("remove");
          return;
        }
        [...table.rows].forEach((r) => r.cells[index]?.remove());
        changedTable(
          row.cells[Math.min(index, row.cells.length - 1)],
          "Spalte entfernt.",
        );
      } else if (action === "header") {
        const first = table.rows[0];
        const target = [...first.cells].every((td) => td.localName === "th")
          ? "td"
          : "th";
        [...first.cells].forEach((old) => {
          const replacement = document.createElement(target);
          if (old.getAttribute("style"))
            replacement.setAttribute("style", old.getAttribute("style"));
          while (old.firstChild) replacement.append(old.firstChild);
          old.replaceWith(replacement);
        });
        changedTable(
          first.cells[Math.min(index, first.cells.length - 1)],
          "Kopfzeile geändert.",
        );
      }
    }

    ["keyup", "mouseup", "focus", "blur"].forEach((event) =>
      surface.addEventListener(event, capture),
    );
    surface.addEventListener("input", () => {
      capture();
      sync();
    });
    surface.addEventListener("keydown", (event) => {
      capture();
      if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
      if (event.key.toLowerCase() === "z") {
        event.preventDefault();
        command(event.shiftKey ? "redo" : "undo");
      } else if (event.key.toLowerCase() === "y") {
        event.preventDefault();
        command("redo");
      }
    });
    surface.addEventListener("beforeinput", (event) => {
      if (
        event.inputType === "historyUndo" ||
        event.inputType === "historyRedo"
      ) {
        event.preventDefault();
        command(event.inputType === "historyUndo" ? "undo" : "redo");
      }
    });
    root.addEventListener("pointerdown", (event) => {
      if (surface.contains(event.target)) return;
      capture();
      const button = event.target.closest("button");
      if (button && !button.closest("[data-rt-link-panel]"))
        event.preventDefault();
    });
    root.querySelectorAll(".kh-rte-menu").forEach((menu) =>
      menu.addEventListener("toggle", () => {
        if (menu.open)
          root.querySelectorAll(".kh-rte-menu").forEach((other) => {
            if (other !== menu) other.open = false;
          });
      }),
    );
    root.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        root
          .querySelectorAll(".kh-rte-menu")
          .forEach((menu) => (menu.open = false));
        root.querySelector("[data-rt-link-panel]").hidden = true;
        restore();
      }
    });
    surface.addEventListener("pointerdown", () =>
      root
        .querySelectorAll(".kh-rte-menu")
        .forEach((menu) => (menu.open = false)),
    );
    surface.addEventListener("click", (event) => {
      if (event.target.closest("a")) event.preventDefault();
    });
    surface.addEventListener("paste", (event) => {
      event.preventDefault();
      capture();
      try {
        const html = event.clipboardData?.getData("text/html");
        if (html) command("insertHTML", sanitize(html));
        else
          command(
            "insertText",
            event.clipboardData?.getData("text/plain") || "",
          );
        report(
          "Inhalt eingefügt. Aktive Inhalte und externe Bilder wurden entfernt.",
        );
      } catch (error) {
        report(error.message, true);
      }
    });
    surface.addEventListener("dragover", (event) => event.preventDefault());
    surface.addEventListener("drop", (event) => {
      event.preventDefault();
      if (event.dataTransfer?.files.length) {
        report("Bilder über die Galerie als Baustein hinzufügen.", true);
        return;
      }
      try {
        const html = event.dataTransfer?.getData("text/html");
        if (html) command("insertHTML", sanitize(html));
        else
          command(
            "insertText",
            event.dataTransfer?.getData("text/plain") || "",
          );
      } catch (error) {
        report(error.message, true);
      }
    });
    root.addEventListener("change", (event) => {
      if (event.target.matches("[data-rt-link-kind]")) {
        const input = root.querySelector("[data-rt-link-url]"),
          kind = event.target.value;
        input.inputMode =
          kind === "email" ? "email" : kind === "phone" ? "tel" : "url";
        root.querySelector("[data-rt-link-label]").textContent =
          kind === "email"
            ? contentText("E-Mail-Adresse")
            : kind === "phone"
              ? contentText("Telefonnummer")
              : contentText("Webadresse oder Seitenpfad");
      }

      const target = event.target;
      if (target.matches("[data-rt-format]"))
        command("formatBlock", target.value);
      else if (target.matches("[data-rt-size]"))
        command("fontSize", target.value);
      else if (target.matches("[data-rt-color]")) {
        const value = color(target.value);
        if (value) command(target.dataset.rtColor, value);
      } else if (target.matches("[data-rt-cell-color]")) {
        const cell = currentCell();
        if (!cell) {
          report("Zuerst eine Tabellenzelle auswählen.", true);
          return;
        }
        const value = color(target.value);
        if (value) {
          cell.style.backgroundColor = value;
          changedTable(cell, "Zellfarbe geändert.");
        }
      }
    });
    root.addEventListener("click", (event) => {
      const button = event.target.closest("button");
      if (!button || !root.contains(button)) return;
      if (button.dataset.rtCommand) command(button.dataset.rtCommand);
      else if (button.dataset.rtEmoji) {
        command("insertText", button.dataset.rtEmoji);
        root
          .querySelectorAll(".kh-rte-menu")
          .forEach((menu) => (menu.open = false));
      } else if (button.dataset.rtSwatch)
        command("foreColor", button.dataset.rtSwatch);
      else if (button.dataset.rtTable) {
        tableAction(button.dataset.rtTable);
        root
          .querySelectorAll(".kh-rte-menu")
          .forEach((menu) => (menu.open = false));
      } else if (button.hasAttribute("data-rt-clear-colors")) {
        const cell = currentCell();
        command("removeFormat");
        if (cell) {
          cell.style.removeProperty("color");
          cell.style.removeProperty("background-color");
          if (!cell.getAttribute("style")) cell.removeAttribute("style");
          sync();
        }
        report(
          "Auswahlformatierung und Farben der ausgewählten Tabellenzelle zurückgesetzt.",
        );
      } else if (button.hasAttribute("data-rt-link")) {
        capture();
        root.querySelector("[data-rt-link-panel]").hidden = false;
        root.querySelector("[data-rt-link-url]").focus();
      } else if (button.hasAttribute("data-rt-link-cancel")) {
        root.querySelector("[data-rt-link-panel]").hidden = true;
        restore();
      } else if (button.hasAttribute("data-rt-link-apply")) {
        const input = root.querySelector("[data-rt-link-url]");
        const entered = input.value.trim();
        const kind = root.querySelector("[data-rt-link-kind]").value;
        const value = href(
          kind === "email"
            ? "mailto:" + encodeURIComponent(entered).replace(/%40/gi, "@")
            : kind === "phone"
              ? "tel:" + entered.replace(/ /g, "")
              : entered,
        );
        if (!value) {
          input.setAttribute("aria-invalid", "true");
          report("Adresse prüfen und erneut übernehmen.", true);
          input.focus();
          return;
        }
        input.removeAttribute("aria-invalid");
        restore();
        const selected = window.getSelection();
        if (selected?.isCollapsed) {
          const link = document.createElement("a");
          link.href = value;
          link.textContent = value;
          command("insertHTML", sanitize(link.outerHTML));
        } else command("createLink", value);
        root.querySelector("[data-rt-link-panel]").hidden = true;
        report("Link übernommen.");
      }
    });
    root.closest("form")?.addEventListener(
      "submit",
      (event) => {
        sync(false);
        if(event.submitter?.name==='save_review')return;
        if (!valid) {
          event.preventDefault();
          event.stopImmediatePropagation();
          root.dispatchEvent(
            new CustomEvent("dvm-field-invalid", { bubbles: true }),
          );
          surface.focus();
        }
      },
      true,
    );
    root.addEventListener("dvm-field-state", () => sync(false));
    sync(false);
  }

  const mount = (root = document) => {
    if (root instanceof Element && root.matches("[data-kh-richtext]"))
      initialize(root);
    root.querySelectorAll("[data-kh-richtext]").forEach(initialize);
  };
  window.KulturhusRichText = Object.freeze({ mount, plain, sanitize });
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", () => mount(), {
      once: true,
    });
  else mount();
})();
