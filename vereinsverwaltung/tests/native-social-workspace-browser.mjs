import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  origin = "http://127.0.0.1:8111";
const credentials = JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
);
const out = root + ".local/browser-tests";
await mkdir(out, { recursive: true, mode: 0o700 });
let n = 0;
const check = (ok, label) => {
  assert(ok, label);
  n++;
  console.log("PASS " + label);
};
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  const context = await browser.newContext({storageState:process.env.SOCIAL_WORKSPACE_STORAGE_DIR?JSON.parse(await readFile(process.env.SOCIAL_WORKSPACE_STORAGE_DIR+"/admin.json","utf8")):undefined}),
    external = [],
    errors = [];
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : (external.push(route.request().url()), route.abort()),
  );
  const page = await context.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  const login = async (p, key) => {
    await p.goto(origin + "/login/");
    await p.locator("[name=email]").fill(credentials[key].email);
    await p.locator("[name=password]").fill(credentials[key].password);
    await Promise.all([
      p.waitForURL("**/vereinsverwaltung/**"),
      p
        .getByRole("button", {
          name: "Anmelden und Vereinsmanager öffnen",
          exact: true,
        })
        .click(),
    ]);
  };
  if(!process.env.SOCIAL_WORKSPACE_STORAGE_DIR) await login(page, "admin");
  else await page.goto(origin + '/vereinsverwaltung/');
  const framework = await page.evaluate(async () => {
    const script = document.querySelector(
        "script[type=module][src*=bootstrap]",
      ),
      m = await import(script.src),
      manifest = JSON.parse(
        document.querySelector("meta[name=manager-navigation]").content,
      );
    const element = document.createElement("div");
    element.className = "collapse";
    element.textContent = "Synthetic framework component";
    document.body.append(element);
    const collapse = new m.Collapse(element, { toggle: false });
    const shown = new Promise((resolve) =>
      element.addEventListener("shown.bs.collapse", resolve, { once: true }),
    );
    collapse.show();
    await shown;
    const visible = getComputedStyle(element).display !== "none";
    collapse.dispose();
    element.remove();
    return {
      version: m.Dialog.VERSION,
      module: manifest.assets["bootstrap-6.0.0-alpha.1.bundle.min.js"].module,
      visible,
      count: document.querySelectorAll("script[src*=bootstrap][type=module]")
        .length,
    };
  });
  check(
    framework.version === "6.0.0-alpha.1",
    "actual local Bootstrap module exports the requested alpha version",
  );
  check(
    framework.module && framework.count === 1,
    "manager lifecycle registers Bootstrap as one ES module",
  );
  check(
    framework.visible,
    "real Bootstrap Collapse component opens and disposes without app errors",
  );
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    let response = await page.goto(
      origin + "/vereinsverwaltung/?view=website&tab=integrations",
    );
    check(response.status() === 200, "account settings load " + width);
    for (const provider of ["meta", "stadtlandfunk"]) {
      const panel = page.locator("[data-connection=" + provider + "]");
      await panel.locator(":scope>summary").click();
      for (const input of await panel.locator("input[type=password]").all())
        check(
          (await input.inputValue()) === "",
          "stored secret stays out of password field " + provider + " " + width,
        );
    }
    check(
      (await page
        .locator("[data-connection=meta] input[name=page_id]")
        .inputValue()) === "123",
      "editable public Meta account details prefilled " + width,
    );
    check(
      (
        await page.locator("[data-connection=stadtlandfunk]").innerText()
      ).includes("Synthetischer Testort"),
      "actual configured destination shown " + width,
    );
    check(
      !/Synthetic-browser-(?:meta|personal|access|refresh)-secret/.test(
        await page.content(),
      ),
      "HTML contains no stored provider secret " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "account settings fit " + width,
    );
    await page.screenshot({
      path: out + "/social-accounts-" + width + ".png",
      fullPage: true,
    });
    response = await page.goto(
      origin + "/vereinsverwaltung/?view=social&section=review",
    );
    check(response.status() === 200, "private review page loads " + width);
    check(
      await page.locator(".workspace-tabs").evaluate((nav) =>
        [...nav.querySelectorAll("a")].every((a) => {
          const r = a.getBoundingClientRect(),
            n = nav.getBoundingClientRect();
          return r.left >= n.left - 1 && r.right <= n.right + 1;
        }),
      ),
      "all social workspace tabs fit without hidden horizontal items " + width,
    );
    const original = page.locator('.chat-choice').filter({hasText:'Wann öffnet der Saal?'}).first();
    check(await original.count()===1,'actual encrypted private comment appears for authorized user '+width);
    await original.click();
    await page.locator('.chat-person').waitFor();
    check(await page.locator('.social-chat script').count()===0,'provider comment markup is escaped '+width);
    await page.locator('.chat-options>summary').click();
    await page.getByText('Abrufzeiten',{exact:true}).click();
    for (const channel of ["meta", "stadtlandfunk"])
      await page.locator("[data-check-plan=" + channel + "]>summary").click();
    check(
      (await page
        .locator("[data-check-plan=meta] input[name=interval_seconds]")
        .count()) === 1,
      "Meta has its own regular API cadence " + width,
    );
    check(
      (await page
        .locator("[data-check-plan=stadtlandfunk] input[name=morning_start]")
        .count()) === 1,
      "personal channel exposes daily windows " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "review and both plans fit " + width,
    );
    await page.screenshot({
      path: out + "/social-review-" + width + ".png",
      fullPage: true,
    });
    response = await page.goto(
      origin +
        "/vereinsverwaltung/?view=social&section=events&source=manager&q=Kalender-Social-Browsertermin",
    );
    check(response.status() === 200, "original calendar source loads " + width);
    check(
      (await page.locator("select[name=source]").inputValue()) === "manager" &&
        (await page.locator(".social-event-source").count()) === 1 &&
        (await page.locator(".social-event-source h3").innerText()) ===
          "Kalender-Social-Browsertermin",
      "original calendar picker preserves source and literal search " + width,
    );
    check(
      (await page.locator("input[name=internal_confirm]").count()) === 1 &&
        (await page
          .locator("input[name=internal_confirm]")
          .evaluate((e) => e.required && !e.checked)),
      "internal calendar preparation has explicit unchecked confirmation " +
        width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "calendar source filter and preparation fit " + width,
    );
    await page.screenshot({
      path: out + "/social-calendar-source-" + width + ".png",
      fullPage: true,
    });
  }
  await page.goto(origin + "/vereinsverwaltung/?view=social&section=review");
  await page.locator('.chat-choice').filter({hasText:'Wann öffnet der Saal?'}).first().click();
  await page.locator('.chat-person').waitFor();
  const review=page.locator('form').filter({has:page.locator('[name=operation][value=social-attention-state]')});
  await review.locator('xpath=..').locator('summary').click();
  const reviewVersion=Number(await review.locator('[name=attention_version]').inputValue());
  await review.locator('[name=attention_state]').selectOption('reply_needed');
  await review.getByRole('button',{name:'Übernehmen',exact:true}).click();
  await page.waitForFunction(expected=>Number(document.querySelector('[name=operation][value=social-attention-state]')?.form.querySelector('[name=attention_version]')?.value)===expected,reviewVersion+1);
  check(
    new URL(page.url()).searchParams.get("section") === "review",
    "private review save retains workspace tab",
  );
  await page.locator(".chat-options>summary").click();
  await page.getByText("Abrufzeiten",{exact:true}).click();
  const personal = page.locator("[data-check-plan=stadtlandfunk]");
  if (!(await personal.evaluate((e) => e.open)))
    await personal.locator(":scope>summary").click();
  const planVersion = Number(
    await personal.locator("input[name=plan_version]").inputValue(),
  );
  await personal.locator("input[name=enabled]").check();
  await personal.locator("input[name=midday]").uncheck();
  await personal
    .getByRole("button", { name: "Zeiten speichern", exact: true })
    .click();
  await page.waitForFunction(
    (expected) =>
      Number(
        document.querySelector(
          "[data-check-plan=stadtlandfunk] input[name=plan_version]",
        )?.value,
      ) === expected,
    planVersion + 1,
  );
  check(
    new URL(page.url()).searchParams.get("section") === "review",
    "plan save retains review tab",
  );
  const saved = page.locator("[data-check-plan=stadtlandfunk]");
  check(
    !(await saved.locator("input[name=midday]").isChecked()) &&
      (await saved.locator("input[name=enabled]").isChecked()),
    "two-window personal plan saved through real authenticated form",
  );
  await page.goto(origin + "/vereinsverwaltung/?view=social&section=events");
  const source = page
    .locator(".social-event-source")
    .filter({ hasText: "Eigener Social-Browsertermin" });
  check(
    (await source.count()) === 1,
    "own event available without Eventbrite connection",
  );
  await source
    .getByRole("button", { name: "Social-Entwurf vorbereiten", exact: true })
    .click();
  await page.waitForFunction(
    () => !!document.querySelector(".social-source-note"),
  );
  check(
    new URL(page.url()).searchParams.get("section") === "compose",
    "event draft opens ordinary composer",
  );
  check(
    await page.locator(".social-source-note").isVisible(),
    "immutable original note visible outside collapsed draft library",
  );
  check(
    (await page.locator("input[name=title]").inputValue()) ===
      "Eigener Social-Browsertermin",
    "real source title becomes editable private draft",
  );
  check(
    !(await page.locator("body").innerText()).includes(
      "Beitrag veröffentlicht",
    ),
    "event preparation does not claim provider publication",
  );
  await page.screenshot({
    path: out + "/social-own-event-composer-390.png",
    fullPage: true,
  });
  await page.goto(
    origin +
      "/vereinsverwaltung/?view=social&section=events&source=manager&q=Kalender-Social-Browsertermin",
  );
  const calendarSource = page.locator(".social-event-source"),
    calendarForm = calendarSource.locator("form");
  const calendarUnconfirmed = await calendarForm.evaluate((e) => [
    ...new FormData(e).entries(),
  ]);
  const calendarRejection = await context.request.post(
    origin + "/vereinsverwaltung/?view=social&section=events",
    {
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      data: new URLSearchParams(calendarUnconfirmed).toString(),
    },
  );
  check(
    calendarRejection.status() === 422,
    "server rejects internal calendar preparation without intentional confirmation",
  );
  await calendarForm.locator("input[name=internal_confirm]").check();
  await calendarForm
    .getByRole("button", { name: "Social-Entwurf vorbereiten", exact: true })
    .click();
  await page.waitForFunction(() =>
    document
      .querySelector(".social-source-note")
      ?.textContent.includes("Kalenderfassung"),
  );
  check(
    new URL(page.url()).searchParams.get("section") === "compose",
    "confirmed calendar original opens the ordinary private composer",
  );
  check(
    (await page.locator("input[name=title]").inputValue()) ===
      "Kalender-Social-Browsertermin",
    "actual original calendar title is retained in the draft",
  );
  check(
    (await page.locator("[data-richtext-surface]").innerText()).includes(
      "Originaler Termin aus dem Vereinskalender.",
    ) &&
      (await page.locator("[data-richtext-surface]").innerText()).includes(
        "Eigener Vereinssaal",
      ),
    "calendar original details and place reach the editable caption",
  );
  check(
    !(await page.locator("body").innerText()).includes(
      "Beitrag veröffentlicht",
    ),
    "calendar preparation retains private draft status",
  );
  await page.screenshot({
    path: out + "/social-calendar-composer-390.png",
    fullPage: true,
  });
  await page.goto(origin + "/vereinsverwaltung/?view=website&tab=integrations");
  let panel = page.locator("[data-connection=meta]");
  await panel.locator(":scope>summary").click();
  let edit = panel.locator("form").filter({
      has: page.locator("input[name=operation][value=api-account]"),
    }),
    accountVersion = Number(
      await edit.locator("input[name=version]").inputValue(),
    );
  await edit.locator("input[name=api_version]").fill("v26.0");
  await edit
    .getByRole("button", { name: "Verbindung speichern", exact: true })
    .click();
  await page.waitForFunction(
    (expected) =>
      Number(
        document
          .querySelector(
            "[data-connection=meta] input[name=operation][value=api-account]",
          )
          ?.form.querySelector("input[name=version]")?.value,
      ) === expected,
    accountVersion + 1,
  );
  check(
    (await page
      .locator("[data-connection=meta] input[name=api_version]")
      .inputValue()) === "v26.0",
    "Meta public detail edit saves through authenticated form",
  );
  check(
    (await page
      .locator("[data-connection=meta] input[name=token]")
      .inputValue()) === "",
    "retained token remains absent after account edit",
  );
  panel = page.locator("[data-connection=meta]");
  if (!(await panel.evaluate((e) => e.open)))
    await panel.locator(":scope>summary").click();
  await panel.locator("details>summary").click();
  const disconnect = panel.locator("form").filter({
    has: page.locator("input[name=operation][value=api-account-disconnect]"),
  });
  const unconfirmed = await disconnect.evaluate((e) => [
    ...new FormData(e).entries(),
  ]);
  const rejection = await context.request.post(
    origin + "/vereinsverwaltung/?view=website&tab=integrations",
    {
      headers: {
        Accept: "application/json",
        "Content-Type": "application/x-www-form-urlencoded",
      },
      data: new URLSearchParams(unconfirmed).toString(),
    },
  );
  check(
    rejection.status() === 422,
    "server rejects disconnect without explicit removal checkbox",
  );
  await disconnect.locator("input[name=disconnect_confirm]").check();
  await disconnect
    .getByRole("button", { name: "Verbindung trennen", exact: true })
    .click();
  await page.waitForFunction(() =>
    document
      .querySelector("[data-connection=meta]>summary")
      ?.textContent.includes("nicht verbunden"),
  );
  check(
    (await page
      .locator("[data-connection=meta] input[name=page_id]")
      .inputValue()) === "",
    "disconnect removes saved Meta target details from form",
  );
  const memberContext = await browser.newContext();
  await memberContext.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const member = await memberContext.newPage();
  await login(member, "member");
  check(
    (
      await member.goto(
        origin + "/vereinsverwaltung/?view=social&section=review",
      )
    ).status() === 403,
    "member without private grant denied by server",
  );
  check(errors.length === 0, "no browser script errors");
  check(
    external.length === 0,
    "account and review UI request no external assets",
  );
  console.log(
    n +
      " native social workspace browser checks passed; synthetic credentials and no live provider action.",
  );
} finally {
  await browser.close();
}
