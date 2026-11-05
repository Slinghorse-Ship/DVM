import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  runtime = root + ".local/native-cms-integration/integrated-manager-runtime/";
const fixture = JSON.parse(
    await readFile(runtime + "field-conditions-browser.json", "utf8"),
  ),
  credentials = JSON.parse(
    await readFile(runtime + "browser-credentials.json", "utf8"),
  );
const origin = "http://127.0.0.1:8111",
  output = root + ".local/browser-tests",
  prefix = fixture.prefix;
const editorUrl =
    origin +
    "/vereinsverwaltung/?" +
    new URLSearchParams({
      view: "website",
      tab: "pages",
      native_item: fixture.editor.id,
    }),
  modelUrl = origin + "/vereinsverwaltung/?view=website&tab=technical";
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
  const publicUrl = origin + "/site/" + fixture.public_slug + "/",
    imageUrl =
      origin +
      "/vereinsverwaltung/?" +
      new URLSearchParams({
        native_endpoint: "media-preview",
        media: fixture.image,
        variant: "original",
      });
  const original = await context.request.get(publicUrl),
    publicBytes = await original.body(),
    pixels = await context.request.get(imageUrl),
    imageBytes = await pixels.body();
  check(
    original.status() === 200 && pixels.status() === 200,
    "actual original conditional publication and original pixels available",
  );
  const control = (suffix) =>
    page.locator('[name="custom_fields[' + prefix + suffix + ']"]');
  const field = (suffix) =>
    page.locator('[data-model-field="' + prefix + suffix + '"]');
  const required = async (suffix) =>
    await control(suffix).evaluate((input) => input.required);
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    await page.goto(editorUrl);
    await page.waitForFunction(
      (prefix) =>
        document.querySelector('[data-model-field="' + prefix + 'details"]')
          ?.hidden === true,
      prefix,
    );
    check(
      !(await field("details").isVisible()) &&
        !(await field("rich_note").isVisible()),
      "actual hidden conditional originals " + width,
    );
    check(
      (await control("details").inputValue()) === "Original hidden details" &&
        (await control("rich_note").inputValue()) ===
          "<p>Original hidden rich note</p>",
      "hidden original text and formatting retained " + width,
    );
    check(
      !(await required("details")) && !(await required("reply")),
      "hidden and inactive requirements removed from native inputs " + width,
    );
    check(
      await field("caption").isVisible(),
      "visible formatted literal angle characters match as text " + width,
    );
    check(
      (await field("page_note").isVisible()) &&
        (await field("image_note").isVisible()),
      "actual page equality and picture filled source rules " + width,
    );
    check(
      (await control("active").inputValue()) === "0",
      "native original false select retained " + width,
    );
    await control("active").selectOption("1");
    check(
      !(await field("details").isVisible()) &&
        (await field("rich_note").isVisible()),
      "AND visibility waits for every source " + width,
    );
    await control("count").fill("10");
    check(
      (await field("details").isVisible()) && (await required("details")),
      "integer boundary activates original required field " + width,
    );
    check(
      (await field("details").locator(".hint").last().innerText()).includes(
        "Pflichtfeld",
      ),
      "required hint follows actual field state " + width,
    );
    await control("details").fill("");
    check(
      await control("details").evaluate((input) => input.validity.valueMissing),
      "actual browser validates newly required empty input " + width,
    );
    await control("details").fill("Original hidden details");
    await control("count").fill("11");
    check(
      (await field("rich_note")
        .locator("[data-richtext-surface]")
        .getAttribute("aria-required")) === "true",
      "formatted conditional requirement changes accessible state " + width,
    );
    await control("active").selectOption("0");
    check(
      !(await field("details").isVisible()) &&
        !(await required("details")) &&
        (await control("details").inputValue()) === "Original hidden details",
      "hiding retains original value and clears browser requirement " + width,
    );
    await control("category").selectOption("Musik");
    check(
      await required("reply"),
      "OR category makes original reply required " + width,
    );
    await control("category").selectOption("Theater");
    await control("amount").fill("999999999999999999.999998");
    check(
      !(await required("reply")),
      "exact adjacent 24 digit decimal remains optional " + width,
    );
    await control("amount").fill("999999999999999999.999999");
    check(
      await required("reply"),
      "exact one micro-unit activates decimal OR requirement " + width,
    );
    await control("amount").fill("0");
    check(
      !(await required("reply")),
      "returning original amount removes conditional requirement " + width,
    );
    await control("day").fill("2026-10-09");
    check(
      !(await field("date_note").isVisible()) &&
        (await control("date_note").inputValue()) === "Original date note",
      "date boundary hides without deleting stored original " + width,
    );
    await control("day").fill("2026-10-10");
    check(
      await field("date_note").isVisible(),
      "date boundary restores original field " + width,
    );
    await control("page").selectOption("");
    check(
      !(await field("page_note").isVisible()),
      "page source empty deactivates identity comparison " + width,
    );
    await control("page").selectOption(fixture.source.id);
    await control("image").selectOption("");
    check(
      !(await field("image_note").isVisible()),
      "picture source empty deactivates filled rule " + width,
    );
    await control("image").selectOption(fixture.image);
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "conditional original editor fits " + width,
    );
    await page.screenshot({
      path: output + "/dvm-conditional-fields-" + width + ".png",
      fullPage: true,
    });
  }
  await page.goto(editorUrl);
  const saveForm = page
    .locator("form")
    .filter({ has: page.locator("input[name=operation][value=page-save]") });
  const forged = await saveForm.evaluate((form) =>
    Object.fromEntries(new FormData(form).entries()),
  );
  forged["custom_fields[" + prefix + "active]"] = "1";
  forged["custom_fields[" + prefix + "count]"] = "10";
  forged["custom_fields[" + prefix + "details]"] = "";
  const refused = await context.request.post(editorUrl, {
    form: forged,
    headers: { Accept: "application/json" },
  });
  check(
    refused.status() === 422,
    "actual forged HTTP request cannot bypass conditional required validation",
  );
  await page.goto(editorUrl);
  check(
    Number(await saveForm.locator("[name=version]").inputValue()) ===
      fixture.editor.version,
    "rejected conditional HTTP write retains original version",
  );
  const posted = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/vereinsverwaltung/",
  );
  const loaded = page.waitForResponse(
    (response) =>
      response.request().isNavigationRequest() &&
      response.request().method() === "GET" &&
      new URL(response.url()).searchParams.get("native_item") ===
        fixture.editor.id,
  );
  await saveForm
    .getByRole("button", { name: "Entwurf speichern", exact: true })
    .click();
  check(
    (await posted).status() === 303,
    "actual hidden value private save follows original HTTP contract",
  );
  check(
    (await loaded).status() === 200,
    "saved conditional editor loads through actual redirect",
  );
  await page.waitForFunction(
    ({ id, version }) =>
      Number(
        document
          .querySelector("input[name=operation][value=page-save]")
          ?.form.querySelector("[name=version]").value,
      ) === version,
    { id: fixture.editor.id, version: fixture.editor.version + 1 },
  );
  check(
    (await control("details").inputValue()) === "Original hidden details" &&
      (await control("rich_note").inputValue()) ===
        "<p>Original hidden rich note</p>",
    "actual saved new revision retains both hidden originals",
  );
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
  check(
    (await fallbackPage
      .locator('[data-model-field="' + prefix + 'details"]')
      .isVisible()) &&
      (await fallbackPage
        .locator('[data-model-field="' + prefix + 'rich_note"]')
        .isVisible()),
    "actual no-JavaScript fallback retains every original field",
  );
  await fallback.close();
  await page.goto(modelUrl);
  const ruleForm = page.locator("form").filter({
    has: page.locator('input[name=key][value="' + prefix + 'rule"]'),
  });
  await ruleForm.locator("xpath=ancestor::details[1]/summary").click();
  await ruleForm
    .getByText("Anzeige und Pflicht nach anderen Angaben", { exact: true })
    .click();
  const editor = ruleForm.locator("[data-condition-kind=show_when]"),
    row = () => editor.locator("[data-condition-row]").first();
  check(
    (await editor.locator("[data-condition-enabled]").isChecked()) &&
      (await row().locator('[name$="[value]"]').inputValue()) === "1",
    "saved Boolean schema rule retains typed original comparison",
  );
  await row()
    .locator('[name$="[field]"]')
    .selectOption(prefix + "category");
  await row().locator('[name$="[operator]"]').selectOption("contains");
  check(
    (await row().locator('[name$="[value]"]').getAttribute("type")) === "text",
    "select substring condition uses human free text comparison",
  );
  await row().locator('[name$="[value]"]').fill("us");
  await row()
    .locator('[name$="[field]"]')
    .selectOption(prefix + "page");
  check(
    (await row().locator("[data-reference-picker]").count()) === 1,
    "schema page comparison reuses shared reference search",
  );
  await row()
    .locator("[data-reference-query]")
    .fill("Own browser conditional referenced original");
  await row().locator("[data-reference-query]").press("Enter");
  await row()
    .locator("[data-reference-status]")
    .filter({ hasText: "1 Treffer · Seite 1 von 1" })
    .waitFor();
  await row()
    .locator("[data-reference-select]")
    .selectOption(fixture.source.id);
  await row().locator('[name$="[operator]"]').selectOption("ne");
  check(
    (await row().locator("[data-reference-select]").inputValue()) ===
      fixture.source.id,
    "operator change retains selected original page comparison",
  );
  check(
    await row().locator("[data-reference-action=previous]").isDisabled(),
    "schema reference operator change retains actual first-page boundary",
  );
  await row().locator('[name$="[operator]"]').selectOption("filled");
  check(
    !(await row().locator("[data-condition-value]").isVisible()),
    "filled comparison removes unnecessary value controls",
  );
  await editor
    .getByRole("button", { name: "Weitere Bedingung", exact: true })
    .click();
  const second = editor.locator("[data-condition-row]").nth(1);
  await second.locator('[name$="[field]"]').selectOption(prefix + "amount");
  await second.locator('[name$="[operator]"]').selectOption("gt");
  await second.locator('[name$="[value]"]').fill("12,5");
  await row()
    .getByRole("button", { name: "Bedingung entfernen", exact: true })
    .click();
  check(
    (await editor.locator("[data-condition-row]").count()) === 1 &&
      (await row().locator('[name$="[field]"]').getAttribute("name")) ===
        "show_when[conditions][1][field]",
    "removed schema row preserves remaining original comparison",
  );
  await editor
    .getByRole("button", { name: "Weitere Bedingung", exact: true })
    .click();
  const dateRow = editor.locator("[data-condition-row]").nth(1);
  await dateRow.locator('[name$="[field]"]').selectOption(prefix + "day");
  await dateRow.locator('[name$="[operator]"]').selectOption("gte");
  await dateRow.locator('[name$="[value]"]').fill("2026-10-10");
  await editor.locator('select[name="show_when[match]"]').selectOption("any");
  for (let i = 0; i < 8; i++)
    await editor
      .getByRole("button", { name: "Weitere Bedingung", exact: true })
      .click();
  check(
    (await editor.locator("[data-condition-row]").count()) === 10 &&
      (await editor
        .getByRole("button", { name: "Weitere Bedingung", exact: true })
        .isDisabled()),
    "schema builder enforces actual ten-condition budget",
  );
  for (let i = 0; i < 8; i++)
    await editor
      .locator("[data-condition-row]")
      .last()
      .getByRole("button", { name: "Bedingung entfernen", exact: true })
      .click();
  check(
    (await dateRow.locator('[name$="[value]"]').getAttribute("type")) ===
      "date",
    "date condition provides native date comparison",
  );
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "human Bootstrap condition builder fits " + width,
    );
  }
  check(
    await ruleForm.evaluate((form) => form.checkValidity()),
    "actual completed schema form passes browser validation " +
      JSON.stringify(
        await ruleForm.evaluate((form) =>
          [...form.elements]
            .filter((input) => input.validity && !input.validity.valid)
            .map((input) => ({ name: input.name, value: input.value })),
        ),
      ),
  );
  const schemaPost = page.waitForResponse(
    (response) =>
      response.request().method() === "POST" &&
      new URL(response.url()).pathname === "/vereinsverwaltung/",
  );
  const schemaLoad = page.waitForResponse(
    (response) =>
      response.request().isNavigationRequest() &&
      response.request().method() === "GET" &&
      new URL(response.url()).searchParams.get("tab") === "technical",
  );
  await ruleForm
    .getByRole("button", { name: "Feldänderung speichern", exact: true })
    .click();
  const schemaResponse = await schemaPost;
  console.log(
    JSON.stringify({
      schema_status: schemaResponse.status(),
      schema_location: schemaResponse.headers().location || "",
    }),
  );
  check(
    schemaResponse.status() === 303,
    "actual schema POST returns expected redirect",
  );
  check(
    (await schemaLoad).status() === 200,
    "actual schema form saves its own private rule version",
  );
  await page.waitForFunction(
    ({ key, version }) =>
      [...document.querySelectorAll("form")]
        .find((form) => form.querySelector("input[name=key]")?.value === key)
        ?.querySelector("input[name=version]").value === String(version),
    { key: prefix + "rule", version: fixture.rule_version + 1 },
  );
  await ruleForm.locator("xpath=ancestor::details[1]/summary").click();
  await ruleForm
    .getByText("Anzeige und Pflicht nach anderen Angaben", { exact: true })
    .click();
  check(
    (await editor.locator('select[name="show_when[match]"]').inputValue()) ===
      "any" && (await editor.locator("[data-condition-row]").count()) === 2,
    "actual saved OR group retains both ordered conditions",
  );
  check(
    (await row().locator('[name$="[value]"]').inputValue()) === "12.5" &&
      (await editor
        .locator("[data-condition-row]")
        .nth(1)
        .locator('[name$="[value]"]')
        .inputValue()) === "2026-10-10",
    "saved typed decimal comma and date comparisons reopen exactly",
  );
  check(
    (await (await context.request.get(publicUrl)).body()).equals(publicBytes),
    "all field condition and schema actions retain exact published original bytes",
  );
  check(
    (await (await context.request.get(imageUrl)).body()).equals(imageBytes),
    "all condition actions retain actual original picture bytes",
  );
  check(
    posts.length === 2,
    "only explicit own content and rule form saves mutate originals",
  );
  const standaloneFixture = JSON.parse(
      await readFile(output + "/field-conditions-standalone.json", "utf8"),
    ),
    standaloneOrigin = "http://127.0.0.1:8107";
  const standalone = await browser.newContext(),
    solo = await standalone.newPage(),
    standalonePosts = [];
  await standalone.route("**/*", (route) =>
    new URL(route.request().url()).origin === standaloneOrigin
      ? route.continue()
      : (external.push(route.request().url()), route.abort()),
  );
  solo.on("pageerror", (error) => errors.push(error.message));
  solo.on("request", (request) => {
    if (request.method() === "POST") standalonePosts.push(request.url());
  });
  const standalonePublicUrl =
    standaloneOrigin + "/site/" + standaloneFixture.public_slug;
  const standaloneOriginal = await standalone.request.get(standalonePublicUrl),
    standaloneBytes = await standaloneOriginal.body();
  check(
    standaloneOriginal.status() === 200,
    "standalone conditional original publication available",
  );
  check(
    (
      await standalone.request.get(
        standaloneOrigin + "/assets/field-conditions.js",
      )
    ).status() === 200,
    "standalone actual shared condition asset available",
  );
  for (const width of [1440, 820, 390]) {
    await solo.setViewportSize({ width, height: 1050 });
    await solo.goto(
      standaloneOrigin +
        "/?" +
        new URLSearchParams({
          view: "website",
          item: standaloneFixture.editor.id,
        }),
    );
    await solo.waitForFunction(
      (prefix) =>
        document.querySelector('[data-model-field="' + prefix + 'details"]')
          ?.hidden === true,
      prefix,
    );
    const ownControl = (suffix) =>
        solo.locator('[name="custom_fields[' + prefix + suffix + ']"]'),
      ownField = (suffix) =>
        solo.locator('[data-model-field="' + prefix + suffix + '"]');
    check(
      !(await ownField("details").isVisible()) &&
        (await ownField("caption").isVisible()),
      "standalone shared visibility and literal formatted comparison " + width,
    );
    await ownControl("active").selectOption("1");
    await ownControl("count").fill("10");
    check(
      (await ownField("details").isVisible()) &&
        (await ownControl("details").evaluate((input) => input.required)),
      "standalone actual AND conditional requirement " + width,
    );
    await ownControl("active").selectOption("0");
    check(
      !(await ownField("details").isVisible()) &&
        (await ownControl("details").inputValue()) ===
          "Original hidden details",
      "standalone hide preserves original " + width,
    );
    await ownControl("amount").fill("999999999999999999.999998");
    check(
      !(await ownControl("reply").evaluate((input) => input.required)),
      "standalone exact adjacent decimal remains optional " + width,
    );
    await ownControl("amount").fill("999999999999999999.999999");
    check(
      await ownControl("reply").evaluate((input) => input.required),
      "standalone exact decimal OR requirement " + width,
    );
    check(
      await solo.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "standalone shared conditional form fits " + width,
    );
    await solo.goto(standaloneOrigin + "/?view=model");
    const ownForm = solo
      .locator("form")
      .filter({
        has: solo.locator('input[name=key][value="' + prefix + 'rule"]'),
      });
    await ownForm.locator("xpath=ancestor::details[1]/summary").click();
    await ownForm
      .getByText("Anzeige und Pflicht nach anderen Angaben", { exact: true })
      .click();
    check(
      (await ownForm.getAttribute("action")) === "/?view=model",
      "standalone schema form retains its own working route " + width,
    );
    const box = await ownForm
      .locator("[data-condition-enabled]")
      .first()
      .boundingBox();
    check(
      box.width >= 16 && box.height >= 16,
      "standalone native Bootstrap rule checkbox remains visible " + width,
    );
    check(
      await solo.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "standalone rule builder fits " + width,
    );
  }
  check(
    (await (await standalone.request.get(standalonePublicUrl)).body()).equals(
      standaloneBytes,
    ),
    "standalone condition actions retain exact published original bytes",
  );
  check(
    standalonePosts.length === 0,
    "standalone conditional reads never submit content",
  );
  await standalone.close();
  check(errors.length === 0, "no conditional browser script errors");
  check(external.length === 0, "all conditional workflows stay entirely local");
  console.log(
    JSON.stringify({
      suite: "native-field-conditions-browser",
      checks,
      widths: [1440, 820, 390],
      actual_private_saves: 2,
      external_requests: 0,
    }),
  );
} finally {
  await browser.close();
}
