import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { spawn } from "node:child_process";
import { createServer } from "node:net";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../", import.meta.url));
const heic = await readFile(
    root + "tests/fixtures/photo-import/own-camera.heic",
  ),
  dng = await readFile(root + "tests/fixtures/photo-import/own-camera.dng"),
  jxl = await readFile(
    root + "tests/fixtures/photo-import/own-jxl-preview.dng",
  );
const free = createServer();
await new Promise((resolve) => free.listen(0, "127.0.0.1", resolve));
const port = free.address().port;
await new Promise((resolve) => free.close(resolve));
const origin = "http://127.0.0.1:" + port;
const server = spawn(
  "php",
  ["-S", "127.0.0.1:" + port, root + "tests/support/photo-import-router.php"],
  { cwd: root, env: { ...process.env, KH_NATIVE_CI: "1" }, stdio: "ignore" },
);
let browser,
  checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
async function session(width = 390, suffix = "") {
  const context = await browser.newContext({
    viewport: { width, height: 850 },
    hasTouch: true,
  });
  await context.route("**/*", (r) => {
    if (new URL(r.request().url()).origin !== origin) {
      external.push(r.request().url());
      return r.abort();
    }
    if (r.request().method() === "POST") writes.push(r.request().url());
    return r.continue();
  });
  await context.addInitScript(() => {
    Object.defineProperty(CanvasRenderingContext2D.prototype, "filter", {
      configurable: true,
      get: () => "none",
      set: () => {},
    });
  });
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  await page.goto(origin + suffix);
  await page.waitForFunction(() => !!window.KulturhusPhotoEditor);
  return { context, page };
}
async function ownFile(page) {
  return page.evaluate(async () => {
    const c = document.createElement("canvas");
    c.width = 240;
    c.height = 160;
    const ctx = c.getContext("2d");
    ctx.fillStyle = "#ed2525";
    ctx.fillRect(0, 0, 120, 160);
    ctx.fillStyle = "#2555ed";
    ctx.fillRect(120, 0, 120, 160);
    const blob = await new Promise((resolve) => c.toBlob(resolve, "image/png"));
    const file = new File([blob], "Own-source.png", { type: "image/png" });
    window.ownPhoto = file;
    window.originalHash = [...new Uint8Array(await file.arrayBuffer())].join(
      ",",
    );
    return file.size;
  });
}
async function start(page, file = null) {
  await page.evaluate(
    (data) => {
      const source = data
        ? new File([new Uint8Array(data.bytes)], data.name, { type: "" })
        : window.ownPhoto;
      window.photoResult = undefined;
      window.photoEditError = "";
      window.KulturhusPhotoEditor.edit(source)
        .then((file) => {
          window.photoResult = file === null ? null : file;
        })
        .catch((e) => (window.photoEditError = e.message));
    },
    file
      ? {
          bytes: [...file],
          name:
            file === dng || file === jxl ? "Own-camera.DNG" : "Own-camera.HEIC",
        }
      : null,
  );
  await page.waitForFunction(
    () => !!document.querySelector(".photo-editor")?.photoEditor,
  );
  return page.getByRole("dialog");
}
async function pixels(page) {
  return page.evaluate(async () => {
    const file = window.photoResult;
    if (!file) return null;
    const bitmap = await createImageBitmap(file),
      c = document.createElement("canvas");
    c.width = bitmap.width;
    c.height = bitmap.height;
    const ctx = c.getContext("2d");
    ctx.drawImage(bitmap, 0, 0);
    const pixel = (x, y) => [...ctx.getImageData(x, y, 1, 1).data];
    return {
      mime: file.type,
      name: file.name,
      size: file.size,
      width: c.width,
      height: c.height,
      top: pixel(Math.floor(c.width / 2), Math.floor(c.height / 4)),
      bottom: pixel(Math.floor(c.width / 2), Math.floor((c.height * 3) / 4)),
      center: pixel(Math.floor(c.width / 2), Math.floor(c.height / 2)),
    };
  });
}
try {
  for (let i = 0; i < 100; i++) {
    try {
      if ((await fetch(origin)).ok) break;
    } catch {}
    if (server.exitCode !== null)
      throw Error("Own editor fixture server failed.");
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  browser = await chromium.launch(
    process.env.PLAYWRIGHT_MODULE
      ? { executablePath: "/usr/bin/chromium" }
      : {},
  );
  for (const width of [320, 390, 820]) {
    const { context, page } = await session(width);
    await ownFile(page);
    let dialog = await start(page);
    check(
      (await dialog
        .boundingBox()
        .then((b) => b.width <= width + 1 && b.height <= 851)) &&
        (await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        )),
      width + " native modal and preview fit the actual viewport",
    );
    const controls = await dialog.locator("button:visible").all();
    check(
      (await Promise.all(controls.map((b) => b.boundingBox()))).every(
        (b) => b.width >= 43.5 && b.height >= 43.5,
      ),
      width + " visible photo tools have actual touch targets",
    );
    check(
      (await dialog.getByRole("tabpanel", { name: "Zuschnitt" }).isVisible()) &&
        (await dialog
          .getByRole("tabpanel", { name: "Licht & Farbe" })
          .isHidden()),
      width + " only the chosen tool panel is visible",
    );
    await dialog
      .getByRole("button", { name: "Rechts drehen", exact: true })
      .click();
    check(
      await dialog.evaluate((e) => e.photoEditor.snapshot().rotation === 90),
      width + " rotation changes the real source geometry",
    );
    await dialog
      .getByRole("button", { name: "Rückgängig", exact: true })
      .click();
    check(
      await dialog.evaluate((e) => e.photoEditor.snapshot().rotation === 0),
      width + " undo restores actual geometry",
    );
    await dialog
      .getByRole("button", { name: "Wiederherstellen", exact: true })
      .click();
    const first = await dialog
      .locator("canvas")
      .evaluate((e) => [e.width, e.height]);
    await dialog
      .getByRole("button", { name: "Übernehmen", exact: true })
      .click();
    await page.waitForFunction(
      () => window.photoResult !== undefined || window.photoEditError,
    );
    const file = await pixels(page);
    check(
      file?.mime === "image/png" &&
        file.width === 160 &&
        file.height === 240 &&
        file.top[0] > 220 &&
        file.top[2] < 65 &&
        file.bottom[2] > 220 &&
        file.bottom[0] < 65,
      width + " export contains the actually rotated red/blue original pixels",
    );
    check(
      await page.evaluate(
        async () =>
          [...new Uint8Array(await window.ownPhoto.arrayBuffer())].join(",") ===
          window.originalHash,
      ),
      width + " editing preserves the selected original source bytes",
    );
    dialog = await start(page);
    await dialog
      .getByRole("tab", { name: "Licht & Farbe", exact: true })
      .click();
    const saturation = dialog.getByLabel("Sättigung", { exact: true });
    await saturation.focus();
    await saturation.press("Home");
    await dialog
      .getByRole("button", { name: "Übernehmen", exact: true })
      .click();
    await page.waitForFunction(() => window.photoResult !== undefined);
    const mono = await pixels(page);
    check(
      mono.center[0] === mono.center[1] && mono.center[1] === mono.center[2],
      width + " color adjustment produces real grayscale output pixels",
    );
    dialog = await start(page);
    await dialog.getByRole("tab", { name: "Filter", exact: true }).focus();
    await page.keyboard.press("ArrowRight");
    check(
      (await dialog
        .getByRole("tab", { name: "Markieren" })
        .getAttribute("aria-selected")) === "true",
      width + " arrow keys switch accessible tool tabs",
    );
    await page.keyboard.press("Escape");
    await page.waitForFunction(() => window.photoResult === null);
    check(
      await page.evaluate(
        () =>
          !document.querySelector(".photo-editor") &&
          !document.documentElement.classList.contains(
            "photo-editor-body-lock",
          ),
      ),
      width + " Escape cancels without exporting and restores page scrolling",
    );
    await context.close();
  }
  const { context, page } = await session();
  await ownFile(page);
  let dialog = await start(page);
  await dialog
    .getByLabel("Seitenverhältnis", { exact: true })
    .selectOption("1");
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  check(
    (await pixels(page)).width === 160 && (await pixels(page)).height === 160,
    "square crop exports its actual chosen geometry",
  );
  dialog = await start(page, heic);
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  const camera = await pixels(page);
  check(
    camera.mime === "image/jpeg" &&
      camera.width === 96 &&
      camera.height === 64 &&
      Math.abs(camera.center[1] - 140) < 12,
    "actual HEIC can be opened, edited and exported through the same modal",
  );
  dialog = await start(page);
  await dialog.getByRole("tab", { name: "Markieren", exact: true }).click();
  await dialog.getByLabel("Werkzeug", { exact: true }).selectOption("redact");
  let box = await dialog.locator("canvas").boundingBox();
  await page.mouse.move(box.x + box.width * 0.3, box.y + box.height * 0.3);
  await page.mouse.down();
  await page.mouse.move(box.x + box.width * 0.7, box.y + box.height * 0.7, {
    steps: 4,
  });
  await page.mouse.up();
  check(
    await dialog.evaluate(
      (e) =>
        e.photoEditor.snapshot().marks.length === 1 &&
        e.photoEditor.snapshot().marks[0].type === "redact",
    ),
    "direct pointer annotation stores a real redaction rectangle",
  );
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  const redacted = await pixels(page);
  check(
    redacted.center[0] < 30 &&
      redacted.center[1] < 30 &&
      redacted.center[2] < 40,
    "exported redaction actually replaces original image pixels",
  );
  dialog = await start(page);
  await dialog.getByRole("tab", { name: "Markieren", exact: true }).click();
  await dialog.getByLabel("Werkzeug", { exact: true }).selectOption("text");
  await dialog
    .getByLabel("Eigener Text", { exact: true })
    .fill("Unser eigener Text");
  box = await dialog.locator("canvas").boundingBox();
  await page.mouse.click(box.x + box.width * 0.2, box.y + box.height * 0.5);
  check(
    await dialog.evaluate(
      (e) => e.photoEditor.snapshot().marks[0].text === "Unser eigener Text",
    ),
    "text annotation uses only the entered user text",
  );
  await dialog.getByRole("button", { name: "Rückgängig", exact: true }).click();
  check(
    await dialog.evaluate((e) => !e.photoEditor.snapshot().marks.length),
    "undo removes the exact manually entered annotation",
  );
  await dialog.getByRole("button", { name: "Abbrechen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult === null);
  dialog = await start(page);
  box = await dialog.locator("canvas").boundingBox();
  const cdp = await context.newCDPSession(page),
    x = box.x + box.width / 2,
    y = box.y + box.height / 2;
  await cdp.send("Input.dispatchTouchEvent", {
    type: "touchStart",
    touchPoints: [
      { x: x - 30, y, id: 1 },
      { x: x + 30, y, id: 2 },
    ],
  });
  await cdp.send("Input.dispatchTouchEvent", {
    type: "touchMove",
    touchPoints: [
      { x: x - 55, y, id: 1 },
      { x: x + 55, y, id: 2 },
    ],
  });
  await cdp.send("Input.dispatchTouchEvent", {
    type: "touchEnd",
    touchPoints: [],
  });
  check(
    await dialog.evaluate(
      (e) =>
        e.photoEditor.snapshot().zoom > 1.5 &&
        e.photoEditor.snapshot().zoom < 2.2,
    ),
    "actual two-finger touch enlarges the crop",
  );
  await dialog.getByRole("button", { name: "Rückgängig", exact: true }).click();
  check(
    await dialog.evaluate((e) => e.photoEditor.snapshot().zoom === 1),
    "one undo restores the complete two-finger gesture",
  );
  await dialog.getByRole("button", { name: "Abbrechen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult === null);
  dialog = await start(page);
  await dialog.getByRole("tab", { name: "Ausgabe", exact: true }).click();
  await dialog
    .getByLabel("Dateiformat", { exact: true })
    .selectOption("image/webp");
  await dialog.getByLabel("Breite in Pixel", { exact: true }).fill("120");
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  const webp = await pixels(page);
  check(
    webp.mime === "image/webp" && webp.width === 120 && webp.height === 80,
    "chosen WebP output contains real resized image bytes",
  );
  await page.setViewportSize({ width: 568, height: 320 });
  dialog = await start(page);
  check(
    await dialog
      .getByRole("button", { name: "Übernehmen", exact: true })
      .boundingBox()
      .then((b) => b.y >= 0 && b.y + b.height <= 321),
    "apply remains visible in a short landscape viewport",
  );
  await dialog.getByRole("button", { name: "Abbrechen", exact: true }).click();
  await page.setViewportSize({ width: 390, height: 850 });
  dialog = await start(page, dng);
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  const raw = await pixels(page);
  check(
    raw.width === 64 &&
      raw.height === 48 &&
      raw.mime === "image/jpeg" &&
      raw.center[0] > 240,
    "actual DNG is decoded and exported through the optional editor",
  );
  dialog = await start(page);
  await dialog.getByRole("tab", { name: "Ausgabe", exact: true }).click();
  await dialog.getByLabel("Breite in Pixel", { exact: true }).fill("9000");
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  check(
    (await dialog.isVisible()) &&
      (await dialog
        .locator(".photo-editor-status")
        .innerText()
        .then((t) => t.includes("Ausgabebreite"))) &&
      (await page.evaluate(() => window.photoResult === undefined)),
    "invalid output stays editable and exports nothing",
  );
  await dialog.getByLabel("Breite in Pixel", { exact: true }).fill("120");
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  check(
    (await pixels(page)).width === 120,
    "correcting an invalid export resumes without reselecting the source",
  );
  await page.evaluate(async () => {
    const c = document.createElement("canvas");
    c.width = 80;
    c.height = 60;
    c.getContext("2d").fillRect(0, 0, 20, 20);
    const blob = await new Promise((resolve) => c.toBlob(resolve, "image/png"));
    window.ownPhoto = new File([blob], "Own-transparent.png", {
      type: "image/png",
    });
  });
  dialog = await start(page);
  await dialog.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await page.waitForFunction(() => window.photoResult !== undefined);
  check(
    (await pixels(page)).mime === "image/png" &&
      (await pixels(page)).center[3] === 0,
    "ordinary transparent PNG keeps its alpha channel in edited PNG output",
  );
  await context.close();
  const cancelled = await session();
  let blocked;
  await cancelled.context.route("**/photo-libraw.wasm", (r) => {
    blocked = r;
  });
  await cancelled.page.evaluate(
    (bytes) => {
      window.cancelledPhoto = undefined;
      KulturhusPhotoEditor.edit(
        new File([new Uint8Array(bytes)], "Own.DNG"),
      ).then((file) => (window.cancelledPhoto = file));
    },
    [...dng],
  );
  await cancelled.page
    .getByRole("dialog")
    .getByRole("button", { name: "Abbrechen", exact: true })
    .click();
  await cancelled.page.waitForFunction(() => window.cancelledPhoto === null);
  check(
    await cancelled.page.evaluate(
      () =>
        !document.querySelector(".photo-editor") &&
        !document.documentElement.classList.contains("photo-editor-body-lock"),
    ),
    "RAW preparation has an immediate visible cancel action and leaves no modal or upload",
  );
  if (blocked) await blocked.abort();
  await cancelled.context.close();
  const compressed = await session();
  const compressedDialog = await start(compressed.page, jxl);
  await compressedDialog
    .getByRole("button", { name: "Übernehmen", exact: true })
    .click();
  await compressed.page.waitForFunction(() => window.photoResult !== undefined);
  const compressedPixels = await pixels(compressed.page);
  check(
    compressedPixels.width === 480 &&
      compressedPixels.height === 640 &&
      compressedPixels.name.includes("-vorschau-bearbeitet") &&
      compressedPixels.top[0] > 240 &&
      compressedPixels.bottom[2] > 200,
    "optional RAW editor preserves the preview filename, portrait geometry and genuine embedded source pixels",
  );
  await compressed.context.close();
  const zoom = await session(820);
  await ownFile(zoom.page);
  await zoom.page.evaluate(() => (document.documentElement.style.zoom = "2"));
  const zoomDialog = await start(zoom.page);
  const zoomBounds = await Promise.all(
    [
      zoomDialog,
      zoomDialog.locator("canvas"),
      zoomDialog.getByRole("button", { name: "Übernehmen", exact: true }),
    ].map((e) => e.boundingBox()),
  );
  check(
    zoomBounds.every(
      (b) =>
        b.x >= -1 && b.y >= -1 && b.x + b.width <= 821 && b.y + b.height <= 851,
    ),
    "photo editor, actual preview and apply fit the viewport at 200 percent zoom",
  );
  await zoom.page.keyboard.press("Escape");
  await zoom.context.close();
  const expanded = await session();
  const ep = expanded.page;
  await ownFile(ep);
  const setRange = async (dialog, label, value) => {
    await dialog.getByLabel(label, { exact: true }).evaluate((input, value) => {
      input.value = value;
      input.dispatchEvent(new Event("input", { bubbles: true }));
      input.dispatchEvent(new Event("change", { bubbles: true }));
    }, String(value));
    await dialog.evaluate((e) => e.photoEditor.render());
  };
  let ed = await start(ep);
  check(
    await ed.locator("details").evaluate((e) => !e.open),
    "fine crop controls start compact and leave the preview visible",
  );
  await ed.getByLabel("Seitenverhältnis", { exact: true }).selectOption("-1");
  await setRange(ed, "Ausschnittbreite in Prozent", 50);
  await setRange(ed, "Ausschnitthöhe in Prozent", 75);
  await ed.getByText("Fein einstellen", { exact: true }).click();
  await setRange(ed, "Ausschnitt horizontal", 100);
  await ed.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await ep.waitForFunction(() => window.photoResult !== undefined);
  const freelyCropped = await pixels(ep);
  check(
    freelyCropped.width === 120 &&
      freelyCropped.height === 120 &&
      freelyCropped.center[2] > 220 &&
      freelyCropped.center[0] < 65,
    "free width/height and pan export the chosen blue region with actual 120 by 120 geometry",
  );
  for (const fineAngle of [-45, -17.5, 17.5, 45]) {
    ed = await start(ep);
    await ed.getByText("Fein einstellen", { exact: true }).click();
    await setRange(ed, "Gerade ausrichten", fineAngle);
    check(
      await ed.evaluate((e) => e.photoEditor.snapshot().straighten !== 0),
      "fine rotation changes the actual state at " + fineAngle,
    );
    await ed.getByRole("button", { name: "Rückgängig", exact: true }).click();
    check(
      await ed.evaluate((e) => e.photoEditor.snapshot().straighten === 0),
      "fine rotation has its own exact undo at " + fineAngle,
    );
    await ed
      .getByRole("button", { name: "Wiederherstellen", exact: true })
      .click();
    await ed.getByLabel("Seitenverhältnis", { exact: true }).selectOption("1");
    await setRange(ed, "Ausschnitt vergrößern", 1.4);
    await setRange(ed, "Ausschnitt horizontal", fineAngle < 0 ? 0 : 100);
    await setRange(ed, "Ausschnitt vertikal", fineAngle < 0 ? 100 : 0);
    await ed.getByRole("button", { name: "Übernehmen", exact: true }).click();
    await ep.waitForFunction(() => window.photoResult !== undefined);
    const rotationPixels = await ep.evaluate(async () => {
      const b = await createImageBitmap(window.photoResult),
        c = document.createElement("canvas");
      c.width = b.width;
      c.height = b.height;
      const ctx = c.getContext("2d");
      ctx.drawImage(b, 0, 0);
      return {
        width: c.width,
        height: c.height,
        alpha: [
          [2, 2],
          [c.width - 3, 2],
          [2, c.height - 3],
          [c.width - 3, c.height - 3],
        ].map(([x, y]) => ctx.getImageData(x, y, 1, 1).data[3]),
      };
    });
    check(
      rotationPixels.width === rotationPixels.height &&
        rotationPixels.alpha.every((a) => a === 255),
      "rotated crop and extreme pan stay within real opaque source pixels at " +
        fineAngle,
    );
  }
  ed = await start(ep);
  await ed.getByRole("tab", { name: "Markieren", exact: true }).click();
  await ed.getByLabel("Werkzeug", { exact: true }).selectOption("clone");
  let cb = await ed.locator("canvas").boundingBox();
  await ep.mouse.click(cb.x + cb.width * 0.25, cb.y + cb.height * 0.5);
  check(
    await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 0),
    "choosing a retouch source does not create an edit or upload",
  );
  await ep.mouse.move(cb.x + cb.width * 0.74, cb.y + cb.height * 0.5);
  await ep.mouse.down();
  await ep.mouse.move(cb.x + cb.width * 0.76, cb.y + cb.height * 0.5, {
    steps: 8,
  });
  await ep.mouse.up();
  check(
    await ed.evaluate(
      (e) => e.photoEditor.snapshot().marks[0]?.type === "clone",
    ),
    "pointer retouch stores an original-bound clone stroke",
  );
  await ed.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await ep.waitForFunction(() => window.photoResult !== undefined);
  const retouchPixel = async () =>
    ep.evaluate(async () => {
      const b = await createImageBitmap(window.photoResult),
        c = document.createElement("canvas");
      c.width = b.width;
      c.height = b.height;
      const x = c.getContext("2d");
      x.drawImage(b, 0, 0);
      return [
        ...x.getImageData(
          Math.floor(c.width * 0.75),
          Math.floor(c.height * 0.5),
          1,
          1,
        ).data,
      ];
    });
  let rp = await retouchPixel();
  check(
    rp[0] > 220 && rp[2] < 65 && rp[3] === 255,
    "exported retouch really copies red source pixels over the selected blue spot",
  );
  ed = await start(ep);
  await ed.getByRole("tab", { name: "Markieren", exact: true }).click();
  await ed.getByLabel("Werkzeug", { exact: true }).selectOption("clone");
  await ed.locator("canvas").focus();
  for (let i = 0; i < 25; i++) await ep.keyboard.press("ArrowLeft");
  await ep.keyboard.press("Space");
  for (let i = 0; i < 50; i++) await ep.keyboard.press("ArrowRight");
  await ep.keyboard.press("Enter");
  check(
    await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 1),
    "keyboard source selection and Enter perform one real retouch action",
  );
  await ed.getByRole("button", { name: "Rückgängig", exact: true }).click();
  check(
    await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 0),
    "undo removes exactly the keyboard retouch",
  );
  await ed
    .getByRole("button", { name: "Wiederherstellen", exact: true })
    .click();
  await ed.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await ep.waitForFunction(() => window.photoResult !== undefined);
  rp = await retouchPixel();
  check(
    rp[0] > 220 && rp[2] < 65,
    "keyboard retouch and redo produce the actual source pixels in the output",
  );
  const retouchPixels = async (coordinates) =>
    ep.evaluate(async (coordinates) => {
      const bitmap = await createImageBitmap(window.photoResult),
        canvas = document.createElement("canvas");
      canvas.width = bitmap.width;
      canvas.height = bitmap.height;
      const ctx = canvas.getContext("2d");
      ctx.drawImage(bitmap, 0, 0);
      bitmap.close();
      return coordinates.map(([x, y]) => [
        ...ctx.getImageData(x, y, 1, 1).data,
      ]);
    }, coordinates);
  const retouchDialog = async (tool) => {
    const dialog = await start(ep);
    await dialog.getByRole("tab", { name: "Markieren", exact: true }).click();
    await dialog.getByLabel("Werkzeug", { exact: true }).selectOption(tool);
    await setRange(dialog, "Stärke", 80);
    await setRange(dialog, "Retuscheintensität", 100);
    return dialog;
  };
  const finishRetouch = async (dialog) => {
    await dialog
      .getByRole("button", { name: "Übernehmen", exact: true })
      .click();
    await ep.waitForFunction(
      () => window.photoResult !== undefined || window.photoEditError,
    );
    check(
      !(await ep.evaluate(() => window.photoEditError)),
      "local retouch exports an actual image without an editor error",
    );
  };
  await ownFile(ep);
  ed = await retouchDialog("blur");
  check(
    (await ed.getByLabel("Farbe", { exact: true }).isHidden()) &&
      (await ed.getByLabel("Eigener Text", { exact: true }).isHidden()),
    "retouch shows only relevant controls without a long annotation form",
  );
  cb = await ed.locator("canvas").boundingBox();
  await ep.mouse.move(cb.x + cb.width * 0.5, cb.y + cb.height * 0.25);
  await ep.mouse.down();
  await ep.mouse.move(cb.x + cb.width * 0.5, cb.y + cb.height * 0.75);
  await ep.mouse.up();
  await finishRetouch(ed);
  let effects = await retouchPixels([
    [119, 40],
    [119, 80],
    [120, 120],
    [10, 10],
  ]);
  check(
    effects
      .slice(0, 3)
      .every(
        (p) =>
          p[0] > 65 && p[0] < 220 && p[2] > 65 && p[2] < 220 && p[3] === 255,
      ),
    "blur changes actual boundary pixels all along a sparse fast pointer stroke without Canvas.filter",
  );
  check(
    effects[3].join() === "237,37,37,255",
    "blur leaves pixels outside the painted stroke exactly unchanged",
  );
  check(
    await ep.evaluate(
      async () =>
        [...new Uint8Array(await window.ownPhoto.arrayBuffer())].join(",") ===
        window.originalHash,
    ),
    "local blur never overwrites the selected original file bytes",
  );
  ed = await retouchDialog("blur");
  cb = await ed.locator("canvas").boundingBox();
  const retouchCdp = await expanded.context.newCDPSession(ep);
  await retouchCdp.send("Input.dispatchTouchEvent", {
    type: "touchStart",
    touchPoints: [
      { x: cb.x + cb.width * 0.5, y: cb.y + cb.height * 0.5, id: 1 },
    ],
  });
  await retouchCdp.send("Input.dispatchTouchEvent", {
    type: "touchMove",
    touchPoints: [
      { x: cb.x + cb.width * 0.5, y: cb.y + cb.height * 0.6, id: 1 },
    ],
  });
  await retouchCdp.send("Input.dispatchTouchEvent", {
    type: "touchCancel",
    touchPoints: [],
  });
  check(
    await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 0),
    "actual canceled touch retouch restores all previous marks",
  );
  await ed.locator("canvas").focus();
  await ep.keyboard.press("Enter");
  check(
    await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 1),
    "Enter paints one blur spot using the real keyboard cursor",
  );
  await ed.getByRole("button", { name: "Rückgängig", exact: true }).click();
  check(
    await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 0),
    "blur undo removes the entire local retouch",
  );
  await ed
    .getByRole("button", { name: "Wiederherstellen", exact: true })
    .click();
  await finishRetouch(ed);
  effects = await retouchPixels([
    [119, 80],
    [10, 10],
  ]);
  check(
    effects[0][0] > 65 &&
      effects[0][0] < 220 &&
      effects[1].join() === "237,37,37,255",
    "redo restores actual blurred output pixels and retains untouched source pixels",
  );
  await ep.evaluate(async () => {
    const canvas = document.createElement("canvas");
    canvas.width = 240;
    canvas.height = 160;
    const ctx = canvas.getContext("2d"),
      pixels = ctx.createImageData(240, 160);
    for (let y = 0; y < 160; y++)
      for (let x = 0; x < 240; x++) {
        const i = (y * 240 + x) * 4,
          red = (x + y) % 2 === 0;
        pixels.data.set(red ? [237, 37, 37, 255] : [37, 85, 237, 255], i);
      }
    ctx.putImageData(pixels, 0, 0);
    window.ownPhoto = new File(
      [await new Promise((resolve) => canvas.toBlob(resolve, "image/png"))],
      "Own-checkerboard.png",
      { type: "image/png" },
    );
  });
  ed = await retouchDialog("pixelate");
  await ed.locator("canvas").focus();
  await ep.keyboard.press("Space");
  await finishRetouch(ed);
  effects = await retouchPixels([
    [120, 80],
    [121, 80],
    [10, 10],
  ]);
  check(
    effects[0].join() === "137,61,137,255" &&
      effects[1].join() === effects[0].join(),
    "pixelation exports real alpha-weighted block averages inside the selected spot",
  );
  check(
    effects[2].join() === "237,37,37,255",
    "pixelation retains all unpainted checkerboard pixels",
  );
  await ownFile(ep);
  ed = await retouchDialog("redeye");
  await ed.locator("canvas").focus();
  for (let i = 0; i < 25; i++) await ep.keyboard.press("ArrowLeft");
  await ep.keyboard.press("Enter");
  for (let i = 0; i < 50; i++) await ep.keyboard.press("ArrowRight");
  await ep.keyboard.press("Space");
  await finishRetouch(ed);
  effects = await retouchPixels([
    [60, 80],
    [180, 80],
    [10, 10],
  ]);
  check(
    effects[0].join() === "37,37,37,255",
    "manual red-eye retouch removes the painted red dominance in actual exported pixels",
  );
  check(
    effects[1].join() === "37,85,237,255" &&
      effects[2].join() === "237,37,37,255",
    "red-eye correction preserves blue painted pixels and unpainted red pixels",
  );
  ed = await retouchDialog("redeye");
  await setRange(ed, "Retuscheintensität", 50);
  cb = await ed.locator("canvas").boundingBox();
  await ep.touchscreen.tap(cb.x + cb.width * 0.25, cb.y + cb.height * 0.5);
  await finishRetouch(ed);
  effects = await retouchPixels([[60, 80]]);
  check(
    effects[0].join() === "137,37,37,255",
    "actual touch red-eye retouch respects the selected half intensity",
  );
  await ep.evaluate(async () => {
    const canvas = document.createElement("canvas");
    canvas.width = canvas.height = 64;
    const ctx = canvas.getContext("2d");
    ctx.fillStyle = "rgba(240,20,20,0.5)";
    ctx.fillRect(16, 16, 32, 32);
    window.ownPhoto = new File(
      [await new Promise((resolve) => canvas.toBlob(resolve, "image/png"))],
      "Own-alpha-retouch.png",
      { type: "image/png" },
    );
    window.retouchSourceAlpha = [...ctx.getImageData(0, 0, 64, 64).data].filter(
      (_, i) => i % 4 === 3,
    );
  });
  for (const tool of ["blur", "pixelate", "redeye"]) {
    ed = await retouchDialog(tool);
    cb = await ed.locator("canvas").boundingBox();
    await ep.touchscreen.tap(cb.x + cb.width * 0.25, cb.y + cb.height * 0.5);
    await finishRetouch(ed);
    check(
      await ep.evaluate(async () => {
        const bitmap = await createImageBitmap(window.photoResult),
          canvas = document.createElement("canvas");
        canvas.width = canvas.height = 64;
        const ctx = canvas.getContext("2d");
        ctx.drawImage(bitmap, 0, 0);
        bitmap.close();
        return [...ctx.getImageData(0, 0, 64, 64).data]
          .filter((_, i) => i % 4 === 3)
          .every((a, i) => a === window.retouchSourceAlpha[i]);
      }),
      tool +
        " preserves every original transparent and half-transparent PNG alpha pixel",
    );
    if (tool !== "redeye") {
      effects = await retouchPixels([[16, 32]]);
      check(
        effects[0][0] > 230 && effects[0][1] < 30,
        tool + " creates no dark fringe at the original transparent PNG edge",
      );
    }
  }
  await ownFile(ep);
  for (const [width, height, zoom] of [
    [320, 850, 1],
    [844, 390, 1],
    [820, 850, 2],
  ]) {
    await ep.setViewportSize({ width, height });
    await ep.evaluate(
      (zoom) => (document.documentElement.style.zoom = String(zoom)),
      zoom,
    );
    ed = await retouchDialog("pixelate");
    const bounds = await Promise.all(
      [
        ed.locator("canvas"),
        ed.getByRole("button", { name: "Übernehmen", exact: true }),
      ].map((e) => e.boundingBox()),
    );
    check(
      bounds.every(
        (b) =>
          b.x >= -1 &&
          b.y >= -1 &&
          b.x + b.width <= width + 1 &&
          b.y + b.height <= height + 1,
      ),
      "retouch preview and apply stay visible at " +
        width +
        " pixels and " +
        zoom * 100 +
        " percent zoom",
    );
    const controlBounds = await ed
      .locator("button:visible, input:visible, select:visible")
      .evaluateAll((elements) =>
        elements.map((e) => {
          const b = e.getBoundingClientRect();
          return [b.width, b.height, getComputedStyle(e).fontSize];
        }),
      );
    check(
      controlBounds.every(
        ([w, h, font]) => w >= 43.5 && h >= 43.5 && parseFloat(font) >= 12,
      ),
      "retouch controls remain at least 44 pixels at " +
        width +
        " pixels and " +
        zoom * 100 +
        " percent zoom",
    );
    await ed.getByRole("button", { name: "Abbrechen", exact: true }).click();
    await ep.waitForFunction(() => window.photoResult === null);
  }
  await ep.setViewportSize({ width: 390, height: 850 });
  await ep.evaluate(() => (document.documentElement.style.zoom = "1"));
  ed = await retouchDialog("blur");
  cb = await ed.locator("canvas").boundingBox();
  for (let i = 0; i < 7; i++) {
    await ep.mouse.move(cb.x + cb.width * 0.1, cb.y + cb.height * 0.1);
    await ep.mouse.down();
    await ep.mouse.move(cb.x + cb.width * 0.9, cb.y + cb.height * 0.9);
    await ep.mouse.up();
  }
  await ed.getByRole("tab", { name: "Ausgabe", exact: true }).click();
  await ed.getByLabel("Breite in Pixel", { exact: true }).fill("4000");
  await ed.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await ep.waitForFunction(() =>
    document
      .querySelector(".photo-editor-error")
      ?.textContent.includes("Zu viele große Retuschen"),
  );
  await ed.evaluate((e) => e.photoEditor.render());
  check(
    await ed
      .locator(".photo-editor-error")
      .textContent()
      .then((text) => text.includes("Zu viele große Retuschen")),
    "large-export error remains visible when the actual preview rerenders",
  );
  check(
    (await ed
      .getByRole("button", { name: "Übernehmen", exact: true })
      .isEnabled()) &&
      (await ed.evaluate((e) => e.photoEditor.snapshot().marks.length === 7)) &&
      (await ep.evaluate(() => window.photoResult === undefined)),
    "bounded large retouch export keeps all edits available and makes no output or upload",
  );
  await ed.getByLabel("Breite in Pixel", { exact: true }).fill("240");
  await finishRetouch(ed);
  check(
    (await pixels(ep)).width === 240 &&
      (await ep.evaluate(
        async () =>
          [...new Uint8Array(await window.ownPhoto.arrayBuffer())].join(",") ===
          window.originalHash,
      )),
    "reducing an over-budget export recovers the actual image and preserves source bytes",
  );
  await ep.evaluate(async () => {
    const c = document.createElement("canvas");
    c.width = c.height = 64;
    const x = c.getContext("2d");
    x.fillStyle = "rgb(128 128 128)";
    x.fillRect(0, 0, 64, 64);
    x.fillStyle = "rgb(144 144 144)";
    x.fillRect(32, 32, 1, 1);
    const b = await new Promise((r) => c.toBlob(r, "image/png"));
    window.ownPhoto = new File([b], "Own-sharpness.png", { type: "image/png" });
  });
  ed = await start(ep);
  await ed.getByRole("tab", { name: "Licht & Farbe", exact: true }).click();
  await setRange(ed, "Schärfe", 100);
  await ed.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await ep.waitForFunction(() => window.photoResult !== undefined);
  const sharpened = await pixels(ep);
  check(
    sharpened.width === 64 &&
      sharpened.center[0] === 176 &&
      sharpened.center[1] === 176 &&
      sharpened.center[3] === 255,
    "sharpening produces the actual kernel result and preserves alpha without Canvas filter support",
  );
  await expanded.context.close();
  const newsletterPicker = await session(390, "?newsletter=1");
  const np = newsletterPicker.page;
  check(
    (await np.locator("[data-nl-image-input]").isHidden()) &&
      (await np
        .getByRole("button", {
          name: "Foto vor dem Hochladen bearbeiten",
          exact: true,
        })
        .isVisible()),
    "hidden newsletter photo picker has a separate visible optional editor action",
  );
  const newsletterChoice = np.waitForEvent("filechooser");
  await np
    .getByRole("button", {
      name: "Foto vor dem Hochladen bearbeiten",
      exact: true,
    })
    .click();
  await (
    await newsletterChoice
  ).setFiles([{ name: "Own-newsletter.HEIC", mimeType: "", buffer: heic }]);
  await np.waitForFunction(
    () => !!document.querySelector(".photo-editor")?.photoEditor,
  );
  await np.getByRole("button", { name: "Übernehmen", exact: true }).click();
  await np.waitForFunction(() => window.photoChanges.length === 1);
  check(
    await np
      .locator("[data-nl-image-input]")
      .evaluate(
        (e) => e.files.length === 1 && e.files[0].type === "image/jpeg",
      ),
    "optional HEIC editing returns exactly one compatible JPEG to the unchanged newsletter change listener",
  );
  await newsletterPicker.context.close();
  const ui = await session();
  const launcher = ui.page.getByRole("button", {
    name: "Foto vor dem Hochladen bearbeiten",
    exact: true,
  });
  const chooser = ui.page.waitForEvent("filechooser");
  await launcher.click();
  await (
    await chooser
  ).setFiles([{ name: "Own.HEIC", mimeType: "", buffer: heic }]);
  await ui.page.getByRole("dialog").waitFor();
  await ui.page
    .getByRole("button", { name: "Übernehmen", exact: true })
    .click();
  await ui.page.waitForFunction(() => window.photoChanges.length === 1);
  check(
    await ui.page
      .locator('[name="image[]"]')
      .evaluate(
        (e) =>
          e.files.length === 1 &&
          e.files[0].type === "image/jpeg" &&
          e.files[0].name.includes("-bearbeitet"),
      ),
    "optional native file chooser returns one compatible file to the existing upload path",
  );
  await ui.page.getByRole("button", { name: "Hochladen", exact: true }).click();
  await ui.page.waitForFunction(() => window.photoUpload);
  check(
    await ui.page.evaluate(
      () =>
        window.photoUpload.images.length === 1 &&
        window.photoUpload.images[0].width === 96 &&
        window.photoUpload.images[0].mime === "image/jpeg",
    ),
    "actual multipart receives the edited JPEG exactly once",
  );
  check(
    writes.length === 1,
    "all preview, cancellation and export checks made no upload; only explicit final submission did",
  );
  await ui.context.close();
  check(
    errors.length === 0,
    "no editor browser runtime errors: " + errors.join("; "),
  );
  check(external.length === 0, "editor makes no external requests");
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      own_pixels: true,
      external_service_calls: false,
    }),
  );
} finally {
  await browser?.close();
  server.kill("SIGTERM");
  await new Promise((resolve) => {
    if (server.exitCode !== null) resolve();
    else server.once("exit", resolve);
  });
}
