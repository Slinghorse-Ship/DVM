import assert from "node:assert/strict";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
});
let checks = 0,
  posts = 0;
const errors = [],
  external = [];
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
  throw Error("Actual account styles did not load");
};
try {
  for (const origin of ["http://127.0.0.1:8107", "http://127.0.0.1:8111"])
    for (const js of [true, false]) {
      const mode = origin.endsWith("8107") ? "standalone" : "integrated";
      const label = mode + (js ? " JS" : " native");
      const context = await browser.newContext({
        javaScriptEnabled: js,
        hasTouch: true,
        viewport: { width: 320, height: 880 },
      });
      await context.route("**/*", (route) => {
        if (new URL(route.request().url()).origin !== origin) {
          external.push(route.request().url());
          return route.abort();
        }
        if (route.request().method() === "POST") posts++;
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (error) => errors.push(error.message));
      let response = await page.goto(origin + "/login", {
        waitUntil: "domcontentloaded",
      });
      await ready(page);
      check(
        response.ok() &&
          response.headers()["cache-control"].includes("no-store"),
        label + " actual anonymous personal login opens privately",
      );
      check(
        (await page
          .locator('link[href*="bootstrap-6.0.0-alpha.1.min.css"]')
          .count()) === 1,
        label + " actual login uses local Bootstrap once",
      );
      const identifier = "own-unsent-Ä<marker>@example.invalid",
        secret = "Own unsent password Ä 2026";
      await page.locator('[name="email"]').fill(identifier);
      await page.locator('[name="password"]').fill(secret);
      await page.locator('[name="csrf"]').evaluate((element) => {
        element.value = "wrong";
      });
      [response] = await Promise.all([
        page.waitForResponse((result) => result.request().method() === "POST"),
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        page
          .getByRole("button", {
            name: "Anmelden und Vereinsmanager öffnen",
            exact: true,
          })
          .click({ noWaitAfter: true }),
      ]);
      await ready(page);
      check(
        response.status() === 422,
        label + " actual invalid session rejects before authentication",
      );
      check(
        (await page.locator('[name="email"]').inputValue()) === identifier &&
          (await page.locator('[name="password"]').inputValue()) === "" &&
          !(await page.content()).includes(secret),
        label +
          " correction retains exact identifier and never echoes password",
      );
      check(
        await page
          .locator("#account-result")
          .evaluate(
            (element) =>
              element === document.activeElement &&
              element.getBoundingClientRect().top >= 0 &&
              element.getBoundingClientRect().bottom <= innerHeight,
          ),
        label + " actual correction receives immediate native focus",
      );
      for (const width of [320, 390, 820]) {
        await page.setViewportSize({ width, height: 880 });
        await page.evaluate(() => {
          document.body.style.zoom = "2";
        });
        check(
          await page.evaluate(
            () => document.documentElement.scrollWidth <= innerWidth + 1,
          ),
          label + " actual corrected login fits " + width + " at 200 percent",
        );
        check(
          await page
            .locator(
              '.account-form input:not([type="hidden"]), .account-form button',
            )
            .evaluateAll((elements) =>
              elements.every(
                (element) =>
                  element.getBoundingClientRect().height >= 44 &&
                  parseFloat(getComputedStyle(element).fontSize) >= 16,
              ),
            ),
          label + " corrected login retains readable touch fields " + width,
        );
      }
      check(
        (await page.locator('[name="email"]').inputValue()) === identifier,
        label + " viewport changes retain the exact original identifier",
      );
      await page.evaluate(() => {
        document.body.style.zoom = "1";
      });
      for (const path of [
        "/forgot",
        "/invite?token=" + "a".repeat(64),
        "/reset?token=" + "a".repeat(64),
      ]) {
        response = await page.goto(origin + path, {
          waitUntil: "domcontentloaded",
        });
        await ready(page);
        check(
          response.ok() && (await page.locator(".account-form").count()) === 1,
          label + " actual " + path.split("?")[0] + " has its native form",
        );
        for (const width of [320, 390, 820]) {
          await page.setViewportSize({ width, height: 880 });
          await page.evaluate(() => {
            document.body.style.zoom = "2";
          });
          check(
            await page.evaluate(
              () => document.documentElement.scrollWidth <= innerWidth + 1,
            ),
            label +
              " actual " +
              path.split("?")[0] +
              " fits " +
              width +
              " at 200 percent",
          );
        }
        await page.evaluate(() => {
          document.body.style.zoom = "1";
        });
      }
      await context.close();
    }
  check(posts === 4, "only four invalid owned session posts were attempted");
  check(
    errors.length === 0,
    "both actual account runtimes have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all actual account requests stay on owned runtimes",
  );
  console.log(
    JSON.stringify({
      suite: "account-correction-app-browser",
      checks,
      post_attempts: posts,
      external_requests: 0,
    }),
  );
} finally {
  await browser.close();
}
