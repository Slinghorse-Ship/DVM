<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,SecretBox,RuntimeSchema};
use Kulturhus\Native\Cms\Schema;
use Kulturhus\Native\Communication\{InboxArchive,InboxHistory,MessageReplyProvider};
use Kulturhus\Native\Integration\Accounts;
use Kulturhus\Native\Web\Communication\InboxHistoryPanel;
require __DIR__.'/support/mail-timeline-originals.php';require __DIR__.'/support/original-database-hash.php';
$db=TestSupport::db();$box=new SecretBox(str_repeat('h',32));$access=new Access($db,'admin');$service=new InboxHistory($db,$box);$n=0;
function historyCheck(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function historyReject(callable $f,string $label):void{try{$f();}catch(DomainException){historyCheck(true,$label);return;}throw new RuntimeException($label);}
if(($argv[1]??'')==='probe'){
    $fixture=json_decode(file_get_contents($argv[2]),true,32,JSON_THROW_ON_ERROR);
    $r=$service->view($access,$fixture['thread'],['inbox_history_kind'=>'connections','inbox_connection'=>$fixture['connection']]);
    if($r['timeline']['total']!==155||count($r['timeline']['messages'])!==40)throw new RuntimeException('Incomplete retained original account/mail history.');
    echo json_encode(['memory'=>memory_get_peak_usage(true)]);exit;
}
TestSupport::seed($db);RuntimeSchema::install($db);Schema::install($db);$club=$access->club();$accounts=new Accounts($db,$box);
$accounts->save($access,'meta',['page_id'=>'123','instagram_id'=>'456','api_version'=>'v25.0','token'=>'synthetic-never-used'],0);$account=$accounts->get($club,'meta');
$mail=ownMailTimeline($db,$box,$access,$account,200000,'blocked','message');$thread=$mail['thread'];$old=$box->open('social-inbox:'.$club.':'.$thread,Db::run($db,'SELECT ciphertext FROM native_social_inbox WHERE club_id=? AND id=?',[$club,$thread])->fetchColumn());
$old['messages']=[];for($i=0;$i<85;++$i)$old['messages'][]=['id'=>$i===0?'123':'old_'.$i,'text'=>'Ursprüngliche Kontonachricht '.$i.' ä🙂 <script>kein Code</script>','incoming'=>$i%2===0,'from_name'=>'Früherer eigener Gast','from_id'=>$old['recipient'],'created_at'=>'2020-01-01 '.sprintf('12:%02d:%02d',intdiv($i,60),$i%60)];
$versions=[];for($i=0;$i<65;++$i)$versions[]=array_replace($old['messages'][0],['text'=>"Genaue frühere Fassung ".$i." ä🙂\n<ursprüngliche Zeile>"]);
$old['message_versions']=['123'=>$versions];$past=[];
for($i=0;$i<34;++$i)$past[]=array_replace($old,['connection_id'=>hash('sha256','other-history-'.$i),'recipient'=>'own_history_guest_'.$i,'messages'=>[array_replace($old['messages'][0],['text'=>'Andere ursprüngliche Kontoverbindung '.$i])],'message_versions'=>[]]);
$old['connection_history']=$past;
$current=InboxArchive::merge($old,array_replace(array_diff_key($old,['connection_history'=>true,'message_versions'=>true]),['connection_id'=>hash('sha256','current-history'),'messages'=>[array_replace($old['messages'][0],['text'=>'Genaue aktuelle Kontonachricht'])]]));
// Additional current versions are distinct from versions of the original older account.
$current['message_versions']=['123'=>array_map(static fn($m)=>array_replace($m,['text'=>'Aktuell gespeicherte '.$m['text']]),$versions)];
Db::run($db,'UPDATE native_social_inbox SET ciphertext=? WHERE club_id=? AND id=?',[$box->seal('social-inbox:'.$club.':'.$thread,$current),$club,$thread]);
$igThread=MessageReplyProvider::threadId($club,'instagram',$old['conversation']);Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$club,$igThread,'instagram',$box->seal('social-inbox:'.$club.':'.$igThread,$current),'2020-01-01 12:00:00']);
$tables=['native_social_inbox','native_social_attention','native_social_originals','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_social_replies','native_private_settings','native_delivery_jobs','native_users','native_grants','khcms_pages','khcms_revisions','khcms_media'];$originals=ownOriginalDatabaseHash($db,$tables);
$seen=[];for($page=1;$page<=3;++$page){$r=$service->view($access,$thread,['inbox_history_page'=>$page]);historyCheck($r['total']===65&&$r['pages']===3&&count($r['rows'])===($page===3?15:25),'every exact previous message version is accessible on bounded page '.$page);historyCheck($r['selected']['id']===$r['rows'][0]['id'],'default selected version follows the actual catalogue page '.$page);array_push($seen,...array_column($r['rows'],'id'));}
historyCheck(count(array_unique($seen))===65,'numeric original message ID versions have distinct stable original choices without gaps');
$first=$service->view($access,$thread,[]);$last=$service->view($access,$thread,['inbox_version'=>end($seen)]);historyCheck($last['timeline']['messages'][0]['text']===$current['message_versions']['123'][0]['text']&&$last['selected']['id']===end($seen),'explicit early version is independent of catalogue page and retains exact Unicode/newline bytes');
$connections=$service->view($access,$thread,['inbox_history_kind'=>'connections']);$connection=$connections['rows'][0]['id'];
historyCheck($connections['total']===35&&$connections['pages']===2&&count($connections['rows'])===25,'all retained account connections have a bounded original catalogue');
historyCheck($connections['timeline']['total']===155&&count($connections['timeline']['messages'])===40,'previous real account retains all 85 native messages and its own 70 original mail answers');
$native=[];$mails=[];for($page=1;$page<=4;++$page){$r=$service->view($access,$thread,['inbox_history_kind'=>'connections','inbox_connection'=>$connection,'message_page'=>$page]);historyCheck(count($r['timeline']['messages'])<=40&&$r['timeline']['total']===155,'retained account/mail timeline is bounded and complete '.$page);foreach($r['timeline']['messages']as$message){if(str_starts_with($message['id'],'mail:'))$mails[]=$message;else$native[]=$message;}}
historyCheck(count($native)===85&&count($mails)===70&&count(array_unique(array_column($mails,'id')))===70,'every previous account original and human mail appears exactly once');
$ordered=$old['messages'];usort($native,static fn($a,$b)=>[$a['created_at'],$a['id']]<=>[$b['created_at'],$b['id']]);historyCheck($native===$ordered,'previous account originals retain exact every-field values and chronological order');
$early=$service->view($access,$thread,['inbox_history_kind'=>'connections','inbox_history_page'=>2]);historyCheck(count($early['rows'])===10&&$early['timeline']['total']===1,'early different account does not borrow any mail from the other binding');
$oldVersions=$service->view($access,$thread,['inbox_connection'=>$connection,'inbox_version'=>'']);historyCheck($oldVersions['timeline']['messages'][0]===$versions[64],'previous account edited versions are scoped to their own exact original snapshot');
historyReject(fn()=>$service->view($access,$thread,['inbox_connection'=>$connection,'inbox_version'=>$first['selected']['id']]),'current-account version cannot be substituted into a previous account');
historyReject(fn()=>$service->view($access,$igThread,['inbox_connection'=>$connection,'inbox_history_kind'=>'connections']),'Facebook history reference cannot select an Instagram original');
$ig=$service->view($access,$igThread,[]);historyCheck($ig['channel']==='instagram'&&$ig['total']===65,'Instagram native previous versions retain the same exact read-only behavior');
historyReject(fn()=>$service->view(new Access($db,'outsider'),$thread,[]),'other club cannot read retained original messages or mails');historyReject(fn()=>$service->view(new Access($db,'reader'),$thread,[]),'missing social permission cannot inspect private history');
foreach([['inbox_connection'=>str_repeat('a',32)],['inbox_version'=>str_repeat('a',32)],['inbox_history_kind'=>'other'],['inbox_history_page'=>4],['inbox_history_page'=>[]],['inbox_version'=>[]],['inbox_history_kind'=>'connections','inbox_version'=>$first['selected']['id']],['inbox_history_kind'=>'connections','message_page'=>5]]as$i=>$invalid)historyReject(fn()=>$service->view($access,$thread,$invalid),'invalid/foreign/unavailable historical reference rejected '.$i);
$work=sys_get_temp_dir().'/kh-inbox-history-'.bin2hex(random_bytes(8));mkdir($work,0700);file_put_contents($work.'/key',str_repeat('h',32));chmod($work.'/key',0600);file_put_contents($work.'/probe.json',json_encode(['thread'=>$thread,'connection'=>$connection],JSON_THROW_ON_ERROR));putenv('KH_NATIVE_PRIVATE_KEY_FILE='.$work.'/key');
try{
    $_GET=['inbox_history_kind'=>'connections','inbox_connection'=>$connection];$html=(new InboxHistoryPanel($db,$access,$work,'own-read-only-csrf'))->render($thread,['conversation_page'=>3,'inbox_channel'=>'facebook','q'=>'Own original query']);$doc=new DOMDocument();@$doc->loadHTML('<?xml encoding="UTF-8">'.$html);$xp=new DOMXPath($doc);
    historyCheck($xp->query('//form|//textarea|//input[@name="operation"]')->length===0&&!str_contains($html,'social-reply-send')&&!str_contains($html,'social-inbox-sync'),'historical chat has no reply, mail, sync or provider write action');
    historyCheck($xp->query('//*[@data-inbox-history]')->length===1&&$xp->query('//*[@class="chat-identity"]')->length===1&&str_contains($html,'Früherer Kontoverlauf · Lesen'),'history is explicitly distinguished as private Facebook message original');
    foreach($xp->query('//a')as$a){parse_str(parse_url($a->getAttribute('href'),PHP_URL_QUERY),$query);historyCheck(($query['conversation_page']??null)==='3'&&($query['q']??null)==='Own original query'&&($query['inbox_channel']??null)==='facebook','history navigation retains original conversation catalogue context');}
    $process=proc_open([...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'probe',$work.'/probe.json'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);$proof=json_decode($out,true);historyCheck($status===0&&$err===''&&($proof['memory']??PHP_INT_MAX)<16*1024*1024,'actual 16-MB process pages a previous connection with more than 14 MB of original encrypted MIME');
    historyCheck($originals===ownOriginalDatabaseHash($db,$tables),'all versions, account histories and old human mail views retain every exact original database byte');
    $inboxBytes=ownOriginalDatabaseHash($db,['native_social_inbox']);$accounts->disconnect($access,'meta',1);$disconnected=$service->view($access,$thread,['inbox_history_kind'=>'connections','inbox_connection'=>$connection]);historyCheck($disconnected['timeline']['total']===155&&$inboxBytes===ownOriginalDatabaseHash($db,['native_social_inbox']),'actual Meta disconnection leaves exact retained account/mail history readable without an active provider configuration');
    Db::run($db,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'");historyReject(fn()=>$service->view($access,$thread,[]),'current role revocation blocks retained private history immediately');
}finally{unlink($work.'/key');unlink($work.'/probe.json');rmdir($work);}
echo $n." native inbox history checks passed; actual old account/mail originals, no provider or business writes.\n";
