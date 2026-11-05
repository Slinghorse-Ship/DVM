<?php declare(strict_types=1);
require dirname(__DIR__).'/native/bootstrap.php';
use Kulturhus\Native\TestSupport;
use Kulturhus\Native\Shared\{Access,Db,RuntimeSchema,SecretBox};
use Kulturhus\Native\Communication\{Attention,ConversationTimeline,InboxCatalogue,MessageReplyProvider,Replies};
use Kulturhus\Native\Integration\Accounts;
require __DIR__.'/support/mail-timeline-originals.php';
require __DIR__.'/support/original-database-hash.php';
$db=TestSupport::db();$box=new SecretBox(str_repeat('s',32));$access=new Access($db,'admin');
if(($argv[1]??'')==='probe'){
    $result=(new InboxCatalogue($db,$box))->listing($access,['q'=>'Eigene Mailantwort 000']);
    if($result['total']!==1||$result['rows'][0]['message_page']!==2)throw new RuntimeException('Incomplete original mail search.');
    echo json_encode(['memory'=>memory_get_peak_usage(true),'rows'=>count($result['rows'])]);exit;
}
TestSupport::seed($db);RuntimeSchema::install($db);$club=$access->club();$accounts=new Accounts($db,$box);
$accounts->save($access,'meta',['page_id'=>'123','instagram_id'=>'456','api_version'=>'v25.0','token'=>'synthetic-never-used'],0);$account=$accounts->get($club,'meta');
$catalogue=new InboxCatalogue($db,$box);$replies=new Replies($db,$box);$timeline=new ConversationTimeline($replies);$n=0;
function searchCheck(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException($label);++$n;echo 'PASS '.$label."\n";}
function searchReject(callable $f,string $label):void{try{$f();}catch(DomainException){searchCheck(true,$label);return;}throw new RuntimeException($label);}
$threads=[];
foreach(['facebook','instagram']as$channel){
    $parent='own_search_'.$channel;$id=MessageReplyProvider::threadId($club,$channel,$parent);$messages=[];
    for($i=0;$i<85;$i++)$messages[]=['id'=>'own_'.$channel.'_'.$i,'incoming'=>$i===0||$i===84,'from_name'=>$i===0?'Früherer Originalgast':'Aktueller Originalgast','from_id'=>$i===0||$i===84?'own_guest':($channel==='facebook'?'123':'456'),'created_at'=>gmdate('Y-m-d H:i:s',time()-1000+$i),'text'=>$i===0?str_repeat('Unveränderte Vorbemerkung ',40).'Gesuchte Originalfrage tief Ä🙂 %_literal':($i===10?'Tatsächlich gesendete menschliche Antwort ä🙂':'Ursprünglicher gespeicherter Verlauf '.$i)];
    $payload=['messages'=>$messages,'conversation'=>$parent,'recipient'=>'own_guest','page_id'=>'123','instagram_id'=>'456','connection_id'=>Accounts::binding($account),'message_versions'=>['own_'.$channel.'_0'=>[['text'=>'Verborgene frühere Bearbeitung','from_name'=>'Historischer Versionsname']]],'connection_history'=>[['messages'=>[['text'=>'Andere frühere Kontoverbindung','from_name'=>'Frühere fremde Bindung']]]]];
    Db::run($db,'INSERT INTO native_social_inbox VALUES(?,?,?,?,?,UTC_TIMESTAMP())',[$club,$id,$channel,$box->seal('social-inbox:'.$club.':'.$id,$payload),end($messages)['created_at']]);
    (new Attention($db,$box))->observe($access,$channel,'message',$parent,[$messages[84]],true);$threads[$channel]=['id'=>$id,'payload'=>$payload];
}
$mail=ownMailTimeline($db,$box,$access,$account);$tables=['native_social_inbox','native_social_attention','native_social_originals','native_social_mail_threads','native_social_mail_replies','native_social_mail_dispatch','native_social_replies','native_private_settings','native_delivery_jobs'];$before=ownOriginalDatabaseHash($db,$tables);
$found=$catalogue->listing($access,['q'=>'gesuchte originalfrage tief ä🙂']);
searchCheck($found['total']===2&&count($found['rows'])===2,'literal case-insensitive Unicode search reaches old native incoming messages on both platforms');
foreach($found['rows']as$row){searchCheck($row['focus_message']==='own_'.$row['channel'].'_0'&&$row['message_page']===3,'oldest native match opens the actual third page '.$row['channel']);searchCheck(mb_strpos($row['text'],'Originalfrage tief Ä🙂')!==false&&mb_strlen($row['text'])<=152,'bounded exact excerpt includes a match beyond 1000 original characters '.$row['channel']);searchCheck(!isset($row['messages'],$row['ciphertext'],$row['payload']),'search projection contains no full private history '.$row['channel']);}
$found=$catalogue->listing($access,['q'=>'Früherer Originalgast']);searchCheck($found['total']===2&&$found['rows'][0]['name']==='Aktueller Originalgast','earlier real sender names remain searchable without replacing current conversation identity');
$found=$catalogue->listing($access,['q'=>'gesendete menschliche Antwort']);searchCheck($found['total']===2&&$found['rows'][0]['focus_message']==='own_'.$found['rows'][0]['channel'].'_10','actual sent native messages are searchable alongside incoming questions');
$found=$catalogue->listing($access,['q'=>'%_literal','channel'=>'instagram']);searchCheck($found['total']===1&&$found['rows'][0]['id']===$threads['instagram']['id'],'literal punctuation and platform filter apply before bounded result pagination');
foreach(['Verborgene frühere Bearbeitung','Andere frühere Kontoverbindung','synthetic-never-used','MMMMMMMM']as$term)searchCheck($catalogue->listing($access,['q'=>$term])['total']===0,'search excludes hidden versions, previous account payloads, credentials and original MIME: '.$term);
searchReject(fn()=>$catalogue->listing(new Access($db,'reader'),['q'=>'Originalfrage']),'ordinary role cannot search private historical message text');
searchCheck($catalogue->listing(new Access($db,'outsider'),['q'=>'Originalfrage'])['total']===0,'cross-club search has no original summaries or counts');
$found=$catalogue->listing($access,['q'=>'Eigene Mailantwort 000']);$row=$found['rows'][0];
searchCheck($found['total']===1&&$row['id']===$mail['thread']&&$row['focus_message']==='mail:'.$mail['mails'][0]&&$row['message_page']===2,'oldest of 70 exact mail answers is found outside the newest 40-message page');
searchCheck($row['text']===$mail['texts'][0],'mail search excerpt keeps the actual human answer and Unicode');
$page=$timeline->page($access,[$mail['question']],$mail['source'],$mail['connection'],$row['message_page']);searchCheck(in_array($row['focus_message'],array_column($page['messages'],'id'),true),'located search page contains the chosen exact mail original');
$found=$catalogue->listing($access,['q'=>'Eigene Mailantwort 069']);searchCheck($found['rows'][0]['message_page']===1,'newest exact mail answer opens the latest page');
searchCheck($catalogue->listing($access,['q'=>'E-Mail'])['total']===1,'mail type search returns one original conversation instead of seventy duplicate rows');
searchReject(fn()=>$timeline->pageContaining($access,[$mail['question']],$mail['source'],'different-binding','mail:'.$mail['mails'][0]),'search target cannot cross original mail account binding');
searchReject(fn()=>$timeline->pageContaining($access,[$mail['question']],$mail['source'],$mail['connection'],'not-an-original'),'unknown search target cannot substitute a nearby native message');
$command=[...TestSupport::phpCommand(),'-d','memory_limit=16M',__FILE__,'probe'];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$status=proc_close($process);$probe=json_decode($out,true);
searchCheck($status===0&&$errors===''&&$probe['rows']===1&&$probe['memory']<16*1024*1024,'actual 16-MB process searches more than 14 MB original encrypted MIME one answer at a time');
$db->beginTransaction();try{Db::run($db,'UPDATE native_social_mail_replies SET ciphertext=CONCAT(ciphertext,?) WHERE club_id=? AND id=?',['bad',$club,$mail['mails'][69]]);searchReject(fn()=>$catalogue->listing($access,['q'=>'unknown human answer']),'damaged authenticated original fails search rather than pretending the corpus is complete');}finally{$db->rollBack();}
searchCheck($before===ownOriginalDatabaseHash($db,$tables),'all searches and target calculations retain every exact original ciphertext, account and job');
(new Attention($db,$box))->observe($access,'facebook','message',$mail['source']['parent_id'],[],true);$archived=ownOriginalDatabaseHash($db,$tables);$found=$catalogue->listing($access,['q'=>'Eigene Mailantwort 000']);
searchCheck($found['total']===1&&$found['rows'][0]['message_page']===2,'read-only archived source remains searchable through its authenticated retained mail binding');
searchCheck($archived===ownOriginalDatabaseHash($db,$tables),'archived mail search keeps every exact current and historical original unchanged');
$fb=$threads['facebook'];$originals=$fb['payload']['messages'];searchCheck($timeline->pageContaining($access,$originals,null,null,$originals[0]['id'])===3,'native target uses exact earliest partial page');
for($i=0;$i<41;$i++)$originals[]=['id'=>'new_arrival_'.$i,'text'=>'New genuine arrival','created_at'=>gmdate('Y-m-d H:i:s',time()+$i),'incoming'=>true];
searchCheck($timeline->pageContaining($access,$originals,null,null,$originals[0]['id'])===4,'new arrivals recalculate actual search page instead of leaving a stale page link');
searchCheck($db->query('SELECT COUNT(*) FROM native_delivery_jobs')->fetchColumn()==0,'complete private search never queues a mail or provider action');
$revokingAccess=static fn(int $at)=>new class($db,'admin',$at) extends Access {
    private int $calls=0;
    public function __construct(private PDO $connection,string $actor,private int $at){parent::__construct($connection,$actor);}
    public function require(string $module,string $capability):array{if(++$this->calls===$this->at)Db::run($this->connection,"DELETE FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'");return parent::require($module,$capability);}
};
searchReject(fn()=>$catalogue->listing($revokingAccess(3),['channel'=>'instagram','q'=>'unknown original native text']),'revocation at metadata batch boundary suppresses the complete private result');
searchReject(fn()=>$replies->searchMail($revokingAccess(4),$mail['source'],$mail['connection'],'Eigene Mailantwort 000'),'revocation before returning an inspected original mail suppresses the private hit');
searchCheck((bool)Db::run($db,"SELECT 1 FROM native_grants WHERE actor_id='admin' AND module='social' AND capability='manage'")->fetchColumn(),'rejected synthetic revocation probes roll back without modifying actual current grants');
searchCheck($replies->searchMail($access,$mail['source'],'wrong-original-connection','Eigene Mailantwort')===null,'direct mail search cannot read another connection despite exact human text');
searchCheck($replies->searchMail($access,array_replace($mail['source'],['parent_id'=>'other-original-parent']),$mail['connection'],'Eigene Mailantwort')===null,'direct mail search cannot substitute a different original conversation');
$text="Genaue Originalfrage Ä🙂 <script>niemals Code</script>\r\nOriginaler Schluss.";
$html=\Kulturhus\Native\Web\Communication\ChatLayout::bubble('Originalgast',$text,'Zeit',false,'',true,'ä🙂 <script>');$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="UTF-8">'.$html);
searchCheck($dom->getElementsByTagName('p')->item(0)->textContent===$text&&$dom->getElementsByTagName('mark')->item(0)->textContent==='Ä🙂 <script>','focused literal highlighting preserves every original Unicode and newline byte as actual text');
searchCheck($dom->getElementsByTagName('script')->length===0&&$dom->getElementsByTagName('mark')->item(0)->hasAttribute('autofocus'),'original markup stays inert and exact matching text is keyboard/no-JavaScript focusable');
echo $n." conversation search checks passed; exact original message/mail text, bounded encrypted reads, no live transport.\n";
