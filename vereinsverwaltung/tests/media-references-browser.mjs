import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_REFERENCE_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
assert(
  [fixture.retained, fixture.unused].every((id) => /^[a-f0-9]{32}$/.test(id)),
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const external = [],
  errors = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const ready = async (page) => {
  for (let i = 0; i < 100; i++) {
    if (
      await page.evaluate(() =>
        [...document.querySelectorAll('link[rel="stylesheet"]')].every(
          (link) => link.sheet,
        ),
      )
    )
      return;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw Error("Actual global media observation styles required");
};
try {
  for (const js of [true, false]) {
    const context = await browser.newContext({
      javaScriptEnabled: js,
      hasTouch: true,
      viewport: { width: 390, height: 1000 },
    });
    await context.route("**/*", (route) => {
      if (new URL(route.request().url()).origin !== fixture.origin) {
        external.push(route.request().url());
        return route.abort();
      }
      if (route.request().method() === "POST") posts++;
      return route.continue();
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    const cases =
      process.env.DVM_MEDIA_REFERENCE_PHASE === "incomplete"
        ? [["incomplete", fixture.unused]]
        : [
            ["retained", fixture.retained],
            ["unreferenced", fixture.unused],
          ];
    for (const [kind, id] of cases) {
      const url =
        fixture.origin +
        "/?view=media&section=library&item=" +
        id +
        "&media_usage_page=1&media_global_check=1#media-usage";
      const entry = url.replace("&media_global_check=1", "");
      check(
        (await page.goto(entry, { waitUntil: "domcontentloaded" })).status() ===
          200,
        "ordinary media opening keeps the global observation deferred " + js,
      );
      await ready(page);
      check(
        (await page.locator("[data-media-global-state]").count()) === 0,
        "ordinary native details do not claim a global result before requesting it " +
          js,
      );
      const request = page.waitForResponse(
        (response) =>
          response.request().method() === "GET" &&
          new URL(response.url()).searchParams.get("media_global_check") ===
            "1",
      );
      const button = page.getByRole("button", {
        name: "Gesamte Verwendung prüfen",
        exact: true,
      });
      await button.focus();
      await button.press("Enter");
      check(
        (await request).status() === 200,
        "keyboard invokes a real native read only complete observation " + js,
      );
      await page.waitForURL(
        (candidate) => candidate.searchParams.get("media_global_check") === "1",
      );
      await page.locator('[data-media-global-state="' + kind + '"]').waitFor();
      check(
        (await page.locator("#media-usage").getAttribute("open")) !== null,
        "requested observation returns directly to the opened original usage panel " +
          js,
      );
      for (const width of [320, 390, 820]) {
        await page.setViewportSize({ width, height: 1100 });
        const response =
          page.url() === url
            ? await page.reload({ waitUntil: "domcontentloaded" })
            : await page.goto(url, { waitUntil: "domcontentloaded" });
        check(
          response.status() === 200,
          "actual original media page opens at " + width + " " + js,
        );
        await ready(page);
        const usage = page.locator("#media-usage");
        check(
          (await usage.getAttribute("open")) !== null,
          "selected native usage panel opens directly at " + width + " " + js,
        );
        check(
          (await usage
            .locator("[data-media-global-state]")
            .getAttribute("data-media-global-state")) === kind,
          "actual global " + kind + " state is visible at " + width + " " + js,
        );
        check(
          await usage.locator("[data-media-global-state]").isVisible(),
          "actual global state is readable without another menu at " +
            width +
            " " +
            js,
        );
        check(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          "filled original media details fit " + width + " " + js,
        );
        check(
          await usage
            .locator("summary,button,select")
            .evaluateAll((nodes) =>
              nodes.every(
                (node) => node.getBoundingClientRect().height >= 43.9,
              ),
            ),
          "actual usage controls keep native 44 pixel targets at " +
            width +
            " " +
            js,
        );
        check(
          !(await usage.textContent()).includes("PRIVATE ORIGINAL CONSENT") &&
            !(await usage.textContent()).includes("Private immutable original"),
          "global observation never exposes original private consent or unrelated full snapshots",
        );
      }
      await page.setViewportSize({ width: 640, height: 1100 });
      await page.evaluate(() => (document.body.style.zoom = "2"));
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        "filled global " + kind + " observation fits 200 percent zoom " + js,
      );
      await page.evaluate(() => (document.body.style.zoom = ""));
      const summary = page.locator("#media-usage>summary");
      await summary.focus();
      await summary.press("Space");
      check(
        (await page.locator("#media-usage").getAttribute("open")) === null,
        "native keyboard closes global observation without a write " + js,
      );
      await summary.press("Space");
      check(
        (await page.locator("#media-usage").getAttribute("open")) !== null,
        "native keyboard reopens original global observation " + js,
      );
    }
    await context.close();
  }
  check(
    posts === 0 && external.length === 0 && errors.length === 0,
    "all actual global media browsing is read only without transport or runtime errors",
  );
  console.log(
    JSON.stringify({
      suite: "media-references-browser",
      checks,
      native_posts: posts,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
