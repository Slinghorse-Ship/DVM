import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { execFileSync } from "node:child_process";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const fixture = JSON.parse(
  await readFile(
    root + ".local/browser-tests/native-member-accounts.json",
    "utf8",
  ),
);
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const checks = [],
  errors = [],
  contexts = [];
const check = (ok, label) => {
  assert(ok, label);
  checks.push(label);
};
const client = async () => {
  const context = await browser.newContext();
  contexts.push(context);
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));
  return { context, page };
};
const login = async (page, person) => {
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(person.username);
  await page.getByLabel("Passwort", { exact: true }).fill(person.password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
};
try {
  const original = await client(),
    relative = await client(),
    reset = await client();
  await login(original.page, fixture.people[0]);
  await login(relative.page, fixture.people[1]);
  await reset.page.goto(origin + "/forgot");
  await reset.page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill("native-browser-family@example.invalid");
  await Promise.all([
    reset.page.waitForNavigation(),
    reset.page.getByRole("button", { name: "Zurücksetzen anfordern" }).click(),
  ]);
  const ambiguousNotice = await reset.page.getByRole("status").innerText();
  await reset.page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(fixture.people[0].username);
  await Promise.all([
    reset.page.waitForNavigation(),
    reset.page.getByRole("button", { name: "Zurücksetzen anfordern" }).click(),
  ]);
  check(
    (await reset.page.getByRole("status").innerText()) === ambiguousNotice,
    "anonymous reset response reveals neither existence nor ambiguous family target",
  );
  execFileSync(
    process.env.DVM_PHP || "/workspace/.setup/bin/php",
    [
      root +
        "vereinsverwaltung/tests/support/account-reset-browser-fixture.php",
    ],
    {
      env: {
        ...process.env,
        KH_NATIVE_TEST_CONFIG:
          root + ".local/native-cms-integration/integrated-manager.json",
      },
      stdio: ["ignore", "ignore", "pipe"],
    },
  );
  const input = JSON.parse(
    await readFile(
      root + ".local/browser-tests/native-account-reset.json",
      "utf8",
    ),
  );
  await reset.page.goto(origin + "/reset?token=" + input.token);
  for (const width of [1440, 820, 390]) {
    await reset.page.setViewportSize({ width, height: 1000 });
    const password = reset.page.getByLabel("Neues Passwort", { exact: true });
    await password.focus();
    check(
      await password.evaluate((el) => document.activeElement === el),
      "reset password accepts keyboard focus " + width,
    );
    check(
      await reset.page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "reset form fits viewport " + width,
    );
  }
  await reset.page
    .getByLabel("Neues Passwort", { exact: true })
    .fill(fixture.people[0].password);
  await reset.page
    .getByLabel("Passwort wiederholen")
    .fill(fixture.people[0].password);
  await Promise.all([
    reset.page.waitForNavigation(),
    reset.page
      .getByRole("button", { name: "Neues Passwort setzen", exact: true })
      .click(),
  ]);
  check(
    (await reset.page.getByRole("status").innerText()).includes(
      "wurde geändert",
    ),
    "real protected reset link changes personal password",
  );
  await original.page.goto(origin + "/vereinsverwaltung/?view=portal");
  check(
    new URL(original.page.url()).pathname.startsWith("/login"),
    "reset revokes the original account session",
  );
  await relative.page.goto(origin + "/vereinsverwaltung/?view=portal");
  check(
    (await relative.page.locator("body").innerText()).includes(
      fixture.people[1].name,
    ),
    "other family account session remains valid",
  );
  await login(reset.page, fixture.people[0]);
  check(
    (await reset.page.locator("body").innerText()).includes(
      fixture.people[0].name,
    ),
    "reset account reauthenticates into its original profile",
  );
  await original.page.goto(origin + "/reset?token=" + input.token);
  await original.page
    .getByLabel("Neues Passwort", { exact: true })
    .fill(fixture.people[0].password);
  await original.page
    .getByLabel("Passwort wiederholen")
    .fill(fixture.people[0].password);
  await Promise.all([
    original.page.waitForNavigation(),
    original.page
      .getByRole("button", { name: "Neues Passwort setzen", exact: true })
      .click(),
  ]);
  check(
    (await original.page.getByRole("alert").count()) === 1,
    "consumed reset link cannot be replayed",
  );
  check(errors.length === 0, "no browser JavaScript errors");
  await writeFile(
    root + ".local/browser-tests/native-account-reset-report.json",
    JSON.stringify({ checks, passed: true, live_transport: false }, null, 2),
  );
  console.log(
    JSON.stringify({
      checks: checks.length,
      passed: true,
      live_transport: false,
    }),
  );
} catch (error) {
  console.error(
    String(error.message)
      .replace(/([?&]token=)[^\s"']+/g, "$1[redacted]")
      .replace(/[a-f0-9]{32,}/gi, "[redacted]"),
  );
  process.exitCode = 1;
} finally {
  await Promise.all(contexts.map((context) => context.close()));
  await browser.close();
}
