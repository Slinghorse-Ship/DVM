import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const heic = await readFile(
  root + "vereinsverwaltung/tests/fixtures/photo-import/own-camera.heic",
);
const raw = await readFile(
  root + "vereinsverwaltung/tests/fixtures/photo-import/own-camera.dng",
);
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const isPhoto = (r) =>
  r.method() === "POST" &&
  r.postDataBuffer()?.includes(Buffer.from("page-photo-upload"));
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const fixturePath =
      root + ".local/browser-tests/page-photo-upload-" + name + ".json";
    const f = JSON.parse(await readFile(fixturePath, "utf8"));
    const report = {};
    const storageState = standalone
      ? undefined
      : JSON.parse(
          await readFile(
            root + ".local/browser-tests/controls-sessions/admin.json",
            "utf8",
          ),
        );
    for (const [width, height, javaScriptEnabled] of [
      [320, 880, true],
      [390, 880, true],
      [820, 880, true],
      [844, 390, true],
      [390, 880, false],
    ]) {
      const key = width + "-" + (javaScriptEnabled ? "js" : "nojs");
      const c = await browser.newContext({
        storageState,
        viewport: { width, height },
        hasTouch: true,
        javaScriptEnabled,
      });
      await c.route("**/*", (r) =>
        new URL(r.request().url()).origin === origin
          ? r.continue()
          : (external.push(r.request().url()), r.abort()),
      );
      const p = await c.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      let photoPosts = 0,
        savePosts = 0;
      p.on("request", (r) => {
        if (isPhoto(r)) photoPosts++;
        else if (r.method() === "POST") savePosts++;
      });
      await p.goto(
        origin +
          (standalone
            ? "/?view=website&item="
            : "/vereinsverwaltung/?view=website&tab=pages&native_item=") +
          f.pages[key].id,
        { waitUntil: "domcontentloaded", timeout: 60000 },
      );
      const form = p.locator("form").filter({
        has: p.locator('input[name="operation"][value="page-save"]'),
      });
      const picker = form.locator("[data-page-media-picker]"),
        file = picker.locator("[data-page-media-upload]");
      const selected = () =>
        picker
          .locator("[data-page-media-select]")
          .evaluate((s) => [...s.selectedOptions].map((o) => o.value));
      const status = picker.locator("[data-page-media-status]");
      await form.locator('[name="title"]').fill(f.prefix + " edited " + key);
      await form.locator('[name="excerpt"]').fill("Own unsaved input " + key);
      await form.locator("details.website-documents summary").click();
      await form.locator('[name="website_pdfs[]"]').setInputFiles(f.files);
      await p.getByText("Navigation, SEO und Bilder", { exact: true }).click();
      check(
        JSON.stringify(await selected()) === JSON.stringify(f.selected),
        name + " exact original picture order " + key,
      );
      const unconfirmed = [];
      const waitUpload = async () => {
        await p.waitForFunction(
          () => {
            const b = document.querySelector("[data-page-media-upload-cancel]");
            return (
              b?.hidden &&
              document
                .querySelector("[data-page-media-status]")
                ?.textContent.includes("Fotos hinzugefügt")
            );
          },
          null,
          { timeout: 90000 },
        );
      };
      if (javaScriptEnabled) {
        check(
          (await file.getAttribute("accept")).includes(".heic") &&
            (await file.getAttribute("accept")).includes(".dng"),
          name + " camera formats recognized in actual page upload " + key,
        );
        const startUpload = async (files) => {
          const began = p.waitForRequest(isPhoto, { timeout: 90000 });
          await file.setInputFiles(files);
          await began;
          await waitUpload();
        };
        if (width === 320) {
          await startUpload([f.png, f.png]);
        }
        if (width === 390) {
          const png = await readFile(f.png),
            bad = await readFile(f.bad_image);
          await startUpload([
            {
              name: f.prefix + "-new-png.png",
              mimeType: "image/png",
              buffer: png,
            },
            {
              name: f.prefix + "-new-invalid.png",
              mimeType: "image/png",
              buffer: bad,
            },
            {
              name: f.prefix + "-new-HEIC.heic",
              mimeType: "image/heic",
              buffer: heic,
            },
            {
              name: f.prefix + "-new-RAW.DNG",
              mimeType: "image/x-adobe-dng",
              buffer: raw,
            },
          ]);
          await waitUpload();
          check(
            (await status.textContent()).includes("1 fehlgeschlagen"),
            name +
              " mixed real PNG HEIC RAW upload retains good images and reports bad source",
          );
        }
        if (width === 820) {
          await startUpload(f.png);
          const lose = async (route) => {
            if (!isPhoto(route.request())) return route.fallback();
            const fields = await form.evaluate((f) =>
              Object.fromEntries(
                ["csrf", "action", "id", "version"].map((key) => [
                  key,
                  f.elements.namedItem(key)?.value || "",
                ]),
              ),
            );
            if (!fields.action) delete fields.action;
            const uploadId = route
              .request()
              .postData()
              ?.match(/name="upload_id"\r?\n\r?\n([a-f0-9]{32})/)?.[1];
            check(
              !!uploadId,
              name +
                " lost acknowledgement retains the exact preassigned client upload identity",
            );
            // Chromium omits file bytes from intercepted multipart bodies; send the exact owned fixture bytes through real HTTP.
            const actual = await c.request.post(route.request().url(), {
              headers: { Accept: "application/json", Origin: origin },
              multipart: {
                ...fields,
                operation: "page-photo-upload",
                upload_id: uploadId,
                photo: {
                  name: f.png.split("/").at(-1),
                  mimeType: "image/png",
                  buffer: await readFile(f.png),
                },
              },
            });
            check(
              actual.status() === 200,
              name + " unconfirmed test uses actual accepted upload",
            );
            const data = await actual.json();
            check(
              data.image.id === uploadId,
              name +
                " actual accepted upload keeps the original proposed identity",
            );
            unconfirmed.push(data.image.id);
            await route.fulfill({
              status: 502,
              contentType: "application/json",
              body: JSON.stringify({
                error: "Own lost acknowledgement fixture",
              }),
            });
          };
          await p.route("**/*", lose);
          await startUpload(f.png);
          await p.unrouteAll({ behavior: "wait" });
          check(
            (await status.textContent()).includes("Ergebnis unklar") &&
              !(await selected()).includes(unconfirmed[0]),
            name + " lost acknowledgement never silently adds or retries image",
          );
          const beforeCheck = photoPosts,
            beforeSelection = await selected();
          const checked = p.waitForResponse(
            (response) =>
              response.request().method() === "GET" &&
              response.url().includes("upload_id=" + unconfirmed[0]),
          );
          await picker.locator("[data-page-photo-check]").click();
          const checkedResponse = await checked,
            checkedData = await checkedResponse.json();
          await p.waitForFunction(() =>
            document
              .querySelector("[data-page-media-status]")
              ?.textContent.includes("Fotos gefunden"),
          );
          check(
            checkedResponse.ok() &&
              checkedData.found === true &&
              checkedData.image.id === unconfirmed[0],
            name +
              " read-only lookup confirms the exact actual upload after acknowledgement loss",
          );
          check(
            photoPosts === beforeCheck &&
              savePosts === 0 &&
              JSON.stringify(await selected()) ===
                JSON.stringify(beforeSelection),
            name +
              " lookup never retries, autosaves or silently selects the recovered photo",
          );
          check(
            (await picker
              .locator(
                '[data-page-media-select] option[value="' +
                  unconfirmed[0] +
                  '"]',
              )
              .count()) === 1,
            name +
              " confirmed unknown upload appears exactly once in the unchanged local catalog",
          );
          const add = picker.locator(
            '[data-page-media-id="' +
              unconfirmed[0] +
              '"] [data-page-media-add]',
          );
          await add.click();
          check(
            (await selected()).at(-1) === unconfirmed[0],
            name + " user can explicitly add the exact recovered upload",
          );
          await picker.locator("[data-page-media-undo]").click();
          check(
            JSON.stringify(await selected()) ===
              JSON.stringify(beforeSelection),
            name +
              " recovered selection undo preserves the original confirmed ordering",
          );
          const statusUrl = new URL(
            await picker.getAttribute("data-page-photo-status"),
            origin,
          );
          statusUrl.searchParams.set("upload_id", unconfirmed[0]);
          const head = await c.request.head(statusUrl.href),
            forbidden = await c.request.post(statusUrl.href);
          check(
            head.status() === 200 &&
              (await head.body()).length === 0 &&
              /no-store/.test(head.headers()["cache-control"] || ""),
            name +
              " actual HEAD lookup has no body and never caches private status",
          );
          check(
            forbidden.status() === 405 &&
              /GET.*HEAD/.test(forbidden.headers().allow || ""),
            name + " actual status endpoint rejects upload replay methods",
          );
          check(
            photoPosts === beforeCheck &&
              savePosts === 0 &&
              (await picker.locator("[data-page-photo-check]").isHidden()),
            name +
              " successful check closes its pending entry without any extra browser write",
          );
          await picker.locator('[data-page-media-tab="selected"]').click();
        }
        if (width === 844) {
          let release, ready;
          const arrived = new Promise((resolve) => (ready = resolve)),
            settled = new Promise((resolve) => (release = resolve));
          let finish;
          const gate = new Promise((resolve) => (finish = resolve));
          const hold = async (route) => {
            if (!isPhoto(route.request())) return route.fallback();
            ready();
            await gate;
            try {
              await route.abort("aborted");
            } catch {}
            release();
          };
          await p.route("**/*", hold);
          await file.setInputFiles(f.png);
          await arrived;
          check(
            await form
              .getByRole("button", { name: "Entwurf speichern", exact: true })
              .isDisabled(),
            name + " pending upload cannot prematurely save the form",
          );
          await picker.locator("[data-page-media-upload-cancel]").click();
          finish();
          await settled;
          await waitUpload();
          await p.unrouteAll({ behavior: "wait" });
          check(
            JSON.stringify(await selected()) === JSON.stringify(f.selected),
            name + " cancelled upload cannot replace original selections",
          );
          const beforeCheck = photoPosts;
          await picker.locator("[data-page-photo-check]").click();
          await p.waitForFunction(() =>
            document
              .querySelector("[data-page-media-status]")
              ?.textContent.includes("noch unbestätigt"),
          );
          check(
            photoPosts === beforeCheck &&
              (await picker.locator("[data-page-photo-check]").isVisible()) &&
              JSON.stringify(await selected()) === JSON.stringify(f.selected),
            name +
              " uncommitted canceled upload stays pending without replay or false absence confirmation",
          );
          await picker.locator("[data-page-photo-forget]").click();
          check(
            (await picker.locator("[data-page-photo-check]").isHidden()) &&
              photoPosts === beforeCheck,
            name + " ending a pending check changes no stored file or upload",
          );
          await startUpload(f.png);
        }
        const wanted = width === 320 ? 2 : width === 390 ? 3 : 1;
        check(
          (await selected()).length === 3 + wanted,
          name +
            " actual direct uploads append exactly confirmed new IDs " +
            key,
        );
        check(
          (await form.locator('[name="version"]').inputValue()) === "2" &&
            savePosts === 0,
          name + " direct upload never autosaves or publishes page " + key,
        );
        check(
          (await form.locator('[name="title"]').inputValue()) ===
            f.prefix + " edited " + key &&
            (await form.locator('[name="excerpt"]').inputValue()) ===
              "Own unsaved input " + key &&
            (await form
              .locator('[name="website_pdfs[]"]')
              .evaluate((i) => i.files.length)) === 2,
          name + " direct photos preserve unsaved texts and PDF batch " + key,
        );
        check(
          (await file.evaluate((i) => i.files.length)) === 0,
          name +
            " completed upload cannot duplicate photos on page save " +
            key,
        );
        check(
          (await picker.locator("[data-page-media-id]").count()) <= 4,
          name + " newly appended photos keep compact selected list " + key,
        );
        check(
          await p.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          name + " actual photo upload fits phone tablet landscape " + key,
        );
        await p.evaluate(() => (document.documentElement.style.zoom = "2"));
        check(
          await p.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          name + " populated photo upload fits 200 percent zoom " + key,
        );
        await p.evaluate(() => (document.documentElement.style.zoom = ""));
      } else {
        await file.setInputFiles([f.png, f.png]);
        await form.locator('[name="website_pdfs[]"]').setInputFiles(f.bad_pdf);
        await Promise.all([
          p.waitForNavigation({
            waitUntil: "domcontentloaded",
            timeout: 60000,
          }),
          form
            .getByRole("button", { name: "Entwurf speichern", exact: true })
            .click(),
        ]);
        check(
          (await form.locator('[name="version"]').inputValue()) === "2" &&
            JSON.stringify(await selected()) === JSON.stringify(f.selected) &&
            (await form.locator('[name="title"]').inputValue()) ===
              f.prefix + " edited " + key,
          name +
            " JavaScript-free failed photo PDF batch retains original page and text",
        );
        await form.locator("details.website-documents summary").click();
        await form.locator('[name="website_pdfs[]"]').setInputFiles(f.files);
        await p
          .getByText("Navigation, SEO und Bilder", { exact: true })
          .click();
        await file.setInputFiles([f.png, f.png]);
      }
      const newIds = javaScriptEnabled ? (await selected()).slice(3) : null;
      if (javaScriptEnabled) {
        const response = p.waitForResponse(
          (r) =>
            r.request().method() === "POST" &&
            !isPhoto(r.request()) &&
            r.url().startsWith(origin),
        );
        await form
          .getByRole("button", { name: "Entwurf speichern", exact: true })
          .click();
        const result = await response;
        check(
          result.status() >= 200 && result.status() < 400,
          name + " actual combined original photo PDF save " + key,
        );
        await p.waitForFunction(
          () =>
            document
              .querySelector('input[name="operation"][value="page-save"]')
              ?.form.querySelector('[name="version"]').value === "3",
          null,
          { timeout: 60000 },
        );
      } else {
        await Promise.all([
          p.waitForNavigation({
            waitUntil: "domcontentloaded",
            timeout: 60000,
          }),
          form
            .getByRole("button", { name: "Entwurf speichern", exact: true })
            .click(),
        ]);
        check(
          (await form.locator('[name="version"]').inputValue()) === "3",
          name + " actual JavaScript-free photos PDFs and page save together",
        );
      }
      report[key] = {
        selected: newIds || (await selected()).slice(3),
        unconfirmed,
      };
      check(
        savePosts === (javaScriptEnabled ? 1 : 2),
        name + " exactly one final save and no duplicate submission " + key,
      );
      check(
        photoPosts === (javaScriptEnabled ? (width === 390 ? 4 : 2) : 0),
        name +
          " exact actual photo request count without automatic retry " +
          key,
      );
      check(
        JSON.stringify((await selected()).slice(0, 3)) ===
          JSON.stringify(f.selected),
        name + " original selected IDs remain ordered after real save " + key,
      );
      await c.close();
    }
    await writeFile(
      fixturePath.replace(".json", "-results.json"),
      JSON.stringify(report),
      { mode: 0o600 },
    );
  }
  check(
    errors.length === 0,
    "both photo upload shells have no uncaught errors",
  );
  check(
    external.length === 0,
    "both photo upload shells issue no external requests",
  );
  console.log(
    JSON.stringify({
      suite: "page-photo-upload-app-browser",
      checks,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
