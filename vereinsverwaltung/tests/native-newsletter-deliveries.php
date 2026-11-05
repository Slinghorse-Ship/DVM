<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__,2).'/vendor/autoload.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,Identity,SecretBox};
use Kulturhus\Native\Communication\{Workspace,Subscribers,Campaigns,NewsletterGateway,NewsletterDeliveryCatalogue as Catalogue};
use Kulturhus\Native\Delivery\{Outbox,MailMessage};
$db=TestSupport::db();
if(($argv[1]??'')==='--memory'){
    $in=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$ownBox=new SecretBox(base64_decode($in['key'],true));$p=Catalogue::page($db,new Access($db,'admin'),$ownBox,['newsletter_job'=>$in['job']]);
    echo json_encode(['rows'=>count($p['rows']),'total'=>$p['total'],'bytes'=>strlen($p['selected']['message']),'peak'=>memory_get_peak_usage(true),'limit'=>ini_get('memory_limit')],JSON_THROW_ON_ERROR);exit;
}
if(($argv[1]??'')==='--kill'){
    $in=json_decode(stream_get_contents(STDIN),true,8,JSON_THROW_ON_ERROR);$ownBox=new SecretBox(base64_decode($in['key'],true));
    (new Outbox($db,$ownBox))->run(static function():never{posix_kill(getmypid(),9);exit(99);},1,['test'],'synthetic-newsletter-kill');exit(98);
}
require dirname(__DIR__).'/src/bootstrap.php';require __DIR__.'/support/original-database-hash.php';
\Kulturhus\Native\Installer\SchemaRegistry::install($db);TestSupport::seed($db);
$access=new Access($db,'admin');$reader=new Access($db,'reader');$club=$access->club();$key=random_bytes(32);$box=new SecretBox($key);$outbox=new Outbox($db,$box);
$identity=new Identity($db,$access);$identity->save(['name'=>'Own original newsletter club','origin'=>'https://newsletter.example.invalid','address'=>'Own fixture address'],$identity->get()['version']);
$subs=new Subscribers($db,$access);$people=[];for($i=0;$i<2;$i++)$people[]=$subs->recordWrittenConsent('own-newsletter'.$i.'@example.invalid','Own human recipient '.$i,['events','news'],'Own synthetic written consent '.$i);
$work=new Workspace($db,$access,'newsletter');$item=$work->save(['title'=>'Human original newsletter subject','body'=>'Actual original newsletter text ä🙂']);$item=$work->approve($item['id'],1);
$mailRoot=dirname(getenv('KH_NATIVE_TEST_CONFIG')).'/mail-design-'.TestSupport::config()['database'];mkdir($mailRoot,0700);$mailDesign=['schema'=>1,'theme'=>'kulturhus-classic','origin'=>'https://newsletter.example.invalid','name'=>'Own original newsletter club','tagline'=>'Own original old tagline','stock_logo'=>true];file_put_contents($mailRoot.'/mail-design.json',json_encode($mailDesign,JSON_THROW_ON_ERROR));chmod($mailRoot.'/mail-design.json',0600);putenv('VEREIN_PRIVATE_ROOT='.$mailRoot);
$campaigns=new Campaigns($db,$access,$box);$jobs=$campaigns->queue($item['id'],gmdate('Y-m-d H:i:s',time()-10));$n=0;
function newsletterHistoryCheck(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function newsletterHistoryReject(callable $f,string $label):void{try{$f();}catch(DomainException){newsletterHistoryCheck(true,$label);return;}throw new RuntimeException($label);}
$page=Catalogue::page($db,$access,$box);
newsletterHistoryCheck($page['total']===2&&count($page['rows'])===2&&array_unique(array_column($page['rows'],'subject'))===[$item['title']],'actual human campaign recipients and original subjects appear in private counted history');
$detail=Catalogue::page($db,$reader,$box,['newsletter_job'=>$jobs[0]]);
newsletterHistoryCheck($detail['selected']['original']['id']===$item['id']&&$detail['selected']['original']['version']===2&&str_contains($detail['selected']['message'],'Actual original newsletter text'),'document reader opens exact immutable approved original and individual recorded mail content');
$firstPayload=$box->open('job:'.$club.':'.$jobs[0],Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$jobs[0]])->fetchColumn());
newsletterHistoryCheck(isset($firstPayload['newsletter_original']['view_hash'])&&!str_contains($detail['selected']['message'],$firstPayload['newsletter_original']['view_hash']),'actual new delivery binds its own frozen browser snapshot without displaying private reference tokens');

newsletterHistoryCheck(str_contains($firstPayload['html'],'kh-mail-card')&&str_contains($firstPayload['html'],'Saxophon')&&str_contains($firstPayload['html'],'Own original old tagline')&&str_contains($firstPayload['html'],'Actual original newsletter text'),'new approved newsletter freezes the complete original table design with unchanged authored content');
$frozenEdition=json_decode(Db::run($db,'SELECT snapshot_json FROM native_campaign_views WHERE club_id=? AND token_hash=?',[$club,$firstPayload['newsletter_original']['view_hash']])->fetchColumn(),true,64,JSON_THROW_ON_ERROR);$browserHtml=\Kulturhus\Native\Shared\MailDesign::browserNewsletter($frozenEdition,\Kulturhus\Native\Communication\Content::preview($frozenEdition,null,true,false));
newsletterHistoryCheck(!str_contains($browserHtml,'<style>')&&str_contains($browserHtml,'/assets/newsletter-classic.css?v=')&&str_contains($browserHtml,'Actual original newsletter text'),'frozen classic browser edition uses its registered responsive stylesheet under the existing CSP without relaxing the policy');
$mail=MailMessage::compose(['from'=>'club@example.invalid','reply_to'=>'reply@example.invalid'],$firstPayload);$mime=$mail->toString();newsletterHistoryCheck(str_contains($mime,'Content-ID: <newsletter-logo@kulturhus>')&&str_contains($mime,'List-Unsubscribe:'),'new classic layout composes the exact embedded PNG and individual unsubscribe headers without a transport');
$originalCipher=Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$jobs[0]])->fetchColumn();$mailDesign['tagline']='Later private configuration';file_put_contents($mailRoot.'/mail-design.json',json_encode($mailDesign,JSON_THROW_ON_ERROR));
newsletterHistoryCheck($originalCipher===Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$jobs[0]])->fetchColumn(),'changing a private presentation setting never rewrites an original queued mail payload');
$longTitle=str_repeat('x',300);$test=$work->save(['title'=>$longTitle,'body'=>'Actual human test text']);$testJobs=$campaigns->queue($test['id'],gmdate('Y-m-d H:i:s',time()-10),'own-test@example.invalid');
$testDetail=Catalogue::page($db,$access,$box,['newsletter_job'=>$testJobs[0]]);
newsletterHistoryCheck($testDetail['selected']['original']['version']===1&&$testDetail['selected']['subject']==='[Test] '.$longTitle,'actual unapproved test mail retains its original draft and complete longest permitted subject');
newsletterHistoryCheck(Catalogue::page($db,$access,$box,['newsletter_item'=>$test['id'],'newsletter_kind'=>'test'])['total']===1,'actual test original participates in its own edition filter without approval');
$testCipher=Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$testJobs[0]])->fetchColumn();$testPayload=$box->open('job:'.$club.':'.$testJobs[0],$testCipher);
Db::run($db,'UPDATE native_campaign_views SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 DAY WHERE club_id=? AND token_hash=?',[$club,$testPayload['newsletter_original']['view_hash']]);
newsletterHistoryCheck(Catalogue::page($db,$reader,$box,['newsletter_job'=>$testJobs[0]])['selected']['original']===$testDetail['selected']['original'],'expired public test link keeps its authenticated private original evidence readable');
$sourceRow=Db::run($db,'SELECT snapshot_json FROM native_campaign_views WHERE club_id=? AND token_hash=?',[$club,$testPayload['newsletter_original']['view_hash']])->fetchColumn();
Db::run($db,'UPDATE native_campaign_views SET snapshot_json=? WHERE club_id=? AND token_hash=?',[json_encode(['title'=>'Changed false source'],JSON_THROW_ON_ERROR),$club,$testPayload['newsletter_original']['view_hash']]);
newsletterHistoryReject(fn()=>Catalogue::page($db,$reader,$box,['newsletter_job'=>$testJobs[0]]),'changed snapshot bytes cannot be passed off as the originally recorded newsletter');
Db::run($db,'UPDATE native_campaign_views SET snapshot_json=? WHERE club_id=? AND token_hash=?',[$sourceRow,$club,$testPayload['newsletter_original']['view_hash']]);
$wrong=$testPayload;$wrong['newsletter_original']['view_hash']=str_repeat('a',64);Db::run($db,'UPDATE native_delivery_jobs SET ciphertext=? WHERE id=?',[$box->seal('job:'.$club.':'.$testJobs[0],$wrong),$testJobs[0]]);
newsletterHistoryReject(fn()=>Catalogue::page($db,$reader,$box,['newsletter_job'=>$testJobs[0]]),'unavailable frozen original is refused rather than rebuilt from current edition');Db::run($db,'UPDATE native_delivery_jobs SET ciphertext=? WHERE id=?',[$testCipher,$testJobs[0]]);
$account=['from'=>'club@example.invalid','reply_to'=>'reply@example.invalid'];$sent=[];
$result=$outbox->run(function($ownClub,$payload,$kind,$job)use($account,&$sent){$mail=MailMessage::compose($account,$payload);$sent[$job['id']]=$mail->getSubject();return 'own-local-newsletter-'.$job['id'];},10,['campaign','test'],$club);
newsletterHistoryCheck(count($result)===3&&count($sent)===3&&$sent[$testJobs[0]]==='[Test] '.$longTitle,'actual fake transport composes all original messages including a full 300-character test title');
newsletterHistoryCheck(Catalogue::page($db,$access,$box,['newsletter_state'=>'sent'])['total']===3&&Catalogue::page($db,$access,$box,['newsletter_kind'=>'test'])['total']===1,'real completed status and mail-kind filters count original newsletter jobs');
$changed=$work->save(['id'=>$item['id'],'title'=>'Human later newsletter revision','body'=>'Actual later source text'],2);
$after=Catalogue::page($db,$reader,$box,['newsletter_job'=>$jobs[0]]);
newsletterHistoryCheck($after['selected']['original']===$detail['selected']['original']&&$after['selected']['message']===$detail['selected']['message'],'later edition edit never replaces the originally queued and actually processed source');
$gateway=new NewsletterGateway($db,$club,$box);$signup=$gateway->signup('own-confirmation@example.invalid','Own confirmation recipient',['events'],'192.0.2.12',true);
$confirmationJob=Db::run($db,"SELECT id FROM native_delivery_jobs WHERE club_id=? AND kind='confirm' ORDER BY due_at DESC,id DESC LIMIT 1",[$club])->fetchColumn();$confirmation=Catalogue::page($db,$access,$box,['newsletter_job'=>$confirmationJob]);$confirmPayload=$box->open('job:'.$club.':'.$confirmationJob,Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$confirmationJob])->fetchColumn());
preg_match('~token=([a-f0-9]{64})~',$confirmPayload['text'],$confirmationToken);
newsletterHistoryCheck($confirmation['selected']['kind']==='confirm'&&isset($confirmationToken[1])&&!str_contains($confirmation['selected']['message'],$confirmationToken[1])&&str_contains($confirmation['selected']['message'],'Persönlicher Newsletterlink'),'actual stored confirmation mail displays human content without handing staff a consent token');
$outbox->run(static fn()=> 'own-confirmation-only',1,['confirm'],$club);
Db::run($db,"UPDATE khn_newsletter_tokens SET expires_at=UTC_TIMESTAMP()-INTERVAL 1 DAY WHERE club_id=? AND subscriber_id=? AND kind='confirm'",[$club,$confirmPayload['subscriber']]);
$renewals=new \Kulturhus\Native\Communication\Confirmations\Renewals($db,$access,$box);$renewalSelection=$renewals->preview($confirmPayload['subscriber']);$renewal=$renewals->queue($confirmPayload['subscriber'],$renewalSelection['hash'],bin2hex(random_bytes(16)),true)[0];
newsletterHistoryCheck(!Catalogue::page($db,$reader,$box,['newsletter_job'=>$renewal])['selected']['prepared'],'unprepared real renewal record has no fabricated original mail content');
$legacy=$outbox->enqueue($club,'test','own-legacy-history-test',['to'=>'own-legacy@example.invalid','subject'=>'Actual old test subject','html'=>'<p>Actual old test content</p>','text'=>'Actual old test content'],gmdate('Y-m-d H:i:s'),$access,'edit');
$legacyView=Catalogue::page($db,$reader,$box,['newsletter_job'=>$legacy]);newsletterHistoryCheck($legacyView['selected']['original']===null&&$legacyView['selected']['message']==='Actual old test content','old test without an original reference remains readable without inventing its edition');
$foreign=$outbox->enqueue('synthetic-b','test','own-foreign-history-test',['to'=>'private-foreign@example.invalid','subject'=>'Foreign private subject','text'=>'Foreign private body','html'=>'<p>Foreign private body</p>'],gmdate('Y-m-d H:i:s'),new Access($db,'outsider'),'edit');
newsletterHistoryReject(fn()=>Catalogue::page($db,$access,$box,['newsletter_job'=>$foreign]),'foreign original delivery cannot enter another club history');
newsletterHistoryReject(fn()=>Catalogue::page($db,$access,$box,['newsletter_job'=>$jobs[0],'newsletter_kind'=>'confirm']),'focused job must belong to the retained mail-kind filter');
newsletterHistoryReject(fn()=>Catalogue::page($db,$access,$box,['newsletter_state'=>'unknown']),'unsupported original delivery status rejected');
foreach([[],0,'01','0',1000001]as$value)newsletterHistoryReject(fn()=>Catalogue::page($db,$reader,$box,['newsletter_page'=>$value]),'ambiguous or excessive history page cannot form a SQL offset');
$unsubscribe=$gateway->unsubscribeToken($firstPayload['subscriber']);newsletterHistoryCheck($gateway->redeem($unsubscribe,'unsubscribe'),'actual public withdrawal erases its original private job data');
$removed=Catalogue::page($db,$access,$box,['newsletter_job'=>$jobs[0]]);newsletterHistoryCheck($removed['selected']['removed']&&$removed['selected']['email']===''&&$removed['selected']['message']===''&&$removed['selected']['original']===null,'withdrawn recipient and message are never reconstructed from older campaign source');
$ownTables=['native_delivery_jobs','native_delivery_sources','native_campaign_views','khn_newsletter_items','khn_newsletter_revisions','khn_newsletter_subscribers','khn_newsletter_consent_events'];$before=ownOriginalDatabaseHash($db,$ownTables);Catalogue::page($db,$reader,$box,['newsletter_job'=>$jobs[1]]);newsletterHistoryCheck(ownOriginalDatabaseHash($db,$ownTables)===$before,'complete individual and original history is read-only for every original queue/source/consent row');
for($i=0;$i<507;$i++)$outbox->enqueue($club,'test','own-paginated-history-'.$i,['to'=>'page'.$i.'@example.invalid','subject'=>'Own human history '.$i,'text'=>'Own exact stored history text','html'=>'<p>Own exact stored history text</p>'],gmdate('Y-m-d H:i:s',time()+3600+$i),$access,'edit');
$full=Catalogue::page($db,$reader,$box);$last=Catalogue::page($db,$reader,$box,['newsletter_page'=>'999999']);
newsletterHistoryCheck($full['total']===513&&count($full['rows'])===6&&$last['page']===86&&count($last['rows'])===3,'all own newsletter deliveries beyond 200 and 500 remain reachable in bounded six-row pages');
$focused=Catalogue::page($db,$reader,$box,['newsletter_job'=>$jobs[1]]);newsletterHistoryCheck($focused['selected']['id']===$jobs[1]&&$focused['page']===86,'deep original link selects its actual counted page beyond hundreds of later jobs');
$snapshotHash=ownOriginalDatabaseHash($db,$ownTables);
$mutationTarget=$full['rows'][0]['id'];$requireCount=0;
$changingAccess=new class($db,$mutationTarget,$box,$requireCount) extends Access {
    private int $calls=0;
    public function __construct(private PDO $ownDb,private string $job,private SecretBox $ownBox,int $ignored){parent::__construct($ownDb,'admin');}
    public function require(string $module,string $capability):array{$result=parent::require($module,$capability);if(++$this->calls===3)Db::run($this->ownDb,'UPDATE native_delivery_jobs SET ciphertext=? WHERE id=?',[$this->ownBox->seal('job:synthetic-a:'.$this->job,[]),$this->job]);return$result;}
};
newsletterHistoryReject(fn()=>Catalogue::page($db,$changingAccess,$box),'erasure during actual multi-message read prevents stale private recipient output');
$large=[];$largeText=str_repeat('own actual stored body ',33000);foreach(range(0,5)as$i)$large[]=$outbox->enqueue($club,'test','own-memory-history-'.$i,['to'=>'memory'.$i.'@example.invalid','subject'=>'Own bounded history '.$i,'text'=>$largeText,'html'=>'<p>'.$largeText.'</p>'],gmdate('Y-m-d H:i:s',time()+10800+$i),$access,'edit');unset($largeText);
$proc=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'--memory'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);if(!is_resource($proc))throw new RuntimeException('Own memory process unavailable.');fwrite($pipes[0],json_encode(['key'=>base64_encode($key),'job'=>$large[5]],JSON_THROW_ON_ERROR));fclose($pipes[0]);$memory=stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($proc);if($exit!==0)throw new RuntimeException('Own memory proof failed: '.substr($memory,0,300));$memory=json_decode($memory,true,16,JSON_THROW_ON_ERROR);
newsletterHistoryCheck($memory['rows']===6&&$memory['total']===519&&$memory['bytes']>700000&&$memory['limit']==='16M'&&$memory['peak']<=16777216,'real 16-MB process reads six large originals and selected full body without buffering all queue ciphertexts');
Db::run($db,"INSERT INTO native_users(id,club_id,name) VALUES('own-newsletter-kill','synthetic-newsletter-kill','Own real process interruption')");Db::run($db,"INSERT INTO native_grants VALUES('own-newsletter-kill','newsletter','edit')");
$killJob=$outbox->enqueue('synthetic-newsletter-kill','test','own-real-process-kill',['to'=>'own-kill@example.invalid','subject'=>'Own actual interrupted message','html'=>'<p>Own human interrupted message</p>','text'=>'Own human interrupted message'],gmdate('Y-m-d H:i:s',time()-10),new Access($db,'own-newsletter-kill'),'edit');
$p=proc_open([...TestSupport::phpCommand(),__FILE__,'--kill'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Own interrupted worker unavailable.');fwrite($pipes[0],json_encode(['key'=>base64_encode($key)],JSON_THROW_ON_ERROR));fclose($pipes[0]);stream_get_contents($pipes[1]);fclose($pipes[1]);proc_close($p);
newsletterHistoryCheck(Db::run($db,'SELECT status FROM native_delivery_jobs WHERE id=?',[$killJob])->fetchColumn()==='processing','actual SIGKILL leaves its accepted durable newsletter transport claim');
Db::run($db,'UPDATE native_delivery_jobs SET started_at=UTC_TIMESTAMP()-INTERVAL 11 MINUTE WHERE id=?',[$killJob]);$killCipher=Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$killJob])->fetchColumn();
$result=$outbox->run(static fn()=>throw new RuntimeException('Never repeat interrupted newsletter mail.'),1,['test'],'synthetic-newsletter-kill');
newsletterHistoryCheck($result===[]&&Db::run($db,'SELECT status FROM native_delivery_jobs WHERE id=?',[$killJob])->fetchColumn()==='uncertain'&&Db::run($db,'SELECT ciphertext FROM native_delivery_jobs WHERE id=?',[$killJob])->fetchColumn()===$killCipher,'actual interrupted newsletter worker requires human review and never automatically repeats its original mail');
Db::run($db,"DELETE FROM native_grants WHERE actor_id='reader' AND module='newsletter' AND capability='read'");newsletterHistoryReject(fn()=>Catalogue::page($db,$reader,$box),'current newsletter right revocation immediately closes a retained original link');
if(getenv('DVM_NEWSLETTER_DELIVERIES_BROWSER')==='1'){
    require __DIR__.'/support/newsletter-deliveries-browser-fixture.php';$browser=ownNewsletterDeliveriesBrowser($db,$box,$key,$testJobs[0]);
    try{$p=proc_open(['node',__DIR__.'/newsletter-deliveries-browser.mjs'],[0=>['pipe','r'],1=>['pipe','w']],$pipes);if(!is_resource($p))throw new RuntimeException('Own history browser unavailable.');fwrite($pipes[0],json_encode($browser['input'],JSON_THROW_ON_ERROR));fclose($pipes[0]);while(($line=fgets($pipes[1]))!==false)echo$line;fclose($pipes[1]);if(proc_close($p)!==0)throw new RuntimeException('Actual newsletter history browser failed.');}
    finally{proc_terminate($browser['process']);proc_close($browser['process']);fclose($browser['log']);}
}
echo json_encode(['checks'=>$n,'new_tables'=>0,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
