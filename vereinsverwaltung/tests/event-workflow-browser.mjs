import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(process.env.PLAYWRIGHT_MODULE);
const fixture = JSON.parse(
  await readFile(process.env.DVM_EVENT_WORKFLOW_FIXTURE, "utf8"),
);
assert.equal(new URL(fixture.origin).hostname, "127.0.0.1");
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
});
let checks = 0,
  posts = 0;
const errors = [],
  outside = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  for (const javaScriptEnabled of [true, false]) {
    const context = await browser.newContext({
      viewport: { width: 390, height: 900 },
      hasTouch: true,
      javaScriptEnabled,
    });
    context.on("request", (r) => {
      if (r.method() === "POST") posts++;
      if (!r.url().startsWith(fixture.origin + "/")) outside.push(r.url());
    });
    const page = await context.newPage();
    page.on("pageerror", (e) => errors.push(e.message));
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 1000 });
      for (const section of ["settings", "link"]) {
        await page.goto(fixture.origin + "/?section=" + section);
        const panel = page.locator("details.panel");
        check(
          (await panel.count()) === 1 && !(await panel.getAttribute("open")),
          section + " starts as one compact closed panel",
        );
        if (width === 320) {
          await panel.locator("summary").focus();
          await page.keyboard.press("Enter");
          check(
            (await panel.getAttribute("open")) !== null,
            section + " opens by keyboard",
          );
          await page.keyboard.press("Space");
          check(
            (await panel.getAttribute("open")) === null,
            section + " closes by keyboard",
          );
        }
        await panel.locator("summary").tap();
        if (width === 390 && section === "settings") {
          const button = panel.locator("button");
          let colour = null;
          for (let attempt = 0; attempt < 30; attempt++) {
            colour = await button.evaluate((el) => {
              const s = getComputedStyle(el);
              return {
                bg: s.backgroundColor,
                fg: s.color,
                font: parseFloat(s.fontSize),
              };
            });
            if (
              colour.bg === "rgb(36, 86, 64)" &&
              colour.fg === "rgb(255, 255, 255)"
            )
              break;
            await new Promise((resolve) => setTimeout(resolve, 50));
          }
          check(
            colour.bg === "rgb(36, 86, 64)" &&
              colour.fg === "rgb(255, 255, 255)" &&
              colour.font >= 16,
            "actual settled primary action has strong contrast and readable 16 px text",
          );
        }
        for (const zoom of [1, 2]) {
          await page.locator("body").evaluate((el, z) => {
            el.style.zoom = String(z);
          }, zoom);
          const layout = await page.evaluate(() => ({
            client: document.documentElement.clientWidth,
            scroll: document.documentElement.scrollWidth,
          }));
          check(
            layout.scroll <= layout.client + 1,
            section +
              " has no horizontal document scroll at " +
              width +
              " and " +
              zoom +
              "x",
          );
          for (const locator of await panel
            .locator(
              "select,input:not([type=hidden]):not([type=checkbox]),button,summary",
            )
            .all()) {
            const m = await locator.evaluate((el) => {
              const s = getComputedStyle(el);
              return {
                h: parseFloat(s.minHeight) || el.offsetHeight,
                font: parseFloat(s.fontSize),
              };
            });
            check(
              m.h >= 44,
              section + " actual control has 44 px touch target",
            );
            if (
              await locator.evaluate((el) =>
                ["INPUT", "SELECT"].includes(el.tagName),
              )
            )
              check(
                m.font >= 16,
                section + " input is readable without iOS focus zoom",
              );
          }
        }
      }
    }
    await page.goto(fixture.origin + "/?section=settings");
    await page.locator("#event-workflow summary").tap();
    await page.locator("select[name=source]").selectOption("facebook");
    await page.locator("select[name=tickets]").selectOption("native");
    await page
      .locator("select[name=payment]")
      .selectOption(javaScriptEnabled ? "stripe" : "sumup");
    await page.locator("input[name=automatic_publication]").uncheck();
    await Promise.all([
      page.waitForURL(/saved=1/),
      page
        .getByRole("button", { name: "Veranstaltungsablauf speichern" })
        .tap(),
    ]);
    await page.locator("#event-workflow summary").tap();
    check(
      (await page.locator("select[name=payment]").inputValue()) ===
        (javaScriptEnabled ? "stripe" : "sumup"),
      "actual provider choice is saved and reopened through native settings action",
    );
    await page.goto(fixture.origin + "/?section=events");
    await page.locator(".website-eventbrite summary").tap();
    check(
      (await page
        .getByRole("button", { name: "Facebook-Termine abgleichen" })
        .count()) === 1 &&
        (await page
          .getByRole("button", { name: "Eventbrite synchronisieren" })
          .count()) === 0,
      "only selected Facebook sync offered",
    );
    await Promise.all([
      page.waitForURL(/saved=1/),
      page.getByRole("button", { name: "Facebook-Termine abgleichen" }).tap(),
    ]);
    check(
      (await page
        .getByRole("status")
        .filter({ hasText: "Gespeichert" })
        .count()) === 1,
      "actual native sync POST completes",
    );
    await page.goto(fixture.origin + "/?section=link");
    await page.locator("#facebook-event-link summary").tap();
    await page.locator("input[name=facebook_event_id]").fill("9004");
    await page.locator("input[name=link_confirm]").check();
    await Promise.all([
      page.waitForURL(/saved=1/),
      page.getByRole("button", { name: "Veranstaltung zuordnen" }).tap(),
    ]);
    check(
      (await page.getByRole("status").count()) === 1,
      "actual explicit version-bound association POST completes",
    );
    if (javaScriptEnabled) {
      await page.setViewportSize({ width: 390, height: 850 });
      await page.goto(fixture.origin + "/?section=settings");
      await page.locator("#event-workflow summary").tap();
      for (let attempt = 0; attempt < 30; attempt++) {
        if (
          await page
            .locator("#event-workflow button")
            .evaluate(
              (el) =>
                getComputedStyle(el).backgroundColor === "rgb(36, 86, 64)",
            )
        )
          break;
        await new Promise((resolve) => setTimeout(resolve, 50));
      }
      await mkdir(".local/browser-tests/event-workflows", { recursive: true });
      await page.screenshot({
        path: ".local/browser-tests/event-workflows/mobile.png",
        fullPage: true,
      });
    }
    const csrf = await page.locator("input[name=csrf]").inputValue();
    const denied = await context.request.post(
      fixture.origin + "/?section=link",
      {
        form: {
          operation: "facebook-event-link",
          csrf: csrf + "foreign",
          id: fixture.id,
          version: "1",
          facebook_event_id: "9004",
          link_confirm: "1",
        },
      },
    );
    check(
      (await denied.text()).includes("Formular abgelaufen"),
      "actual HTTP rejects foreign CSRF",
    );
    await context.close();
  }
  check(
    posts === 6,
    "exactly six intentional browser form posts across JS and no-JS",
  );
  check(
    errors.length === 0 && outside.length === 0,
    "no browser script errors or external browser requests",
  );
  console.log(
    JSON.stringify({
      checks,
      posts,
      external_requests: outside.length,
      errors,
    }),
  );
} finally {
  await browser.close();
}
