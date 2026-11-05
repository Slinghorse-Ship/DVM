<?php declare(strict_types=1);
require_once __DIR__.'/../src/Finance/Banking/EnableBanking.php';
require_once __DIR__.'/../src/Finance/Banking/BankImport.php';
use Kulturhus\Verein\EnableBanking;
if(PHP_SAPI!=='cli')exit(1);
$root=sys_get_temp_dir().'/kulturhus-ais-test-'.bin2hex(random_bytes(8));mkdir($root,0700);
foreach(['PRODUCTION','SANDBOX'] as $env){file_put_contents($root.'/enable-'.strtolower($env).'.key',random_bytes(64));file_put_contents($root.'/enable-'.strtolower($env).'.json',json_encode(['environment'=>$env,'private_key'=>'/different/ssh/chroot/key','redirect_url'=>'https://www.kulturhus.de/vereinsverwaltung/']));}
$calls=[];$state='';$badRedirect=false;$exchanges=0;
$transport=function($method,$path,$body)use(&$calls,&$state,&$badRedirect,&$exchanges){
    $calls[]=[$method,$path];
    return match($path){
        '/aspsps?country=DE'=>['aspsps'=>[['name'=>'Testbank','country'=>'DE']]],
        '/auth'=>(function()use($body,&$state,&$badRedirect){$state=$body['state'];return ['url'=>$badRedirect?'https://evil.invalid/':'https://tilisy-sandbox.enablebanking.com/ais/start?sessionid=test'];})(),
        '/sessions'=>(function()use(&$exchanges){$exchanges++;return ['session_id'=>'s1','accounts'=>[['uid'=>'account-1'],['uid'=>'account-2']]];})(),
        '/accounts/account-2/balances'=>['balances'=>[]],
        '/accounts/account-2/transactions'=>['transactions'=>[],'continuation_key'=>'next'],
        '/accounts/account-2/transactions?continuation_key=next'=>['transactions'=>[['entry_reference'=>'unique']]],
        '/accounts/account-2/transactions?date_from=2020-01-01&date_to=2020-01-31'=>['transactions'=>[],'continuation_key'=>'next page'],
        '/accounts/account-2/transactions?date_from=2020-01-01&date_to=2020-01-31&continuation_key=next+page'=>['transactions'=>[['entry_reference'=>'stable-production-shape']]],
        default=>throw new RuntimeException('Unexpected API call '.$path)
    };
};
$checks=0;$assert=function($ok)use(&$checks){if(!$ok)throw new RuntimeException('Assertion '.($checks+1).' failed');$checks++;};
$reject=function(callable $fn)use($assert){try{$fn();}catch(DomainException){$assert(true);return;}$assert(false);};
try{
 $client=new EnableBanking('SANDBOX','treasurer',$root,$transport);$client->start('Testbank');
 $wrong=new EnableBanking('SANDBOX','other-user',$root,$transport);$reject(fn()=>$wrong->finish($state,'code'));$assert($exchanges===0);
 $client->finish($state,'code');$assert(count($client->connections()['s1']['accounts'])===2);
 $reject(fn()=>$client->finish($state,'code'));$assert($exchanges===1);
 $assert((new EnableBanking('PRODUCTION','treasurer',$root,$transport))->connections()===[]);
 $assert($wrong->connections()===[]);$reject(fn()=>$wrong->accountData('account-2'));
 $assert($client->accountData('account-2')['transactions']['continuation_key']==='next');
 $assert(count($client->accountData('account-2','next')['transactions']['transactions'])===1);
 $assert($client->transactionsPage('account-2','2020-01-01','2020-01-31')['continuation_key']==='next page');
 $assert($client->transactionsPage('account-2','2020-01-01','2020-01-31','next page')['transactions'][0]['entry_reference']==='stable-production-shape');
 $reject(fn()=>$wrong->transactionsPage('account-2','2020-01-01','2020-01-31'));
 $reject(fn()=>$client->transactionsPage('account-2','2020-02-30','2020-03-01'));
 $reject(fn()=>$client->accountData('../payments'));
 foreach(glob($root.'/connections-*.enc') as $file)$assert(!str_contains(file_get_contents($file),'account-1'));
 $badRedirect=true;$reject(fn()=>$client->start('Testbank'));
 $assert(!EnableBanking::expired([]));
 $assert(EnableBanking::expired(['access'=>['valid_until'=>gmdate('c',time()-60)]]));
 $assert(!EnableBanking::expired(['access'=>['valid_until'=>gmdate('c',time()+60)]]));
 $assert(count(array_filter($calls,fn($call)=>str_contains($call[1],'payment')))===0);
 echo json_encode(['checks'=>$checks,'multi_account'=>true,'environment_isolation'=>true,'actor_binding'=>true,'replay_rejected'=>true,'encrypted_storage'=>true,'pagination'=>true]).PHP_EOL;
}finally{foreach(glob($root.'/*') as $file)unlink($file);rmdir($root);}
