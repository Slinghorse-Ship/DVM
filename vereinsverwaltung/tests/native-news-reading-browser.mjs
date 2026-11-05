import assert from 'node:assert/strict';
import {readFile,writeFile} from 'node:fs/promises';
import {fileURLToPath} from 'node:url';
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const ownFixture=process.env.DVM_EDITORIAL_READING_FIXTURE?JSON.parse(await readFile(process.env.DVM_EDITORIAL_READING_FIXTURE,'utf8')):null;
const root=fileURLToPath(new URL('../../',import.meta.url)),browser=await chromium.launch({executablePath:process.env.CHROMIUM_EXECUTABLE||'/usr/bin/chromium'});
let checks=0,reviews=0;const errors=[],external=[];
const check=(ok,label)=>{assert(ok,label);checks++;console.log('PASS '+label);};
const fits=async p=>{await p.waitForFunction(()=>[...document.querySelectorAll('link[rel=stylesheet]')].every(l=>l.sheet!==null));await p.evaluate(()=>document.fonts.ready);return p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);};
try{
  for(const standalone of [true,false]){
    const name=standalone?'standalone':'integrated',origin=ownFixture?.origin??('http://127.0.0.1:'+(standalone?'8107':'8111'));
    const f=ownFixture?.modes[name]??JSON.parse(await readFile(root+`.local/browser-tests/news-reading-${name}.json`,'utf8'));
    let state;
    const sessionPath=root+'.local/browser-tests/news-reading-integrated-session.json';let cached;
    if(!ownFixture&&!standalone)try{cached=JSON.parse(await readFile(sessionPath,'utf8'));}catch(e){if(e.code!=='ENOENT')throw e;}
    const login=await browser.newContext({storageState:cached}),first=await login.newPage();
    await login.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
    if(ownFixture){await first.goto(origin+'/own-test-enter?mode='+(standalone?'native':'integrated')+'&module=news&role=reader');}
    else if(standalone){await first.goto(origin+'/?view=news');await first.getByLabel('Synthetische Demorolle',{exact:true}).selectOption('reader');await first.getByRole('button',{name:'Wechseln',exact:true}).click();await first.waitForFunction(()=>document.querySelector('#notice')?.textContent.includes('Lokale Demorolle gewechselt.'));}
    else{await first.goto(itemUrlForSession(origin,f.items[0]));if(new URL(first.url()).pathname==='/login/'){await first.getByLabel('E-Mail-Adresse oder persönlicher Benutzername',{exact:true}).fill(f.credentials.email);await first.getByLabel('Passwort',{exact:true}).fill(f.credentials.password);await Promise.all([first.waitForURL(u=>u.pathname==='/vereinsverwaltung/'),first.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click()]);}}
    state=await login.storageState();if(!ownFixture&&!standalone)await writeFile(sessionPath,JSON.stringify(state),{mode:0o600});await login.close();
    const itemUrl=(item,general=false)=>origin+(standalone?'/?':'/vereinsverwaltung/?')+new URLSearchParams(standalone?{view:general?'website':'news',item:item.id}:{view:'website',tab:general?'pages':'news',native_item:item.id});
    for(const item of f.items){
      const c=await browser.newContext({storageState:state,javaScriptEnabled:item.js,viewport:{width:item.width,height:900},hasTouch:item.width<=390}),p=await c.newPage(),posts=[],fetches=[];
      await c.route('**/*',r=>new URL(r.request().url()).origin===origin?r.continue():(external.push(r.request().url()),r.abort()));
      p.on('pageerror',e=>errors.push(e.message));p.on('request',r=>{if(r.method()==='POST')posts.push(r.url());if(['fetch','xhr'].includes(r.resourceType())&&r.method()==='GET')fetches.push(r.url());});
      check((await p.goto(itemUrl(item))).status()===200,name+' actual authenticated saved news opens');
      const view=p.locator('[data-news-reading]');check(await view.count()===1,name+' reader gets complete saved original');
      check(await view.locator('h2').textContent()===item.title,name+' exact escaped title is displayed');
      if(ownFixture&&!standalone&&item.width===390&&item.js){await view.locator('h2').scrollIntoViewIfNeeded();await p.screenshot({path:'/tmp/editorial-reading-mobile.png'});}
      check(await view.locator('time').getAttribute('datetime')==='2050-02-03'&&await view.locator('time').textContent()==='03.02.2050',name+' saved date remains exact');
      check((await view.textContent()).includes('RSS-Feed'),name+' stored channel is visible');
      check(await view.locator('.kh-richtext strong').count()===80,name+' every original formatted paragraph is readable');
      check(await view.locator('form,input,textarea,select').count()===0,name+' reading controls cannot edit content');
      check(await p.locator('input[name=operation][value=article-save]').count()===0,name+' read-only role receives no editor form');
      if(!standalone&&item.width<=1000){
        if(item.js){
          const menu=p.getByRole('button',{name:'Navigation öffnen',exact:true});await menu.click();
          check(await p.locator('.app').evaluate(e=>e.inert)&&await menu.getAttribute('aria-expanded')==='true','tablet menu opens with protected reading surface');
          await p.keyboard.press('Escape');
          check(await menu.evaluate(e=>e===document.activeElement)&&!await p.locator('.app').evaluate(e=>e.inert),'tablet menu closes with keyboard focus restored');
        }else{
          const menu=p.getByRole('link',{name:'Navigation öffnen',exact:true});await menu.click();
          check(await p.locator('#navigation').evaluate(e=>e.getBoundingClientRect().left>=-1&&e===document.activeElement),'native menu opens and focuses without JavaScript');
          await p.getByRole('link',{name:'Menü schließen',exact:true}).click();
          check(await p.locator('#navigation').evaluate(e=>e.getBoundingClientRect().right<=1)&&await p.locator('#main').evaluate(e=>e===document.activeElement),'native menu closes and returns to content without JavaScript');
        }
      }
      const cover=view.locator('section[aria-label="Gespeicherte Bilder"] img').first();await cover.scrollIntoViewIfNeeded();
      await cover.evaluate(img=>img.complete?undefined:new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=reject;}));
      check(await cover.evaluate(img=>img.naturalWidth===320&&img.naturalHeight===200),name+' unpublished cover loads through authenticated private route');
      check(await cover.getAttribute('alt')==='Gespeichertes eigenes Foto 0 & <Kunst>',name+' original alternative text survives escaping');
      const imageLink=view.locator('section[aria-label="Gespeicherte Bilder"] a').first();
      check((await p.request.get(new URL(await imageLink.getAttribute('href'),origin).href)).status()===200,name+' original large private image can be opened');
      const publicUrl=origin+'/media/'+f.images[0]+'/large';check((await p.request.get(publicUrl)).status()===404,name+' unpublished original never acquires public access');
      const gallery=view.locator('details').filter({has:p.getByText('Weitere Bilder (11)',{exact:true})});
      check(!await gallery.evaluate(d=>d.open),name+' extra images start collapsed');await gallery.locator('summary').click();
      const scroll=gallery.getByRole('region',{name:'Weitere gespeicherte Bilder'});await scroll.focus();
      check(await scroll.evaluate(e=>document.activeElement===e&&e.clientHeight<=360&&e.scrollHeight>e.clientHeight),name+' bounded image gallery is focusable and scrollable');
      check(await scroll.locator('img').count()===11,name+' all further original images remain available');
      const metadata=view.locator('details').filter({has:p.getByText('Adresse, Menü und Suchmaschinen',{exact:true})});await metadata.locator('summary').click();
      check((await metadata.textContent()).includes('Ursprünglicher SEO-Titel')&&(await metadata.textContent()).includes('Ursprüngliches Menü'),name+' saved publication metadata is readable');
      check(await fits(p),name+' read view fits '+item.width+' pixels');
      await p.evaluate(()=>{document.documentElement.style.zoom='2';});check(await fits(p),name+' reading and gallery fit actual 200 percent zoom');await p.evaluate(()=>{document.documentElement.style.zoom='';});
      const jump=p.getByRole('link',{name:'Prüfung & Freigabe',exact:true});const box=await jump.boundingBox();check(box.height>=44,name+' direct review jump has a touch target');
      const beforeJump=fetches.length;await view.evaluate(e=>e.dataset.readingIdentity='retained');
      await jump.scrollIntoViewIfNeeded();if(item.width<=390)await jump.tap();else{await jump.focus();await jump.press('Enter');}
      if(item.js)await p.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
      const panel=p.getByRole('region',{name:'Redaktionelle Prüfung'});
      check(new URL(p.url()).hash==='#news-review-panel',name+' native review jump keeps exact original route');
      check(await panel.evaluate(e=>e.getBoundingClientRect().top>=-2&&e.getBoundingClientRect().top<innerHeight),name+' one action reaches review after eighty saved paragraphs');
      check(await panel.evaluate(e=>document.activeElement===e),name+' native review target receives keyboard focus');
      check(fetches.length===beforeJump&&await view.getAttribute('data-reading-identity')==='retained',name+' native jump does not fetch or replace the saved reading view');
      if(standalone){const button=panel.getByRole('button',{name:'Redaktionell freigeben',exact:true});check(await button.isEnabled(),'publisher without edit rights can act after reading');if(item.js)await button.click();else await Promise.all([p.waitForNavigation({waitUntil:'load'}),button.click()]);reviews++;await p.waitForFunction(()=>document.querySelector('[data-source-version]')?.dataset.sourceVersion==='2');check(await p.locator('[data-news-review=approved]').count()===1,'actual readonly publisher approval is saved');}
      else check(await panel.locator('button').count()===0,'plain authenticated reader gets no editorial decisions');
      check(posts.length===(standalone?1:0),name+' reading and native jump produce no hidden writes');
      check((await p.goto(itemUrl(item,true))).status()===200,name+' generic editor opens same saved news');
      check(await p.locator('[data-news-reading]').count()===1,name+' generic editor shares full read presentation');
      check(await fits(p),name+' generic reading presentation fits viewport');
      if(!standalone&&item.js){
        await p.evaluate(()=>window.newsReadingNavigationMarker='retained');
        const area=p.locator('#workspace .website-area-menu');if(await area.count()&&!await area.evaluate(n=>n.open))await area.locator(':scope > summary').click();
        await p.locator('#workspace .section-sub-nav').getByRole('link',{name:'Aktuelles',exact:true}).click();
        await p.waitForFunction(()=>new URL(location.href).searchParams.get('tab')==='news'&&!new URL(location.href).searchParams.has('native_item')&&!document.querySelector('main').hasAttribute('aria-busy'));
        check(await p.evaluate(()=>window.newsReadingNavigationMarker)==='retained','actual integrated page change keeps the current document');
        check(await p.locator('link[rel=stylesheet][href*=navigation-no-script]').count()===0,'soft navigation never activates styles inside noscript');
        if(item.width<=1000){
          const menu=p.getByRole('button',{name:'Navigation öffnen',exact:true});check(await menu.isVisible(),'tablet menu remains reachable after actual page change');
          for(let i=0;i<3;i++){
            if(i){const tab=i===1?'Seiten':'Aktuelles',key=i===1?'pages':'news';await p.locator('#workspace .section-sub-nav').getByRole('link',{name:tab,exact:true}).click();await p.waitForFunction(key=>new URL(location.href).searchParams.get('tab')===key&&!document.querySelector('main').hasAttribute('aria-busy'),key);}
            await menu.click();check(await p.locator('.app').evaluate(e=>e.inert)&&await menu.getAttribute('aria-expanded')==='true','one tap opens menu after successive soft page changes');await p.keyboard.press('Escape');check(!await p.locator('.app').evaluate(e=>e.inert)&&await menu.getAttribute('aria-expanded')==='false','menu closes once without leaked page handlers');
          }
        }
      }
      await c.close();
    }
  }
  check(errors.length===0,'no browser script errors');check(external.length===0,'no provider or external request');
  console.log(JSON.stringify({checks,private_review_decisions:reviews,publications:0,external_actions:0}));
}finally{await browser.close();}

function itemUrlForSession(origin,item){return origin+'/vereinsverwaltung/?view=website&tab=news&native_item='+item.id;}
