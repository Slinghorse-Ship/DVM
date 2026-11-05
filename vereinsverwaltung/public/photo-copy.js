/* Edit stored photo pixels into a new private asset; original forms and uses stay intact. */
(() => {
  "use strict";
  if (window.KulturhusPhotoCopies) return;
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
  const pending = new Set(),
    jobs = new WeakMap(),
    unconfirmed = new WeakMap(),
    MAX_BYTES = 12 * 1024 * 1024;
  const waiting = (form) => {
    if (!unconfirmed.has(form)) unconfirmed.set(form, new Map());
    return unconfirmed.get(form);
  };
  const localURL = (value) => {
    const url = new URL(value, location.href);
    if (url.origin !== location.origin || url.username || url.password)
      throw Error("Bildzugang prüfen.");
    return url;
  };
  const controls = (form) => {
    const job = jobs.get(form),
      size = waiting(form).size;
    const edit = form.querySelector("[data-photo-copy-edit]"),
      check = form.querySelector("[data-photo-copy-check]"),
      forget = form.querySelector("[data-photo-copy-forget]");
    if (edit) edit.disabled = !!job;
    if (check) {
      check.hidden = !size;
      check.disabled = !!job;
      check.textContent = mediaFormat("Speicherung prüfen ({count})", {count: size});
    }
    if (forget) {
      forget.hidden = !size;
      forget.disabled = !!job && job.kind !== "check";
    }
  };
  function savedLink(image, expected) {
    if (!image || image.id !== expected || !/^[a-f0-9]{32}$/.test(image.id))
      throw Error("Speicherbestätigung prüfen.");
    const next = localURL(image.edit_url),
      id =
        next.searchParams.get("item") || next.searchParams.get("native_item");
    if (id !== image.id || next.searchParams.get("section") !== "library")
      throw Error("Gespeicherte Bildfassung prüfen.");
    const link = document.createElement("a");
    link.href = next.href;
    link.textContent = mediaText("Neue Fassung öffnen");
    link.className = "btn btn-outline theme-secondary";
    link.dataset.nav = "";
    return link;
  }
  async function inspect(form) {
    if (jobs.has(form) || !waiting(form).size) return;
    const controller = new AbortController(),
      job = { form, controller, kind: "check" },
      status = form.querySelector("[data-photo-copy-status]");
    pending.add(job);
    jobs.set(form, job);
    controls(form);
    form.setAttribute("aria-busy", "true");
    const timeout = setTimeout(() => controller.abort(), 45000);
    let found = 0,
      last = null,
      failed = false;
    try {
      const url = localURL(form.dataset.photoCopyStatusUrl);
      for (const [id] of waiting(form)) {
        if (controller.signal.aborted || !form.isConnected) break;
        url.searchParams.set("upload_id", id);
        status.textContent = mediaText("Speicherung wird geprüft …");
        const response = await fetch(url, {
          credentials: "same-origin",
          redirect: "error",
          headers: { Accept: "application/json" },
          signal: controller.signal,
        });
        if (!response.ok) throw Error("Speicherung nicht prüfbar.");
        const data = await response.json();
        if (typeof data.found !== "boolean")
          throw Error("Speicherbestätigung prüfen.");
        if (controller.signal.aborted || !form.isConnected) break;
        if (!data.found) continue;
        last = savedLink(data.image, id);
        waiting(form).delete(id);
        found++;
      }
    } catch {
      failed = !controller.signal.aborted;
    } finally {
      clearTimeout(timeout);
      pending.delete(job);
      jobs.delete(form);
      controls(form);
      form.removeAttribute("aria-busy");
      if (form.isConnected) {
        status.textContent = found
          ? mediaFormat("{count} Fassungen gefunden.", {count: found}) + " "
          : mediaText("Speicherung noch unbestätigt.") + " ";
        if (last) status.append(last);
        if (waiting(form).size)
          status.append(" · " + mediaFormat("{count} noch unbestätigt.", {count: waiting(form).size}));
        if (failed)
          status.append(" " + mediaText("Prüfung nicht möglich. Später erneut prüfen."));
        else if (controller.signal.aborted) status.append(" " + mediaText("Prüfung gestoppt."));
      }
    }
  }
  const hydrate = (root) => {
    for (const form of root.querySelectorAll("[data-photo-copy]")) {
      const button = form.querySelector("[data-photo-copy-edit]");
      if (button && window.KulturhusPhotoEditor) button.hidden = false;
      controls(form);
    }
  };
  async function edit(form) {
    if (jobs.has(form)) return;
    if (waiting(form).size >= 50 || !globalThis.crypto?.getRandomValues) {
      form.querySelector("[data-photo-copy-status]").textContent =
        waiting(form).size >= 50
          ? mediaText("Offene Speicherungen zuerst prüfen.")
          : mediaText("Sichere Upload-Kennung nicht verfügbar.");
      return;
    }
    const controller = new AbortController(),
      job = { form, controller, kind: "edit" },
      button = form.querySelector("[data-photo-copy-edit]"),
      status = form.querySelector("[data-photo-copy-status]");
    let sending = false,
      confirmedFailure = false,
      uploadId;
    pending.add(job);
    jobs.set(form, job);
    controls(form);
    form.setAttribute("aria-busy", "true");
    const timeout = setTimeout(() => controller.abort(), 45000);
    try {
      status.textContent = mediaText("Foto wird geladen …");
      const image = form.closest("figure").querySelector("img[data-original]"),
        url = localURL(image.dataset.original);
      let response;
      try {
        response = await fetch(url, {
          signal: controller.signal,
          credentials: "same-origin",
          redirect: "error",
        });
      } catch (error) {
        if (error.name === "AbortError") throw error;
        throw Error("Gespeichertes Foto konnte nicht geladen werden.");
      }
      const type = (response.headers.get("content-type") || "").split(";")[0];
      if (
        !response.ok ||
        !["image/jpeg", "image/png", "image/webp"].includes(type)
      )
        throw Error("Gespeichertes Foto konnte nicht geladen werden.");
      const reader = response.body.getReader(),
        parts = [];
      let bytes = 0;
      try {
        while (true) {
          const part = await reader.read();
          if (part.done) break;
          bytes += part.value.length;
          if (bytes > MAX_BYTES)
            throw Error("Foto überschreitet die Bearbeitungsgrenze.");
          parts.push(part.value);
        }
      } finally {
        await reader.cancel().catch(() => {});
      }
      clearTimeout(timeout);
      if (controller.signal.aborted || !form.isConnected) return;
      const file = new File(parts, form.dataset.photoCopyName || "Foto.png", {
        type,
      });
      const edited = await window.KulturhusPhotoEditor.edit(file, {
        signal: controller.signal,
      });
      if (!edited || controller.signal.aborted || !form.isConnected) {
        status.textContent = "";
        return;
      }
      const body = new FormData(form);
      body.set("image", edited, edited.name);
      uploadId = [...crypto.getRandomValues(new Uint8Array(16))]
        .map((byte) => byte.toString(16).padStart(2, "0"))
        .join("");
      body.set("upload_id", uploadId);
      waiting(form).set(uploadId, edited.name);
      controls(form);
      sending = true;
      status.textContent = mediaText("Neue Bildfassung wird gespeichert …");
      const saveTimeout = setTimeout(() => controller.abort(), 45000);
      let saved;
      try {
        saved = await fetch(
          localURL(form.getAttribute("action") || location.href),
          {
            method: "POST",
            body,
            headers: { Accept: "application/json" },
            signal: controller.signal,
            credentials: "same-origin",
            redirect: "error",
          },
        );
      } finally {
        clearTimeout(saveTimeout);
      }
      const data = await saved.json();
      if (!saved.ok) {
        confirmedFailure = [400, 403, 404, 409, 422].includes(saved.status);
        if (confirmedFailure) waiting(form).delete(uploadId);
        throw Error(
          data.error || "Bildfassung konnte nicht gespeichert werden.",
        );
      }
      if (!data.ok) throw Error("Speicherbestätigung prüfen.");
      const link = savedLink(data.image, uploadId);
      if (controller.signal.aborted || !form.isConnected) return;
      waiting(form).delete(uploadId);
      status.textContent = mediaText("Neue Fassung gespeichert.") + " ";
      status.append(link);
    } catch (error) {
      if (form.isConnected)
        status.textContent =
          sending && !confirmedFailure
            ? mediaText("Speicherung nicht bestätigt. Neue Bilder prüfen. Keine automatische Wiederholung.")
            : error.name === "AbortError"
              ? mediaText("Bearbeitung abgebrochen.")
              : mediaText(error.message);
    } finally {
      clearTimeout(timeout);
      pending.delete(job);
      jobs.delete(form);
      controls(form);
      form.removeAttribute("aria-busy");
    }
  }
  document.addEventListener("click", (event) => {
    const check = event.target.closest("[data-photo-copy-check]");
    if (check && !check.disabled) {
      event.preventDefault();
      void inspect(check.closest("[data-photo-copy]"));
      return;
    }
    const forget = event.target.closest("[data-photo-copy-forget]");
    if (forget && !forget.disabled) {
      event.preventDefault();
      const form = forget.closest("[data-photo-copy]");
      if (jobs.get(form)?.kind === "check") jobs.get(form).controller.abort();
      waiting(form).clear();
      controls(form);
      form.querySelector("[data-photo-copy-status]").textContent =
        mediaText("Prüfung beendet.");
      return;
    }
    const button = event.target.closest("[data-photo-copy-edit]");
    if (button && !button.disabled) {
      event.preventDefault();
      void edit(button.closest("[data-photo-copy]"));
    }
  });
  const abort = () => {
    for (const job of pending) job.controller.abort();
  };
  window.addEventListener("pagehide", abort);
  document.addEventListener("manager:before-unmount", abort);
  const start = () => {
    hydrate(document);
    new MutationObserver((records) => {
      for (const job of pending)
        if (!job.form.isConnected) job.controller.abort();
      for (const record of records)
        for (const node of record.addedNodes)
          if (node.nodeType === 1) hydrate(node);
    }).observe(document.body, { childList: true, subtree: true });
  };
  window.KulturhusPhotoCopies = { abort };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
