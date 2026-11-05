/** Original whiteboard-style tutorial scenes containing real reviewed UI crops. */
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'C:/Users/wehla/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright');
const root=path.resolve(__dirname,'../..'),out=path.join(root,'.local/tutorial-build'),sources=path.join(root,'docs/vereinsverwaltung/handbuch');
const escape=s=>s.replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
(async()=>{
 fs.mkdirSync(out,{recursive:true});
 const figures=JSON.parse(fs.readFileSync(path.join(sources,'figures.json'),'utf8'));
 const videos=JSON.parse(fs.readFileSync(path.join(sources,'tutorials.json'),'utf8'));
 const selected=(process.env.KULTURHUS_TUTORIAL_IDS||'').split(',').map(x=>x.trim()).filter(Boolean);for(const id of selected)if(!videos.some(video=>video.id===id))throw Error('Unknown tutorial: '+id);
 const browser=await chromium.launch({headless:true,channel:'msedge'}),page=await browser.newPage({viewport:{width:1440,height:900},deviceScaleFactor:1});
 for(const video of videos){
  if(selected.length&&!selected.includes(video.id))continue;
  for(const [index,scene] of video.scenes.entries()){
   const figure=scene.figure?figures[scene.figure]:null;
   if(figure&&!figure.privacy)throw Error('Capture requires privacy review: '+scene.figure);
   const image=figure?'<img alt="'+escape(figure.title)+'" src="data:image/png;base64,'+fs.readFileSync(path.join(root,'vereinsverwaltung/resources/handbook',figure.file)).toString('base64')+'">':'';
   const notes=scene.notes||(figure?figure.notes:scene.text.split(/(?<=[.!?])\s+/).slice(0,3));
   await page.setContent(`<!doctype html><html lang="de"><meta charset="utf-8"><style>
    *{box-sizing:border-box}body{margin:0;width:1440px;height:900px;background:#fffdf6;background-image:radial-gradient(#d8e2e6 1px,transparent 1px);background-size:22px 22px;color:#183346;font-family:Arial,sans-serif;padding:40px 52px}header{display:flex;justify-content:space-between;align-items:center;font-size:22px;font-weight:bold;color:#315d6d}header b{background:#ffd267;border:2px solid #183346;border-radius:8px;padding:10px 20px;color:#183346}h1{font-size:38px;margin:22px 0 8px}h2{font-size:28px;margin:0 0 22px;font-weight:normal}.board{height:642px;display:flex;gap:28px;align-items:center}.picture{background:white;padding:14px;border:3px solid #315d6d;border-radius:8px 12px 7px 13px;box-shadow:8px 8px 0 #e5edec;flex:1;min-width:0;display:flex;justify-content:center}img{max-width:100%;max-height:580px;object-fit:contain}.notes{width:${figure?'420px':'1100px'};flex-shrink:0}ol{list-style:none;padding:0;counter-reset:step}li{font-size:${figure?'21px':'31px'};line-height:1.42;margin:18px 0;padding:14px 16px 14px 56px;background:#fff;border:2px solid #b9cbd1;border-radius:5px;position:relative;counter-increment:step}li:before{content:counter(step);position:absolute;left:12px;top:14px;background:#164e8c;color:white;border-radius:50%;width:30px;height:30px;text-align:center;line-height:30px;font-size:19px;font-weight:bold}footer{position:absolute;left:52px;right:52px;bottom:22px;display:flex;justify-content:space-between;font-size:18px;color:#43616d}.arrow{font-size:65px;color:#315d6d;transform:rotate(-8deg)}
   </style><header><span>KULTURHUS · SCHRITT FÜR SCHRITT</span><b>${index+1} / ${video.scenes.length}</b></header><h1>${escape(video.title)}</h1><h2>${escape(scene.title)}</h2><main class="board">${figure?'<div class="picture">'+image+'</div>':'<span class="arrow">➜</span>'}<div class="notes"><ol>${notes.map(n=>'<li>'+escape(n.replace(/\*\*/g,''))+'</li>').join('')}</ol></div></main><footer><span>${escape(video.path)}</span><span>Nur erfundene Beispiele · Zum Mitmachen pausieren</span></footer></html>`);
   await page.evaluate(()=>Promise.all([...document.images].map(i=>i.decode())));
   const overflow=await page.evaluate(()=>[...document.querySelectorAll('.board li,img')].some(e=>e.getBoundingClientRect().bottom>849));
   if(overflow)throw Error('Tutorial scene overflows '+video.id+'-'+(index+1));
   await page.screenshot({path:path.join(out,video.id+'-'+(index+1)+'.png')});
   if(index===0)fs.copyFileSync(path.join(out,video.id+'-1.png'),path.join(root,'vereinsverwaltung/resources/handbook','video-'+video.id+'.png'));
  }
  console.log('Scenes: '+video.id);
 }
 await browser.close();
})().catch(e=>{console.error(e);process.exit(1)});
