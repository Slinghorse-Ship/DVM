import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from "node:assert/strict";
import { readFile, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url));
const browser = await chromium.launch({
  executablePath:
    process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
let checks = 0;
const errors = [],
  external = [],
  writes = [];
const check = (ok, label) => {
  assert(ok, label);
  checks++;
  console.log("PASS " + label);
};
const poll = async (fn) => {
  for (let i = 0; i < 100; i++) {
    try {
      if (await fn()) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 100));
  }
  throw Error("Actual album catalogue did not become ready");
};
try {
  for (const standalone of [true, false]) {
    const name = standalone ? "standalone" : "integrated",
      origin = kOrigin(standalone);
    const f = JSON.parse(
      await readFile(
        kDirectory()+"/album-catalogue-" + name + ".json",
        "utf8",
      ),
    );
    const url = (q = f.prefix, status = "all", page = 1) =>
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams({
        ...(standalone
          ? { view: "media" }
          : { view: "website", tab: "gallery" }),
        q,
        status,
        album_list_page: String(page),
      });
    let expected = [...f.ids];
    for (const javaScriptEnabled of [true, false]) {
      const mode = name + (javaScriptEnabled ? " JS" : " native");
      const context = await browser.newContext({
        javaScriptEnabled,
        storageState:kStorage(standalone),
        hasTouch: true,
        viewport: { width: 320, height: 880 },
      });
      const previews = [];
      await context.route("**/*", (route) => {
        const request = route.request();
        if (new URL(request.url()).origin !== origin) {
          external.push(request.url());
          return route.abort();
        }
        if (request.method() === "POST") writes.push(request.url());
        if (
          request.url().includes("/media-preview/") ||
          request.url().includes("native_endpoint=media-preview")
        )
          previews.push(request.url());
        return route.continue();
      });
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      await page.goto(url(), { waitUntil: "domcontentloaded" });
      const displayed = () =>
        page
          .locator("[data-album-row]")
          .evaluateAll((nodes) => nodes.map((n) => n.dataset.albumRow));
      const choose = async (n) => {
        const pager = page.locator(".album-list-pager").first();
        if (
          Number(
            await pager.getByLabel("Albenseite", { exact: true }).inputValue(),
          ) === n &&
          (await displayed())[0] === expected[(n - 1) * 25]
        )
          return;
        await pager
          .getByLabel("Albenseite", { exact: true })
          .selectOption(String(n));
        await pager
          .getByRole("button", { name: "Anzeigen", exact: true })
          .click();
        await poll(
          async () =>
            Number(
              await page
                .locator('[name="album_list_page"]')
                .first()
                .inputValue(),
            ) === n && (await displayed())[0] === expected[(n - 1) * 25],
        );
      };
      for (const [i, width] of [320, 390, 820].entries()) {
        await page.setViewportSize({ width, height: 880 });
        await choose(i + 1);
        assert.deepEqual(
          await displayed(),
          expected.slice(i * 25, i * 25 + 25),
        );
        checks++;
        console.log("PASS " + mode + " exact complete album page " + (i + 1));
        await poll(() =>
          page.evaluate(() =>
            [...document.querySelectorAll('link[rel="stylesheet"]')].every(
              (link) => link.sheet,
            ),
          ),
        );
        await page.evaluate(() => (document.body.style.zoom = "2"));
        if (!standalone) check(await page.evaluate(() => {
          const app = document.querySelector('body > .app');
          const body = document.body.getBoundingClientRect();
          return app && getComputedStyle(app).marginLeft === '0px' && Math.abs(app.getBoundingClientRect().x - body.x) <= 1 && app.getBoundingClientRect().width >= body.width - 1;
        }), mode + ' hidden tablet navigation leaves the whole reading width at ' + width + ' and 200 percent');
        const geometry=await page.evaluate(()=>({viewport:innerWidth,root:{scroll:document.documentElement.scrollWidth,client:document.documentElement.clientWidth},tables:[...document.querySelectorAll('.gallery-manager .content-table-wrap')].map(n=>({scroll:n.scrollWidth,client:n.clientWidth,width:n.getBoundingClientRect().width})),overflow:[...document.querySelectorAll('body *')].filter(n=>n.checkVisibility({checkVisibilityCSS:true})&&n.getBoundingClientRect().right>innerWidth+2).slice(0,40).map(n=>({tag:n.tagName,id:n.id,classes:n.className,width:n.getBoundingClientRect().width,right:n.getBoundingClientRect().right,minWidth:getComputedStyle(n).minWidth,display:getComputedStyle(n).display,position:getComputedStyle(n).position,overflow:getComputedStyle(n).overflowX}))}));
        if(geometry.root.scroll>geometry.root.client+2||geometry.tables.some(n=>n.scroll>n.client+2)){
          const artifact=kDirectory()+'/'+name+'-'+(javaScriptEnabled?'js':'nojs')+'-overflow-'+width;
          await writeFile(artifact+'.json',JSON.stringify(geometry,null,2),{flag:'wx',mode:0o600});await page.screenshot({path:artifact+'.png',fullPage:true});console.log('Owned album overflow geometry',JSON.stringify(geometry));
        }
        check(
          await page.evaluate(
            () =>
              document.documentElement.scrollWidth <=
                document.documentElement.clientWidth + 2 &&
              [
                ...document.querySelectorAll(
                  ".gallery-manager .content-table-wrap",
                ),
              ].every((n) => n.scrollWidth <= n.clientWidth + 2),
          ),
          mode +
            " short rows fit " +
            width +
            " at 200 percent without sideways list scrolling",
        );
        if (!standalone && width === 820) await page.screenshot({path:kDirectory()+'/integrated-'+(javaScriptEnabled?'js':'nojs')+'-album-tablet-double-zoom.png'});
        const box = await page
          .locator(".album-list-pager")
          .first()
          .getByRole("button", { name: "Anzeigen", exact: true })
          .boundingBox();
        check(
          box.height >= 87 && box.width >= 87,
          mode + " actual scaled pager has 44 pixel touch targets",
        );
        await page.evaluate(() => (document.body.style.zoom = "1"));
      }
      check(
        previews.every((u) => u.includes(f.image)),
        mode + " list requests only actual visible own cover",
      );
      for (const [q, status, ids] of [
        [f.prefix + " caption 37 100%_!", "all", [f.ids[37]]],
        [f.prefix, "published", f.published],
        [f.prefix, "draft", f.ids.filter((id) => !f.published.includes(id))],
        ["own-body-only", "all", []],
      ]) {
        await page.goto(url(q, status), { waitUntil: "domcontentloaded" });
        assert.deepEqual(await displayed(), ids.slice(0, 25));
        checks++;
        console.log(
          "PASS " +
            mode +
            " actual literal caption/status search " +
            status +
            " " +
            q,
        );
        check(
          (await page.locator(".content-list-count").textContent()) ===
            ids.length + " Alben",
          mode + " exact filtered count includes every hidden short page",
        );
      }
      const target = f.ids[25],
        direction = javaScriptEnabled ? "up" : "down",
        returnPage = javaScriptEnabled ? 2 : 1;
      await page.goto(url(f.prefix, "all", returnPage), {
        waitUntil: "domcontentloaded",
      });
      const row = page.locator('[data-album-row="' + target + '"]');
      const form = row.locator("form").filter({
        has: page.locator('input[name="direction"][value="' + direction + '"]'),
      });
      const old = await form.evaluate((node) =>
        Object.fromEntries(new FormData(node)),
      );
      const oldUrl = page.url();
      await form.getByRole("button").click();
      [expected[24], expected[25]] = [expected[25], expected[24]];
      await poll(
        async () =>
          JSON.stringify(await displayed()) ===
            JSON.stringify(
              expected.slice((returnPage - 1) * 25, returnPage * 25),
            ) &&
          Number(
            await page.locator('[name="order_version"]').first().inputValue(),
          ) ===
            f.order_version + (javaScriptEnabled ? 1 : 2),
      );
      const returned = new URL(page.url());
      check(
        returned.searchParams.get("q") === f.prefix &&
          returned.searchParams.get("status") === "all" &&
          returned.searchParams.get("album_list_page") === String(returnPage) &&
          !returned.searchParams.get(standalone ? "item" : "native_item"),
        mode +
          " real global movement retains exact filtered overview and current page",
      );
      await choose(javaScriptEnabled ? 1 : 2);
      assert.deepEqual(
        await displayed(),
        expected.slice(javaScriptEnabled ? 0 : 25, javaScriptEnabled ? 25 : 50),
      );
      checks++;
      console.log(
        "PASS " +
          mode +
          " actual movement crosses the complete album page boundary",
      );
      if (javaScriptEnabled) {
        const rejected = await context.request.post(oldUrl, { form: old });
        check(
          rejected.status() === 409,
          mode + " actual stale order POST rejects once",
        );
        await page.goto(url(), { waitUntil: "domcontentloaded" });
        assert.deepEqual(await displayed(), expected.slice(0, 25));
        checks++;
        console.log(
          "PASS " +
            mode +
            " rejected order retains exact complete earlier movement",
        );
      }
      await context.close();
    }
  }
  check(
    writes.length === 4,
    "exactly four accepted browser order POSTs across both apps",
  );
  check(
    errors.length === 0,
    "both actual app routes have no JavaScript errors",
  );
  check(
    external.length === 0,
    "all browser requests stay on owned synthetic runtimes",
  );
  console.log(
    JSON.stringify({
      suite: "album-catalogue-app-browser",
      checks,
      writes: writes.length,
      rejected_writes: 2,
      external_requests: external.length,
    }),
  );
} finally {
  await browser.close();
}
