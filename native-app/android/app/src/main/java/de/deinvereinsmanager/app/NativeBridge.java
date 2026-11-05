package de.deinvereinsmanager.app;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.util.Base64;
import android.webkit.WebMessage;
import android.webkit.WebMessagePort;
import android.webkit.WebView;
import org.json.JSONObject;
import java.io.File;
import java.io.FileOutputStream;
import java.nio.charset.StandardCharsets;
import java.util.HashMap;
import java.util.Map;
import java.util.UUID;
import java.util.function.Supplier;

/** A main-frame-only message port; there is deliberately no JavascriptInterface. */
final class NativeBridge {
    private static final int NOTIFICATIONS=801;
    private final MainActivity owner;
    private final WebView web;
    private final Supplier<OriginPolicy> origin;
    private final Map<String,Transfer> transfers=new HashMap<>();
    private final Map<String,Integer> notifications=new HashMap<>();
    private WebMessagePort port;
    private String nonce, permissionRequest;
    private int nextNotification=1;
    NativeBridge(MainActivity owner,WebView web,Supplier<OriginPolicy> origin){this.owner=owner;this.web=web;this.origin=origin;}
    private NotificationManager manager(){return owner.getSystemService(NotificationManager.class);}
    private String permission(){
        if(Build.VERSION.SDK_INT>=33&&owner.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS)!=PackageManager.PERMISSION_GRANTED)
            return owner.getPreferences(0).getBoolean("notification-asked",false)?"denied":"default";
        return manager().areNotificationsEnabled()?"granted":"denied";
    }
    void reset(){
        nonce=null;permissionRequest=null;if(port!=null){port.close();port=null;}
        for(Transfer transfer:transfers.values())transfer.cancel();transfers.clear();
    }
    void attach(){
        reset();OriginPolicy selected=origin.get();if(selected==null||!selected.owns(web.getUrl()))return;
        try{
            nonce=UUID.randomUUID().toString();
            JSONObject configuration=new JSONObject().put("platform","android").put("origin",selected.origin()).put("nonce",nonce).put("permission",permission()).put("payments",owner.payments.available());
            String script;
            try(java.io.InputStream input=owner.getAssets().open("native-client.js");java.io.ByteArrayOutputStream bytes=new java.io.ByteArrayOutputStream()){
                byte[] buffer=new byte[8192];int count;while((count=input.read(buffer))!=-1)bytes.write(buffer,0,count);script=bytes.toString("UTF-8");
            }
            WebMessagePort[] pair=web.createWebMessageChannel();port=pair[0];final String bound=nonce;
            port.setWebMessageCallback(new WebMessagePort.WebMessageCallback(){@Override public void onMessage(WebMessagePort receiving,WebMessage message){if(bound.equals(nonce))message(message.getData());}});
            web.evaluateJavascript("(function(){const DVM_NATIVE_CONFIGURATION="+configuration+";"+script+"})();",ignored->{
                if(bound.equals(nonce)&&selected.owns(web.getUrl()))web.postWebMessage(new WebMessage(bound,new WebMessagePort[]{pair[1]}),Uri.parse(selected.origin()));else pair[1].close();
            });
        }catch(Exception failed){owner.show(owner.getString(R.string.native_app_features_could_not_be_connected));reset();}
    }
    private void reply(String id,Object result,String error){
        if(port==null||nonce==null||origin.get()==null||!origin.get().owns(web.getUrl()))return;
        try{JSONObject packet=new JSONObject().put("nonce",nonce).put("id",id);if(error!=null)packet.put("error",error);else packet.put("result",result==null?JSONObject.NULL:result);port.postMessage(new WebMessage(packet.toString()));}catch(Exception closed){/* A new page owns a new port. */}
    }
    private void message(String raw){
        if(raw==null||raw.length()>200000||origin.get()==null||!origin.get().owns(web.getUrl()))return;
        String id="";
        try{
            JSONObject packet=new JSONObject(raw);id=packet.optString("id");if(!packet.optString("nonce").equals(nonce)||!id.matches("[0-9]{1,12}"))return;
            switch(packet.optString("operation")){
                case "payment":reply(id,owner.payments.pay(packet),null);break;
                case "print":owner.printPage();reply(id,true,null);break;
                case "notification-permission":
                    if(Build.VERSION.SDK_INT>=33&&permission().equals("default")){
                        if(permissionRequest!=null){reply(id,null,owner.getString(R.string.native_permission_is_already_being_checked));break;}
                        permissionRequest=id;owner.getPreferences(0).edit().putBoolean("notification-asked",true).apply();owner.requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS},NOTIFICATIONS);
                    }else reply(id,permission(),null);break;
                case "notification":notify(packet);reply(id,true,null);break;
                case "notification-close":Integer old=notifications.remove(packet.optString("notification"));if(old!=null)manager().cancel(old);reply(id,true,null);break;
                case "file-start":{
                    if(transfers.size()>=2)throw new Exception();double size=packet.optDouble("size",-1);if(size<0||size>1024L*1024*1024||size!=(long)size)throw new Exception();
                    String key=UUID.randomUUID().toString().replace("-","");Transfer transfer=new Transfer(key,packet.optString("name"),packet.optString("type"),(long)size);transfers.put(key,transfer);reply(id,key,null);break;
                }
                case "file-chunk":{
                    Transfer transfer=transfers.get(packet.optString("job"));String data=packet.optString("data");if(transfer==null||data.length()>90000||!data.matches("[A-Za-z0-9+/]*={0,2}"))throw new Exception();
                    byte[] bytes=Base64.decode(data,Base64.NO_WRAP);if(bytes.length<1||bytes.length>65536||packet.optInt("sequence",-1)!=transfer.sequence||transfer.written+bytes.length>transfer.size)throw new Exception();
                    transfer.output.write(bytes);transfer.written+=bytes.length;transfer.sequence++;reply(id,true,null);break;
                }
                case "file-finish":{
                    Transfer transfer=transfers.get(packet.optString("job"));if(transfer==null||transfer.written!=transfer.size)throw new Exception();transfer.output.close();transfers.remove(packet.optString("job"));reply(id,true,null);owner.offerFile(transfer.file,transfer.mime);break;
                }
                case "file-cancel":{Transfer transfer=transfers.remove(packet.optString("job"));if(transfer!=null)transfer.cancel();reply(id,true,null);break;}
                default:reply(id,null,owner.getString(R.string.native_this_app_feature_is_unavailable));
            }
        }catch(Exception invalid){reply(id,null,owner.getString(R.string.native_app_action_unconfirmed));}
    }
    boolean permissionResult(int code){if(code!=NOTIFICATIONS)return false;String id=permissionRequest;permissionRequest=null;if(id!=null)reply(id,permission(),null);return true;}
    void refreshPermission(){if(port==null||nonce==null)return;try{port.postMessage(new WebMessage(new JSONObject().put("nonce",nonce).put("event","permission").put("permission",permission()).toString()));}catch(Exception closed){}}
    private void notify(JSONObject packet)throws Exception{
        if(!permission().equals("granted"))return;
        String target=packet.optString("target");if(!origin.get().owns(target))throw new Exception();
        String identifier=packet.optString("notification");if(!identifier.matches("notification-[0-9]{1,12}"))throw new Exception();
        String title=packet.optString("title"),body=packet.optString("body");if(title.length()>200||body.length()>1000)throw new Exception();
        int number=nextNotification++;notifications.put(identifier,number);manager().createNotificationChannel(new NotificationChannel("dvm-chat","Chat",NotificationManager.IMPORTANCE_DEFAULT));
        Intent open=new Intent(owner,MainActivity.class).setAction("dvm-notification").addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP|Intent.FLAG_ACTIVITY_CLEAR_TOP)
                .putExtra("origin",origin.get().origin()).putExtra("target",target).putExtra("nonce",nonce).putExtra("notification",identifier);
        PendingIntent click=PendingIntent.getActivity(owner,number,open,PendingIntent.FLAG_UPDATE_CURRENT|PendingIntent.FLAG_IMMUTABLE);
        android.app.Notification notification=new android.app.Notification.Builder(owner,"dvm-chat").setSmallIcon(R.drawable.notification_icon).setContentTitle(title).setContentText(body).setAutoCancel(true).setContentIntent(click).build();manager().notify(number,notification);
    }
    boolean openNotification(Intent intent){
        if(!"dvm-notification".equals(intent.getAction())||origin.get()==null||!origin.get().origin().equals(intent.getStringExtra("origin"))||!origin.get().owns(intent.getStringExtra("target")))return false;
        String target=intent.getStringExtra("target");owner.leave(()->{
            if(port!=null&&nonce!=null&&nonce.equals(intent.getStringExtra("nonce"))){try{port.postMessage(new WebMessage(new JSONObject().put("nonce",nonce).put("event","notification-click").put("notification",intent.getStringExtra("notification")).toString()));}catch(Exception closed){owner.load(target);}}
            else owner.load(target);
        });return true;
    }
    private final class Transfer {
        final File file;final FileOutputStream output;final long size;final String mime;long written;int sequence;
        Transfer(String key,String name,String type,long size)throws Exception{
            this.size=size;mime=type.matches("[A-Za-z0-9.+_-]+/[A-Za-z0-9.+_-]+")?type:"application/octet-stream";
            String leaf=name.replaceAll("[^\\p{L}\\p{N} ._()-]","_");if(leaf.isEmpty()||leaf.equals(".")||leaf.equals("..")||leaf.getBytes(StandardCharsets.UTF_8).length>240)throw new Exception();
            File folder=new File(owner.getCacheDir(),"shared/"+key);if(!folder.mkdirs())throw new Exception();file=new File(folder,leaf);output=new FileOutputStream(file);
        }
        void cancel(){try{output.close();}catch(Exception ignored){}file.delete();file.getParentFile().delete();}
    }
}
