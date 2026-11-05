import {readFile,writeFile,mkdir} from 'node:fs/promises';
import assert from 'node:assert/strict';
const fixture=JSON.parse(await readFile(process.env.DVM_WEBSITE_WORKSPACE_FIXTURE,'utf8'));
const origins=Object.values(fixture.origins);for(const origin of origins)assert.match(origin,/^http:\/\/127\.0\.0\.1:[0-9]+$/);
const artifacts=process.env.DVM_WEBSITE_WORKSPACE_ARTIFACTS||new URL('./website-workspace-artifacts/',`file://${fixture.result_file}`).pathname;await mkdir(artifacts,{recursive:true});
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE||'playwright');
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
const checks=[],errors=[],layoutFailures=[],httpResponses=[],assets=new Map(),surfaceStates=[];let views=0,catalogueViews=0,businessPosts=0,external=0;
function check(ok,label){assert.ok(ok,label);checks.push(label);}
function layout(ok,label){if(ok)checks.push(label);else layoutFailures.push(label);}
async function buttonContrast(page,button,label){
 for(const state of ['normal','hover','keyboard']){
  await page.mouse.move(0,0);
  if(state==='hover')await button.hover();
  if(state==='keyboard'){await button.focus();await page.keyboard.press('Shift+Tab');await page.keyboard.press('Tab');check(await button.evaluate(e=>e.matches(':focus-visible')),label+' actual keyboard focus is visible');}
  await page.waitForTimeout(180);
  const result=await button.evaluate(e=>{
   const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d',{willReadFrequently:true});
   const rgba=color=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=color;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data];};
   const chain=[];for(let node=e;node;node=node.parentElement)chain.push(node);let bg=[255,255,255];
   for(const node of chain.reverse()){const c=rgba(getComputedStyle(node).backgroundColor),alpha=c[3]/255;bg=bg.map((v,i)=>c[i]*alpha+v*(1-alpha));}
   const color=getComputedStyle(e).color,c=rgba(color),fg=bg.map((v,i)=>c[i]*c[3]/255+v*(1-c[3]/255));
   const luminance=c=>c.map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4;}).reduce((n,v,i)=>n+v*[.2126,.7152,.0722][i],0),a=luminance(fg),b=luminance(bg);
   return {ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05),color,background:getComputedStyle(e).backgroundColor};
  });
  layout(result.ratio>=4.5,label+' '+state+' text contrast at least 4.5:1 '+JSON.stringify(result));
 }
}
try{
 for(const app of ['native','integrated'])for(const mode of ['js','basic']){
  const origin=fixture.origins[app],context=await browser.newContext({javaScriptEnabled:mode==='js',viewport:{width:390,height:844}});context.setDefaultTimeout(20000);context.setDefaultNavigationTimeout(45000);
  await context.route('**/*',async route=>{const request=route.request(),url=new URL(request.url());if(!origins.includes(url.origin)){external++;errors.push('External request '+url.href);await route.abort();return;}if(request.method()!=='GET'){businessPosts++;await route.abort();return;}await route.continue();});
  const page=await context.newPage();page.on('pageerror',error=>errors.push(error.stack||error.message));page.on('console',message=>{if(message.type()==='error')errors.push(message.text());});page.on('response',response=>{const type=response.request().resourceType();if(['stylesheet','script','image','font'].includes(type)){assets.set(response.url(),response.status());if(response.status()!==200)errors.push(type+' '+response.status()+' '+response.url());}if(response.status()>=500)errors.push('Server '+response.status()+' '+response.url());});
  const entered=await context.request.get(origin+'/own-workspace-enter',{maxRedirects:0});check(entered.status()===303,'Actual own personal session entry redirects '+app+' '+mode);
  for(const surface of ['overview','membership']){
   const target=surface==='membership'?'/mitglied-werden/':(app==='native'?'/?view=overview':'/vereinsverwaltung/?view=website&tab=overview');
   const response=await page.goto(origin+target);httpResponses.push({app,mode,surface,path:target,status:response.status(),policy:response.headers()['content-security-policy']||''});check(response.status()===200,'Actual '+app+' '+mode+' '+surface+' GET 200, got '+response.status());await page.waitForLoadState('networkidle');
   const policy=response.headers()['content-security-policy']||'';check(/(?:^|;)\s*img-src\s+[^;]*\bdata:/.test(policy),'Actual '+app+' '+surface+' CSP permits Bootstrap data SVG images');
   let originalRecentHref=null;
   if(surface==='overview'){
    await page.locator('.website-overview-card').first().waitFor();check(await page.locator('.website-overview-card').count()===5,'Actual '+app+' '+mode+' overview contains all five navigation cards');check((await page.locator('.website-recent').innerText()).includes(fixture.titles[app]),'Human original title retained exactly in '+app+' '+mode+' overview');
    originalRecentHref=await page.locator('.website-recent a').evaluateAll((nodes,title)=>nodes.find(node=>node.textContent===title)?.getAttribute('href'),fixture.titles[app]);check(typeof originalRecentHref==='string'&&originalRecentHref!=='','Actual original human title has its real edit link '+app+' '+mode);
    const css=await context.request.get(origin+'/vereinsverwaltung/native-website-workspace.css');check(css.status()===200&&/text\/css/.test(css.headers()['content-type']||''),'Actual mapped workspace CSS returns CSS 200 '+app+' '+mode);check((await css.text()).includes('grid-template-columns'),'Actual mapped workspace stylesheet contains the real responsive rules '+app+' '+mode);
    const style=await page.locator('.website-overview-cards').evaluate(node=>({display:getComputedStyle(node).display,columns:getComputedStyle(node).gridTemplateColumns}));check(style.display==='grid'&&style.columns!=='none','Actual overview consumes responsive grid stylesheet '+app+' '+mode);
   }else{
    const form=page.locator('#mitgliedsantrag form');await form.waitFor();check(await form.locator('select[name=membership_type]').count()===1,'Actual '+app+' '+mode+' published membership form has its real membership choice');
    // Decode one image URI read from the actually loaded pinned Bootstrap stylesheet under this real page's CSP.
    const bootstrap=page.locator('link[rel=stylesheet][href*="bootstrap-6."]').first();const css=await context.request.get(new URL(await bootstrap.getAttribute('href'),origin).href);const bytes=await css.text(),match=bytes.match(/url\("(data:image\/svg\+xml,[^"]+)"\)/);check(Boolean(match),'Pinned actual Bootstrap stylesheet contains its SVG image URI '+app+' '+mode);
    const decoded=await page.evaluate(async uri=>{const image=new Image();image.src=uri;try{await image.decode();return image.naturalWidth>0&&image.naturalHeight>0;}catch{return false;}},match[1]);check(decoded,'Real public membership CSP decodes pinned Bootstrap data SVG '+app+' '+mode);
   }
   for(const [width,height]of [[320,720],[390,844],[820,1024],[1440,900]])for(const font of [16,32]){
    await page.setViewportSize({width,height});await page.evaluate(font=>document.documentElement.style.fontSize=font+'px',font);await page.waitForTimeout(70);
    const state=await page.evaluate(surface=>{const shown=node=>node.getClientRects().length&&getComputedStyle(node).visibility!=='hidden';const scope=document.querySelector(surface==='overview'?'.website-workspace':'#mitgliedsantrag');const targets=[...scope.querySelectorAll(surface==='overview'?'.website-overview-card,.website-recent a':'button,select,input:not([type=hidden]):not([type=checkbox]),label.check')].filter(shown).map(node=>({text:(node.getAttribute('aria-label')||node.textContent||node.name||node.tagName).trim().slice(0,100),height:node.getBoundingClientRect().height,width:node.getBoundingClientRect().width}));const overview=surface==='overview'?{height:scope.querySelector('.website-overview-cards').getBoundingClientRect().height,cards:[...scope.querySelectorAll('.website-overview-card')].map(node=>({title:node.querySelector('h2').textContent.trim(),count:node.querySelector('.website-card-count').textContent.trim(),titleVisible:!!shown(node.querySelector('h2')),countVisible:!!shown(node.querySelector('.website-card-count')),decorationsHidden:[...node.querySelectorAll('.website-card-icon,p,.website-card-open')].every(child=>!shown(child))})),recent:[...scope.querySelectorAll('.website-recent a')].map(node=>({text:node.textContent,href:node.getAttribute('href'),height:node.getBoundingClientRect().height,lineHeight:parseFloat(getComputedStyle(node).lineHeight)}))}:null;return{width:innerWidth,scroll:document.documentElement.scrollWidth,targets,overview,scopeWidth:scope.getBoundingClientRect().width,columns:surface==='overview'?getComputedStyle(scope.querySelector('.website-overview-cards')).gridTemplateColumns:null};},surface);
    const label=[app,mode,surface,width,font].join(' ');surfaceStates.push({label,...state});layout(state.scroll<=width+1,'No horizontal document overflow '+label);check(state.targets.length>0,'Actual nonempty interactive surface '+label);layout(state.targets.every(target=>target.height>=43.5),'Actual navigation/form targets at least44px '+label+' '+JSON.stringify(state.targets.filter(x=>x.height<43.5)));views++;
    if(surface==='overview'){
     layout(state.overview.cards.every(card=>card.titleVisible&&card.countVisible)&&JSON.stringify(state.overview.cards.map(card=>[card.title,card.count]))===JSON.stringify([['Seiten','3'],['Menü','1'],['Aktuelles','0'],['Fotogalerie','0'],['Veranstaltungen','0']]),'All five actual navigation titles and original counts remain visible '+label);
     check(state.overview.recent.some(link=>link.text===fixture.titles[app]&&link.href===originalRecentHref),'The full human original title and target remain in their existing link '+label);
     if(state.scopeWidth<=36*font){layout(state.overview.cards.every(card=>card.decorationsHidden),'Compact navigation omits duplicate decorative content '+label);layout(state.overview.recent.every(link=>Number.isFinite(link.lineHeight)&&link.height<=3*link.lineHeight+1),'Compact recent-title preview occupies at most three lines '+label);}
     if(width===320&&font===32)layout(state.overview.height<=1000,'All five compact navigation rows together stay within1000px '+label+' '+state.overview.height);
    }
    if(width===320&&font===32)await page.screenshot({path:artifacts+'/'+app+'-'+mode+'-'+surface+'-320-32.png',fullPage:true,animations:'disabled'});
   }
   await page.evaluate(()=>document.documentElement.style.fontSize='16px');
  }
  if(app==='integrated')for(const [width,height]of [[320,720],[390,844],[820,1024]])for(const font of [16,32]){
   await page.setViewportSize({width,height});const response=await page.goto(origin+'/vereinsverwaltung/?view=website&tab=gallery');check(response.status()===200,'Actual compact gallery opens '+mode+' '+width+' '+font);await page.waitForLoadState('networkidle');await page.evaluate(font=>document.documentElement.style.fontSize=font+'px',font);
   const menu=page.locator('#workspace .website-area-menu'),summary=menu.locator(':scope > summary'),navigation=menu.locator('nav[aria-label="Website verwalten"]');
   check(await menu.getAttribute('open')===null&&!await navigation.isVisible(),'Actual '+mode+' gallery initially keeps secondary area navigation collapsed');check((await summary.innerText()).includes('Fotogalerie'),'Collapsed area menu identifies the actual gallery');
   const metrics=await page.evaluate(()=>{const header=document.querySelector('.website-workspace-header'),input=document.querySelector('.content-list-filters input[name=q]'),summary=document.querySelector('.website-area-menu>summary');return {heading:header.getBoundingClientRect().height,filterTop:input.getBoundingClientRect().top,summary:summary.getBoundingClientRect().height,scroll:document.documentElement.scrollWidth,viewport:document.documentElement.clientWidth};});
   const label=mode+' '+width+' '+font;layout(metrics.heading<=Math.max(112,4*font),'Compact website heading occupies at most two short rows '+label+' '+metrics.heading);layout(metrics.summary>=44,'Area disclosure retains a 44px control '+label);layout(metrics.scroll<=width+1,'Compact gallery has no sideways overflow '+label);if(font===16)layout(metrics.filterTop<=height-44,'Actual gallery search is reachable in the first phone/tablet screen '+label+' '+metrics.filterTop);
   await buttonContrast(page,page.getByRole('link',{name:'Dateien und Bildfassungen',exact:true}),'Actual file-library link '+label);
   if(width===820&&font===32)await page.screenshot({path:artifacts+'/integrated-'+mode+'-compact-gallery-820-32.png',animations:'disabled'});
   if(mode==='js'){await summary.focus();await summary.press('Enter');}else await summary.click();check(await navigation.isVisible()&&await navigation.getByRole('link').count()===8,'Actual '+mode+' disclosure opens every allowed website target');
   await navigation.getByRole('link',{name:'Seiten',exact:true}).click();await page.waitForURL(url=>url.searchParams.get('view')==='website'&&url.searchParams.get('tab')==='pages');check(await page.locator('#workspace .website-area-menu').getAttribute('open')===null,'Real '+mode+' area navigation returns to a compact content list without a POST');catalogueViews++;
  }
  await context.close();
 }
 check(views===64,'All two applications, JS/basic, two real surfaces, four widths and two font sizes executed');check(catalogueViews===12,'All integrated catalogue disclosure widths, fonts and JS/basic modes executed');check(businessPosts===0,'No business POST or publication/mail action');check(external===0,'No external network or provider request');check(layoutFailures.length===0,'Every requested layout and touch target passes '+JSON.stringify(layoutFailures));check(errors.length===0,'No actual CSP, JavaScript, static asset or server errors '+JSON.stringify(errors));
 const result={suite:'native-website-workspace-browser',views,catalogue_views:catalogueViews,checks:checks.length,business_posts:businessPosts,external_requests:external,errors,http_responses:httpResponses,layout_failures:layoutFailures,static_assets:assets.size,surfaces:surfaceStates};await writeFile(fixture.result_file,JSON.stringify(result,null,2)+'\n');await writeFile(artifacts+'/result.json',JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({...result,surfaces:undefined}));
}catch(error){await writeFile(artifacts+'/failure.json',JSON.stringify({error:error.stack,views,errors,http_responses:httpResponses,layout_failures:layoutFailures,surfaces:surfaceStates},null,2)+'\n');for(const [i,context]of browser.contexts().entries())for(const [j,page]of context.pages().entries())try{await writeFile(artifacts+'/failure-'+i+'-'+j+'.html',await page.content());await page.screenshot({path:artifacts+'/failure-'+i+'-'+j+'.png',fullPage:true});}catch{}throw error;}finally{await browser.close();}
