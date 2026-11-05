<?php declare(strict_types=1);
namespace Kulturhus\Native\Integration {
    // An isolated process replaces only the first transport entry with a sentinel.
    // No request, socket or provider connection can be created by this test.
    final class OwnHttpWouldStart extends \RuntimeException {}
    function curl_init(string $url): never
    { $GLOBALS['own_http_origin']=$url;throw new OwnHttpWouldStart('Synthetic transport boundary only.'); }
}
namespace {
    require dirname(__DIR__).'/native/bootstrap.php';
    use Kulturhus\Native\Integration\{JsonHttp,OwnHttpWouldStart};
    $n=0;$check=static function(bool $ok,string $label)use(&$n):void{if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label.PHP_EOL;};
    $saved=[];foreach(['KULTURHUS_DISABLE_MAIL','KULTURHUS_DISABLE_WEBSITE','KULTURHUS_LOCAL_DEMO','KH_NATIVE_LOCAL_DEMO']as$name)$saved[$name]=getenv($name);
    $set=static function(string $name,string|false $value):void{putenv($value===false?$name:$name.'='.$value);};
    try{
        putenv('KULTURHUS_DISABLE_MAIL=0');putenv('KULTURHUS_DISABLE_WEBSITE=0');
        foreach([false,'','0','1','synthetic','true']as$legacy)foreach([false,'','0','1','synthetic','true']as$native){
            $set('KULTURHUS_LOCAL_DEMO',$legacy);$set('KH_NATIVE_LOCAL_DEMO',$native);unset($GLOBALS['own_http_origin']);$reached=false;$denied=false;
            try{JsonHttp::request('meta','GET','/v24.0/111?fields=id');}catch(OwnHttpWouldStart){$reached=true;}catch(DomainException){$denied=true;}
            $allowed=in_array($legacy,[false,'','0'],true)&&in_array($native,[false,'','0'],true);
            $check($reached===$allowed&&$denied!==$allowed,'actual transport boundary respects explicit production-zero and every synthetic demo flag');
            if($reached)$check($GLOBALS['own_http_origin']==='https://graph.facebook.com/v24.0/111?fields=id','eligible production request still uses the fixed Graph origin without contacting it');
        }
        putenv('KULTURHUS_LOCAL_DEMO=0');putenv('KH_NATIVE_LOCAL_DEMO=0');putenv('KULTURHUS_DISABLE_WEBSITE=1');
        try{JsonHttp::request('meta-upload','POST','/video-upload/701',['Authorization: OAuth synthetic-never-live']);throw new RuntimeException('Disabled transport entered.');}catch(DomainException){$check(true,'development disable switch blocks the upload origin before any transport');}
        putenv('KULTURHUS_DISABLE_WEBSITE=0');putenv('KULTURHUS_DISABLE_MAIL=1');
        try{JsonHttp::request('meta','GET','/v24.0/111?fields=id');}catch(OwnHttpWouldStart){$check(true,'explicit Meta eligibility remains independent of disabled mail transport');}
        try{JsonHttp::request('meta-upload','POST','/video-upload/701',['Authorization: OAuth synthetic-never-live']);}catch(OwnHttpWouldStart){$check($GLOBALS['own_http_origin']==='https://rupload.facebook.com/video-upload/701','eligible hosted upload uses exactly the separate fixed HTTPS origin');}
        foreach([['meta-upload','POST','https://attacker.invalid'],['meta-upload','POST','/../video-upload/701'],['meta-upload','PUT','/video-upload/701']]as[$provider,$method,$path]){
            try{JsonHttp::request($provider,$method,$path);throw new RuntimeException('Invalid route reached transport.');}catch(DomainException){$check(true,'invalid origin, traversal or method is rejected before transport');}
        }
        try{JsonHttp::request('meta-upload','POST','/video-upload/701',["Authorization: OAuth synthetic\r\nHost: attacker.invalid"]);throw new RuntimeException('Injected header reached transport.');}catch(DomainException){$check(true,'injected header cannot enter the hosted upload transport');}
        echo json_encode(['suite'=>'native-http-gates','checks'=>$n,'network_requests'=>0,'live_transport'=>false]).PHP_EOL;
    }finally{foreach($saved as$name=>$value)$set($name,$value);}
}
