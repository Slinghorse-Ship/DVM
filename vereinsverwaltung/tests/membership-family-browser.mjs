import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_PUBLIC_FORM_FIXTURE, "utf8"),
);
assert(process.env.KH_NATIVE_CI === "1");
assert(new URL(fixture.origin).hostname === "127.0.0.1");
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0;
const external = [],
  errors = [],
  posts = [];
const check = (ok, message) => {
  assert(ok, message);
  checks++;
  console.log("PASS " + message);
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
  throw Error("Actual membership styles did not become ready");
};
try {
  for (const own of fixture.cases) {
    const mode = (own.js ? "JS" : "native") + " " + own.type;
    const context = await browser.newContext({
      javaScriptEnabled: own.js,
      hasTouch: true,
      viewport: { width: 320, height: 880 },
    });
    await context.route("**/*", (route) => {
      const request = route.request();
      if (new URL(request.url()).origin !== fixture.origin) {
        external.push(request.url());
        return route.abort();
      }
      if (request.method() === "POST") posts.push(request.postData());
      return route.continue();
    });
    const page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    const response = await page.goto(fixture.origin + "/mitglied-werden/", {
      waitUntil: "domcontentloaded",
    });
    check(response.ok(), mode + " actual published public route opens");
    await ready(page);
    const form = page.locator(".kh-membership-form");
    const details = form.locator("[data-family-fields]");
    const choose = form.locator('[name="membership_type"]');
    await choose.selectOption(own.type);
    if (!own.js) {
      const summary = details.locator("summary");
      await summary.focus();
      await summary.press("Enter");
      check(
        await details.evaluate((n) => n.open),
        mode + " keyboard opens native family fields",
      );
      check(
        (await form.locator('[name="family_members"]').isVisible()) &&
          (await form.locator('[name="family_reference"]').isVisible()),
        mode + " both applicable native family fields remain reachable",
      );
    } else {
      check(
        await details.evaluate((n) => n.open && !n.hidden),
        mode + " actual selection opens family fields",
      );
      check(
        await form
          .locator('[name="family_reference"]')
          .evaluate(
            (n, type) =>
              n.required === (type === "family_member") &&
              n.disabled === (type !== "family_member"),
            own.type,
          ),
        mode + " current selection requires only the applicable reference",
      );
    }
    await form
      .locator('[name="request_key"]')
      .evaluate((n, key) => (n.value = key), own.key);
    await form.locator('[name="first_name"]').fill("Eigene Vorschau Ä");
    await form
      .locator('[name="last_name"]')
      .fill("Familienprüfung " + own.type);
    await form.locator('[name="birthday"]').fill("1990-01-02");
    await form.locator('[name="email"]').fill(own.key + "@example.invalid");
    await form.locator('[name="address"]').fill("Synthetischer Testweg 1");
    await form.locator('[name="consent"]').check();
    const familyText = "Weitere Ä Person\nZweite eigene Person";
    if (own.type === "family") {
      await form.locator('[name="family_members"]').fill(familyText);
      await choose.selectOption("single");
      await choose.selectOption("family");
      check(
        (await form.locator('[name="family_members"]').inputValue()) ===
          familyText,
        mode + " switching membership retains original unsent family input",
      );
    }
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 880 });
      await page.evaluate(() => (document.body.style.zoom = "2"));
      check(
        await page.evaluate(
          () =>
            document.documentElement.scrollWidth <=
            document.documentElement.clientWidth + 2,
        ),
        mode + " actual opened family form fits " + width + " at 200 percent",
      );
      await page.evaluate(() => (document.body.style.zoom = "1"));
    }
    await page.setViewportSize({ width: 320, height: 880 });
    const submit = async () => {
      await Promise.all([
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        form
          .getByRole("button", { name: "Testantrag absenden", exact: true })
          .click({ noWaitAfter: true }),
      ]);
      await ready(page);
    };
    const errorPosition = async () => {
      check(
        new URL(page.url()).hash === "#membership-application",
        mode + " actual failed submission returns directly to its form",
      );
      check(
        await page
          .locator('.application-form-state [role="alert"]')
          .evaluate((n) => {
            const rect = n.getBoundingClientRect();
            return rect.top >= -2 && rect.top < innerHeight && rect.bottom > 0;
          }),
        mode + " actual error is visible without scrolling through the page",
      );
      if (own.js)
        check(
          await page
            .locator('.application-form-state [role="alert"]')
            .evaluate((n) => n === document.activeElement),
          mode + " real error receives keyboard focus",
        );
      check(
        (await details.evaluate((n) => n.open)) &&
          (await choose.inputValue()) === own.type,
        mode +
          " correction retains its selected family and opened native details",
      );
      check(
        (await form.locator('[name="first_name"]').inputValue()) ===
          "Eigene Vorschau Ä" &&
          (await form.locator('[name="email"]').inputValue()) ===
            own.key + "@example.invalid",
        mode + " actual error preserves typed personal originals",
      );
    };
    if (own.type === "family") {
      await form
        .locator('[name="configuration_version"]')
        .evaluate((n) => (n.value = "0"));
      await submit();
      check(
        (await page.locator('[role="alert"]').innerText()).includes(
          "Antragsbedingungen wurden geändert",
        ),
        mode + " real HTTP rejects the outdated terms",
      );
      await errorPosition();
      check(
        !(await form.locator('[name="consent"]').isChecked()),
        mode + " outdated terms require a new deliberate consent",
      );
      check(
        (await form.locator('[name="family_members"]').inputValue()) ===
          familyText,
        mode + " original Unicode family lines survive correction",
      );
      await form.locator('[name="consent"]').check();
    } else {
      const before = posts.length;
      if (own.js) {
        await form
          .getByRole("button", { name: "Testantrag absenden", exact: true })
          .click();
        check(
          posts.length === before,
          mode +
            " native validation blocks an empty required payer before any request",
        );
        check(
          await form
            .locator('[name="family_reference"]')
            .evaluate((n) => !n.validity.valid && n === document.activeElement),
          mode + " missing payer receives accessible native focus",
        );
      } else {
        await submit();
        check(
          (await page.locator('[role="alert"]').innerText()).includes(
            "Beitragszahlers angeben",
          ),
          mode + " real server rejects the missing family payer",
        );
        await errorPosition();
      }
      await form
        .locator('[name="family_reference"]')
        .fill("Eigener Beitragszahler Ä");
    }
    check(
      await form
        .locator(".form-control")
        .evaluateAll((ns) =>
          ns
            .filter((n) => n.getClientRects().length)
            .every(
              (n) =>
                n.getBoundingClientRect().height >= 44 &&
                parseFloat(getComputedStyle(n).fontSize) >= 16,
            ),
        ),
      mode + " actual opened fields retain readable touch targets",
    );
    await submit();
    check(
      (await page.locator(".application-received").innerText()).includes(
        "Bitte bestätige deinen Mitgliedsantrag",
      ),
      mode + " actual corrected family request awaits email confirmation",
    );
    check(
      new URL(page.url()).hash === "#membership-application",
      mode + " native success returns directly to its confirmation",
    );
    check(
      await page
        .locator(".application-received")
        .evaluate(
          (n) =>
            n.getBoundingClientRect().top >= -2 &&
            n.getBoundingClientRect().top < innerHeight,
        ),
      mode + " actual confirmation is visible without repeated page scrolling",
    );
    await context.close();
  }
  check(errors.length === 0, "actual family flows have no JavaScript errors");
  check(
    external.length === 0,
    "all actual family requests remain in the isolated own runtime",
  );
  check(
    posts.length === 7,
    "exactly four new requests and three corrections use actual public POST routes",
  );
  console.log(
    JSON.stringify({
      suite: "membership-family-browser",
      checks,
      post_attempts: posts.length,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
