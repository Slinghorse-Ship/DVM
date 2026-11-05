import de.deinvereinsmanager.app.OriginPolicy;

public final class OriginPolicyTest {
    private static int checks;
    private static void check(boolean value,String message){if(!value)throw new AssertionError(message);checks++;}
    public static void main(String[]args){
        OriginPolicy p=new OriginPolicy(" HTTPS://Verein.Example:443/ ",false);
        check(p.origin().equals("https://verein.example"),"canonical configured origin");
        check(p.owns("https://verein.example/vereinsverwaltung/?view=chat&item=original"),"own exact original chat route");
        check(p.owns("https://VEREIN.example:443/private-file/original"),"same normalized HTTPS origin");
        for(String url:new String[]{"https://verein.example.evil.invalid/","https://other.invalid/","http://verein.example/","https://verein.example:8443/","https://user@verein.example/","file:///private/key","content://private/key","javascript:alert(1)","data:text/html,original"})check(!p.owns(url),"foreign or untrusted origin denied");
        for(String url:new String[]{"http://verein.example","https://user:password@verein.example","https://verein.example/other","https://verein.example?token=original","https://verein.example#original","https://verein.example:0","https://verein.example%2f.evil.invalid","https://verein.example.","javascript:original","https://verein.example\\@evil.invalid"}){
            boolean denied=false;try{new OriginPolicy(url,false);}catch(IllegalArgumentException expected){denied=true;}check(denied,"ambiguous configuration denied");
        }
        check(new OriginPolicy("https://verein-münster.example",false).origin().equals("https://xn--verein-mnster-3ob.example"),"international club domain remains canonical");
        check(new OriginPolicy("https://verein.example:8443",false).owns("https://verein.example:8443/original"),"explicit HTTPS port remains scoped");
        check(new OriginPolicy("http://10.0.2.2:8111",true).owns("http://10.0.2.2:8111/vereinsverwaltung/"),"only debug emulator backend can use local HTTP");
        for(String url:new String[]{"http://10.0.2.2:8111","http://localhost:8111","http://127.0.0.1:8111"}){boolean denied=false;try{new OriginPolicy(url,false);}catch(IllegalArgumentException expected){denied=true;}check(denied,"release forbids cleartext even for loopback");}
        boolean denied=false;try{new OriginPolicy("http://other.invalid",true);}catch(IllegalArgumentException expected){denied=true;}check(denied,"debug does not authorize arbitrary cleartext servers");
        check(OriginPolicy.external("https://checkout.stripe.com/original"),"checkout opens independently");
        check(OriginPolicy.external("mailto:person@example.invalid"),"explicit mail link");
        check(OriginPolicy.external("tel:+49123456789"),"explicit telephone link");
        for(String url:new String[]{"javascript:original","intent://original","file:///original","https://user:secret@other.invalid/","https://other.invalid/\noriginal"})check(!OriginPolicy.external(url),"unsafe external launch denied");
        System.out.println("{\"suite\":\"native-app-origin-policy\",\"checks\":"+checks+",\"external_actions\":0}");
    }
}
