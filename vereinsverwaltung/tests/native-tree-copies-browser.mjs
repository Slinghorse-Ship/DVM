import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
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
    await readFile(base + "tree-copies-browser.json", "utf8"),
  );
const source = fixture.source,
  origin = "http://127.0.0.1:8111",
  output = root + ".local/browser-tests";
await mkdir(output, { recursive: true, mode: 0o700 });
let n = 0;
const check = (ok, label) => {
  assert(ok, label);
  n++;
  console.log("PASS " + label);
};
const url = (id, tab = "pages", extra = {}) =>
  origin +
  "/vereinsverwaltung/?" +
  new URLSearchParams({ view: "website", tab, native_item: id, ...extra });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  const context = await browser.newContext(),
    page = await context.newPage(),
    errors = [],
    external = [];
  await context.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin
      ? r.continue()
      : (external.push(r.request().url()), r.abort()),
  );
  page.on("pageerror", (e) => errors.push(e.message));
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
  const publicBefore = {};
  for (const [key, row] of Object.entries(source)) {
    const response = await context.request.get(
      origin + "/site/" + row.slug + "/",
    );
    publicBefore[key] = [response.status(), await response.text()];
  }
  const imageUrl =
    origin +
    "/vereinsverwaltung/?native_endpoint=media-preview&media=" +
    fixture.photo +
    "&variant=original";
  const originalImage = await context.request.get(imageUrl),
    pixels = await originalImage.body();
  check(
    originalImage.status() === 200,
    "actual original pixels available before tree copying",
  );
  const suffix = Date.now().toString(36);
  await page.goto(url(source.root.id));
  await page.locator(".page-copy-panel summary").click();
  const conflict = page.locator(".page-copy-panel form");
  await conflict.locator("[name=copy_tree]").check();
  await conflict
    .locator("[name=copy_title]")
    .fill("Own occupied tree correction");
  await conflict.locator("[name=copy_slug]").fill(source.root.slug);
  const conflictPost = page.waitForResponse(
    (r) =>
      r.request().method() === "POST" &&
      r.request().postData()?.includes("operation=page-copy"),
  );
  await conflict
    .getByRole("button", {
      name: "Unveröffentlichten Entwurf anlegen",
      exact: true,
    })
    .click();
  check(
    (await conflictPost).status() === 422,
    "actual occupied subtree target returns actionable HTTP conflict",
  );
  await page.waitForFunction(() =>
    document.body?.innerText.includes("Die neue Adresse ist bereits vergeben"),
  );
  check(
    await page.locator(".page-copy-panel").evaluate((el) => el.open),
    "actual conflict reopens copy correction panel",
  );
  check(
    await page.locator(".page-copy-panel [name=copy_tree]").isChecked(),
    "actual conflict preserves subtree choice",
  );
  check(
    (await page.locator(".page-copy-panel [name=copy_title]").inputValue()) ===
      "Own occupied tree correction" &&
      (await page.locator(".page-copy-panel [name=copy_slug]").inputValue()) ===
        source.root.slug,
    "actual conflict preserves title and address for correction",
  );
  for (const width of [1440, 820, 390]) {
    await page.setViewportSize({ width, height: 1050 });
    for (const basis of ["draft", "published"]) {
      await page.goto(url(source.root.id));
      const panel = page.locator(".page-copy-panel");
      await panel.locator("summary").focus();
      await panel.locator("summary").press("Enter");
      const form = panel.locator("form");
      const checkbox = form.getByRole("checkbox", {
        name: "Unterseiten mitkopieren",
        exact: true,
      });
      await checkbox.focus();
      await checkbox.press("Space");
      check(
        await checkbox.isChecked(),
        "keyboard selects actual subtree option " + basis + " " + width,
      );
      check(
        (await checkbox.getAttribute("class")).includes("check"),
        "actual Bootstrap 6 checkbox " + width,
      );
      check(
        await checkbox.evaluate((el) => {
          const r = el.getBoundingClientRect();
          return (
            r.width >= 16 &&
            r.height >= 16 &&
            getComputedStyle(el).appearance === "auto"
          );
        }),
        "visible full-sized checkbox retains native glyph " + width,
      );
      await form.locator("[name=basis]").selectOption(basis);
      await form
        .locator("[name=copy_title]")
        .fill("Own private copied tree " + width + " " + basis);
      const slug = "own-tree-" + width + "-" + basis + "-" + suffix;
      await form.locator("[name=copy_slug]").fill(slug);
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        "expanded subtree form fits " + width,
      );
      if (basis === "draft")
        await panel.screenshot({
          path: output + "/dvm-tree-copy-form-" + width + ".png",
        });
      const post = page.waitForResponse(
        (r) =>
          r.request().method() === "POST" &&
          r.request().postData()?.includes("operation=page-copy"),
      );
      await form
        .getByRole("button", {
          name: "Unveröffentlichten Entwurf anlegen",
          exact: true,
        })
        .click();
      check(
        (await post).status() === 303,
        "actual subtree POST succeeds " + basis + " " + width,
      );
      await page.waitForURL(
        (u) =>
          u.searchParams.get("native_item") &&
          u.searchParams.get("native_item") !== source.root.id,
      );
      const newRoot = new URL(page.url()).searchParams.get("native_item");
      check(
        (await page.locator("[name=body_html]").inputValue()) ===
          (basis === "draft"
            ? source.root.body_html
            : "<p>Published original tree root</p>"),
        "root reopens correct persisted original basis " + basis + " " + width,
      );
      const newAlbum = await page
        .locator('[name="custom_fields[browser_tree_link]"]')
        .inputValue();
      check(
        newAlbum !== source.album.id && /^[a-f0-9]{32}$/.test(newAlbum),
        "forward reference reaches new album identity " + basis + " " + width,
      );
      const children = page.locator(".page-children-panel");
      check(
        (await children.locator("[data-child-page]").count()) ===
          (basis === "draft" ? 3 : 2),
        "actual copy exposes complete current child hierarchy " +
          basis +
          " " +
          width,
      );
      check(
        (await children.innerText()).includes(
          basis === "draft" ? "3 Unterseiten" : "2 Unterseiten",
        ),
        "actual child count is preserved " + basis + " " + width,
      );
      if (basis === "draft")
        await children.screenshot({
          path: output + "/dvm-tree-copy-children-" + width + ".png",
        });
      const childLink = children.getByRole("link", {
        name: source.child.title,
        exact: true,
      });
      await childLink.focus();
      await childLink.press("Enter");
      await page.waitForURL(
        (u) => u.searchParams.get("native_item") !== newRoot,
      );
      const newChild = new URL(page.url()).searchParams.get("native_item");
      check(
        newChild !== source.child.id,
        "keyboard opens new copied child " + basis + " " + width,
      );
      check(
        (await page.locator("[name=parent_id]").inputValue()) === newRoot,
        "actual copied child parent points to new root " + basis + " " + width,
      );
      check(
        (await page
          .locator('[name="custom_fields[browser_tree_link]"]')
          .inputValue()) === newRoot,
        "back reference resolves inside new tree " + basis + " " + width,
      );
      check(
        (await page.locator("[name=body_html]").inputValue()) ===
          (basis === "draft"
            ? source.child.body_html
            : "<p>Published original tree child</p>"),
        "child original basis reopens from MySQL " + basis + " " + width,
      );
      const albumLink = page
        .locator(".page-children-panel")
        .getByRole("link", { name: source.album.title, exact: true });
      await albumLink.click();
      await page.waitForURL(
        (u) => u.searchParams.get("native_item") === newAlbum,
      );
      check(
        new URL(page.url()).searchParams.get("tab") === "gallery",
        "mixed copied album opens correct editor " + basis + " " + width,
      );
      check(
        (await page.locator(".album-images").innerText()).includes(
          "Own original tree caption",
        ),
        "actual copied album keeps original caption " + basis + " " + width,
      );
      const albumSlug = await page.locator("[name=slug]").inputValue();
      check(
        (
          await context.request.get(origin + "/site/" + albumSlug + "/")
        ).status() === 404,
        "copied album remains private " + basis + " " + width,
      );
      await page.goto(url(newRoot));
      await page
        .locator(".page-children-panel")
        .getByRole("link", { name: source.event.title, exact: true })
        .click();
      await page.waitForURL((u) => u.searchParams.get("tab") === "events");
      check(
        (await page.locator("[name=event_location]").inputValue()) ===
          source.event.event_location,
        "mixed copied event keeps original place " + basis + " " + width,
      );
      const eventSlug = await page
        .locator('form:has([name=operation][value="page-save"]) [name=slug]')
        .inputValue();
      check(
        (
          await context.request.get(origin + "/site/" + eventSlug + "/")
        ).status() === 404,
        "copied event remains private " + basis + " " + width,
      );
      check(
        (await context.request.get(origin + "/site/" + slug + "/")).status() ===
          404,
        "copied root remains private " + basis + " " + width,
      );
      await page.goto(url(source.root.id));
      check(
        (await page
          .locator('[name="custom_fields[browser_tree_link]"]')
          .inputValue()) === source.album.id,
        "original forward reference stays exact " + basis + " " + width,
      );
      check(
        (await page.locator("[name=body_html]").inputValue()) ===
          source.root.body_html,
        "original current private body stays exact " + basis + " " + width,
      );
      check(
        await page.evaluate(
          () => document.documentElement.scrollWidth <= innerWidth + 2,
        ),
        "completed hierarchy fits viewport " + basis + " " + width,
      );
    }
    const paging = fixture.paging;
    await page.goto(url(paging.root));
    const list = () => page.locator(".page-children-panel [data-child-page]"),
      ids = () =>
        list().evaluateAll((nodes) => nodes.map((n) => n.dataset.childPage));
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(paging.children.slice(0, 25)),
      "real first 25-child page " + width,
    );
    await page
      .getByRole("link", { name: "Nächste Unterseiten", exact: true })
      .focus();
    await page
      .getByRole("link", { name: "Nächste Unterseiten", exact: true })
      .press("Enter");
    await page.waitForURL((u) => u.searchParams.get("children_page") === "2");
    check(
      JSON.stringify(await ids()) ===
        JSON.stringify(paging.children.slice(25, 50)),
      "keyboard child page retains correct parent and order " + width,
    );
    check(
      new URL(page.url()).searchParams.get("native_item") === paging.root,
      "child paging keeps exact original parent " + width,
    );
    await page
      .getByRole("link", { name: "Nächste Unterseiten", exact: true })
      .click();
    await page.waitForURL((u) => u.searchParams.get("children_page") === "3");
    check(
      JSON.stringify(await ids()) === JSON.stringify(paging.children.slice(50)),
      "last child page has five remaining originals " + width,
    );
    await page.goto(url(paging.root, "pages", { children_page: "999999" }));
    check(
      (await page.locator(".page-children-count").innerText()).includes(
        "55 Unterseiten · Seite 3 von 3",
      ),
      "child pager clamps large input to existing page " + width,
    );
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 2,
      ),
      "child paging fits " + width,
    );
  }
  for (const [key, row] of Object.entries(source)) {
    const after = await context.request.get(origin + "/site/" + row.slug + "/");
    check(
      after.status() === publicBefore[key][0] &&
        (await after.text()) === publicBefore[key][1],
      "all original public bytes unchanged: " + key,
    );
  }
  const imageAfter = await context.request.get(imageUrl);
  check(
    (await imageAfter.body()).equals(pixels),
    "actual original image bytes unchanged",
  );
  check(errors.length === 0, "no tree browser script regressions");
  check(external.length === 0, "all tree copying/navigation stays local");
  const memberContext = await browser.newContext();
  await memberContext.route("**/*", (r) =>
    new URL(r.request().url()).origin === origin ? r.continue() : r.abort(),
  );
  const member = await memberContext.newPage();
  await member.goto(origin + "/login/");
  await member.locator("[name=email]").fill(credentials.member.email);
  await member.locator("[name=password]").fill(credentials.member.password);
  await Promise.all([
    member.waitForURL("**/vereinsverwaltung/**"),
    member
      .getByRole("button", {
        name: "Anmelden und Vereinsmanager öffnen",
        exact: true,
      })
      .click(),
  ]);
  check(
    (await member.goto(url(source.root.id))).status() === 403,
    "ordinary member cannot open subtree source",
  );
  check(
    (await member.locator(".page-copy-panel,.page-children-panel").count()) ===
      0,
    "ordinary member cannot obtain copying or private hierarchy controls",
  );
  await memberContext.close();
  console.log(
    JSON.stringify({
      suite: "native-tree-copies-browser",
      checks: n,
      copies: 6,
      pages: 27,
      widths: [1440, 820, 390],
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
