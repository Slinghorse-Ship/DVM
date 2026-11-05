'use strict';
// Exercise the browser policy that a mocked PHP transport alone cannot detect.
const { chromium } = require('playwright');
const http = require('node:http');
const fs = require('node:fs');
const assert = require('node:assert/strict');
(async () => {
  const server = http.createServer((req, res) => {
    res.setHeader('Content-Security-Policy', "default-src 'none'; script-src 'self'; form-action 'self'");
    if (req.url === '/forward.js') {res.setHeader('Content-Type','text/javascript');res.end(fs.readFileSync(__dirname+'/../public/enable-banking-forward.js'));return;}
    if (req.method === 'POST') {res.writeHead(303,{Location:req.url==='/old'?'https://auth.enablebanking.com/test':'/continue'});res.end();return;}
    res.setHeader('Content-Type','text/html');
    res.end(req.url==='/continue'?'<a id="enable-bank-forward" href="https://auth.enablebanking.com/test">Weiter</a><script src="/forward.js" defer></script>':'<form method="post" action="'+req.url+'"><button>Verbinden</button></form>');
  });
  await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
  const browser=await chromium.launch({channel:'msedge',headless:true});
  try {
    const page=await browser.newPage();const errors=[];page.on('console',m=>{if(m.type()==='error')errors.push(m.text());});
    await page.route('https://auth.enablebanking.com/test',route=>route.fulfill({body:'Sandbox-Freigabe',contentType:'text/html'}));
    const url='http://127.0.0.1:'+server.address().port;
    await page.goto(url+'/old');await page.getByRole('button').click();
    await page.waitForTimeout(250);assert(page.url().startsWith(url));assert(errors.some(x=>/form-action/.test(x)));
    errors.length=0;await page.goto(url+'/new');await page.getByRole('button').click();
    await page.waitForURL('https://auth.enablebanking.com/test');assert(!errors.some(x=>/form-action/.test(x)));
    console.log('PASS Chromium reproduces blocked external POST redirect; same-origin completion and validated navigation succeed without weakening CSP');
  } finally {await browser.close();await new Promise(resolve=>server.close(resolve));}
})().catch(error=>{console.error(error);process.exitCode=1;});
