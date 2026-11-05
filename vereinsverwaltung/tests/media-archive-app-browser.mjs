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
let checks = 0;
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
        root + ".local/browser-tests/media-archive-" + name + ".json",
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
    const url = (extra = {}) =>
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams({
        ...(standalone
          ? { view: "media" }
          : { view: "website", tab: "gallery" }),
        section: "library",
        ...extra,
      });
    let version = 0;
    for (const [width, javaScriptEnabled] of [
      [390, true],
      [320, true],
      [820, true],
      [390, false],
    ]) {
      const context = await browser.newContext({
        storageState,
        viewport: { width, height: 880 },
        hasTouch: true,
        javaScriptEnabled,
      });
      await context.route("**/*", (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (request.method() === "POST") writes.push(request.url());
        return route.continue();
      });
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      const item = { [standalone ? "item" : "native_item"]: f.ids[0] };
      await p.goto(url(item));
      const imageURL = await p
        .locator(".media-editor figure img")
        .getAttribute("src");
      const source = await context.request.get(new URL(imageURL, origin).href);
      check(
        source.status() === 200,
        name + " private original available " + width + " " + javaScriptEnabled,
      );
      const privateHash = hash(await source.body()),
        publicURL = origin + "/media/" + f.ids[0] + "/medium";
      const publicImage = await context.request.get(publicURL);
      check(
        publicImage.status() === 200,
        name + " published original available before archive " + width,
      );
      const publicHash = hash(await publicImage.body()),
        publicHTML = await (
          await context.request.get(origin + "/site/" + f.slug)
        ).text();
      const action = p.getByRole("button", {
        name: "Bild archivieren",
        exact: true,
      });
      check(
        (await action.isVisible()) && (await action.boundingBox()).height >= 44,
        name + " archive touch action " + width,
      );
      check(
        (await p
          .locator(".media-archive [name=archive_version]")
          .inputValue()) === String(version),
        name + " original-bound archive form " + version,
      );
      await action.click();
      await p
        .getByRole("button", { name: "Bild zurückholen", exact: true })
        .waitFor();
      version++;
      check(
        (await p
          .locator(".media-archive [name=archive_version]")
          .inputValue()) === String(version),
        name + " actual archive persisted " + width,
      );
      check(
        hash(
          await (
            await context.request.get(new URL(imageURL, origin).href)
          ).body(),
        ) === privateHash,
        name + " archived private pixels unchanged " + width,
      );
      check(
        hash(await (await context.request.get(publicURL)).body()) ===
          publicHash &&
          (await (
            await context.request.get(origin + "/site/" + f.slug)
          ).text()) === publicHTML,
        name + " archived published bytes and HTML unchanged " + width,
      );
      check(
        await p
          .locator(".media-archive")
          .evaluate((el) => el.scrollWidth <= el.clientWidth + 1),
        name + " archive controls fit " + width,
      );
      if (width === 820) {
        await p.evaluate(() => (document.body.style.zoom = "2"));
        check(
          await p
            .locator(".media-archive")
            .evaluate(
              (el) =>
                el.getBoundingClientRect().right <= innerWidth + 2 &&
                el.scrollWidth <= el.clientWidth + 1,
            ),
          name + " archive controls fit at 200 percent zoom",
        );
        await p.evaluate(() => (document.body.style.zoom = ""));
      }
      const history = p.locator(".media-archive details");
      check(
        !(await history.getAttribute("open")),
        name + " history starts compact " + width,
      );
      await history.locator("summary").click();
      check(
        (await history.locator("li").count()) === version,
        name + " original history fully available " + version,
      );
      await p.goto(url({ media_q: f.prefix, media_archive: "archived" }));
      check(
        (await p.locator(".media-library-card").count()) === 1 &&
          (
            await p.locator(".media-library-card a").getAttribute("href")
          ).includes(f.ids[0]),
        name + " archive search returns exact original " + width,
      );
      await p.goto(url({ media_q: f.prefix, media_archive: "active" }));
      check(
        (await p.locator(".media-library-card").count()) === 1 &&
          (
            await p.locator(".media-library-card a").getAttribute("href")
          ).includes(f.ids[1]),
        name + " active search keeps exact untouched photo " + width,
      );
      await p.goto(url({ media_q: f.prefix, media_archive: "all" }));
      check(
        (await p.locator(".media-library-card").count()) === 2,
        name + " all search keeps both originals " + width,
      );
      await p.goto(url(item));
      const restore = p.getByRole("button", {
        name: "Bild zurückholen",
        exact: true,
      });
      await restore.focus();
      await restore.press("Enter");
      await p
        .getByRole("button", { name: "Bild archivieren", exact: true })
        .waitFor();
      version++;
      check(
        (await p
          .locator(".media-archive [name=archive_version]")
          .inputValue()) === String(version),
        name + " exact same photo restored " + width + " " + javaScriptEnabled,
      );
      check(
        hash(await (await context.request.get(publicURL)).body()) ===
          publicHash &&
          (await (
            await context.request.get(origin + "/site/" + f.slug)
          ).text()) === publicHTML,
        name + " restored original publication unchanged " + width,
      );
      await context.close();
    }
  }
  check(errors.length === 0, "no browser script errors");
  check(external.length === 0, "no external requests or publication");
  check(
    writes.length === 16,
    "exactly sixteen actual original-bound archive/restore POSTs",
  );
  console.log(
    JSON.stringify({
      checks,
      writes: writes.length,
      external: external.length,
      live_transport: false,
    }),
  );
} finally {
  await browser.close();
}
