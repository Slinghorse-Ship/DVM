import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_CONTACT_LINK_FIXTURE, "utf8"),
);
assert(/^http:\/\/127\.0\.0\.1:\d{4,5}$/.test(fixture.origin));
assert(/^[a-f0-9]{32}$/.test(fixture.id));
const origin = fixture.origin,
  url = origin + "/?view=website&item=" + fixture.id;
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const errors = [],
  external = [];
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
  throw Error("Actual editor styles required");
};
const ownContext = async (js) => {
  const context = await browser.newContext({
    javaScriptEnabled: js,
    hasTouch: true,
    viewport: { width: 390, height: 900 },
  });
  await context.route("**/*", (route) => {
    if (new URL(route.request().url()).origin !== origin) {
      external.push(route.request().url());
      return route.abort();
    }
    if (route.request().method() === "POST") posts++;
    return route.continue();
  });
  return context;
};
try {
  const context = await ownContext(true),
    page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));
  check(
    (await page.goto(url)).status() === 200,
    "actual selected private page editor opens",
  );
  await ready(page);
  const editor = page
    .locator("[data-kh-richtext]")
    .filter({ has: page.locator('[name="body_html"]') });
  const surface = editor.locator("[data-richtext-surface]"),
    value = editor.locator('[name="body_html"]');
  await page.waitForFunction(
    () =>
      document
        .querySelector('[name="body_html"]')
        .closest("[data-kh-richtext]")
        .querySelector("[data-richtext-surface]").contentEditable === "true",
  );
  const selectText = async (text) => {
    await surface.evaluate((node, text) => {
      const paragraph = [...node.querySelectorAll("p")].find(
        (p) => p.textContent === text,
      );
      if (!paragraph) throw Error("Own original text required");
      node.focus();
      const range = document.createRange();
      range.selectNodeContents(paragraph);
      const selection = getSelection();
      selection.removeAllRanges();
      selection.addRange(range);
      node.dispatchEvent(new MouseEvent("mouseup", { bubbles: true }));
    }, text);
  };
  const add = async (text, kind, input, expected, keyboard = false) => {
    await selectText(text);
    if (keyboard) {
      await editor.locator("[data-rt-link]").focus();
      await editor.locator("[data-rt-link]").press("Enter");
    } else await editor.locator("[data-rt-link]").click();
    await editor.locator("[data-rt-link-kind]").selectOption(kind);
    const control = editor.locator("[data-rt-link-url]");
    check(
      (await control.evaluate((node) => node.inputMode)) ===
        (kind === "email" ? "email" : kind === "phone" ? "tel" : "url"),
      "actual " + kind + " kind selects the corresponding mobile keyboard",
    );
    check(
      await control.evaluate((node) =>
        [...node.labels].some(
          (label) =>
            label.textContent.trim() ===
            (node.inputMode === "email"
              ? "E-Mail-Adresse"
              : node.inputMode === "tel"
                ? "Telefonnummer"
                : "Webadresse oder Seitenpfad"),
        ),
      ),
      "actual selected address has a matching native label",
    );
    await control.fill(input);
    await editor.locator("[data-rt-link-apply]").click();
    check(
      (await surface
        .getByRole("link", { name: text, exact: true })
        .getAttribute("href")) === expected,
      "actual selected original " + text + " receives only the entered target",
    );
    check(
      (await value.inputValue()).includes('href="' + expected + '"'),
      "client canonical storage retains exact " + kind + " target",
    );
    check(
      await editor.locator("[data-rt-link-panel]").isHidden(),
      "successful link returns directly to the text",
    );
  };
  await add(
    "E-Mail Ä",
    "email",
    "own+news@example.invalid",
    "mailto:own%2Bnews@example.invalid",
  );
  await add(
    "Telefon",
    "phone",
    "+49 (0) 4454-12345;ext=12",
    "tel:+49(0)4454-12345;ext=12",
    true,
  );
  await add("Website", "web", "/kontakt/", "/kontakt/");
  await add(
    "Sonderpostfach",
    "email",
    "own#%news@example.invalid",
    "mailto:own%23%25news@example.invalid",
  );
  const before = await value.inputValue();
  await selectText("Website");
  await editor.locator("[data-rt-link]").click();
  for (const [kind, input] of [
    ["email", "own@example.invalid?subject=Prefilled"],
    ["email", "own@@example.invalid"],
    ["phone", "123;ext=bad"],
    ["web", "mailto:own@example.invalid?subject=Prefilled"],
    ["web", "javascript:alert(1)"],
  ]) {
    await editor.locator("[data-rt-link-kind]").selectOption(kind);
    await editor.locator("[data-rt-link-url]").fill(input);
    await editor.locator("[data-rt-link-apply]").click();
    check(
      (await value.inputValue()) === before,
      "invalid entered " +
        kind +
        " retains all original selected text and links",
    );
    check(
      (await editor
        .locator("[data-rt-link-url]")
        .getAttribute("aria-invalid")) === "true" &&
        (await editor
          .locator("[data-rt-link-url]")
          .evaluate((node) => node === document.activeElement)),
      "invalid entered " +
        kind +
        " keeps immediate accessible correction focus",
    );
    check(
      await editor.locator("[data-rt-link-panel]").isVisible(),
      "invalid target keeps the correction fields open",
    );
  }
  await editor.locator("[data-rt-link-url]").press("Escape");
  check(
    (await editor.locator("[data-rt-link-panel]").isHidden()) &&
      (await surface.evaluate((node) => node === document.activeElement)),
    "Escape closes the link fields and restores text focus",
  );
  check(
    (await value.inputValue()) === before && posts === 0,
    "inserting and correcting links never saves, sends or launches an external contact",
  );
  await surface.press("Control+z");
  check(
    !(await value.inputValue()).includes(
      "mailto:own%23%25news@example.invalid",
    ),
    "actual editor undo reverses only the latest own link",
  );
  await surface.press("Control+Shift+z");
  check(
    (await value.inputValue()) === before,
    "actual editor redo restores exact entered links",
  );
  await selectText("Website");
  await surface.evaluate((node) => {
    const clipboard = new DataTransfer();
    clipboard.setData(
      "text/html",
      '<p><a href="mailto:copy%25box@example.invalid">Kopiert Ä</a> <a href="tel:04454.12345">Telefonkopie</a> <a href="mailto:copy@example.invalid?subject=Prefilled">Keine Vorgabe</a> <a href="mailto://copy@example.invalid">Kein Host</a></p>',
    );
    node.dispatchEvent(
      new ClipboardEvent("paste", {
        clipboardData: clipboard,
        bubbles: true,
        cancelable: true,
      }),
    );
  });
  check(
    (await surface
      .getByRole("link", { name: "Kopiert Ä", exact: true })
      .getAttribute("href")) === "mailto:copy%25box@example.invalid",
    "actual paste retains the exact encoded own mailbox",
  );
  check(
    (await surface
      .getByRole("link", { name: "Telefonkopie", exact: true })
      .getAttribute("href")) === "tel:04454.12345",
    "actual paste retains the exact safe own telephone link",
  );
  for (const text of ["Keine Vorgabe", "Kein Host"])
    check(
      (await surface
        .locator("a")
        .filter({ hasText: text })
        .getAttribute("href")) === null,
      "actual paste removes an unsafe contact target and keeps its visible text",
    );
  await surface.press("Control+z");
  check(
    (await value.inputValue()) === before,
    "undo after actual paste retains all earlier own entered contact links",
  );
  for (const width of [320, 390, 820]) {
    await page.setViewportSize({ width, height: 1000 });
    await editor.locator("[data-rt-link]").click();
    await ready(page);
    check(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "filled actual editor fits viewport " + width,
    );
    for (const selector of [
      ".kh-rte-toolbar button",
      ".kh-rte-toolbar select",
      ".kh-rte-menu summary",
      "[data-rt-link-kind]",
      "[data-rt-link-url]",
      "[data-rt-link-apply]",
      "[data-rt-link-cancel]",
    ]) {
      const dimensions = await editor.locator(selector).evaluateAll((nodes) =>
        nodes.map((node) => {
          const box = node.getBoundingClientRect();
          return {
            control: node.textContent.trim(),
            height: box.height,
            width: box.width,
            font: parseFloat(getComputedStyle(node).fontSize),
          };
        }),
      );
      if (
        !dimensions.every(
          (node) =>
            node.height >= 43.9 && node.width >= 43.9 && node.font >= 15.9,
        )
      )
        console.log(JSON.stringify({ selector, width, dimensions }));
      check(
        await editor.locator(selector).evaluateAll((nodes) =>
          nodes.every((node) => {
            const box = node.getBoundingClientRect();
            return (
              box.height >= 43.9 &&
              box.width >= 43.9 &&
              parseFloat(getComputedStyle(node).fontSize) >= 15.9
            );
          }),
        ),
        "actual " +
          selector +
          " has readable 44 pixel touch controls at " +
          width,
      );
    }
    await editor.locator("[data-rt-link-cancel]").click();
    for (const name of ["Farben", "Emoji", "Tabelle", "Weitere Formate"]) {
      const menu = editor
        .locator(".kh-rte-menu")
        .filter({
          has: page
            .locator("summary")
            .filter({ hasText: new RegExp(name + "$", "u") }),
        });
      await menu.locator("summary").click();
      check(
        await menu.locator(".kh-rte-popover").evaluate((node) => {
          const box = node.getBoundingClientRect();
          return (
            box.left >= -1 &&
            box.right <= innerWidth + 1 &&
            node.scrollWidth <= node.clientWidth + 1
          );
        }),
        "actual " + name + " touch menu fits viewport " + width,
      );
      await menu.locator("summary").click();
    }
  }
  await page.setViewportSize({ width: 640, height: 1100 });
  await page.evaluate(() => (document.body.style.zoom = "2"));
  await editor.locator("[data-rt-link]").click();
  check(
    await page.evaluate(
      () => document.documentElement.scrollWidth <= innerWidth + 1,
    ),
    "filled actual contact editor fits 200 percent zoom",
  );
  await editor.locator("[data-rt-link-cancel]").click();
  await page.evaluate(() => (document.body.style.zoom = ""));
  await page.setViewportSize({ width: 390, height: 1000 });
  if (process.env.DVM_CONTACT_LINK_SCREENSHOT) {
    await mkdir(
      new URL(".", "file://" + process.env.DVM_CONTACT_LINK_SCREENSHOT),
      { recursive: true },
    );
    await editor.screenshot({ path: process.env.DVM_CONTACT_LINK_SCREENSHOT });
  }
  const post = page.waitForResponse(
    (response) => response.request().method() === "POST",
  );
  await editor
    .locator("xpath=ancestor::form")
    .getByRole("button", { name: "Entwurf speichern", exact: true })
    .click();
  check(
    (await post).status() === 200,
    "actual JS manager saves one selected own draft through its native action",
  );
  await page.goto(url);
  await ready(page);
  check(
    (await page.locator('[name="body_html"]').inputValue()) === before,
    "actual server reload returns the exact original client contact links",
  );
  check(posts === 1, "exactly one actual native own draft save occurred");
  await context.close();
  const native = await ownContext(false),
    nativePage = await native.newPage();
  check(
    (await nativePage.goto(url)).status() === 200,
    "actual saved own contact links remain readable without JavaScript",
  );
  await ready(nativePage);
  const savedEditor = nativePage
    .locator("[data-kh-richtext]")
    .filter({ has: nativePage.locator('[name="body_html"]') });
  check(
    (await savedEditor.locator('[name="body_html"]').inputValue()) === before &&
      (await savedEditor.locator("[data-richtext-surface] a").count()) === 4,
    "native fallback preserves every exact own stored contact target",
  );
  check(
    (await savedEditor
      .locator("[data-richtext-surface]")
      .getAttribute("contenteditable")) === "false",
    "native fallback honestly retains its existing read only formatted text",
  );
  check(
    posts === 1 && errors.length === 0 && external.length === 0,
    "all actual contact editing has one own save and no runtime errors or external calls",
  );
  await native.close();
  console.log(
    JSON.stringify({
      suite: "contact-links-browser",
      checks,
      native_posts: posts,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
