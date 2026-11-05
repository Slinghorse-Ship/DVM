import assert from "node:assert/strict";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(root + ".local/browser-tests/member-bank-native.json", "utf8"),
);
const credentials = JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const errors = [],
  checks = [];
try {
  const context = await browser.newContext();
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", { exact: true })
    .fill(credentials.admin.email);
  await page
    .getByLabel("Passwort", { exact: true })
    .fill(credentials.admin.password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  await page.goto(origin + fixture.url);
  const save = page
    .locator("form")
    .filter({ has: page.locator("[name=operation][value=save]") });
  if ((await save.locator("[name=version]").inputValue()) === "0") {
    await save
      .getByLabel("Kontoinhaber", { exact: true })
      .fill("Synthetischer Browserkontoinhaber");
    await save
      .getByLabel("IBAN", { exact: true })
      .fill("DE89370400440532013000");
    await save.locator("[name=password]").fill(credentials.admin.password);
    await Promise.all([
      page.waitForNavigation(),
      save.getByRole("button", { name: "Bankdaten sicher speichern" }).click(),
    ]);
  }
  assert.equal(await page.locator('[name="data[iban]"]').inputValue(), "");
  const reveal = page
    .locator("form")
    .filter({ has: page.locator("[name=operation][value=reveal]") });
  await reveal.locator("[name=password]").fill(credentials.admin.password);
  await reveal
    .getByRole("button", { name: "Bankdaten öffnen", exact: true })
    .click();
  await page.locator("[data-bank-revealed-form]").waitFor();
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    const form = page.locator("[data-bank-revealed-form]");
    assert.equal(
      await form.locator('[name="data[iban]"]').inputValue(),
      "DE89370400440532013000",
    );
    assert.equal(await form.locator("[name=password]").inputValue(), "");
    await form.locator("[name=password]").focus();
    assert(
      await form
        .locator("[name=password]")
        .evaluate((el) => document.activeElement === el),
    );
    assert(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
    );
    assert(
      !/Fatal error:|Warning:|Notice:|Parse error:/.test(
        await page.locator("body").innerText(),
      ),
    );
    await mkdir(root + ".local/browser-tests", {
      recursive: true,
      mode: 0o700,
    });
    await page.screenshot({
      path: root + ".local/browser-tests/native-member-bank-" + width + ".png",
      fullPage: true,
    });
    checks.push({
      width,
      native_password: true,
      original_values: true,
      fresh_save_password: true,
      keyboard: true,
      overflow: false,
    });
  }
  await page.reload();
  assert.equal(await page.locator("[data-bank-revealed-form]").count(), 0);
  assert.equal(await page.locator('[name="data[iban]"]').inputValue(), "");
  const deniedContext = await browser.newContext();
  const member = await deniedContext.newPage();
  await member.goto(origin + "/login/");
  await member
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", { exact: true })
    .fill(credentials.member.email);
  await member
    .getByLabel("Passwort", { exact: true })
    .fill(credentials.member.password);
  await Promise.all([
    member.waitForURL("**/vereinsverwaltung/**"),
    member
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  const denied = await member.goto(origin + fixture.url);
  assert.equal(denied.status(), 403);
  assert.equal(
    await member.locator('[name=password], [name="data[iban]"]').count(),
    0,
  );
  assert.deepEqual(errors, []);
  await writeFile(
    root + ".local/browser-tests/native-member-bank-result.json",
    JSON.stringify(
      {
        checks,
        get_masked: true,
        foreign_member_denied: true,
        live_transport: false,
      },
      null,
      2,
    ),
    { mode: 0o600 },
  );
  console.log(
    "Native member bank: actual account/password, original encrypted bank data, masked GET, separate save confirmation, member denial and keyboard at 1440/820/390 px passed.",
  );
} finally {
  await browser.close();
}
