'use strict';
// Adversarial cookie replay using native PHP sessions and the real preference/controller.
// The fixture simulates account/CSRF services; it is not a physical-device or live-account test.
const {chromium}=require('playwright'),fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process');
const root=path.resolve(__dirname,'../..'),directory=fs.mkdtempSync(path.join(root,'.local/session-cookie-test-replay-'));
const base='http://127.0.0.1:8096/',php=process.env.KULTURHUS_TEST_PHP||path.join(root,'.local/php-8.5.10/php.exe');
const server=cp.spawn(php,['-S','127.0.0.1:8096',path.join(__dirname,'support/session-cookie-router.php')],{cwd:root,env:{...process.env,VEREIN_MODE:'synthetic',KULTURHUS_COOKIE_TEST:'1',KULTURHUS_COOKIE_DIRECTORY:directory},windowsHide:true,stdio:['ignore','ignore','pipe']});
let serverError='',browser;server.stderr.on('data',b=>serverError+=b);const checks=[],contexts=[];
const check=(v,label)=>{if(!v)throw Error(label);checks.push(label);console.log('PASS '+label);};
const read=async c=>{const r=await c.request.get(base);if(!r.ok())throw Error('Synthetic session endpoint failed');return r.json();};
const post=async(c,form)=>c.request.post(base,{form,maxRedirects:0});
const context=async(cookies=[])=>{const c=await browser.newContext({userAgent:'CookieFixture Replay Browser'});contexts.push(c);if(cookies.length)await c.addCookies(cookies);return c;};
const login=async c=>{let s=await read(c);const r=await post(c,{action:'login',csrf:s.csrf});if(!r.ok())throw Error('Synthetic login failed');return r.json();};
const choose=async(c,mode)=>{const s=await read(c),r=await post(c,{portal_action:'session-duration',mode,csrf:s.csrf});if(r.status()!==303)throw Error('Synthetic device selection failed');return read(c);};
const cookie=(list,suffix='')=>list.find(c=>c.name==='COOKIE_TEST_PW'+suffix);
(async()=>{try{
 for(let n=0;n<60;n++){if(server.exitCode!==null)throw Error('Isolated replay port could not start');try{if((await fetch(base)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
 browser=await chromium.launch({channel:'msedge',headless:true});let c=await context(),s=await login(c);
 check(s.authenticated&&s.mode==='month','native login begins with authenticated 30-day preference');
 const other=await context();await login(other);await choose(other,'month');const otherBefore=await other.cookies();
 await choose(c,'logout');const old=await c.cookies(),oldId=cookie(old).value;
 check(decodeURIComponent(cookie(old,'_duration').value).startsWith('v1|logout|')&&(await read(c)).idle_seconds===0,'unbounded selection has signed preference and no application idle timeout');
 s=await choose(c,'day');const current=await c.cookies(),currentId=cookie(current).value;
 check(currentId!==oldId&&!fs.existsSync(path.join(directory,'sess_'+oldId)),'shortening rotates native ID and destroys previous authenticated session file');
 check(s.authenticated&&s.mode==='day'&&s.idle_seconds===86400,'shortening remains authenticated with 24-hour idle limit');
 let attack=await context(old);s=await read(attack);check(!s.authenticated,'old unbounded SID, challenge and signed preference together cannot revive login');
 attack=await context(current.map(c=>c.name.endsWith('_duration')?cookie(old,'_duration'):c));s=await read(attack);check(s.authenticated&&s.mode==='day'&&s.idle_seconds===86400,'old valid unbounded preference cannot lengthen new shortened SID');
 attack=await context(old.map(c=>c.name.endsWith('_duration')?cookie(current,'_duration'):c));s=await read(attack);check(!s.authenticated,'new preference cannot authenticate the destroyed old SID');
 const fresh=await c.cookies();
 for(const [label,transform] of [
  ['missing preference',list=>list.filter(c=>!c.name.endsWith('_duration'))],
  ['incorrect MAC',list=>list.map(c=>c.name.endsWith('_duration')?{...c,value:c.value.slice(0,-1)+(c.value.endsWith('a')?'b':'a')}:c)],
  ['unsigned unbounded mode',list=>list.map(c=>c.name.endsWith('_duration')?{...c,value:'logout'}:c)],
  ['overlong malformed preference',list=>list.map(c=>c.name.endsWith('_duration')?{...c,value:'x'.repeat(301)}:c)]
 ]){attack=await context(transform(fresh));s=await read(attack);check(s.authenticated&&s.mode==='day'&&s.idle_seconds===86400,label+' falls back conservatively and never extends the session');}
 s=await read(c);let r=await post(c,{portal_action:'session-duration',mode:'logout',csrf:'incorrect'});check(r.status()===403&&(await read(c)).mode==='day','incorrect native CSRF cannot change lifetime');
 const beforeInvalid=cookie(await c.cookies()).value;r=await post(c,{portal_action:'session-duration',mode:'forever',csrf:s.csrf});check(r.status()===403&&cookie(await c.cookies()).value===beforeInvalid,'unsupported mode is rejected before rotating native SID');
 const guest=await context(),g=await read(guest);r=await post(guest,{portal_action:'session-duration',mode:'logout',csrf:g.csrf});check(r.status()===403&&!cookie(await guest.cookies(),'_duration'),'guest with its own valid CSRF receives no signed authenticated preference');
 r=await c.request.get(base+'?portal_action=session-duration&mode=logout');check((await r.json()).mode==='day','GET cannot change duration');
 s=await read(c);r=await post(c,{portal_action:'session-duration',mode:'day',csrf:s.csrf,user_id:'999',actor:'another-person',session_id:cookie(otherBefore).value});check(r.status()===303&&(await read(other)).mode==='month'&&cookie(await other.cookies()).value===cookie(otherBefore).value,'submitted account or session target cannot alter another device');
 const dayCookies=await c.cookies();s=await read(c);await post(c,{action:'age',age_seconds:'172800',csrf:s.csrf});
 attack=await context(dayCookies.map(c=>c.name.endsWith('_duration')?cookie(old,'_duration'):c));s=await read(attack);check(!s.authenticated,'two-day-old shortened session cannot be restored with previous unbounded preference');
 const last=await context();await login(last);await choose(last,'logout');const retained=await last.cookies();s=await read(last);await post(last,{action:'logout',csrf:s.csrf});check(!(await last.cookies()).some(c=>c.name==='COOKIE_TEST_PW'||c.name.endsWith('_challenge')||c.name.endsWith('_duration')),'explicit logout removes all three native/preference cookies');
 attack=await context(retained);check(!(await read(attack)).authenticated,'replaying all pre-logout unbounded cookies cannot authenticate');
 check((await read(other)).authenticated&&(await read(other)).mode==='month','unrelated authenticated device remains intact through shortening and logout');
 console.log(checks.length+' adversarial session replay checks passed; cookie values were never printed or saved.');
}finally{for(const c of contexts)await c.close().catch(()=>{});if(browser)await browser.close();server.kill();fs.writeFileSync(path.join(directory,'replay-result.json'),JSON.stringify({checks},null,2));fs.writeFileSync(path.join(directory,'server.log'),serverError);}})().catch(e=>{console.error(e.message);process.exitCode=1;});
