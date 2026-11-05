(() => {
  "use strict";
  const photoText = source => { const key = 'photo:' + source, value = window.KulturhusInterface?.text(key); return value === undefined || value === key ? source : value; };
  const photoFormat = (source, values) => photoText(source).replace(/\{([a-z_]+)\}/g, (token, key) => Object.hasOwn(values, key) ? String(values[key]) : token);
  if (window.KulturhusPhotos) return;
  const base = new URL(document.currentScript.src, location.href),
    RAW =
      /\.(?:dng|cr2|cr3|crw|nef|nrw|arw|sr2|srf|rw2|rwl|orf|raf|pef|ptx|raw|iiq|3fr|fff|kdc|dcr|mos|mef|mrw|erf)$/i,
    HEIF = /\.(?:heic|heif|hif)$/i;
  const ACCEPT =
    "image/heic,image/heif,.heic,.heif,.hif,.dng,.cr2,.cr3,.crw,.nef,.nrw,.arw,.sr2,.srf,.rw2,.rwl,.orf,.raf,.pef,.raw,.iiq,.3fr,.fff,.kdc,.dcr,.mos,.mef,.mrw,.erf";
  const selected = new WeakMap(),
    active = new WeakMap(),
    replaying = new WeakSet(),
    pending = new Set();
  let sequence = 0;
  const asset = (name) => {
    const url = new URL(name, base);
    if (url.origin !== location.origin)
      throw Error("Bildverarbeitung nicht verfügbar.");
    return url.href;
  };
  const rename = (file, extension, mime) =>
    new File([file], file.name.replace(/\.[^.]*$/, "") + "." + extension, {
      type: mime,
      lastModified: file.lastModified,
    });
  async function format(file) {
    const bytes = new Uint8Array(await file.slice(0, 512).arrayBuffer());
    if (bytes[0] === 255 && bytes[1] === 216 && bytes[2] === 255) return "jpeg";
    if (bytes[0] === 137 && String.fromCharCode(...bytes.slice(1, 4)) === "PNG")
      return "png";
    if(bytes.length>=12&&String.fromCharCode(...bytes.slice(0,4))==='RIFF'&&String.fromCharCode(...bytes.slice(8,12))==='WEBP')return 'webp';
    if (
      bytes.length >= 16 &&
      String.fromCharCode(...bytes.slice(4, 8)) === "ftyp"
    ) {
      const brands = String.fromCharCode(
        ...bytes.slice(8, Math.min(bytes.length, 128)),
      );
      if (/heic|heix|hevc|hevx|mif1|heif|msf1/.test(brands)) return "heif";
    }
    if (HEIF.test(file.name) || /^image\/hei[cf]/i.test(file.type))
      return "heif";
    if (
      RAW.test(file.name) ||
      /image\/(?:x-.*(?:raw|dng)|dng)/i.test(file.type)
    )
      return "raw";
    // DNGVersion (50706) in a TIFF directory also identifies an extensionless DNG.
    const little = bytes[0] === 73 && bytes[1] === 73,
      big = bytes[0] === 77 && bytes[1] === 77;
    if (bytes.length >= 8 && (little || big)) {
      const view = new DataView(bytes.buffer),
        offset = view.getUint32(4, little);
      if (view.getUint16(2, little) === 42 && offset + 2 <= bytes.length) {
        const count = Math.min(view.getUint16(offset, little), 128);
        for (
          let i = 0;
          i < count && offset + 2 + i * 12 + 12 <= bytes.length;
          i++
        )
          if (view.getUint16(offset + 2 + i * 12, little) === 50706)
            return "raw";
      }
    }
    return "";
  }
  async function encode(kind, buffer, options, signal) {
    const worker = new Worker(asset("photo-import-worker.js")),
      id = ++sequence;
    try {
      return await new Promise((resolve, reject) => {
        let timer;
        const abort = () => {
          cleanup();
          reject(new DOMException("Abgebrochen", "AbortError"));
        };
        const cleanup = () => {
          clearTimeout(timer);
          signal?.removeEventListener("abort", abort);
        };
        worker.onmessage = ({ data }) => {
          if (data.id !== id) return;
          cleanup();
          data.error ? reject(Error(data.error)) : resolve(data.result);
        };
        worker.onerror = () => {
          cleanup();
          reject(Error("Bildverarbeitung nicht verfügbar."));
        };
        signal?.addEventListener("abort", abort, { once: true });
        timer = setTimeout(() => {
          cleanup();
          reject(Error("Bildverarbeitung dauerte zu lange."));
        }, 45000);
        if (signal?.aborted) {
          cleanup();
          abort();
          return;
        }
        worker.postMessage({ id, kind, buffer, ...options }, [buffer]);
      });
    } finally {
      worker.terminate();
    }
  }
  async function convert(
    file,
    { signal, maxSide = 2400, maxBytes = 1700000 } = {},
  ) {
    if (signal?.aborted) throw new DOMException("Abgebrochen", "AbortError");
    if (!(file instanceof File)) throw Error("Bilddatei prüfen.");
    const kind = await format(file);
    if(['jpeg','png','webp'].includes(kind)){
      if(file.size<1||file.size>256*1024*1024)throw Error('Bilddatei prüfen (höchstens 256 MB).');
      const mime=kind==='jpeg'?'image/jpeg':'image/'+kind;
      if(typeof Worker!=='function'||typeof OffscreenCanvas!=='function'||typeof createImageBitmap!=='function')return RAW.test(file.name)||HEIF.test(file.name)?rename(file,kind==='jpeg'?'jpg':kind,mime):file;
      const result=await encode('raster',await file.arrayBuffer(),{mime,maxSide,maxBytes},signal);
      if(signal?.aborted)throw new DOMException('Abgebrochen','AbortError');
      if(result.unchanged)return RAW.test(file.name)||HEIF.test(file.name)?rename(file,kind==='jpeg'?'jpg':kind,mime):file;
      const extension=result.mime==='image/jpeg'?'jpg':result.mime==='image/webp'?'webp':'png';
      return new File([result.buffer],file.name.replace(/\.[^.]*$/,'')+'.'+extension,{type:result.mime,lastModified:file.lastModified});
    }
    if (!["heif", "raw"].includes(kind)) return file;
    if (file.size < 1 || file.size > 256 * 1024 * 1024)
      throw Error("Bilddatei prüfen (höchstens 256 MB).");
    let result;
    if (kind === "heif")
      result = await encode(
        "heif",
        await file.arrayBuffer(),
        { maxSide, maxBytes },
        signal,
      );
    else {
      const { default: LibRaw } = await import(asset("photo-libraw.mjs"));
      if (signal?.aborted) throw new DOMException("Abgebrochen", "AbortError");
      const raw = new LibRaw();
      let timer, abort;
      try {
        const cancelled = new Promise((_, reject) => {
          abort = () => {
            raw.dispose();
            reject(new DOMException("Abgebrochen", "AbortError"));
          };
          signal?.addEventListener("abort", abort, { once: true });
        });
        const decoded = (async () => {
          await raw.open(new Uint8Array(await file.arrayBuffer()), {
            halfSize: true,
            useCameraWb: true,
            outputBps: 8,
            outputColor: 1,
          });
          const metadata = await raw.metadata();
          if (!metadata || metadata.raw_width * metadata.raw_height > 120000000)
            throw Error("RAW-Bild ist für die Verarbeitung zu groß.");
          return await raw.imageData();
        })();
        const pixels = await Promise.race([
          decoded,
          cancelled,
          new Promise(
            (_, reject) =>
              (timer = setTimeout(
                () => reject(Error("RAW-Bildverarbeitung dauerte zu lange.")),
                45000,
              )),
          ),
        ]);
        if (
          !pixels ||
          pixels.bits !== 8 ||
          pixels.colors !== 3 ||
          !(pixels.data instanceof Uint8Array) ||
          pixels.data.length !== pixels.width * pixels.height * 3
        )
          throw Error("RAW-Datei konnte nicht umgewandelt werden.");
        const buffer =
          pixels.data.byteOffset === 0 &&
          pixels.data.byteLength === pixels.data.buffer.byteLength
            ? pixels.data.buffer
            : pixels.data.slice().buffer;
        raw.dispose();
        result = await encode(
          "rgb",
          buffer,
          { width: pixels.width, height: pixels.height, maxSide, maxBytes },
          signal,
        );
      } catch (error) {
        if (signal?.aborted || error.name === "AbortError")
          throw new DOMException("Abgebrochen", "AbortError");
        if (/^RAW-Bild/.test(error.message)) throw error;
        raw.dispose();
        try {
          const { rawPreview } = await import(asset("photo-raw-preview.mjs")),
            preview = await rawPreview(file, { signal });
          result = await encode(
            "jpeg",
            preview.buffer,
            {
              width: preview.width,
              height: preview.height,
              orientation: preview.orientation,
              maxSide,
              maxBytes,
            },
            signal,
          );
          result.preview = true;
        } catch (previewError) {
          if (signal?.aborted || previewError.name === "AbortError")
            throw new DOMException("Abgebrochen", "AbortError");
          if (/^RAW-Bild/.test(previewError.message)) throw previewError;
          throw Error(
            "RAW-Datei konnte nicht gelesen werden. Kameraformat prüfen.",
          );
        }
      } finally {
        clearTimeout(timer);
        if (abort) signal?.removeEventListener("abort", abort);
        raw.dispose();
      }
    }
    if (signal?.aborted) throw new DOMException("Abgebrochen", "AbortError");
    const name =
      file.name.replace(/\.[^.]*$/, "") +
      (result.preview ? "-vorschau" : "") +
      ".jpg";
    return new File([result.buffer], name, {
      type: "image/jpeg",
      lastModified: file.lastModified,
    });
  }
  function eligible(input) {
    return (
      input instanceof HTMLInputElement &&
      input.type === "file" &&
      input.name !== "canvas_image" &&
      !input.hasAttribute("data-photo-original") &&
      (/image\/|\.(?:jpe?g|png|webp|heic|heif|dng)(?:,|$)/i.test(
        input.accept,
      ) ||
        /^(?:image|images|logo|signature|receipt_file|document_file|document_files|inventory_file|chat_file|gema_file)(?:\[\])?$/.test(
          input.name,
        ))
    );
  }
  function hydrate(root) {
    for (const input of root?.querySelectorAll?.("input[type=file]") || [])
      if (eligible(input)) {
        if (!input.hasAttribute("data-photo-import")) {
          input.dataset.photoImport = "";
          input.accept = [input.accept, ACCEPT].filter(Boolean).join(",");
        }
        if (
          (input.hasAttribute("hidden") &&
            !input.matches("[data-nl-image-input]")) ||
          input.disabled ||
          input.hasAttribute("data-photo-editor-bound") ||
          input.parentElement?.closest("[hidden]")
        )
          continue;
        input.dataset.photoEditorBound = "";
        const edit = document.createElement("button");
        edit.type = "button";
        edit.className = "btn btn-outline theme-secondary photo-edit-launch";
        edit.textContent = photoText("Foto bearbeiten …");
        edit.setAttribute("aria-label", photoText("Foto vor dem Hochladen bearbeiten"));
        edit.onclick = (event) => {
          event.preventDefault();
          event.stopPropagation();
          if (
            active.has(input) ||
            !window.KulturhusPhotoEditor ||
            input.disabled
          )
            return;
          const picker = document.createElement("input");
          picker.type = "file";
          picker.accept = "image/jpeg,image/png,image/webp," + ACCEPT;
          picker.onchange = async () => {
            const file = picker.files[0];
            if (!file) return;
            edit.disabled = true;
            const controller = new AbortController(),
              observer = new MutationObserver(() => {
                if (!input.isConnected) controller.abort();
              });
            observer.observe(document.body, { childList: true, subtree: true });
            try {
              const accept = input.accept,
                types = ["image/jpeg", "image/png", "image/webp"].filter(
                  (type) =>
                    accept.includes("image/*") ||
                    accept.includes(type) ||
                    (type === "image/jpeg" && /\.jpe?g(?:,|$)/i.test(accept)) ||
                    (type === "image/png" && /\.png(?:,|$)/i.test(accept)) ||
                    (type === "image/webp" && /\.webp(?:,|$)/i.test(accept)),
                );
              const result = await window.KulturhusPhotoEditor.edit(file, {
                signal: controller.signal,
                allowedTypes: types.length
                  ? types
                  : ["image/jpeg", "image/png"],
              });
              if (result && input.isConnected) {
                const transfer = new DataTransfer();
                transfer.items.add(result);
                input.files = transfer.files;
                input.dispatchEvent(new Event("change", { bubbles: true }));
              }
            } catch (error) {
              if (error.name !== "AbortError")
                report([
                  photoText(String(error.message || "Bilddatei prüfen.")).slice(0, 160),
                ]);
            } finally {
              observer.disconnect();
              edit.disabled = false;
              if (edit.isConnected && !input.disabled) edit.focus();
            }
          };
          picker.click();
        };
        (input.closest("label") || input).insertAdjacentElement(
          "afterend",
          edit,
        );
      }
  }
  function report(issues) {
    if (!issues.length) return;
    let box = document.getElementById("photo-import-message");
    if (!box) {
      box = document.createElement("div");
      box.id = "photo-import-message";
      box.className = "photo-import-message";
      box.setAttribute("role", "status");
      const text = document.createElement("p"),
        close = document.createElement("button");
      close.type = "button";
      close.textContent = "×";
      close.setAttribute("aria-label", photoText("Bildmeldung schließen"));
      close.onclick = () => box.remove();
      box.append(text, close);
      document.body.append(box);
    }
    box.querySelector("p").textContent = photoFormat(
      issues.length === 1 ? "{count} Datei konnte nicht umgewandelt werden: {issues}" : "{count} Dateien konnten nicht umgewandelt werden: {issues}",
      {count: issues.length, issues: issues.slice(0, 3).join(" · ")},
    ) + (issues.length > 3 ? photoFormat(" · {count} weitere", {count: issues.length - 3}) : "");
  }
  document.addEventListener(
    "change",
    async (event) => {
      const input = event.target;
      if (
        !eligible(input) ||
        (input.hasAttribute("data-photo-server") && /^(?:receipt_file|document_file|document_files|inventory_file|chat_file|gema_file)(?:\[\])?$/.test(input.name)) ||
        input.disabled ||
        replaying.has(input) ||
        active.has(input) ||
        !input.files.length
      )
        return;
      // Sniff before any module handles the selection, including mislabeled camera files.
      const files = [...input.files];
      event.stopImmediatePropagation();
      const controller = new AbortController();
      active.set(input, controller);
      selected.set(input, files);
      const job = { input, controller };
      pending.add(job);
      const form = input.form,
        buttons = [
          ...(form?.querySelectorAll(
            "button[type=submit],button:not([type]),input[type=submit]",
          ) || []),
        ].map((button) => [button, button.disabled]);
      for (const [button] of buttons) button.disabled = true;
      input.disabled = true;
      const progress = document.createElement("div");
      progress.className = "photo-import-progress";
      progress.setAttribute("role", "status");
      const status = document.createElement("output"),
        cancel = document.createElement("button");
      cancel.type = "button";
      cancel.textContent = photoText("Abbrechen");
      cancel.onclick = () => controller.abort();
      progress.append(status, cancel);
      input.insertAdjacentElement("afterend", progress);
      const ready = [],
        issues = [];
      try {
        if (files.length > 50) throw Error("Höchstens 50 Bilder auswählen.");
        for (let index = 0; index < files.length; index++) {
          if (!input.isConnected)
            throw new DOMException("Ansicht gewechselt", "AbortError");
          status.textContent = photoFormat("Bild {number} von {count} wird vorbereitet …", {number: index + 1, count: files.length});
          try {
            ready.push(
              await convert(files[index], {
                signal: controller.signal,
                maxSide: /^chat_file(?:\[\])?$/.test(input.name) ? 2000 : 2400,
              }),
            );
          } catch (error) {
            if (controller.signal.aborted || error.name === "AbortError")
              throw error;
            issues.push(
              files[index].name +
                ": " +
                photoText(String(error.message || "Bilddatei prüfen.")).slice(0, 160),
            );
          }
        }
        if (!input.isConnected || controller.signal.aborted)
          throw new DOMException("Abgebrochen", "AbortError");
        const transfer = new DataTransfer();
        for (const file of ready) transfer.items.add(file);
        input.files = transfer.files;
        report(issues);
      } catch (error) {
        input.value = "";
        if (error.name !== "AbortError") report([photoText(error.message)]);
      } finally {
        input.disabled = !!input.parentElement?.closest("[hidden]");
        for (const [button, disabled] of buttons) button.disabled = disabled;
        progress.remove();
        active.delete(input);
        pending.delete(job);
      }
      if (
        input.isConnected &&
        input.files.length &&
        !controller.signal.aborted
      ) {
        replaying.add(input);
        try {
          input.dispatchEvent(new Event("change", { bubbles: true }));
        } finally {
          replaying.delete(input);
        }
      }
    },
    true,
  );
  document.addEventListener(
    "submit",
    (event) => {
      for (const input of event.target.querySelectorAll("input[type=file]"))
        if (active.has(input)) {
          event.preventDefault();
          event.stopImmediatePropagation();
          return;
        }
    },
    true,
  );
  window.KulturhusPhotos = {
    convert,
    format,
    accept: ACCEPT,
    originals: (input) => selected.get(input) || [...input.files],
  };
  window.addEventListener("pagehide", () => {
    for (const job of pending) job.controller.abort();
  });
  const start = () => {
    hydrate(document);
    new MutationObserver((records) => {
      for (const job of pending)
        if (
          !job.input.isConnected ||
          job.input.parentElement?.closest("[hidden]")
        )
          job.controller.abort();
      for (const record of records) {
        if (record.type === "attributes")
          hydrate(record.target.parentElement || record.target);
        for (const node of record.addedNodes)
          if (node.nodeType === 1) {
            if (eligible(node)) hydrate(node.parentElement);
            else hydrate(node);
          }
      }
    }).observe(document.body, {
      childList: true,
      subtree: true,
      attributes: true,
      attributeFilter: ["disabled", "hidden"],
    });
  };
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", start, { once: true });
  else start();
})();
