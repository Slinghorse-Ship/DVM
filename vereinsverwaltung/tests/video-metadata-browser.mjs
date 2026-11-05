import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_VIDEO_METADATA_FIXTURE, "utf8"),
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const errors = [],
  outside = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
try {
  const context = await browser.newContext({
    viewport: { width: 390, height: 1000 },
    hasTouch: true,
  });
  context.on("request", (request) => {
    if (request.method() === "POST") posts++;
    if (!request.url().startsWith(fixture.origin + "/"))
      outside.push(request.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(String(error)));
  const url =
    fixture.origin + "/?view=social&item=" + fixture.item + "&video_page=1";
  await page.goto(url);
  await page.locator(".social-video-card-tools > summary").first().click();
  const panel = page.locator("#social-videos"),
    details = panel.locator(".social-video-metadata").first();
  const form = details.locator("form").filter({
    has: page.locator(
      "input[name=operation][value=social-video-metadata-save]",
    ),
  });
  check(
    (await details.getAttribute("open")) === null,
    "original source details start closed instead of creating a long page",
  );
  await details.locator(":scope > summary").click();
  check(
    (await form.locator("input[name=metadata_version]").inputValue()) === "0",
    "new source begins with genuinely neutral metadata version",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(
        (z) => (document.documentElement.style.zoom = String(z)),
        zoom,
      );
      const result = await form.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        targets: [
          ...root.querySelectorAll(
            "input:not([type=hidden]):not([type=checkbox]),select,textarea,button,summary",
          ),
        ]
          .filter((node) => node.getBoundingClientRect().height > 0)
          .map((node) => ({
            tag: node.tagName,
            name: node.getAttribute("name"),
            height: node.getBoundingClientRect().height,
            font: parseFloat(getComputedStyle(node).fontSize),
          })),
      }));
      check(
        result.fit,
        "actual source details fit " + width + "px at " + zoom + "x",
      );
      check(
        result.targets.every((t) => t.height >= 43.5 && t.font >= 15.5),
        "actual source fields and save controls stay touchable at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 1000 });
  await form.locator(".video-metadata-links > summary").click();
  await form.locator(".video-metadata-rights > summary").click();
  for (const [name, value] of Object.entries({
    alt: "Own browser video description",
    credit: "Own browser original credit",
    source_name: "Own browser camera",
    source_url: "https://example.invalid/browser-source",
    license_name: "Own browser licence",
    license_url: "https://example.invalid/browser-licence",
  }))
    await form.locator('[name="metadata[' + name + ']"]').fill(value);
  await form
    .locator('select[name="metadata[rights_state]"]')
    .selectOption("cleared");
  await form
    .locator('select[name="metadata[consent_state]"]')
    .selectOption("recorded");
  await form.getByText("Privater Nachweis", { exact: false }).first().click();
  await form.locator("input[name=replace_proof]").check();
  await form
    .locator("textarea[name=private_proof]")
    .fill("Own private browser proof, never public");
  const original = await form.evaluate((node) =>
    new URLSearchParams(new FormData(node)).toString(),
  );
  const invalid = new URLSearchParams(original);
  invalid.set("csrf", "invalid-original-csrf");
  const rejected = await context.request.post(
    fixture.origin + "/?view=social&item=" + fixture.item,
    {
      data: invalid.toString(),
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      maxRedirects: 0,
    },
  );
  posts++;
  check(
    rejected.status() >= 400 && rejected.status() < 500,
    "actual invalid CSRF cannot change source metadata",
  );
  let response = page.waitForResponse((r) => r.request().method() === "POST");
  await form.getByRole("button", { name: "Angaben speichern" }).click();
  check(
    (await response).status() === 200,
    "actual native metadata form saves public fields and encrypted private proof",
  );
  await details.locator('[data-metadata-status]').filter({hasText:'Videoangaben gespeichert.'}).waitFor();
  await page.reload();
  await page.locator(".social-video-card-tools > summary").first().click();
  await details.locator(":scope > summary").click();
  check(
    (await form.locator("input[name=metadata_version]").inputValue()) === "1" &&
      (await form.locator('input[name="metadata[credit]"]').inputValue()) ===
        "Own browser original credit",
    "actual response shows precisely the saved source revision",
  );
  check(
    (await form.locator("textarea[name=private_proof]").inputValue()) === "" &&
      (await form
        .innerText()
        .then((text) => !text.includes("Own private browser proof"))),
    "saved private proof is never preloaded or exposed in ordinary source form",
  );
  const stale = await form.evaluate((node) =>
    new URLSearchParams(new FormData(node)).toString(),
  );
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await panel.getByRole("button", { name: "Diese Fassung freigeben" }).click();
  check(
    (await response).status() === 200,
    "actual selected video approval captures the saved metadata revision",
  );
  await page.reload();
  await page.locator(".social-video-card-tools > summary").first().click();
  check(
    (await panel.locator("article .badge").innerText()) === "Freigegeben",
    "current exact source approval is visible",
  );
  await details.locator(":scope > summary").click();
  await form
    .locator('input[name="metadata[credit]"]')
    .fill("Own browser revised credit");
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await form.getByRole("button", { name: "Angaben speichern" }).click();
  check(
    (await response).status() === 200,
    "actual editor update saves a new public source revision",
  );
  await details.locator('[data-metadata-status]').filter({hasText:'Videoangaben gespeichert.'}).waitFor();
  await page.reload();
  await page.locator(".social-video-card-tools > summary").first().click();
  check(
    (await panel.locator("article .badge").innerText()) ===
      "Freigabe erneuern",
    "changed original source details never retain a misleading approval mark",
  );
  const conflict = await context.request.post(
    fixture.origin + "/?view=social&item=" + fixture.item,
    {
      data: stale,
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      maxRedirects: 0,
    },
  );
  posts++;
  check(
    conflict.status() === 409,
    "actual stale source revision is rejected without promotion",
  );
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await panel
    .getByRole("button", { name: "Neue Freigabe vorbereiten" })
    .click();
  check(
    (await response).status() === 200,
    "actual conscious review renewal prepares a new content-bound artifact",
  );
  await page.reload();
  await page.locator(".social-video-card-tools > summary").first().click();
  check(
    (await panel.locator("article").count()) === 2 &&
      (await panel
        .locator("input[name=video_id]")
        .filter({
          hasNot: page.locator(
            "input[name=operation][value=social-video-renew]",
          ),
        })
        .count()) === 1,
    "renewal retains the earlier original and exposes only one current approval choice",
  );
  response = page.waitForResponse((r) => r.request().method() === "POST");
  await panel.getByRole("button", { name: "Diese Fassung freigeben" }).click();
  check(
    (await response).status() === 200,
    "actual latest source revision can be explicitly approved",
  );
  await page.reload();
  await page.locator(".social-video-card-tools > summary").first().click();
  check(
    (await panel.locator("article .badge").innerText()) === "Freigegeben",
    "exact renewed source approval appears immediately",
  );
  await details.locator(":scope > summary").click();
  check(
    (await form.locator("input[name=metadata_version]").inputValue()) === "2" &&
      (await form.locator('input[name="metadata[credit]"]').inputValue()) ===
        "Own browser revised credit",
    "renewed review retains the actual current public source fields",
  );
  check(
    (await form.locator("textarea[name=private_proof]").inputValue()) === "",
    "renewed source view still does not preload private consent text",
  );
  await mkdir(".local/browser-tests/video-metadata", { recursive: true });
  await details.screenshot({
    path: ".local/browser-tests/video-metadata/mobile.png",
  });
  const plainContext = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 900 },
  });
  const plain = await plainContext.newPage();
  await plain.goto(url);
  await plain.locator(".social-video-card-tools > summary").first().click();
  await plain
    .locator(".social-video-metadata")
    .first()
    .locator(":scope > summary")
    .click();
  check(
    (await plain
      .locator("input[name=metadata_version]")
      .first()
      .inputValue()) === "2",
    "actual source details remain usable without JavaScript",
  );
  await plainContext.close();
  check(
    posts === 7,
    "exactly five conscious accepted and two rejected submissions were sent",
  );
  check(
    errors.length === 0 && outside.length === 0,
    "actual metadata and review flow has no script errors or external requests",
  );
  console.log(
    JSON.stringify({
      suite: "video-metadata-browser",
      checks,
      posts,
      live_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
