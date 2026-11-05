import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
import { createHash } from "node:crypto";
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
    const name = standalone ? "standalone" : "integrated",
      origin = "http://127.0.0.1:" + (standalone ? 8107 : 8111),
      f = JSON.parse(
        await readFile(
          root + ".local/browser-tests/provider-images-" + name + ".json",
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
    const events =
      origin +
      (standalone
        ? "/?view=events"
        : "/vereinsverwaltung/?view=website&tab=events");
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
      await context.route("**/*", (route) =>
        new URL(route.request().url()).origin === origin
          ? route.continue()
          : (external.push(route.request().url()), route.abort()),
      );
      const p = await context.newPage();
      p.on("pageerror", (e) => errors.push(e.message));
      await p.goto(events);
      const panel = p.locator(".website-eventbrite");
      await panel.locator("summary").click();
      const choice = panel.getByLabel("Anbieterbilder übernehmen");
      check(
        !(await choice.isChecked()),
        name +
          " provider pictures require explicit optional choice " +
          width +
          " " +
          javaScriptEnabled,
      );
      check(
        (await choice.locator("..").boundingBox()).height >= 44,
        name + " provider picture choice is touch-sized " + width,
      );
      await choice.check();
      check(
        (await choice.isChecked()) &&
          (await panel
            .locator("form")
            .evaluate((f) => new FormData(f).get("eventbrite_images"))) === "1",
        name + " actual form carries the selected image request " + width,
      );
      await choice.uncheck();
      check(
        (await panel
          .locator("form")
          .evaluate((f) => new FormData(f).get("eventbrite_images"))) === null,
        name + " ordinary sync keeps provider downloads optional " + width,
      );
      if (width === 820) {
        await p.evaluate(() => (document.body.style.zoom = "2"));
        check(
          await panel.evaluate(
            (el) =>
              el.getBoundingClientRect().right <= innerWidth + 2 &&
              el.scrollWidth <= el.clientWidth + 1,
          ),
          name + " provider controls fit 200 percent zoom",
        );
      }
      await p.close();
      const imagePage = await context.newPage();
      imagePage.on("pageerror", (e) => errors.push(e.message));
      await imagePage.goto(
        origin +
          (standalone
            ? "/?view=media&section=library&item="
            : "/vereinsverwaltung/?view=website&tab=gallery&section=library&native_item=") +
          f.id,
      );
      const usage = imagePage.locator(".media-usage");
      await usage.locator("summary").click();
      const row = usage.locator("article").filter({ hasText: "Anbieterbild" });
      check(
        (await row.count()) === 1,
        name +
          " actual received image exposes its exact native provider association " +
          width,
      );
      const link = row.getByRole("link", { name: "Inhalt öffnen" });
      check(
        (await link.getAttribute("href")).includes(f.page),
        name + " provider usage link binds the original event " + width,
      );
      await link.click();
      check(
        (await imagePage.locator("[name=title]").inputValue()) === f.prefix,
        name +
          " real provider link opens the unchanged original event " +
          width,
      );
      const response = await context.request.get(
        origin + "/media/" + f.id + "/medium",
      );
      check(
        response.status() === 404,
        name +
          " received private draft image never leaks through public projection " +
          width,
      );
      await context.close();
    }
  }
  check(errors.length === 0, "no script errors");
  check(
    external.length === 0,
    "no unselected external picture fetch or live transport",
  );
  console.log(
    JSON.stringify({
      checks,
      external: external.length,
      live_transport: false,
    }),
  );
} finally {
  await browser.close();
}
