import {kDirectory,kOrigin,kStorage,kConfig,kSource} from './support/k-browser-client.mjs';
import assert from "node:assert/strict";
import { readFile, mkdir, writeFile } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const root = fileURLToPath(new URL("../../", import.meta.url)),
  out = kDirectory()+"";
let checks = 0,
  actions = 0;
const check = (ok, label) => {
  assert(ok, label);
  ++checks;
  console.log("PASS " + label);
};
await mkdir(out, { recursive: true, mode: 0o700 });
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
try {
  for (const standalone of [false, true]) {
    const name = standalone ? "standalone" : "integrated",
      origin = kOrigin(standalone);
    const original = JSON.parse(
      await readFile(out + "/home-layout-" + name + ".json", "utf8"),
    );
    const errors = [],
      external = [];
    const setup = async (javaScriptEnabled = true) => {
      const context = await browser.newContext({ javaScriptEnabled,storageState:kStorage(standalone) });
      await context.route("**/*", (route) =>
        new URL(route.request().url()).origin === origin
          ? route.continue()
          : (external.push(route.request().url()), route.abort()),
      );
      const page = await context.newPage();
      page.on("pageerror", (e) => errors.push(e.message));
      return { context, page };
    };
    const { context, page } = await setup();
    const editorUrl =
      origin +
      (standalone ? "/?" : "/vereinsverwaltung/?") +
      new URLSearchParams(
        standalone
          ? {
              view: "website",
              item: original.id,
              compare_from: String(original.revision),
            }
          : {
              view: "website",
              tab: "pages",
              native_item: original.id,
              compare_from: String(original.revision),
            },
      );
    const publicUrl = origin + "/site/start" + (standalone ? "" : "/");
    const before = await context.request.get(publicUrl);
    check(before.status() === 200, name + " owned default home is public");
    const bytes = await before.body();
    await writeFile(kDirectory()+'/home-before-'+name+'.html',bytes,{flag:'wx',mode:0o600});
    await page.goto(editorUrl);
    const saveForm = () =>
      page.locator("form").filter({
        has: page.locator("input[name=operation][value=page-save]"),
      });
    const current = async () =>
      Number(await saveForm().locator("[name=version]").inputValue());
    const submit = async (form, button) => {
      const response = page.waitForResponse(
        (r) =>
          r.request().method() === "POST" && new URL(r.url()).origin === origin,
      );
      await form.getByRole("button", { name: button, exact: true }).click();
      const saved=await response;
      const status=saved.status();
      const json=saved.request().headers().accept?.includes('application/json')===true;
      if(status!==(json?200:303))console.log('Owned home save diagnostic',{status,json,type:saved.headers()['content-type'],notice:await page.locator('.notice,.form-errors,[role=alert]').allTextContents()});
      check(
        status === (json ? 200 : 303),
        name + " actual protected " + button + " succeeds",
      );
      if(json){
        const result=await saved.json(),destination=new URL(result.url,origin);
        check((standalone?typeof result.html==='string'&&result.html.includes('page-save'):result.ok===true)&&destination.origin===origin&&destination.searchParams.get('view')==='website'&&destination.searchParams.get(standalone?'item':'native_item')===original.id,name+' negotiated app response confirms the exact protected page save');
      }
      ++actions;
    };
    const startVersion = await current();
    await page.locator("[data-home-layout] > summary").click();
    await saveForm().locator("[name=home_layout_enabled]").check();
    await saveForm()
      .locator('[name="home_layout[hero_title]"]')
      .fill("Own browser home hero <literal>");
    const hero = saveForm()
      .locator(".kh-rte")
      .filter({ has: page.locator('[name="home_layout[hero_html]"]') });
    await hero
      .locator("[contenteditable=true]")
      .fill("Own browser formatted hero");
    await saveForm()
      .locator('[name="home_sections[0]"]')
      .selectOption("content");
    await saveForm()
      .locator('[name="home_sections[1]"]')
      .selectOption("highlights");
    for (let slot = 2; slot < 5; ++slot)
      await saveForm()
        .locator('[name="home_sections[' + slot + ']"]')
        .selectOption("");
    await saveForm()
      .locator("[name=home_highlights]")
      .fill("Own browser music\nOwn browser volunteering");
    await submit(saveForm(), "Entwurf speichern");
    await page.waitForFunction(
      (v) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form?.querySelector("[name=version]")?.value,
        ) === v,
      startVersion + 1,
    );
    check(
      (await context.request.get(publicUrl)).status() === 200 &&
        Buffer.compare(
          await (await context.request.get(publicUrl)).body(),
          bytes,
        ) === 0,
      name + " saved custom draft retains exact public home",
    );
    for (const width of [1440, 820, 390]) {
      await page.setViewportSize({ width, height: 1050 });
      await page.goto(editorUrl);
      await page.locator("[data-home-layout] > summary").click();
      check(
        await saveForm()
          .locator("[name=home_layout_enabled]")
          .evaluate((e) => e.checked && e.classList.contains("check")),
        name + " saved custom choice uses Bootstrap 6 checkbox " + width,
      );
      check(
        (await saveForm()
          .locator('[name="home_layout[hero_title]"]')
          .inputValue()) === "Own browser home hero <literal>",
        name + " original configured hero retained " + width,
      );
      check(
        (await saveForm().locator('[name="home_sections[0]"]').inputValue()) ===
          "content" &&
          (await saveForm()
            .locator('[name="home_sections[2]"]')
            .inputValue()) === "",
        name + " order and hidden section retained " + width,
      );
      check(
        await saveForm()
          .locator('[name="home_sections[1]"]')
          .evaluate(
            (e) =>
              e.classList.contains("form-control") && e.labels.length === 1,
          ),
        name + " Bootstrap controls have associated labels " + width,
      );
      const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth > innerWidth + 1,
      );
      check(!overflow, name + " manager has no horizontal overflow " + width);
      await page.screenshot({
        path: out + "/dvm-home-layout-" + name + "-" + width + ".png",
        fullPage: true,
      });
      await page
        .locator("[data-home-layout] > summary")
        .scrollIntoViewIfNeeded();
      await page.screenshot({
        path: out + "/dvm-home-layout-focus-" + name + "-" + width + ".png",
      });
      const previewLink = page.getByRole("link", {
        name: "Gespeicherte Startseite privat ansehen ↗",
        exact: true,
      });
      const href = await previewLink.getAttribute("href"),
        preview = await context.request.get(new URL(href, origin).href);
      check(
        preview.status() === 200 &&
          preview.headers()["cache-control"] === "no-store" &&
          preview.headers()["x-robots-tag"] === "noindex, nofollow",
        name + " actual private preview is protected and uncached " + width,
      );
      const previewPage = await context.newPage();
      await previewPage.setViewportSize({ width, height: 1050 });
      await previewPage.goto(new URL(href, origin).href);
      check(
        (await previewPage.locator("h1").textContent()) ===
          "Own browser home hero <literal>" &&
          (await previewPage.locator("[role=status]").textContent()).includes(
            "Private Startseitenvorschau",
          ),
        name + " private preview renders saved draft " + width,
      );
      check(
        (await previewPage
          .locator(".native-home-intro + .culture-strip")
          .count()) === 1 &&
          (await previewPage.locator("#termine,.section--news").count()) === 0,
        name + " private preview respects ordered visible sections " + width,
      );
      check(
        await previewPage.evaluate(() => {
          const poster = document.querySelector(".hero__poster strong");
          const singleLine =
            !poster ||
              poster.offsetHeight <=
              parseFloat(getComputedStyle(poster).fontSize) * 1.3;
          return (
            document.documentElement.scrollWidth <= innerWidth + 1 && singleLine
          );
        }),
        name + " private website preview has no horizontal overflow " + width,
      );
      await previewPage.screenshot({
        path: out + "/dvm-home-preview-" + name + "-" + width + ".png",
        fullPage: true,
      });
      await previewPage.close();
    }
    const plain = await setup(false);
    await plain.page.goto(editorUrl);
    await plain.page.locator("[data-home-layout] > summary").click();
    const plainForm = plain.page.locator("form").filter({
      has: plain.page.locator("input[name=operation][value=page-save]"),
    });
    await plainForm
      .locator('[name="home_sections[1]"]')
      .selectOption("content");
    const invalid = plain.page.waitForResponse(
      (r) =>
        r.request().method() === "POST" && new URL(r.url()).origin === origin,
    );
    await plainForm
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    check(
      (await invalid).status() === 422,
      name +
        " invalid repeated section rejected through actual JavaScript-free POST",
    );
    await page.goto(editorUrl);
    check(
      (await current()) === startVersion + 1,
      name + " rejected duplicate leaves exact current version",
    );
    await plain.page.goto(editorUrl);
    await plain.page.locator("[data-home-layout] > summary").click();
    await plainForm
      .locator('[name="home_layout[poster_main]"]')
      .fill("OWN CLUB");
    const plainResponse = plain.page.waitForResponse(
      (r) =>
        r.request().method() === "POST" && new URL(r.url()).origin === origin,
    );
    await plainForm
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    check(
      (await plainResponse).status() === 303,
      name + " valid JavaScript-free layout save succeeds",
    );
    ++actions;
    await plain.context.close();
    await page.goto(editorUrl);
    check(
      (await current()) === startVersion + 2,
      name + " JavaScript-free save creates one exact new private revision",
    );
    const privateBytes=await(await context.request.get(publicUrl)).body();
    await writeFile(kDirectory()+'/home-private-'+name+'.html',privateBytes,{flag:'wx',mode:0o600});
    check(
      Buffer.compare(
        privateBytes,
        bytes,
      ) === 0,
      name + " all private layout operations preserve public bytes",
    );
    await submit(
      page.locator("form").filter({
        has: page.locator("input[name=operation][value=page-publish]"),
      }),
      "Veröffentlichen",
    );
    await page.waitForFunction(
      (v) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form?.querySelector("[name=version]")?.value,
        ) === v,
      startVersion + 3,
    );
    const published = await context.newPage();
    await published.goto(publicUrl);
    check(
      (await published.locator("h1").textContent()) ===
        "Own browser home hero <literal>" &&
        (await published
          .locator(".native-home-intro + .culture-strip")
          .count()) === 1 &&
        (await published.locator("#termine,.section--news").count()) === 0,
      name + " explicit own synthetic publication applies exact saved layout",
    );
    await published.close();
    const historyPage = Math.ceil(
      ((await current()) - original.revision + 1) / 25,
    );
    await page.goto(editorUrl + "&versions_page=" + historyPage);
    await submit(
      page.locator('[data-saved-version="' + original.revision + '"] form'),
      "Als Entwurf laden",
    );
    await page.waitForFunction(
      (v) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form?.querySelector("[name=version]")?.value,
        ) === v,
      startVersion + 4,
    );
    check(
      !(await saveForm().locator("[name=home_layout_enabled]").isChecked()),
      name + " historical original restoration recovers default home draft",
    );
    const stillPublic = await context.request.get(publicUrl);
    check(
      (await stillPublic.text()).includes(
        "Own browser home hero &lt;literal&gt;",
      ),
      name + " private historical restoration keeps published custom home",
    );
    await submit(
      page.locator("form").filter({
        has: page.locator("input[name=operation][value=page-publish]"),
      }),
      "Veröffentlichen",
    );
    await page.waitForFunction(
      (v) =>
        Number(
          document
            .querySelector("input[name=operation][value=page-save]")
            ?.form?.querySelector("[name=version]")?.value,
        ) === v,
      startVersion + 5,
    );
    const restoredBytes=await(await context.request.get(publicUrl)).body();
    await writeFile(kDirectory()+'/home-restored-'+name+'.html',restoredBytes,{flag:'wx',mode:0o600});
    const baselineHtml=bytes.toString(),restoredHtml=restoredBytes.toString();
    const contextAttribute=/\bdata-url-context='([^']*)'/g;
    const baselineContexts=[...baselineHtml.matchAll(contextAttribute)],restoredContexts=[...restoredHtml.matchAll(contextAttribute)];
    check(baselineContexts.length===1&&restoredContexts.length===1,name+' original public URL context remains unique');
    const baselineContext=JSON.parse(baselineContexts[0][1]),restoredContext=JSON.parse(restoredContexts[0][1]);
    check(baselineContext.published_revision===original.revision&&restoredContext.published_revision===startVersion+5&&restoredContext.generation===baselineContext.generation+2,name+' two explicit publications advance the exact public source and cache generations');
    const sameContext={...restoredContext,published_revision:baselineContext.published_revision,generation:baselineContext.generation};
    check(JSON.stringify(sameContext)===JSON.stringify(baselineContext),name+' every other original public routing field remains exact');
    check(restoredHtml.replace(restoredContexts[0][0],baselineContexts[0][0])===baselineHtml,name+' restored original content and complete markup remain byte-identical outside those two required counters');
    check(errors.length === 0, name + " no browser runtime errors");
    check(external.length === 0, name + " no external requests");
    await context.close();
  }
  console.log(
    JSON.stringify({ checks, local_actions: actions, external_actions: 0 }),
  );
} finally {
  await browser.close();
}
