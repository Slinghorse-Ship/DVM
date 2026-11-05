'use strict';
const { chromium } = require('playwright');
const fs=require('node:fs'),assert=require('node:assert/strict');
(async()=>{
 const fixture=JSON.parse(fs.readFileSync(process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE,'utf8'));
 const base=new URL(fixture.base);assert.equal(base.origin,'http://127.0.0.1:8091');assert(fixture.club.startsWith('member-profile-'));
 const browser=await chromium.launch({headless:true,channel:'msedge'});
 try{
  const context=await browser.newContext({viewport:{width:1280,height:960}});
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.own,url:base.origin+'/'}]);
  await context.route('**/*',route=>new URL(route.request().url()).origin===base.origin?route.continue():route.abort());
  const page=await context.newPage();const errors=[];page.on('pageerror',err=>errors.push(err.message));
  const url=base.origin+'/?view=portal&tab=safety&volunteer=1&volunteer_kind=general';
  await page.goto(url);assert.equal(await page.locator('[name=person]').count(),0);
  await page.locator('[name=activity]').fill('Aufbau und Betreuung der Bühnentechnik bei Veranstaltungen.');
  assert.equal(await page.getByText('Bestätigung für das Kalenderjahr '+new Date().getFullYear(),{exact:true}).count(),1);
  await page.getByRole('button',{name:'Vollständige PDF-Vorschau erstellen',exact:true}).click();
  await page.locator('[data-volunteer-preview]').waitFor();
  const response=await page.request.get(new URL(await page.getByRole('link',{name:'PDF-Vorschau in neuem Tab öffnen'}).getAttribute('href'),page.url()).href);
  assert.equal(response.status(),200);assert((await response.body()).subarray(0,5).equals(Buffer.from('%PDF-')));
  await page.locator('[data-volunteer-save] [name=reviewed]').check();
  await page.getByRole('button',{name:'Geprüftes PDF ablegen',exact:true}).click();
  await page.getByRole('heading',{name:'Abgelegte Ehrenamtsbestätigung',exact:true}).waitFor();
  const download=await page.request.get(new URL(await page.getByRole('link',{name:'Abgelegtes PDF herunterladen'}).getAttribute('href'),page.url()).href);assert.equal(download.status(),200);
  await context.addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base.origin+'/'}]);
  await page.setViewportSize({width:390,height:844});await page.goto(url+'&person='+fixture.relative);
  assert.equal(await page.locator('[name=activity]').count(),0);assert.equal(await page.getByText('Passive Mitgliedschaft',{exact:true}).count(),1);
  assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));
  await page.getByRole('button',{name:'Vollständige PDF-Vorschau erstellen',exact:true}).click();await page.locator('[data-volunteer-preview]').waitFor();
  await page.screenshot({path:'.local/volunteer-general/mobile-preview.png',fullPage:true});
  assert.deepEqual(errors,[]);console.log('PASS own-member full preview/save/download and board passive mobile flow, no external requests');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
