const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const assert=require('node:assert/strict'),fs=require('node:fs'),path=require('node:path');
(async()=>{
 const root=path.resolve(__dirname,'../..'),base=process.env.HANDBOOK_TEST_BASE||'http://127.0.0.1:8092/vereinsverwaltung/';
 assert(['http://127.0.0.1:8091/','http://127.0.0.1:8092/vereinsverwaltung/'].includes(base),'Only the isolated local test application is allowed');
 const expected=JSON.parse(fs.readFileSync(path.join(root,'vereinsverwaltung/resources/handbook-tutorials.json'),'utf8'));
 const chapterDirectory=path.join(root,'docs/vereinsverwaltung/handbuch');
 const chapterFiles=fs.readdirSync(chapterDirectory).filter(file=>/^\d\d-.*\.md$/.test(file)).sort();assert.equal(chapterFiles.length,20);
 const chapterSource=chapterFiles.map(file=>fs.readFileSync(path.join(chapterDirectory,file),'utf8')).join('\n');
 const expectedTasks=(chapterSource.match(/^### /gm)||[]).length;assert(expectedTasks>=185);
 const figureKeys=[...chapterSource.matchAll(/^:::bild ([a-z0-9-]+)\r?$/gm)].map(match=>match[1]);assert(figureKeys.length>=43);
 assert.equal(new Set(figureKeys).size,figureKeys.length,'Each rendered figure needs its own unique anchor');
 const figures=JSON.parse(fs.readFileSync(path.join(chapterDirectory,'figures.json'),'utf8'));
 const imageManifest=JSON.parse(fs.readFileSync(path.join(root,'vereinsverwaltung/resources/handbook-images.json'),'utf8'));
 assert.deepEqual(imageManifest,figureKeys.map(key=>figures[key].file));
 const browser=await chromium.launch({headless:true,channel:'msedge'}),page=await browser.newPage({viewport:{width:1280,height:960}});
 const fixtureFile=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE;
 const fixture=fixtureFile?JSON.parse(fs.readFileSync(fixtureFile,'utf8').replace(/^\uFEFF/,'')):null;
 if(fixture){assert(base==='http://127.0.0.1:8091/'&&fixture.club.startsWith('member-profile-'));await page.context().addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.admin,url:base}]);await page.context().route('**/*',r=>r.request().method()==='GET'&&new URL(r.request().url()).origin===new URL(base).origin?r.continue():r.abort());}
 const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('console',m=>{if(m.type()==='error'&&/Content Security Policy|Refused to/.test(m.text()))errors.push(m.text());});
 const initial=await page.goto(base+'?view=handbook');if(fixture)assert.equal(initial.headers()['x-kulturhus-local-project'],fixture.project_hash);
 assert.equal(await page.locator('.handbook-chapter').count(),chapterFiles.length);assert.equal(await page.locator('.handbook-task').count(),expectedTasks);
 const badAnchors=await page.locator('.handbook a[href^="#"]').evaluateAll(links=>links.filter(a=>!document.getElementById(a.hash.slice(1))).map(a=>a.hash));assert.deepEqual(badAnchors,[]);
 const ids=await page.locator('[id]').evaluateAll(nodes=>nodes.map(n=>n.id));assert.equal(new Set(ids).size,ids.length);
 assert.deepEqual(await page.locator('.handbook-figure').evaluateAll(nodes=>nodes.map(node=>node.id)),figureKeys.map(key=>'bild-'+key));
 for(const image of await page.locator('.handbook-figure img').all()){
  const response=await page.request.get(new URL(await image.getAttribute('src'),base).href);assert.equal(response.status(),200);assert.match(response.headers()['content-type'],/image\/png/);
 }
 await page.locator('.handbook-figure img').evaluateAll(images=>images.forEach(image=>image.loading='eager'));
 await page.waitForFunction(()=>[...document.querySelectorAll('.handbook-figure img')].every(image=>image.complete&&image.naturalWidth>0));
 assert.equal(await page.locator('.handbook-figure img').count(),figureKeys.length);
 await page.getByLabel('Anleitung finden',{exact:true}).fill('fünf Dokumente');assert(await page.locator('[data-handbook-result]:visible').count()>0);
 await page.getByLabel('Anleitung finden',{exact:true}).fill('keinpassenderbegriffxyz');assert.equal(await page.locator('[data-handbook-result]:visible').count(),0);assert.match(await page.locator('[data-handbook-results]').innerText(),/Keine passende/);
 await page.getByLabel('Anleitung finden',{exact:true}).fill('');
 await page.getByRole('link',{name:'Videoanleitungen öffnen',exact:true}).click();assert(await page.locator('#hilfe-videos').getAttribute('open')!==null);
 assert.equal(await page.locator('.handbook-video-card').count(),expected.length);
 for(const tutorial of expected){
  const card=page.locator('#video-'+tutorial.id);await card.locator(':scope > summary').click();const video=card.locator('video');
  await video.evaluate(v=>{v.load();});await page.waitForFunction(id=>document.querySelector('#video-'+id+' video').readyState>=1,tutorial.id);
  const meta=await video.evaluate(v=>({duration:v.duration,width:v.videoWidth,error:v.error?.message}));assert(!meta.error);assert.equal(meta.width,1440);assert(Math.abs(meta.duration-tutorial.duration)<1.3);
  const start=tutorial.scenes[1].start;await card.locator('[data-video-seek]').nth(1).click();await page.waitForFunction(({id,start})=>{const v=document.querySelector('#video-'+id+' video');return !v.paused&&v.currentTime>=start;},{id:tutorial.id,start});await video.evaluate(v=>v.pause());
  const vtt=await page.request.get(base+'?view=handbook&handbook_media='+tutorial.subtitles);assert.equal(vtt.status(),200);assert.match(await vtt.text(),/^WEBVTT/);
  const range=await page.request.get(base+'?view=handbook&handbook_media='+tutorial.file,{headers:{Range:'bytes=0-99'}});assert.equal(range.status(),206);assert.equal((await range.body()).length,100);assert.match(range.headers()['content-range'],/^bytes 0-99\//);
  await card.locator(':scope > summary').click();
 }
 for(const suffix of ['handbook_media=unknown.mp4','handbook_media=..%2Findex.php','handbook_image=01-dokumente-auswahl.png'])assert.equal((await page.request.get(base+'?view=handbook&'+suffix)).status(),404);
 assert.equal((await page.request.get(base+'?view=handbook&handbook_media='+expected[0].file,{headers:{Range:'bytes=999999999999-'}})).status(),416);
 await page.setViewportSize({width:390,height:844});await page.goto(base+'?view=handbook#video-newsletter');await page.waitForFunction(()=>document.querySelector('#video-newsletter').open);
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));await page.screenshot({path:path.join(root,'.local/tutorial-build/help-mobile.png')});
 await page.goto(base+'?view=handbook#kapitel-18');await page.screenshot({path:path.join(root,'.local/tutorial-build/chapter18-mobile.png')});
 assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
 await page.setViewportSize({width:1280,height:960});await page.goto(base+'?view=handbook');await page.screenshot({path:path.join(root,'.local/tutorial-build/help-desktop.png')});
 if(fixture)await page.context().addCookies([{name:'KH_VEREIN_DEMO',value:fixture.sessions.own,url:base}]);else{const csrf=await page.locator('[name=csrf]').first().inputValue();await page.request.post(base,{form:{csrf,action:'persona',actor:'demo-member'}});}await page.goto(base+'?view=handbook');assert.equal(await page.locator('.handbook-chapter').count(),chapterFiles.length);
 assert.equal((await page.request.get(base+'?view=handbook&handbook_media='+expected[0].file,{headers:{Range:'bytes=0-9'}})).status(),206);
 assert.deepEqual(errors,[]);await browser.close();console.log(`PASS: ${chapterFiles.length} chapters, ${expectedTasks} tasks, ${figureKeys.length} rendered and decoded illustrated instructions, ${expected.length} playable/seekable narrated videos, subtitles, range limits, search, anchors, desktop/mobile and member role.`);
})().catch(error=>{console.error(error);process.exit(1)});
