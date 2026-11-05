import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_REMOVAL_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
for (const key of [
  "js",
  "nojs",
  "pending_js",
  "pending_nojs",
  "protected",
  "old_removed",
])
  assert(/^[a-f0-9]{32}$/.test(fixture[key]));
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const external = [],
  errors = [];
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
  throw Error("Actual private media styles required");
};
const imageReady = async (image) => {
  await image.waitFor({ state: "visible" });
  for (let i = 0; i < 100; i++) {
    if (await image.evaluate((node) => node.complete && node.naturalWidth > 0))
      return;
    await new Promise((resolve) => setTimeout(resolve, 100));
  }
  throw Error("Actual original private image required");
};
const contextFor = async (js) => {
  const context = await browser.newContext({
    javaScriptEnabled: js,
    hasTouch: true,
    viewport: { width: 390, height: 900 },
  });
  await context.route("**/*", (route) => {
    if (new URL(route.request().url()).origin !== fixture.origin) {
      external.push(route.request().url());
      return route.abort();
    }
    if (route.request().method() === "POST") posts++;
    return route.continue();
  });
  return context;
};
const address = (section, id = "", extra = "") =>
  fixture.origin +
  "/?view=media&section=" +
  section +
  (id ? "&item=" + id : "") +
  extra;
const submit = async (page, form, section, id, js) => {
  const response = page.waitForResponse((r) => r.request().method() === "POST");
  await Promise.all([
    js
      ? page.waitForURL(
          (url) =>
            url.searchParams.get("section") === section &&
            url.searchParams.get("item") === id,
          { waitUntil: "domcontentloaded" },
        )
      : page.waitForNavigation({ waitUntil: "domcontentloaded" }),
    form.getByRole("button").click({ noWaitAfter: true }),
  ]);
  check(
    (await response).status() === (js ? 200 : 303),
    "actual " + (js ? "JS" : "native") + " post confirms its exact destination",
  );
  await ready(page);
};
const geometry = async (page, scope, label) => {
  check(
    await page.evaluate(
      () =>
        document.documentElement.scrollWidth <=
        document.documentElement.clientWidth + 1,
    ),
    label + " has no document-wide horizontal scroll",
  );
  const targets = await page.locator(scope).evaluateAll((elements) =>
    elements
      .filter((el) => el.getClientRects().length && !el.closest("[hidden]"))
      .map((el) => ({
        text: el.textContent?.trim(),
        width: el.getBoundingClientRect().width,
        height: el.getBoundingClientRect().height,
        font: parseFloat(getComputedStyle(el).fontSize),
      })),
  );
  check(
    targets.length > 0 &&
      targets.every((r) => r.width >= 43.9 && r.height >= 43.9),
    label + " has at least 44-pixel action and keyboard targets",
  );
  check(
    targets.every((r) => r.font >= 15.9),
    label + " keeps readable action text and inputs",
  );
};
try {
  for (const js of [true, false]) {
    const context = await contextFor(js),
      page = await context.newPage();
    page.on("pageerror", (error) => errors.push(error.message));
    const id = fixture[js ? "js" : "nojs"],
      pending = fixture[js ? "pending_js" : "pending_nojs"];
    check(
      (await page.goto(address("library", id))).status() === 200,
      "actual own " + (js ? "JS" : "native") + " active original opens",
    );
    await ready(page);
    const panel = page.locator("#media-remove"),
      summary = panel.locator("summary");
    check(
      !(await panel.evaluate((node) => node.open)),
      "removal confirmation starts collapsed",
    );
    await summary.focus();
    await page.keyboard.press("Enter");
    check(
      await panel.evaluate((node) => node.open),
      "keyboard opens the real native confirmation",
    );
    let form = panel.locator("form"),
      confirm = form.locator('[name="confirm_remove"]');
    check(
      !(await confirm.isChecked()),
      "removal requires a fresh explicit confirmation",
    );
    const before = posts;
    await form
      .getByRole("button", { name: "Bild entfernen", exact: true })
      .click();
    check(
      posts === before &&
        !(await confirm.evaluate((node) => node.checkValidity())),
      "unchecked removal makes no HTTP write",
    );
    await confirm.check();
    await geometry(
      page,
      "#media-remove summary, #media-remove button, .media-remove-confirm",
      "actual confirmation",
    );
    await submit(page, form, "removed", id, js);
    const card = page.locator('[data-removed-media="' + id + '"]');
    check(
      await card.isVisible(),
      "completed removal displays only the exact selected original",
    );
    const image = card.locator("img");
    await image.scrollIntoViewIfNeeded();
    await imageReady(image);
    check(
      await image.evaluate((node) => node.complete && node.naturalWidth > 0),
      "actual private original thumbnail loads after removal",
    );
    check(
      (
        await context.request.get(
          fixture.origin + "/media-preview/" + id + "/medium",
        )
      ).status() === 422,
      "ordinary preview is unavailable while its pixels are quarantined",
    );
    await submit(
      page,
      card.locator('form:has(input[value="media-restore"])'),
      "library",
      id,
      js,
    );
    await imageReady(page.locator(".media-editor img"));
    check(
      (await page.locator("#media-remove").isVisible()) &&
        (await page
          .locator(".media-editor img")
          .evaluate((node) => node.complete && node.naturalWidth > 0)),
      "restore opens the same original image and normal editor",
    );
    check(
      (await page.goto(address("library", pending))).status() === 200,
      "previous active URL resolves an interrupted original move",
    );
    await ready(page);
    const recovery = page.locator(".media-recovery form");
    check(
      (await recovery.isVisible()) &&
        (await recovery
          .locator('input[value="media-removal-recover"]')
          .count()) === 1,
      "interrupted move offers its exact original recovery action",
    );
    await geometry(page, ".media-recovery button", "actual recovery");
    await submit(page, recovery, "library", pending, js);
    await imageReady(page.locator(".media-editor img"));
    check(
      await page
        .locator(".media-editor img")
        .evaluate((node) => node.complete && node.naturalWidth > 0),
      "actual recovery returns the original image without another removal",
    );
    for (const width of [320, 390, 820]) {
      await page.setViewportSize({ width, height: 1000 });
      check(
        (await page.goto(address("removed"))).status() === 200,
        "actual original list opens at " + width,
      );
      await ready(page);
      check(
        (await page.locator(".removed-media-list article").count()) === 6,
        "original first list is bounded to six records at " + width,
      );
      await geometry(
        page,
        ".removed-media-card button, .removed-media-card h3 a, .media-library-pager a, .media-library-pager button, .media-library-pager input",
        "actual " + (js ? "JS" : "native") + " list " + width,
      );
      await page.evaluate(() => (document.documentElement.style.zoom = "2"));
      await geometry(
        page,
        ".removed-media-card button, .removed-media-card h3 a, .media-library-pager a, .media-library-pager button, .media-library-pager input",
        "actual list " + width + " at 200 percent",
      );
      await page.evaluate(() => (document.documentElement.style.zoom = "1"));
      const pager = page.locator(".media-library-pager").first(),
        choice = pager.locator('[name="removed_page"]');
      await choice.fill("2");
      await Promise.all([
        page.waitForURL((url) => url.searchParams.get("removed_page") === "2", {
          waitUntil: "domcontentloaded",
        }),
        pager
          .getByRole("button", { name: "Öffnen", exact: true })
          .click({ noWaitAfter: true }),
      ]);
      await ready(page);
      check(
        (await page.locator(".removed-media-list article").count()) === 2,
        "direct native page choice finds the last two original records",
      );
      check(
        await page.locator(".removed-media").isVisible(),
        "direct original paging retains its visible list anchor",
      );
      if (js && width === 390) {
        await mkdir(".local/browser-tests/media-removal", { recursive: true });
        await page.screenshot({
          path: ".local/browser-tests/media-removal/mobile.png",
          fullPage: true,
        });
      }
    }
    if (js) {
      await page.setViewportSize({ width: 390, height: 900 });
      await page.goto(address("library", fixture.protected));
      await ready(page);
      const protectedPanel = page.locator("#media-remove");
      await protectedPanel.locator("summary").click();
      const protectedForm = protectedPanel.locator("form");
      await protectedForm.locator('[name="confirm_remove"]').check();
      const rejected = page.waitForResponse(
        (r) => r.request().method() === "POST",
      );
      await protectedForm
        .getByRole("button", { name: "Bild entfernen", exact: true })
        .click();
      check(
        (await rejected).status() === 422,
        "actual current global use blocks published original removal",
      );
      const feedback = protectedForm.locator("[data-media-removal-error]");
      await feedback.waitFor({ state: "visible" });
      check(
        (await feedback.textContent()).includes("wird noch verwendet") &&
          (await feedback.evaluate((node) => document.activeElement === node)),
        "failed JS removal keeps a short immediate local error and keyboard focus",
      );
      check(
        (await protectedForm.locator('[name="confirm_remove"]').isChecked()) &&
          (await protectedForm
            .locator("[data-media-removal-check]")
            .isVisible()),
        "failed JS removal retains exact user selection and offers current state inspection",
      );
    }
    await context.close();
  }
  for (const actor of ["reader", "editor"]) {
    const context = await contextFor(false),
      page = await context.newPage();
    await page.goto(address("library"));
    await ready(page);
    const form = page.locator(
      'form:has(input[name="operation"][value="identity"])',
    );
    await form.locator('[name="actor"]').selectOption(actor);
    await Promise.all([
      page.waitForNavigation({ waitUntil: "domcontentloaded" }),
      form.getByRole("button").click({ noWaitAfter: true }),
    ]);
    check(
      (await page
        .locator('.workspace-tabs a[href*="section=removed"]')
        .count()) === 0,
      "actual current " + actor + " has no protected removed navigation",
    );
    check(
      (
        await context.request.get(
          fixture.origin + "/media-removed/" + fixture.old_removed + "/thumb",
        )
      ).status() === 422,
      "actual current " +
        actor +
        " cannot download old private quarantine pixels",
    );
    const response = await page.goto(address("removed"));
    check(
      response.status() === 422 &&
        !(await page.content()).includes(
          "Protected earlier removed original.png",
        ),
      "actual current " + actor + " cannot read private removed original names",
    );
    await context.close();
  }
  check(
    posts === 9,
    "browser writes only two exact own removals, two restores, two recoveries, one rejected old use and two synthetic role changes",
  );
  check(
    external.length === 0 && errors.length === 0,
    "all actual private media flows make no external request or browser error",
  );
  console.log(
    JSON.stringify({
      suite: "media-removal-browser",
      checks,
      posts,
      external_requests: 0,
    }),
  );
} finally {
  await browser.close();
}
