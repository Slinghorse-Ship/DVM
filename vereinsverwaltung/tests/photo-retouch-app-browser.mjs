import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const writes = [],
  errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111),
      f = JSON.parse(
        await readFile(
          root + ".local/browser-tests/photo-retouch-" + name + ".json",
          "utf8",
        ),
      );
    const context = await browser.newContext({
      storageState: standalone
        ? undefined
        : JSON.parse(
            await readFile(
              root + ".local/browser-tests/controls-sessions/admin.json",
              "utf8",
            ),
          ),
      viewport: { width: 390, height: 850 },
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
    const p = await context.newPage();
    p.on("pageerror", (error) => errors.push(error.message));
    const url =
      origin +
      (standalone
        ? "/?view=media&section=library"
        : "/vereinsverwaltung/?view=website&tab=gallery&section=library");
    for (const [tool, width, zoom] of [
      ["blur", 320, 1],
      ["pixelate", 820, 2],
      ["redeye", 390, 1],
    ]) {
      await p.setViewportSize({ width, height: 850 });
      await p.goto(url, { waitUntil: "domcontentloaded" });
      await p.evaluate(
        (zoom) => (document.documentElement.style.zoom = String(zoom)),
        zoom,
      );
      await p.locator(".media-upload > summary").click();
      const form = p.locator("[data-media-upload-form]"),
        before = writes.length;
      const source = f.sources[tool],
        bytes = await readFile(source.path);
      check(
        createHash("sha256").update(bytes).digest("hex") === source.sha256,
        name + " selected " + tool + " source bytes remain original",
      );
      const chooser = p.waitForEvent("filechooser");
      await form
        .getByRole("button", {
          name: "Foto vor dem Hochladen bearbeiten",
          exact: true,
        })
        .click();
      await (
        await chooser
      ).setFiles([{ name: source.name, mimeType: "image/png", buffer: bytes }]);
      await p.waitForFunction(
        () => !!document.querySelector(".photo-editor")?.photoEditor,
      );
      const dialog = p.getByRole("dialog");
      await dialog.getByRole("tab", { name: "Markieren", exact: true }).click();
      check(
        (await dialog.getByLabel("Werkzeug", { exact: true }).count()) === 1 &&
          (await dialog.locator("[data-select-search-input]").count()) === 0,
        name + " local editor keeps one actual native tool selector",
      );
      await dialog.getByLabel("Werkzeug", { exact: true }).selectOption(tool);
      for (const label of ["Stärke", "Retuscheintensität"]) {
        await dialog.getByLabel(label, { exact: true }).focus();
        await dialog.getByLabel(label, { exact: true }).press("End");
      }
      check(
        (await dialog.getByLabel("Farbe", { exact: true }).isHidden()) &&
          (await dialog.getByLabel("Eigener Text", { exact: true }).isHidden()),
        name + " " + tool + " has compact relevant tools",
      );
      const bounds = await Promise.all(
        [
          dialog.locator("canvas"),
          dialog.getByRole("button", { name: "Übernehmen", exact: true }),
        ].map((e) => e.boundingBox()),
      );
      check(
        bounds.every(
          (b) =>
            b.x >= -1 &&
            b.y >= -1 &&
            b.x + b.width <= width + 1 &&
            b.y + b.height <= 851,
        ),
        name +
          " " +
          tool +
          " preview and apply fit actual mobile and zoom bounds",
      );
      if (tool === "blur") {
        const box = await dialog.locator("canvas").boundingBox();
        await p.touchscreen.tap(
          box.x + box.width * 0.5,
          box.y + box.height * 0.5,
        );
      } else {
        await dialog.locator("canvas").focus();
        if (tool === "redeye")
          for (let i = 0; i < 25; i++) await p.keyboard.press("ArrowLeft");
        await p.keyboard.press("Enter");
      }
      check(
        writes.length === before,
        name + " " + tool + " preview makes no upload or publication",
      );
      const uploaded = p.waitForResponse(
        (response) =>
          response.request().method() === "POST" &&
          response.request().postData()?.includes("media-upload"),
      );
      await dialog
        .getByRole("button", { name: "Übernehmen", exact: true })
        .click();
      const response = await uploaded;
      const result = await response.json();
      check(
        response.ok() &&
          result.ok === true &&
          /^[a-f0-9]{32}$/.test(result.image?.id || "") &&
          typeof result.image.edit_url === "string",
        name + " " + tool + " stores one confirmed actual multipart photo",
      );
      await p.waitForFunction(
        () =>
          !document.querySelector(".photo-editor") &&
          /1 Bild (aufgenommen|gespeichert)/.test(document.body.textContent),
      );
      check(
        writes.length === before + 1,
        name + " " + tool + " performs exactly one explicit final upload",
      );
      check(
        createHash("sha256")
          .update(await readFile(source.path))
          .digest("hex") === source.sha256,
        name + " " + tool + " preserves selected source bytes after storage",
      );
    }
    await context.close();
  }
  check(
    writes.length === 6,
    "exactly six final own retouch uploads in both actual applications",
  );
  check(
    errors.length === 0,
    "no app retouch runtime errors: " + errors.join("; "),
  );
  check(external.length === 0, "no external retouch app requests");
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      external_service_calls: false,
    }),
  );
} finally {
  await browser.close();
}
