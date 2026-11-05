import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";

const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const session = JSON.parse(
  await readFile(
    root + ".local/browser-tests/controls-sessions/admin.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
let checks = 0;
const external = [],
  errors = [],
  writes = [],
  reads = [];
const check = (condition, label) => {
  assert(condition, label);
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
        root + ".local/browser-tests/album-upload-" + name + "-compact.json",
        "utf8",
      ),
    );
    const bytes = await Promise.all(
      ["a", "b"].map((key) => readFile(f.sources[key].path)),
    );
    for (const [i, key] of ["a", "b"].entries())
      check(
        createHash("sha256").update(bytes[i]).digest("hex") ===
          f.sources[key].sha256,
        name + " exact selected source bytes",
      );
    const png = (suffix, i = 0) => ({
      name: f.prefix + "-" + suffix + ".png",
      mimeType: "image/png",
      buffer: bytes[i],
    });
    const url =
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? { view: "media", item: f.album }
          : { view: "website", tab: "gallery", native_item: f.album },
      );
    let lose = "",
      held = false,
      failRead = false,
      pending = "",
      capturedVersion = 0,
      release;
    let gate = Promise.resolve();
    const makeContext = async (late = false) => {
      let releaseAsset;
      const assetGate = late
        ? new Promise((resolve) => {
            releaseAsset = resolve;
          })
        : Promise.resolve();
      const context = await browser.newContext({
        storageState: standalone ? undefined : session,
        viewport: { width: 320, height: 880 },
        hasTouch: true,
      });
      await context.route("**/*", async (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (
          late &&
          request.method() === "GET" &&
          new URL(request.url()).pathname.endsWith("photo-library.js")
        )
          await assetGate;
        if (
          request.method() === "GET" &&
          request.url().includes("album-photo-status")
        ) {
          reads.push(request.url());
          if (failRead)
            return route.fulfill({
              status: 503,
              contentType: "application/json",
              body: '{"error":"Own temporary lookup failure"}',
            });
        }
        if (request.method() === "POST") {
          assert(
            request.postData()?.includes("album-upload"),
            "Only deliberate album photo uploads may run from this page",
          );
          writes.push(request.url());
          const id = field(request, "upload_id");
          check(
            /^[a-f0-9]{32}$/.test(id || ""),
            name + " every real upload has an exact proposed identity",
          );
          if (lose) {
            pending = id;
            capturedVersion = Number(field(request, "version"));
            const stale = lose === "stale";
            lose = "";
            const accepted = await context.request.post(request.url(), {
              multipart: {
                csrf: field(request, "csrf"),
                operation: "album-upload",
                id: f.album,
                version: String(capturedVersion),
                upload_id: id,
                ...(!standalone ? { action: "native-module" } : {}),
                image: {
                  ...png(stale ? "lost-stale" : "lost-current", stale ? 1 : 0),
                },
              },
              headers: { Accept: "application/json" },
              maxRedirects: 0,
            });
            const result = await accepted.json();
            check(
              accepted.status() === 200 &&
                result.ok === true &&
                result.image.id === id,
              name +
                " lost acknowledgement follows a genuine accepted multipart upload",
            );
            assert.deepEqual(result.image.album, {
              id: f.album,
              version: capturedVersion + 1,
            });
            checks++;
            if (stale) {
              const edit = await context.request.post(url, {
                form: {
                  csrf: field(request, "csrf"),
                  operation: "album-save",
                  id: f.album,
                  version: String(capturedVersion + 1),
                  ...(!standalone ? { action: "native-module" } : {}),
                  title: f.prefix + " independent edit Ä",
                  slug: f.slug.replace(/^galerie\//, ""),
                  body: "Own independent text Ä",
                },
                maxRedirects: 0,
              });
              check(
                edit.status() === 303,
                name +
                  " genuinely saved independent edit follows the accepted photo",
              );
            }
            return route.fulfill({
              status: 502,
              contentType: "application/json",
              body: '{"error":"Own lost acknowledgement"}',
            });
          }
          if (held) {
            pending = id;
            await gate;
            try {
              await route.abort();
            } catch {
              /* Browser already cancelled. */
            }
            return;
          }
        }
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (error) => errors.push(error.message));
      const navigation = page.goto(url, { waitUntil: "domcontentloaded" });
      if (late) {
        const earlyInput = page.locator("[data-album-upload]");
        await earlyInput.waitFor({ state: "attached" });
        check(
          !(await page.evaluate(() => !!window.KulturhusPhotoLibrary)),
          name + " early selection precedes the actual upload asset",
        );
        await earlyInput.setInputFiles([png("late")]);
        releaseAsset();
      }
      await navigation;
      await page.waitForFunction(
        () =>
          !!window.KulturhusPhotoLibrary &&
          document.querySelector("[data-richtext-surface]")?.contentEditable ===
            "true",
      );
      return { context, page };
    };
    let { context, page } = await makeContext();
    const form = page.locator("[data-album-upload-form]"),
      input = form.locator("[data-album-upload]"),
      status = form.locator("[data-upload-progress]"),
      results = form.locator("[data-library-upload-results]");
    const albumForm = page.locator(
      'form:has([name="operation"][value="album-save"])',
    );
    await page.getByText("Albumangaben bearbeiten", { exact: true }).click();
    await albumForm
      .locator('[name="title"]')
      .fill(f.prefix + " unsaved title Ä");
    await albumForm.locator('[name="excerpt"]').fill("Own unsaved excerpt Ä");
    await albumForm
      .locator("[data-richtext-surface]")
      .fill("Own unsaved formatted text Ä");
    const original = page.locator(".album-image").first();
    await original
      .getByText("Bildangaben & Reihenfolge", { exact: true })
      .click();
    await original
      .locator('[name="caption"]')
      .fill("Own unsaved photo caption Ä");
    await original.locator('[name="tags"]').fill("Own unsaved photo tags Ä");
    const exact = async () =>
      check(
        (await albumForm.locator('[name="title"]').inputValue()) ===
          f.prefix + " unsaved title Ä" &&
          (await albumForm.locator('[name="excerpt"]').inputValue()) ===
            "Own unsaved excerpt Ä" &&
          (await albumForm.locator("[data-richtext-surface]").innerText()) ===
            "Own unsaved formatted text Ä" &&
          (await original.locator('[name="caption"]').inputValue()) ===
            "Own unsaved photo caption Ä" &&
          (await original.locator('[name="tags"]').inputValue()) ===
            "Own unsaved photo tags Ä",
        name +
          " exact title excerpt rich text original caption and tags remain unsaved and intact",
      );
    let before = writes.length;
    await input.setInputFiles(
      Array.from({ length: 8 }, (_, i) => png("compact-" + i, i % 2)),
    );
    await status.filter({ hasText: "8 Bilder gespeichert." }).waitFor();
    check(
      writes.length === before + 8 && page.url() === url,
      name + " exact eight-file batch never replaces the view",
    );
    await exact();
    check(
      !(await page.locator("#workspace").evaluate((root) => root.inert)),
      name + " whole workspace remains usable",
    );
    check(
      Number(
        await page
          .locator("[data-album-version]")
          .getAttribute("data-album-version"),
      ) ===
        f.version + 8,
      name + " only exact accepted album revisions advance",
    );
    check(
      await page
        .locator('form:has([name="id"]) [name="version"]')
        .evaluateAll((fields) =>
          fields.every((field) => Number(field.value) === 10),
        ),
      name + " every own album action retains the same exact version",
    );
    check(
      (await results.locator("a").count()) === 6,
      name + " at most six private results are shown",
    );
    const openAlbum = form.getByRole("link", {
      name: "Albumfotos anzeigen",
      exact: true,
    });
    check(
      (await openAlbum.isVisible()) &&
        new URL(await openAlbum.getAttribute("href"), origin).searchParams.get(
          "album_page",
        ) === "2",
      name +
        " deliberate album link reaches the page containing the new photos",
    );
    const pager = results.getByLabel("Seite gespeicherter Uploads", {
      exact: true,
    });
    await pager.selectOption("2");
    check(
      (await results.locator("a").count()) === 2 &&
        (await results.innerText()).includes("compact-7.png"),
      name + " direct second page reaches exact last selected photos",
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 880 });
      await page.evaluate(() => (document.body.style.zoom = "2"));
      check(
        await page.evaluate(
          () =>
            document.documentElement.scrollWidth <=
            document.documentElement.clientWidth + 2,
        ),
        name + " actual populated upload fits " + width + " at 200 percent",
      );
      for (const control of [results.locator("a").first(), pager]) {
        const box = await control.boundingBox();
        check(
          box?.height >= 43.5 && box?.width >= 43.5,
          name + " actual result and page controls have touch targets",
        );
      }
      await page.evaluate(() => (document.body.style.zoom = ""));
    }
    lose = "current";
    before = writes.length;
    await input.setInputFiles([
      png("lost-current"),
      png("never-after-unknown"),
    ]);
    await status.filter({ hasText: "Upload nicht bestätigt" }).waitFor();
    check(
      writes.length === before + 1 && (await input.isDisabled()),
      name + " unknown save blocks any next upload without replay",
    );
    await exact();
    failRead = true;
    await form.locator("[data-library-upload-check]").click();
    await status.filter({ hasText: "Prüfung nicht möglich" }).waitFor();
    check(
      (await form.locator("[data-library-upload-check]").isEnabled()) &&
        writes.length === before + 1,
      name + " temporary read failure stays correctable without a write",
    );
    failRead = false;
    await form.locator("[data-library-upload-check]").click();
    await status.filter({ hasText: "1 Bilder gefunden." }).waitFor();
    check(
      (await input.isEnabled()) &&
        Number(await form.locator('[name="version"]').inputValue()) ===
          capturedVersion + 1,
      name + " exact current receipt unlocks the original version once",
    );
    await exact();
    await pager.selectOption("2");
    check(
      (await results
        .locator('[data-library-uploaded="' + pending + '"]')
        .count()) === 1 && writes.length === before + 1,
      name + " recovered exact private photo appears once without replay",
    );
    held = true;
    gate = new Promise((resolve) => {
      release = resolve;
    });
    before = writes.length;
    const started = page.waitForRequest(
      (r) => r.method() === "POST" && r.postData()?.includes("album-upload"),
    );
    await input.setInputFiles([png("cancelled"), png("never-after-abort")]);
    await started;
    check(
      (await form.locator("[data-library-upload-cancel]").isEnabled()) &&
        (await albumForm.locator('[name="title"]').isEnabled()),
      name + " cancellation and other unsaved inputs remain usable",
    );
    await form.locator("[data-library-upload-cancel]").click();
    await status.filter({ hasText: "Upload gestoppt." }).waitFor();
    release();
    held = false;
    check(
      writes.length === before + 1 && (await input.isDisabled()),
      name + " abort never sends the queued next image",
    );
    await form.locator("[data-library-upload-check]").click();
    await status.filter({ hasText: "1 noch unbestätigt." }).waitFor();
    check(
      writes.length === before + 1 &&
        (await form.locator("[data-library-upload-check]").isEnabled()),
      name + " honest missing receipt remains checkable without replay",
    );
    await exact();
    await form.locator("[data-library-upload-forget]").click();
    check(
      (await form.locator("[data-album-upload-reload]").isVisible()) &&
        (await input.isDisabled()),
      name +
        " ending lookup requires deliberate reload before further album writes",
    );
    await exact();
    await form.locator("[data-album-upload-reload]").click();
    await page.locator("dialog[open]").waitFor();
    if (standalone) await page.locator('[data-choice="stay"]').click();
    else
      await page
        .locator("dialog[open]")
        .getByRole("button", { name: "Abbrechen", exact: true })
        .click();
    await exact();
    check(
      page.url() === url && writes.length === before + 1,
      name + " staying at deliberate reload preserves all actual unsaved forms",
    );
    await context.close();
    ({ context, page } = await makeContext());
    const staleForm = page.locator("[data-album-upload-form]"),
      staleInput = staleForm.locator("[data-album-upload]"),
      staleStatus = staleForm.locator("[data-upload-progress]");
    lose = "stale";
    before = writes.length;
    await staleInput.setInputFiles([
      png("lost-stale", 1),
      png("never-after-independent-edit"),
    ]);
    await staleStatus.filter({ hasText: "Upload nicht bestätigt" }).waitFor();
    const oldVersion = await staleForm.locator('[name="version"]').inputValue();
    await staleForm.locator("[data-library-upload-check]").click();
    await staleStatus.filter({ hasText: "Album wurde geändert." }).waitFor();
    check(
      (await staleInput.isDisabled()) &&
        (await staleForm.locator('[name="version"]').inputValue()) ===
          oldVersion,
      name +
        " genuine later edit never promotes the stale forms to a newer revision",
    );
    check(
      (await staleForm.locator("[data-album-upload-reload]").isVisible()) &&
        writes.length === before + 1,
      name +
        " later edit offers deliberate reload and never replays the accepted upload",
    );
    check(
      (await staleForm
        .locator('[data-library-uploaded="' + pending + '"]')
        .count()) === 1,
      name + " exact stale receipt still offers its private photo once",
    );
    await context.close();
    before = writes.length;
    ({ context, page } = await makeContext(true));
    await page
      .locator("[data-upload-progress]")
      .filter({ hasText: "1 Bild gespeichert." })
      .waitFor();
    check(
      writes.length === before + 1 &&
        (await page.locator("[data-library-uploaded]").count()) === 1,
      name + " actual late asset consumes early album selection exactly once",
    );
    check(
      (await page
        .locator("[data-album-upload]")
        .evaluate((input) => input.files.length)) === 0 &&
        (await page.locator(".album-title").innerText()) ===
          f.prefix + " independent edit Ä",
      name +
        " early selection clears only its file field and preserves the saved independent title",
    );
    await context.close();
  }
  check(
    errors.length === 0,
    "no actual application JavaScript error: " + errors.join(" · "),
  );
  check(external.length === 0, "no external service request");
  check(
    writes.length === 24 && reads.length === 8,
    "only twenty-two accepted photo writes, two cancelled attempts and eight explicit reads",
  );
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      reads: reads.length,
      external_service_calls: false,
      original_preservation_requires_fixture_check: true,
    }),
  );
} finally {
  await browser.close();
}
