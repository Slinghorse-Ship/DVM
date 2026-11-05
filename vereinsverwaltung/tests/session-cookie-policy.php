<?php declare(strict_types=1);
require __DIR__.'/../../processwire/site/src/WebsiteSessionPolicy.php';
use Kulturhus\WebsiteSessionPolicy;
$n=0;
function check(bool $value,string $label):void{global $n;if(!$value)throw new RuntimeException($label);$n++;echo "PASS $label\n";}
foreach([2592000=>2592000,86400=>86400,3600=>3600,1=>1,172800=>172800,5184000=>2592000,0=>2592000,-1=>2592000] as $configured=>$expected){
    check(WebsiteSessionPolicy::lifetime((object)['sessionExpireSeconds'=>$configured])===$expected,"finite cookie duration for idle setting $configured");
}
check(WebsiteSessionPolicy::lifetime((object)[])===2592000,'default duration is 30 days');
check(WebsiteSessionPolicy::lifetime((object)['kulturhusSessionCookieLifetime'=>34560000,'sessionExpireSeconds'=>0])===34560000,'explicit logout mode uses finite 400-day browser transport');
$signedConfig=(object)['userAuthSalt'=>'synthetic-preference-test-secret-only'];$sid=str_repeat('a',32);
$proof=static function(string $mode,string $id,int $expiry):string{
    $payload='v1|'.$mode.'|'.hash('sha256',$id).'|'.$expiry;
    $key=hash_hmac('sha256','kulturhus-device-session-v1','synthetic-preference-test-secret-only',true);
    return $payload.'|'.hash_hmac('sha256',$payload,$key);
};
foreach(['day','month','logout'] as $mode)check(WebsiteSessionPolicy::preference($signedConfig,$sid,$proof($mode,$sid,time()+60))===$mode,"signed $mode preference belongs to exact native SID");
$signed=$proof('logout',$sid,time()+60);
check(WebsiteSessionPolicy::preference($signedConfig,str_repeat('b',32),$signed)===null,'old preference cannot extend a new session identifier');
check(WebsiteSessionPolicy::preference($signedConfig,$sid,substr($signed,0,-1).(str_ends_with($signed,'a')?'b':'a'))===null,'tampered MAC never enables logout mode');
check(WebsiteSessionPolicy::preference($signedConfig,$sid,str_replace('|logout|','|month|',$signed))===null,'mode modification invalidates signature');
check(WebsiteSessionPolicy::preference($signedConfig,$sid,$proof('logout',$sid,time()-1))===null,'expired signed device proof is rejected');
check(WebsiteSessionPolicy::preference($signedConfig,$sid,null)===null,'missing duration proof cannot authorize longer lifetime');
check(WebsiteSessionPolicy::preference($signedConfig,$sid,[])===null,'malformed array duration cookie is rejected');
check(WebsiteSessionPolicy::preference((object)['userAuthSalt'=>'different-synthetic-secret'],$sid,$signed)===null,'different installation key cannot validate a duration proof');
$config=(object)['sessionExpireSeconds'=>86400];$before=ini_get('session.cookie_lifetime');
WebsiteSessionPolicy::configure($config);
check(!isset($config->kulturhusSessionCookieLifetime)&&ini_get('session.cookie_lifetime')===$before,'CLI jobs do not create or alter website cookies');
check(!WebsiteSessionPolicy::refresh((object)['config'=>$config]),'unconfigured runtime does not refresh cookies');
$bridge=file_get_contents(__DIR__.'/../src/Integration/ProcessWire/ProcessWireSession.php');
check(str_contains($bridge,"'logged_in'=>\$user->isLoggedin()")&&str_contains($bridge,"\$user->hasStatus(\\ProcessWire\\Page::statusLocked)"),'real ProcessWire login and account-state checks remain in the bridge');
$entry=file_get_contents(__DIR__.'/../public/index.php');
check(str_contains($entry,"if(\$production&&!(\$pwIdentity['logged_in']??false))")&&str_contains($entry,"ProcessWireIdentity::resolve(\$db"),'manager cookie alone is never accepted as central authentication');
check(str_contains($entry,"session_regenerate_id(true);\$_SESSION=[];\$_SESSION['csrf']")&&str_contains($entry,"session.use_strict_mode','1'"),'identity changes still rotate the session and clear prior actor drafts');
echo "$n session cookie policy checks passed\n";
