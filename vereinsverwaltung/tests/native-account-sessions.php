<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db};
use Kulturhus\Native\Auth\{Schema,Service,Sessions};
use Kulturhus\Native\Web\Account;

$db=TestSupport::db();TestSupport::seed($db);Schema::install($db);\Kulturhus\Native\Communication\Schema::install($db);
Db::run($db,"INSERT INTO native_grants(actor_id,module,capability) VALUES('admin','accounts','manage'),('outsider','accounts','manage')");
$now=1791756000;$auth=new Service($db,random_bytes(32),static function()use(&$now):int{return $now;});$password=bin2hex(random_bytes(16)).'Aa7!';$admin=new Access($db,'admin');
foreach(['reader','editor','outsider']as$actor){$invitation=$auth->createInvitation($actor==='outsider'?new Access($db,'outsider'):$admin,$actor,$actor==='outsider'?'own-outsider@example.invalid':'own-shared-family@example.invalid',86400,'own-'.$actor);$auth->acceptInvitation($invitation['token'],$password);}
$current=$auth->passwordLogin('own-reader',$password,'current')['session_token'];
$sameClub=$auth->passwordLogin('own-editor',$password,'same-club')['session_token'];$foreign=$auth->passwordLogin('own-outsider@example.invalid',$password,'foreign')['session_token'];
$owner=$auth->resolve($current);$tokens=[$current];
(new \Kulturhus\Native\Shared\Identity($db,$admin))->save(['name'=>'Own session fixture','origin'=>'https://own-session.example.invalid'],0);
for($i=0;$i<64;$i++)$tokens[]=Sessions::create($db,$owner['id'],$now-$i,'standard')['session_token'];
$expired=Sessions::create($db,$owner['id'],$now-40000,'standard')['session_token'];$closed=Sessions::create($db,$owner['id'],$now-10,'standard')['session_token'];$auth->logout($closed);
$challenge=$auth->beginEmailFactor($tokens[1],$password,true);$targetHash=hash('sha256',$tokens[1]);$targetCode=Db::run($db,'SELECT * FROM native_auth_email_codes WHERE id=?',[hash('sha256',$challenge['challenge'])])->fetch();if(!$targetCode)throw new RuntimeException('Actual bound email code required.');
$targetBefore=Db::run($db,'SELECT * FROM native_auth_sessions WHERE token_hash=?',[$targetHash])->fetch();
$tables=$db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);$before=ownOriginalDatabaseHash($db,$tables);$counts=[];foreach($tables as$table)$counts[$table]=(int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
$n=0;function check(bool $ok,string $label):void{global $n;if(!$ok)throw new RuntimeException($label);$n++;echo 'PASS '.$label."\n";}
function rejects(callable $action,string $label):void{try{$action();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$first=$auth->sessions($current);check($first['total']===65&&$first['pages']===11&&count($first['rows'])===6&&$first['rows'][0]['current']&&$first['rows'][0]['id']===hash('sha256',$current),'bounded own login catalogue includes the exact current device first');
$seen=[];for($page=1;$page<=$first['pages'];$page++){$list=$auth->sessions($current,$page);check($list['page']===$page&&count($list['rows'])===($page===11?5:6),'every actual login page contains its complete bounded window');foreach($list['rows']as$row){check(array_keys($row)===['id','created_at','last_seen','expires_at','duration_mode','current']&&is_int($row['created_at'])&&is_int($row['expires_at']),'login rows expose only original own metadata');$seen[]=$row['id'];}}
$expected=array_map(static fn(string $token):string=>hash('sha256',$token),$tokens);sort($seen);sort($expected);check($seen===$expected&&count(array_unique($seen))===65,'all original own login identities are reachable exactly once');
check($auth->sessions($current,1000000)['page']===11,'high valid page clamps to the original complete last window');
foreach([0,'0','01',-1,[],true,'1.2','1000001']as$page)rejects(fn()=>$auth->sessions($current,$page),'malformed login page cannot select or mutate a session');
check(ownOriginalDatabaseHash($db,$tables)===$before,'every page and invalid selector preserves every original account and session byte');
foreach([hash('sha256',$current),hash('sha256',$sameClub),hash('sha256',$foreign),str_repeat('a',64),'bad']as$id)rejects(fn()=>$auth->revokeSession($current,$id),'current, foreign, unrelated and malformed sessions cannot be individually closed');
rejects(fn()=>$auth->resolve($first['rows'][0]['id']),'listed session identifier cannot authenticate as a bearer token');
check(ownOriginalDatabaseHash($db,$tables)===$before,'all refused closures preserve every account/session/code original');
$view=new Account($auth,'own-csrf','own-client');$state=['auth_token'=>$current];
rejects(fn()=>$view->action(['csrf'=>'wrong','operation'=>'revoke-session','session_id'=>$targetHash],$state),'single-session account form retains CSRF before any mutation');
rejects(fn()=>$view->action(['csrf'=>'own-csrf','operation'=>'revoke-session','session_id'=>[]],$state),'forged target array cannot close an account session');
$html=$view->render('/account',['section'=>'sessions','session_page'=>'11'],$state);$dom=new DOMDocument();@$dom->loadHTML($html);$doc=new DOMXPath($dom);
check($doc->query('//ul[@class="account-session-list"]/li')->length===5&&$doc->query('//details[@id="account-sessions"][@open]')->length===1,'actual server-rendered account shows only the selected complete last window');
check($doc->query('//input[@name="session_page"]')->length===2&&!str_contains($html,$current)&&!str_contains($html,$password),'top and bottom native page controls expose no bearer or password');
check(ownOriginalDatabaseHash($db,$tables)===$before,'actual catalogue rendering changes no original row');
$excluded=static function(string $table)use($targetHash,$targetCode):array{return match($table){'native_auth_sessions'=>[' WHERE token_hash<>?',[$targetHash]],'native_auth_email_codes'=>[' WHERE id<>?',[$targetCode['id']]],default=>['',[]]};};$protected=ownOriginalDatabaseHash($db,$tables,$excluded);
check($auth->revokeSession($current,$targetHash),'exact selected other own login closes through the actual account service');
$targetAfter=Db::run($db,'SELECT * FROM native_auth_sessions WHERE token_hash=?',[$targetHash])->fetch();check((int)$targetAfter['revoked_at']===$now,'selected closure binds its exact original timestamp');unset($targetBefore['revoked_at'],$targetAfter['revoked_at']);check($targetAfter===$targetBefore,'selected session retains its original identity, duration and history bytes');
$codeAfter=Db::run($db,'SELECT * FROM native_auth_email_codes WHERE id=?',[$targetCode['id']])->fetch();check((int)$codeAfter['consumed_at']===$now&&$codeAfter['cipher']===null&&$codeAfter['status']==='blocked','only selected session-bound pending security mail is invalidated');foreach(['consumed_at','cipher','status']as$field){unset($codeAfter[$field],$targetCode[$field]);}check($codeAfter===$targetCode,'selected security-code history and original account binding remain intact');
check(ownOriginalDatabaseHash($db,$tables,$excluded)===$protected,'every other original account, session, factor, history, grant and row remains byte exact');
rejects(fn()=>$auth->resolve($tokens[1]),'closed selected login loses its actual authority immediately');check($auth->resolve($current)===$owner&&$auth->resolve($tokens[2])['id']===$owner['id']&&$auth->resolve($sameClub)['actor_id']==='editor'&&$auth->resolve($foreign)['actor_id']==='outsider','current, other own and foreign sessions retain their original live identities');
$now+=100;$after=ownOriginalDatabaseHash($db,$tables);check(!$auth->revokeSession($current,$targetHash)&&!$auth->revokeSession($current,hash('sha256',$expired))&&!$auth->revokeSession($current,hash('sha256',$closed)),'closed or expired original selections are idempotent');check(ownOriginalDatabaseHash($db,$tables)===$after,'closure replay changes no historical timestamp, code or session');
$db->beginTransaction();$auth->revokeSession($current,hash('sha256',$tokens[2]));$db->rollBack();check(ownOriginalDatabaseHash($db,$tables)===$after,'outer transaction rollback restores the exact selected original');
$notice=$view->action(['csrf'=>'own-csrf','operation'=>'revoke-session','session_id'=>$targetHash],$state);check(str_contains($notice['notice'],'bereits beendet'),'actual repeated account form reports its completed state');
foreach($counts as$table=>$count)check((int)Db::run($db,'SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn()===$count,'complete original table count retained: '.$table);
$currentHash=hash('sha256',$current);$withoutCurrent=static fn(string $table):array=>$table==='native_auth_sessions'?[' WHERE token_hash<>?',[$currentHash]]:['',[]];$otherOriginals=ownOriginalDatabaseHash($db,$tables,$withoutCurrent);$auth->logout($current);rejects(fn()=>$auth->sessions($current),'revoked current login cannot read the catalogue');rejects(fn()=>$auth->revokeSession($current,hash('sha256',$tokens[2])),'revoked current login cannot close another session');check(ownOriginalDatabaseHash($db,$tables,$withoutCurrent)===$otherOriginals,'current logout retains all other original records');
echo json_encode(['suite'=>'native-account-sessions','checks'=>$n,'active_originals'=>65,'external_requests'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
