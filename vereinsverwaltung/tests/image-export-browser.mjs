import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { createServer } from "node:http";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);

const root = fileURLToPath(new URL("../native/Web/assets/", import.meta.url));
const markup = execFileSync(
  "php",
  [fileURLToPath(new URL("./image-export-fixture.php", import.meta.url))],
  { encoding: "utf8" },
);
const allowed = new Map([
  ["image-export.js", "image-export.js"],
  ["image-studio.js", "image-studio.js"],
  ["image-studio.css", "image-studio.css"],
  ["image-studio-media.js", "image-studio/media.js"],
  ["image-studio-clones.js", "image-studio/clones.js"],
  ["image-studio-clone-painter.js", "image-studio/clone-painter.js"],
  ["image-studio-renderer.js", "image-studio/renderer.js"],
  ["image-studio-controls.js", "image-studio/controls.js"],
]);
const server = createServer(async (req, res) => {
  const name = (req.url || "").slice(1);
  if (req.url === "/") {
    res.setHeader("Content-Type", "text/html; charset=utf-8");
    res.end(
      '<!doctype html><html lang="de"><meta charset="utf-8"><link rel="stylesheet" href="/image-studio.css">' +
        markup +
        '<script src="/image-export.js"></script><script src="/image-studio-clones.js"></script><script src="/image-studio-clone-painter.js"></script><script src="/image-studio-media.js"></script><script src="/image-studio-renderer.js"></script><script src="/image-studio-controls.js"></script><script src="/image-studio.js"></script></html>',
    );
  } else if (allowed.has(name)) {
    res.setHeader(
      "Content-Type",
      name.endsWith(".css") ? "text/css" : "text/javascript",
    );
    res.end(await readFile(root + allowed.get(name)));
  } else {
    res.writeHead(404);
    res.end();
  }
});
await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
const browser = await chromium.launch(
  process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
    ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }
    : {},
);
try {
  const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto("http://127.0.0.1:" + server.address().port + "/");
  await page
    .locator("[data-studio-status]")
    .filter({ hasText: "Bildstudio bereit" })
    .waitFor()
    .catch((error) => {
      throw Error(error.message + "; browser errors: " + errors.join("; "));
    });
  let layoutChecks = 0;
  for (const [width, height] of [
    [320, 844],
    [390, 844],
    [820, 1000],
    [568, 320],
  ]) {
    await page.setViewportSize({ width, height });
    const studio = page.locator("[data-canvas-editor]");
    const before = await studio.evaluate((e) =>
      JSON.stringify(e.studio.snapshot()),
    );
    await studio
      .getByRole("button", { name: "Großansicht", exact: true })
      .click();
    const dialog = page.getByRole("dialog", {
      name: "Bildstudio · Großansicht",
    });
    const boxes = await Promise.all(
      [
        dialog,
        dialog.locator("canvas"),
        dialog.locator(".studio-controls"),
        dialog.getByRole("button", {
          name: "Bildfassung speichern",
          exact: true,
        }),
      ].map((e) => e.boundingBox()),
    );
    assert(
      boxes.every(
        (b) =>
          b &&
          b.x >= -1 &&
          b.y >= -1 &&
          b.x + b.width <= width + 1 &&
          b.y + b.height <= height + 1,
      ),
      width +
        " studio dialog, actual canvas, inspector and save remain within viewport",
    );
    layoutChecks++;
    const visible = await dialog.locator("button:visible").all();
    assert(
      (await Promise.all(visible.map((b) => b.boundingBox()))).every(
        (b) => b.height >= 43.5 && b.width >= 43.5,
      ),
      width + " studio tools keep real 44 pixel touch heights",
    );
    layoutChecks++;
    assert.equal(
      await dialog.getByRole("tabpanel").filter({ visible: true }).count(),
      1,
    );
    layoutChecks++;
    await dialog.getByRole("tab", { name: "Hinzufügen", exact: true }).focus();
    await page.keyboard.press("ArrowRight");
    assert.equal(
      await dialog
        .getByRole("tab", { name: "Bearbeiten", exact: true })
        .getAttribute("aria-selected"),
      "true",
    );
    layoutChecks++;
    assert(
      await dialog
        .locator(".studio-property-group > summary")
        .first()
        .isVisible(),
    );
    assert.equal(
      await dialog
        .locator(".studio-property-group")
        .first()
        .evaluate((e) => e.open),
      false,
    );
    layoutChecks++;
    await page.keyboard.press("End");
    assert(await dialog.locator(".studio-export-controls").isVisible());
    layoutChecks++;
    await page.keyboard.press("Escape");
    assert.equal(await page.getByRole("dialog").count(), 0);
    assert(
      await studio
        .getByRole("button", { name: "Großansicht", exact: true })
        .evaluate((e) => e === document.activeElement),
    );
    layoutChecks++;
    assert.equal(
      await studio.evaluate((e) => JSON.stringify(e.studio.snapshot())),
      before,
    );
    layoutChecks++;
    assert(
      await page.evaluate(
        () => !document.documentElement.classList.contains("studio-modal-lock"),
      ),
    );
    layoutChecks++;
    await studio.getByRole("tab", { name: "Hinzufügen", exact: true }).click();
  }
  await page.setViewportSize({ width: 820, height: 1000 });
  await page.evaluate(() => (document.documentElement.style.zoom = "2"));
  await page.getByRole("button", { name: "Großansicht", exact: true }).click();
  const zoomedDialog = page.getByRole("dialog");
  const zoomed = await Promise.all(
    [
      zoomedDialog,
      zoomedDialog.locator("canvas"),
      zoomedDialog.locator(".studio-controls"),
      zoomedDialog.getByRole("button", {
        name: "Bildfassung speichern",
        exact: true,
      }),
    ].map((e) => e.boundingBox()),
  );
  assert(
    zoomed.every(
      (b) =>
        b.x >= -1 &&
        b.y >= -1 &&
        b.x + b.width <= 821 &&
        b.y + b.height <= 1001,
    ),
    "native studio, actual canvas, inspector and save remain within viewport at 200 percent zoom",
  );
  layoutChecks++;
  await page.keyboard.press("Escape");
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 844 });
  await page.locator(".studio-export summary").click();
  await page.locator("[data-export-width]").fill("360");
  assert.equal(await page.locator("[data-export-height]").inputValue(), "450");
  const original = await page
    .locator("[data-canvas-editor]")
    .evaluate((element) => element.studio.snapshot());
  for (const [name, mime] of [
    ["png", "image/png"],
    ["jpeg", "image/jpeg"],
    ["webp", "image/webp"],
  ]) {
    const download = page.waitForEvent("download");
    await page.locator('[data-studio="download-' + name + '"]').click();
    const file = await download;
    assert.match(
      file.suggestedFilename(),
      name === "jpeg" ? /360x450\.jpg$/ : new RegExp("360x450\\." + name + "$"),
    );
    assert.equal(await file.failure(), null);
    const bytes = await readFile(await file.path());
    const decoded = await page.evaluate(
      async ({ bytes, mime }) => {
        const blob = new Blob([new Uint8Array(bytes)], { type: mime });
        const image = await createImageBitmap(blob);
        const canvas = document.createElement("canvas");
        canvas.width = image.width;
        canvas.height = image.height;
        const context = canvas.getContext("2d");
        context.drawImage(image, 0, 0);
        const pixel = Array.from(context.getImageData(0, 0, 1, 1).data);
        image.close();
        return { width: canvas.width, height: canvas.height, pixel };
      },
      { bytes: Array.from(bytes), mime },
    );
    assert.equal(decoded.width, 360);
    assert.equal(decoded.height, 450);
    assert(
      decoded.pixel[3] === 255 && decoded.pixel[1] > decoded.pixel[0],
      "actual artwork pixels survive export",
    );
    assert.deepEqual(
      await page
        .locator("[data-canvas-editor]")
        .evaluate((element) => element.studio.snapshot()),
      original,
    );
  }
  await page.locator("[data-export-aspect]").uncheck();
  await page.locator("[data-export-height]").fill("240");
  const resized = page.waitForEvent("download");
  await page.locator('[data-studio="download-webp"]').click();
  assert.match((await resized).suggestedFilename(), /360x240\.webp$/);
  const guards = await page.evaluate(async () => {
    const api = window.KulturhusImageExport;
    let rejected = 0;
    for (const [width, height, mime] of [
      [0, 500, "image/png"],
      [8193, 32, "image/png"],
      [4096, 4096, "image/png"],
      [100, 100, "image/gif"],
      [100.5, 100, "image/png"],
    ]) {
      try {
        api.configuration(width, height, mime);
      } catch {
        ++rejected;
      }
    }
    const controller = new AbortController();
    controller.abort();
    try {
      await api.encode(
        document.querySelector("canvas"),
        { width: 360, height: 450, mime: "image/png" },
        controller.signal,
      );
    } catch (error) {
      if (error.name === "AbortError") ++rejected;
    }
    return rejected;
  });
  assert.equal(guards, 6);
  assert(
    await page
      .locator(".studio-export-controls")
      .evaluate(
        (element) => element.getBoundingClientRect().right <= window.innerWidth,
      ),
    "mobile export controls fit viewport",
  );
  await page.getByRole("button", { name: "Großansicht", exact: true }).click();
  await page.evaluate(() => KulturhusImageStudio.unmount());
  assert.equal(await page.getByRole("dialog").count(), 0);
  assert(
    await page.evaluate(
      () => !document.documentElement.classList.contains("studio-modal-lock"),
    ),
  );
  layoutChecks++;
  console.log(
    JSON.stringify({
      studio_layout_checks: layoutChecks,
      external_service_calls: false,
      view_changes_preserve_artwork: true,
    }),
  );
  assert.deepEqual(errors, []);
  console.log(
    "Image export browser checks passed: real PNG/JPEG/WebP bytes, dimensions, pixels, unchanged artwork, bounded input, abort and mobile controls.",
  );
} finally {
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
}
