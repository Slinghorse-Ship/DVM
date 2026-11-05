package de.deinvereinsmanager.app;

import android.app.AlertDialog;
import android.webkit.CookieManager;
import com.sumup.reader.sdk.api.SumUpState;
import com.sumup.merchant.reader.api.SumUpAPI;
import com.sumup.merchant.reader.api.SumUpLogin;
import com.sumup.merchant.reader.api.SumUpPayment;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.math.BigDecimal;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.Executors;
import java.util.function.Supplier;
import org.json.JSONObject;

/** Official SumUp 7.1.0 reader SDK. Server confirmation alone records a paid sale. */
final class PaymentSupport {
    static void initialize(android.app.Application application){if(!BuildConfig.SUMUP_AFFILIATE_KEY.isEmpty())SumUpState.init(application);}
    private static final int LOGIN=710,READER=711,CHECKOUT=712;
    private final MainActivity owner;
    private final Supplier<OriginPolicy> origin;
    private boolean busy;
    PaymentSupport(MainActivity owner,Supplier<OriginPolicy> origin){this.owner=owner;this.origin=origin;}
    boolean available(){return !BuildConfig.SUMUP_AFFILIATE_KEY.isEmpty();}
    boolean busy(){return busy;}
    void settings(){
        if(!available()){owner.show(owner.getString(R.string.native_card_terminal_not_configured_choose_another_payment_method));return;}
        if(busy){owner.show(owner.getString(R.string.native_a_payment_attempt_is_in_progress_check_its_result_first));return;}
        new AlertDialog.Builder(owner).setTitle(owner.getString(R.string.native_card_terminal)).setItems(SumUpAPI.isLoggedIn()?new String[]{owner.getString(R.string.native_card_reader),owner.getString(R.string.native_sign_out)}:new String[]{owner.getString(R.string.native_sign_in_to_sumup)},(dialog,index)->{
            if(!SumUpAPI.isLoggedIn())SumUpAPI.openLoginActivity(owner,SumUpLogin.builder(BuildConfig.SUMUP_AFFILIATE_KEY).build(),LOGIN);
            else if(index==0)SumUpAPI.openCardReaderPage(owner,READER);else SumUpAPI.logout();
        }).show();
    }
    boolean pay(JSONObject packet){
        OriginPolicy selected=origin.get();String attempt=packet.optString("attempt"),csrf=packet.optString("csrf");
        if(!available()||busy||selected==null||!selected.origin().startsWith("https://")||!attempt.matches("[a-f0-9]{32}")||!csrf.matches("[a-f0-9]{64}")){owner.show(owner.getString(R.string.native_payment_attempt_not_started_review_the_saved_result));return false;}
        if(!SumUpAPI.isLoggedIn()){owner.show(owner.getString(R.string.native_sign_in_to_sumup_and_check_the_merchant_first));return false;}
        String merchant=SumUpAPI.getCurrentMerchant().getMerchantCode(),base=selected.origin();
        if(SumUpAPI.getCurrentMerchant().getCurrency()!=SumUpPayment.Currency.EUR){owner.show(owner.getString(R.string.native_check_the_sumup_merchant_and_currency));return false;}
        String marker="sumup-started-"+base+"-"+merchant+"-"+attempt;
        if(owner.getPreferences(0).getBoolean(marker,false)){owner.show(owner.getString(R.string.native_check_this_payment_attempt_on_the_server_first));return false;}
        String cookie=CookieManager.getInstance().getCookie(base+"/vereinsverwaltung/?view=pos");busy=true;
        var executor=Executors.newSingleThreadExecutor();executor.execute(()->{
            try{
                JSONObject instruction=instruction(base,attempt,csrf,cookie,"native-info");validate(instruction,attempt,merchant);
                // This SDK offers card-reader checkout; Android Tap to Pay requires its separate SDK.
                if(!instruction.getString("method").equals("sumup-reader"))throw new Exception(owner.getString(R.string.native_tap_to_pay_is_unavailable_in_this_app_choose_a_card_reader_o));
                if(!same(base))throw new Exception();
                JSONObject started=instruction(base,attempt,csrf,cookie,"native-start");validate(started,attempt,merchant);
                for(String key:new String[]{"attempt","reference","amount_cents","currency","merchant_code","method"})if(!started.get(key).equals(instruction.get(key)))throw new Exception();
                owner.runOnUiThread(()->{
                    try{
                        if(!same(base)||!SumUpAPI.isLoggedIn()||!merchant.equals(SumUpAPI.getCurrentMerchant().getMerchantCode())||SumUpAPI.getCurrentMerchant().getCurrency()!=SumUpPayment.Currency.EUR)throw new Exception();
                        // Synchronous durable marker before the SDK; never retry this reference blindly.
                        if(!owner.getPreferences(0).edit().putBoolean(marker,true).commit())throw new Exception();
                        SumUpPayment payment=SumUpPayment.builder().total(BigDecimal.valueOf(started.getLong("amount_cents"),2)).currency(SumUpPayment.Currency.EUR).title(started.getString("reference")).foreignTransactionId(started.getString("reference")).build();
                        SumUpAPI.checkout(owner,payment,CHECKOUT);
                    }catch(Exception error){busy=false;owner.show(owner.getString(R.string.native_payment_attempt_unconfirmed_review_the_saved_result_on_the_s));}
                });
            }catch(Exception error){owner.runOnUiThread(()->{busy=false;owner.show(owner.getString(R.string.native_payment_attempt_unconfirmed_review_the_saved_result_on_the_s));});}
            finally{executor.shutdown();}
        });return true;
    }
    private boolean same(String base){return origin.get()!=null&&origin.get().origin().equals(base)&&!owner.isDestroyed();}
    private static void validate(JSONObject instruction,String attempt,String merchant)throws Exception{
        Object amount=instruction.get("amount_cents");if(!(amount instanceof Number))throw new Exception();double value=((Number)amount).doubleValue();
        if(value<1||value>100000000||value!=(long)value||!instruction.getString("attempt").equals(attempt)||!instruction.getString("reference").equals("KH-"+attempt)||!instruction.getString("currency").equals("EUR")||!instruction.getString("merchant_code").equals(merchant)||!java.util.Arrays.asList("sumup-reader","sumup-tap").contains(instruction.getString("method")))throw new Exception();
    }
    private JSONObject instruction(String base,String attempt,String csrf,String cookie,String operation)throws Exception{
        if(!same(base))throw new Exception();HttpURLConnection connection=(HttpURLConnection)new URL(base+"/vereinsverwaltung/?view=pos").openConnection();
        try{
            connection.setInstanceFollowRedirects(false);connection.setConnectTimeout(20000);connection.setReadTimeout(20000);connection.setRequestMethod("POST");connection.setDoOutput(true);
            connection.setRequestProperty("Origin",base);connection.setRequestProperty("Content-Type","application/x-www-form-urlencoded");if(cookie!=null)connection.setRequestProperty("Cookie",cookie);
            byte[] body=("operation="+operation+"&payment="+attempt+"&csrf="+csrf).getBytes(StandardCharsets.UTF_8);connection.setFixedLengthStreamingMode(body.length);
            try(var output=connection.getOutputStream()){output.write(body);}if(connection.getResponseCode()!=200)throw new Exception();
            ByteArrayOutputStream bytes=new ByteArrayOutputStream();try(InputStream input=connection.getInputStream()){byte[] buffer=new byte[8192];int count;while((count=input.read(buffer))!=-1){if(bytes.size()+count>65536)throw new Exception();bytes.write(buffer,0,count);}}
            return new JSONObject(bytes.toString("UTF-8")).getJSONObject("native");
        }finally{connection.disconnect();}
    }
    boolean result(int code){
        if(code!=LOGIN&&code!=READER&&code!=CHECKOUT)return false;
        if(code==CHECKOUT){busy=false;OriginPolicy selected=origin.get();if(selected!=null)owner.load(selected.origin()+"/vereinsverwaltung/?view=pos");owner.show(owner.getString(R.string.native_device_attempt_finished_check_the_payment_independently_now));}
        return true;
    }
}
