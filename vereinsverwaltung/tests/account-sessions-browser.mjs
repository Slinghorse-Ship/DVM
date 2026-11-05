import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { dirname } from "node:path";
const fixtureFile = process.env.DVM_ACCOUNT_PANEL_FIXTURE;
const fixture = JSON.parse(await readFile(fixtureFile, "utf8"));
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,
});
const origin = fixture.origin;
assert.match(origin, /^http:\/\/127\.0\.0\.1:\d+$/);
let checks = 0,
  posts = 0;
const errors = [],
  external = [],
  targets = {};
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
  for (const mode of ["js", "basic"]) {
    const context = await browser.newContext({
      javaScriptEnabled: mode === "js",
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
    const submit = async (form, label, status = 200, method = "POST") => {
      const [response] = await Promise.all([
        page.waitForResponse(
          (result) =>
            result.request().method() === method &&
            new URL(result.url()).origin === origin,
        ),
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        form
          .getByRole("button", { name: label, exact: true })
          .click({ noWaitAfter: true }),
      ]);
      check(
        response.status() === status,
        mode + " actual " + label + " uses its native form",
      );
      await ready(page);
    };
    const result = async (role) =>
      check(
        await page
          .locator("#account-result")
          .evaluate(
            (element, role) =>
              element.getAttribute("role") === role &&
              element === document.activeElement &&
              element.getBoundingClientRect().top >= 0 &&
              element.getBoundingClientRect().bottom <= innerHeight,
            role,
          ),
        mode + " actual session result receives immediate native focus",
      );
    await page.goto(origin + "/login", { waitUntil: "domcontentloaded" });
    await page
      .locator('[name="email"]')
      .fill(fixture.accounts[mode].login_name);
    await page.locator('[name="password"]').fill(fixture.password);
    await submit(
      page.locator(".account-form"),
      "Anmelden und Vereinsmanager öffnen",
      303,
    );
    await page.goto(origin + "/account?section=sessions", {
      waitUntil: "domcontentloaded",
    });
    await ready(page);
    check(
      (await page
        .locator("#account-sessions")
        .evaluate((element) => element.open)) &&
        (await page.locator(".account-session-list > li").count()) === 6,
      mode + " actual own login list opens in six rows",
    );
    check(
      (await page.locator(".account-current-session").count()) === 1 &&
        (await page.locator(".account-current-session form").count()) === 0,
      mode +
        " exact current login is identified without an individual closure button",
    );
    check(
      (await page.locator("#account-sessions").textContent()).includes(
        "14 aktive Anmeldungen",
      ),
      mode + " original total includes all fourteen own live logins",
    );
    const firstIds = await page
      .locator('.account-session-list input[name="session_id"]')
      .evaluateAll((elements) => elements.map((element) => element.value));
    const lastPage = page.locator(".account-session-pages").last();
    await lastPage.locator('[name="session_page"]').fill("3");
    await submit(lastPage, "Seite anzeigen", 200, "GET");
    check(
      (await page.locator(".account-session-list > li").count()) === 2 &&
        (await page.locator(".account-current-session").count()) === 0,
      mode +
        " native last-page control reaches the complete remaining original logins",
    );
    check(
      await page
        .locator('.account-session-pages input[name="session_page"]')
        .evaluateAll((elements) =>
          elements.every((element) => element.value === "3"),
        ),
      mode + " top and bottom page controls retain the exact selection",
    );
    let form = page.locator(".account-session-list form").first();
    const target = await form.locator('[name="session_id"]').inputValue();
    targets[mode] = target;
    check(
      /^[a-f0-9]{64}$/.test(target) && !firstIds.includes(target),
      mode +
        " original last-page target is an independent own login identifier",
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
        mode + " actual filled login list fits " + width + " at 200 percent",
      );
      check(
        await page
          .locator(
            '#account-sessions input:not([type="hidden"]), #account-sessions button, #account-sessions > summary',
          )
          .evaluateAll((elements) =>
            elements.every(
              (element) =>
                element.getBoundingClientRect().height >= 44 &&
                parseFloat(getComputedStyle(element).fontSize) >= 16,
            ),
          ),
        mode +
          " filled session list retains readable native touch targets " +
          width,
      );
    }
    await page.evaluate(() => {
      document.body.style.zoom = "1";
    });
    await page.setViewportSize({ width: 320, height: 880 });
    await form.locator('[name="csrf"]').evaluate((element) => {
      element.value = "wrong";
    });
    await submit(form, "Diese Anmeldung beenden", 422);
    await result("alert");
    check(
      (await page
        .locator('.account-session-list input[name="session_id"]')
        .evaluateAll(
          (elements, target) =>
            elements.some((element) => element.value === target),
          target,
        )) &&
        (await page.locator("#account-sessions").textContent()).includes(
          "14 aktive Anmeldungen",
        ),
      mode +
        " rejected closure retains its original own target and all live logins",
    );
    const replay = await page
      .locator(".account-session-list form")
      .first()
      .evaluate((element) => Object.fromEntries(new FormData(element)));
    form = page.locator(".account-session-list form").first();
    await submit(form, "Diese Anmeldung beenden");
    await result("status");
    check(
      (await page.locator("#account-sessions").textContent()).includes(
        "13 aktive Anmeldungen",
      ) &&
        (await page.locator(".account-session-list > li").count()) === 1 &&
        (await page
          .locator('.account-session-list input[name="session_id"]')
          .evaluateAll(
            (elements, target) =>
              elements.every((element) => element.value !== target),
            target,
          )),
      mode + " actual closure removes only the exact chosen login",
    );
    posts++;
    const repeated = await context.request.post(
      origin + "/account?section=sessions&session_page=3",
      { form: replay },
    );
    check(
      repeated.status() === 200 &&
        (await repeated.text()).includes(
          "Diese Anmeldung ist bereits beendet.",
        ),
      mode +
        " actual stale closure reports its completed original without a second mutation",
    );
    check(
      !(await page.content()).includes(fixture.password),
      mode + " actual login catalogue never echoes a password",
    );
    const firstPage = page.locator(".account-session-pages").first();
    await firstPage.locator('[name="session_page"]').fill("1");
    await submit(firstPage, "Seite anzeigen", 200, "GET");
    check(
      (await page.locator(".account-current-session").count()) === 1 &&
        (await page.locator("#account-sessions").textContent()).includes(
          "13 aktive Anmeldungen",
        ),
      mode +
        " current login retains its actual authority after selected closure",
    );
    await context.close();
  }
  check(posts === 8, "only eight selected owned account posts were attempted");
  check(
    errors.length === 0,
    "actual own login lists have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all session requests stay on the owned runtime",
  );
  const report = {
    suite: "account-sessions-browser",
    checks,
    post_attempts: posts,
    targets,
    external_requests: 0,
  };
  await writeFile(
    dirname(fixtureFile) + "/session-result.json",
    JSON.stringify(report) + "\n",
    { mode: 0o600 },
  );
  console.log(JSON.stringify({ ...report, targets: undefined }));
} finally {
  await browser.close();
}
