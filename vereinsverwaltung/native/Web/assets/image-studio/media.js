/* Private same-origin image loading and local pixel adjustments. */
(() => {
  "use strict";
  function create({ sources, signal, active }) {
    const images = new Map(),
      processed = new Map(),
      loads = new Set();
    async function image(key) {
      active();
      if (images.has(key)) return images.get(key);
      const url = sources.get(key);
      if (!url) throw Error("Ein eigenes Quellbild ist nicht mehr verfügbar.");
      const resolved = new URL(url, location.href);
      if (resolved.origin !== location.origin)
        throw Error("Nur eigene Medien sind erlaubt.");
      const promise = new Promise((ok, no) => {
        const im = new Image();
        loads.add(im);
        const finish = () => {
          loads.delete(im);
          signal.removeEventListener("abort", abort);
          im.onload = im.onerror = null;
        };
        const abort = () => {
          finish();
          im.src = "";
          no(new DOMException("Editor geschlossen.", "AbortError"));
        };
        signal.addEventListener("abort", abort, { once: true });
        im.onload = () => {
          finish();
          im.naturalWidth * im.naturalHeight <= 16000000
            ? ok(im)
            : no(Error("Quellbild ist zu groß."));
        };
        im.onerror = () => {
          finish();
          no(Error("Ein Quellbild konnte nicht geladen werden."));
        };
        im.src = resolved.href;
      });
      images.set(key, promise);
      return promise;
    }
    async function assets(doc) {
      await Promise.all(
        doc.layers
          .filter((l) => l.type === "image" || l.type === "qr")
          .map((l) => image(l.type === "qr" ? "qr" : l.media_ref)),
      );
      await document.fonts.ready;
    }
    async function photo(l) {
      const im = await image(l.media_ref);
      if (
        !l.exposure &&
        l.gamma === 1 &&
        !l.temperature &&
        !l.tint &&
        !l.sharpness &&
        !(l.clone_stamps || []).length
      )
        return im;
      const key = JSON.stringify([
        l.media_ref,
        l.exposure,
        l.gamma,
        l.temperature,
        l.tint,
        l.sharpness,
        l.clone_stamps || [],
      ]);
      if (processed.has(key)) return processed.get(key);
      const surface = document.createElement("canvas");
      surface.width = im.naturalWidth;
      surface.height = im.naturalHeight;
      const g = surface.getContext("2d", { willReadFrequently: true });
      g.drawImage(im, 0, 0);
      const pixels = g.getImageData(0, 0, surface.width, surface.height),
        data = pixels.data,
        exposure = 2 ** l.exposure,
        gamma = 1 / l.gamma;
      for (let i = 0; i < data.length; i += 4) {
        data[i] =
          255 *
          (Math.max(
            0,
            Math.min(
              255,
              data[i] * exposure + l.temperature * 0.35 + l.tint * 0.15,
            ),
          ) /
            255) **
            gamma;
        data[i + 1] =
          255 *
          (Math.max(0, Math.min(255, data[i + 1] * exposure - l.tint * 0.3)) /
            255) **
            gamma;
        data[i + 2] =
          255 *
          (Math.max(
            0,
            Math.min(
              255,
              data[i + 2] * exposure - l.temperature * 0.35 + l.tint * 0.15,
            ),
          ) /
            255) **
            gamma;
      }
      if (l.sharpness) {
        const original = new Uint8ClampedArray(data),
          w = surface.width,
          h = surface.height,
          amount = l.sharpness / 100;
        for (let y = 1; y < h - 1; y++)
          for (let x = 1; x < w - 1; x++) {
            const i = (y * w + x) * 4;
            for (let c = 0; c < 3; c++)
              data[i + c] =
                original[i + c] +
                amount *
                  (4 * original[i + c] -
                    original[i - 4 + c] -
                    original[i + 4 + c] -
                    original[i - w * 4 + c] -
                    original[i + w * 4 + c]);
          }
      }
      g.putImageData(pixels, 0, 0);
      window.KulturhusPhotoClones.apply(surface, l.clone_stamps || []);
      surface.naturalWidth = surface.width;
      surface.naturalHeight = surface.height;
      processed.set(key, surface);
      while (processed.size > 3)
        processed.delete(processed.keys().next().value);
      return surface;
    }

    function dispose() {
      loads.forEach((im) => {
        im.onload = im.onerror = null;
        im.src = "";
      });
      loads.clear();
      images.clear();
      processed.forEach((surface) => {
        surface.width = surface.height = 0;
      });
      processed.clear();
      sources.clear();
    }
    return Object.freeze({
      image,
      assets,
      photo,
      dispose,
      count: () => images.size,
    });
  }
  window.KulturhusImageStudioMedia = Object.freeze({ create });
})();
