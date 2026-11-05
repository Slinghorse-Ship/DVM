import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { execFile } from "node:child_process";
import { promisify } from "node:util";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(
    root + ".local/browser-tests/native-email-factor.json",
    "utf8",
  ),
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
const contexts = [],
  checks = [],
  errors = [];
let activePage;
const check = (ok, label) => {
  assert(ok, label);
  checks.push(label);
};
const deliver = async (purpose) => {
  await promisify(execFile)(
    "/workspace/.setup/bin/php",
    [
      root + "vereinsverwaltung/tests/support/email-factor-browser-fixture.php",
      purpose,
    ],
    {
      env: {
        ...process.env,
        KH_NATIVE_TEST_CONFIG:
          root + ".local/native-cms-integration/integrated-manager.json",
      },
    },
  );
  return JSON.parse(
    await readFile(
      root + ".local/browser-tests/native-email-code.json",
      "utf8",
    ),
  ).code;
};
const client = async () => {
  const context = await browser.newContext({
    userAgent: "DVM synthetic MFA browser " + crypto.randomUUID(),
  });
  contexts.push(context);
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  return { context, page };
};
const form = (page, operation) =>
  page
    .locator("form")
    .filter({ has: page.locator("[name=operation][value=" + operation + "]") });
const login = async (page, identifier, password) => {
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(identifier);
  await page.getByLabel("Passwort", { exact: true }).fill(password);
  await page
    .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
    .click();
  if (
    await page.getByLabel("Code aus deiner E-Mail", { exact: true }).count()
  ) {
    await page
      .getByLabel("Code aus deiner E-Mail", { exact: true })
      .fill(await deliver("login"));
    await page
      .getByRole("button", { name: "Anmeldung bestätigen", exact: true })
      .click();
  }
  await page.waitForURL("**/vereinsverwaltung/**");
};
const toggle = async (page, enable) => {
  const f = form(page, enable ? "enable-email-factor" : "disable-email-factor");
  await f.locator("[name=current_password]").fill(fixture.password);
  await f.locator("button").click();
  return deliver(enable ? "enable" : "disable");
};
try {
  const member = await client(),
    second = await client(),
    unrelated = await client();
  activePage = member.page;
  await login(member.page, fixture.username, fixture.password);
  await member.page.goto(origin + "/account");
  // A repeated run completes through the same real account workflow, never edits security flags directly.
  if (await form(member.page, "disable-email-factor").count()) {
    const code = await toggle(member.page, false);
    await member.page
      .getByLabel("Code aus deiner E-Mail", { exact: true })
      .fill(code);
    await member.page
      .getByRole("button", { name: "E-Mail-Code bestätigen" })
      .click();
  }
  await login(second.page, fixture.username, fixture.password);
  await login(
    unrelated.page,
    credentials.member.email,
    credentials.member.password,
  );
  const activation = await toggle(member.page, true);
  check(
    (await form(member.page, "enable-email-factor").count()) === 1,
    "activation awaits original email confirmation",
  );
  check(
    !(await member.page.content()).includes(activation),
    "security code absent from HTTP response",
  );
  await member.page
    .getByLabel("Code aus deiner E-Mail", { exact: true })
    .fill("00000000");
  await member.page
    .getByRole("button", { name: "E-Mail-Code bestätigen" })
    .click();
  check(
    (await member.page.getByRole("alert").innerText()).includes(
      "Sicherheitscode",
    ),
    "invalid activation code visibly rejected",
  );
  await member.page
    .getByLabel("Code aus deiner E-Mail", { exact: true })
    .fill(activation);
  await member.page
    .getByRole("button", { name: "E-Mail-Code bestätigen" })
    .click();
  check(
    (await member.page.getByRole("status").innerText()).includes("aktiviert"),
    "actual original account enables email factor",
  );
  await second.page.goto(origin + "/vereinsverwaltung/?view=portal");
  check(
    new URL(second.page.url()).pathname.startsWith("/login"),
    "activation revokes other session of same person",
  );
  await unrelated.page.goto(origin + "/vereinsverwaltung/?view=portal");
  check(
    !new URL(unrelated.page.url()).pathname.startsWith("/login"),
    "another personal account remains authenticated",
  );
  await form(member.page, "logout").locator("button").click();
  await member.page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(fixture.username);
  await member.page
    .getByLabel("Passwort", { exact: true })
    .fill(fixture.password);
  await member.page
    .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
    .click();
  check(
    (await member.page.locator("h2").innerText()) === "Anmeldung bestätigen",
    "password alone displays native second factor",
  );
  const blocked = await member.context.request.get(
    origin + "/vereinsverwaltung/?view=portal",
  );
  check(
    new URL(blocked.url()).pathname.startsWith("/login"),
    "password proof grants no manager session before code",
  );
  const loginCode = await deliver("login");
  for (const width of [1440, 820, 390]) {
    await member.page.setViewportSize({ width, height: 1050 });
    const input = member.page.getByLabel("Code aus deiner E-Mail", {
      exact: true,
    });
    await input.focus();
    check(
      await input.evaluate((el) => document.activeElement === el),
      "email code keyboard focus " + width,
    );
    check(
      await member.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "email factor fits viewport " + width,
    );
  }
  await member.page
    .getByLabel("Code aus deiner E-Mail", { exact: true })
    .fill(loginCode);
  await member.page
    .getByRole("button", { name: "Anmeldung bestätigen", exact: true })
    .click();
  await member.page.waitForURL("**/vereinsverwaltung/**");
  check(
    (await member.page.locator("body").innerText()).includes(fixture.name),
    "delivered login code opens original personal member portal",
  );
  await member.page.goto(origin + "/vereinsverwaltung/?view=portal&tab=bank");
  const save = member.page
    .locator("form")
    .filter({ has: member.page.locator("[name=operation][value=save]") });
  if ((await save.locator("[name=version]").inputValue()) === "0") {
    await save.getByLabel("Kontoinhaber", { exact: true }).fill(fixture.name);
    await save
      .getByLabel("IBAN", { exact: true })
      .fill("DE89370400440532013000");
    await save.locator("[name=password]").fill(fixture.password);
    await save
      .getByRole("button", { name: "Bankdaten sicher speichern" })
      .click();
    await member.page
      .getByLabel("Code aus der E-Mail", { exact: true })
      .waitFor();
    check(
      (await member.page
        .getByLabel("Code aus der E-Mail", { exact: true })
        .count()) === 1,
      "native bank save uses the current email factor",
    );
    await member.page
      .getByLabel("Code aus der E-Mail", { exact: true })
      .fill(await deliver("stepup"));
    await member.page
      .getByRole("button", { name: "Bestätigen und speichern" })
      .click();
  }
  const reveal = member.page
    .locator("form")
    .filter({ has: member.page.locator("[name=operation][value=reveal]") });
  await reveal.locator("[name=password]").fill(fixture.password);
  await reveal
    .getByRole("button", { name: "Bankdaten öffnen", exact: true })
    .click();
  await member.page
    .getByLabel("Code aus der E-Mail", { exact: true })
    .waitFor();
  check(
    (await member.page
      .getByLabel("Code aus der E-Mail", { exact: true })
      .count()) === 1,
    "native bank reveal selects email code instead of Authenticator",
  );
  await member.page
    .getByLabel("Code aus der E-Mail", { exact: true })
    .fill(await deliver("stepup"));
  await member.page
    .getByRole("button", { name: "Bestätigen und Bankdaten öffnen" })
    .click();
  await member.page.locator("[data-bank-revealed-form]").waitFor();
  check(
    (await member.page
      .locator('[data-bank-revealed-form] [name="data[iban]"]')
      .inputValue()) === "DE89370400440532013000",
    "email proof reveals exact original encrypted bank bytes",
  );
  await member.page.reload();
  check(
    (await member.page.locator("[data-bank-revealed-form]").count()) === 0,
    "leaving revealed bank screen masks original values",
  );
  await member.page.goto(origin + "/account");
  const disable = await toggle(member.page, false);
  await member.page
    .getByLabel("Code aus deiner E-Mail", { exact: true })
    .fill(disable);
  await member.page
    .getByRole("button", { name: "E-Mail-Code bestätigen" })
    .click();
  check(
    (await member.page.getByRole("status").innerText()).includes("deaktiviert"),
    "native email disable requires delivered action-specific code",
  );
  await form(member.page, "logout").locator("button").click();
  await login(member.page, fixture.username, fixture.password);
  check(
    (await member.page
      .getByLabel("Code aus deiner E-Mail", { exact: true })
      .count()) === 0,
    "confirmed disable preserves original password-only login",
  );
  check(errors.length === 0, "no browser JavaScript errors");
  await writeFile(
    root + ".local/browser-tests/native-email-factor-report.json",
    JSON.stringify({ checks, passed: true, live_transport: false }, null, 2),
    { mode: 0o600 },
  );
  console.log(
    JSON.stringify({
      checks: checks.length,
      passed: true,
      live_transport: false,
    }),
  );
} catch (error) {
  if (activePage) {
    console.error(
      "Page alerts: " +
        JSON.stringify(await activePage.getByRole("alert").allTextContents()),
    );
  }
  console.error(
    "Last completed browser check: " +
      (checks.at(-1) || "initial authentication"),
  );
  console.error(
    (String(error.message) + "\n" + String(error.stderr || ""))
      .replace(/\b[0-9]{8}\b/g, "[redacted]")
      .replace(/[a-f0-9]{32,}/gi, "[redacted]"),
  );
  process.exitCode = 1;
} finally {
  await Promise.all(contexts.map((c) => c.close()));
  await browser.close();
}
