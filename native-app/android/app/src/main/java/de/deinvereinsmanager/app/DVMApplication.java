package de.deinvereinsmanager.app;
/** Vendor initialization belongs to the application process, not each recreated screen. */
public final class DVMApplication extends android.app.Application {
    @Override public void onCreate(){super.onCreate();PaymentSupport.initialize(this);}
}
