import assert from "node:assert/strict";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
let checks = 0;
const errors = [],
  external = [],
  posts = [];
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
          (n) => n.sheet,
        ),
      )
    )
      return;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw Error("Actual newsletter styles did not load");
};
try {
  for (const standalone of process.env.DVM_NEWSLETTER_APP_ORIGIN
    ? [true]
    : [true, false])
    for (const js of [true, false]) {
      const origin =
        process.env.DVM_NEWSLETTER_APP_ORIGIN ||
        "http://127.0.0.1:" + (standalone ? 8107 : 8111);
      const mode =
        (standalone ? "standalone" : "integrated") + (js ? " JS" : " native");
      const context = await browser.newContext({
        javaScriptEnabled: js,
        hasTouch: true,
        viewport: { width: 320, height: 880 },
      });
      await context.route("**/*", (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (request.method() === "POST") posts.push(request.postData());
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (error) => errors.push(error.message));
      const response = await page.goto(origin + "/newsletter/", {
        waitUntil: "domcontentloaded",
      });
      check(
        response.ok() &&
          response.headers()["cache-control"].includes("no-store"),
        mode + " actual anonymous signup opens privately",
      );
      await ready(page);
      check(
        (await page
          .locator('link[href*="bootstrap-6.0.0-alpha.1.min.css"]')
          .count()) === 1,
        mode + " actual local Bootstrap alpha base loads once",
      );
      check(
        (await page.locator('link[rel="canonical"]').getAttribute("href")) ===
          origin + "/newsletter/",
        mode + " actual canonical identifies the form route",
      );
      const form = page.locator(".newsletter-form");
      const email = "owned-preview@example.invalid",
        name = "Eigene Vorschau Ä <marker>";
      await form.locator('[name="email"]').fill(email);
      await form.locator('[name="name"]').fill(name);
      await form.locator('[name="consent"]').check();
      const submit = async () => {
        const [result] = await Promise.all([
          page.waitForNavigation({ waitUntil: "domcontentloaded" }),
          form
            .getByRole("button", { name: "Anmeldung anfordern", exact: true })
            .click({ noWaitAfter: true }),
        ]);
        await ready(page);
        return result;
      };
      await submit();
      check(
        (await page.locator('[role="alert"]').innerText()).includes(
          "Thema wählen",
        ),
        mode + " actual server rejects an empty topic selection",
      );
      check(
        (await form.locator('[name="email"]').inputValue()) === email &&
          (await form.locator('[name="name"]').inputValue()) === name,
        mode + " actual correction retains exact typed and escaped originals",
      );
      check(
        new URL(page.url()).hash === "#newsletter-form" &&
          (await page
            .locator('[role="alert"]')
            .evaluate(
              (n) =>
                n === document.activeElement &&
                n.getBoundingClientRect().top >= -2 &&
                n.getBoundingClientRect().top < innerHeight,
            )),
        mode +
          " actual error receives immediate native anchor and keyboard focus",
      );
      for (const width of [320, 390, 820]) {
        await page.setViewportSize({ width, height: 880 });
        await page.evaluate(() => (document.body.style.zoom = "2"));
        check(
          await page.evaluate(
            () =>
              document.documentElement.scrollWidth <=
              document.documentElement.clientWidth + 2,
          ),
          mode + " actual signup correction fits " + width + " at 200 percent",
        );
        await page.evaluate(() => (document.body.style.zoom = "1"));
      }
      check(
        await form
          .locator("input.form-control")
          .evaluateAll((ns) =>
            ns
              .filter((n) => n.getClientRects().length)
              .every(
                (n) =>
                  n.getBoundingClientRect().height >= 44 &&
                  parseFloat(getComputedStyle(n).fontSize) >= 16,
              ),
          ),
        mode + " real signup fields retain readable touch targets",
      );
      check(
        await form
          .locator("label.check")
          .evaluateAll((ns) =>
            ns.every((n) => n.getBoundingClientRect().height >= 44),
          ),
        mode + " topic and consent labels have complete touch targets",
      );
      await page.setViewportSize({ width: 320, height: 880 });
      check(
        await form
          .locator('label.check:has([name="consent"])')
          .evaluate((n) => {
            const input = n.querySelector("input").getBoundingClientRect();
            const text = n.querySelector("span").getBoundingClientRect();
            return (
              text.left >= input.right && Math.abs(text.top - input.top) <= 8
            );
          }),
        mode + " long consent text starts beside its checkbox at phone width",
      );
      await form.locator('[value="news"]').check();
      await form
        .locator('[name="csrf"]')
        .evaluate((n) => (n.value = "invalid-owned-session"));
      const rejected = await submit();
      check(
        rejected.status() === 403 &&
          (await page.locator('[role="alert"]').innerText()).includes(
            "Formular abgelaufen",
          ),
        mode + " actual invalid session returns a usable correction",
      );
      check(
        (await form.locator('[value="news"]').isChecked()) &&
          (await form.locator('[name="consent"]').isChecked()) &&
          (await form.locator('[name="email"]').inputValue()) === email,
        mode + " rejected session preserves exact chosen topics and consent",
      );
      for (const kind of ["bestaetigen", "abmelden"]) {
        const token = "a".repeat(64);
        const endpoint = origin + "/newsletter/" + kind + "/?token=" + token;
        const opened = await page.goto(endpoint, {
          waitUntil: "domcontentloaded",
        });
        await ready(page);
        check(
          opened.ok(),
          mode + " actual " + kind + " link opens without an action",
        );
        check(
          (await page.locator('[name="token"]').inputValue()) === token,
          mode + " original " + kind + " capability stays in its native form",
        );
        check(
          (await page.locator('link[rel="canonical"]').getAttribute("href")) ===
            origin + "/newsletter/" + kind + "/",
          mode +
            " actual " +
            kind +
            " canonical excludes the private capability",
        );
        for (const width of [320, 390, 820]) {
          await page.setViewportSize({ width, height: 880 });
          await page.evaluate(() => (document.body.style.zoom = "2"));
          check(
            await page.evaluate(
              () =>
                document.documentElement.scrollWidth <=
                document.documentElement.clientWidth + 2,
            ),
            mode +
              " actual " +
              kind +
              " form fits " +
              width +
              " at 200 percent",
          );
          await page.evaluate(() => (document.body.style.zoom = "1"));
        }
        const head = await context.request.head(endpoint);
        check(
          head.status() === 200 && (await head.body()).length === 0,
          mode + " actual " + kind + " HEAD returns no token action or body",
        );
      }
      await context.close();
    }
  check(
    posts.length === (process.env.DVM_NEWSLETTER_APP_ORIGIN ? 4 : 8),
    "exactly the selected invalid real posts cannot create subscriptions",
  );
  check(
    errors.length === 0,
    "both actual newsletter runtimes have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all actual newsletter browser requests stay on owned runtimes",
  );
  console.log(
    JSON.stringify({
      suite: "newsletter-forms-app-browser",
      checks,
      post_attempts: posts.length,
      external_requests: 0,
    }),
  );
} finally {
  await browser.close();
}
