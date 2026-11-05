import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  fixtureRoot = root + "vereinsverwaltung/tests/fixtures/photo-import/";
const heic = await readFile(fixtureRoot + "own-camera.heic"),
  dng = await readFile(fixtureRoot + "own-camera.dng"),
  jxl = await readFile(fixtureRoot + "own-jxl-preview.dng");
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
          root + ".local/browser-tests/photo-import-" + name + ".json",
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
      viewport: { width: 320, height: 880 },
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
    p.setDefaultTimeout(30000);
    const route = (view, id = "", section = "") =>
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? {
              view,
              ...(id ? { item: id } : {}),
              ...(section ? { section } : {}),
            }
          : {
              view: ["media", "news"].includes(view) ? "website" : view,
              ...(view === "media"
                ? { tab: "gallery" }
                : view === "news"
                  ? { tab: "news" }
                  : {}),
              ...(id ? { native_item: id } : {}),
              ...(section ? { section } : {}),
            },
      );
    if (!process.env.PHOTO_IMPORT_MODULES_ONLY) {
      await p.goto(route("media", "", "library"), {
        waitUntil: "domcontentloaded",
      });
      await p.waitForFunction(() => !!window.KulturhusPhotos);
      await p.locator(".media-upload > summary").click();
      let input = p.locator("[data-media-upload]");
      await input.setInputFiles([
        { name: f.prefix + "library-a.HEIC", mimeType: "", buffer: heic },
        {
          name: f.prefix + "library-bad.DNG",
          mimeType: "",
          buffer: Buffer.from("own broken RAW"),
        },
        {
          name: f.prefix + "library-b.DNG",
          mimeType: "image/x-adobe-dng",
          buffer: dng,
        },
        { name: f.prefix + "library-jxl.DNG", mimeType: "", buffer: jxl },
      ]);
      await p.waitForFunction(() =>
        /3 (Bilder hochgeladen|Bilder gespeichert|Bilder aufgenommen)/.test(
          document.body.textContent,
        ),
      );
      check(
        await p
          .locator("#photo-import-message")
          .innerText()
          .then((t) => t.includes("library-bad.DNG")),
        name +
          " actual mixed library upload preserves its visible conversion error",
      );
      check(
        await p.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        name + " actual mixed upload fits 320 px",
      );
      await p.goto(route("media", "", "library"), {
        waitUntil: "domcontentloaded",
      });
      await p.locator(".media-upload > summary").click();
      await p
        .locator("[data-media-upload]")
        .setInputFiles([
          {
            name: f.prefix + "mislabeled.jpg",
            mimeType: "image/jpeg",
            buffer: heic,
          },
        ]);
      await p.waitForFunction(() =>
        /1 Bild(er)? (hochgeladen|gespeichert|aufgenommen)/.test(
          document.body.textContent,
        ),
      );
      check(
        true,
        name + " actual single upload recognizes HEIC even under a JPEG label",
      );
      await p.goto(route("media", f.album), { waitUntil: "domcontentloaded" });
      input = p.locator("[data-album-upload]");
      await input.setInputFiles([
        { name: f.prefix + "album-a.heic", mimeType: "", buffer: heic },
        { name: f.prefix + "album-b.dng", mimeType: "", buffer: dng },
      ]);
      await p.waitForFunction(() =>
        /2 (Bilder hochgeladen|Bilder gespeichert|Bilder aufgenommen)/.test(
          document.body.textContent,
        ),
      );
      check(
        true,
        name + " actual album sequentially saves both camera formats",
      );
      await p.goto(route("media", f.album), { waitUntil: "domcontentloaded" });
      await p.locator("[data-album-drop]").evaluate(
        (target, { bytes, name }) => {
          const transfer = new DataTransfer();
          transfer.items.add(new File([new Uint8Array(bytes)], name));
          target.dispatchEvent(
            new DragEvent("drop", { bubbles: true, dataTransfer: transfer }),
          );
        },
        { bytes: [...heic], name: f.prefix + "album-drop.heic" },
      );
      await p.waitForFunction(() =>
        /1 Bild(er)? (hochgeladen|gespeichert|aufgenommen)/.test(
          document.body.textContent,
        ),
      );
      check(
        true,
        name + " drag and drop passes through the same real camera conversion",
      );
      await p.goto(route("news", f.article), { waitUntil: "domcontentloaded" });
      const form = p
        .locator("form")
        .filter({ has: p.locator("[name=operation][value=article-upload]") });
      check(
        (await form.locator("[name=alt]").getAttribute("required")) === null,
        name + " article photo needs no mandatory metadata step",
      );
      await form
        .locator("[name=image]")
        .setInputFiles([
          { name: f.prefix + "article.dng", mimeType: "", buffer: dng },
        ]);
      await p.waitForFunction(
        () =>
          document
            .querySelector("input[name=operation][value=article-upload]")
            ?.form?.querySelector("input[type=file]")?.files[0]?.type ===
          "image/jpeg",
      );
      await form
        .getByRole("button", { name: "Bild hochladen", exact: true })
        .click();
      await p.waitForFunction(
        () =>
          Number(
            document
              .querySelector("[name=operation][value=article-upload]")
              .form.querySelector("[name=version]").value,
          ) === 2,
      );
      check(
        true,
        name +
          " article receives and stores actual RAW conversion without supplied description",
      );
      await p.goto(route("social", f.social), {
        waitUntil: "domcontentloaded",
      });
      const photo = p
        .locator("form")
        .filter({
          has: p.locator("[name=operation][value=social-photo-upload]"),
        });
      await photo.locator("summary").count();
      await photo
        .locator("[name=photo]")
        .setInputFiles([
          { name: f.prefix + "social.heic", mimeType: "", buffer: heic },
        ]);
      await p.waitForFunction(
        () =>
          document.querySelector("[name=photo]").files[0]?.type ===
          "image/jpeg",
      );
      check(
        (await photo.locator("[name=alt]").getAttribute("required")) === null,
        name +
          " social photo selection uses the shared local conversion and optional description",
      );
    }
    if (!standalone) {
      for (const [url, field] of [
        ["?view=settings&tab=paper", "logo"],
        ["?view=documents&new_document=1", "document_files[]"],
        ["?view=finance&tab=receipts&new_entry=1&kind=expense", "receipt_file"],
      ]) {
        await p.goto(origin + "/vereinsverwaltung/" + url, {
          waitUntil: "domcontentloaded",
        });
        let target = p.locator('input[type=file][name="' + field + '"]');
        check(
          (await target.count()) === 1,
          "integrated " + field + " actual photo form is available",
        );
        await target.setInputFiles(
          field === "document_files[]"
            ? [
                {
                  name: f.prefix + "document-a.HEIC",
                  mimeType: "",
                  buffer: heic,
                },
                {
                  name: f.prefix + "document-b.DNG",
                  mimeType: "",
                  buffer: dng,
                },
              ]
            : [
                {
                  name: f.prefix + field.replace(/\W/g, "") + ".heic",
                  mimeType: "",
                  buffer: heic,
                },
              ],
        );
        await p.waitForFunction(
          (field) =>
            document.querySelector('input[type=file][name="' + field + '"]')
              ?.files[0]?.type === "image/jpeg",
          field,
        );
        check(
          true,
          "integrated " +
            field +
            " camera photo is converted in its actual module",
        );
        if (field === "document_files[]") {
          await p.waitForFunction(
            () =>
              document.querySelectorAll(
                '[name="live_uploads[document_files][]"]',
              ).length === 2,
          );
          await p
            .getByRole("button", {
              name: "Zuordnung prüfen und speichern",
              exact: true,
            })
            .click();
          await p
            .getByRole("dialog")
            .getByRole("button", {
              name: "Diese Zuordnungen für alle 2 Dokumente bestätigen",
              exact: true,
            })
            .click();
          await p.waitForFunction(
            () =>
              !document.querySelector(
                "[name=action][value=document-upload-many]",
              ),
          );
          check(
            true,
            "integrated real multi-document submission stores both converted camera photos",
          );
        }
      }
    }
    await context.close();
  }
  check(
    errors.length === 0,
    "no app browser runtime errors: " + errors.join("; "),
  );
  check(external.length === 0, "no external app requests");
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
