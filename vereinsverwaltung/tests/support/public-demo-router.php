<?php declare(strict_types=1);
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
if(($_SERVER['REMOTE_ADDR']??'')!=='127.0.0.1'){http_response_code(403);return;}
require dirname(__DIR__,3).'/vendor/autoload.php';require dirname(__DIR__,2).'/native/bootstrap.php';require dirname(__DIR__,2).'/src/bootstrap.php';
use Kulturhus\Native\Demo\{Configuration,Runtime,Mount};
$fixture=json_decode((string)file_get_contents((string)getenv('DVM_PUBLIC_DEMO_FIXTURE')),true,32,JSON_THROW_ON_ERROR);
$_SERVER['HTTPS']='on';$_SERVER['REQUEST_SCHEME']='https';$_SERVER['SERVER_PORT']='443';
$path=parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH);
if($path==='/normal-worker.js'){
 header('Content-Type: text/javascript');header('Service-Worker-Allowed: /');echo 'self.addEventListener("install",event=>event.waitUntil(self.skipWaiting()));';return;
}
if($path==='/normal'){
 header('Content-Type: text/html; charset=utf-8');setcookie('KH_NATIVE_MANAGER','owned-normal-session',['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Strict']);echo '<!doctype html><html><meta charset="utf-8"><title>Owned normal storage fixture</title><body><h1>Normal original</h1></body></html>';return;
}
if(str_starts_with((string)$path,'/demo/_owned-tests/')){
 Runtime::activate(Configuration::read($fixture['configuration']));
 if(($_GET['dvm_demo_worker']??'')==='1')header('Service-Worker-Allowed: /demo/');
 Mount::dispatch(static function()use($path):void{
  header('Content-Type: text/javascript; charset=utf-8');
  if(str_ends_with($path,'/module.mjs')){echo 'export const label="owned-module";';return;}
  $answer='async function answer(event,port){try{await caches.open("worker-same");const names=await caches.keys();const peers=globalThis.clients?(await clients.matchAll({includeUncontrolled:true})).map(c=>new URL(c.url).pathname):[];port.postMessage({mounted:globalThis.DVMDemoMounted===true,names,peers,namespace:globalThis.DVM_DEMO_CONFIGURATION?.namespace,label:globalThis.ownModuleLabel||"classic"});}catch(error){port.postMessage({error:error.message});}}';
  if(str_contains($path,'worker-module'))echo 'import {label} from "./module.mjs";globalThis.ownModuleLabel=label;';
  echo $answer;
  if(str_contains($path,'shared'))echo 'self.onconnect=event=>{const port=event.ports[0];port.onmessage=message=>answer(message,port);port.start();};';
  elseif(str_contains($path,'service-worker'))echo 'self.addEventListener("install",event=>event.waitUntil(self.skipWaiting()));self.addEventListener("activate",event=>event.waitUntil(self.clients.claim()));self.addEventListener("message",event=>event.waitUntil(answer(event,event.ports[0])));';
  else echo 'self.onmessage=event=>answer(event,self);';
 });return;
}
require $fixture['webroot'].'/demo/index.php';
