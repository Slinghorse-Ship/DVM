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
  const node = (tag, text, attrs = {}) => {
    const el = document.createElement(tag);
    if (text) el.textContent = text;
    for (const [key, value] of Object.entries(attrs))
      el.setAttribute(key, value);
    return el;
  };
  const button = (text, attrs = {}) =>
    node("button", contentText(text), {
      type: "button",
      class: "btn btn-outline theme-secondary",
      ...attrs,
    });
  function mount(picker) {
    if (mounted.has(picker)) return;
    const select = picker.querySelector("[data-page-media-select]");
    const controls = picker.querySelector("[data-page-media-controls]");
    const status = picker.querySelector("[data-page-media-status]");
    if (!select || !controls || !status) return;
    const options = [...select.options];
    if (options.some((o) => !/^[a-f0-9]{32}$/.test(o.value))) return;
    const byId = new Map(options.map((o) => [o.value, o]));
    const catalog = options
      .filter((o) => !o.hasAttribute("data-unavailable"))
      .sort(
        (a, b) =>
          a.textContent.localeCompare(b.textContent, "de") ||
          a.value.localeCompare(b.value),
      );
    const original = options.filter((o) => o.selected).map((o) => o.value);
    let chosen = [...original];
    let view = chosen.length ? "selected" : "catalog",
      query = "",
      page = 1;
    const history = [];
    const tabs = node("div", "", {
      class: "page-media-tabs",
      "data-dirty-ignore": "",
    });
    const selectedTab = button("Ausgewählt", {
      "data-page-media-tab": "selected",
    });
    const catalogTab = button("Bilder", { "data-page-media-tab": "catalog" });
    const undo = button("Rückgängig", {
      "data-page-media-undo": "",
      "aria-label": contentText("Letzte Änderung der Bildauswahl rückgängig machen"),
    });
    tabs.append(selectedTab, catalogTab, undo);
    const search = node("div", "", {
      class: "page-media-search",
      "data-dirty-ignore": "",
    });
    const input = node("input", "", {
      type: "search",
      class: "form-control",
      maxlength: "120",
      id: select.id + "-search",
      "data-page-media-query": "",
    });
    search.append(
      node("label", contentText("Bildbeschreibung oder Dateiname suchen"), {
        for: input.id,
      }),
      input,
    );
    const results = node("div", "", {
      class: "page-media-results",
      "data-page-media-results": "",
    });
    const pagination = node("div", "", {
      class: "page-media-pagination",
      "data-dirty-ignore": "",
    });
    const previous = button("←", {
      "aria-label": contentText("Vorherige Bilder"),
      "data-page-media-previous": "",
    });
    const next = button("→", {
      "aria-label": contentText("Weitere Bilder"),
      "data-page-media-next": "",
    });
    const pageSelect = node("select", "", {
      class: "form-control",
      "aria-label": contentText("Bilderseite"),
      "data-page-media-page": "",
      "data-no-select-search": "",
    });
    pagination.append(previous, pageSelect, next);
    controls.append(tabs, search, results, pagination);
    select.hidden = true;
    controls.hidden = false;
    mounted.add(picker);
    const visible = () => {
      for (let el = picker; el && el !== document.body; el = el.parentElement) {
        if (el.hidden || el.inert || (el.tagName === "DETAILS" && !el.open))
          return false;
      }
      return true;
    };
    const commit = () => {
      const ids = new Set(chosen);
      for (const option of options) option.selected = ids.has(option.value);
      select.replaceChildren(
        ...chosen.map((id) => byId.get(id)),
        ...catalog.filter((o) => !ids.has(o.value)),
        ...options.filter(
          (o) => o.hasAttribute("data-unavailable") && !ids.has(o.value),
        ),
      );
      select.dispatchEvent(new Event("change", { bubbles: true }));
    };
    const change = (update, label, focusId = "") => {
      history.push([...chosen]);
      if (history.length > 20) history.shift();
      update();
      commit();
      render(focusId);
      status.textContent = contentFormat("{action} · {count} Bilder ausgewählt", {action:contentText(label),count:chosen.length});
    };
    const upload = picker.querySelector("[data-page-media-upload]");
    const cancelUpload = picker.querySelector(
      "[data-page-media-upload-cancel]",
    );
    const checkUploads = picker.querySelector("[data-page-photo-check]");
    const forgetUploads = picker.querySelector("[data-page-photo-forget]");
    const pendingUploads = new Map();
    let uploadJob = null;
    const pendingControls = () => {
      for (const button of [checkUploads, forgetUploads]) {
        if (!button) continue;
        button.hidden = pendingUploads.size === 0;
        button.disabled = uploadJob !== null;
      }
      if (checkUploads)
        checkUploads.textContent =
          contentFormat("Uploads prüfen ({count})",{count:pendingUploads.size});
    };
    const validImage = (image, id) => {
      if (
        !image ||
        image.id !== id ||
        !/^[a-f0-9]{32}$/.test(image.id) ||
        typeof image.alt !== "string" ||
        typeof image.name !== "string" ||
        typeof image.url !== "string"
      )
        return false;
      const url = new URL(image.url, location.origin);
      return url.origin === location.origin && !url.username && !url.password;
    };
    const addCatalogImage = (image) => {
      if (byId.has(image.id)) return false;
      const option = new Option(
        image.alt + (image.name ? " · " + image.name : ""),
        image.id,
      );
      options.push(option);
      byId.set(image.id, option);
      catalog.push(option);
      catalog.sort(
        (a, b) =>
          a.textContent.localeCompare(b.textContent, "de") ||
          a.value.localeCompare(b.value),
      );
      select.append(option);
      return true;
    };
    forgetUploads?.addEventListener("click", () => {
      if (uploadJob) return;
      pendingUploads.clear();
      pendingControls();
      status.textContent = contentText("Prüfung beendet.");
    });
    checkUploads?.addEventListener("click", async () => {
      if (uploadJob || !pendingUploads.size) return;
      const url = new URL(picker.dataset.pagePhotoStatus, location.origin);
      if (url.origin !== location.origin || url.username || url.password)
        return;
      const controller = new AbortController(),
        job = { picker, controller, kind: "check" };
      uploadJob = job;
      uploads.add(job);
      pendingControls();
      upload.disabled = true;
      cancelUpload.hidden = false;
      const timer = setTimeout(() => controller.abort(), 45000);
      let found = 0,
        lastName = "",
        failed = false;
      try {
        for (const [id] of pendingUploads) {
          if (controller.signal.aborted || !picker.isConnected) break;
          url.searchParams.set("upload_id", id);
          status.textContent = contentText("Upload wird geprüft …");
          const response = await fetch(url, {
            credentials: "same-origin",
            redirect: "error",
            headers: { Accept: "application/json" },
            signal: controller.signal,
          });
          if (!response.ok) throw Error("Upload nicht prüfbar.");
          const data = await response.json();
          if (typeof data.found !== "boolean")
            throw Error("Uploadantwort prüfen.");
          if (controller.signal.aborted || !picker.isConnected) break;
          if (!data.found) continue;
          if (!validImage(data.image, id)) throw Error("Uploadantwort prüfen.");
          addCatalogImage(data.image);
          pendingUploads.delete(id);
          found++;
          lastName = data.image.name;
        }
      } catch {
        failed = !controller.signal.aborted;
      } finally {
        clearTimeout(timer);
        if (uploadJob === job) uploadJob = null;
        uploads.delete(job);
        upload.disabled = false;
        cancelUpload.hidden = true;
        pendingControls();
        if (picker.isConnected) {
          if (found) {
            view = "catalog";
            query = Array.from(lastName).slice(0, 120).join("");
            input.value = query;
            page = 1;
          }
          render();
          status.textContent =
            (found
              ? contentFormat("{count} Fotos gefunden. Zur Auswahl hinzufügen.",{count:found})
              : contentText("Noch kein Upload bestätigt.")) +
            (pendingUploads.size
              ? " · " + contentFormat("{count} noch unbestätigt. Später erneut prüfen.",{count:pendingUploads.size})
              : "") +
            (failed
              ? " · " + contentText("Prüfung nicht möglich.")
              : controller.signal.aborted
                ? " · " + contentText("Prüfung gestoppt.")
                : "");
        }
      }
    });
    if (upload && cancelUpload) {
      cancelUpload.addEventListener("click", () =>
        uploadJob?.controller.abort(),
      );
      picker.closest("form")?.addEventListener(
        "submit",
        (event) => {
          if (uploadJob && uploadJob.kind !== "check") {
            event.preventDefault();
            event.stopImmediatePropagation();
          }
        },
        true,
      );
      upload.addEventListener("change", async () => {
        if (uploadJob || !upload.files.length) return;
        if (pendingUploads.size >= 50 || !globalThis.crypto?.getRandomValues) {
          status.textContent =
            pendingUploads.size >= 50
              ? contentText("Offene Uploads zuerst prüfen.")
              : contentText("Sichere Upload-Kennung nicht verfügbar. Ohne JavaScript hochladen.");
          upload.value = "";
          return;
        }
        const files = [...upload.files];
        if (files.length > 50 || files.length > 100 - chosen.length) {
          status.textContent =
            contentFormat("Bis zu {count} weitere Fotos auswählen.",{count:Math.min(50,100-chosen.length)});
          upload.value = "";
          return;
        }
        const form = picker.closest("form");
        if (!form) return;
        const url = new URL(
          form.getAttribute("action") || location.href,
          location.href,
        );
        if (url.origin !== location.origin || url.username || url.password)
          return;
        const controller = new AbortController();
        const job = { picker, controller, kind: "upload" };
        uploadJob = job;
        uploads.add(job);
        pendingControls();
        const submitters = [
          ...form.querySelectorAll(
            "button[type=submit],button:not([type]),input[type=submit]",
          ),
        ].map((b) => [b, b.disabled]);
        submitters.forEach(([b]) => (b.disabled = true));
        upload.disabled = true;
        picker.setAttribute("aria-busy", "true");
        cancelUpload.hidden = false;
        controls.inert = true;
        let added = 0,
          failed = 0,
          pendingRequest = false,
          uncertain = false;
        const names = [];
        try {
          for (const file of files) {
            if (controller.signal.aborted || !picker.isConnected) break;
            status.textContent =
              contentFormat("Foto {number} von {count} wird hochgeladen …",{number:added+failed+1,count:files.length});
            const body = new FormData();
            for (const key of ["csrf", "action", "id", "version"]) {
              const field = form.elements.namedItem(key);
              if (field instanceof HTMLInputElement) body.set(key, field.value);
            }
            body.set("operation", picker.dataset.pagePhotoOperation === "communication-photo-upload" ? "communication-photo-upload" : "page-photo-upload");
            body.set("photo", file, file.name);
            const uploadId = [...crypto.getRandomValues(new Uint8Array(16))]
              .map((byte) => byte.toString(16).padStart(2, "0"))
              .join("");
            body.set("upload_id", uploadId);
            pendingUploads.set(uploadId, file.name);
            pendingRequest = true;
            const timer = setTimeout(() => controller.abort(), 45000);
            let response;
            try {
              response = await fetch(url, {
                method: "POST",
                body,
                credentials: "same-origin",
                redirect: "error",
                headers: { Accept: "application/json" },
                signal: controller.signal,
              });
            } finally {
              clearTimeout(timer);
            }
            if (!response.ok) {
              if ([400, 413, 415, 422].includes(response.status)) {
                failed++;
                pendingRequest = false;
                pendingUploads.delete(uploadId);
                if (names.length < 3) names.push(file.name);
                continue;
              }
              uncertain = true;
              break;
            }
            const data = await response.json(),
              image = data.image;
            if (
              !data.ok ||
              !validImage(image, uploadId) ||
              byId.has(image.id)
            ) {
              uncertain = true;
              break;
            }
            if (controller.signal.aborted || !picker.isConnected) {
              uncertain = true;
              break;
            }
            addCatalogImage(image);
            change(() => {
              chosen.push(image.id);
            }, "Foto hinzugefügt");
            added++;
            pendingRequest = false;
            pendingUploads.delete(uploadId);
          }
        } catch {
          uncertain = pendingRequest;
        } finally {
          if (uploadJob === job) uploadJob = null;
          pendingControls();
          uploads.delete(job);
          upload.value = "";
          upload.disabled = false;
          picker.removeAttribute("aria-busy");
          cancelUpload.hidden = true;
          controls.inert = false;
          submitters.forEach(([b, disabled]) => (b.disabled = disabled));
          if (picker.isConnected) {
            if (added) {
              view = "selected";
              page = Math.ceil(chosen.length / 4);
            }
            render();
            status.textContent =
              contentFormat("{count} Fotos hinzugefügt",{count:added}) +
              (failed
                ? " · " + contentFormat("{count} fehlgeschlagen: {names}",{count:failed,names:names.join(", ")})
                : "") +
              (uncertain
                ? " · " + contentText("Ergebnis unklar. Neue Fotos im Bestand prüfen.")
                : controller.signal.aborted
                  ? " · " + contentText("Upload gestoppt.")
                  : "");
          }
        }
      });
    }
    const thumbnail = (id) => {
      const url = new URL(
        picker.dataset.pageMediaPreview.replace(
          "00000000000000000000000000000000",
          id,
        ),
        location.origin,
      );
      if (url.origin !== location.origin) return null;
      const img = node("img", "", {
        src: url.href,
        alt: "",
        width: "52",
        height: "52",
        loading: "lazy",
        decoding: "async",
      });
      img.addEventListener(
        "error",
        () => {
          img.removeAttribute("src");
          img.hidden = true;
        },
        { once: true },
      );
      return img;
    };
    function render(focusId = "") {
      selectedTab.textContent = contentFormat("Ausgewählt ({count})",{count:chosen.length});
      const attachmentCount = picker.closest(".press-photo-attachments")?.querySelector("[data-attachment-count]");
      if (attachmentCount) attachmentCount.textContent = contentFormat("{count} ausgewählt",{count:chosen.length});
      catalogTab.textContent = contentFormat("Bilder ({count})",{count:catalog.length});
      for (const [tab, name] of [
        [selectedTab, "selected"],
        [catalogTab, "catalog"],
      ]) {
        tab.setAttribute("aria-pressed", String(view === name));
      }
      undo.disabled = history.length === 0;
      search.hidden = view !== "catalog";
      if (!visible()) {
        results.replaceChildren();
        return;
      }
      const list =
        view === "selected"
          ? chosen.map((id) => byId.get(id))
          : catalog.filter((o) =>
              o.textContent
                .toLocaleLowerCase("de")
                .includes(query.toLocaleLowerCase("de")),
            );
      const size = view === "selected" ? 4 : 6;
      const last = Math.max(1, Math.ceil(list.length / size));
      page = Math.min(Math.max(1, page), last);
      pageSelect.replaceChildren(
        ...Array.from(
          { length: last },
          (_, i) =>
            new Option(
              contentFormat("Seite {page} von {pages}",{page:i+1,pages:last}),
              String(i + 1),
              false,
              i + 1 === page,
            ),
        ),
      );
      previous.disabled = page <= 1;
      next.disabled = page >= last;
      const rows = [];
      for (const option of list.slice((page - 1) * size, page * size)) {
        const id = option.value,
          index = chosen.indexOf(id);
        const row = node("div", "", {
          class: "page-media-row",
          "data-page-media-id": id,
        });
        const label = node("span", option.textContent, {
          class: "page-media-label",
        });
        if (!option.hasAttribute("data-unavailable")) {
          const img = thumbnail(id);
          if (img) row.append(img);
        }
        row.append(label);
        const actions = node("div", "", { class: "page-media-row-actions" });
        if (view === "selected") {
          const up = button("↑", {
            "aria-label": contentFormat("Bild nach vorne: {name}",{name:option.textContent}),
            "data-page-media-move": "up",
          });
          const down = button("↓", {
            "aria-label": contentFormat("Bild nach hinten: {name}",{name:option.textContent}),
            "data-page-media-move": "down",
          });
          up.disabled = index === 0;
          down.disabled = index === chosen.length - 1;
          for (const [b, delta] of [
            [up, -1],
            [down, 1],
          ])
            b.addEventListener("click", () =>
              change(
                () => {
                  [chosen[index], chosen[index + delta]] = [
                    chosen[index + delta],
                    chosen[index],
                  ];
                  page = Math.floor((index + delta) / size) + 1;
                },
                "Reihenfolge geändert",
                id,
              ),
            );
          const remove = button("Entfernen", {
            "aria-label": contentFormat("Auswahl entfernen: {name}",{name:option.textContent}),
            "data-page-media-remove": "",
          });
          remove.addEventListener("click", () => {
            const adjacent = chosen[index + 1] || chosen[index - 1] || "";
            change(
              () => {
                chosen.splice(index, 1);
              },
              "Aus Auswahl entfernt",
              adjacent,
            );
            if (!adjacent) selectedTab.focus();
          });
          actions.append(up, down, remove);
        } else {
          const add = button(index >= 0 ? "Ausgewählt" : "Hinzufügen", {
            "data-page-media-add": "",
            "aria-label":
              contentFormat(index>=0?"Bereits ausgewählt: {name}":"Bild hinzufügen: {name}",{name:option.textContent}),
          });
          add.disabled = index >= 0;
          add.addEventListener("click", () => {
            if (chosen.length >= 100) {
              status.textContent = contentText("Maximal 100 Bilder auswählen.");
              return;
            }
            change(() => {
              chosen.push(id);
            }, "Bild hinzugefügt");
            const nextAdd = [
              ...results.querySelectorAll("[data-page-media-add]"),
            ].find((b) => !b.disabled);
            (nextAdd || catalogTab).focus();
          });
          actions.append(add);
        }
        row.append(actions);
        rows.push(row);
      }
      results.replaceChildren(
        ...(rows.length
          ? rows
          : [
              node(
                "p",
                contentText(view === "selected"?"Keine Bilder ausgewählt.":"Keine Bilder gefunden."),
              ),
            ]),
      );
      status.textContent =
        contentFormat(view === "selected"?"{count} Bilder ausgewählt · Seite {page} von {pages}":"{count} Treffer · Seite {page} von {pages}",{count:view==="selected"?chosen.length:list.length,page,pages:last});
      if (focusId)
        results
          .querySelector(
            '[data-page-media-id="' + focusId + '"] button:not([disabled])',
          )
          ?.focus();
    }
    selectedTab.addEventListener("click", () => {
      view = "selected";
      page = 1;
      render();
    });
    catalogTab.addEventListener("click", () => {
      view = "catalog";
      page = 1;
      render();
    });
    input.addEventListener("input", () => {
      query = input.value;
      page = 1;
      render();
    });
    input.addEventListener("keydown", (event) => {
      if (event.key === "Enter") {
        event.preventDefault();
        render();
      }
    });
    previous.addEventListener("click", () => {
      page--;
      render();
    });
    next.addEventListener("click", () => {
      page++;
      render();
    });
    pageSelect.addEventListener("change", () => {
      page = Number(pageSelect.value);
      render();
    });
    undo.addEventListener("click", () => {
      if (!history.length) return;
      chosen = history.pop();
      commit();
      render();
      status.textContent =
        contentFormat("Änderung rückgängig · {count} Bilder ausgewählt",{count:chosen.length});
      (undo.disabled ? selectedTab : undo).focus();
    });
    for (let el = picker.parentElement; el; el = el.parentElement)
      if (el.tagName === "DETAILS")
        el.addEventListener("toggle", () => render());
    picker.closest("form")?.addEventListener("reset", () =>
      queueMicrotask(() => {
        chosen = [...original];
        history.length = 0;
        page = 1;
        commit();
        render();
      }),
    );
    render();
  }
  const scan = () =>
    document.querySelectorAll("[data-page-media-picker]").forEach(mount);
  new MutationObserver((records) => {
    uploads.forEach((job) => {
      if (!job.picker.isConnected) job.controller.abort();
    });
    if (
      records.some((r) =>
        [...r.addedNodes].some(
          (n) =>
            n.nodeType === 1 &&
            (n.matches?.("[data-page-media-picker]") ||
              n.querySelector?.("[data-page-media-picker]")),
        ),
      )
    )
      scan();
  }).observe(document.documentElement, { childList: true, subtree: true });
  addEventListener("pagehide", () =>
    uploads.forEach((job) => job.controller.abort()),
  );
  scan();
})();
