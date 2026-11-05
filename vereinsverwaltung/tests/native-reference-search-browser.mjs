import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
import { createHash } from "node:crypto";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  base = root + ".local/native-cms-integration/integrated-manager-runtime/";
const credentials = JSON.parse(
    await readFile(base + "browser-credentials.json", "utf8"),
  ),
  fixture = JSON.parse(
    await readFile(base + "reference-search-browser.json", "utf8"),
  );
const origin = "http://127.0.0.1:8111",
  output = root + ".local/browser-tests";
await mkdir(output, { recursive: true, mode: 0o700 });
let n = 0;
const check = (ok, label) => {
  assert(ok, label);
  n++;
  console.log("PASS " + label);
};
const editorUrl =
  origin +
  "/vereinsverwaltung/?" +
  new URLSearchParams({
    view: "website",
    tab: "pages",
    native_item: fixture.editor.id,
  });
const endpoint = (args) =>
  origin +
  "/vereinsverwaltung/?" +
  new URLSearchParams({ native_endpoint: "reference-options", ...args });
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
  const publicUrl = origin + "/site/" + fixture.rows[0].slug + "/",
    before = await context.request.get(publicUrl),
    publicBytes = await before.body();
  check(
    before.status() === 200,
    "real original published lookup page available",
  );
  const imageUrl =
    origin +
    "/vereinsverwaltung/?" +
    new URLSearchParams({
      native_endpoint: "media-preview",
      media: fixture.images[57].id,
      variant: "original",
    });
  const imageBefore = await context.request.get(imageUrl),
    imageBytes = await imageBefore.body();
  check(
    imageBefore.status() === 200 &&
      createHash("sha256").update(imageBytes).digest("hex") ===
        fixture.image_hash,
    "actual original selected picture bytes available",
  );
  const picker = (name) =>
    page
      .locator("[data-reference-picker]")
      .filter({ has: page.locator('select[name="' + name + '"]') });
  const optionIds = (control) =>
    control
      .locator("select option")
      .evaluateAll((options) =>
        options.filter((option) => option.value).map((option) => option.value),
      );
  const search = async (control, q) => {
    if (!(await control.locator("[data-reference-query]").isVisible()))
      await control.locator("xpath=ancestor::details[1]/summary").click();
    await control.locator("[data-reference-query]").fill(q);
    await control.locator("[data-reference-query]").press("Enter");
    await control
      .locator("[data-reference-status]")
      .filter({ hasText: /Treffer · Seite/ })
      .waitFor();
    await page.waitForFunction(
      () =>
        ![...document.querySelectorAll("[data-reference-picker]")].some((p) =>
          p.hasAttribute("aria-busy"),
        ),
    );
  };
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    await page.goto(editorUrl);
    const link = picker("custom_fields[browser_lookup_page]"),
      picture = picker("custom_fields[browser_lookup_image]"),
      parent = picker("parent_id");
    check(
      (await page.locator("[data-reference-picker]").count()) === 3,
      "all free and parent reference controls share native picker " + width,
    );
    check(
      (await link.locator("select").inputValue()) === fixture.rows[68].id &&
        (await picture.locator("select").inputValue()) ===
          fixture.images[57].id &&
        (await parent.locator("select").inputValue()) === fixture.rows[68].id,
      "saved far-away page/picture/parent selections retain identity " + width,
    );
    check(
      (await link.locator("select option").count()) <= 27 &&
        (await picture.locator("select option").count()) <= 27,
      "initial reference lists remain bounded " + width,
    );
    check(
      await link
        .locator("select")
        .evaluate(
          (select) =>
            select.required && select.classList.contains("form-control"),
        ),
      "actual Bootstrap/native required selection " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "reference editor fits viewport " + width,
    );
    await search(link, "Own lookup page");
    check(
      (await link.locator("[data-reference-status]").innerText()).includes(
        "69 Treffer · Seite 1 von 3",
      ),
      "actual SQL first-page reference count " + width,
    );
    check(
      JSON.stringify(await optionIds(link)) ===
        JSON.stringify([
          ...fixture.rows.slice(0, 25).map((row) => row.id),
          fixture.rows[68].id,
        ]),
      "first 25 actual reference rows plus retained original " + width,
    );
    check(
      (await link.locator("select").inputValue()) === fixture.rows[68].id,
      "search does not silently change saved selection " + width,
    );
    check(
      !(await page.evaluate(() => window.KulturhusNavigation.isDirty())),
      "search-only inputs do not mark content unsaved " + width,
    );
    const next = link.getByRole("button", {
      name: "Weitere Treffer",
      exact: true,
    });
    await next.focus();
    await next.press("Enter");
    await link
      .locator("[data-reference-status]")
      .filter({ hasText: "Seite 2 von 3" })
      .waitFor();
    check(
      JSON.stringify(await optionIds(link)) ===
        JSON.stringify([
          ...fixture.rows.slice(25, 50).map((row) => row.id),
          fixture.rows[68].id,
        ]),
      "keyboard paged reference rows are exact and continuous " + width,
    );
    await next.click();
    await link
      .locator("[data-reference-status]")
      .filter({ hasText: "Seite 3 von 3" })
      .waitFor();
    check(
      JSON.stringify(await optionIds(link)) ===
        JSON.stringify(fixture.rows.slice(50).map((row) => row.id)) &&
        (await next.isDisabled()),
      "last reference page deduplicates selected original and disables next " +
        width,
    );
    await link
      .getByRole("button", { name: "Vorherige Treffer", exact: true })
      .click();
    await link
      .locator("[data-reference-status]")
      .filter({ hasText: "Seite 2 von 3" })
      .waitFor();
    check(
      (await link.locator("select").inputValue()) === fixture.rows[68].id,
      "previous reference page preserves selection " + width,
    );
    await search(link, "Own lookup page 68 100%_!");
    check(
      (await link.locator("[data-reference-status]").innerText()).includes(
        "1 Treffer",
      ) && (await optionIds(link))[0] === fixture.rows[68].id,
      "wildcards in actual reference search remain literal " + width,
    );
    check(
      (await link.locator("select option:checked").innerText()).includes(
        "<marker>",
      ) && (await link.locator("marker").count()) === 0,
      "stored reference markup stays visible literal text " + width,
    );
    await search(link, "nothing present");
    check(
      (await link.locator("[data-reference-status]").innerText()).includes(
        "0 Treffer",
      ) && (await link.locator("select").inputValue()) === fixture.rows[68].id,
      "empty actual results retain saved original " + width,
    );
    await search(picture, "Own lookup picture");
    check(
      (await picture.locator("[data-reference-status]").innerText()).includes(
        "58 Treffer · Seite 1 von 3",
      ),
      "actual picture references use same bounded page count " + width,
    );
    await picture
      .getByRole("button", { name: "Weitere Treffer", exact: true })
      .click();
    await picture
      .locator("[data-reference-status]")
      .filter({ hasText: "Seite 2 von 3" })
      .waitFor();
    check(
      JSON.stringify(await optionIds(picture)) ===
        JSON.stringify([
          ...fixture.images.slice(25, 50).map((row) => row.id),
          fixture.images[57].id,
        ]),
      "actual picture second slice retains selected source " + width,
    );
    await search(picture, "OWN-LOOKUP-57.PNG");
    check(
      (await picture.locator("[data-reference-status]").innerText()).includes(
        "1 Treffer",
      ) &&
        (await picture.locator("select").inputValue()) ===
          fixture.images[57].id,
      "actual filename search preserves original picture " + width,
    );
    await search(parent, "Own lookup editor");
    check(
      (await parent.locator("[data-reference-status]").innerText()).includes(
        "0 Treffer",
      ) && !(await optionIds(parent)).includes(fixture.editor.id),
      "current editor cannot select itself as parent " + width,
    );
    check(
      !(await page.evaluate(() => window.KulturhusNavigation.isDirty())),
      "all reference searching and paging retain clean document " + width,
    );
    await page.screenshot({
      path: output + "/dvm-reference-search-" + width + ".png",
    });
  }
  await page.goto(editorUrl);
  const delayedPattern =
    "**/vereinsverwaltung/?native_endpoint=reference-options*";
  await context.route(delayedPattern, async (route) => {
    if (
      new URL(route.request().url()).searchParams.get("q") ===
      "Own lookup page 03"
    )
      await new Promise((resolve) => setTimeout(resolve, 350));
    await route.continue().catch(() => {});
  });
  const racing = picker("custom_fields[browser_lookup_page]");
  const beginSlow = async () => {
    await racing.locator("[data-reference-query]").fill("Own lookup page 03");
    const started = page.waitForRequest(
      (request) =>
        new URL(request.url()).searchParams.get("q") === "Own lookup page 03",
    );
    await racing.locator("[data-reference-query]").press("Enter");
    await started;
  };
  await beginSlow();
  await search(racing, "Own lookup page 04");
  await page.waitForTimeout(450);
  check(
    (await optionIds(racing)).includes(fixture.rows[4].id) &&
      !(await optionIds(racing)).includes(fixture.rows[3].id),
    "a newer actual search wins against delayed older response",
  );
  await beginSlow();
  await racing.locator("select").selectOption(fixture.rows[4].id);
  await racing
    .locator("[data-reference-status]")
    .filter({ hasText: "Auswahl geändert" })
    .waitFor();
  check(
    (await racing.locator("select").inputValue()) === fixture.rows[4].id,
    "late search cannot replace an explicitly changed original selection",
  );
  await racing.locator("select").selectOption(fixture.rows[68].id);
  check(
    !(await page.evaluate(() => window.KulturhusNavigation.isDirty())),
    "restoring the saved reference clears unsaved content state",
  );
  await page.goto(editorUrl);
  await beginSlow();
  await page
    .getByRole("navigation", { name: "Website verwalten" })
    .getByRole("link", { name: "Seiten", exact: true })
    .click();
  await page.waitForFunction(
    () =>
      !document.querySelector("[data-reference-picker]") &&
      !document.querySelector("main")?.hasAttribute("aria-busy"),
  );
  await page.waitForTimeout(450);
  check(
    (await page.locator("[data-reference-picker]").count()) === 0 &&
      !(await page.locator("main").innerText()).includes(
        "Verknüpfungen werden gesucht",
      ),
    "soft navigation aborts former private reference lookup",
  );
  await context.unroute(delayedPattern);
  await page.goto(editorUrl);
  const link = picker("custom_fields[browser_lookup_page]"),
    picture = picker("custom_fields[browser_lookup_image]"),
    parent = picker("parent_id");
  await search(link, "Own lookup page 01");
  await link.locator("select").selectOption(fixture.rows[1].id);
  await search(picture, "Own lookup picture 01");
  await picture.locator("select").selectOption(fixture.images[1].id);
  await search(parent, "Own lookup page 02");
  await parent.locator("select").selectOption(fixture.rows[2].id);
  check(
    await page.evaluate(() => window.KulturhusNavigation.isDirty()),
    "deliberate changed reference marks original content unsaved",
  );
  const loadedSave = page.waitForResponse(
    (response) =>
      response.request().method() === "GET" &&
      response.request().isNavigationRequest() &&
      new URL(response.url()).searchParams.get("native_item") ===
        fixture.editor.id,
  );
  const [savedResponse] = await Promise.all([
    page.waitForResponse(
      (response) =>
        response.request().method() === "POST" &&
        new URL(response.url()).pathname === "/vereinsverwaltung/",
    ),
    page
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click(),
  ]);
  check(
    savedResponse.status() === 303 &&
      new URL(savedResponse.headers().location, origin).searchParams.get(
        "native_item",
      ) === fixture.editor.id,
    "explicit own reference save completes its real HTTP request",
  );
  check(
    (await loadedSave).status() === 200,
    "saved reference editor loads through the original HTTP redirect",
  );
  await page.waitForFunction(
    ({ related, image, parent, version }) =>
      document.querySelector(
        'select[name="custom_fields[browser_lookup_page]"]',
      )?.value === related &&
      document.querySelector(
        'select[name="custom_fields[browser_lookup_image]"]',
      )?.value === image &&
      document.querySelector('select[name="parent_id"]')?.value === parent &&
      Number(
        document
          .querySelector('input[name="operation"][value="page-save"]')
          ?.form.querySelector('[name="version"]').value,
      ) === version &&
      !document.querySelector("main")?.hasAttribute("aria-busy"),
    {
      related: fixture.rows[1].id,
      image: fixture.images[1].id,
      parent: fixture.rows[2].id,
      version: fixture.editor.version + 1,
    },
  );
  check(
    (await picker("custom_fields[browser_lookup_page]")
      .locator("select")
      .inputValue()) === fixture.rows[1].id &&
      (await picker("custom_fields[browser_lookup_image]")
        .locator("select")
        .inputValue()) === fixture.images[1].id &&
      (await picker("parent_id").locator("select").inputValue()) ===
        fixture.rows[2].id,
    "actual private save preserves all explicitly chosen typed references",
  );
  const head = await context.request.head(
    endpoint({ kind: "page", q: "Own lookup page" }),
  );
  check(
    head.status() === 200 && (await head.body()).length === 0,
    "reference HTTP HEAD has no response body",
  );
  const bad = await context.request.get(endpoint({ kind: "page", page: "-1" }));
  check(bad.status() === 403, "malformed reference HTTP page is refused");
  const post = await context.request.post(endpoint({ kind: "page" }));
  check(post.status() === 405, "reference HTTP rejects mutation requests");
  const anonymous = await browser.newContext();
  const denied = await anonymous.request.get(endpoint({ kind: "page" }), {
    headers: { Accept: "application/json" },
    maxRedirects: 0,
  });
  check(
    [401, 303].includes(denied.status()),
    "anonymous HTTP cannot read private reference catalogue",
  );
  await anonymous.close();
  const after = await context.request.get(publicUrl),
    imageAfter = await context.request.get(imageUrl);
  check(
    (await after.body()).equals(publicBytes),
    "all reference actions retain exact published original bytes",
  );
  check(
    (await imageAfter.body()).equals(imageBytes),
    "all reference actions retain actual original image bytes",
  );
  check(
    posts.length === 1 && new URL(posts[0]).pathname === "/vereinsverwaltung/",
    "only explicit own editor save mutates content",
  );
  check(errors.length === 0, "no reference browser script errors");
  check(external.length === 0, "all reference workflows stay local");
  console.log(
    JSON.stringify({
      suite: "native-reference-search-browser",
      checks: n,
      widths: [1440, 820, 390],
      external_requests: 0,
      private_saves: posts.length,
    }),
  );
} finally {
  await browser.close();
}
