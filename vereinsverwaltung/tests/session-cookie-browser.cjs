/* Native PHP cookies and real browser close/reopen; synthetic-only, no real login or mail. */
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),os=require('os'),cp=require('child_process');
const root=path.resolve(__dirname,'../..');
const directory=fs.mkdtempSync(path.join(root,'.local/session-cookie-test-'));
const php=process.env.KULTURHUS_TEST_PHP||path.join(root,'.local/php-8.5.10/php.exe');
const base='http://127.0.0.1:8095/';
const nativeName='COOKIE_TEST_PW_v2';
const server=cp.spawn(php,['-S','127.0.0.1:8095',path.join(__dirname,'support/session-cookie-router.php')],{
    cwd:root,env:{...process.env,VEREIN_MODE:'synthetic',KULTURHUS_COOKIE_TEST:'1',KULTURHUS_COOKIE_DIRECTORY:directory},stdio:['ignore','ignore','pipe']
});
let serverError='';server.stderr.on('data',data=>serverError+=data);let checks=0,browser;
const check=(value,label)=>{if(!value)throw Error(label);checks++;console.log('PASS '+label);};
const launch=()=>chromium.launch({channel:'msedge',headless:true});
const context=async(cookies=[])=>{const c=await browser.newContext({viewport:{width:390,height:844},userAgent:'CookieFixture iPhone Browser'});if(cookies.length)await c.addCookies(cookies);return c;};
const get=async(c,query='')=>{const r=await c.request.get(base+query);check(r.ok(),'synthetic session endpoint responds');return r.json();};
const post=async(c,action,csrf,query='')=>c.request.post(base+query,{form:{action,csrf}});
const persistent=cookies=>cookies.filter(c=>c.expires>Date.now()/1000);
(async()=>{try{
    for(let i=0;i<40;i++){try{if((await fetch(base)).ok)break;}catch{}await new Promise(r=>setTimeout(r,100));}
    browser=await launch();let c=await context(),state=await get(c,'?legacy=1');
    state=await(await post(c,'login',state.csrf,'?legacy=1')).json();check(state.authenticated,'synthetic baseline login succeeds');
    let cookies=await c.cookies();check(cookies.find(x=>x.name==='COOKIE_TEST_PW').expires===-1,'old ProcessWire cookie is tied to browser session');
    let saved=persistent(cookies);check(saved.some(x=>x.name.endsWith('_challenge')),'challenge already survives browser shutdown');
    await browser.close();browser=await launch();c=await context(saved);state=await get(c,'?legacy=1');
    check(!state.authenticated,'discarding only browser-session cookies reproduces lost login after app close');
    state=await(await post(c,'login',state.csrf,'?legacy=1')).json();check(state.authenticated,'legacy session is active before storage cutover');
    state=await get(c);check(!state.authenticated&&state.private_storage,'old namespace cookies cannot authenticate after private storage cutover');
    await c.close();c=await context();state=await get(c);state=await(await post(c,'login',state.csrf)).json();
    check(state.authenticated,'login remains native and generates a new session');cookies=await c.cookies();
    let pw=cookies.find(x=>x.name===nativeName);check(pw.expires>Date.now()/1000+2591980&&pw.expires<=Date.now()/1000+2592001,'login cookie expires within finite 30-day window');
    check(state.idle_seconds===2592000&&state.mode==='month','native login default and server idle timeout both use 30 days');
    check(state.private_storage&&state.gc_probability===0,'month mode uses private storage with per-request GC disabled');
    check(pw.httpOnly&&pw.sameSite==='Lax','HttpOnly and SameSite protections preserved');
    const identity=pw.value,challenge=cookies.find(x=>x.name.endsWith('_challenge')).value,csrf=state.csrf;
    saved=persistent(cookies);await browser.close();browser=await launch();c=await context(saved);state=await get(c);
    check(state.authenticated&&state.csrf===csrf,'fresh browser retains authenticated session and unchanged CSRF');
    cookies=await c.cookies();check(cookies.find(x=>x.name===nativeName).value===identity&&cookies.find(x=>x.name.endsWith('_challenge')).value===challenge,'reopening does not rotate identity or bypass challenge');
    check(Math.abs(cookies.find(x=>x.name===nativeName).expires-cookies.find(x=>x.name.endsWith('_challenge')).expires)<2,'validated challenge and login cookie use the same finite idle window');
    await c.addCookies(cookies.map(x=>x.name===nativeName?{...x,expires:Math.floor(Date.now()/1000)+60}:x));state=await get(c);
    check((await c.cookies()).find(x=>x.name===nativeName).expires>Date.now()/1000+2591980,'authenticated activity renews remaining idle window');
    const missingChallenge=await c.cookies();await c.clearCookies();await c.addCookies(missingChallenge.filter(x=>!x.name.endsWith('_challenge')));let rejected=await get(c);check(!rejected.authenticated,'persistent login cookie without native challenge grants no access');
    await c.clearCookies();await c.addCookies(missingChallenge);state=await get(c);
    check((await post(c,'logout','incorrect-csrf')).status()===403,'logout remains CSRF protected');
    const retained=await c.cookies();state=await(await post(c,'logout',state.csrf)).json();check(!state.authenticated,'explicit logout ends session');
    check(!(await c.cookies()).some(x=>x.name===nativeName||x.name.endsWith('_challenge')),'logout clears both native login and challenge cookies');
    await browser.close();browser=await launch();c=await context(retained);state=await get(c);
    check(!state.authenticated,'replaying pre-logout cookie values cannot revive deleted session');
    await c.close();c=await context();state=await get(c,'?short=1');state=await(await post(c,'login',state.csrf,'?short=1')).json();
    check((await c.cookies()).find(x=>x.name===nativeName).expires<=Date.now()/1000+6,'shorter configured idle timeout is respected');
    await post(c,'expire',state.csrf,'?short=1');state=await get(c,'?short=1');check(!state.authenticated,'expired server-side session cannot be refreshed by persistent cookies');
    await c.close();c=await context();state=await get(c,'?secure=1');check(state.cookie_params.secure===true,'Secure flag is preserved for HTTPS configuration');
    await c.close();c=await context();state=await get(c);state=await(await post(c,'login',state.csrf)).json();
    const chosen=await c.request.post(base,{form:{portal_action:'session-duration',mode:'logout',csrf:state.csrf},maxRedirects:0});check(chosen.status()===303,'logout-only mode is saved by real native controller');
    const sentinel=path.join(directory,'private-native','sess_gc-sentinel-native-session');fs.writeFileSync(sentinel,'synthetic|i:1;');fs.utimesSync(sentinel,new Date(Date.now()-86400000),new Date(Date.now()-86400000));
    state=await get(c,'?gc=1');check(state.authenticated&&state.idle_seconds===0&&state.gc_probability===0,'logout-only mode disables idle timeout without running zero-age PHP garbage collection');
    check(fs.existsSync(sentinel),'zero idle timeout never deletes another older native session file');
    const unlimited=await c.cookies();
    await c.request.post(base,{form:{portal_action:'session-duration',mode:'day',csrf:state.csrf},maxRedirects:0});
    state=await get(c,'?gc=1');let dayCookies=await c.cookies();
    check(state.authenticated&&state.mode==='day'&&state.idle_seconds===86400&&state.gc_probability===0,'day mode also keeps global file GC disabled');
    check(dayCookies.find(x=>x.name===nativeName).value!==unlimited.find(x=>x.name===nativeName).value,'shortening duration rotates and destroys the old private session');
    const replay=await context(unlimited);const replayState=await get(replay);check(!replayState.authenticated,'old unlimited cookie set cannot revive the session after shortening');await replay.close();
    check(fs.existsSync(sentinel),'day request cannot delete another older long-duration session');
    await c.request.post(base,{form:{portal_action:'session-duration',mode:'month',csrf:state.csrf},maxRedirects:0});
    state=await get(c,'?gc=1');check(state.authenticated&&state.mode==='month'&&state.gc_probability===0,'switching back to month preserves GC isolation');
    const fixture=process.env.KULTURHUS_MEMBER_PROFILE_FIXTURE;
    if(fixture){
        const f=JSON.parse(fs.readFileSync(fixture,'utf8'));check(/^http:\/\/(127\.0\.0\.1|localhost):8091\/?$/.test(f.base),'manager check restricted to local synthetic server');
        await c.close();c=await context();await c.addCookies([{name:'KH_VEREIN_DEMO',value:f.sessions.admin,url:f.base,httpOnly:true,sameSite:'Strict'}]);
        let r=await c.request.get(f.base+'?view=settings&tab=account'),html=await r.text();
        check(r.ok(),'actual local manager account page opens');const token=html.match(/name="csrf" value="([a-f0-9]+)"/)[1];
        cookies=await c.cookies();const managerCookie=cookies.find(x=>x.name==='KH_VEREIN_DEMO');check(managerCookie.expires>Date.now()/1000+2591980&&managerCookie.sameSite==='Strict'&&managerCookie.httpOnly,'actual manager cookie has matching duration and its own protection');
        saved=persistent(cookies);await browser.close();browser=await launch();c=await context(saved);r=await c.request.get(f.base+'?view=settings&tab=account');html=await r.text();
        check(r.ok()&&html.includes('name="csrf" value="'+token+'"'),'real manager CSRF survives fresh browser without database/account changes');
    }
    console.log(`${checks} browser cookie checks passed; no real accounts or mail used.`);
}finally{if(browser)await browser.close();server.kill();fs.writeFileSync(path.join(directory,'server.log'),serverError);}})().catch(error=>{console.error(error);process.exitCode=1;});
