import assert from "node:assert/strict";
import {createHash} from "node:crypto";
import { readFile, writeFile, mkdir } from "node:fs/promises";
import { fileURLToPath } from "node:url";
const { chromium } = await import(
  process.env.PLAYWRIGHT_MODULE || "playwright"
);
const ownFixture=process.env.DVM_EVENT_PROGRAMME_FIXTURE?JSON.parse(await readFile(process.env.DVM_EVENT_PROGRAMME_FIXTURE,'utf8')):null;
let assertions=0;
const verify={equal:(...args)=>{assert.equal(...args);assertions++;},ok:(...args)=>{assert.ok(...args);assertions++;},deepEqual:(...args)=>{assert.deepEqual(...args);assertions++;}};
const root = fileURLToPath(new URL("../../", import.meta.url));
const fixture = ownFixture?.data??JSON.parse(
  await readFile(root + ".local/browser-tests/event-catalogue.json", "utf8"),
);
const credentials = ownFixture?.accounts??JSON.parse(
  await readFile(
    root +
      ".local/native-cms-integration/integrated-manager-runtime/browser-credentials.json",
    "utf8",
  ),
);
const origin = ownFixture?.origin??"http://127.0.0.1:8111";
const browser = await chromium.launch({
  executablePath: process.env.CHROMIUM_EXECUTABLE || "/usr/bin/chromium",
});
const checks = [],
  errors = [];
try {
  const context = await browser.newContext();
  await context.route("**/*", (route) =>
    new URL(route.request().url()).origin === origin
      ? route.continue()
      : route.abort(),
  );
  const page = await context.newPage();
  page.on("pageerror", (error) => errors.push(error.message));
  const fit = async () =>
    verify.ok(
      await page.evaluate(
        () => document.documentElement.scrollWidth <= innerWidth + 1,
      ),
      "horizontal overflow",
    );
  if(ownFixture){const basicContext=await browser.newContext({javaScriptEnabled:false,viewport:{width:320,height:950}});await basicContext.route('**/*',route=>new URL(route.request().url()).origin===origin?route.continue():route.abort());const basic=await basicContext.newPage();await basic.goto(origin+fixture.programme);await basic.evaluate(()=>document.documentElement.style.zoom='2');const form=basic.locator('form').filter({has:basic.locator('[name=q]')});await form.locator('[name=q]').fill('Programmprüfung');const filtering=basic.waitForResponse(response=>response.request().method()==='GET'&&new URL(response.url()).searchParams.get('q')==='Programmprüfung');await form.locator('button').click();verify.equal((await filtering).status(),200);await basic.waitForLoadState();await basic.evaluate(()=>document.documentElement.style.zoom='2');verify.equal(await basic.locator('.event-row').count(),20);const fits=await basic.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1);if(!fits)console.error(JSON.stringify(await basic.evaluate(()=>({width:innerWidth,scroll:document.documentElement.scrollWidth,overflow:[...document.querySelectorAll('input,select,button,form,.event-row,.event-filters,.site-header,main')].map(node=>{const r=node.getBoundingClientRect();return{tag:node.tagName,class:node.className,left:r.left,right:r.right,width:r.width,text:node.textContent.slice(0,60)}}).filter(row=>row.left<0||row.right>innerWidth+1).slice(0,25)}))));const artifact=process.env.DVM_BROWSER_ARTIFACT_DIR||ownFixture.root;await mkdir(artifact,{recursive:true});await basic.screenshot({path:artifact+'/event-programme-nojs-zoom.png'});verify.ok(fits,'actual programme and filters fit 320 at 200 percent without JavaScript');await basic.getByRole('link',{name:'Seite 2',exact:true}).click();verify.equal(await basic.locator('.event-row').count(),5);await basicContext.close();checks.push({width:320,javascript:false,zoom:2,actualFilter:true,pagination:true});}
  for (const width of (ownFixture?[1440,820,390,320]:[1440,820,390])) {
    await page.setViewportSize({ width, height: 900 });
    verify.equal((await page.goto(origin + fixture.programme)).status(), 200);
    await page
      .getByRole("heading", { name: "Programm 2050", exact: true })
      .waitFor();
    verify.equal(await page.locator(".event-row").count(), 20);
    verify.ok(
      (await page.locator("main").innerText()).includes("25 Veranstaltungen"),
    );
    verify.equal(
      await page.locator("[name=q]").inputValue(),
      "Programmprüfung",
    );
    verify.equal(await page.locator("[name=jahr]").inputValue(), "2050");
    if (width === 390) {
      const menu = page.getByRole("button", { name: "Menü", exact: true });
      await menu.click();
      verify.equal(await menu.getAttribute("aria-expanded"), "true");
      await page.keyboard.press("Escape");
      verify.equal(await menu.getAttribute("aria-expanded"), "false");
      verify.ok(await menu.evaluate((node) => node === document.activeElement));
    }
    await fit();
    await page.getByRole("link", { name: "Seite 2", exact: true }).click();
    verify.equal(await page.locator(".event-row").count(), 5);
    verify.ok(
      page.url().includes("jahr=2050") && page.url().includes("q=Programmpr"),
    );
    verify.equal(
      await page
        .getByRole("link", { name: "Seite 2", exact: true })
        .getAttribute("aria-current"),
      "page",
    );
    await fit();
    checks.push({ width, programme: true, pagination: true });
    await page.goto(origin + fixture.archive);
    await page
      .getByRole("heading", { name: "Programmarchiv", exact: true })
      .waitFor();
    verify.equal(await page.locator(".event-row").count(), 1);
    await page
      .getByRole("heading", { name: "Programmprüfung Archiv", exact: true })
      .waitFor();
    await fit();
    await page.goto(origin + fixture.detail);
    await page
      .getByRole("heading", { name: "Programmprüfung 1", exact: true })
      .waitFor();
    verify.ok((await page.locator("main").innerText()).includes("Testweg 12"));
    verify.ok(
      (await page.locator("main").innerText()).includes("Eintritt frei"),
    );
    if(ownFixture){const photo=page.locator('.event-detail-image img');verify.equal(await photo.count(),1);verify.equal(await photo.getAttribute('alt'),'Eigenes öffentliches Terminbild <Original>');await photo.evaluate(node=>node.decode());verify.ok(await photo.evaluate(node=>{const r=node.getBoundingClientRect();return node.naturalWidth>0&&r.width>0&&r.left>=-1&&r.right<=innerWidth+1;}),'own decoded event image fits viewport '+width);const original=await context.request.get(origin+await photo.getAttribute('src'));verify.equal(original.status(),200);verify.equal(createHash('sha256').update(await original.body()).digest('hex'),fixture.medium_sha);}
    const calendar = page.getByRole("link", {
      name: "Termin speichern",
      exact: true,
    });
    await calendar.focus();
    verify.ok(
      await calendar.evaluate((node) => node === document.activeElement),
    );
    const ics = await context.request.get(
      origin + (await calendar.getAttribute("href")),
    );
    verify.equal(ics.status(), 200);
    const text = (await ics.text()).replace(/\r\n[ \t]/g, "");
    verify.equal((text.match(/BEGIN:VEVENT\r\n/g) || []).length, 1);
    verify.ok(
      text.includes("DTSTART:20500102T180000Z") &&
        text.includes("DTEND:20500102T203000Z"),
    );
    const ld = JSON.parse(
      await page.locator('script[type="application/ld+json"]').textContent(),
    );
    verify.equal(ld.eventStatus, "https://schema.org/EventScheduled");
    verify.ok(ld.organizer.name);
    verify.equal(
      await page.getByRole("link", { name: "Tickets", exact: true }).count(),
      1,
    );
    await fit();
    await page.goto(origin + fixture.cancel);
    await page
      .getByRole("status")
      .filter({ hasText: "Diese Veranstaltung wurde abgesagt." })
      .waitFor();
    verify.equal(
      await page.getByRole("link", { name: "Tickets", exact: true }).count(),
      0,
    );
    const cancelled = await context.request.get(
      origin + "/feeds/termine/?event=" + fixture.cancel_id,
    );
    verify.equal(cancelled.status(), 200);
    verify.ok((await cancelled.text()).includes("STATUS:CANCELLED"));
    const old = await context.request.get(
      origin + "/programmbrowser-absage-alt/?quelle=kalender",
      { maxRedirects: 0 },
    );
    verify.equal(old.status(), 308);
    verify.equal(
      old.headers().location,
      "/veranstaltungen/eventbrite-9000000099/?quelle=kalender",
    );
    await fit();
    checks.push({
      width,
      archive: true,
      detail: true,
      cancellation: true,
      actualCalendar: true,
      keyboard: true,
    });
  }
  if(ownFixture){await page.goto(origin+'/own-test-enter?role=admin');}else{
  await page.goto(origin + "/login/");
  await page
    .getByLabel("E-Mail-Adresse oder persönlicher Benutzername", {
      exact: true,
    })
    .fill(credentials.admin.email);
  await page
    .getByLabel("Passwort", { exact: true })
    .fill(credentials.admin.password);
  await Promise.all([
    page.waitForURL("**/vereinsverwaltung/**"),
    page
      .getByRole("button", { name: "Anmelden und Vereinsmanager öffnen" })
      .click(),
  ]);
  }
  for (const width of (ownFixture?[1440,820,390,320]:[1440,820,390])) {
    await page.setViewportSize({ width, height: 900 });
    await page.goto(origin + fixture.editor);
    const form = page
      .locator("form")
      .filter({ has: page.locator("[name=operation][value=page-save]") });
    await form.locator("[name=event_status]").waitFor();
    verify.equal(
      await form.locator("[name=event_status]").inputValue(),
      "scheduled",
    );
    verify.equal(
      await form.locator("[name=event_end_local]").inputValue(),
      "2050-01-02T21:30",
    );
    await form.locator("[name=event_address]").fill("Testweg 12");
    const version = Number(await form.locator("[name=version]").inputValue());
    await form
      .getByRole("button", { name: "Entwurf speichern", exact: true })
      .click();
    await page.waitForFunction(
      (expected) =>
        Number(
          document
            .querySelector("[name=operation][value=page-save]")
            ?.form.querySelector("[name=version]")?.value,
        ) === expected,
      version + 1,
    );
    verify.equal(
      await form.locator("[name=event_end_local]").inputValue(),
      "2050-01-02T21:30",
    );
    await fit();
    checks.push({ width, authenticatedEditor: true, originalEnd: true });
  }
  verify.deepEqual(errors, []);
  const output = root + ".local/browser-tests/event-catalogue";
  if(!ownFixture){await mkdir(output, { recursive: true });
  await writeFile(
    output + "/result.json",
    JSON.stringify({ checks, errors, externalTransport: false }, null, 2) +
      "\n",
  );
  }
  console.log(JSON.stringify({suite:"native-event-programme-browser",assertions,cases:checks.length,widths:ownFixture?[1440,820,390,320]:[1440,820,390],external_actions:0}));
  console.log(
    "Event programme browser passed: filters, archive, pagination, original details, actual single ICS, cancellation, authenticated editor and keyboard at 1440/820/390 widths.",
  );
} finally {
  await browser.close();
}
