/** Exercise the actual protected SPA lifecycle; legacy development mode keeps its full page requests. */
export async function actualOwnMenuRoutes(page,origin){
 return page.locator('#navigation a[href]').evaluateAll((links,origin)=>{
  const routes=new Map();
  for(const link of links){
   if((link.getAttribute('href')||'').startsWith('#'))continue;
   const target=new URL(link.href,origin);
   if(target.origin!==origin||target.pathname.replace(/\/$/,'')!=='/vereinsverwaltung'||!target.searchParams.has('view'))continue;
   target.hash='';
   routes.set(target.href,{url:target.href,label:link.textContent.trim()||link.getAttribute('aria-label')||''});
  }
  return [...routes.values()];
 },origin);
}
export async function actualOwnMenuPage(page,url,owned,check){
 if(!owned||new URL(url).searchParams.get('view')==='handbook')return page.goto(url);
 await page.waitForFunction(()=>typeof window.KulturhusNavigation?.navigate==='function');
 const responses=[];const record=response=>{if(response.request().headers()['x-kulturhus-navigation']==='1'&&new URL(response.url()).pathname==='/vereinsverwaltung/')responses.push(response);};page.on('response',record);
 let timer;
 try{
  await page.evaluate(()=>{window.ownedMenuDocumentMarker='retained';window.ownedMenuChrome={navigation:document.getElementById('navigation'),topbar:document.querySelector('.topbar'),groups:[...document.querySelectorAll('#navigation .nav-group')].map(g=>[g.querySelector('.subnav a[href]')?.getAttribute('href'),g.open]),scroll:document.getElementById('navigation').scrollTop};});
  const moved=await Promise.race([page.evaluate(url=>window.KulturhusNavigation.navigate(url),url),new Promise((_,reject)=>{timer=setTimeout(()=>reject(new Error('Actual own menu navigation did not complete: '+new URL(url).search)),45000);})]);
  check(moved===true,'actual own menu navigation accepts '+new URL(url).search);
  check(await page.evaluate(()=>window.ownedMenuDocumentMarker)==='retained','actual own menu keeps its document and asset lifecycle');
  check(await page.evaluate(()=>window.ownedMenuChrome.navigation===document.getElementById('navigation')&&window.ownedMenuChrome.topbar===document.querySelector('.topbar')&&Math.abs(window.ownedMenuChrome.scroll-document.getElementById('navigation').scrollTop)<=1&&window.ownedMenuChrome.groups.every(([href,open])=>{const g=[...document.querySelectorAll('#navigation .nav-group')].find(g=>g.querySelector('.subnav a[href]')?.getAttribute('href')===href);return !g||g.open===open;})),'actual own menu retains both shell elements, its group choices and scroll position');
  const response=responses.at(-1);check(!!response,'actual own menu receives a real protected controller response');return response;
 }catch(error){console.log('Own menu transition diagnostic',await page.evaluate(()=>({view:location.search,guarded:window.KulturhusPage?.guarded(),dialogs:[...document.querySelectorAll('dialog[open]')].map(n=>({id:n.id,text:n.textContent.trim().slice(0,450)})),changedFields:[...document.querySelectorAll('main input,main textarea,main select')].filter(n=>['checkbox','radio'].includes(n.type)?n.checked!==n.defaultChecked:n.tagName==='SELECT'?[...n.options].some(o=>o.selected!==o.defaultSelected):n.value!==n.defaultValue).map(n=>({name:n.name,type:n.type,length:n.value.length,defaultLength:n.defaultValue?.length}))})));throw error;}
 finally{clearTimeout(timer);page.off('response',record);}
}
