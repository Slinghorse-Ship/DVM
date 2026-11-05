<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');ob_start();
require dirname(__DIR__).'/native/bootstrap.php';require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';require __DIR__.'/support/editorial-browser-fixture.php';
require __DIR__.'/support/own-website-pdf.php';
use Kulturhus\Native\{TestSupport,Installer\SchemaRegistry,Shared\Db};
use Kulturhus\Verein\Minutes;
use Kulturhus\Verein\Web\MinutesForm;
$cfg=TestSupport::config();$db=TestSupport::db();
if(($cfg['owner']??'')!=='isolated-native-ci'||($cfg['label']??'')!=='native-minutes-interface'||($cfg['host']??'')!=='127.0.0.1'||($cfg['port']??0)!==13371||$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)!==[])throw new RuntimeException('Fresh owned minutes fixture required.');
SchemaRegistry::install($db);$f=ownEditorialBrowserFixture($db);$actor=$f['accounts']['admin']['actor'];$minutes=new Minutes($db,$actor);$n=0;
$check=static function(bool $ok,string $why)use(&$n):void{if(!$ok)throw new RuntimeException($why);++$n;echo'PASS '.$why.PHP_EOL;};
$deny=static function(callable $operation,string $why,?int $code=null)use($check):void{$failure=null;try{$operation();}catch(DomainException $error){$failure=$error;}$check($failure!==null&&($code===null||$failure->getCode()===$code),$why);};
final class OwnMinutesConnection extends PDO
{
 public ?Closure $beforeMinuteWrite=null;
 public bool $loseCommit=false;
 public function prepare(string $query,array $options=[]):PDOStatement|false
 {
  if($this->beforeMinuteWrite!==null&&str_starts_with($query,'UPDATE minutes SET ')){$next=$this->beforeMinuteWrite;$this->beforeMinuteWrite=null;$next();}
  return parent::prepare($query,$options);
 }
 public function commit():bool{$ok=parent::commit();if($this->loseCommit){$this->loseCommit=false;throw new PDOException('Own minute COMMIT accepted before response loss');}return$ok;}
}
$connect=static function()use($cfg):OwnMinutesConnection{return new OwnMinutesConnection('mysql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);};
$fault=$connect();$other=$connect();$faultMinutes=new Minutes($fault,$actor);$otherMinutes=new Minutes($other,$actor);$key=static fn():string=>bin2hex(random_bytes(16));
$base=['title'=>'Protokolle {version} Own original ä🙂 <&>','meeting_on'=>'2026-10-31','source_kind'=>'editor','body_html'=>'<h2>Own original minutes</h2><p>0 Original body ä🙂</p>'];
$check($minutes->canRead()&&$minutes->canWrite(),'actual current minutes writer can read and write');
$tables=$db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$id=$minutes->save('',0,$base,$key());$otherBody='<p>Own separately committed concurrent text ä🙂</p>';$sourceAfterOther=null;
$fault->beforeMinuteWrite=static function()use($otherMinutes,$id,$base,$otherBody,$key,$db,$tables,&$sourceAfterOther):void{$otherMinutes->save($id,1,array_replace($base,['body_html'=>$otherBody]),$key());$sourceAfterOther=ownOriginalDatabaseHash($db,$tables);};
$failure=null;try{$faultMinutes->save($id,1,array_replace($base,['body_html'=>'<p>Own displaced stale intention</p>']),$key());}catch(Throwable $error){$failure=$error;}
$current=$minutes->entry($id);echo json_encode(['minute_concurrent_save_observation'=>['exception'=>$failure?get_class($failure):null,'code'=>$failure?->getCode(),'saved_body_is_other'=>$current['body_html']===$otherBody,'history_count'=>count($minutes->history($id))]],JSON_THROW_ON_ERROR).PHP_EOL;
$check($failure instanceof DomainException&&$failure->getCode()===409,'concurrent minute save cannot claim success for displaced original text');
$check($current['body_html']===$otherBody&&(int)$current['version']===2&&ownOriginalDatabaseHash($db,$tables)===$sourceAfterOther,'actual conflicting save retains every whole row after the concurrent original commit');

$pdfData=array_replace($base,['title'=>'Own exact PDF minutes','source_kind'=>'pdf','body_html'=>'']);$pdfA=ownWebsitePdf('Own first original minute PDF');$pdfB=ownWebsitePdf('Own independently saved original minute PDF');$pdfC=ownWebsitePdf('Own displaced stale PDF intention');
$pdfId=$minutes->save('',0,$pdfData,$key(),['name'=>'Own first.pdf','bytes'=>$pdfA]);$sourceAfterOther=null;
$fault->beforeMinuteWrite=static function()use($otherMinutes,$pdfId,$pdfData,$pdfB,$key,$db,$tables,&$sourceAfterOther):void{$otherMinutes->save($pdfId,1,$pdfData,$key(),['name'=>'Own concurrent original.pdf','bytes'=>$pdfB]);$sourceAfterOther=ownOriginalDatabaseHash($db,$tables);};
$failure=null;try{$faultMinutes->save($pdfId,1,$pdfData,$key(),['name'=>'Own displaced.pdf','bytes'=>$pdfC]);}catch(Throwable $error){$failure=$error;}
$file=$minutes->file($pdfId);$check($failure instanceof DomainException&&$failure->getCode()===409&&$file['name']==='Own concurrent original.pdf'&&$file['bytes']===$pdfB&&$file['sha256']===hash('sha256',$pdfB),'actual concurrent PDF change cannot replace the newer original file or its metadata');
$check(ownOriginalDatabaseHash($db,$tables)===$sourceAfterOther,'all complete rows and newer original PDF remain exact after the stale upload refusal');

$notificationActor=$f['accounts']['member']['actor'];(new Kulturhus\Verein\ManagerNotifications($db,$notificationActor))->save(['minutes'],0);
$encode=static fn(string $bytes):string=>rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');
(new Kulturhus\Verein\PushNotifications($db,$notificationActor))->save(['endpoint'=>'https://fcm.googleapis.com/fcm/send/own-isolated-minutes-reader','keys'=>['p256dh'=>$encode("\x04".str_repeat("\0",64)),'auth'=>$encode(str_repeat('a',16))]]);
$publicationId=$minutes->save('',0,array_replace($base,['title'=>'Own concurrent publication']),$key());$sourceAfterOther=null;
$fault->beforeMinuteWrite=static function()use($otherMinutes,$publicationId,$db,$tables,&$sourceAfterOther):void{$otherMinutes->transition($publicationId,1,'publish');$sourceAfterOther=ownOriginalDatabaseHash($db,$tables);};
$failure=null;try{$faultMinutes->transition($publicationId,1,'archive');}catch(Throwable $error){$failure=$error;}
$pending=(int)Db::run($db,"SELECT COUNT(*) FROM manager_push_deliveries d JOIN manager_notifications n ON n.id=d.notification_id WHERE n.source_id=? AND n.topic='minutes' AND d.state='pending'",[$publicationId])->fetchColumn();
$check($failure instanceof DomainException&&$failure->getCode()===409&&$minutes->entry($publicationId)['state']==='published'&&$pending===1,'stale archive cannot cancel a separately committed publication or its one genuine pending notification');
$check(ownOriginalDatabaseHash($db,$tables)===$sourceAfterOther,'all complete publication history notification and unrelated rows remain exact after the conflicting transition');

$createdKey=$key();$createdData=array_replace($base,['title'=>'Own accepted creation after lost acknowledgement']);$fault->loseCommit=true;$lost=false;
try{$faultMinutes->save('',0,$createdData,$createdKey);}catch(PDOException $error){$lost=$error->getMessage()==='Own minute COMMIT accepted before response loss';}
$created=Db::run($db,'SELECT * FROM minutes WHERE club_id=? AND request_key=?',[$f['club'],$createdKey])->fetch();
$check($lost&&$created&&$created['title']===$createdData['title']&&(int)$created['version']===1,'actual accepted creation preserves one original and its original lost-COMMIT exception');
$afterAccepted=ownOriginalDatabaseHash($db,$tables);$replayed=$minutes->save('',0,$createdData,$createdKey);
$check($replayed===$created['id']&&ownOriginalDatabaseHash($db,$tables)===$afterAccepted,'explicit identical creation replay reads its one original without another row or history event');
$refused=false;try{$minutes->save('',0,array_replace($createdData,['title'=>'Own different intent for accepted key']),$createdKey);}catch(DomainException){$refused=true;}
$check($refused&&ownOriginalDatabaseHash($db,$tables)===$afterAccepted,'changed intent cannot reuse an accepted creation key or alter its original');

$editId=$minutes->save('',0,array_replace($base,['title'=>'Own original before lost update']),$key());$desired=array_replace($base,['title'=>'Own accepted update after lost acknowledgement']);$fault->loseCommit=true;$lost=false;
try{$faultMinutes->save($editId,1,$desired,$key());}catch(PDOException $error){$lost=$error->getMessage()==='Own minute COMMIT accepted before response loss';}
$saved=$minutes->entry($editId);$check($lost&&$saved['title']===$desired['title']&&(int)$saved['version']===2,'actual accepted update preserves the intended original successor and lost-COMMIT error');
$afterAccepted=ownOriginalDatabaseHash($db,$tables);$refused=false;try{$minutes->save($editId,1,$desired,$key());}catch(DomainException $error){$refused=$error->getCode()===409;}
$check($refused&&ownOriginalDatabaseHash($db,$tables)===$afterAccepted,'explicit stale update after lost acknowledgement cannot append another version or audit');

$beforeAbort=ownOriginalDatabaseHash($db,$tables);$db->exec("CREATE TRIGGER own_minute_audit_abort BEFORE INSERT ON minutes_history FOR EACH ROW BEGIN IF NEW.minutes_id='".$pdfId."' AND NEW.action='updated' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Own exact minute history SQL abort'; END IF; END");
$failed=false;try{$minutes->save($pdfId,2,array_replace($pdfData,['title'=>'Own refused PDF history update']),$key(),['name'=>'Own refused replacement.pdf','bytes'=>$pdfC]);}catch(PDOException $error){$failed=str_contains($error->getMessage(),'Own exact minute history SQL abort');}finally{$db->exec('DROP TRIGGER own_minute_audit_abort');}
$check($failed&&ownOriginalDatabaseHash($db,$tables)===$beforeAbort&&$minutes->file($pdfId)['bytes']===$pdfB,'real history SQL abort rolls back PDF bytes metadata and every earlier whole original row');

$beforeReads=ownOriginalDatabaseHash($db,$tables);$recovered=$minutes->creationResult($createdKey);
$check($recovered!==null&&$recovered['id']===$created['id']&&!isset($recovered['request_key'])&&!isset($recovered['request_hash']),'explicit creator-scoped original intention lookup reads the same saved document without exposing request metadata');
$check($minutes->creationResult($key())===null,'an unconfirmed save without a stored result returns no substitute document');
$check(ownOriginalDatabaseHash($db,$tables)===$beforeReads,'original creation lookup never writes a row or creates a replacement draft');

$query=['view'=>'minutes'];$compose=$query+['compose'=>'1'];
foreach([
 $query+['mid'=>['Own original']],$query+['mid'=>null],$query+['mid'=>'0'],$query+['compose'=>'0'],$query+['compose'=>['1']],$query+['state'=>'unknown'],$query+['state'=>null],$query+['q'=>['Own original']],$query+['q'=>null],$query+['q'=>"invalid\xff"],$query+['year'=>'2026x'],$query+['year'=>['2026']],$query+['page'=>null],$query+['page'=>'0'],$query+['page'=>'01'],$query+['page'=>'1000001'],$query+['created_request'=>['Own key']],$query+['created_request'=>$createdKey,'mid'=>$id],$query+['created_request'=>$createdKey,'q'=>'0'],$query+['unknown'=>'never ignored'],
]as$i=>$raw)$deny(static fn()=>MinutesForm::query($raw),'unsupported original minutes query never falls back to another view or draft '.$i,422);
$check(MinutesForm::query($query+['q'=>'0','state'=>'draft'])['q']==='0','literal zero remains the requested original minutes search');
$post=['action'=>'minutes-save','csrf'=>'Own hidden minutes csrf','mid'=>'','version'=>'0','request_key'=>$key(),'title'=>'0 Own whole original ä🙂','meeting_on'=>'0 Own invalid day','source_kind'=>'editor','body_mode'=>'plain','body_html'=>'','body_text'=>"0 Own original text ä🙂\r\nA <literal> & line",'continue_edit'=>'1'];
$parsed=MinutesForm::command($compose,$post);$check($parsed['request_key']===$post['request_key']&&$parsed['version']===0&&$parsed['data']['meeting_on']===$post['meeting_on']&&$parsed['data']['body_html']==='<p>0 Own original text ä🙂<br>A &lt;literal&gt; &amp; line</p>','JavaScript-free command keeps original key version invalid day and literal text with real line breaks');
$rich='<h2>0 Own <strong>original</strong> heading ä🙂</h2><p>A &amp; B<br>Second line</p><ul><li>One</li><li>Two</li></ul>';$plain=MinutesForm::plain($rich);
$check(str_contains($plain,'0 Own original heading ä🙂')&&str_contains($plain,"A & B\nSecond line")&&!str_contains($plain,'<strong>'),'progressive plain editor exposes human text rather than stored HTML instructions');
$check(MinutesForm::fromPlain(str_replace("\n","\r\n",$plain),$rich)===$rich,'unchanged plain text preserves every original rich markup byte despite native form line endings');
$check(MinutesForm::fromPlain('0 New explicit plain text',$rich)==='<p>0 New explicit plain text</p>','consciously changed plain text becomes safe visible paragraphs without reinterpreting markup');
$edit=$post;$edit['mid']=$id;$edit['version']='2';$edit['body_mode']='html';$edit['body_html']=$rich;unset($edit['body_text']);$editQuery=$query+['mid'=>$id,'compose'=>'1'];
$check(MinutesForm::command($editQuery,$edit)['data']['body_html']===$rich,'enhanced rich editor retains exact original human markup for authoritative server sanitization');
foreach([
 [$compose,array_replace($post,['version'=>['0']])],[$compose,array_replace($post,['request_key'=>['Own raw key']])],[$compose,array_replace($post,['body_mode'=>'unknown'])],[$compose,array_replace($post,['body_text'=>['Own raw text']])],[$compose,array_replace($post,['continue_edit'=>'yes'])],[$compose,array_replace($post,['upload_required'=>'0'])],[$compose,$post+['ignored'=>'not ignored']],[$query,$post],[$editQuery,array_replace($edit,['mid'=>$pdfId])],[$query+['mid'=>$id],$edit],[$query+['created_request'=>$createdKey],$post],
]as$i=>[$scope,$raw])$deny(static fn()=>MinutesForm::command($scope,$raw),'unsupported or crossed complete minutes command preserves its raw original without writing '.$i);
$transition=['action'=>'minutes-transition','csrf'=>'Own transition csrf','mid'=>$id,'version'=>'2','transition'=>'publish'];
$check(!MinutesForm::command($query+['mid'=>$id],$transition)['confirmed'],'missing native transition confirmation never becomes an authorised publication');
$check(MinutesForm::command($query+['mid'=>$id],$transition+['confirm_transition'=>'1'])['confirmed'],'one explicit native confirmation retains its exact transition and original version');
$deny(static fn()=>MinutesForm::command($query+['mid'=>$id],$transition+['confirm_transition'=>'0']),'literal zero cannot confirm a publication',422);
$deny(static fn()=>MinutesForm::command($editQuery,$transition+['confirm_transition'=>'1']),'an open editing form cannot be silently converted to a publication',422);
$check(MinutesForm::download($query+['mid'=>$pdfId,'minute_file'=>$pdfId])===$pdfId,'original PDF download keeps the exact selected minutes identifier');
$deny(static fn()=>MinutesForm::download($query+['mid'=>$id,'minute_file'=>$pdfId]),'crossed PDF download cannot replace the originally selected protocol',409);
$check(ownOriginalDatabaseHash($db,$tables)===$beforeReads,'all original complete rows stay exact through strict form and progressive editor metadata checks');
require __DIR__.'/support/minutes-interface-models.php';
if(ob_get_level()>0)ob_end_flush();echo json_encode(['suite'=>'native-minutes-interface','checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR).PHP_EOL;
if(getenv('DVM_MINUTES_BROWSER')==='1')require __DIR__.'/support/minutes-browser-fixture.php';
