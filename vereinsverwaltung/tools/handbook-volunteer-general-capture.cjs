/** Actual local synthetic UI, explanatory callouts only; never submit a form. */
'use strict';
const fs=require('node:fs'),path=require('node:path'),crypto=require('node:crypto'),assert=require('node:assert/strict');
const {chromium}=require('playwright'),{capture}=require('./handbook-capture.cjs');
(async()=>{
 const root=path.resolve(__dirname,'../..');
 const f=JSON.parse(fs.readFileSync(process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,'utf8'));
 const origin=new URL(f.base).origin;
 assert.equal(origin,'http://127.0.0.1:8091');assert(f.club.startsWith('member-profile-'));
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try {
  const context=await browser.newContext({viewport:{width:1100,height:1000},serviceWorkers:'block'});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:f.sessions.admin,url:origin+'/'}]);
  await context.route('**/*',r=>new URL(r.request().url()).origin===origin&&r.request().method()==='GET'?r.continue():r.abort());
  const page=await context.newPage(),figures={};
  const go=async person=>{const response=await page.goto(origin+'/?view=portal&tab=safety&volunteer=1&volunteer_kind=general&person='+person);assert.equal(response.headers()['x-kulturhus-local-project'],f.project_hash);};
  await go(f.member);
  await page.locator('[name=activity]').fill('Erfundenes Beispiel: Aufbau und Betreuung der Bühnentechnik bei Vereinsveranstaltungen.');
  figures['ehrenamt-allgemein-aktiv']=await capture(page,'ehrenamt-allgemein-aktiv','Aktive Mitgliedschaft: jährliche Bestätigung und vorgeschlagene Aufgaben prüfen',[
   [page.getByText('Aktive Mitgliedschaft',{exact:true}).locator('..'),'Die Mitgliedschaft stammt aus dem Mitgliederprofil. Nur der Vorstand kann sie dort berichtigen.'],
   [page.locator('[name=activity]').locator('..'),'Der Text zur aktiven Mithilfe ist vorgeschlagen. Prüfe ihn und ergänze tatsächliche Aufgaben.'],
   [page.getByText('Bestätigung für das Kalenderjahr '+new Date().getFullYear(),{exact:true}).locator('..'),'Die Bestätigung bezieht sich automatisch auf das aktuelle Kalenderjahr.'],
   [page.locator('[name=effort]').locator('..'),'Den zeitlichen Umfang kannst du freiwillig ergänzen.']
  ]);
  await go(f.relative);assert.equal(await page.locator('[name=activity]').count(),0);
  figures['ehrenamt-allgemein-passiv']=await capture(page,'ehrenamt-allgemein-passiv','Passive Mitgliedschaft: Mitgliedsstatus bestätigen',[
   [page.getByText('Passive Mitgliedschaft',{exact:true}).locator('..'),'Bei passiver Mitgliedschaft werden keine aktiven Aufgaben oder Stunden abgefragt.'],
   [page.locator('fieldset').filter({has:page.locator('legend', {hasText:'Vereinsangaben im Formular'})}),'Der Vereinsname und die Anschrift kommen aus den Vereinseinstellungen.'],
   [page.getByRole('button',{name:'Vollständige PDF-Vorschau erstellen',exact:true}),'Erst eine vollständige Vorschau erstellen und prüfen. Dadurch wird noch kein Dokument abgelegt.']
  ]);
  for(const item of Object.values(figures)){
   item.captured_at='2026-09-09';item.privacy_reviewed_at='2026-09-09';
   item.privacy_review='Actual isolated local application; synthetic membership identifiers and fictional club only. Unsaved clearly fictional task example. No real member information, signatures or credentials. GET-only capture, no mail/push/document creation.';
   item.sha256=crypto.createHash('sha256').update(fs.readFileSync(path.join(root,'vereinsverwaltung/resources/handbook',item.file))).digest('hex');
  }
  fs.writeFileSync(path.join(root,'.local/volunteer-general-figures.json'),JSON.stringify(figures,null,2));
  console.log(Object.keys(figures));
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
