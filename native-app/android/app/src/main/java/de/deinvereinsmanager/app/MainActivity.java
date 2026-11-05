package de.deinvereinsmanager.app;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.content.ClipData;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Color;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.print.PrintManager;
import android.view.View;
import android.view.WindowInsets;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.JsPromptResult;
import android.webkit.JsResult;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebStorage;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.EditText;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public final class MainActivity extends androidx.activity.ComponentActivity {
    private static final int PICK_FILES = 410, SAVE_FILE = 411, CAMERA = 412;
    private final Handler main = new Handler(Looper.getMainLooper());
    private final ExecutorService files = Executors.newSingleThreadExecutor();
    private WebView web;
    private OriginPolicy policy;
    private NativeBridge bridge;
    PaymentSupport payments;
    private LinearLayout root;
    private TextView notice;
    private ProgressBar progress;
    private ValueCallback<Uri[]> picker;
    private String pickerOrigin;
    private PermissionRequest camera;
    private File pendingSave;

    @Override public void onCreate(Bundle state) {
        super.onCreate(state);
        root = new LinearLayout(this); root.setOrientation(LinearLayout.VERTICAL);
        root.setBackgroundColor(Color.rgb(245,248,247));
        root.setOnApplyWindowInsetsListener((view, insets) -> {
            if (android.os.Build.VERSION.SDK_INT >= 30) {
                android.graphics.Insets bars = insets.getInsets(WindowInsets.Type.systemBars() | WindowInsets.Type.displayCutout());
                root.setPadding(bars.left,bars.top,bars.right,bars.bottom);
            } else root.setPadding(insets.getSystemWindowInsetLeft(),insets.getSystemWindowInsetTop(),insets.getSystemWindowInsetRight(),insets.getSystemWindowInsetBottom());
            return insets;
        });
        setContentView(root);
        getOnBackPressedDispatcher().addCallback(this,new androidx.activity.OnBackPressedCallback(true){@Override public void handleOnBackPressed(){back();}});
        LinearLayout toolbar = new LinearLayout(this); toolbar.setGravity(android.view.Gravity.CENTER_VERTICAL);
        android.widget.ImageButton back = action("←",R.string.native_back, this::back); toolbar.addView(back,new LinearLayout.LayoutParams(dp(52),dp(52)));
        TextView title = new TextView(this); title.setText(R.string.app_name);title.setTextSize(19);title.setPadding(dp(8),0,0,0);
        toolbar.addView(title,new LinearLayout.LayoutParams(0,dp(52),1));
        toolbar.addView(action("↻",R.string.native_reload_view,()->leave(()->load(safeCurrent()))),new LinearLayout.LayoutParams(dp(52),dp(52)));
        toolbar.addView(action("⋮",R.string.native_app_menu,this::menu),new LinearLayout.LayoutParams(dp(52),dp(52)));root.addView(toolbar);
        progress = new ProgressBar(this,null,android.R.attr.progressBarStyleHorizontal);progress.setMax(100);root.addView(progress,new LinearLayout.LayoutParams(-1,dp(3)));
        notice = new TextView(this);notice.setPadding(dp(16),dp(8),dp(16),dp(8));notice.setVisibility(View.GONE);root.addView(notice);
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);
        web = new WebView(this);root.addView(web,new LinearLayout.LayoutParams(-1,0,1));
        payments=new PaymentSupport(this,()->policy);
        bridge=new NativeBridge(this,web,()->policy);
        WebSettings settings = web.getSettings();settings.setJavaScriptEnabled(true);settings.setDomStorageEnabled(true);settings.setBuiltInZoomControls(true);settings.setDisplayZoomControls(false);settings.setTextZoom(Math.round(getResources().getConfiguration().fontScale*100));
        settings.setAllowFileAccess(false);settings.setAllowContentAccess(true);settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setSupportMultipleWindows(false);settings.setJavaScriptCanOpenWindowsAutomatically(false);settings.setMediaPlaybackRequiresUserGesture(true);
        settings.setUserAgentString(settings.getUserAgentString()+" DVMApp/1.0 Android");
        CookieManager.getInstance().setAcceptCookie(true);CookieManager.getInstance().setAcceptThirdPartyCookies(web,false);
        web.setWebViewClient(new Client());web.setWebChromeClient(new Chrome());
        web.setDownloadListener((url,agent,disposition,mime,length)->download(url,disposition,mime));
        String saved=getPreferences(MODE_PRIVATE).getString("origin","");
        if (!saved.isEmpty()) { try { policy=new OriginPolicy(saved,BuildConfig.DEBUG);if(!bridge.openNotification(getIntent()))load(policy.start()); } catch(IllegalArgumentException invalid){chooseServer();} }
        else chooseServer();
        if(getIntent().getBooleanExtra("dvm-renderer-recovered",false)){getIntent().removeExtra("dvm-renderer-recovered");show(getString(R.string.native_view_reopened_review_the_saved_result_before_sending_again));}
    }

    private int dp(int n) { return Math.round(n*getResources().getDisplayMetrics().density); }
    private android.widget.ImageButton action(String text,int description,Runnable handler) {
        android.widget.ImageButton button=new android.widget.ImageButton(this);
        button.setImageResource(text.equals("←")?android.R.drawable.ic_media_previous:text.equals("↻")?android.R.drawable.ic_popup_sync:android.R.drawable.ic_menu_more);
        button.setContentDescription(getString(description));button.setTag(description);button.setMinimumHeight(dp(48));button.setPadding(dp(12),dp(12),dp(12),dp(12));
        android.util.TypedValue ripple=new android.util.TypedValue();getTheme().resolveAttribute(android.R.attr.selectableItemBackgroundBorderless,ripple,true);button.setBackgroundResource(ripple.resourceId);
        button.setOnClickListener(v->handler.run());return button;
    }
    void show(String message) { if(isFinishing()||isDestroyed())return;notice.setText(message);notice.setVisibility(View.VISIBLE); }
    private void chooseServer() {
        EditText address=new EditText(this);address.setSingleLine();address.setInputType(android.text.InputType.TYPE_CLASS_TEXT|android.text.InputType.TYPE_TEXT_VARIATION_URI);
        address.setHint("https://verein.example");address.setContentDescription(getString(R.string.native_club_address));address.setText(policy==null?"":policy.origin());
        LinearLayout box=new LinearLayout(this);box.setPadding(dp(24),dp(8),dp(24),0);address.setMinHeight(dp(56));box.addView(address,new LinearLayout.LayoutParams(-1,-2));
        AlertDialog dialog=new AlertDialog.Builder(this).setTitle(getString(R.string.native_your_club)).setView(box).setPositiveButton(getString(R.string.native_open),null).setNegativeButton(policy==null?getString(R.string.native_close):getString(R.string.native_cancel),(d,w)->{if(policy==null)finish();}).setCancelable(policy!=null).create();
        dialog.setOnShowListener(d->dialog.getButton(AlertDialog.BUTTON_POSITIVE).setOnClickListener(v->{
            try {
                OriginPolicy chosen=new OriginPolicy(address.getText().toString(),BuildConfig.DEBUG);
                if(policy!=null&&!chosen.origin().equals(policy.origin())) {
                    CookieManager.getInstance().removeAllCookies(done->{WebStorage.getInstance().deleteAllData();policy=chosen;remember();load(policy.start());});
                } else {policy=chosen;remember();load(policy.start());}
                dialog.dismiss();
            }catch(IllegalArgumentException invalid){address.setError(getString(R.string.native_enter_your_club_https_address_without_a_path_credentials_or_));}
        }));dialog.show();
    }
    private void remember(){getPreferences(MODE_PRIVATE).edit().putString("origin",policy.origin()).apply();}
    void load(String url){if(policy!=null&&policy.owns(url)){notice.setVisibility(View.GONE);web.loadUrl(url);}}
    private String safeCurrent(){String url=web.getUrl();return policy!=null&&policy.owns(url)?url:policy.start();}
    void leave(Runnable action) {
        if(payments.busy()){show(getString(R.string.native_a_payment_attempt_is_in_progress_check_its_result_first));return;}
        if(policy==null||!policy.owns(web.getUrl())){action.run();return;}
        web.evaluateJavascript("Boolean(window.KulturhusNavigation?.dirty?.() || window.KulturhusPage?.guarded?.())",answer->{
            if("true".equals(answer))new AlertDialog.Builder(this).setTitle(getString(R.string.native_discard_your_changes)).setMessage(getString(R.string.native_unsaved_changes_will_be_lost)).setNegativeButton(getString(R.string.native_cancel),null).setPositiveButton(getString(R.string.native_discard),(d,w)->action.run()).show();else action.run();
        });
    }
    private void back(){leave(()->{if(web.canGoBack())web.goBack();else new AlertDialog.Builder(this).setTitle(getString(R.string.native_close_the_app)).setNegativeButton(getString(R.string.native_stay),null).setPositiveButton(getString(R.string.native_close),(d,w)->finish()).show();});}
    private void menu(){new AlertDialog.Builder(this).setTitle("DVM").setItems(payments.available()?new String[]{getString(R.string.native_switch_club),getString(R.string.native_open_in_browser),getString(R.string.native_print),getString(R.string.native_card_terminal)}:new String[]{getString(R.string.native_switch_club),getString(R.string.native_open_in_browser),getString(R.string.native_print)},(d,item)->{
        if(item==0)leave(this::chooseServer);
        else if(item==1)external(safeCurrent());
        else if(item==2)printPage();else payments.settings();
    }).show();}
    void printPage(){((PrintManager)getSystemService(PRINT_SERVICE)).print("DVM",web.createPrintDocumentAdapter("DVM"),null);}
    @Override protected void onNewIntent(Intent intent){super.onNewIntent(intent);setIntent(intent);bridge.openNotification(intent);}
    @Override public void onConfigurationChanged(android.content.res.Configuration changed){super.onConfigurationChanged(changed);web.getSettings().setTextZoom(Math.round(changed.fontScale*100));android.view.ViewGroup toolbar=(android.view.ViewGroup)root.getChildAt(0);for(int i=0;i<toolbar.getChildCount();i++){android.view.View item=toolbar.getChildAt(i);if(item.getTag() instanceof Integer)item.setContentDescription(getString((Integer)item.getTag()));}}
    private void external(String target){if(!OriginPolicy.external(target))return;try{startActivity(new Intent(Intent.ACTION_VIEW,Uri.parse(target)).addCategory(Intent.CATEGORY_BROWSABLE));}catch(Exception unavailable){show(getString(R.string.native_no_suitable_app_is_available));}}

    private final class Client extends WebViewClient {
        @Override public boolean shouldOverrideUrlLoading(WebView view,WebResourceRequest request){
            if(policy!=null&&policy.owns(request.getUrl().toString()))return false;
            if(request.isForMainFrame()&&request.getMethod().equals("GET"))external(request.getUrl().toString());
            return true;
        }
        @Override public WebResourceResponse shouldInterceptRequest(WebView view,WebResourceRequest request){
            if(policy!=null&&!policy.owns(request.getUrl().toString())&&(request.isForMainFrame()||!request.getUrl().getScheme().equals("https")||request.getUrl().getUserInfo()!=null))return new WebResourceResponse("text/plain","UTF-8",403,"Origin denied",java.util.Collections.emptyMap(),new java.io.ByteArrayInputStream(new byte[0]));
            return null;
        }
        @Override public void onPageStarted(WebView view,String url,android.graphics.Bitmap icon){progress.setVisibility(View.VISIBLE);bridge.reset();if(picker!=null){picker.onReceiveValue(null);picker=null;}}
        @Override public void onPageFinished(WebView view,String url){progress.setVisibility(View.GONE);CookieManager.getInstance().flush();bridge.attach();}
        @Override public void onReceivedError(WebView view,WebResourceRequest request,WebResourceError error){if(request.isForMainFrame())show(getString(R.string.native_connection_interrupted_review_the_saved_result_before_sendin));}
        @Override public void onReceivedHttpError(WebView view,WebResourceRequest request,WebResourceResponse response){if(request.isForMainFrame()&&response.getStatusCode()>=500)show(getString(R.string.native_the_club_server_is_currently_unavailable_try_opening_it_late));}
        @Override public void onReceivedSslError(WebView view,SslErrorHandler handler,SslError error){handler.cancel();show(getString(R.string.native_the_secure_connection_could_not_be_confirmed));}
        @Override public boolean onRenderProcessGone(WebView view,android.webkit.RenderProcessGoneDetail detail){getIntent().putExtra("dvm-renderer-recovered",true);main.post(()->MainActivity.this.recreate());return true;}
        @Override public void onFormResubmission(WebView view,android.os.Message dontResend,android.os.Message resend){dontResend.sendToTarget();show(getString(R.string.native_the_action_will_not_be_sent_again_review_the_saved_result_in));}
    }
    private final class Chrome extends WebChromeClient {
        @Override public void onProgressChanged(WebView view,int value){progress.setProgress(value);}
        @Override public boolean onJsAlert(WebView view,String url,String message,JsResult result){if(policy==null||!policy.owns(url)){result.cancel();return true;}new AlertDialog.Builder(MainActivity.this).setMessage(message).setPositiveButton("OK",(d,w)->result.confirm()).setOnCancelListener(d->result.cancel()).show();return true;}
        @Override public boolean onJsConfirm(WebView view,String url,String message,JsResult result){if(policy==null||!policy.owns(url)){result.cancel();return true;}new AlertDialog.Builder(MainActivity.this).setMessage(message).setPositiveButton("Weiter",(d,w)->result.confirm()).setNegativeButton(getString(R.string.native_cancel),(d,w)->result.cancel()).setOnCancelListener(d->result.cancel()).show();return true;}
        @Override public boolean onJsBeforeUnload(WebView view,String url,String message,JsResult result){return onJsConfirm(view,url,getString(R.string.native_leave_your_unsaved_changes),result);}
        @Override public boolean onJsPrompt(WebView view,String url,String message,String initial,JsPromptResult result){if(policy==null||!policy.owns(url)){result.cancel();return true;}EditText input=new EditText(MainActivity.this);input.setText(initial);new AlertDialog.Builder(MainActivity.this).setMessage(message).setView(input).setPositiveButton(getString(R.string.native_apply),(d,w)->result.confirm(input.getText().toString())).setNegativeButton(getString(R.string.native_cancel),(d,w)->result.cancel()).setOnCancelListener(d->result.cancel()).show();return true;}
        @Override public boolean onShowFileChooser(WebView view,ValueCallback<Uri[]> callback,FileChooserParams params){
            if(policy==null||!policy.owns(view.getUrl())){callback.onReceiveValue(null);return true;}
            if(picker!=null){callback.onReceiveValue(null);return true;}picker=callback;pickerOrigin=policy.origin();
            Intent intent=new Intent(Intent.ACTION_OPEN_DOCUMENT).addCategory(Intent.CATEGORY_OPENABLE).setType("*/*");
            intent.putExtra(Intent.EXTRA_ALLOW_MULTIPLE,params.getMode()==FileChooserParams.MODE_OPEN_MULTIPLE);intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION|Intent.FLAG_GRANT_PERSISTABLE_URI_PERMISSION);
            try{startActivityForResult(intent,PICK_FILES);}catch(Exception unavailable){picker.onReceiveValue(null);picker=null;show(getString(R.string.native_file_selection_is_unavailable));}return true;
        }
        @Override public void onGeolocationPermissionsShowPrompt(String origin,GeolocationPermissions.Callback callback){callback.invoke(origin,false,false);}
        @Override public void onPermissionRequest(PermissionRequest request){main.post(()->{
            if(policy==null||!policy.owns(request.getOrigin().toString())||!java.util.Arrays.asList(request.getResources()).contains(PermissionRequest.RESOURCE_VIDEO_CAPTURE)){request.deny();return;}
            if(camera!=null)camera.deny();camera=request;if(checkSelfPermission(Manifest.permission.CAMERA)==PackageManager.PERMISSION_GRANTED){request.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});camera=null;}else requestPermissions(new String[]{Manifest.permission.CAMERA},CAMERA);
        });}
        @Override public void onPermissionRequestCanceled(PermissionRequest request){if(camera==request)camera=null;}
    }
    @Override public void onRequestPermissionsResult(int code,String[] permissions,int[] results){super.onRequestPermissionsResult(code,permissions,results);if(bridge.permissionResult(code))return;if(code==CAMERA&&camera!=null){if(results.length>0&&results[0]==PackageManager.PERMISSION_GRANTED&&policy.owns(camera.getOrigin().toString()))camera.grant(new String[]{PermissionRequest.RESOURCE_VIDEO_CAPTURE});else camera.deny();camera=null;}}
    @Override protected void onActivityResult(int code,int result,Intent data){super.onActivityResult(code,result,data);if(payments.result(code))return;
        if(code==PICK_FILES&&picker!=null){java.util.ArrayList<Uri> selected=new java.util.ArrayList<>();if(result==RESULT_OK&&data!=null&&policy!=null&&policy.origin().equals(pickerOrigin)){if(data.getClipData()!=null)for(int i=0;i<data.getClipData().getItemCount();i++)selected.add(data.getClipData().getItemAt(i).getUri());else if(data.getData()!=null)selected.add(data.getData());for(Uri uri:selected)try{getContentResolver().takePersistableUriPermission(uri,Intent.FLAG_GRANT_READ_URI_PERMISSION);}catch(SecurityException temporary){/* The user-selected grant remains valid for this upload. */}}picker.onReceiveValue(selected.isEmpty()?null:selected.toArray(new Uri[0]));picker=null;}
        if(code==SAVE_FILE&&result==RESULT_OK&&data!=null&&data.getData()!=null&&pendingSave!=null){File file=pendingSave;Uri destination=data.getData();pendingSave=null;files.execute(()->{try(InputStream input=new FileInputStream(file);java.io.OutputStream output=getContentResolver().openOutputStream(destination,"w")){if(output==null)throw new Exception();copy(input,output);main.post(()->show(getString(R.string.native_file_saved)));}catch(Exception failed){main.post(()->show(getString(R.string.native_the_file_could_not_be_saved)));}});}
    }
    private void download(String target,String disposition,String type){
        if(policy==null||!policy.owns(target)){external(target);return;}
        final OriginPolicy selected=policy;final String cookie=CookieManager.getInstance().getCookie(target);final String agent=web.getSettings().getUserAgentString();
        files.execute(()->{File output=null;try{
            HttpURLConnection connection=(HttpURLConnection)new URL(target).openConnection();connection.setInstanceFollowRedirects(false);connection.setConnectTimeout(15000);connection.setReadTimeout(30000);connection.setRequestProperty("User-Agent",agent);if(cookie!=null)connection.setRequestProperty("Cookie",cookie);
            try{if(connection.getResponseCode()!=200)throw new Exception();String name=URLUtil.guessFileName(target,disposition,type).replaceAll("[^\\p{L}\\p{N} ._()-]","_");if(name.equals(".")||name.equals("..")||name.isEmpty())name=getString(R.string.native_file);
                File folder=new File(getCacheDir(),"shared/"+UUID.randomUUID().toString().replace("-",""));if(!folder.mkdirs())throw new Exception();output=new File(folder,name);
                try(InputStream input=connection.getInputStream();FileOutputStream stream=new FileOutputStream(output)){copy(input,stream);}
                if(connection.getContentLengthLong()>=0&&output.length()!=connection.getContentLengthLong())throw new Exception();
            }finally{connection.disconnect();}
            File ready=output;main.post(()->{if(!isFinishing()&&!isDestroyed()&&policy!=null&&policy.origin().equals(selected.origin()))offerFile(ready,type);});
        }catch(Exception failed){if(output!=null)output.delete();main.post(()->show(getString(R.string.native_download_unconfirmed_open_the_original_file_again_deliberate)));}});
    }
    void offerFile(File file,String mime){new AlertDialog.Builder(this).setTitle(file.getName()).setItems(new String[]{getString(R.string.native_open),getString(R.string.native_share),getString(R.string.native_save)},(d,choice)->{
        String type=mime==null?"application/octet-stream":mime;
        if(choice==2){pendingSave=file;try{startActivityForResult(new Intent(Intent.ACTION_CREATE_DOCUMENT).addCategory(Intent.CATEGORY_OPENABLE).setType(type).putExtra(Intent.EXTRA_TITLE,file.getName()),SAVE_FILE);}catch(Exception unavailable){show(getString(R.string.native_the_save_location_is_unavailable));}}
        else {Uri uri=new Uri.Builder().scheme("content").authority(getPackageName()+".files").appendPath(file.getParentFile().getName()).appendPath(file.getName()).build();Intent share=choice==0?new Intent(Intent.ACTION_VIEW).setDataAndType(uri,type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION):new Intent(Intent.ACTION_SEND).setType(type).putExtra(Intent.EXTRA_STREAM,uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);share.setClipData(ClipData.newRawUri(file.getName(),uri));try{startActivity(Intent.createChooser(share,choice==0?getString(R.string.native_open_file):getString(R.string.native_share_file)));}catch(Exception unavailable){show(getString(R.string.native_no_suitable_app_is_available));}}
    }).show();}
    private static void copy(InputStream input,java.io.OutputStream output)throws java.io.IOException{byte[] buffer=new byte[16384];int count;while((count=input.read(buffer))!=-1)output.write(buffer,0,count);}
    @Override protected void onResume(){super.onResume();if(web!=null)web.onResume();if(bridge!=null)bridge.refreshPermission();}
    @Override protected void onPause(){super.onPause();if(web!=null)web.onPause();CookieManager.getInstance().flush();}
    @Override protected void onDestroy(){if(picker!=null)picker.onReceiveValue(null);if(camera!=null)camera.deny();files.shutdown();bridge.reset();web.destroy();super.onDestroy();}
}
