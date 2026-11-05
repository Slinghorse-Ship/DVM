import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
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
const errors = [],
  external = [],
  writes = [];
const check = (condition, label) => {
  assert(condition, label);
  checks++;
  console.log("PASS " + label);
};
const poll = async (work) => {
  for (let i = 0; i < 100; i++) {
    try {
      if (await work()) return;
    } catch {
      /* Navigation replaces the document. */
    }
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw Error("Actual document did not become ready");
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/album-browsing-" + name + ".json",
        "utf8",
      ),
    );
    const url =
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? { view: "media", item: f.album }
          : { view: "website", tab: "gallery", native_item: f.album },
      );
    for (const javaScriptEnabled of [true, false]) {
      const context = await browser.newContext({
        javaScriptEnabled,
        storageState: standalone ? undefined : session,
        hasTouch: true,
        viewport: { width: 320, height: 880 },
      });
      const previews = [];
      await context.route("**/*", (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (request.method() === "POST") writes.push(request.url());
        if (
          request.method() === "GET" &&
          (request.url().includes("/media-preview/") ||
            request.url().includes("native_endpoint=media-preview"))
        )
          previews.push(request.url());
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (error) => errors.push(error.message));
      await page.goto(url, { waitUntil: "domcontentloaded" });
      if (javaScriptEnabled)
        await page.waitForFunction(
          () => document.querySelector("[data-page-media-select]")?.hidden,
        );
      const mode = name + (javaScriptEnabled ? " JS" : " native");
      const choose = async (n) => {
        const pager = page.locator(".album-image-pager").first();
        await pager
          .getByLabel("Bilderseite", { exact: true })
          .selectOption(String(n));
        await pager
          .getByRole("button", { name: "Anzeigen", exact: true })
          .click();
        await poll(
          async () =>
            Number(
              await page.locator('[name="album_page"]').first().inputValue(),
            ) === n &&
            (await page
              .locator("[data-album-image]")
              .first()
              .getAttribute("data-album-image")) ===
              (n <= 3 ? f.items[(n - 1) * 6].id : f.choices[11]),
        );
      };
      check(
        (await page.locator("[data-album-image]").count()) === 6,
        mode + " actual album opens with six exact original photos",
      );
      check(
        (await page.locator("[data-page-media-picker] img").count()) === 0 &&
          previews.every((u) =>
            f.items.slice(0, 6).some((item) => u.includes(item.id)),
          ),
        mode + " folded catalogue makes no full-image-store requests",
      );
      for (const [i, width] of [320, 390, 820].entries()) {
        await page.setViewportSize({ width, height: 880 });
        await choose(i + 1);
        const actual = await page
          .locator("[data-album-image]")
          .evaluateAll((nodes) => nodes.map((n) => n.dataset.albumImage));
        assert.deepEqual(
          actual,
          f.items.slice(i * 6, i * 6 + 6).map((item) => item.id),
        );
        checks++;
        console.log(
          "PASS " +
            mode +
            " actual page " +
            (i + 1) +
            " preserves every original identity and order",
        );
        await poll(() =>
          page.evaluate(() =>
            [...document.querySelectorAll('link[rel="stylesheet"]')].every(
              (link) => link.sheet,
            ),
          ),
        );
        await page.evaluate(() => (document.body.style.zoom = "2"));
        check(
          await page.evaluate(
            () =>
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 2,
          ),
          mode +
            " six original photos and pager fit " +
            width +
            " at 200 percent",
        );
        const box = await page
          .locator(".album-image-pager")
          .first()
          .getByRole("button", { name: "Anzeigen", exact: true })
          .boundingBox();
        check(
          box?.width >= 44 && box?.height >= 44,
          mode + " actual page action has touch size",
        );
        await page.evaluate(() => (document.body.style.zoom = ""));
      }
      check(
        writes.length === (standalone ? 0 : 3),
        mode + " browsing never submits a mutation",
      );
      await page
        .getByText("Bilder aus der Mediathek hinzufügen", { exact: true })
        .click();
      const attach = page.locator(
          'form:has([name="operation"][value="album-attach"])',
        ),
        select = attach.locator('[name="images[]"]');
      check(
        await select.evaluate(
          (s, ids) =>
            ids.every((id) => [...s.options].some((o) => o.value === id)),
          f.choices,
        ),
        mode +
          " complete native choice contains every own actual library photo",
      );
      check(
        await select.evaluate(
          (s, ids) =>
            ids.every((id) => ![...s.options].some((o) => o.value === id)),
          f.items.map((item) => item.id),
        ),
        mode + " original album photos cannot be selected twice",
      );
      check(
        (await attach.locator('input[type="file"]').count()) === 0,
        mode + " saved-photo choice is separate from deliberate upload",
      );
      if (javaScriptEnabled) {
        const query = attach.locator("[data-page-media-query]");
        await query.fill(f.prefix + " choice");
        await poll(
          async () =>
            (await attach.locator("[data-page-media-id]").count()) === 6 &&
            (await attach.locator("[data-page-media-page] option").count()) ===
              3,
        );
        await attach.locator("[data-page-media-page]").selectOption("3");
        check(
          (await attach.locator("[data-page-media-id]").count()) === 1 &&
            (await attach
              .locator("[data-page-media-id]")
              .getAttribute("data-page-media-id")) === f.choices[12],
          mode +
            " exact search and page choice reach the last original library identity",
        );
        await attach.locator("[data-page-media-add]").click();
        await attach.locator('[data-page-media-tab="selected"]').click();
        check(
          (await attach.locator("[data-page-media-id]").count()) === 1 &&
            (await select.evaluate((s) =>
              [...s.selectedOptions].map((o) => o.value).join(),
            )) === f.choices[12],
          mode + " selection keeps the exact original identity without a write",
        );
        await attach.locator("[data-page-media-undo]").click();
        check(
          (await select.evaluate((s) => s.selectedOptions.length)) === 0 &&
            writes.length === (standalone ? 0 : 3),
          mode + " undo restores the unsaved choice without changing the album",
        );
      } else {
        await select.selectOption(f.choices.slice(11, 13));
        await attach
          .locator('[name="version"]')
          .evaluate((field) => (field.value = String(Number(field.value) - 1)));
        const rejected = page.waitForResponse(
          (r) =>
            r.request().method() === "POST" &&
            new URL(r.url()).origin === origin,
        );
        await attach
          .getByRole("button", {
            name: "Ausgewählte Bilder hinzufügen",
            exact: true,
          })
          .click();
        check(
          (await rejected).status() === (standalone ? 409 : 422),
          mode + " actual stale attachment is rejected",
        );
        await poll(
          async () =>
            (await page
              .locator('select[name="images[]"]')
              .evaluate((s) => s.selectedOptions.length)) === 2,
        );
        assert.deepEqual(
          await page
            .locator('select[name="images[]"]')
            .evaluate((s) => [...s.selectedOptions].map((o) => o.value)),
          f.choices.slice(11, 13),
        );
        checks++;
        check(
          (await page.locator("[data-album-photo-count]").textContent()) ===
            "18" && (await page.getByRole("alert").count()) > 0,
          mode +
            " actual correction retains the original album and readable failure",
        );
        await page
          .getByText("Bilder aus der Mediathek hinzufügen", { exact: true })
          .click();
        const accepted = page.waitForResponse(
          (r) =>
            r.request().method() === "POST" &&
            new URL(r.url()).origin === origin,
        );
        await page
          .locator('form:has([name="operation"][value="album-attach"])')
          .getByRole("button", {
            name: "Ausgewählte Bilder hinzufügen",
            exact: true,
          })
          .click();
        check(
          (await accepted).status() === 303,
          mode +
            " exact corrected native selection appends both existing photos",
        );
        await poll(
          async () =>
            (await page.locator("[data-album-photo-count]").textContent()) ===
            "20",
        );
        await choose(4);
        assert.deepEqual(
          await page
            .locator("[data-album-image]")
            .evaluateAll((nodes) => nodes.map((n) => n.dataset.albumImage)),
          f.choices.slice(11, 13),
        );
        checks++;
        check(
          (await page.locator("[data-album-image]").count()) === 2,
          mode +
            " new original photos remain reachable on the short final page",
        );
        await choose(2);
        const seventh = page.locator("[data-album-image]").first();
        await seventh
          .getByText("Bildangaben & Reihenfolge", { exact: true })
          .click();
        await seventh
          .locator('[name="caption"]')
          .fill("Own changed seventh caption Ä");
        await seventh.locator('[name="tags"]').fill("Own changed tag Ä");
        const saved = page.waitForResponse(
          (r) =>
            r.request().method() === "POST" &&
            new URL(r.url()).origin === origin,
        );
        await seventh
          .getByRole("button", { name: "Bildangaben speichern", exact: true })
          .click();
        check(
          (await saved).status() === 303,
          mode + " actual original photo caption saves from its own page",
        );
        await poll(
          async () =>
            (await page
              .locator("[data-album-image]")
              .first()
              .getAttribute("data-album-image")) === f.items[6].id &&
            (
              await page.locator("[data-album-image]").first().innerText()
            ).includes("Own changed seventh caption Ä"),
        );
        check(
          new URL(page.url()).searchParams.get("album_page") === "2",
          mode + " ordinary save returns to the exact current photo page",
        );
      }
      await context.close();
    }
  }
  check(
    errors.length === 0,
    "no actual application JavaScript error: " + errors.join(" · "),
  );
  check(external.length === 0, "no external request");
  check(
    writes.length === 6,
    "only four deliberate private saves and two rejected attachments",
  );
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      external_service_calls: false,
      original_preservation_requires_fixture_check: true,
    }),
  );
} finally {
  await browser.close();
}
