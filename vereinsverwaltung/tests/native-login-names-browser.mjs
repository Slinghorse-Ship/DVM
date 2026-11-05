import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');const root=fileURLToPath(new URL('../../',import.meta.url));const browser=await chromium.launch({executablePath:'/usr/bin/chromium'});let checks=0,saves=0;
const check=(v,l)=>{assert(v,l);checks++;console.log('PASS '+l);};
try{for(const mode of ['integrated','standalone']){
 const origin=mode==='integrated'?'http://127.0.0.1:8111':'http://127.0.0.1:8107',fixture=JSON.parse(await readFile(root+'.local/browser-tests/login-names-'+mode+'.json','utf8'));
 const credentials=JSON.parse(await readFile(fixture.credentials_file,'utf8'));
 const context=await browser.newContext(),errors=[],external=[];const restrict=c=>c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));await restrict(context);const page=await context.newPage();page.on('pageerror',e=>errors.push(e.message));
 const login=async(p,identifier)=>{await p.goto(origin+'/login');await p.locator('[name=email]').fill(identifier);await p.locator('[name=password]').fill(credentials.password);const res=p.waitForResponse(r=>r.request().method()==='POST');await p.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click();check((await res).status()===303,mode+' actual personal account authenticates');await p.waitForLoadState('networkidle');};
 await login(page,credentials.email);
 const account=origin+'/account',prefix='own-alias-'+mode+'-'+fixture.account.id.slice(0,8);
 const open=async p=>{await p.goto(account);await p.locator('.account-login-name summary').click();return p.locator('.account-login-name form');};
 const submit=async(p,form,name)=>{await form.locator('[name=login_name]').fill(name);await form.locator('[name=current_password]').fill(credentials.password);check(await form.evaluate(f=>f.checkValidity()),mode+' filled alias form satisfies actual native validation');await form.getByRole('button',{name:'Benutzernamen speichern',exact:true}).click({noWaitAfter:true});await p.getByRole('status').filter({hasText:'Persönlicher Benutzername gespeichert.'}).waitFor({timeout:20000});check((await p.locator('.portal-login__card').textContent()).includes(name),mode+' actual personal alias saves and is rendered by server');saves++;};
 for(const width of [1440,820,390]){
  await page.setViewportSize({width,height:1000});const form=await open(page);
  check(await page.locator('link[href*="bootstrap-6.0.0-alpha.1.min.css"]').count()===1,mode+' local pinned Bootstrap '+width);
  check(!await form.evaluate(f=>f.getBoundingClientRect().right>innerWidth+1||f.scrollWidth>f.clientWidth+1),mode+' personal alias form fits '+width);
  await form.getByRole('button',{name:'Benutzernamen speichern',exact:true}).focus();check(await form.getByRole('button',{name:'Benutzernamen speichern',exact:true}).evaluate(b=>b===document.activeElement),mode+' keyboard alias action '+width);
  await submit(page,form,prefix+'-'+width);check((await page.locator('.portal-login__card').textContent()).includes(prefix+'-'+width),mode+' saved personal alias is visible '+width);
 }
 // JavaScript-free save advances the original while an earlier tab keeps its exact version.
 const stale=await context.newPage(),staleForm=await open(stale),old=await staleForm.locator('[name=version]').inputValue();
 const basicContext=await browser.newContext({javaScriptEnabled:false,storageState:await context.storageState()});await restrict(basicContext);const basic=await basicContext.newPage();const basicForm=await open(basic);await submit(basic,basicForm,prefix+'-final');await basicContext.close();
 await staleForm.locator('[name=login_name]').fill(prefix+'-stale');await staleForm.locator('[name=current_password]').fill(credentials.password);const conflict=stale.waitForResponse(r=>r.request().method()==='POST');await staleForm.getByRole('button',{name:'Benutzernamen speichern',exact:true}).click();check((await conflict).status()===422,mode+' actual competing tab cannot replace newer account version');await stale.waitForLoadState('networkidle');
 check(await stale.locator('.account-login-name [name=version]').inputValue()===old,mode+' conflict retains original account version');check(await stale.locator('.account-login-name [name=login_name]').inputValue()===prefix+'-stale',mode+' conflict retains proposed alias');check(await stale.locator('.account-login-name [name=current_password]').inputValue()==='',mode+' failed form does not retain or echo password');await stale.close();
 const values=await (await open(page)).evaluate(f=>Object.fromEntries(new FormData(f)));const forged=await context.request.post(account,{form:{...values,csrf:'wrong',current_password:credentials.password}});check([403,422].includes(forged.status()),mode+' forged account CSRF rejected');
 const freshContext=await browser.newContext();await restrict(freshContext);const fresh=await freshContext.newPage();await login(fresh,prefix+'-final');await fresh.goto(account);check((await fresh.locator('.portal-login__card').textContent()).includes(credentials.email),mode+' actual new alias chooses same original mailbox');await freshContext.close();
 check(errors.length===0,mode+' no browser errors');check(external.length===0,mode+' no external requests');await context.close();
}const result={suite:'native-login-names-browser',checks,saves,modes:['integrated','standalone'],widths:[1440,820,390],external_actions:0};await writeFile(root+'.local/browser-tests/login-names-result.json',JSON.stringify(result)+'\n',{mode:0o600});console.log(JSON.stringify(result));}finally{await browser.close();}
