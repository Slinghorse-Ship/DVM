import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {fileURLToPath} from 'node:url';

export const kSource=fileURLToPath(new URL('../../../',import.meta.url));
const sha=bytes=>crypto.createHash('sha256').update(bytes).digest('hex');
const privateFile=file=>{if(!path.isAbsolute(file)||fs.realpathSync(file)!==file||fs.lstatSync(file).isSymbolicLink()||!fs.statSync(file).isFile()||(fs.statSync(file).mode&0o077)||fs.statSync(file).size>131072)throw Error('Owned private K-browser file required');return JSON.parse(fs.readFileSync(file,'utf8'));};
const filename=process.env.DVM_K_BROWSER_FIXTURE;
if(!filename)throw Error('Explicit own K-browser fixture required');
export const kFixture=privateFile(filename);
if(kFixture.owner!=='isolated-k-browser'||kFixture.source!==kSource.replace(/\/$/,'')||kFixture.root!==path.dirname(filename)||fs.realpathSync(kFixture.root)!==kFixture.root||(fs.statSync(kFixture.root).mode&0o077))throw Error('Own K-browser source and directory required');
if(kFixture.directory!==path.join(kFixture.root,'results')||fs.realpathSync(kFixture.directory)!==kFixture.directory||fs.lstatSync(kFixture.directory).isSymbolicLink()||(fs.statSync(kFixture.directory).mode&0o077))throw Error('Owned private K-browser result directory required');
const parent=privateFile(kFixture.parent_config);
if(sha(fs.readFileSync(kFixture.parent_config))!==kFixture.parent_sha256||parent.owner!=='isolated-native-ci'||parent.label!=='native-k-browser'||parent.host!=='127.0.0.1'||parent.port!==13371||!/^kh_native_[a-f0-9]{24}$/.test(parent.database))throw Error('Exact K-browser parent required');
if(Object.keys(kFixture.source_proofs).length<4)throw Error('K-browser sources required');
for(const [name,hash]of Object.entries(kFixture.source_proofs)){const source=path.join(kSource,name);if(path.isAbsolute(name)||name.includes('..')||fs.lstatSync(source).isSymbolicLink()||sha(fs.readFileSync(source))!==hash)throw Error('Original K-browser source required');}
for(const [mode,app]of Object.entries(kFixture.apps)){const config=privateFile(app.config);if(!['standalone','integrated'].includes(mode)||path.dirname(app.config)!==kFixture.root||config.owner!=='isolated-k-browser'||config.label!=='native-k-browser-'+mode||config.database!==app.database||config.database===parent.database||config.host!=='127.0.0.1'||config.port!==13371||!/^kh_native_[a-f0-9]{24}$/.test(config.database)||sha(fs.readFileSync(app.config))!==app.config_sha256||new URL(app.origin).hostname!=='127.0.0.1')throw Error('Own fresh K-browser child required');for(const role of ['admin','reader'])if(app.storage[role]!==path.join(kFixture.directory,mode+'-'+role+'.json'))throw Error('Bound own K-browser session path required');}
export const kDirectory=()=>kFixture.directory;
export const kOrigin=standalone=>kFixture.apps[standalone?'standalone':'integrated'].origin;
export const kConfig=standalone=>kFixture.apps[standalone?'standalone':'integrated'].config;
export const kStorage=(standalone,role='admin')=>privateFile(kFixture.apps[standalone?'standalone':'integrated'].storage[role]);

if(process.argv[1]===fileURLToPath(import.meta.url)){
    if(process.argv.length!==3||process.argv[2]!=='--warm')throw Error('Explicit own login warmup required');
    const {chromium}=await import(process.env.PLAYWRIGHT_MODULE);const browser=await chromium.launch({executablePath:process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE||'/usr/bin/chromium',args:['--no-sandbox']});
    try{for(const [mode,app]of Object.entries(kFixture.apps))for(const role of ['admin','reader']){
        const c=await browser.newContext();let external=0;const errors=[];c.on('page',p=>p.on('pageerror',error=>errors.push(error.message)));await c.route('**/*',route=>{if(new URL(route.request().url()).origin!==app.origin){external++;return route.abort();}return route.continue();});const p=await c.newPage();await p.goto(app.origin+'/login/');await p.locator('[name=email]').fill(app.credentials[role].email);await p.locator('[name=password]').fill(app.credentials[role].password);await p.getByRole('button',{name:'Anmelden und Vereinsmanager öffnen',exact:true}).click();await p.waitForURL(url=>mode==='standalone'?url.pathname==='/'&&url.searchParams.has('view'):url.pathname==='/vereinsverwaltung/');
        const target=app.storage[role];if(path.dirname(target)!==kFixture.directory||fs.existsSync(target)||external||errors.length||await p.locator('[name=password]').count())throw Error('New private authenticated own session required');fs.writeFileSync(target,JSON.stringify(await c.storageState()),{flag:'wx',mode:0o600});console.log('PASS actual personal password login '+mode+'/'+role);await c.close();
    }}finally{await browser.close();}
}
