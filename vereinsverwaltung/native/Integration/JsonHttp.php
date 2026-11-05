<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration;
/** Fixed provider origins, bounded TLS requests, no redirects or credential-bearing diagnostics. */
final class JsonHttp
{
    private const ORIGINS=['eventbrite'=>'https://www.eventbriteapi.com','sumup'=>'https://api.sumup.com','sumup-online'=>'https://api.sumup.com','stripe'=>'https://api.stripe.com','paypal'=>'https://api-m.paypal.com','paypal-sandbox'=>'https://api-m.sandbox.paypal.com','meta'=>'https://graph.facebook.com','meta-upload'=>'https://rupload.facebook.com','google-wallet-oauth'=>'https://oauth2.googleapis.com','google-wallet'=>'https://walletobjects.googleapis.com'];
    public static function request(string $provider,string $method,string $path,array $headers=[],?array $body=null,?string $encoded=null):array
    {
        \Kulturhus\Native\Demo\Network::assertAllowed();
        if(!isset(self::ORIGINS[$provider])||!in_array($method,['GET','POST','DELETE'],true)||!preg_match('~^/[A-Za-z0-9_./?=&%+,:-]*$~D',$path)||str_contains($path,'..'))throw new \DomainException('Ungültiger API-Aufruf.');
        $disabled=in_array($provider,['meta','meta-upload'],true)?getenv('KULTURHUS_DISABLE_WEBSITE')==='1':(in_array($provider,['stripe','sumup-online'],true)?getenv('KULTURHUS_DISABLE_PAYMENTS')==='1'||getenv('KH_NATIVE_CI')==='1'||getenv('GITHUB_ACTIONS')==='true':getenv('KULTURHUS_DISABLE_MAIL')==='1');
        if(in_array($provider,['google-wallet','google-wallet-oauth'],true))$disabled=getenv('KULTURHUS_DISABLE_WALLET')==='1'||getenv('KH_NATIVE_CI')==='1'||getenv('GITHUB_ACTIONS')==='true';
        if($disabled||!in_array(getenv('KULTURHUS_LOCAL_DEMO'),[false,'','0'],true)||!in_array(getenv('KH_NATIVE_LOCAL_DEMO'),[false,'','0'],true))throw new \DomainException('Externe Aktionen in der Testumgebung gesperrt.');
        if(!function_exists('curl_init'))throw new \DomainException('PHP-cURL erforderlich.');
        foreach($headers as $header)if(!is_string($header)||preg_match('/[\r\n\x00]/',$header))throw new \DomainException('Ungültiger HTTP-Header.');
        if($body!==null&&$encoded!==null)throw new \LogicException('Ein Anfrageformat erforderlich.');
        $data=$body!==null?json_encode($body,JSON_THROW_ON_ERROR):$encoded;$buffer='';$responseHeaders=[];
        $ch=curl_init(self::ORIGINS[$provider].$path);
        curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>array_merge(['Accept: application/json'],$body!==null?['Content-Type: application/json']:[],$headers),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$buffer){if(strlen($buffer)+strlen($chunk)>8*1024*1024)return 0;$buffer.=$chunk;return strlen($chunk);},CURLOPT_HEADERFUNCTION=>static function($ch,$line)use(&$responseHeaders){if(strlen($line)>8192)return 0;$parts=explode(':',$line,2);if(count($parts)===2)$responseHeaders[strtolower(trim($parts[0]))]=trim($parts[1]);return strlen($line);}]);
        if($data!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$data);
        try {if(curl_exec($ch)===false)throw new \RuntimeException('API-Ergebnis unbekannt.');$status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);}finally{curl_close($ch);}
        try{$decoded=$buffer===''?[]:json_decode($buffer,true,128,JSON_THROW_ON_ERROR);}catch(\Throwable){throw new \RuntimeException('API-Antwort unlesbar.');}
        if(!is_array($decoded))throw new \RuntimeException('API-Antwort ungültig.');
        return ['status'=>$status,'body'=>$decoded,'headers'=>$responseHeaders];
    }
}
