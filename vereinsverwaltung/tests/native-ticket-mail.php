<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__,2).'/vendor/autoload.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Events\Workflow;
use Kulturhus\Native\Events\Tickets\{Offers,Orders,Mail};
use Kulturhus\Native\Cms\Pages;

$db=TestSupport::db();TestSupport::seed($db);Kulturhus\Native\Communication\Schema::install($db);Kulturhus\Native\Cms\Schema::install($db);
$access=new Access($db,'admin');$club=$access->club();$box=new SecretBox(random_bytes(32));$checks=0;
$check=static function(bool $ok,string $label)use(&$checks):void{if(!$ok)throw new RuntimeException($label);$checks++;};
$identity=new Identity($db,$access);$identity->save(['name'=>'Ticketverein','origin'=>'https://ticket-mail.example.invalid'],0);
(new Workflow($db,$access))->save(['source'=>'local','tickets'=>'native','payment'=>'none','automatic_publication'=>false],0);
$pages=new Pages($db,$access);$page=$pages->save(['title'=>'Mail event <original>','slug'=>'veranstaltungen/mail','template'=>'event','event_start'=>'2050-10-25T19:00','event_timezone'=>'Europe/Berlin']);$page=$pages->publish($page['id'],$page['version']);
(new Offers($db,$access))->save($page['id'],['capacity'=>100,'price_cents'=>0,'active'=>true],0);
$orders=new Orders($db,$club,$box,$access);$book=static fn($name)=>$orders->book($page['id'],1,1,$name,strtolower($name).'@example.invalid',bin2hex(random_bytes(32)));
$first=$book('First');$check(Mail::status($db,$club,$first['id'])==='queued','issuance atomically queues exactly one order confirmation');
$snapshot=static function()use($db):array{$data=[];foreach(['native_ticket_orders','native_ticket_passes','native_ticket_history']as$table)$data[$table]=$db->query('SELECT * FROM '.$table.' ORDER BY 1,2')->fetchAll();return $data;};
$originals=$snapshot();$sent=[];$send=static function(array $message)use(&$sent):void{$sent[]=$message;};
Mail::run($db,$box,$club,$send);$check(!$sent&&Mail::status($db,$club,$first['id'])==='queued','development policy blocks even injected transport without consuming queue');
$environment=[];foreach(['VEREIN_MODE','KULTURHUS_LOCAL_DEMO','KULTURHUS_DISABLE_MAIL','APP_ENV','APP_URL']as$key){$environment[$key]=getenv($key);putenv($key);}putenv('APP_URL=https://ticket-mail.example.invalid');
try{
    Mail::run($db,$box,$club,null);$check(Mail::status($db,$club,$first['id'])==='queued','disabled private worker leaves durable confirmation queued');
    Mail::run($db,$box,'another-club',$send);$check(!$sent,'foreign club cannot deliver original confirmation');
    $result=Mail::run($db,$box,$club,$send);$check($result===['sent'=>1,'blocked'=>0,'uncertain'=>0]&&count($sent)===1,'one bounded private dispatch sends exact original recipient');
    $message=$sent[0];$check($message['to']==='first@example.invalid'&&str_contains($message['text'],$first['access_token'])&&str_contains($message['text'],$first['title']),'message uses original purchaser, event and protected order access');
    $check(str_contains($message['html'],'&lt;original&gt;')&&!str_contains($message['html'],'Mail event <original>'),'original event text is escaped in HTML');
    $check(!str_contains(json_encode($message),$first['passes'][0]['code'])&&!isset($message['attachments']),'mail links to current verified status without permanently reusable QR attachment');
    $mime=Kulturhus\Native\Delivery\MailMessage::compose(['from'=>'tickets@example.invalid','reply_to'=>'reply@example.invalid'],$message)->toString();
    $check(str_contains($mime,'first@example.invalid')&&str_contains($mime,'multipart/alternative'),'real MIME composition includes plain and HTML message');
    Mail::run($db,$box,$club,$send);$check(count($sent)===1&&Mail::status($db,$club,$first['id'])==='sent','repeated worker never repeats confirmed SMTP call');
    $check($snapshot()===$originals,'delivery leaves full original payment/pass/history rows byte-identical');
    $fail=$book('Failure');$calls=0;$failing=static function()use(&$calls):void{$calls++;throw new RuntimeException('SMTP accepted but acknowledgement lost');};
    $result=Mail::run($db,$box,$club,$failing);$check($result['uncertain']===1&&Mail::status($db,$club,$fail['id'])==='uncertain','unknown SMTP result is persisted as uncertain');
    Mail::run($db,$box,$club,$failing);$check($calls===1,'uncertain send never automatically repeats');
    $interrupted=$book('Interrupted');Db::run($db,"UPDATE native_ticket_mail SET status='sending' WHERE order_id=?",[$interrupted['id']]);
    $result=Mail::run($db,$box,$club,$send);$check($result['uncertain']===1&&Mail::status($db,$club,$interrupted['id'])==='uncertain'&&count($sent)===1,'abandoned durable send intent becomes uncertain without another SMTP call');
    $review=$book('Review');Db::run($db,"UPDATE native_ticket_orders SET status='payment_review' WHERE id=?",[$review['id']]);
    $result=Mail::run($db,$box,$club,$send);$check($result['blocked']===1&&Mail::status($db,$club,$review['id'])==='blocked'&&count($sent)===1,'payment correction blocks queued confirmation before transport');
    $changed=$book('Changed');$identity->save(['name'=>'Ticketverein','origin'=>'https://changed.example.invalid'],1);
    Mail::run($db,$box,$club,$send);$check(Mail::status($db,$club,$changed['id'])==='blocked'&&count($sent)===1,'changed club origin cannot redirect original private buyer access');
    $identity->save(['name'=>'Ticketverein','origin'=>'https://ticket-mail.example.invalid'],2);
    $rollback=null;try{Db::atomic($db,static function()use($book,&$rollback):void{$rollback=$book('Rollback');throw new RuntimeException('rollback');});}catch(DomainException|RuntimeException){}
    $check($rollback===null,'booking inside an unrelated transaction cannot leak a mail intent');
    $a=$book('Boundedone');$b=$book('Boundedtwo');$result=Mail::run($db,$box,$club,$send,1);$check($result['sent']===1&&count($sent)===2,'worker limit bounds actual sends');
    Mail::run($db,$box,$club,$send,1);$check(count($sent)===3,'next bounded run delivers remaining original once');
    $concurrent=$book('Concurrent');$otherDb=TestSupport::db();$nested=null;
    Mail::run($db,$box,$club,static function($message)use($otherDb,$box,$club,$send,&$nested):void{$nested=Mail::run($otherDb,$box,$club,$send);$send($message);});
    $check($nested===['sent'=>0,'blocked'=>0,'uncertain'=>0]&&count($sent)===4&&Mail::status($db,$club,$concurrent['id'])==='sent','independent MySQL worker cannot classify or repeat an active SMTP attempt');
    $check((int)Db::run($db,"SELECT COUNT(*) FROM native_ticket_mail WHERE club_id=? AND status='sending'",[$club])->fetchColumn()===0,'all owned completed send attempts leave durable terminal status');
    foreach([0,21]as$limit){try{Mail::run($db,$box,$club,$send,$limit);throw new RuntimeException('invalid bound accepted');}catch(DomainException){$check(true,'invalid worker bound rejected');}}
    $check(Mail::status($db,'foreign',$first['id'])===null,'mail status does not cross club scope');
    $routes=Kulturhus\Verein\MailChannelSettings::defaults();$check($routes['routes']['tickets']==='board'&&isset(Kulturhus\Verein\MailChannelSettings::PURPOSES['tickets']),'ticket confirmations have their own configurable shared SMTP purpose');
}finally{foreach($environment as$key=>$value)putenv($value===false?$key:$key.'='.$value);}
echo "$checks ticket mail contracts passed; injected transport only, no real SMTP.\n";
