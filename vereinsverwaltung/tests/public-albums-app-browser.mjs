import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
let checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const poll = async (fn) => {
  for (let i = 0; i < 100; i++) {
    try {
      if (await fn()) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 100));
  }
  throw Error("Actual public gallery did not become ready");
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = kOrigin(standalone);
    const f = JSON.parse(
      await readFile(
        kDirectory()+"/public-albums-" + name + ".json",
        "utf8",
      ),
    );
    const url = (slug = "galerie", page = 1) =>
      origin +
      (standalone ? "/site/" + slug : "/" + slug + "/") +
      "?page=" +
      page;
    const last = Math.ceil(f.index.length / 6),
      ownPage =
        Math.floor(f.index.findIndex((p) => p.id === f.album.id) / 6) + 1;
    for (const javaScriptEnabled of [true, false]) {
      const mode = name + (javaScriptEnabled ? " JS" : " native");
      const context = await browser.newContext({
        javaScriptEnabled,
        hasTouch: true,
        viewport: { width: 320, height: 880 },
      });
      await context.route("**/*", (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (request.method() !== "GET") writes.push(request.method());
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      const displayed = () =>
        page
          .locator("[data-public-album]")
          .evaluateAll((ns) => ns.map((n) => n.dataset.publicAlbum));
      const choose = async (n, label) => {
        const pager = page.locator(".public-gallery-pager").first();
        await pager.getByLabel(label, { exact: true }).selectOption(String(n));
        await Promise.all([
          page.waitForURL(
            (target) => Number(target.searchParams.get("page")) === n,
            { waitUntil: "domcontentloaded" },
          ),
          pager
            .getByRole("button", { name: "Anzeigen", exact: true })
            .click({ noWaitAfter: true }),
        ]);
        await poll(
          async () =>
            Number(new URL(page.url()).searchParams.get("page")) === n &&
            Number(
              await page
                .locator(".public-gallery-pager select")
                .first()
                .inputValue(),
            ) === n,
        );
      };
      await page.goto(url(), { waitUntil: "domcontentloaded" });
      for (const [i, width] of [320, 390, 820].entries()) {
        const number = [1, ownPage, last][i];
        if (Number(new URL(page.url()).searchParams.get("page")) !== number)
          await choose(number, "Albenseite");
        assert.deepEqual(
          await displayed(),
          f.index.slice((number - 1) * 6, number * 6).map((p) => p.id),
        );
        checks++;
        console.log(
          "PASS " + mode + " exact published original album window " + number,
        );
        const originals = f.index
          .slice((number - 1) * 6, number * 6)
          .flatMap((p) => p.preview);
        check(
          (await page.locator("[data-public-photo]").count()) <= 18 &&
            (
              await page
                .locator("[data-public-photo]")
                .evaluateAll((ns) => ns.map((n) => n.dataset.publicPhoto))
            ).every((id) => originals.includes(id)),
          mode +
            " overview limits original previews to three per published album",
        );
        await page.setViewportSize({ width, height: 880 });
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
          mode + " public gallery fits " + width + " at 200 percent",
        );
        const box = await page
          .locator(".public-gallery-pager")
          .first()
          .getByRole("button", { name: "Anzeigen", exact: true })
          .boundingBox();
        check(
          box.height >= 87 && box.width >= 87,
          mode + " public pager has scaled 44 pixel touch targets",
        );
        await page.evaluate(() => (document.body.style.zoom = "1"));
      }
      await page.goto(url(f.album.slug), { waitUntil: "domcontentloaded" });
      for (const number of [1, 2]) {
        if (number === 2) await choose(number, "Bilderseite");
        assert.deepEqual(
          await page
            .locator("[data-public-photo]")
            .evaluateAll((ns) => ns.map((n) => n.dataset.publicPhoto)),
          f.photos.slice((number - 1) * 6, number * 6),
        );
        checks++;
        console.log("PASS " + mode + " complete original photo page " + number);
        check(
          (await page.locator("h1").textContent()) === f.album.title &&
            !(await page
              .locator("main")
              .textContent()
              .then((t) => t.includes("PRIVATE "))),
          mode + " private title/template/caption/credit changes stay hidden",
        );
        const canonical = await page
          .locator("link[rel=canonical]")
          .getAttribute("href");
        check(
          canonical ===
            origin +
              (standalone
                ? "/site/" + f.album.slug
                : "/" + f.album.slug + "/") +
              (number > 1 ? "?page=2" : ""),
          mode +
            " actual public canonical retains normalized original photo page",
        );
        if (number === 1) {
          check(
            (await page
              .locator("[data-public-photo]")
              .first()
              .getByRole("img")
              .getAttribute("alt")) === f.details.alt &&
              (await page
                .locator("main")
                .textContent()
                .then((t) => t.includes(f.details.credit))),
            mode +
              " exact earlier published alternative and credit survive private global metadata edits",
          );
        }
        const captions = await page
          .locator("[data-public-photo] figcaption")
          .allTextContents();
        check(
          captions.every((text, i) =>
            text.startsWith(
              f.album.gallery_items[(number - 1) * 6 + i].caption,
            ),
          ),
          mode + " all original Unicode captions retain exact published text",
        );
        if (!javaScriptEnabled)
          check(
            (await page
              .getByRole("link", { name: "Großes Bild öffnen", exact: true })
              .count()) === f.photos.slice((number - 1) * 6, number * 6).length,
            mode + " every displayed original has a native full-photo link",
          );
        await page.setViewportSize({ width: 390, height: 880 });
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
            " actual original photo page " +
            number +
            " fits at 200 percent",
        );
        await page.evaluate(() => (document.body.style.zoom = "1"));
      }
      if (javaScriptEnabled) {
        await page
          .locator("[data-public-photo]")
          .first()
          .getByRole("button")
          .click();
        const dialog = page.getByRole("dialog", { name: "Große Bildansicht" });
        await dialog.waitFor({ state: "visible" });
        check(
          (await dialog.locator("img").getAttribute("src")).includes(
            f.photos[6],
          ),
          mode + " actual last-page lightbox opens exact seventh original",
        );
        await page.keyboard.press("ArrowRight");
        check(
          (await dialog.locator("img").getAttribute("src")).includes(
            f.photos[7],
          ),
          mode + " actual keyboard reaches eighth original",
        );
        await page.keyboard.press("Escape");
        await dialog.waitFor({ state: "hidden" });
        check(true, mode + " actual lightbox keyboard close works");
        await page.screenshot({
          path:
            kDirectory()+"/public-gallery-mobile-" +
            name +
            ".png",
        });
      } else {
        const href = await page
          .getByRole("link", { name: "Großes Bild öffnen", exact: true })
          .last()
          .getAttribute("href");
        const original = await context.request.get(new URL(href, origin).href);
        check(
          href.includes(f.photos[7]) &&
            original.status() === 200 &&
            Buffer.from(await original.body())
              .subarray(0, 8)
              .equals(Buffer.from([137, 80, 78, 71, 13, 10, 26, 10])),
          mode + " real native link reads the exact eighth original PNG",
        );
      }
      for (const query of [
        "page=0",
        "page=01",
        "page=1.0",
        "page[]=1",
        "page=83335",
      ]) {
        const response = await context.request.get(
          url(f.album.slug).split("?")[0] + "?" + query,
        );
        check(
          response.status() === 400,
          mode + " actual malformed public page rejects: " + query,
        );
      }
      const head = await context.request.head(url(f.album.slug, 2));
      check(
        head.status() === 200 && (await head.body()).length === 0,
        mode + " actual original photo page supports HEAD without a body",
      );
      await context.close();
    }
  }
  check(writes.length === 0, "every public browser interaction is read-only");
  check(
    errors.length === 0,
    "both actual public routes have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all public browser requests stay on owned runtimes",
  );
  console.log(
    JSON.stringify({
      suite: "public-albums-app-browser",
      checks,
      writes: 0,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
