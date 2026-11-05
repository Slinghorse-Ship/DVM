<?php declare(strict_types=1);
namespace Kulturhus\Native\Status;
/** Status-only API surface. The sole POST authenticates PayPal; no business write exists. */
final class Http
{
    private const ORIGINS=['meta'=>'https://graph.facebook.com','sumup'=>'https://api.sumup.com','stripe'=>'https://api.stripe.com','paypal'=>'https://api-m.paypal.com','paypal-sandbox'=>'https://api-m.sandbox.paypal.com','eventbrite'=>'https://www.eventbriteapi.com','stadtlandfunk'=>'https://prod.digitale-doerfer.de'];
    public static function allowed(string $provider,string $method,string $path):bool {
        return match($provider){
            'meta'=>$method==='GET'&&(bool)preg_match('~^/v[0-9]{1,3}\.[0-9]{1,2}/[A-Za-z0-9_-]{1,100}\?fields=id$~D',$path),
            'sumup'=>$method==='GET'&&$path==='/v0.1/me/merchant-profile',
            'stripe'=>$method==='GET'&&$path==='/v1/account',
            'paypal','paypal-sandbox'=>($method==='POST'&&$path==='/v1/oauth2/token')||($method==='GET'&&$path==='/v1/reporting/balances?currency_code=EUR'),
            'eventbrite'=>$method==='GET'&&(bool)preg_match('~^/v3/organizations/[A-Za-z0-9_-]{1,100}/(?:events/\?status=live&page_size=1)?$~D',$path),
            'stadtlandfunk'=>$method==='GET'&&$path==='/app/onboardingStatus',
            default=>false,
        };
    }
    public static function request(string $provider,string $method,string $path,#[\SensitiveParameter] array $headers=[],#[\SensitiveParameter] ?string $body=null):array {
        \Kulturhus\Native\Demo\Network::assertAllowed();
        if(!self::allowed($provider,$method,$path)||($method==='GET'&&$body!==null)||($method==='POST'&&$body!=='grant_type=client_credentials'))throw new \DomainException('Nur die festgelegte Dienstprüfung ist erlaubt.');
        $curl=curl_init(self::ORIGINS[$provider].$path);$buffer='';
        curl_setopt_array($curl,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$headers),CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($handle,$chunk)use(&$buffer){if(strlen($buffer)+strlen($chunk)>262144)return 0;$buffer.=$chunk;return strlen($chunk);}]);
        if($body!==null)curl_setopt($curl,CURLOPT_POSTFIELDS,$body);
        try{$ok=curl_exec($curl)!==false;$status=$ok?(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE):0;}finally{curl_close($curl);}
        try{$data=json_decode($buffer,true,64,JSON_THROW_ON_ERROR);$valid=is_array($data);}catch(\Throwable){$data=[];$valid=false;}
        return ['status'=>$status,'body'=>is_array($data)?$data:[],'json_valid'=>$valid];
    }
}
