/* Only the separately mounted demo loads this adapter. Business field values stay literal. */
(function mountDemo() {
 'use strict';
 const config=globalThis.DVM_DEMO_CONFIGURATION;
 if(!config||globalThis.DVMDemoMounted)return;
 Object.defineProperty(globalThis,'DVMDemoMounted',{value:true});
 const NativeURL=globalThis.URL,prefix=config.namespace,mount=config.mount;
 const ownBlobs=new Map(),createBlob=NativeURL.createObjectURL?.bind(NativeURL),revokeBlob=NativeURL.revokeObjectURL?.bind(NativeURL);
 if(createBlob)NativeURL.createObjectURL=value=>{const url=createBlob(value);if(value instanceof Blob)ownBlobs.set(url,value);return url;};
 if(revokeBlob)NativeURL.revokeObjectURL=value=>{ownBlobs.delete(String(value));return revokeBlob(value);};
 const inside=path=>path===mount||path.startsWith(mount+'/');
 const base=globalThis.location.href;
 function mapped(value,{external=false}={}){
  const url=new NativeURL(String(value),base);
  if(url.protocol==='data:')return url.href;
  if(url.protocol==='blob:'){if(url.origin!==config.origin)throw new TypeError('Foreign demo blob');return url.href;}
  if(url.origin!==config.origin){if(external)return String(value);throw new TypeError('External requests are disabled in the demo');}
  if(!inside(url.pathname))url.pathname=mount+url.pathname;
  return url.href;
 }
 const ownName=name=>prefix+String(name);
 const fetchOriginal=globalThis.fetch;
 if(fetchOriginal)globalThis.fetch=function(input,options){
  try{const original=input instanceof Request?input.url:String(input),url=mapped(original);return fetchOriginal.call(this,input instanceof Request&&url!==original?new Request(url,input):input instanceof Request?input:url,options);}
  catch(error){return Promise.reject(error);}
 };
 if(globalThis.XMLHttpRequest){const open=XMLHttpRequest.prototype.open;XMLHttpRequest.prototype.open=function(method,url,...rest){return open.call(this,method,mapped(url),...rest);};}
 if(globalThis.BroadcastChannel){const Original=globalThis.BroadcastChannel;globalThis.BroadcastChannel=class extends Original{constructor(name){super(ownName(name));}};}
 if(globalThis.indexedDB){
  const factory=globalThis.indexedDB,open=factory.open.bind(factory),remove=factory.deleteDatabase.bind(factory),list=factory.databases?.bind(factory);
  factory.open=(name,...args)=>open(ownName(name),...args);factory.deleteDatabase=name=>remove(ownName(name));
  if(list)factory.databases=async()=> (await list()).filter(item=>item.name?.startsWith(prefix)).map(item=>({...item,name:item.name.slice(prefix.length)}));
 }
 if(globalThis.caches){
  const storage=globalThis.caches,open=storage.open.bind(storage),remove=storage.delete.bind(storage),has=storage.has.bind(storage),keys=storage.keys.bind(storage);
  storage.open=name=>open(ownName(name));storage.delete=name=>remove(ownName(name));storage.has=name=>has(ownName(name));storage.keys=async()=> (await keys()).filter(name=>name.startsWith(prefix)).map(name=>name.slice(prefix.length));
  storage.match=async(request,options={})=>{
   if(options.cacheName!==undefined)return (await open(ownName(options.cacheName))).match(request,options);
   for(const name of await storage.keys()){const result=await(await storage.open(name)).match(request,options);if(result)return result;}
  };
 }
 const workerOriginal=globalThis.Worker;
 if(workerOriginal){globalThis.Worker=class extends workerOriginal{
  constructor(value,options={}){
   const url=new NativeURL(mapped(value));let wrapper;
   if(url.protocol==='blob:'){
    const original=ownBlobs.get(url.href);if(!original)throw new TypeError('A demo blob worker requires a blob created in this demo context');
    const source=';globalThis.DVM_DEMO_CONFIGURATION='+JSON.stringify(config)+';\n('+mountDemo.toString()+')();\n';
    wrapper=NativeURL.createObjectURL(new Blob([source,original],{type:'text/javascript'}));
   }else url.searchParams.set('dvm_demo_worker','1');
   super(wrapper||url.href,options);this.demoWrapper=wrapper;
  }
  terminate(){if(this.demoWrapper)NativeURL.revokeObjectURL(this.demoWrapper);return super.terminate();}
 };}
 if(globalThis.SharedWorker){const Original=globalThis.SharedWorker;globalThis.SharedWorker=class extends Original{constructor(value,options={}){const url=new NativeURL(mapped(value));if(url.protocol==='blob:')throw new TypeError('A demo shared worker requires its own script URL');url.searchParams.set('dvm_demo_worker','1');super(url.href,typeof options==='string'?ownName(options):{...options,name:ownName(options.name||'')});}};}
 if(globalThis.importScripts){const original=globalThis.importScripts;globalThis.importScripts=(...urls)=>original(...urls.map(url=>mapped(url)));}
 if(globalThis.clients){
  const clients=globalThis.clients,all=clients.matchAll.bind(clients),get=clients.get.bind(clients),open=clients.openWindow.bind(clients);
  clients.matchAll=async options=>(await all(options)).filter(client=>inside(new NativeURL(client.url).pathname));
  clients.get=async id=>{const client=await get(id);return client&&inside(new NativeURL(client.url).pathname)?client:undefined;};clients.openWindow=url=>open(mapped(url));
 }
 if(typeof window==='undefined')return;
 for(const property of ['localStorage','sessionStorage']){
  const storage=window[property],get=storage.getItem.bind(storage),set=storage.setItem.bind(storage),remove=storage.removeItem.bind(storage),key=storage.key.bind(storage),length=()=>Object.getOwnPropertyDescriptor(Storage.prototype,'length').get.call(storage);
  const names=()=>Array.from({length:length()},(_,index)=>key(index)).filter(name=>name.startsWith(prefix));
  const methods={getItem:name=>get(ownName(name)),setItem:(name,value)=>set(ownName(name),value),removeItem:name=>remove(ownName(name)),clear:()=>names().forEach(remove),key:index=>names()[index]?.slice(prefix.length)??null};
  const proxy=new Proxy(storage,{
   get(target,name){if(name==='length')return names().length;if(Object.hasOwn(methods,name))return methods[name];if(typeof name==='symbol')return Reflect.get(target,name,target);const value=get(ownName(name));return value===null?undefined:value;},
   set(target,name,value){set(ownName(name),value);return true;},deleteProperty(target,name){remove(ownName(name));return true;},
   ownKeys:()=>names().map(name=>name.slice(prefix.length)),getOwnPropertyDescriptor(target,name){const value=get(ownName(name));return value===null?undefined:{configurable:true,enumerable:true,writable:true,value};},
  });Object.defineProperty(window,property,{configurable:true,get:()=>proxy});
 }
 const attributes=new Set(['href','src','action','formaction','poster','data-url','data-href','data-src']);
 const isURL=name=>attributes.has(name)||/^data-.*-(?:url|href|src|endpoint)$/.test(name);
 const setAttribute=Element.prototype.setAttribute,getAttribute=Element.prototype.getAttribute;
 function attribute(name,value){
  if(!isURL(name)||!value||String(value).startsWith('#')||/^(?:mailto|tel|javascript):/i.test(value))return value;
  return mapped(value,{external:true});
 }
 Element.prototype.setAttribute=function(name,value){return setAttribute.call(this,name,attribute(String(name).toLowerCase(),value));};
 function rewrite(root){for(const element of root.querySelectorAll('*'))for(const attr of element.attributes)if(isURL(attr.name))setAttribute.call(element,attr.name,attribute(attr.name,attr.value));}
 for(const [name,properties] of Object.entries({HTMLAnchorElement:['href'],HTMLLinkElement:['href'],HTMLScriptElement:['src'],HTMLImageElement:['src'],HTMLSourceElement:['src'],HTMLMediaElement:['src'],HTMLVideoElement:['poster'],HTMLIFrameElement:['src'],HTMLFormElement:['action'],HTMLInputElement:['formAction'],HTMLButtonElement:['formAction']})){
  const prototype=window[name]?.prototype;if(!prototype)continue;
  for(const property of properties){const descriptor=Object.getOwnPropertyDescriptor(prototype,property);if(descriptor?.set&&descriptor.configurable)Object.defineProperty(prototype,property,{...descriptor,set(value){return descriptor.set.call(this,attribute(property.toLowerCase(),value));}});}
 }
 const inner=Object.getOwnPropertyDescriptor(Element.prototype,'innerHTML');
 function html(value){const template=document.createElement('template');inner.set.call(template,String(value));rewrite(template.content);return inner.get.call(template);}
 for(const property of ['innerHTML','outerHTML']){const descriptor=Object.getOwnPropertyDescriptor(Element.prototype,property);if(descriptor?.set&&descriptor.configurable)Object.defineProperty(Element.prototype,property,{...descriptor,set(value){return descriptor.set.call(this,html(value));}});}
 const adjacent=Element.prototype.insertAdjacentHTML;Element.prototype.insertAdjacentHTML=function(where,value){return adjacent.call(this,where,html(value));};
 const observer=new MutationObserver(records=>{for(const record of records)for(const node of record.addedNodes)if(node instanceof Element){for(const attr of node.attributes)if(isURL(attr.name))setAttribute.call(node,attr.name,attribute(attr.name,attr.value));rewrite(node);}});observer.observe(document,{subtree:true,childList:true});
 for(const method of ['pushState','replaceState']){const original=history[method];history[method]=function(state,title,url){return original.call(this,state,title,url===undefined?undefined:mapped(url));};}
 const open=window.open;window.open=function(url,...args){return open.call(this,url===undefined?url:mapped(url,{external:true}),...args);};
 if(navigator.serviceWorker){
  const service=navigator.serviceWorker,register=service.register.bind(service),get=service.getRegistration.bind(service),all=service.getRegistrations.bind(service);
  service.register=(value,options={})=>{const url=new NativeURL(mapped(value));url.searchParams.set('dvm_demo_worker','1');return register(url.href,{...options,...(options.scope!==undefined?{scope:mapped(options.scope)}:{})});};
  service.getRegistration=scope=>get(scope===undefined?location.href:mapped(scope));service.getRegistrations=async()=>(await all()).filter(item=>inside(new NativeURL(item.scope).pathname));
 }
})();
