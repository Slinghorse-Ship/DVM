import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
let checks = 0,
  successes = 0,
  blocked = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const hash = (bytes) => createHash("sha256").update(bytes).digest("hex");
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/media-edit-" + name + ".json",
        "utf8",
      ),
    );
    const storageState = standalone
      ? undefined
      : JSON.parse(
          await readFile(
            root + ".local/browser-tests/controls-sessions/admin.json",
            "utf8",
          ),
        );
    const url =
      origin +
      (standalone
        ? "/?view=media"
        : "/vereinsverwaltung/?view=website&tab=gallery") +
      "&section=library&" +
      (standalone ? "item=" : "native_item=") +
      f.ids[0];
    for (const width of [390, 320, 820]) {
      const context = await browser.newContext({
        storageState,
        viewport: { width, height: 880 },
        hasTouch: true,
      });
      let failSource = false,
        failSave = false,
        failStatus = false,
        unknownId = "";
      await context.route("**/*", async (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (
          failStatus &&
          request.method() === "GET" &&
          request.url().includes("upload_id=" + unknownId)
        )
          return route.fulfill({
            status: 503,
            contentType: "application/json",
            body: JSON.stringify({
              error: "Own status temporarily unavailable",
            }),
          });
        if (request.method() === "POST") {
          writes.push(request.url());
          if (failSave && request.postData()?.includes("media-edit-copy")) {
            blocked++;
            const uploadId = request
              .postData()
              ?.match(/name="upload_id"\r?\n\r?\n([a-f0-9]{32})/)?.[1];
            check(
              !!uploadId,
              name + " unknown derived copy keeps its exact proposed identity",
            );
            const own = await p.evaluate(() => window.ownEditedCopyForAckLoss);
            const fields = await p
              .locator("[data-photo-copy]")
              .evaluate((form) =>
                Object.fromEntries(
                  [...new FormData(form)].map(([key, value]) => [key, value]),
                ),
              );
            const actual = await context.request.post(request.url(), {
              headers: { Accept: "application/json", Origin: origin },
              multipart: {
                ...fields,
                upload_id: uploadId,
                image: {
                  name: own.name,
                  mimeType: own.type,
                  buffer: Buffer.from(own.bytes),
                },
              },
            });
            const data = await actual.json();
            check(
              actual.status() === 200 && data.image.id === uploadId,
              name +
                " lost-ack copy is actually accepted through real HTTP with the exact edited PNG bytes",
            );
            unknownId = uploadId;
            return route.fulfill({
              status: 502,
              contentType: "application/json",
              body: JSON.stringify({
                error: "Own lost acknowledgement fixture",
              }),
            });
          }
        }
        if (
          failSource &&
          (request.url().includes("/original") ||
            request.url().includes("variant=original"))
        )
          return route.abort();
        return route.continue();
      });
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(url);
      const form = p.locator("[data-photo-copy]"),
        status = form.locator("[data-photo-copy-status]"),
        button = form.getByRole("button", {
          name: "Foto bearbeiten",
          exact: true,
        });
      await button.waitFor();
      const originalURL = new URL(
        await p
          .locator(".media-editor figure img")
          .getAttribute("data-original"),
        origin,
      ).href;
      const originalHash = hash(
          await (await context.request.get(originalURL)).body(),
        ),
        publicURL = origin + "/media/" + f.ids[0] + "/medium";
      const publicHash = hash(
          await (await context.request.get(publicURL)).body(),
        ),
        publicHTML = await (
          await context.request.get(origin + "/site/" + f.slug)
        ).text();
      const metadata = p.locator(".media-metadata-form"),
        alt = metadata.locator("[name=alt]");
      await alt.fill("Eigene ungespeicherte Angabe " + width);
      const values = await metadata.evaluate((form) =>
        Object.fromEntries(new FormData(form)),
      );
      check(
        (await button.boundingBox()).height >= 44,
        name + " stored photo editor has a touch-sized action " + width,
      );
      await button.click();
      const dialog = p.locator("dialog.photo-editor");
      await dialog
        .getByRole("button", { name: "Übernehmen", exact: true })
        .waitFor();
      await dialog
        .getByRole("button", { name: "Abbrechen", exact: true })
        .click();
      await p.waitForFunction(
        () =>
          !document
            .querySelector("[data-photo-copy]")
            .hasAttribute("aria-busy"),
      );
      check(
        (await status.textContent()) === "" &&
          (await p.locator("dialog.photo-editor").count()) === 0,
        name + " cancel saves no copy " + width,
      );
      if (width === 320) {
        failSource = true;
        await button.click();
        await status
          .filter({
            hasText: "Gespeichertes Foto konnte nicht geladen werden.",
          })
          .waitFor();
        await p.waitForFunction(
          () =>
            !document
              .querySelector("[data-photo-copy]")
              .hasAttribute("aria-busy"),
        );
        failSource = false;
        check(
          await button.isEnabled(),
          name +
            " failed source download leaves the original and action usable",
        );
        failSave = true;
        await p.evaluate(() => {
          const original = window.KulturhusPhotoEditor.edit;
          window.KulturhusPhotoEditor.edit = async (...args) => {
            const file = await original(...args);
            if (file)
              window.ownEditedCopyForAckLoss = {
                name: file.name,
                type: file.type,
                bytes: [...new Uint8Array(await file.arrayBuffer())],
              };
            return file;
          };
        });
        await button.click();
        await dialog
          .getByRole("button", { name: "Übernehmen", exact: true })
          .waitFor();
        await dialog
          .getByRole("button", { name: "Rechts drehen", exact: true })
          .click();
        await dialog
          .getByRole("button", { name: "Übernehmen", exact: true })
          .click();
        await status
          .filter({ hasText: "Keine automatische Wiederholung" })
          .waitFor();
        await p.waitForFunction(
          () =>
            !document
              .querySelector("[data-photo-copy]")
              .hasAttribute("aria-busy"),
        );
        failSave = false;
        check(
          (await button.isEnabled()) && (await form.locator("a").count()) === 0,
          name +
            " unknown save is not automatically retried or claimed successful",
        );
        const beforeRead = writes.length;
        failStatus = true;
        await form.locator("[data-photo-copy-check]").click();
        await status.filter({ hasText: "Prüfung nicht möglich" }).waitFor();
        check(
          writes.length === beforeRead &&
            (await form.locator("[data-photo-copy-check]").isEnabled()) &&
            (await form.locator("[data-photo-copy-check]").isVisible()),
          name + " failed read retains the exact pending copy without replay",
        );
        failStatus = false;
        const checked = p.waitForResponse(
          (response) =>
            response.request().method() === "GET" &&
            response.url().includes("upload_id=" + unknownId),
        );
        await form.locator("[data-photo-copy-check]").click();
        const response = await checked,
          data = await response.json();
        await form
          .getByRole("link", { name: "Neue Fassung öffnen", exact: true })
          .waitFor();
        check(
          response.ok() && data.found === true && data.image.id === unknownId,
          name + " read-only status finds the exact accepted derived copy",
        );
        check(
          writes.length === beforeRead &&
            (await form.locator("[data-photo-copy-check]").isHidden()),
          name +
            " finding the derived copy never replays a POST or retains a pending confirmation",
        );
        const recovered = new URL(
          await form
            .getByRole("link", { name: "Neue Fassung öffnen", exact: true })
            .getAttribute("href"),
        );
        check(
          (recovered.searchParams.get("item") ||
            recovered.searchParams.get("native_item")) === unknownId &&
            p.url() === url,
          name +
            " recovered exact copy can be opened without navigating or losing current input",
        );
      }
      await button.click();
      await dialog
        .getByRole("button", { name: "Übernehmen", exact: true })
        .waitFor();
      await dialog
        .getByRole("button", { name: "Rechts drehen", exact: true })
        .click();
      check(
        (await dialog.evaluate((d) => d.photoEditor.snapshot().rotation)) ===
          90,
        name + " actual stored original rotates in the editor " + width,
      );
      await dialog
        .getByRole("button", { name: "Übernehmen", exact: true })
        .click();
      const link = form.getByRole("link", {
        name: "Neue Fassung öffnen",
        exact: true,
      });
      await link.waitFor();
      check(
        await link
          .boundingBox()
          .then((b) => b.width >= 43.5 && b.height >= 43.5),
        name +
          " confirmed new-copy link is a real 44-pixel touch action " +
          width,
      );
      successes++;
      check(
        (await p.url()) === url,
        name + " saved new copy leaves the original editor open " + width,
      );
      check(
        JSON.stringify(
          await metadata.evaluate((form) =>
            Object.fromEntries(new FormData(form)),
          ),
        ) === JSON.stringify(values),
        name +
          " every unsaved original form value survives copy editing " +
          width,
      );
      check(
        hash(await (await context.request.get(originalURL)).body()) ===
          originalHash &&
          hash(await (await context.request.get(publicURL)).body()) ===
            publicHash &&
          (await (
            await context.request.get(origin + "/site/" + f.slug)
          ).text()) === publicHTML,
        name +
          " exact original private/public pixels and page remain unchanged " +
          width,
      );
      const next = new URL(await link.getAttribute("href"), origin),
        id = next.searchParams.get(standalone ? "item" : "native_item");
      check(
        /^[a-f0-9]{32}$/.test(id) && id !== f.ids[0] && next.origin === origin,
        name + " saved copy link binds a new own image " + width,
      );
      const copyPage = await context.newPage();
      await copyPage.goto(next.href);
      check(
        (await copyPage.locator(".media-editor figcaption").textContent()) ===
          "32 × 48 Pixel",
        name +
          " actual stored copy opens with independently checked geometry " +
          width,
      );
      await copyPage.close();
      check(
        await form.evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
        name + " stored photo controls fit " + width,
      );
      if (width === 820) {
        await p.evaluate(() => (document.body.style.zoom = "2"));
        check(
          await form.evaluate(
            (el) =>
              el.getBoundingClientRect().right <= innerWidth + 2 &&
              el.scrollWidth <= el.clientWidth + 1,
          ),
          name + " stored photo controls fit 200 percent zoom",
        );
        await p.evaluate(() => (document.body.style.zoom = ""));
      }
      await context.close();
    }
  }
  check(errors.length === 0, "no browser script errors");
  check(external.length === 0, "no external image service or publication");
  check(
    successes === 6 && blocked === 2 && writes.length === 8,
    "six confirmed copies and two actually accepted lost-ack copies without retries",
  );
  console.log(
    JSON.stringify({
      checks,
      successes,
      blocked,
      writes: writes.length,
      external: external.length,
      live_transport: false,
    }),
  );
} finally {
  await browser.close();
}
