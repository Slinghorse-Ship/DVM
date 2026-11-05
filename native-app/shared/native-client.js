/* Only injected into the selected website's main frame by the native application. */
(function (config) {
  'use strict';
  if (location.origin !== config.origin) return;
  window.DVMNative?._dispose?.();
  /* DVM_INTERFACE_START */
  const interfaceMessages={"de":{"native_app_notifications_allowed_notifications_work_while_the_app_i":"App-Hinweise erlaubt. Hinweise funktionieren bei geöffneter Anwendung.","native_app_notifications_are_enabled_only_after_your_action_and_per":"App-Hinweise werden erst nach deinem Klick und deiner Erlaubnis aktiviert.","native_app_notifications_are_off_you_can_enable_them_in_device_sett":"App-Hinweise ausgeschaltet. Du kannst sie in den Geräteeinstellungen erlauben.","native_app_result_unconfirmed":"App-Ergebnis nicht bestätigt.","native_demo_payments_disabled":"In der Demo sind Gerätezahlungen gesperrt.","native_file":"Datei","native_the_app_connection_is_not_ready_yet":"App-Verbindung noch nicht bereit.","native_the_file_could_not_be_transferred_open_the_original_export_a":"Datei konnte nicht übernommen werden. Export erneut bewusst öffnen.","native_the_print_view_could_not_be_opened":"Druckansicht konnte nicht geöffnet werden.","native_view_changed":"Ansicht gewechselt."},"en":{"native_app_notifications_allowed_notifications_work_while_the_app_i":"App notifications allowed. Notifications work while the app is open.","native_app_notifications_are_enabled_only_after_your_action_and_per":"App notifications are enabled only after your action and permission.","native_app_notifications_are_off_you_can_enable_them_in_device_sett":"App notifications are off. You can enable them in device settings.","native_app_result_unconfirmed":"App result unconfirmed.","native_demo_payments_disabled":"Device payments are disabled in the demo.","native_file":"File","native_the_app_connection_is_not_ready_yet":"The app connection is not ready yet.","native_the_file_could_not_be_transferred_open_the_original_export_a":"The file could not be transferred. Open the original export again deliberately.","native_the_print_view_could_not_be_opened":"The print view could not be opened.","native_view_changed":"View changed."}};
  /* DVM_INTERFACE_END */
  function nativeText(key){const locale=(document.documentElement.lang||'de').toLowerCase().split('-')[0]==='en'?'en':'de';return interfaceMessages[locale]?.[key]||interfaceMessages.de?.[key]||key;}
  let send, count=0, permission=config.permission||'default';
  const pending=new Map(), notifications=new Map();
  const receive=packet=>{
    if (!packet || packet.nonce!==config.nonce) return;
    if (packet.event==='notification-click') {
      const item=notifications.get(packet.notification);
      if(item){item.onclick?.(new Event('click'));item.dispatchEvent(new Event('click'));}
      return;
    }
    if(packet.event==='permission'){permission=packet.permission;permissionLabel();return;}
    const item=pending.get(packet.id);if(!item)return;pending.delete(packet.id);clearTimeout(item.timer);
    if(packet.error)item.reject(new Error(packet.error));else item.resolve(packet.result);
  };
  function call(operation,data={}){
    return new Promise((resolve,reject)=>{
      if(operation==='payment'&&/^\/demo(?:\/|$)/.test(location.pathname)){reject(new Error(nativeText('native_demo_payments_disabled')));return;}

      if(!send){reject(new Error(nativeText('native_the_app_connection_is_not_ready_yet')));return;}
      const id=String(++count),timer=setTimeout(()=>{pending.delete(id);reject(new Error(nativeText('native_app_result_unconfirmed')));},30000);
      pending.set(id,{resolve,reject,timer});send({nonce:config.nonce,id,operation,...data});
    });
  }
  function permissionLabel(){
    const node=document.querySelector('[data-chat-permission-status]');if(!node)return;
    const text=permission==='granted'?nativeText('native_app_notifications_allowed_notifications_work_while_the_app_i'):permission==='denied'?nativeText('native_app_notifications_are_off_you_can_enable_them_in_device_sett'):nativeText('native_app_notifications_are_enabled_only_after_your_action_and_per');
    if(node.textContent!==text)node.textContent=text;
  }
  class NativeNotification extends EventTarget {
    static get permission(){return permission;}
    static requestPermission(callback){return call('notification-permission').then(value=>{permission=value;permissionLabel();callback?.(value);return value;});}
    constructor(title,options={}){
      super();this.id='notification-'+(++count);this.onclick=null;
      if(permission!=='granted')return;
      notifications.set(this.id,this);
      let target=location.href;const match=/^kh-chat-([a-zA-Z0-9_-]+):[0-9]+$/.exec(options.tag||'');
      if(match){const url=new URL(location.pathname,location.origin);url.search=new URLSearchParams({view:'chat',item:match[1]}).toString();target=url.href;}
      call('notification',{notification:this.id,title:String(title),body:String(options.body||''),tag:String(options.tag||this.id),target}).catch(()=>notifications.delete(this.id));
    }
    close(){notifications.delete(this.id);call('notification-close',{notification:this.id}).catch(()=>{});}
  }
  function error(message){if(window.KulturhusDialog?.alert)window.KulturhusDialog.alert(message);else window.alert(message);}
  async function save(blob,name){
    const job=await call('file-start',{name,type:blob.type||'application/octet-stream',size:blob.size});let sequence=0;
    try{
      for(let offset=0;offset<blob.size;offset+=65536){
        const data=new Uint8Array(await blob.slice(offset,offset+65536).arrayBuffer());let binary='';
        for(let i=0;i<data.length;i+=8192)binary+=String.fromCharCode(...data.subarray(i,i+8192));
        await call('file-chunk',{job,sequence:sequence++,data:btoa(binary)});
      }
      return await call('file-finish',{job});
    }catch(failed){call('file-cancel',{job}).catch(()=>{});throw failed;}
  }
  const download=event=>{
    const link=event.target.closest?.('a[download]');if(!link||!/^blob:|^data:/.test(link.href))return;
    const url=new URL(link.href);if(url.protocol==='blob:'&&url.origin!==config.origin)return;
    event.preventDefault();event.stopImmediatePropagation();fetch(link.href).then(response=>response.blob()).then(blob=>save(blob,link.download||nativeText('native_file'))).catch(()=>error(nativeText('native_the_file_could_not_be_transferred_open_the_original_export_a')));
  };
  const observer=new MutationObserver(permissionLabel);observer.observe(document,{subtree:true,childList:true,attributes:true,attributeFilter:['lang']});
  document.addEventListener('click',download,true);
  const originalPrint=window.print;window.print=()=>call('print').catch(()=>error(nativeText('native_the_print_view_could_not_be_opened')));
  window.DVMNative=Object.freeze({platform:config.platform,version:1,get payments(){return config.payments===true&&!/^\/demo(?:\/|$)/.test(location.pathname);},pay:data=>call('payment',data),save,_dispose(){observer.disconnect();document.removeEventListener('click',download,true);window.print=originalPrint;for(const item of pending.values()){clearTimeout(item.timer);item.reject(new Error(nativeText('native_view_changed')));}pending.clear();}});
  function connected(){try{Object.defineProperty(window,'Notification',{configurable:true,value:NativeNotification});}catch{}permissionLabel();}
  if(config.platform==='ios'){
    window.DVMNativeReceive=receive;send=packet=>window.webkit.messageHandlers.dvmApp.postMessage(packet);connected();
  }else{
    const attach=event=>{
      if(event.data!==config.nonce||!event.ports?.[0])return;window.removeEventListener('message',attach);
      const port=event.ports[0];port.onmessage=event=>{try{receive(JSON.parse(event.data));}catch{}};send=packet=>port.postMessage(JSON.stringify(packet));port.start();connected();
    };
    window.addEventListener('message',attach);
  }
})(DVM_NATIVE_CONFIGURATION);
