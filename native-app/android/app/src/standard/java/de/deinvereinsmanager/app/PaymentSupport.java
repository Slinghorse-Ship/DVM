package de.deinvereinsmanager.app;
import android.content.Intent;
import java.util.function.Supplier;
import org.json.JSONObject;
final class PaymentSupport {
    static void initialize(android.app.Application application){}
    private final MainActivity owner;
    PaymentSupport(MainActivity owner,Supplier<OriginPolicy> origin){this.owner=owner;}
    boolean available(){return false;}
    boolean busy(){return false;}
    void settings(){owner.show(owner.getString(R.string.native_card_terminal_not_configured_choose_another_payment_method));}
    boolean pay(JSONObject packet){settings();return false;}
    boolean result(int code){return false;}
}
