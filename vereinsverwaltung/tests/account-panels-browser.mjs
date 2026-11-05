import assert from "node:assert/strict";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { tmpdir } from "node:os";
import { createHmac } from "node:crypto";

const fixture = JSON.parse(
  await readFile(process.env.DVM_ACCOUNT_PANEL_FIXTURE, "utf8"),
);
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
const totp = (secret) => {
  let bits = 0,
    buffer = 0;
  const bytes = [],
    alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ234567";
  for (const letter of secret) {
    buffer = (buffer << 5) | alphabet.indexOf(letter);
    bits += 5;
    if (bits >= 8) {
      bits -= 8;
      bytes.push((buffer >> bits) & 255);
    }
    buffer &= (1 << bits) - 1;
  }
  const counter = Buffer.alloc(8);
  counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
  const hash = createHmac("sha1", Buffer.from(bytes)).update(counter).digest();
  return String(
    (hash.readUInt32BE(hash.at(-1) & 15) & 0x7fffffff) % 1000000,
  ).padStart(6, "0");
};
try {
  for (const mode of ["js", "basic"]) {
    const context = await browser.newContext({
      javaScriptEnabled: mode === "js",
      hasTouch: true,
      viewport: { width: 390, height: 880 },
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
    const submit = async (form, label, status = 200) => {
      const [response] = await Promise.all([
        page.waitForResponse(
          (response) =>
            response.request().method() === "POST" &&
            new URL(response.url()).origin === origin,
        ),
        page.waitForNavigation({ waitUntil: "domcontentloaded" }),
        form
          .getByRole("button", { name: label, exact: true })
          .click({ noWaitAfter: true }),
      ]);
      check(
        response.status() === status,
        mode + " actual " + label + " uses its native POST",
      );
      await ready(page);
    };
    const open = async (id) => {
      const panel = page.locator("#account-" + id);
      if (!(await panel.evaluate((element) => element.open))) {
        await panel.locator(":scope > summary").focus();
        await page.keyboard.press("Space");
      }
      check(
        (await panel.evaluate((element) => element.open)) &&
          (await page
            .locator('details[name="account-settings"][open]')
            .count()) === 1,
        mode + " keyboard opens only " + id,
      );
      return panel;
    };
    const message = async (role) => {
      const locator = page.locator("#account-result");
      check(
        (await locator.getAttribute("role")) === role &&
          (await locator.evaluate(
            (element) =>
              element === document.activeElement &&
              element.getBoundingClientRect().top >= 0 &&
              element.getBoundingClientRect().bottom <= innerHeight,
          )),
        mode + " actual result receives visible native focus",
      );
    };
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
    await page.goto(origin + "/account", { waitUntil: "domcontentloaded" });
    await ready(page);
    check(
      (await page.locator('details[name="account-settings"]').count()) === 8 &&
        (await page
          .locator('details[name="account-settings"][open]')
          .count()) === 0,
      mode + " initial account is a short eight-setting overview",
    );
    check(
      (await page
        .locator('link[href*="bootstrap-6.0.0-alpha.1.min.css"]')
        .count()) === 1,
      mode + " actual account uses local Bootstrap once",
    );
    const proposal = fixture.accounts[mode].login_name + "-browser";
    let panel = await open("username"),
      form = panel.locator("form");
    await form.locator('[name="login_name"]').fill(proposal);
    const oldVersion = await form.locator('[name="version"]').inputValue();
    await open("password");
    await open("username");
    check(
      (await form.locator('[name="login_name"]').inputValue()) === proposal,
      mode + " changing settings retains unsent original input",
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 880 });
      await page.evaluate(() => {
        document.body.style.zoom = "2";
      });
      for (const id of [
        "language",
        "appearance",
        "username",
        "password",
        "security",
        "device",
        "sessions",
      ]) {
        panel = await open(id);
        const fits = await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        );
        if (!fits) {
          const geometry = await page.evaluate(() => ({mode:document.documentElement.lang,scrollWidth:document.documentElement.scrollWidth,innerWidth,scrollX,bodyWidth:document.body.getBoundingClientRect().width,nodes:[...document.querySelectorAll('body *')].filter(n => n.getClientRects().length && n.getBoundingClientRect().right + scrollX > innerWidth + 1).map(n => ({tag:n.tagName,cls:n.className,right:n.getBoundingClientRect().right,width:n.getBoundingClientRect().width,minWidth:getComputedStyle(n).minWidth,display:getComputedStyle(n).display}))}));
          const evidence=join(process.env.VEREIN_TEST_RESULTS||tmpdir(),'dvm-account-panels-'+Date.now());await mkdir(evidence,{recursive:true,mode:0o700});await writeFile(join(evidence,'account-panels-results.json'),JSON.stringify({mode,id,width,...geometry}),{mode:0o600});await page.screenshot({path:join(evidence,'account-panels-failure.png'),fullPage:true});console.log(JSON.stringify({account_overflow:{mode,id,width,...geometry},evidence}));
        }
        check(
          fits,
          mode + " actual " + id + " fits " + width + " at 200 percent",
        );
        check(
          await panel
            .locator('input:not([type="hidden"]), select, button, summary')
            .evaluateAll((elements) =>
              elements
                .filter(
                  (element) =>
                    element.getClientRects().length &&
                    getComputedStyle(element).visibility !== "hidden",
                )
                .every((element) => {
                  const choice = element.matches('input[type="radio"],input[type="checkbox"]');
                  const target = choice ? element.closest('label') || element : element;
                  return target.getBoundingClientRect().height >= 44 &&
                    (!(element instanceof HTMLInputElement) || choice ||
                      parseFloat(getComputedStyle(element).fontSize) >= 16);
                }),
            ),
          mode + " actual " + id + " has readable touch controls " + width,
        );
      }
    }
    await page.evaluate(() => {
      document.body.style.zoom = "1";
    });
    await page.setViewportSize({ width: 320, height: 880 });
    panel = await open("username");
    form = panel.locator("form");
    const csrf = await form.locator('[name="csrf"]').inputValue();
    await form.locator('[name="csrf"]').evaluate((element) => {
      element.value = "wrong";
    });
    await form.locator('[name="current_password"]').fill(fixture.password);
    await submit(form, "Benutzernamen speichern", 422);
    await message("alert");
    form = page.locator("#account-username form");
    check(
      (await form.locator('[name="login_name"]').inputValue()) === proposal &&
        (await form.locator('[name="version"]').inputValue()) === oldVersion &&
        (await form.locator('[name="current_password"]').inputValue()) === "",
      mode + " rejection retains exact proposal/version and clears password",
    );
    check(
      (await form.locator('[name="csrf"]').inputValue()) === csrf &&
        !(await page.content()).includes(fixture.password),
      mode + " corrected native session never echoes credentials",
    );
    await form.locator('[name="current_password"]').fill(fixture.password);
    await submit(form, "Benutzernamen speichern");
    await message("status");
    check(
      (await page.locator(".account-identity").textContent()).includes(
        proposal,
      ),
      mode + " actual correction chooses the same original family account",
    );
    form = page.locator("#account-username form");
    await form.locator('[name="version"]').evaluate((element, version) => {
      element.value = version;
    }, oldVersion);
    await form.locator('[name="current_password"]').fill(fixture.password);
    await submit(form, "Benutzernamen speichern", 422);
    await message("alert");
    check(
      (await page
        .locator('#account-username [name="version"]')
        .inputValue()) === oldVersion &&
        (await page
          .locator('#account-username [name="login_name"]')
          .inputValue()) === proposal,
      mode + " stale account version stays visible for correction",
    );
    panel = await open("password");
    form = panel.locator("form");
    await form.locator('[name="current_password"]').fill(fixture.password);
    await form.locator('[name="password"]').fill(fixture.password + "New");
    await form.locator('[name="repeat"]').fill(fixture.password + "Other");
    await submit(form, "Passwort ändern", 422);
    await message("alert");
    check(
      (await page
        .locator("#account-password")
        .evaluate((element) => element.open)) &&
        (await page
          .locator('#account-password input[type="password"]')
          .evaluateAll((elements) =>
            elements.every((element) => element.value === ""),
          )),
      mode +
        " password mismatch opens only its setting and retains no passwords",
    );
    panel = await open("security");
    form = panel
      .locator("form")
      .filter({ has: page.locator('[name="operation"][value="begin-totp"]') });
    await form.locator('[name="current_password"]').fill(fixture.password);
    await submit(form, "Authenticator einrichten");
    await message("status");
    check(
      (await page
        .locator("#account-security")
        .evaluate((element) => element.open)) &&
        (await page.locator(".account-codes").isVisible()),
      mode + " actual setup opens its security setting immediately",
    );
    const secret = await page.locator(".account-codes > code").textContent();
    check(
      /^[A-Z2-7]{16,160}$/.test(secret) &&
        (await page.locator('a[href^="otpauth:"]').count()) === 1,
      mode + " own pending authenticator has its native setup link",
    );
    form = page.locator("#account-security form").filter({
      has: page.locator('[name="operation"][value="confirm-totp"]'),
    });
    await form.locator('[name="code"]').fill(totp(secret));
    await submit(form, "Einrichtung bestätigen");
    await message("status");
    check(
      (await page.locator(".account-codes li code").count()) === 10 &&
        (await page.locator(".account-codes").isVisible()),
      mode + " actual confirmation shows all ten own recovery originals",
    );
    check(
      !(await page.content()).includes(secret) &&
        !(await page.content()).includes(fixture.password),
      mode +
        " confirmed authenticator does not expose original setup or password",
    );
    await page.goto(origin + "/account", { waitUntil: "domcontentloaded" });
    await ready(page);
    await open("security");
    check(
      (await page.locator(".account-codes").count()) === 0 &&
        (await page.locator("#account-security").textContent()).includes(
          "Authenticator aktiviert",
        ),
      mode + " reload keeps the factor and never repeats recovery codes",
    );
    await page.locator("#account-security details > summary").click();
    form = page.locator("#account-security details form");
    await form.locator('[name="current_password"]').fill(fixture.password);
    await form.locator('[name="code"]').fill("invalid-own-code");
    await submit(form, "2FA deaktivieren", 422);
    await message("alert");
    check(
      (await page
        .locator("#account-security details")
        .evaluate((element) => element.open)) &&
        (await page.locator('#account-security [name="code"]').inputValue()) ===
          "" &&
        (await page.locator("#account-security").textContent()).includes(
          "Authenticator aktiviert",
        ),
      mode +
        " failed factor action reopens both settings and leaves the factor intact",
    );
    check(
      !(await page.content()).includes("invalid-own-code") &&
        !(await page.content()).includes(fixture.password),
      mode + " rejected factor does not repeat security secrets",
    );
    await context.close();
  }
  check(
    posts === 16,
    "only the sixteen selected original account posts were attempted",
  );
  check(
    errors.length === 0,
    "actual account settings have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all account requests remain on the owned runtime",
  );
  console.log(
    JSON.stringify({
      suite: "account-panels-browser",
      checks,
      post_attempts: posts,
      external_requests: 0,
    }),
  );
} finally {
  await browser.close();
}
