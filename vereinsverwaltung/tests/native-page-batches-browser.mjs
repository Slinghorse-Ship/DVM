import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const root=fileURLToPath(new URL('../../',import.meta.url)),browser=await chromium.launch({executablePath:'/usr/bin/chromium'});
let checks=0,steps=0;const check=(v,l)=>{assert(v,l);checks++;console.log('PASS '+l);};
try{for(const mode of ['integrated','standalone']){
 const origin=mode==='integrated'?'http://127.0.0.1:8111':'http://127.0.0.1:8107',f=JSON.parse(await readFile(root+'.local/browser-tests/page-batches-'+mode+'.json','utf8'));
 const context=await browser.newContext(),errors=[],external=[];const restrict=c=>c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));await restrict(context);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 if(mode==='integrated'){const c=JSON.parse(await readFile(root+'.local/native-cms-integration/integrated-manager-runtime/browser-credentials.json','utf8'));await page.goto(origin+'/login/');await page.locator('[name=email]').fill(c.admin.email);await page.locator('[name=password]').fill(c.admin.password);await Promise.all([page.waitForURL('**/vereinsverwaltung/**'),page.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}
 const url=(q={})=>origin+(mode==='integrated'?'/vereinsverwaltung/?':'/?')+new URLSearchParams(mode==='integrated'?{view:'website',tab:'pages',section:'bulk',...q}:{view:'website',section:'bulk',...q});
 const submit=async(p,button,noJs=false)=>{const response=p.waitForResponse(r=>r.request().method()==='POST'&&r.request().postData()?.includes('page-batch'));await button.click();const res=await response;check(res.status()===(mode==='standalone'&&!noJs?200:303),mode+' actual durable job HTTP step');await p.waitForLoadState('networkidle');steps++;};
 const prepare=async(p,width,direction='trash',noJs=false)=>{
  await p.goto(url({direction,q:'Own batch '+width+' original'}));const detail=p.locator('details').filter({has:p.locator('[name=batch_scope][value=all]')});await detail.locator('summary').click();const form=detail.locator('form');await form.locator('[name=batch_confirm]').check();await submit(p,form.getByRole('button',{name:'Alle Suchtreffer vorbereiten',exact:true}),noJs);check(await p.locator('[data-page-batch]').count()===1,mode+' prepared full selection keeps job URL');
 };
 const run=async(p,total,noJs=false)=>{let job=p.locator('[data-page-batch]');let advance=job.getByRole('button',{name:'Bis zu 25 Inhalte fortsetzen',exact:true});let count=0;
  while(await advance.count()){await submit(p,advance,noJs);count++;if(count===1&&total>25){check((await job.textContent()).includes('25 von '+total+' geprüft'),mode+' bounded first step persists exact checkpoint');const remembered=p.url();await p.reload();check(p.url()===remembered&&(await job.textContent()).includes('25 von '+total+' geprüft'),mode+' actual browser reload resumes same original job');}if(count>5)throw new Error('Unexpected job pause');}
  check((await job.textContent()).includes('Abgeschlossen'),mode+' selected original batch completes');
 };
 for(const width of [1440,820,390]){await page.setViewportSize({width,height:1000});await prepare(page,width);const job=page.locator('[data-page-batch]');
  check(!await job.evaluate(p=>p.getBoundingClientRect().right>innerWidth+1||p.scrollWidth>p.clientWidth+1),mode+' filled batch panel fits '+width);
  await job.getByRole('button',{name:'Bis zu 25 Inhalte fortsetzen',exact:true}).focus();check(await job.getByRole('button',{name:'Bis zu 25 Inhalte fortsetzen',exact:true}).evaluate(b=>b===document.activeElement),mode+' keyboard continuation '+width);
  await run(page,f.groups[width].length);await prepare(page,width,'restore');await run(page,f.groups[width].length);
 }
 // Keep completed checkpoints when the rest of a larger batch is cancelled.
 await prepare(page,820);await submit(page,page.locator('[data-page-batch]').getByRole('button',{name:'Bis zu 25 Inhalte fortsetzen',exact:true}));await submit(page,page.locator('[data-page-batch]').getByRole('button',{name:'Stapel abbrechen',exact:true}));check((await page.locator('[data-page-batch]').textContent()).includes('25 von 41 geprüft · Abgebrochen'),mode+' cancellation retains committed steps');await prepare(page,820,'restore');await run(page,25);
 // A newer page revision pauses the originally approved selection, then explicit skip keeps it untouched.
 await prepare(page,1440);const jobUrl=page.url();const target=f.groups[1440].at(-1);
 const editorUrl=origin+(mode==='integrated'?'/vereinsverwaltung/?':'/?')+new URLSearchParams(mode==='integrated'?{view:'website',tab:'pages',native_item:target.id}:{view:'website',item:target.id});
 const editor=await context.newPage();await editor.goto(editorUrl);const values=await editor.locator('.page-hierarchy-panel form').evaluate(f=>Object.fromEntries(new FormData(f)));const changed=await context.request.post(editorUrl,{form:{...values,hierarchy_position:'17'}});check([200,303].includes(changed.status()),mode+' actual independent editor advances original version');await editor.close();
 await page.goto(jobUrl);await submit(page,page.locator('[data-page-batch]').getByRole('button',{name:'Bis zu 25 Inhalte fortsetzen',exact:true}));check((await page.locator('[data-page-batch]').textContent()).includes('Zur Korrektur angehalten'),mode+' actual changed original pauses saved selection');
 const skip=page.locator('form:has([name=operation][value=page-batch-skip])');await skip.locator('[name=batch_confirm]').check();await submit(page,skip.getByRole('button',{name:'Diesen Konflikt unverändert überspringen',exact:true}));await run(page,2);check((await page.locator('[data-page-batch]').textContent()).includes('1 Originale bewusst übersprungen'),mode+' skipped original remains in durable history');await prepare(page,1440,'restore');await run(page,2);
 const noJs=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});await restrict(noJs);const basic=await noJs.newPage();await prepare(basic,390,'trash',true);await run(basic,3,true);await prepare(basic,390,'restore',true);await run(basic,3,true);await noJs.close();
 check(errors.length===0,mode+' no browser errors');check(external.length===0,mode+' no external requests');await context.close();
}const result={suite:'native-page-batches-browser',checks,steps,widths:[1440,820,390],external_actions:0};await writeFile(root+'.local/browser-tests/page-batches-result.json',JSON.stringify(result)+'\n',{mode:0o600});console.log(JSON.stringify(result));}finally{await browser.close();}
