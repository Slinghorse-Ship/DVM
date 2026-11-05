/* Sequential private photo uploads. Unknown saves are only checked by explicit GET. */
(() => {
  "use strict";
  if (window.KulturhusPhotoLibrary) return;
  const mediaText = (source) => {
    const read = window.KulturhusInterface?.text;
    if (!read) return source;
    const key = "media:" + source, translated = read(key);
    if (translated !== key) return translated;
    const photoKey = "photo:" + source, photo = read(photoKey);
    return photo === photoKey ? source : photo;
  };
  const mediaFormat = (source, values) => mediaText(source).replace(/\{([a-z]+)\}/g,
    (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token);
  const states = new WeakMap(),
    jobs = new Set(),
    MAX_BYTES = 256 * 1024 * 1024,
    MAX_BATCH_BYTES = 512 * 1024 * 1024,
    FORMS = "[data-media-upload-form], [data-album-upload-form]",
    INPUTS = "[data-media-upload], [data-album-upload]";
  const state = (form) => {
    if (!states.has(form))
      states.set(form, {
        pending: new Map(),
        images: [],
        page: 1,
        job: null,
        stale: false,
      });
    return states.get(form);
  };
  const local = (value) => {
    const url = new URL(value, location.href);
    if (url.origin !== location.origin || url.username || url.password)
      throw Error("Bildzugang prüfen.");
    return url;
  };
  const checkedImage = (image, id) => {
    if (
      !image ||
      image.id !== id ||
      !/^[a-f0-9]{32}$/.test(id) ||
      typeof image.name !== "string"
    )
      throw Error("Speicherbestätigung prüfen.");
    const url = local(image.edit_url);
    if (
      (url.searchParams.get("item") || url.searchParams.get("native_item")) !==
        id ||
      url.searchParams.get("section") !== "library"
    )
      throw Error("Gespeichertes Bild prüfen.");
    return { id, name: image.name, href: url.href };
  };
  const isAlbum = (form) => form.hasAttribute("data-album-upload-form");
  function albumReceipt(form, receipt, base, status = false) {
    const id = form.querySelector('[name="id"]').value;
    if (
      !receipt ||
      receipt.id !== id ||
      !Number.isInteger(base) ||
      base < 1 ||
      base > 4294967294 ||
      receipt.version !== base + 1 ||
      (status && typeof receipt.current !== "boolean")
    )
      throw Error("Albumfassung prüfen.");
    const s = state(form),
      root = form.closest("[data-album]");
    if (
      receipt.current === false ||
      s.stale ||
      !root ||
      root.dataset.album !== id ||
      Number(root.dataset.albumVersion) !== base ||
      Number(form.querySelector('[name="version"]').value) !== base
    ) {
      s.stale = true;
      return false;
    }
    // Advance only the exact accepted version; leave every unsaved visible field intact.
    const workspace = form.closest("#workspace") || root;
    for (const ownForm of workspace.querySelectorAll("form")) {
      const ownId = ownForm.querySelector('[name="id"]'),
        version = ownForm.querySelector('[name="version"]');
      if (ownId?.value === id && Number(version?.value) === base)
        version.value = String(receipt.version);
    }
    root.dataset.albumVersion = String(receipt.version);
    root.dataset.albumCount = String(Number(root.dataset.albumCount) + 1);
    const count = workspace.querySelector("[data-album-photo-count]");
    if (count) count.textContent = root.dataset.albumCount;
    const draft = workspace.querySelector("[data-album-draft-status]");
    if (draft) draft.textContent = " · " + mediaText("Entwurf gespeichert");
    return true;
  }
  const button = (label, action) => {
    const node = document.createElement("button");
    node.type = "button";
    node.className = "btn btn-outline theme-secondary";
    node.textContent = mediaText(label);
    node.onclick = action;
    return node;
  };
  function results(form) {
    const s = state(form),
      panel = form.querySelector("[data-library-upload-results]");
    panel.replaceChildren();
    panel.hidden = !s.images.length;
    if (!s.images.length) return;
    const pages = Math.ceil(s.images.length / 6);
    s.page = Math.max(1, Math.min(s.page, pages));
    const title = document.createElement("p");
    title.textContent = mediaFormat("Gespeicherte Bilder · {count}", {count: s.images.length});
    panel.append(title);
    for (const image of s.images.slice((s.page - 1) * 6, s.page * 6)) {
      const link = document.createElement("a");
      link.className = "btn btn-outline theme-secondary";
      link.dataset.nav = "";
      link.dataset.libraryUploaded = image.id;
      link.href = image.href;
      link.textContent = image.name;
      panel.append(link);
    }
    if (pages > 1) {
      const nav = document.createElement("nav");
      nav.setAttribute("aria-label", mediaText("Gespeicherte Uploads"));
      const previous = button("Zurück", () => {
        s.page--;
        results(form);
        form.querySelector("[data-library-results-page]").focus();
      });
      previous.disabled = s.page === 1;
      const next = button("Weiter", () => {
        s.page++;
        results(form);
        form.querySelector("[data-library-results-page]").focus();
      });
      next.disabled = s.page === pages;
      const label = document.createElement("label"),
        select = document.createElement("select");
      label.textContent = mediaText("Seite") + " ";
      select.className = "form-select";
      select.setAttribute("aria-label", mediaText("Seite gespeicherter Uploads"));
      select.dataset.libraryResultsPage = "";
      select.dataset.noSelectSearch = "";
      for (let page = 1; page <= pages; page++)
        select.add(
          new Option(
            mediaFormat("{page} von {pages}", {page, pages}),
            String(page),
            false,
            page === s.page,
          ),
        );
      select.onchange = () => {
        s.page = Number(select.value);
        results(form);
        form.querySelector("[data-library-results-page]").focus();
      };
      label.append(select);
      nav.append(previous, label, next);
      panel.append(nav);
    }
  }
  function remember(form, image) {
    const s = state(form);
    if (!s.images.some((old) => old.id === image.id)) s.images.push(image);
    if (s.images.length > 50) s.images.splice(0, s.images.length - 50);
    results(form);
  }
  function controls(form) {
    const s = state(form),
      job = s.job;
    for (const field of form.querySelectorAll(
      INPUTS + ", .photo-edit-launch, button[type=submit], button:not([type])",
    ))
      field.disabled =
        !!job || (isAlbum(form) && (s.stale || !!s.pending.size));
    const cancel = form.querySelector("[data-library-upload-cancel]"),
      check = form.querySelector("[data-library-upload-check]"),
      forget = form.querySelector("[data-library-upload-forget]");
    cancel.hidden = job?.kind !== "upload";
    check.hidden = !s.pending.size;
    check.disabled = !!job;
    check.textContent = mediaFormat("Uploads prüfen ({count})", {count: s.pending.size});
    forget.hidden = !s.pending.size;
    forget.disabled = job?.kind === "upload";
    const reload = form.querySelector("[data-album-upload-reload]");
    if (reload) {
      reload.hidden =
        !s.stale && (!!job || !!s.pending.size || !s.images.length);
      reload.textContent = mediaText(s.stale ? "Album neu laden" : "Albumfotos anzeigen");
      if (!s.stale && s.images.length) {
        const url = local(reload.getAttribute("href"));
        url.searchParams.set(
          "album_page",
          String(
            Math.max(
              1,
              Math.ceil(
                Number(form.closest("[data-album]").dataset.albumCount) / 6,
              ),
            ),
          ),
        );
        url.hash = "album-image-list";
        reload.href = url.href;
      }
    }
    if (job) form.setAttribute("aria-busy", "true");
    else form.removeAttribute("aria-busy");
  }
  const begin = (form, kind) => {
    const job = { form, kind, controller: new AbortController(), ended: false };
    state(form).job = job;
    jobs.add(job);
    controls(form);
    return job;
  };
  const finish = (job) => {
    jobs.delete(job);
    state(job.form).job = null;
    controls(job.form);
  };
  async function upload(form, files) {
    const s = state(form),
      status = form.querySelector("[data-upload-progress]");
    if (s.job || !files.length || !form.isConnected) return;
    if (isAlbum(form) && (s.stale || s.pending.size)) {
      status.textContent = s.stale
        ? mediaText("Album wurde geändert. Album neu laden.")
        : mediaText("Offenen Upload zuerst prüfen.");
      return;
    }
    if (files.length > 50 || s.pending.size + files.length > 50) {
      status.textContent =
        mediaText("Höchstens 50 Bilder auswählen; offene Uploads zuerst prüfen.");
      return;
    }
    if (files.reduce((total, file) => total + file.size, 0) > MAX_BATCH_BYTES) {
      status.textContent = mediaText("Kameraoriginale zusammen bis 512 MiB auswählen.");
      return;
    }
    if (!globalThis.crypto?.getRandomValues) {
      status.textContent = mediaText("Sichere Upload-Kennung nicht verfügbar.");
      return;
    }
    const base = new FormData(form),
      job = begin(form, "upload");
    let version = isAlbum(form) ? Number(base.get("version")) : null;
    let accepted = 0,
      failures = 0,
      message = "";
    const errors = [];
    try {
      const url = local(form.getAttribute("action") || location.href);
      for (let index = 0; index < files.length; index++) {
        if (job.controller.signal.aborted || !form.isConnected) break;
        const file = files[index];
        if (file.size > MAX_BYTES) {
          failures++;
          if (errors.length < 3)
            errors.push(mediaFormat("{name}: größer als 256 MiB", {name: file.name}));
          continue;
        }
        const id = Array.from(
          crypto.getRandomValues(new Uint8Array(16)),
          (byte) => byte.toString(16).padStart(2, "0"),
        ).join("");
        const body = new FormData();
        for (const [key, value] of base)
          if (key !== "image" && key !== "image[]" && key !== "upload_id")
            body.append(key, value);
        body.set("image", file, file.name);
        body.set("upload_id", id);
        if (isAlbum(form)) body.set("version", String(version));
        s.pending.set(id, { name: file.name, version });
        controls(form);
        status.textContent =
          mediaFormat("Bild {number} von {count} wird hochgeladen …", {number: index + 1, count: files.length});
        const timer = setTimeout(() => job.controller.abort(), 45000);
        try {
          const response = await fetch(url, {
            method: "POST",
            body,
            headers: { Accept: "application/json" },
            credentials: "same-origin",
            redirect: "error",
            signal: job.controller.signal,
          });
          const data = await response.json();
          if (job.controller.signal.aborted || !form.isConnected) break;
          if (!response.ok) {
            if (![400, 403, 404, 409, 413, 415, 422].includes(response.status))
              throw Error("Upload nicht bestätigt.");
            s.pending.delete(id);
            failures++;
            if (errors.length < 3)
              errors.push(
                file.name +
                  ": " +
                  (typeof data.error === "string"
                    ? mediaText(data.error)
                    : mediaText("Upload fehlgeschlagen")),
              );
            if ([403, 404, 409].includes(response.status)) {
              if (isAlbum(form)) s.stale = true;
              break;
            }
            continue;
          }
          if (data.ok !== true) throw Error("Upload nicht bestätigt.");
          const image = checkedImage(data.image, id);
          const current =
            !isAlbum(form) || albumReceipt(form, data.image.album, version);
          s.pending.delete(id);
          accepted++;
          remember(form, image);
          if (!current) break;
          if (isAlbum(form)) version++;
        } finally {
          clearTimeout(timer);
        }
      }
    } catch {
      message = " " + mediaText("Upload nicht bestätigt. Uploads prüfen.");
    } finally {
      form.querySelector(INPUTS).value = "";
      finish(job);
      if (form.isConnected)
        status.textContent =
          mediaFormat(accepted === 1 ? "{count} Bild gespeichert." : "{count} Bilder gespeichert.", {count: accepted}) +
          (failures ? " " + mediaFormat("{count} fehlgeschlagen: {issues}", {count: failures, issues: errors.join(" · ")}) : "") +
          (job.controller.signal.aborted ? " " + mediaText("Upload gestoppt.") : message) +
          (s.stale ? " " + mediaText("Album wurde geändert. Album neu laden.") : "");
    }
  }
  async function inspect(form) {
    const s = state(form);
    if (s.job || !s.pending.size) return;
    const job = begin(form, "check"),
      status = form.querySelector("[data-upload-progress]"),
      timer = setTimeout(() => job.controller.abort(), 45000);
    let found = 0,
      failed = false;
    try {
      const url = local(
        isAlbum(form)
          ? form.dataset.albumPhotoStatusUrl
          : form.dataset.libraryPhotoStatusUrl,
      );
      for (const [id, pending] of s.pending) {
        if (job.controller.signal.aborted || !form.isConnected) break;
        url.searchParams.set("upload_id", id);
        if (isAlbum(form)) {
          url.searchParams.set(
            "album_id",
            form.querySelector('[name="id"]').value,
          );
          url.searchParams.set("version", String(pending.version));
        }
        status.textContent = mediaText("Uploads werden geprüft …");
        const response = await fetch(url, {
          credentials: "same-origin",
          redirect: "error",
          headers: { Accept: "application/json" },
          signal: job.controller.signal,
        });
        if (!response.ok) throw Error("Prüfung nicht möglich.");
        const data = await response.json();
        if (job.controller.signal.aborted || !form.isConnected) break;
        if (typeof data.found !== "boolean")
          throw Error("Speicherbestätigung prüfen.");
        if (!data.found) continue;
        const image = checkedImage(data.image, id);
        if (isAlbum(form))
          albumReceipt(form, data.album, pending.version, true);
        s.pending.delete(id);
        remember(form, image);
        found++;
      }
    } catch {
      failed = !job.controller.signal.aborted;
    } finally {
      clearTimeout(timer);
      finish(job);
      if (form.isConnected && !job.ended)
        status.textContent =
          mediaFormat("{count} Bilder gefunden.", {count: found}) +
          (s.pending.size ? " " + mediaFormat("{count} noch unbestätigt.", {count: s.pending.size}) : "") +
          (failed ? " " + mediaText("Prüfung nicht möglich. Später erneut prüfen.")
            : job.controller.signal.aborted ? " " + mediaText("Prüfung gestoppt.") : "") +
          (s.stale ? " " + mediaText("Album wurde geändert. Album neu laden.") : "");
    }
  }
  function hydrate(root) {
    const forms = [
      ...(root.matches?.(FORMS) ? [root] : []),
      ...(root.querySelectorAll?.(FORMS) || []),
    ];
    for (const form of forms) {
      const fresh = !states.has(form);
      state(form);
      const input = form.querySelector(INPUTS);
      if (fresh && input.files.length && !input.disabled)
        queueMicrotask(() => {
          if (
            form.isConnected &&
            !state(form).job &&
            input.files.length &&
            !input.disabled
          )
            input.dispatchEvent(new Event("change", { bubbles: true }));
        });
    }
  }
  document.addEventListener("change", (event) => {
    if (event.target.matches(INPUTS))
      void upload(event.target.form, [...event.target.files]);
  });
  document.addEventListener(
    "submit",
    (event) => {
      if (!event.target.matches(FORMS)) return;
      event.preventDefault();
      event.stopPropagation();
      void upload(event.target, [...event.target.querySelector(INPUTS).files]);
    },
    true,
  );
  document.addEventListener("click", (event) => {
    const action = event.target.closest(
      "[data-library-upload-cancel], [data-library-upload-check], [data-library-upload-forget]",
    );
    if (!action || action.disabled) return;
    event.preventDefault();
    const form = action.closest(FORMS),
      s = state(form);
    if (action.hasAttribute("data-library-upload-cancel"))
      s.job?.controller.abort();
    else if (action.hasAttribute("data-library-upload-check"))
      void inspect(form);
    else {
      if (s.job) {
        s.job.ended = true;
        s.job.controller.abort();
      }
      s.pending.clear();
      if (isAlbum(form)) s.stale = true;
      controls(form);
      form.querySelector("[data-upload-progress]").textContent =
        mediaText("Prüfung beendet.");
    }
  });
  const abort = () => {
    for (const job of jobs) job.controller.abort();
  };
  const guarded = (root) =>
    [...root.querySelectorAll("[data-album-upload-form]")].some(
      (form) => state(form).job?.kind === "upload" || state(form).pending.size,
    );
  window.addEventListener("pagehide", abort);
  document.addEventListener("manager:before-unmount", abort);
  window.addEventListener("beforeunload", (event) => {
    if ([...jobs].some((job) => job.kind === "upload") || guarded(document)) {
      event.preventDefault();
      event.returnValue = "";
    }
  });
  const start = () => {
    hydrate(document);
    new MutationObserver((records) => {
      for (const job of jobs) if (!job.form.isConnected) job.controller.abort();
      for (const record of records)
        for (const node of record.addedNodes)
          if (node.nodeType === 1) hydrate(node);
    }).observe(document.body, { childList: true, subtree: true });
  };
  window.KulturhusPhotoLibrary = { abort, guarded };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
