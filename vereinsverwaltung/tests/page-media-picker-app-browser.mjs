import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({ executablePath: "/usr/bin/chromium" });
let checks = 0;
const errors = [],
  external = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated";
    const origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111);
    const f = JSON.parse(
      await readFile(
        root + ".local/browser-tests/page-media-picker-" + name + ".json",
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
    for (const [width, height, javaScriptEnabled] of [
      [320, 880, true],
      [390, 880, true],
      [820, 880, true],
      [844, 390, true],
      [390, 880, false],
    ]) {
      const key = width + "-" + (javaScriptEnabled ? "js" : "nojs");
      const context = await browser.newContext({
        storageState,
        viewport: { width, height },
        hasTouch: true,
        javaScriptEnabled,
      });
      await context.route("**/*", (r) =>
        new URL(r.request().url()).origin === origin
          ? r.continue()
          : (external.push(r.request().url()), r.abort()),
      );
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      let posts = 0;
      const thumbIds = new Set();
      p.on("request", (r) => {
        if (r.method() === "POST") posts++;
        const u = new URL(r.url());
        const id =
          u.searchParams.get("media") ||
          u.pathname.match(/media-preview\/([a-f0-9]{32})\/thumb/)?.[1];
        if (f.images.includes(id)) thumbIds.add(id);
      });
      const url =
        origin +
        (standalone
          ? "/?view=website&item="
          : "/vereinsverwaltung/?view=website&tab=pages&native_item=") +
        f.pages[key].id;
      await p.goto(url, { waitUntil: "domcontentloaded", timeout: 60000 });
      const form = p.locator("form").filter({
        has: p.locator('input[name="operation"][value="page-save"]'),
      });
      const picker = form.locator("[data-page-media-picker]");
      const selected = () =>
        picker
          .locator("[data-page-media-select]")
          .evaluate((s) => [...s.selectedOptions].map((o) => o.value));
      check(
        JSON.stringify(await selected()) === JSON.stringify(f.selected),
        name + " exact 100 original selected IDs " + key,
      );
      check(
        (await picker.locator("option").count()) >= 112,
        name + " complete native multiple fallback " + key,
      );
      if (javaScriptEnabled) {
        await picker
          .locator("[data-page-media-controls]")
          .waitFor({ state: "attached" });
        check(
          (await picker.locator("img").count()) === 0 && thumbIds.size === 0,
          name + " closed picker requests no own thumbnails " + key,
        );
      }
      await form.locator('[name="title"]').fill(f.prefix + " edited " + key);
      await form.locator('[name="excerpt"]').fill("Own unsaved input " + key);
      await form.locator("details.website-documents summary").click();
      await form.locator('[name="website_pdfs[]"]').setInputFiles(f.files);
      await form
        .locator("details")
        .filter({
          has: p.getByText("Navigation, SEO und Bilder", { exact: true }),
        })
        .locator(":scope > summary")
        .click();
      if (javaScriptEnabled) {
        await picker.locator('[data-page-media-tab="selected"]').click();
        check(
          (await picker.locator("[data-page-media-id]").count()) === 4,
          name + " 100 selections show only four rows " + key,
        );
        await picker.locator("[data-page-media-page]").selectOption("25");
        check(
          (
            await picker
              .locator("[data-page-media-id]")
              .evaluateAll((rows) => rows.map((r) => r.dataset.pageMediaId))
          ).join() === f.selected.slice(96).join(),
          name + " direct last selection page retains every original " + key,
        );
        await picker.locator('[data-page-media-tab="catalog"]').click();
        await picker.locator("[data-page-media-query]").fill(f.prefix);
        check(
          (await picker.locator("[data-page-media-id]").count()) === 6,
          name + " catalogue results bounded to six rows " + key,
        );
        check(
          (await picker.locator("[data-page-media-page] option").count()) ===
            19,
          name + " all 112 own images reachable in short pages " + key,
        );
        await picker.locator("[data-page-media-page]").selectOption("19");
        check(
          (await picker.locator("[data-page-media-id]").count()) === 4,
          name + " catalogue last page includes original tail " + key,
        );
        await picker
          .locator("[data-page-media-query]")
          .fill(f.prefix + " Bild 111 100%_! <marker> ä");
        check(
          (await picker.locator("[data-page-media-id]").count()) === 1 &&
            (await picker
              .locator("[data-page-media-id]")
              .getAttribute("data-page-media-id")) === f.images[111],
          name + " literal punctuation Unicode search " + key,
        );
        await picker.locator("[data-page-media-query]").press("Enter");
        check(
          posts === 0,
          name + " search Enter never submits draft or upload " + key,
        );
        await picker.locator("[data-page-media-add]").click();
        check(
          (await selected()).length === 100 &&
            (
              await picker.locator("[data-page-media-status]").textContent()
            ).includes("Maximal 100"),
          name +
            " full selection rejects extra image without losing originals " +
            key,
        );
        await picker.locator('[data-page-media-tab="selected"]').click();
        await picker
          .locator(
            '[data-page-media-id="' +
              f.selected[0] +
              '"] [data-page-media-remove]',
          )
          .click();
        check(
          JSON.stringify(await selected()) ===
            JSON.stringify(f.selected.slice(1)),
          name + " explicit remove changes only chosen original " + key,
        );
        await picker.locator("[data-page-media-undo]").click();
        check(
          JSON.stringify(await selected()) === JSON.stringify(f.selected),
          name + " undo restores exact original order " + key,
        );
        await picker
          .locator(
            '[data-page-media-id="' +
              f.selected[0] +
              '"] [data-page-media-remove]',
          )
          .click();
        await picker.locator('[data-page-media-tab="catalog"]').click();
        await picker.locator("[data-page-media-add]").click();
        await picker.locator('[data-page-media-tab="selected"]').click();
        const first = picker.locator(
          '[data-page-media-id="' +
            f.selected[1] +
            '"] [data-page-media-move="down"]',
        );
        await first.click();
        await picker.locator("[data-page-media-undo]").click();
        check(
          JSON.stringify(await selected()) ===
            JSON.stringify([...f.selected.slice(1), f.images[111]]),
          name + " reorder undo retains added selection " + key,
        );
        await first.click();
        const wanted = [...f.selected.slice(1), f.images[111]];
        [wanted[0], wanted[1]] = [wanted[1], wanted[0]];
        check(
          JSON.stringify(await selected()) === JSON.stringify(wanted),
          name + " explicit ordering remains exact for real POST " + key,
        );
        check(
          (await form.locator('[name="title"]').inputValue()) ===
            f.prefix + " edited " + key &&
            (await form.locator('[name="excerpt"]').inputValue()) ===
              "Own unsaved input " + key &&
            (await form
              .locator('[name="website_pdfs[]"]')
              .evaluate((i) => i.files.length)) === 2,
          name +
            " search paging and order preserve unsaved texts and multiple uploads " +
            key,
        );
        check(
          posts === 0,
          name + " chooser actions send no page or upload POST " + key,
        );
        const touch = await picker
          .locator("button:visible,input:visible,select:visible")
          .evaluateAll((els) =>
            els
              .filter((e) => !e.disabled)
              .every(
                (e) =>
                  e.getBoundingClientRect().height >= 43.5 &&
                  e.getBoundingClientRect().width >= 43.5,
              ),
          );
        check(
          touch,
          name + " all visible chooser targets at least 44 pixels " + key,
        );
        const noOverflow = () =>
          p.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          );
        check(
          await noOverflow(),
          name +
            " populated narrow and landscape layout has no page overflow " +
            key,
        );
        await p.evaluate(() => (document.documentElement.style.zoom = "2"));
        check(
          await noOverflow(),
          name + " chooser and full editor usable at 200 percent zoom " + key,
        );
        await p.evaluate(() => (document.documentElement.style.zoom = ""));
        await picker.locator('[data-page-media-tab="catalog"]').click();
        await picker
          .locator("[data-page-media-query]")
          .fill("Nothing matches this own search");
        check(
          (await picker.locator("[data-page-media-id]").count()) === 0,
          name + " empty search keeps full selection " + key,
        );
        await picker.locator("[data-page-media-query]").fill(f.prefix);
        await picker.locator("[data-page-media-query]").focus();
        await p.keyboard.press("Tab");
        check(
          await p.evaluate(
            () =>
              !!document.activeElement?.closest("[data-page-media-picker]") &&
              ["BUTTON", "SELECT"].includes(document.activeElement.tagName),
          ),
          name + " keyboard reaches chooser actions " + key,
        );
        // A context replaced through normal navigation must mount a fresh working chooser.
      } else {
        await picker
          .locator("[data-page-media-select]")
          .selectOption(f.selected.slice(1));
        check(
          JSON.stringify(await selected()) ===
            JSON.stringify(f.selected.slice(1)),
          name + " JavaScript-free multiple selection retains original order",
        );
        check(
          (await picker
            .locator("[data-page-media-select]")
            .getAttribute("size")) === "6",
          name + " JavaScript-free chooser has six visible native rows",
        );
        await form.locator('[name="website_pdfs[]"]').setInputFiles(f.bad);
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
          JSON.stringify(await selected()) ===
            JSON.stringify(f.selected.slice(1)) &&
            (await form.locator('[name="title"]').inputValue()) ===
              f.prefix + " edited " + key &&
            (await form.locator('[name="version"]').inputValue()) === "2",
          name +
            " failed JavaScript-free PDF save retains exact unsaved own selection and text",
        );
        await form.locator("details.website-documents summary").click();
        await form.locator('[name="website_pdfs[]"]').setInputFiles(f.files);
      }
      const save = form.getByRole("button", {
        name: "Entwurf speichern",
        exact: true,
      });
      if (javaScriptEnabled) {
        const response = p.waitForResponse(
          (r) => r.request().method() === "POST" && r.url().startsWith(origin),
        );
        await save.click();
        const actual = await response;
        check(
          actual.status() >= 200 && actual.status() < 400,
          name + " actual selected-order and multiple-PDF save " + key,
        );
        await p.waitForFunction(
          () =>
            document
              .querySelector('input[name="operation"][value="page-save"]')
              ?.form.querySelector('[name="version"]').value === "3",
          { timeout: 60000 },
        );
      } else {
        await Promise.all([
          p.waitForNavigation({
            waitUntil: "domcontentloaded",
            timeout: 60000,
          }),
          save.click(),
        ]);
        check(
          (await form.locator('[name="version"]').inputValue()) === "3",
          name + " actual JavaScript-free original-order and PDF save",
        );
      }
      check(
        posts === (javaScriptEnabled ? 1 : 2),
        name + " exactly one real save without duplicate upload " + key,
      );
      if (javaScriptEnabled) {
        await form
          .locator("details")
          .filter({
            has: p.getByText("Navigation, SEO und Bilder", { exact: true }),
          })
          .locator(":scope > summary")
          .click();
        await picker.locator('[data-page-media-tab="catalog"]').click();
        await picker
          .locator("[data-page-media-query]")
          .fill(f.prefix + "-111.png");
        check(
          (await picker.locator("[data-page-media-id]").count()) === 1,
          name + " new page context mounts a working chooser " + key,
        );
      }
      await context.close();
    }
  }
  check(
    errors.length === 0,
    "both real shells have no uncaught browser errors",
  );
  check(external.length === 0, "both real shells issue no external requests");
  console.log(
    JSON.stringify({
      suite: "page-media-picker-app-browser",
      checks,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
