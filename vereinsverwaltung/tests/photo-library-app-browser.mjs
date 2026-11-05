import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const session = JSON.parse(
  await readFile(
    root + ".local/browser-tests/controls-sessions/admin.json",
    "utf8",
  ),
);
const heic = await readFile(
    root + "vereinsverwaltung/tests/fixtures/photo-import/own-camera.heic",
  ),
  raw = await readFile(
    root + "vereinsverwaltung/tests/fixtures/photo-import/own-camera.dng",
  );
let checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const field = (request, name) =>
  request
    .postData()
    ?.match(new RegExp('name="' + name + '"\\r?\\n\\r?\\n([^\\r\\n]*)'))?.[1];
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/photo-library-" + name + ".json",
        "utf8",
      ),
    );
    const bytes = await readFile(f.source),
      png = (suffix) => ({
        name: f.prefix + "-" + suffix,
        mimeType: "image/png",
        buffer: bytes,
      });
    check(
      createHash("sha256").update(bytes).digest("hex") === f.source_sha256,
      name + " selected source remains exact",
    );
    const url =
      origin +
      (standalone
        ? "/?view=media&section=library"
        : "/vereinsverwaltung/?view=website&tab=gallery&section=library");
    const context = await browser.newContext({
      storageState: standalone ? undefined : session,
      viewport: { width: 320, height: 880 },
      hasTouch: true,
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    let lose = false,
      failRead = false,
      hold = false,
      unknown = "",
      heldId = "",
      release;
    let gate = Promise.resolve();
    await context.route("**/*", async (route) => {
      const request = route.request();
      if (new URL(request.url()).origin !== origin) {
        external.push(request.url());
        return route.abort();
      }
      if (
        failRead &&
        request.method() === "GET" &&
        request.url().includes("upload_id=" + unknown)
      )
        return route.fulfill({
          status: 503,
          contentType: "application/json",
          body: JSON.stringify({ error: "Own temporary lookup failure" }),
        });
      if (
        request.method() === "POST" &&
        request.postData()?.includes("media-upload")
      ) {
        writes.push(request.url());
        const id = field(request, "upload_id");
        check(
          /^[a-f0-9]{32}$/.test(id || ""),
          name + " every actual JS upload has one exact proposed identity",
        );
        if (lose) {
          unknown = id;
          lose = false;
          const accepted = await context.request.post(request.url(), {
            headers: { Accept: "application/json", Origin: origin },
            multipart: {
              csrf: field(request, "csrf"),
              operation: "media-upload",
              upload_id: id,
              alt: field(request, "alt"),
              ...(!standalone ? { action: "native-module" } : {}),
              image: {
                name: f.prefix + "-lost.png",
                mimeType: "image/png",
                buffer: bytes,
              },
            },
          });
          const body = await accepted.json();
          check(
            accepted.ok() && body.ok === true && body.image.id === id,
            name +
              " lost acknowledgement follows a genuine accepted HTTP upload",
          );
          return route.fulfill({
            status: 502,
            contentType: "application/json",
            body: JSON.stringify({ error: "Own lost acknowledgement" }),
          });
        }
        if (hold) {
          heldId = id;
          await gate;
          try {
            await route.abort();
          } catch {
            /* The browser may have already cancelled its request. */
          }
          return;
        }
      }
      return route.continue();
    });
    await page.goto(url, { waitUntil: "domcontentloaded" });
    await page.waitForFunction(() => !!window.KulturhusPhotoLibrary);
    await page.locator(".media-upload > summary").click();
    const form = page.locator("[data-media-upload-form]"),
      input = form.locator("[data-media-upload]"),
      status = form.locator("[data-upload-progress]");
    await form.getByText("Bildbeschreibung ergänzen", { exact: true }).click();
    const alt = f.prefix + " own unsaved Ä caption";
    await form.locator('[name="alt"]').fill(alt);
    let before = writes.length;
    await input.setInputFiles(
      Array.from({ length: 7 }, (_, i) => png("batch-" + (i + 1) + ".png")),
    );
    await status.filter({ hasText: "7 Bilder gespeichert." }).waitFor();
    check(
      writes.length === before + 7,
      name + " seven selected files make exactly seven uploads",
    );
    check(
      (await page.url()) === url &&
        (await form.locator('[name="alt"]').inputValue()) === alt,
      name + " confirmed batch preserves the actual form and unsaved caption",
    );
    check(
      (await form
        .locator("[data-library-uploaded], [data-library-upload-results] a")
        .count()) === 6,
      name + " confirmed list shows at most six uploads",
    );
    const results = form.locator("[data-library-upload-results]"),
      pager = results.getByLabel("Seite gespeicherter Uploads", {
        exact: true,
      });
    const first = await results
      .locator("a")
      .first()
      .getAttribute("data-library-uploaded");
    await pager.selectOption("2");
    check(
      (await results.locator("a").count()) === 1 &&
        (await results.innerText()).includes("batch-7.png"),
      name + " direct second page reaches the seventh original identity",
    );
    await results.getByRole("button", { name: "Zurück", exact: true }).click();
    check(
      (await results.locator("a").count()) === 6,
      name + " bounded previous-page navigation works",
    );
    for (const control of [
      results.locator("a").first(),
      results.getByRole("button", { name: "Weiter", exact: true }),
      pager,
    ]) {
      const box = await control.boundingBox();
      check(
        box?.height >= 43.5 && box?.width >= 43.5,
        name + " real upload result controls meet touch size",
      );
    }
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      name + " actual batch and controls fit 320 pixels",
    );
    await page.setViewportSize({ width: 820, height: 880 });
    await page.evaluate(() => (document.documentElement.style.zoom = "2"));
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      name + " actual populated upload panel fits at 200 percent zoom",
    );
    await pager.selectOption("2");
    check(
      (await results.locator("a").count()) === 1,
      name + " actual result pagination remains usable at 200 percent zoom",
    );
    await page.evaluate(() => (document.documentElement.style.zoom = "1"));
    await page.setViewportSize({ width: 390, height: 880 });
    lose = true;
    before = writes.length;
    const started = page.waitForRequest(
      (r) => r.method() === "POST" && r.postData()?.includes("media-upload"),
    );
    await input.setInputFiles([png("lost.png"), png("not-started.png")]);
    await started;
    await status.filter({ hasText: "Upload nicht bestätigt" }).waitFor();
    check(
      writes.length === before + 1 &&
        (await form.locator("[data-library-upload-check]").isVisible()),
      name + " unknown acknowledgement stops the batch without replay",
    );
    check(
      !(await results
        .locator('[data-library-uploaded="' + unknown + '"]')
        .count()),
      name + " unconfirmed accepted photo is not adopted before lookup",
    );
    failRead = true;
    await form.locator("[data-library-upload-check]").click();
    await status.filter({ hasText: "Prüfung nicht möglich" }).waitFor();
    check(
      (await form.locator("[data-library-upload-check]").isEnabled()) &&
        writes.length === before + 1,
      name + " failed read retains explicit lookup without another upload",
    );
    failRead = false;
    const found = page.waitForResponse(
      (r) =>
        r.request().method() === "GET" &&
        r.url().includes("upload_id=" + unknown),
    );
    await form.locator("[data-library-upload-check]").click();
    const recovered = await found;
    check(
      recovered.ok() && (await recovered.json()).image.id === unknown,
      name + " explicit read finds the exact caller-owned accepted photo",
    );
    await status.filter({ hasText: "1 Bilder gefunden." }).waitFor();
    await pager.selectOption("2");
    const recoveredLink = results.locator(
      '[data-library-uploaded="' + unknown + '"]',
    );
    check(
      (await recoveredLink.count()) === 1 &&
        (await recoveredLink.getAttribute("href")).includes(unknown),
      name + " recovered image has one exact private opening link",
    );
    check(
      writes.length === before + 1 &&
        (await page.url()) === url &&
        (await form.locator('[name="alt"]').inputValue()) === alt,
      name +
        " read-only recovery preserves location and caption with zero replay",
    );
    check(
      await form.locator("[data-library-upload-check]").isHidden(),
      name + " confirmed identity leaves the pending list",
    );
    before = writes.length;
    const mixed = page.waitForRequest(
      (r) => r.method() === "POST" && r.postData()?.includes("media-upload"),
    );
    await input.setInputFiles([
      {
        name: f.prefix + "-bad.png",
        mimeType: "image/png",
        buffer: Buffer.from("own invalid PNG"),
      },
      { name: f.prefix + "-heic.heic", mimeType: "image/heic", buffer: heic },
      {
        name: f.prefix + "-raw.dng",
        mimeType: "image/x-adobe-dng",
        buffer: raw,
      },
    ]);
    await mixed;
    await status.filter({ hasText: "2 Bilder gespeichert." }).waitFor();
    check(
      writes.length === before + 3 &&
        (await status.innerText()).includes("1 fehlgeschlagen"),
      name + " known invalid photo allows the next actual HEIC and RAW uploads",
    );
    check(
      await form.locator("[data-library-upload-check]").isHidden(),
      name + " known rejection manufactures no pending save",
    );
    const sourceCSRF = await form.locator('[name="csrf"]').inputValue();
    await form
      .locator('[name="csrf"]')
      .evaluate((node) => (node.value = "own-invalid-csrf"));
    const csrfResponse = page.waitForResponse(
      (r) =>
        r.request().method() === "POST" &&
        r.request().postData()?.includes("media-upload"),
    );
    await input.setInputFiles([png("csrf.png")]);
    check(
      (await csrfResponse).status() === 422,
      name + " genuine multipart upload rejects bad CSRF",
    );
    await status.filter({ hasText: "0 Bilder gespeichert." }).waitFor();
    await form
      .locator('[name="csrf"]')
      .evaluate((node, value) => (node.value = value), sourceCSRF);
    check(
      (await form.locator("[data-library-upload-check]").isHidden()) &&
        (await input.isEnabled()),
      name +
        " known CSRF failure remains correctable without an uncertain identity",
    );
    before = writes.length;
    hold = true;
    gate = new Promise((resolve) => (release = resolve));
    const held = page.waitForRequest(
      (r) => r.method() === "POST" && r.postData()?.includes("media-upload"),
    );
    await input.setInputFiles([png("cancelled.png"), png("after-cancel.png")]);
    await held;
    await form
      .locator("[data-library-upload-cancel]")
      .waitFor({ state: "visible" });
    check(
      (await form.locator('[name="alt"]').isEnabled()) &&
        !(await page.locator("#workspace").evaluate((node) => node.inert)),
      name + " ongoing upload leaves the actual page and caption usable",
    );
    const box = await form
      .locator("[data-library-upload-cancel]")
      .boundingBox();
    check(
      box?.height >= 43.5 && box?.width >= 43.5,
      name + " actual upload cancellation is a touch action",
    );
    await form.locator("[data-library-upload-cancel]").click();
    await status.filter({ hasText: "Upload gestoppt" }).waitFor();
    hold = false;
    release();
    check(
      writes.length === before + 1 && (await input.isEnabled()),
      name + " abort stops the next selected file and restores selection",
    );
    const missing = page.waitForResponse(
      (r) =>
        r.request().method() === "GET" &&
        r.url().includes("upload_id=" + heldId),
    );
    await form.locator("[data-library-upload-check]").click();
    check(
      (await (await missing).json()).found === false,
      name + " actual lookup reports cancelled unreceived upload honestly",
    );
    await status.filter({ hasText: "noch unbestätigt" }).waitFor();
    check(
      (await form.locator("[data-library-upload-check]").isVisible()) &&
        writes.length === before + 1,
      name +
        " missing upload remains explicitly checkable with no automatic request",
    );
    await form.locator("[data-library-upload-forget]").click();
    await status.filter({ hasText: "Prüfung beendet." }).waitFor();
    check(
      (await form.locator("[data-library-upload-check]").isHidden()) &&
        (await form.locator('[name="alt"]').inputValue()) === alt,
      name +
        " ending local verification retains the caption and accepted files",
    );
    const duplicate = await context.request.post(url, {
      headers: { Accept: "application/json", Origin: origin },
      multipart: {
        csrf: sourceCSRF,
        operation: "media-upload",
        upload_id: first,
        ...(!standalone ? { action: "native-module" } : {}),
        image: {
          name: f.prefix + "-duplicate.png",
          mimeType: "image/png",
          buffer: bytes,
        },
      },
    });
    check(
      [409, 422].includes(duplicate.status()),
      name + " genuine duplicate identity cannot overwrite an accepted photo",
    );
    const malformed = await context.request.post(url, {
      headers: { Accept: "application/json", Origin: origin },
      multipart: {
        csrf: sourceCSRF,
        operation: "media-upload",
        upload_id: "../own",
        ...(!standalone ? { action: "native-module" } : {}),
        image: {
          name: f.prefix + "-malformed.png",
          mimeType: "image/png",
          buffer: bytes,
        },
      },
    });
    check(
      malformed.status() === 422,
      name + " actual malformed identity is rejected without storing a photo",
    );
    await context.unrouteAll({ behavior: "wait" });
    await context.close();
    const early = await browser.newContext({
      storageState: standalone ? undefined : session,
      viewport: { width: 390, height: 880 },
    });
    let releaseAsset,
      earlyWrites = 0;
    const assetGate = new Promise((resolve) => (releaseAsset = resolve));
    await early.route("**/*", async (route) => {
      const request = route.request();
      if (new URL(request.url()).origin !== origin) {
        external.push(request.url());
        return route.abort();
      }
      if (new URL(request.url()).pathname.endsWith("/photo-library.js")) {
        const response = await route.fetch();
        await assetGate;
        return route.fulfill({ response });
      }
      if (request.method() === "POST") {
        earlyWrites++;
        writes.push(request.url());
      }
      return route.continue();
    });
    const ep = await early.newPage();
    ep.on("pageerror", (e) => errors.push(e.message));
    await ep.goto(url, { waitUntil: "commit" });
    await ep.locator(".media-upload > summary").click();
    await ep.locator("[data-media-upload]").setInputFiles([png("late.png")]);
    await ep.waitForFunction(
      () => !document.querySelector("[data-media-upload]").disabled,
    );
    check(
      earlyWrites === 0,
      name + " early selection waits for the actual upload asset",
    );
    releaseAsset();
    await ep
      .locator("[data-upload-progress]")
      .filter({ hasText: "1 Bild gespeichert." })
      .waitFor();
    check(
      earlyWrites === 1 &&
        (await ep.locator("[data-library-upload-results] a").count()) === 1,
      name + " late asset consumes the existing selection exactly once",
    );
    check(
      (await ep.locator("[data-media-upload]").inputValue()) === "" &&
        (await ep.url()) === url,
      name +
        " late upload clears only the file selection without replacing the form",
    );
    await early.unrouteAll({ behavior: "wait" });
    await early.close();
    const plain = await browser.newContext({
      storageState: standalone ? undefined : session,
      javaScriptEnabled: false,
      viewport: { width: 390, height: 880 },
      hasTouch: true,
    });
    await plain.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : (external.push(route.request().url()), route.abort()),
    );
    const p = await plain.newPage();
    await p.goto(url, { waitUntil: "domcontentloaded" });
    await p.locator(".media-upload > summary").click();
    const plainForm = p.locator("[data-media-upload-form]");
    await plainForm
      .locator("[data-media-upload]")
      .setInputFiles([png("nojs-1.png"), png("nojs-2.png")]);
    const saved = p.waitForResponse((r) => r.request().method() === "POST");
    await plainForm
      .getByRole("button", { name: "Hochladen", exact: true })
      .click();
    const plainResponse = await saved;
    check(
      plainResponse.status() >= 200 && plainResponse.status() < 400,
      name + " native multi-file form still saves without JavaScript",
    );
    await p.waitForLoadState("domcontentloaded");
    check(
      (await p.locator("body").innerText()).includes("2 Bilder hochgeladen."),
      name + " actual no-JavaScript batch confirms both new files",
    );
    check(
      await p.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      name + " native fallback fits a phone",
    );
    await plain.close();
  }
  check(errors.length === 0, "no application JavaScript error");
  check(external.length === 0, "no external service or transport");
  console.log(
    JSON.stringify({
      checks,
      js_upload_attempts: writes.length,
      live_transport: false,
      original_preservation_requires_fixture_check: true,
    }),
  );
} finally {
  await browser.close();
}
