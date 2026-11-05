(() => {
  "use strict";
  const currency = new Intl.NumberFormat("de-DE", {
    style: "currency",
    currency: "EUR",
  });
  document.addEventListener("change", (event) => {
    const field = event.target;
    if (
      !(field instanceof HTMLSelectElement || field instanceof HTMLInputElement)
    )
      return;
    const form = field.closest("[data-ticket-price]");
    if (!form) return;
    const prices = JSON.parse(
      form.querySelector("[data-voucher-prices]")?.textContent || "{}",
    );
    const gift = form.elements.voucher_product?.value;
    const amount =
      Number(form.dataset.ticketPrice) * Number(form.elements.quantity.value) +
      Number(prices[gift] || 0) *
        Number(form.elements.voucher_quantity?.value || 0);
    if (Number.isSafeInteger(amount) && amount >= 0)
      form.querySelector("[data-ticket-total]").textContent = currency.format(
        amount / 100,
      );
  });
  let cameraEpoch = 0;
  let camera = null,
    frame = null,
    currentPanel = null;
  function stopCamera() {
    cameraEpoch++;
    if (frame !== null) cancelAnimationFrame(frame);
    frame = null;
    if (camera) for (const track of camera.getTracks()) track.stop();
    camera = null;
    if (currentPanel) {
      const video = currentPanel.querySelector("[data-ticket-video]");
      video.srcObject = null;
      video.hidden = true;
      currentPanel.querySelector("[data-ticket-camera-stop]").hidden = true;
    }
    currentPanel = null;
  }
  let decoder = null;
  function loadDecoder() {
    return (decoder ??= new Promise((resolve, reject) => {
      const script = document.createElement("script");
      script.src = "/assets/ticket-qr-decoder.js";
      script.onload = () => resolve(window.jsQR);
      script.onerror = () => {
        decoder = null;
        reject(new Error("QR-Scanner konnte nicht geladen werden."));
      };
      document.head.append(script);
    }));
  }
  document.addEventListener("click", async (event) => {
    if (event.target.closest("[data-ticket-camera-stop]")) {
      stopCamera();
      return;
    }
    const button = event.target.closest("[data-ticket-camera]");
    if (!button) return;
    const panel = button.closest(".ticket-scan");
    stopCamera();
    currentPanel = panel;
    const epoch = cameraEpoch;
    const status = panel.querySelector("[data-ticket-camera-status]");
    button.disabled = true;
    try {
      const decode = await loadDecoder();
      if (epoch !== cameraEpoch || !panel.isConnected) return;
      const stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: { ideal: "environment" } },
        audio: false,
      });
      if (
        epoch !== cameraEpoch ||
        currentPanel !== panel ||
        !panel.isConnected
      ) {
        for (const track of stream.getTracks()) track.stop();
        return;
      }
      camera = stream;
      const video = panel.querySelector("[data-ticket-video]");
      video.srcObject = stream;
      video.hidden = false;
      panel.querySelector("[data-ticket-camera-stop]").hidden = false;
      await video.play();
      if (epoch !== cameraEpoch || !panel.isConnected) return;
      status.textContent = "QR-Code in die Kamera halten";
      const canvas = document.createElement("canvas"),
        context = canvas.getContext("2d", { willReadFrequently: true });
      let lastDecoded = 0;
      const scan = (now = performance.now()) => {
        if (epoch !== cameraEpoch || !camera || currentPanel !== panel) return;
        if (!panel.isConnected) {
          stopCamera();
          return;
        }
        if (
          video.readyState >= 2 &&
          video.videoWidth &&
          now - lastDecoded >= 120
        ) {
          lastDecoded = now;
          const scale = Math.min(1, 720 / video.videoWidth);
          canvas.width = Math.round(video.videoWidth * scale);
          canvas.height = Math.round(video.videoHeight * scale);
          context.drawImage(video, 0, 0, canvas.width, canvas.height);
          const image = context.getImageData(0, 0, canvas.width, canvas.height);
          const found = decode(image.data, image.width, image.height);
          if (
            found &&
            /^(?:DVM:T:1:[a-f0-9]{32}:[a-f0-9]{32}|DVM:V:1:[A-F0-9]{32}|KH:1:[A-F0-9]{32}:[A-F0-9]{16})$/.test(
              found.data,
            )
          ) {
            panel.querySelector("[data-ticket-code]").value = found.data;
            status.textContent = "Ticketcode erkannt";
            stopCamera();
            panel.querySelector("form").requestSubmit();
            return;
          }
        }
        frame = requestAnimationFrame(scan);
      };
      scan();
    } catch {
      if (epoch === cameraEpoch) {
        status.textContent = "Kamera nicht verfügbar. Code eingeben.";
        stopCamera();
      }
    } finally {
      button.disabled = false;
    }
  });
  document.addEventListener(
    "toggle",
    (event) => {
      if (
        event.target instanceof HTMLDetailsElement &&
        !event.target.open &&
        event.target.contains(currentPanel)
      )
        stopCamera();
    },
    true,
  );
  window.addEventListener("pagehide", stopCamera);
  document.addEventListener("visibilitychange", () => {
    if (document.hidden) stopCamera();
  });
  const order = document.querySelector("[data-ticket-order]");
  if (
    order &&
    ["creating_checkout", "awaiting_payment", "payment_uncertain"].includes(
      order.dataset.ticketOrder,
    )
  ) {
    let attempts = 0;
    const poll = async () => {
      if (document.hidden || !order.isConnected || attempts++ >= 60) return;
      try {
        const response = await fetch(location.href, {
          credentials: "same-origin",
          cache: "no-store",
        });
        if (response.ok) {
          const documentNext = new DOMParser().parseFromString(
            await response.text(),
            "text/html",
          );
          const next = documentNext.querySelector("[data-ticket-order]");
          if (next && next.dataset.ticketOrder !== order.dataset.ticketOrder) {
            order.replaceWith(next);
            return;
          }
        }
      } catch {}
      setTimeout(poll, 5000);
    };
    setTimeout(poll, 5000);
  }
})();
