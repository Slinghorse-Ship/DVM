<?php declare(strict_types=1);
ini_set('zend.exception_ignore_args','1');
require dirname(__DIR__).'/native/bootstrap.php';
require dirname(__DIR__).'/src/bootstrap.php';
require __DIR__.'/support/original-database-hash.php';
use Kulturhus\Native\{TestSupport,Shared\Db,Delivery\DeliveryNotStarted};
use Kulturhus\Verein\{AdmissionVotes,MembershipApplications,ApplicationNotifications};
$db=TestSupport::db();Kulturhus\Native\Installer\SchemaRegistry::install($db);
$db->exec("INSERT INTO clubs VALUES('own-notifications','Own notifications'); INSERT INTO contacts(id,club_id,kind,name,email) VALUES('own-board-person','own-notifications','person','Own board','board@example.invalid'); INSERT INTO actors(id,club_id,role,contact_id,is_superuser) VALUES('own-admin','own-notifications','members',NULL,1),('own-board','own-notifications','member','own-board-person',0)");
(new AdmissionVotes($db,'own-admin'))->grant('own-board',0,'1. Vorstand',true,true);
$intake=new MembershipApplications($db,'own-notifications','own-admin');$settings=$intake->settings()['config'];$settings['enabled']=true;$intake->saveSettings($settings,0);
$person=['first_name'=>'Own','last_name'=>'Applicant','email'=>'applicant@example.invalid','birthday'=>'1990-01-02','address'=>'Own fixture street'];
$queue=static fn():string=>$intake->submit($person,bin2hex(random_bytes(16)),1,true);
$origin='https://notification.example.invalid';
foreach(['KULTURHUS_LOCAL_DEMO'=>'0','KULTURHUS_DISABLE_MAIL'=>'0','VEREIN_MODE'=>'production','APP_ENV'=>'production','APP_URL'=>$origin]as$name=>$value)putenv($name.'='.$value); // Capturing callbacks only; never SMTP.
$n=0;$check=static function(bool$ok,string$label)use(&$n){if(!$ok)throw new RuntimeException($label);$n++;echo'PASS '.$label."\n";};
$row=static fn(string$id)=>Db::run($db,'SELECT * FROM application_notifications WHERE club_id=? AND application_id=?',['own-notifications',$id])->fetch();
$id=$queue();$original=$row($id);$protected=['membership_applications','audit_events','actors','contacts','admission_board'];
$before=ownOriginalDatabaseHash($db,$protected);
$dispatch=static fn(callable$send,?PDO$connection=null):int=>ApplicationNotifications::dispatch($connection??$db,'own-notifications',$origin,$send,'Own club');
$check($dispatch(static fn()=>throw new DeliveryNotStarted('mail_configuration_missing'))===0&&$row($id)['status']==='pending','known unstarted mail retains the original application notification for safe continuation');
$held=$row($id);$check($held['application_id']===$original['application_id']&&$held['email']===$original['email']&&$held['created_at']===$original['created_at']&&ownOriginalDatabaseHash($db,$protected)===$before,'pending continuation keeps original application identity recipient creation and every full business row');
$messages=[];$check($dispatch(static function($to,$subject,$text)use(&$messages){$messages[]=[$to,$subject,$text];})===1&&count($messages)===1&&$messages[0][0]==='board@example.invalid'&&str_contains($messages[0][2],'application='.$id),'corrected delivery sends the exact original application link once');
$completed=$row($id);$check($dispatch(static fn()=>throw new RuntimeException('No repeated original'))===0&&$row($id)===$completed,'completed original notification never repeats or changes');
final class OwnNotificationAcknowledgement extends PDO{public bool$armed=false;}
final class OwnNotificationAcknowledgedStatement extends PDOStatement{
 protected function __construct(private OwnNotificationAcknowledgement$owner){}
 public function execute(?array$params=null):bool{$ok=parent::execute($params);if($this->owner->armed&&str_contains($this->queryString,"UPDATE application_notifications SET status='sent'")){$this->owner->armed=false;throw new PDOException('Own accepted UPDATE acknowledgement lost');}return$ok;}
}
$cfg=TestSupport::config();$fault=new OwnNotificationAcknowledgement('mysql:host=127.0.0.1;port=13371;dbname='.$cfg['database'].';charset=utf8mb4',$cfg['username'],$cfg['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);$fault->setAttribute(PDO::ATTR_STATEMENT_CLASS,[OwnNotificationAcknowledgedStatement::class,[$fault]]);
$acceptedId=$queue();$calls=0;$check($dispatch(static function()use($fault,&$calls){$calls++;$fault->armed=true;},$fault)===1&&$calls===1&&$row($acceptedId)['status']==='sent'&&$row($acceptedId)['sent_at']!==null,'real accepted sent UPDATE survives lost acknowledgement without false uncertainty');
$accepted=$row($acceptedId);$check($dispatch(static fn()=>throw new RuntimeException('No accepted replay'))===0&&$row($acceptedId)===$accepted,'accepted original is not sent or rewritten again');
$invalidId=$queue();$dispatch(static fn()=>throw new DeliveryNotStarted('mail_message_invalid'));$check($row($invalidId)['status']==='skipped','invalid unstarted message safely stops using the existing permitted state');
$unknownId=$queue();$calls=0;$dispatch(static function()use(&$calls){$calls++;throw new RuntimeException('Own unknown SMTP outcome');});$unknown=$row($unknownId);$check($calls===1&&$unknown['status']==='uncertain','begun unknown SMTP remains distinct from a known unstarted delivery');$dispatch(static function()use(&$calls){$calls++;});$check($calls===1&&$row($unknownId)===$unknown,'uncertain original notification never retries automatically');
$revokedId=$queue();$dispatch(static fn()=>throw new DeliveryNotStarted('mail_delivery_disabled'));Db::run($db,"UPDATE admission_board SET enabled=0 WHERE club_id='own-notifications'");$calls=0;$dispatch(static function()use(&$calls){$calls++;});$check($calls===0&&$row($revokedId)['status']==='skipped','pending continuation checks current board recipient authority before transport');
echo json_encode(['checks'=>$n,'external_actions'=>0],JSON_THROW_ON_ERROR)."\n";
