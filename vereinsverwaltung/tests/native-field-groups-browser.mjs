import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  runtime = root + ".local/native-cms-integration/integrated-manager-runtime/",
  output = root + ".local/browser-tests";
const credentials = JSON.parse(
  await readFile(runtime + "browser-credentials.json", "utf8"),
);
let checks = 0;
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
await mkdir(output, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  for (const standalone of [false, true]) {
    const fixture = JSON.parse(
        await readFile(
          standalone
            ? output + "/field-groups-standalone.json"
            : runtime + "field-groups-browser.json",
          "utf8",
        ),
      ),
      prefix = fixture.prefix,
      origin = standalone ? "http://127.0.0.1:8107" : "http://127.0.0.1:8111",
      name = standalone ? "standalone" : "integrated";
    const context = await browser.newContext(),
      page = await context.newPage(),
      errors = [],
      external = [],
      posts = [];
    await context.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : (external.push(route.request().url()), route.abort()),
    );
    page.on("pageerror", (error) => errors.push(error.message));
    page.on("request", (request) => {
      if (
        request.method() === "POST" &&
        !new URL(request.url()).pathname.startsWith("/login")
      )
        posts.push(request.url());
    });
    if (!standalone) {
      await page.goto(origin + "/login/");
      await page.locator("[name=email]").fill(credentials.admin.email);
      await page.locator("[name=password]").fill(credentials.admin.password);
      await Promise.all([
        page.waitForURL("**/vereinsverwaltung/**"),
        page
          .getByRole("button", {
            name: "Anmelden und Vereinsmanager öffnen",
            exact: true,
          })
          .click(),
      ]);
    }
    const editorUrl =
        origin +
        (standalone ? "/?" : "/vereinsverwaltung/?") +
        new URLSearchParams(
          standalone
            ? { view: "website", item: fixture.editor.id }
            : { view: "website", tab: "pages", native_item: fixture.editor.id },
        ),
      modelUrl =
        origin +
        (standalone
          ? "/?view=model"
          : "/vereinsverwaltung/?view=website&tab=technical");
    const publicUrl =
        origin + "/site/" + fixture.public_slug + (standalone ? "" : "/"),
      imageUrl = standalone
        ? origin + "/media-preview/" + fixture.image + "/original"
        : origin +
          "/vereinsverwaltung/?" +
          new URLSearchParams({
            native_endpoint: "media-preview",
            media: fixture.image,
            variant: "original",
          });
    const original = await context.request.get(publicUrl),
      publicBytes = await original.body(),
      image = await context.request.get(imageUrl),
      imageBytes = await image.body();
    check(
      original.status() === 200 && image.status() === 200,
      name + " actual grouped public original and pixels available",
    );
    const control = (suffix) =>
        page.locator('[name="custom_fields[' + prefix + suffix + ']"]'),
      group = (key) => page.locator('[data-field-group="' + key + '"]');
    const open = async (key) => {
      if (!((await group(key).getAttribute("open")) !== null))
        await group(key).locator(":scope > summary").click();
    };
    const saveForm = () =>
      page.locator("form").filter({
        has: page.locator("input[name=operation][value=page-save]"),
      });
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({ width, height: 1050 });
      await page.goto(editorUrl);
      await page.waitForFunction(
        () =>
          document.querySelector("[data-field-group=conditional]")?.hidden ===
          true,
      );
      check(
        !(await group("conditional").isVisible()),
        name + " empty conditional group hidden " + width,
      );
      check(
        (await group("basics").getAttribute("open")) !== null &&
          (await group("body").getAttribute("open")) === null,
        name + " original collapsed state retained " + width,
      );
      check(
        (await control("active").inputValue()) === "0" &&
          (await control("amount").inputValue()) ===
            fixture.values[prefix + "amount"],
        name + " original false and exact decimal retained " + width,
      );
      await control("active").selectOption("1");
      check(
        (await group("conditional").isVisible()) &&
          (await group("conditional").getAttribute("open")) !== null &&
          (await control("conditional").isVisible()),
        name + " newly required empty field opens actual group " + width,
      );
      await control("conditional").fill("Own pending conditional note");
      await control("active").selectOption("0");
      check(
        !(await group("conditional").isVisible()) &&
          (await control("conditional").inputValue()) ===
            "Own pending conditional note",
        name + " hiding group retains unsaved original " + width,
      );
      await open("body");
      await control("title_note").fill("");
      await group("body").locator(":scope > summary").click();
      const before = posts.length;
      await saveForm()
        .getByRole("button", { name: "Entwurf speichern", exact: true })
        .click();
      await page.waitForFunction(
        (name) => document.activeElement?.getAttribute("name") === name,
        "custom_fields[" + prefix + "title_note]",
      );
      check(
        (await group("body").getAttribute("open")) !== null &&
          (await control("title_note").evaluate(
            (input) => input === document.activeElement,
          )) &&
          posts.length === before,
        name +
          " native required validation reveals collapsed ancestor " +
          width,
      );
      await control("title_note").fill(fixture.values[prefix + "title_note"]);
      await open("format");
      const surface = page.locator(
        '[data-model-field="' + prefix + 'formatted"] [data-richtext-surface]',
      );
      await control("active").selectOption("1");
      await surface.fill("");
      await group("body").locator(":scope > summary").click();
      await saveForm()
        .getByRole("button", { name: "Entwurf speichern", exact: true })
        .click();
      check(
        (await group("body").getAttribute("open")) !== null &&
          (await group("format").getAttribute("open")) !== null &&
          (await surface.evaluate(
            (input) => input === document.activeElement,
          )) &&
          posts.length === before,
        name + " richtext validation reveals all collapsed ancestors " + width,
      );
      await surface.fill("Own corrected formatted text");
      await open("references");
      check(
        (await control("page").inputValue()) === fixture.source &&
          (await control("image").inputValue()) === fixture.image,
        name +
          " deeply grouped original reference identities retained " +
          width,
      );
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        name + " nested Bootstrap fields fit " + width,
      );
      await page.screenshot({
        path: output + "/dvm-field-groups-" + name + "-" + width + ".png",
        fullPage: true,
      });
      await page.goto(modelUrl);
      const templateForm = page.locator("form").filter({
        has: page.locator('input[name=key][value="' + fixture.template + '"]'),
      });
      await templateForm.locator("xpath=ancestor::details[1]/summary").click();
      check(
        (await templateForm.locator("[data-group-row]").count()) === 5 &&
          (await templateForm
            .locator('[name="field_groups[' + prefix + 'image]"]')
            .inputValue()) === "references",
        name + " model builder retains original assignments " + width,
      );
      await templateForm
        .getByRole("button", { name: "Gruppe hinzufügen", exact: true })
        .click();
      const newGroup = templateForm.locator("[data-group-row]").last();
      await newGroup
        .locator("[data-group-label]")
        .fill("Weitere Angaben <wörtlich>");
      const newKey = await newGroup.getAttribute("data-group-key");
      await newGroup.locator('[name$="[parent]"]').selectOption("basics");
      await templateForm
        .locator('[name="field_groups[' + prefix + 'amount]"]')
        .selectOption(newKey);
      check(
        (
          await templateForm
            .locator(
              '[name="field_groups[' + prefix + 'amount]"] option:checked',
            )
            .innerText()
        ).includes("Allgemeine Angaben / Weitere Angaben <wörtlich>"),
        name + " human nested names remain literal " + width,
      );
      await newGroup
        .getByRole("button", { name: "Gruppe entfernen", exact: true })
        .click();
      check(
        (await templateForm.locator("[data-group-row]").count()) === 5 &&
          (await templateForm
            .locator('[name="field_groups[' + prefix + 'amount]"]')
            .inputValue()) === "basics",
        name + " removing group retains assignment in parent " + width,
      );
      await templateForm
        .locator(
          '[data-group-field="' + prefix + 'amount"] [data-field-move=up]',
        )
        .click();
      check(
        (await templateForm
          .locator("[data-group-field]")
          .first()
          .getAttribute("data-group-field")) ===
          prefix + "amount",
        name + " actual field reorder changes submitted order " + width,
      );
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 1,
        ),
        name + " Bootstrap group builder fits " + width,
      );
    }
    // No script fallback keeps original groups and controls reachable through native details.
    const fallback = await browser.newContext({
        javaScriptEnabled: false,
        storageState: await context.storageState(),
      }),
      fallbackPage = await fallback.newPage();
    await fallback.route("**/*", (route) =>
      new URL(route.request().url()).origin === origin
        ? route.continue()
        : (external.push(route.request().url()), route.abort()),
    );
    await fallbackPage.goto(editorUrl);
    for (const key of ["body", "format", "references", "conditional"])
      await fallbackPage
        .locator('[data-field-group="' + key + '"] > summary')
        .click();
    check(
      (await fallbackPage
        .locator('[name="custom_fields[' + prefix + 'conditional]"]')
        .isVisible()) &&
        (await fallbackPage
          .locator('[name="custom_fields[' + prefix + 'image]"]')
          .isVisible()),
      name + " native no-script groups retain every original field",
    );
    await fallback.close();
    // Save a concrete edited definition, then a private draft through their real forms.
    await page.goto(modelUrl);
    const form = page.locator("form").filter({
      has: page.locator('input[name=key][value="' + fixture.template + '"]'),
    });
    await form.locator("xpath=ancestor::details[1]/summary").click();
    for (let index = 0; index < 15; index++)
      await form
        .getByRole("button", { name: "Gruppe hinzufügen", exact: true })
        .click();
    check(
      (await form.locator("[data-group-row]").count()) === 20 &&
        (await form
          .getByRole("button", { name: "Gruppe hinzufügen", exact: true })
          .isDisabled()),
      name + " builder enforces exact twenty-group budget",
    );
    for (let index = 0; index < 15; index++)
      await form
        .locator("[data-group-row]")
        .last()
        .getByRole("button", { name: "Gruppe entfernen", exact: true })
        .click();
    check(
      (await form.locator("[data-group-row]").count()) === 5 &&
        (await form
          .getByRole("button", { name: "Gruppe hinzufügen", exact: true })
          .isEnabled()),
      name +
        " removing temporary groups restores budget without removing original groups",
    );
    await form
      .getByRole("button", { name: "Gruppe hinzufügen", exact: true })
      .click();
    const added = form.locator("[data-group-row]").last();
    await added.locator("[data-group-label]").fill("Zusatzinformationen");
    const addedKey = await added.getAttribute("data-group-key");
    await added.locator('[name$="[parent]"]').selectOption("basics");
    await added.locator("[data-group-move=up]").click();
    check(
      (await form
        .locator("[data-group-row]")
        .nth(4)
        .getAttribute("data-group-key")) === addedKey,
      name + " group reorder changes actual submitted row order",
    );
    await form
      .locator('[name="field_groups[' + prefix + 'amount]"]')
      .selectOption(addedKey);
    await form
      .locator('[data-group-field="' + prefix + 'amount"] [data-field-move=up]')
      .click();
    const posted = page.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        new URL(response.url()).origin === origin,
    );
    await form
      .getByRole("button", { name: "Vorlagenänderung speichern", exact: true })
      .click();
    check(
      (await posted).status() === (standalone ? 200 : 303),
      name + " actual template save succeeds through its own route",
    );
    await page.waitForFunction(
      ({ key, version }) =>
        [...document.querySelectorAll("form")]
          .find((form) => form.querySelector("input[name=key]")?.value === key)
          ?.querySelector("[name=version]").value === String(version),
      { key: fixture.template, version: fixture.template_version + 1 },
    );
    await form.locator("xpath=ancestor::details[1]/summary").click();
    check(
      (await form.locator("[data-group-row]").count()) === 6 &&
        (await form
          .locator("[data-group-row]")
          .nth(4)
          .getAttribute("data-group-key")) === addedKey &&
        (await form
          .locator('[name="field_groups[' + prefix + 'amount]"]')
          .inputValue()) === addedKey &&
        (await form
          .locator("[data-group-field]")
          .first()
          .getAttribute("data-group-field")) ===
          prefix + "amount",
      name +
        " actual persisted layout retains new group and deliberate field order",
    );
    await page.goto(editorUrl);
    const draftPost = page.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        new URL(response.url()).origin === origin,
    );
    await saveForm()
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    check(
      (await draftPost).status() === (standalone ? 200 : 303),
      name + " actual grouped private draft save succeeds",
    );
    await page.waitForFunction(
      (version) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form.querySelector("[name=version]").value,
        ) === version,
      fixture.editor.version + 1,
    );
    check(
      (await control("amount").inputValue()) ===
        fixture.values[prefix + "amount"] &&
        (await control("formatted").inputValue()) ===
          fixture.values[prefix + "formatted"] &&
        (await control("page").inputValue()) === fixture.source &&
        (await control("image").inputValue()) === fixture.image &&
        (await control("active").inputValue()) === "0",
      name +
        " actual new draft preserves exact scalar formatting and reference originals",
    );
    check(
      (await (await context.request.get(publicUrl)).body()).equals(publicBytes),
      name + " all group actions preserve exact approved public bytes",
    );
    check(
      (await (await context.request.get(imageUrl)).body()).equals(imageBytes),
      name + " all group actions preserve original picture bytes",
    );
    check(
      posts.length === 2,
      name + " only two deliberate private form writes occur",
    );
    check(
      errors.length === 0,
      name +
        " shared group modules have no browser errors: " +
        JSON.stringify(errors),
    );
    check(
      external.length === 0,
      name + " no provider or external requests occur",
    );
    await context.close();
  }
  console.log(
    JSON.stringify({
      suite: "native-field-groups-browser",
      checks,
      external_actions: 0,
    }),
  );
} finally {
  await browser.close();
}
