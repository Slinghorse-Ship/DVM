import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_VIDEO_APPROVAL_FIXTURE, "utf8"),
);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0,
  posts = 0;
const outside = [],
  errors = [];
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
  const panel = page.locator("#social-videos");
  const selected = panel.locator("article").filter({
    has: page.locator('input[name=video_id][value="' + fixture.selected + '"]'),
  });
  const openSelected = async () => {
    const tools = panel.locator("article").filter({hasText:"Own browser selected sound.mp4"}).locator(".social-video-card-tools");
    if (!await tools.evaluate(node => node.open)) await tools.locator(":scope > summary").click();
  };
  await openSelected();
  check(
    (await page.locator("select[name=kind]").inputValue()) === "reel",
    "actual composer exposes the explicit reel format",
  );
  check(
    (await page.locator("select[name=template]").inputValue()) ===
      "social-reel",
    "actual original reel template is selected",
  );
  check(
    (await page.locator("#social-images").count()) === 0 &&
      (await page.locator("[data-image-studio]").count()) === 0,
    "reel form shows video choices without a misleading still-image generator",
  );
  check(
    (await panel.locator("form input[name=video_id]").count()) === 2,
    "both actual current original video versions can be consciously chosen",
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate(
        (z) => (document.documentElement.style.zoom = String(z)),
        zoom,
      );
      const result = await panel.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        controls: [
          ...root.querySelectorAll(
            "article button,article summary,article a[download]",
          ),
        ]
          .filter((node) => node.getBoundingClientRect().height > 0)
          .map((node) => ({
            height: node.getBoundingClientRect().height,
            font: parseFloat(getComputedStyle(node).fontSize),
          })),
      }));
      check(
        result.fit,
        "actual video selection fits " + width + "px at " + zoom + "x zoom",
      );
      check(
        result.controls.every(
          (control) => control.height >= 43.5 && control.font >= 15.5,
        ),
        "actual approval and playback targets stay touch friendly at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.evaluate(() => (document.documentElement.style.zoom = "1"));
  await page.setViewportSize({ width: 390, height: 1000 });
  const form = selected
      .locator("form")
      .filter({
        has: page.locator("input[name=operation][value=communication-approve]"),
      }),
    body = await form.evaluate((node) =>
      Object.fromEntries(new FormData(node).entries()),
    );
  const invalid = await context.request.post(
    fixture.origin + "/?view=social&item=" + fixture.item,
    { form: { ...body, csrf: "invalid-original-csrf" }, maxRedirects: 0 },
  );
  posts++;
  check(
    invalid.status() >= 400 && invalid.status() < 500,
    "actual invalid CSRF cannot approve a chosen video",
  );
  await page.reload();
  await openSelected();
  check(
    (await panel.locator("form input[name=video_id]").count()) === 2,
    "rejected CSRF retains both unapproved original choices",
  );
  const [response] = await Promise.all([
    page.waitForResponse((r) => r.request().method() === "POST"),
    selected.getByRole("button", { name: "Diese Fassung freigeben" }).click(),
  ]);
  check(
    response.status() === 200,
    "actual native approval form is submitted successfully",
  );
  await page.reload();
  await openSelected();
  check(
    (await panel
      .locator("article")
      .filter({ hasText: "Own browser selected sound.mp4" })
      .locator(".badge")
      .innerText()) === "Freigegeben",
    "selected sound original immediately carries the approval mark",
  );
  check(
    (await panel
      .locator("article")
      .filter({ hasText: "Own browser later silent.mp4" })
      .locator(".badge")
      .count()) === 0,
    "later alternative is never silently marked as approved",
  );
  check(
    (await panel.locator("form input[name=video_id]").count()) === 0,
    "approved original offers no duplicate approval action",
  );
  const stale = await context.request.post(
    fixture.origin + "/?view=social&item=" + fixture.item,
    { form: body, maxRedirects: 0 },
  );
  posts++;
  check(
    stale.status() >= 400 && stale.status() < 500,
    "actual stale original version is rejected after approval",
  );
  await page.reload();
  await openSelected();
  check(
    (await panel.locator("article .badge").count()) === 1,
    "duplicate rejection retains precisely one unchanged approved original",
  );
  await mkdir(".local/browser-tests/video-approval", { recursive: true });
  await panel.screenshot({
    path: ".local/browser-tests/video-approval/mobile.png",
  });
  const noScript = await browser.newContext({
    javaScriptEnabled: false,
    viewport: { width: 320, height: 900 },
  });
  const plain = await noScript.newPage();
  await plain.goto(url);
  await plain.locator("#social-videos article").filter({hasText:"Own browser selected sound.mp4"}).locator(".social-video-card-tools > summary").click();
  check(await plain.locator("#social-videos article .badge").isVisible(),"approved original is actually readable after opening its saved card without JavaScript");
  check(
    (await plain.locator("#social-videos article .badge").innerText()) ===
      "Freigegeben",
    "approved original remains visible without JavaScript",
  );
  await noScript.close();
  check(
    posts === 3,
    "exactly one accepted and two rejected form submissions were made",
  );
  check(
    errors.length === 0 && outside.length === 0,
    "actual selection and approval have no script errors or external requests",
  );
  console.log(
    JSON.stringify({
      suite: "video-approval-browser",
      checks,
      posts,
      live_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
