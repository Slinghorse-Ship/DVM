<?php declare(strict_types=1);
/** Isolated SQLite and synthetic provider callbacks. No network, mail, or real accounts. */
require __DIR__.'/../src/bootstrap.php';
use Kulturhus\Verein\{Database,ClubChat,PushNotifications,ManagerNotifications as Preferences};

$db=\Kulturhus\Verein\Tests\MySqlFixture::connect(':memory:');Database::migrate($db);$checks=0;
function check(bool $condition,string $label):void{global $checks;if(!$condition)throw new RuntimeException($label);++$checks;echo "PASS $label\n";}
function denied(callable $run,string $label):void{try{$run();}catch(DomainException){check(true,$label);return;}throw new RuntimeException($label);}
$q=static function(string $sql,array $args=[])use($db):PDOStatement{$s=$db->prepare($sql);$s->execute($args);return $s;};
$key=static fn()=>bin2hex(random_bytes(16));
$db->exec("INSERT INTO clubs VALUES('a','Synthetic A'),('b','Synthetic B');
 INSERT INTO actors(id,club_id,role,is_superuser) VALUES
 ('sender','a','member',0),('recipient','a','members',1),('other','a','members',1),
 ('foreign-sender','b','member',0),('foreign','b','members',1),('editor','a','editor',0)");
$sub=static fn(string $id)=>['endpoint'=>'https://fcm.googleapis.com/fcm/send/synthetic-'.$id,'keys'=>[
 'p256dh'=>rtrim(strtr(base64_encode("\x04".str_repeat("\x00",64)),'+/','-_'),'='),
 'auth'=>rtrim(strtr(base64_encode(str_repeat('a',16)),'+/','-_'),'=')]];
foreach(['recipient'=>['one','two'],'other'=>['other'],'foreign'=>['foreign']] as $actor=>$devices)foreach($devices as $device)(new PushNotifications($db,$actor))->save($sub($device));
$preferences=static fn(string $actor)=>new Preferences($db,$actor);
$state=static fn()=> $preferences('recipient')->state();
$save=static function(array $topics)use($preferences,$state):void{$preferences('recipient')->save($topics,$state()['version']);};
$pending=static fn(string $actor)=>(int)$q("SELECT COUNT(*) FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id WHERE s.actor_id=? AND d.state='pending'",[$actor])->fetchColumn();
$messageState=static fn(string $actor,int $seq)=>$q('SELECT d.state FROM push_deliveries d JOIN push_subscriptions s ON s.id=d.subscription_id WHERE s.actor_id=? AND d.message_seq=? ORDER BY s.id',[$actor,$seq])->fetchAll(PDO::FETCH_COLUMN);
$chat=new ClubChat($db,'sender');$room=$chat->create('Synthetic discussion',['recipient','other']);
$foreignChat=new ClubChat($db,'foreign-sender');$foreignRoom=$foreignChat->create('Synthetic foreign discussion',['foreign']);
$send=static function(string $body='Synthetic text')use($chat,$room,$key,$db):int{$chat->send($room,$body,$key());return (int)$db->query('SELECT MAX(seq) FROM club_chat_messages')->fetchColumn();};
$providerCalls=[];$provider=static function(array $subscription,string $payload)use(&$providerCalls):string{$providerCalls[]=['endpoint'=>$subscription['endpoint'],'payload'=>$payload];return 'sent';};
$drain=static function()use($db,$provider,&$providerCalls):array{$providerCalls=[];PushNotifications::dispatch($db,$provider);return $providerCalls;};

check($state()['selected']===['chat']&&$state()['version']===0,'existing device with no preferences keeps chat enabled');
check($state()['available']['chat']==='Neue Chat-Nachrichten','chat is an available named account choice');
check((int)$q('SELECT COUNT(*) FROM notification_preferences')->fetchColumn()===0,'reading legacy choice writes no preference or history');
$q('INSERT INTO notification_preferences VALUES(?,?,?,?,?)',['a','recipient','["payments"]',1,gmdate('c')]);
check($state()['selected']===['chat','payments'],'legacy topic array without chat retains chat and its prior choices');
check(Preferences::chatEnabled($db,'recipient'),'enqueue and dispatch agree on legacy default');
check(!isset($preferences('editor')->state()['available']['chat']),'editor has no chat choice');
denied(fn()=>$preferences('editor')->save(['chat'],0),'editor cannot turn chat on');
denied(fn()=>$preferences('sender')->save(['payments'],0),'ordinary chat participant cannot add finance notifications');

$first=$send();$foreignChat->send($foreignRoom,'Synthetic foreign message',$key());
check($pending('recipient')===2&&$pending('other')===1&&$pending('foreign')===1,'old subscriptions receive new chat messages on each device');
$originalSubs=$q('SELECT * FROM push_subscriptions ORDER BY id')->fetchAll();
$oldVersion=$state()['version'];$save(['payments']);
check($state()['selected']===['payments']&&!Preferences::chatEnabled($db,'recipient'),'explicit off persists and reload presents chat unchecked');
$raw=json_decode((string)$q('SELECT topics FROM notification_preferences WHERE actor_id=?',['recipient'])->fetchColumn(),true);
check(in_array('_chat_disabled',$raw,true)&&!array_intersect(['_chat_disabled'],array_keys($state()['available'])),'private opt-out marker remains hidden from offered choices');
check($messageState('recipient',$first)===['cancelled','cancelled'],'turning off cancels all own waiting chat deliveries');
check($pending('other')===1&&$pending('foreign')===1,'turning off preserves other users and other clubs waiting deliveries');
check($q('SELECT * FROM push_subscriptions ORDER BY id')->fetchAll()===$originalSubs,'topic choice leaves every registered device intact');
denied(fn()=>$preferences('recipient')->save(['chat','payments'],$oldVersion),'stale form cannot re-enable chat');
check($state()['selected']===['payments'],'stale version failure preserves selected state');
denied(fn()=>$save(['_chat_disabled']),'public API rejects the private marker');
denied(fn()=>$save(['chat','unknown-topic']),'public API rejects unknown topics');
check($state()['selected']===['payments'],'invalid options do not alter valid preferences');
$calls=$drain();check(count($calls)===2,'only unaffected other accounts are sent pending notifications');

$missed=$send('Synthetic message while chat is off');
check($messageState('recipient',$missed)===[],'messages received while off never enter the recipient queue');
$drain();$save(['chat','payments']);
check($state()['selected']===['chat','payments']&&$pending('recipient')===0,'re-enable saves chat without creating a history replay');
check($messageState('recipient',$first)===['cancelled','cancelled']&&$messageState('recipient',$missed)===[],'re-enable neither revives cancelled deliveries nor backfills skipped messages');
check($drain()===[],'no old notification is dispatched immediately after re-enable');
$latest=$send('Synthetic newest message');$calls=$drain();
check(count($calls)===3&&$messageState('recipient',$latest)===['sent','sent'],'only a subsequent new message is delivered on both recipient devices');
check(!str_contains(implode('',array_column($calls,'payload')),'Synthetic newest'),'chat payload stays generic and contains no message text');
check((int)$q('SELECT COUNT(*) FROM manager_notifications')->fetchColumn()===0,'chat continues through its original queue with no second manager notification');

// A pre-existing queue row must also respect a disabled choice at dispatch, even
// if it did not pass through the current preference-save cancellation path.
$queued=$send();
$q('UPDATE notification_preferences SET topics=? WHERE actor_id=?',['["payments","_chat_disabled"]','recipient']);
$calls=$drain();
check(count($calls)===1&&$messageState('recipient',$queued)===['cancelled','cancelled'],'dispatcher rechecks explicit opt-out independently of enqueue');
$q('UPDATE notification_preferences SET topics=? WHERE actor_id=?',['["chat","payments","_chat_disabled"]','recipient']);
check(!Preferences::chatEnabled($db,'recipient')&&$state()['selected']===['payments'],'explicit opt-out wins over a contradictory stored legacy value');

// Failure must not commit only half of the preference/audit/cancellation operation.
$save(['chat','payments']);$atomicMessage=$send();$before=$state();
$db->exec("CREATE TRIGGER synthetic_audit_failure BEFORE INSERT ON audit_events FOR EACH ROW BEGIN IF NEW.action='notifications.preferences.saved' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic failure'; END IF; END");
$failed=false;try{$save(['payments']);}catch(PDOException){$failed=true;}$db->exec('DROP TRIGGER synthetic_audit_failure');
check($failed&&$state()===$before&&$pending('recipient')===2,'audit failure rolls back preference change and leaves pending notifications unchanged');
$drain();

// Dispatch loads a batch first. Turning off during a preceding recipient's
// callback must still prevent a later stale snapshot row from being claimed.
$save(['chat','payments']);
$earlier=$chat->create('Synthetic earlier room',['other']);$chat->send($earlier,'Earlier callback',$key());
$race=$send('Later batch row');$providerCalls=[];$switched=false;
PushNotifications::dispatch($db,static function(array $s,string $payload)use(&$switched,$save,$provider):string{if(!$switched){$save(['payments']);$switched=true;}return $provider($s,$payload);});
check($switched&&$messageState('recipient',$race)===['cancelled','cancelled'],'opt-out during an already loaded worker batch prevents later pending sends');
check(count($providerCalls)===2,'loaded batch still dispatches unaffected participant messages');

$save(['chat','payments']);$revoked=$send();$q('UPDATE actors SET active=0 WHERE id=?',['recipient']);
check(!Preferences::chatEnabled($db,'recipient'),'disabled account is never treated as chat enabled');
denied(fn()=>$save(['chat']),'disabled account cannot alter preferences');
$calls=$drain();check(count($calls)===1&&$messageState('recipient',$revoked)===['cancelled','cancelled'],'account revocation blocks previously queued notifications');
$blocked=$send();check($messageState('recipient',$blocked)===[],'inactive participant receives no new queued chat notification');$drain();
$q('UPDATE actors SET active=1 WHERE id=?',['recipient']);
check($pending('recipient')===0&&$drain()===[],'restoring account access also creates no replay');
check(!(\Kulturhus\Verein\Tests\MySqlFixture::foreignKeyErrors($db)[0]??false),'preference changes preserve relational integrity');
echo "$checks chat notification preference checks passed\n";
