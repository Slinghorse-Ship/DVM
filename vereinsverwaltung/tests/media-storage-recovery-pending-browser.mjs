import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_MEDIA_RECOVERY_PENDING_FIXTURE, "utf8"),
);
assert.equal(new URL(fixture.origin).hostname, "127.0.0.1");
assert.match(fixture.source, /^[a-f0-9]{32}$/);
const browser = await chromium.launch({
  executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || undefined,
});
let checks = 0;
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
    javaScriptEnabled: fixture.javascript,
  });
  await context.addCookies([
    {
      ...fixture.cookie,
      domain: "127.0.0.1",
      path: "/",
      httpOnly: true,
      sameSite: "Strict",
    },
  ]);
  context.on("request", (request) => {
    if (!request.url().startsWith(fixture.origin + "/"))
      outside.push(request.url());
  });
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(String(error)));
  const url =
    fixture.origin +
    "/?view=media&section=storage&recover_source=" +
    fixture.source;
  const catalogue =
    fixture.origin + "/?view=media&section=storage&storage_recoveries=1";
  await page.goto(catalogue);
  const card = page.locator('[data-recovery-target="' + fixture.target + '"]');
  const resumeLink = card.getByRole("link", {
    name: "Fortsetzen",
    exact: true,
  });
  check(
    (await page.locator("[data-recovery-target]").count()) <= 6 &&
      (await resumeLink.count()) === 1,
    "actual bounded continuation catalogue offers the exact current own original",
  );
  for (let attempt = 0; attempt < 30; attempt++) {
    const settled = await resumeLink.evaluate(
      (node) =>
        getComputedStyle(node).color === "rgb(255, 255, 255)" &&
        getComputedStyle(node).backgroundColor === "rgb(36, 86, 64)",
    );
    if (settled) break;
    await new Promise((resolve) => setTimeout(resolve, 50));
  }
  const colors = await resumeLink.evaluate((node) => ({
    color: getComputedStyle(node).color,
    background: getComputedStyle(node).backgroundColor,
    height: node.getBoundingClientRect().height,
  }));

  check(
    colors.color === "rgb(255, 255, 255)" &&
      colors.background === "rgb(36, 86, 64)" &&
      colors.height >= 43.5,
    "actual catalogue continuation has readable contrast and a touch target",
  );
  await resumeLink.click();
  await page.waitForURL("**recover_source=" + fixture.source + "*");
  check(
    new URL(page.url()).searchParams.get("storage_recoveries") === "1",
    "actual continuation navigation retains its bounded catalogue context",
  );
  const panel = page.locator(".media-storage-recovery"),
    form = panel.locator("form");
  const button = form.getByRole("button", { name: "Fortsetzen", exact: true });
  await button.waitFor({ state: "visible" });
  check(
    (await panel.getByRole("status").textContent()) ===
      "Wiederherstellung unterbrochen." && (await button.isEnabled()),
    "actual pending copy offers one explicit continuation with " +
      (fixture.javascript ? "JavaScript" : "native HTML"),
  );
  for (const width of [320, 390, 820])
    for (const zoom of [1, 2]) {
      await page.setViewportSize({ width, height: 1000 });
      await page.evaluate((z) => {
        document.documentElement.style.zoom = String(z);
      }, zoom);
      const proof = await panel.evaluate((root) => ({
        fit: root.scrollWidth <= root.clientWidth + 1,
        targets: [
          ...root.querySelectorAll("input:not([type=hidden]),button,summary"),
        ]
          .filter((n) => n.getBoundingClientRect().height > 0)
          .map((n) => ({
            height: n.getBoundingClientRect().height,
            font: parseFloat(getComputedStyle(n).fontSize),
          })),
      }));
      check(
        proof.fit,
        "pending continuation fits " + width + "px/" + zoom + "x",
      );
      check(
        proof.targets.every((t) => t.height >= 43.5 && t.font >= 15.5),
        "pending continuation remains touchable and readable at " +
          width +
          "px/" +
          zoom +
          "x",
      );
    }
  await page.evaluate(() => {
    document.documentElement.style.zoom = "1";
  });
  const fingerprint = await form
    .locator("[name=source_fingerprint]")
    .inputValue();
  check(
    fingerprint === fixture.fingerprint &&
      (await form.locator("[name=recovery_resume]").inputValue()) === "1",
    "actual continuation retains the original source proof and explicit pending state",
  );
  await form
    .locator("[name=recovery_name]")
    .fill("Own continued real photo.png");
  if (fixture.javascript) {
    const response = page.waitForResponse(
      (r) => r.request().method() === "POST",
    );
    await button.click();
    check(
      (await response).status() === 200,
      "actual pending photo saves through exactly one native continuation POST",
    );
    await page.waitForURL("**section=library**");
  } else {
    await Promise.all([page.waitForNavigation(), button.click()]);
    check(
      new URL(page.url()).searchParams.get("section") === "library",
      "actual native HTML continuation saves without JavaScript",
    );
  }
  check(
    new URL(page.url()).searchParams.get("item") === fixture.target,
    "actual continuation opens the same prepared distinct original identity",
  );
  check(
    !new URL(page.url()).searchParams.has("storage_recoveries") &&
      !new URL(page.url()).searchParams.has("recover_source"),
    "actual successful continuation clears obsolete storage context from the saved photo",
  );
  await page.goto(url);
  check(
    (await panel.locator("form").count()) === 0 &&
      (await panel
        .getByRole("link", { name: "Foto öffnen", exact: true })
        .count()) === 1,
    "actual continuation recheck opens the committed original without another copy form",
  );
  await page.goto(catalogue);
  check(
    (await page
      .locator('[data-recovery-target="' + fixture.target + '"]')
      .count()) === 0,
    "actual committed photo leaves the pending catalogue without changing the retained source",
  );
  check(
    errors.length === 0 && outside.length === 0,
    "pending copy has no script error or external request",
  );
  console.log(
    JSON.stringify({
      suite: "media-storage-recovery-pending-browser",
      checks,
      javascript: fixture.javascript,
      accepted_writes: 1,
      external_transport: false,
    }),
  );
  await context.close();
} finally {
  await browser.close();
}
