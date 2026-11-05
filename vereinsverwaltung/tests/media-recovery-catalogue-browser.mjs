import assert from "node:assert/strict";
import { readFile, mkdir } from "node:fs/promises";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const fixture = JSON.parse(
  await readFile(process.env.DVM_RECOVERY_CATALOGUE_FIXTURE, "utf8"),
);
assert.equal(new URL(fixture.origin).hostname, "127.0.0.1");
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
  for (const javascript of [true, false]) {
    const context = await browser.newContext({
      viewport: { width: 390, height: 1000 },
      hasTouch: true,
      javaScriptEnabled: javascript,
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
    await page.goto(fixture.origin + "/?view=media&section=storage");
    await page
      .getByRole("navigation", { name: "Speicheransichten" })
      .getByRole("link", { name: "Fortsetzen", exact: true })
      .click();
    await page.waitForURL("**storage_recoveries=1*");
    check(
      (await page.locator(".media-storage form [name=operation]").count()) ===
        0,
      "actual catalogue includes no unrelated quota, source or bulk form with JavaScript " +
        javascript,
    );
    for (const width of [320, 390, 820])
      for (const zoom of [1, 2]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.evaluate((z) => {
          document.documentElement.style.zoom = String(z);
        }, zoom);
        const proof = await page.locator(".media-storage").evaluate((root) => ({
          fit: root.scrollWidth <= root.clientWidth + 1,
          targets: [...root.querySelectorAll("a")]
            .filter((node) => node.getBoundingClientRect().height > 0)
            .map((node) => ({
              height: node.getBoundingClientRect().height,
              font: parseFloat(getComputedStyle(node).fontSize),
            })),
          cards: root.querySelectorAll("[data-recovery-target]").length,
        }));
        if (
          !proof.fit ||
          proof.targets.some((t) => t.height < 43.5 || t.font < 15.5)
        )
          console.log(JSON.stringify({ width, zoom, javascript, proof }));
        check(
          proof.fit && proof.cards <= 6,
          "actual bounded pending list fits " +
            width +
            "px/" +
            zoom +
            "x with JavaScript " +
            javascript,
        );
        check(
          proof.targets.every((t) => t.height >= 43.5 && t.font >= 15.5),
          "actual pending navigation stays touchable and readable at " +
            width +
            "px/" +
            zoom +
            "x with JavaScript " +
            javascript,
        );
      }
    await page.evaluate(() => {
      document.documentElement.style.zoom = "1";
    });
    await page.setViewportSize({ width: 390, height: 1000 });
    const seen = [],
      cursors = [];
    for (let number = 0; number < 10; number++) {
      const rows = await page
        .locator("[data-recovery-target]")
        .evaluateAll((nodes) =>
          nodes.map((node) => ({
            id: node.dataset.recoveryTarget,
            href: node.querySelector("a")?.getAttribute("href"),
          })),
        );
      check(
        rows.length <= 6 &&
          rows.every(
            (row) => fixture.targets.includes(row.id) && !seen.includes(row.id),
          ),
        "actual metadata page contains only exact current own targets without duplicates with JavaScript " +
          javascript,
      );
      for (const row of rows) {
        seen.push(row.id);
        check(
          new URL(row.href, fixture.origin).searchParams.get(
            "storage_recoveries",
          ) === "1" &&
            (new URL(row.href, fixture.origin).searchParams.get(
              "recoveries_after",
            ) || "") ===
              (new URL(page.url()).searchParams.get("recoveries_after") || ""),
          "actual exact source link retains the pending catalogue context",
        );
      }
      const next = page
        .getByRole("link", { name: "Nächste Fotos →", exact: true })
        .first();
      if (!(await next.count())) break;
      const destination = new URL(
        await next.getAttribute("href"),
        fixture.origin,
      );
      const cursor = destination.searchParams.get("recoveries_after");
      assert.match(cursor, /^[a-f0-9]{32}$/);
      check(
        !cursors.includes(cursor),
        "actual next-page cursor progresses rather than repeating a metadata page",
      );
      cursors.push(cursor);
      await next.click();
      await page.waitForURL("**recoveries_after=" + cursor + "*");
    }
    check(
      JSON.stringify([...seen].sort()) ===
        JSON.stringify([...fixture.targets].sort()),
      "actual paged catalogue reaches all ten valid pending photos with JavaScript " +
        javascript,
    );
    await page
      .getByRole("navigation", { name: "Speicheransichten" })
      .getByRole("link", { name: "Nicht zugeordnet", exact: true })
      .click();
    await page.waitForURL("**storage_check=1*");
    check(
      (await page.locator("[data-recovery-target]").count()) === 0 &&
        (await page.locator("[data-storage-group]").count()) <= 6,
      "actual storage views stay separate and independently bounded",
    );
    if (javascript) {
      await mkdir(".local/browser-tests/media-recovery-catalogue", {
        recursive: true,
      });
      await page.goto(
        fixture.origin + "/?view=media&section=storage&storage_recoveries=1",
      );
      await page.locator(".media-storage").screenshot({
        path: ".local/browser-tests/media-recovery-catalogue/mobile.png",
      });
    }
    await context.close();
  }
  check(
    errors.length === 0 && outside.length === 0,
    "actual pending metadata navigation has no script error or external request",
  );
  console.log(
    JSON.stringify({
      suite: "media-recovery-catalogue-browser",
      checks,
      accepted_writes: 0,
      external_transport: false,
    }),
  );
} finally {
  await browser.close();
}
