import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  fixtures = root + "vereinsverwaltung/tests/fixtures/photo-import/";
const heic = await readFile(fixtures + "own-camera.heic"),
  dng = await readFile(fixtures + "own-camera.dng");
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const errors = [],
  external = [],
  writes = [];
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
          root + ".local/browser-tests/photo-editor-" + name + ".json",
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
    p.on("pageerror", (e) => errors.push(e.message));
    const route = (view, id = "") =>
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? { view, ...(id ? { item: id } : { section: "library" }) }
          : {
              view: "website",
              tab: view === "media" ? "gallery" : "news",
              ...(id ? { native_item: id } : { section: "library" }),
            },
      );
    await p.goto(route("media"), { waitUntil: "domcontentloaded" });
    await p.locator(".media-upload > summary").click();
    const form = p.locator("[data-media-upload-form]");
    check(
      (await form
        .getByRole("button", {
          name: "Foto vor dem Hochladen bearbeiten",
          exact: true,
        })
        .count()) === 1,
      name + " real library has exactly one optional editor action",
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
    ).setFiles([
      { name: f.prefix + "library.HEIC", mimeType: "", buffer: heic },
    ]);
    await p.waitForFunction(
      () => !!document.querySelector(".photo-editor")?.photoEditor,
    );
    const dialog = p.getByRole("dialog");
    check(
      await dialog.boundingBox().then((b) => b.x >= 0 && b.y >= 0 && b.x + b.width <= 391 && b.y + b.height <= 851),
      name + " editor and preview fit the actual manager viewport",
    );
    await dialog
      .getByRole("button", { name: "Rechts drehen", exact: true })
      .click();
    await dialog
      .getByRole("button", { name: "Übernehmen", exact: true })
      .click();
    await p.waitForFunction(() =>
      /1 Bild(er)? (aufgenommen|gespeichert)/.test(document.body.textContent),
    );
    check(
      true,
      name + " real HEIC edit returns one rotated JPEG to automatic upload",
    );
    await p.goto(route("news", f.article), { waitUntil: "domcontentloaded" });
    const article = p
      .locator("form")
      .filter({ has: p.locator("[name=operation][value=article-upload]") });
    const pick = p.waitForEvent("filechooser");
    await article
      .getByRole("button", {
        name: "Foto vor dem Hochladen bearbeiten",
        exact: true,
      })
      .click();
    await (
      await pick
    ).setFiles([{ name: f.prefix + "article.DNG", mimeType: "", buffer: dng }]);
    await p.waitForFunction(
      () => !!document.querySelector(".photo-editor")?.photoEditor,
    );
    const raw = p.getByRole("dialog");
    await raw.getByRole("tab", { name: "Filter", exact: true }).click();
    await raw.getByLabel("Fotofilter", { exact: true }).selectOption("mono");
    await raw.getByRole("button", { name: "Übernehmen", exact: true }).click();
    await p.waitForFunction(
      () =>
        document
          .querySelector("[name=operation][value=article-upload]")
          .form.querySelector("[type=file]").files[0]?.type === "image/jpeg",
    );
    check(
      (await article.locator("[name=alt]").inputValue()) === "",
      name + " editing creates no mandatory description or invented rights",
    );
    await article
      .getByRole("button", { name: "Bild hochladen", exact: true })
      .click();
    await p.waitForFunction(
      () =>
        Number(
          document
            .querySelector("[name=operation][value=article-upload]")
            ?.form.querySelector("[name=version]")?.value,
        ) === 2,
    );
    check(
      true,
      name + " real RAW filter edit is stored as a new article photo",
    );
    check(
      await p.evaluate(
        () =>
          document.documentElement.scrollWidth <= innerWidth + 2 &&
          !document.querySelector(".photo-editor") &&
          !document.documentElement.classList.contains(
            "photo-editor-body-lock",
          ),
      ),
      name + " completed edit returns to the usable original module",
    );
    await context.close();
  }
  check(
    writes.length === 4,
    "exactly four own final uploads, no automatic extra write",
  );
  check(
    errors.length === 0,
    "no app editor runtime errors: " + errors.join("; "),
  );
  check(external.length === 0, "no external app editor requests");
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
