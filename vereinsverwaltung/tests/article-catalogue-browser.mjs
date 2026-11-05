import fs from 'node:fs';
const f=JSON.parse(fs.readFileSync(0,'utf8'));
const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);
const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE,headless:true,args:['--no-sandbox','--disable-dev-shm-usage']});
let checks=0,posts=0,views=0;const check=(ok,label)=>{if(!ok)throw new Error(label);checks++;};
const url=(mode,extra={})=>f.origin+(mode==='integrated'?'/vereinsverwaltung/':'/')+'?'+new URLSearchParams({...mode==='integrated'?{view:'website',tab:'news'}:{view:'news'},status:'all',...extra});
try{
 for(const mode of ['native','integrated'])for(const js of [true,false]){
  console.log('Checking actual article catalogue '+mode+'/'+js);
  const c=await browser.newContext({viewport:{width:390,height:900},hasTouch:true,javaScriptEnabled:js});
  await c.route('**/*',r=>new URL(r.request().url()).origin===f.origin?r.continue():r.abort());
  const p=await c.newPage();p.on('request',r=>{if(r.method()==='POST')posts++;});
  await p.goto(f.origin+'/own-test-enter?mode='+mode+'&module=news');await p.goto(url(mode,{page:'2',q:'100%_!'}));await p.locator('[data-news-row]').first().waitFor();
  check(await p.locator('[data-news-row]').count()===6,'six real exact query originals '+mode+'/'+js);
  check((await p.locator('.news-pagination').innerText()).includes('Seite 2 von 8'),'complete exact literal count '+mode+'/'+js);
  const item=await p.locator('[data-news-row]').first().getAttribute('data-news-row');
  await p.locator('[data-news-row]').first().getByRole('link',{name:'Bearbeiten',exact:true}).click();await p.locator('.back-link').waitFor();
  const opened=new URL(p.url());check((opened.searchParams.get('item')??opened.searchParams.get('native_item'))===item&&opened.searchParams.get('q')==='100%_!'&&opened.searchParams.get('page')==='2','selected original retains actual list context');
  await p.getByRole('link',{name:'← Alle Beiträge',exact:true}).click();await p.locator('[data-news-row]').first().waitFor();check(new URL(p.url()).searchParams.get('page')==='2'&&await p.locator('[data-news-row]').count()===6,'back retains six original results');
  await p.locator('.news-pagination').getByRole('link',{name:'Weiter',exact:true}).click();await p.waitForURL(u=>u.searchParams.get('page')==='3');await p.locator('[data-news-row]').first().waitFor();check(new URL(p.url()).searchParams.get('q')==='100%_!','next retains literal original query');
  await p.locator('.news-pagination input[name=page]').fill('8');await p.locator('.news-pagination button').click();await p.waitForURL(u=>u.searchParams.get('page')==='8');await p.locator('[data-news-row]').first().waitFor();check(await p.locator('[data-news-row]').count()===1,'direct last page retains forty-third original');
  for(const width of [320,390,820])for(const scale of [1,2]){
   await p.setViewportSize({width,height:900});await p.evaluate(scale=>document.documentElement.style.fontSize=String(scale*100)+'%',scale);await p.waitForTimeout(80);
   const fit=await p.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,small:[...document.querySelectorAll('.news-pagination a,.news-pagination button,.news-pagination input:not([type=hidden])')].filter(e=>e.getClientRects().length&&e.getBoundingClientRect().height<44).map(e=>e.outerHTML.slice(0,100))}));
   if(fit.overflow||fit.small.length){await p.screenshot({path:'/tmp/news-catalogue-ui-failed.png',fullPage:true});throw new Error(JSON.stringify({mode,js,width,scale,...fit}));}
   check(true,'actual touch and font viewport '+mode+'/'+js+'/'+width+'/'+scale);views++;
  }
  await c.close();
 }
 check(posts===0,'all actual list and exact source browsing remains readonly');console.log(JSON.stringify({browser_checks:checks,local_posts:posts,mobile_font_views:views}));
}finally{await browser.close();}
