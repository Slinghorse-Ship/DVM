import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_RETENTION_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
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
const address = fixture.origin + "/?view=media&section=removed";
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
  throw Error("Actual native styles required");
};
try {
  for (const js of [true, false]) {
    const context = await browser.newContext({
      javaScriptEnabled: js,
      hasTouch: true,
      viewport: { width: 390, height: 900 },
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
    check(
      (await page.goto(address)).status() === 200,
      "actual own retention catalogue opens",
    );
    await ready(page);
    const panel = page.locator("#media-retention"),
      summary = panel.locator("summary"),
      form = panel.locator("form"),
      input = form.locator('[name="minimum_days"]');
    check(
      !(await panel.evaluate((node) => node.open)),
      "retention choices start collapsed",
    );
    await summary.focus();
    await page.keyboard.press("Enter");
    check(
      await panel.evaluate((node) => node.open),
      "keyboard opens the actual native retention choices",
    );
    check(
      (await input.inputValue()) === (js ? "14" : "7"),
      "actual form reads the current stored club rule",
    );
    const initialPosts = posts;
    await input.fill("3651");
    await form.locator("button").click();
    check(
      (await input.evaluate((node) => !node.validity.valid)) &&
        posts === initialPosts,
      "native bounds prevent an invalid request without disabling the form",
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 900 });
      for (const zoom of [1, 2]) {
        await page.evaluate(
          (value) => (document.documentElement.style.zoom = String(value)),
          zoom,
        );
        check(
          await page.evaluate(
            () =>
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 1,
          ),
          "retention and original cards fit at " + width + " and zoom " + zoom,
        );
        for (const control of [summary, input, form.locator("button")]) {
          const geometry = await control.evaluate((node) => {
            const r = node.getBoundingClientRect(),
              style = getComputedStyle(node);
            return {
              width: r.width,
              height: r.height,
              font: parseFloat(style.fontSize),
            };
          });
          check(
            geometry.width >= 43.5 &&
              geometry.height >= 43.5 &&
              geometry.font >= 16,
            "actual retention control is readable and touch sized at " +
              width +
              " and zoom " +
              zoom,
          );
        }
      }
    }
    await page.evaluate(() => (document.documentElement.style.zoom = "1"));
    await page.setViewportSize({ width: 390, height: 900 });
    await input.fill(js ? "7" : "11");
    if (js) {
      await mkdir(".local/browser-tests/media-retention", { recursive: true });
      await page.screenshot({
        path: ".local/browser-tests/media-retention/mobile.png",
      });
    }
    const response = page.waitForResponse(
      (r) => r.request().method() === "POST",
    );
    if (js) {
      await form.locator("button").click();
      check(
        (await response).status() === 200,
        "actual JS policy saves through the native JSON action",
      );
      await page
        .locator('#media-retention [name="retention_version"][value="7"]')
        .waitFor({ state: "attached" });
    } else {
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        form.locator("button").click(),
      ]);
      check(
        (await response).status() === 303,
        "actual JavaScript-free policy saves through native redirect",
      );
    }
    check(
      (await page
        .locator('#media-retention [name="minimum_days"]')
        .inputValue()) === (js ? "7" : "11"),
      "actual saved policy appears directly in the new native form",
    );
    check(
      (await page
        .locator('#media-retention [name="retention_version"]')
        .inputValue()) === (js ? "7" : "8"),
      "actual action returns the exact new rule version",
    );
    if (js) {
      await page.locator("#media-retention summary").click();
      const stale = page.locator("#media-retention form");
      await stale.locator('[name="minimum_days"]').fill("27");
      await stale
        .locator('[name="retention_version"]')
        .evaluate((node) => (node.value = "6"));
      const failed = page.waitForResponse(
        (r) => r.request().method() === "POST",
      );
      await stale.locator("button").click();
      check(
        (await failed).status() === 409,
        "actual older policy form receives a conflict",
      );
      const alert = stale.locator("[data-media-removal-error]");
      await alert.waitFor({ state: "visible" });
      for (
        let i = 0;
        i < 100 &&
        !(await alert.evaluate((node) => document.activeElement === node));
        i++
      )
        await new Promise((resolve) => setTimeout(resolve, 50));
      check(
        await alert.evaluate((node) => document.activeElement === node),
        "actual conflict moves keyboard focus to its local short error",
      );
      check(
        (await stale.locator('[name="minimum_days"]').inputValue()) === "27" &&
          (await stale.locator("[data-media-removal-check]").isVisible()),
        "conflict retains typed days and offers a read of the current rule",
      );
      const current = page.waitForResponse(
        (r) =>
          r.request().method() === "GET" && r.url().includes("section=removed"),
      );
      await stale.locator("[data-media-removal-check]").click();
      await page.locator("#discard[open]").waitFor();
      await page.locator('#discard [data-choice="stay"]').click();
      check(
        (await stale.locator('[name="minimum_days"]').inputValue()) === "27",
        "staying in the native discard dialog retains unsubmitted days",
      );
      await stale.locator("[data-media-removal-check]").click();
      await page.locator("#discard[open]").waitFor();
      await page.locator('#discard [data-choice="discard"]').click();
      check(
        (await current).status() === 200,
        "current policy check reads without resending the change",
      );
      await page
        .locator('#media-retention [name="minimum_days"][value="7"]')
        .waitFor({ state: "attached" });
    }
    await context.close();
  }
  check(
    posts === 3,
    "exactly two own new policy writes and one rejected stale request",
  );
  check(
    external.length === 0 && errors.length === 0,
    "no external request or uncaught browser error",
  );
  console.log(
    JSON.stringify({
      suite: "media-retention-browser",
      checks,
      posts,
      external_requests: 0,
      live_transport: false,
    }),
  );
} finally {
  await browser.close();
}
