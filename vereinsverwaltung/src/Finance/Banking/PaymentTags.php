<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
use PDOException;
use Throwable;

/** Versioned labels for complete recorded payments. Never changes money or approvals. */
final class PaymentTags
{
 public const TABLES=['finance_payment_tags','finance_payment_tag_states','finance_payment_tag_assignments','finance_payment_tag_events'];
 public const MAX_TAGS=200;
 public const MAX_SELECTION=20;
 public function __construct(private PDO$db,private string$actorId){}
 private function actor(bool$write=false):array{$a=Permissions::actor($this->db,$this->actorId);if(!in_array($a['finance_access'],$write?['write']:['read','write'],true))throw new DomainException('Finanzberechtigung für Zahlungstags erforderlich.',403);return$a;}
 public function available():bool{$this->actor();$q=$this->db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN(?,?,?,?)');$q->execute(self::TABLES);if((int)$q->fetchColumn()!==4)return false;
  if((int)$this->db->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn()<128)return false;
  $q=$this->db->prepare('SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME IN(?,?) ORDER BY TRIGGER_NAME');$q->execute(['payment_tag_event_immutable_delete','payment_tag_event_immutable_update']);$guards=$q->fetchAll();if(count($guards)!==2)return false;foreach($guards as$guard)if($guard['ACTION_TIMING']!=='BEFORE'||$guard['EVENT_OBJECT_TABLE']!=='finance_payment_tag_events'||$guard['ACTION_STATEMENT']!=="BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Payment tag event originals are immutable'; END"||$guard['TRIGGER_NAME']!=='payment_tag_event_immutable_'.strtolower($guard['EVENT_MANIPULATION']))return false;return true;}
 private function ready():void{if(!$this->available())throw new DomainException('Zahlungstags benötigen die vollständige Paketaktualisierung.',503);}
 private static function json(mixed$value):string{$sort=static function(mixed$item)use(&$sort):mixed{if(!is_array($item))return$item;if(!array_is_list($item))ksort($item,SORT_STRING);foreach($item as$key=>$child)$item[$key]=$sort($child);return$item;};return json_encode($sort($value),JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR);}
 private static function text(string$value,int$max):void{if(!mb_check_encoding($value,'UTF-8')||str_contains($value,"\0")||$value===''||mb_strlen($value)>$max)throw new DomainException('Ursprüngliche Zahlungstag-Angaben prüfen.',422);}
 private static function key(string$key):void{if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Ursprüngliche Vorgangskennung prüfen. Zahlungstags bewusst neu öffnen.',422);}
 private static function name(string$raw):string{self::text($raw,80);$name=trim($raw);if($name===''||preg_match('/[\x00-\x1f\x7f]/u',$raw)||preg_match('/\A\s*\z/u',$name))throw new DomainException('Tagname vollständig und ohne Steuerzeichen angeben (höchstens 80 Zeichen).',422);return$name;}
 private function read(callable$fn):mixed{$own=!$this->db->inTransaction();try{if($own){$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();}$result=$fn();if($own)$this->db->commit();return$result;}catch(Throwable$failure){if($own&&$this->db->inTransaction())$this->db->rollBack();throw$failure;}}
 private function known(array$a,string$key,string$request):?array{$q=$this->db->prepare('SELECT * FROM finance_payment_tag_events WHERE club_id=? AND request_key=?');$q->execute([$a['club_id'],$key]);$row=$q->fetch();if(!$row)return null;if($row['actor_id']!==$a['id']||$row['request_sha256']!==hash('sha256',$request)||$row['request_json']!==$request)throw new DomainException('Vorgangskennung gehört zu anderen Zahlungstag-Angaben.',409);return$row;}
 private static function result(array$event,bool$replayed):array{return['replayed'=>$replayed,'event'=>$event,'first'=>json_decode($event['next_snapshot'],true,128,JSON_THROW_ON_ERROR)];}
 private function write(string$key,string$request,callable$fn):array
 {
  $a=$this->actor(true);if($this->db->inTransaction())throw new DomainException('Zahlungstags benötigen einen eigenen Speichervorgang.',409);self::key($key);$this->ready();if($known=$this->known($a,$key,$request))return self::result($known,true);
  try{$this->db->beginTransaction();$a=$this->actor(true);if($known=$this->known($a,$key,$request)){$this->db->commit();return self::result($known,true);}$result=$fn($a);$this->db->commit();return$result;}
  catch(Throwable$failure){if($this->db->inTransaction())$this->db->rollBack();if($failure instanceof PDOException){if((int)($failure->errorInfo[1]??0)===1062&&($known=$this->known($a,$key,$request)))return self::result($known,true);if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Zahlungstags werden gerade bearbeitet. Bitte erneut prüfen.',503,$failure);}throw$failure;}
 }
 private function append(array$a,string$scope,string$object,int$version,mixed$before,mixed$after,string$key,string$request):array
 {
  $id=bin2hex(random_bytes(16));$at=gmdate('Y-m-d\TH:i:s\Z');$this->db->prepare('INSERT INTO finance_payment_tag_events(club_id,id,scope,object_id,source_version,result_version,actor_id,happened_at,request_key,request_json,request_sha256,previous_snapshot,next_snapshot) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$id,$scope,$object,$version,$version+1,$a['id'],$at,$key,$request,hash('sha256',$request),self::json($before),self::json($after)]);
  $this->db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$a['club_id'],$a['id'],'finance.tags.'.$scope.'.saved',$object,$version+1,$at]);return$this->known($a,$key,$request)??throw new \RuntimeException('Gespeicherter Zahlungstag-Vorgang fehlt.');
 }
 public function catalogue():array{$a=$this->actor();if(!$this->available())return[];$q=$this->db->prepare('SELECT id,name,active,version FROM finance_payment_tags WHERE club_id=? ORDER BY name,id');$q->execute([$a['club_id']]);return$q->fetchAll();}
 public function tag(string$id):array{$a=$this->actor();$this->ready();self::text($id,32);$q=$this->db->prepare('SELECT id,name,active,version FROM finance_payment_tags WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);return$q->fetch()?:throw new DomainException('Zahlungstag nicht verfügbar.',404);}
 public function saveTag(?string$id,int$version,string$rawName,bool$active,string$key):array
 {
  $this->actor(true);if(($id===null&&$version!==0)||($id!==null&&$version<1))throw new DomainException('Ursprüngliche Tagfassung prüfen.',422);if($id!==null)self::text($id,32);$name=self::name($rawName);$request=self::json(['scope'=>'catalogue','id'=>$id,'version'=>$version,'name'=>$rawName,'active'=>$active]);
  return$this->write($key,$request,function(array$a)use($id,$version,$name,$active,$key,$request):array{
   $q=$this->db->prepare('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id']]);if(!$q->fetchColumn())throw new DomainException('Vereinszugang nicht verfügbar.',403);
   $before=null;$actual=$id??bin2hex(random_bytes(16));if($id!==null){$q=$this->db->prepare('SELECT id,name,active,version FROM finance_payment_tags WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$before=$q->fetch();if(!$before)throw new DomainException('Zahlungstag nicht verfügbar.',404);if((int)$before['version']!==$version)throw new DomainException('Zahlungstag inzwischen geändert. Zuerst vergleichen.',409);if($before['name']===$name&&(bool)$before['active']===$active)throw new DomainException('Keine geänderten Zahlungstag-Angaben vorhanden.',422);}
   else{$q=$this->db->prepare('SELECT COUNT(*) FROM finance_payment_tags WHERE club_id=?');$q->execute([$a['club_id']]);if((int)$q->fetchColumn()>=self::MAX_TAGS)throw new DomainException('Höchstens 200 Zahlungstags pro Verein verwenden.',422);}
   $nameKey=hash('sha256',mb_strtolower($name,'UTF-8'));$q=$this->db->prepare('SELECT id FROM finance_payment_tags WHERE club_id=? AND name_key=?');$q->execute([$a['club_id'],$nameKey]);$same=$q->fetchColumn();if($same!==false&&$same!==$actual)throw new DomainException('Dieser Tagname ist bereits vorhanden. Vorhandenen Zahlungstag verwenden.',409);
   if($id===null)$this->db->prepare('INSERT INTO finance_payment_tags(club_id,id,name,name_key,active,version) VALUES(?,?,?,?,?,1)')->execute([$a['club_id'],$actual,$name,$nameKey,(int)$active]);
   else{$q=$this->db->prepare('UPDATE finance_payment_tags SET name=?,name_key=?,active=?,version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([$name,$nameKey,(int)$active,$a['club_id'],$id,$version]);if($q->rowCount()!==1)throw new DomainException('Zahlungstag inzwischen geändert. Zuerst vergleichen.',409);}
   $next=['id'=>$actual,'name'=>$name,'active'=>(int)$active,'version'=>$version+1];return self::result($this->append($a,'catalogue',$actual,$version,$before,$next,$key,$request),false);
  });
 }
 private function source(array$a,string$id,bool$lock=false):array{self::text($id,128);$q=$this->db->prepare('SELECT * FROM finance_transactions WHERE club_id=? AND id=?'.($lock?' FOR UPDATE NOWAIT':''));$q->execute([$a['club_id'],$id]);return$q->fetch()?:throw new DomainException('Kontoumsatz nicht verfügbar.',404);}
 private function selected(array$a,string$source):array{$q=$this->db->prepare('SELECT t.id,t.name,t.active,t.version FROM finance_payment_tag_assignments x JOIN finance_payment_tags t ON t.club_id=x.club_id AND t.id=x.tag_id WHERE x.club_id=? AND x.transaction_id=? ORDER BY t.name,t.id');$q->execute([$a['club_id'],$source]);return$q->fetchAll();}
 public function state(string$id):array
 {
  $this->actor();return$this->read(function()use($id):array{$a=$this->actor();$source=$this->source($a,$id);$inherited=$source['source']==='reversal'&&$source['reversal_of']!==null;$origin=$inherited?$this->source($a,$source['reversal_of']):$source;$ready=$this->available();if(!$ready)return['available'=>false,'source'=>$source,'transaction_id'=>$origin['id'],'inherited'=>$inherited,'version'=>0,'tags'=>[],'catalogue'=>[]];$q=$this->db->prepare('SELECT version FROM finance_payment_tag_states WHERE club_id=? AND transaction_id=?');$q->execute([$a['club_id'],$origin['id']]);return['available'=>true,'source'=>$source,'transaction_id'=>$origin['id'],'inherited'=>$inherited,'version'=>(int)($q->fetchColumn()?:0),'tags'=>$this->selected($a,$origin['id']),'catalogue'=>$this->catalogue()];});
 }
 public function assign(string$id,int$version,array$choices,string$key):array
 {
  $this->actor(true);self::text($id,128);if($version<0||count($choices)>self::MAX_SELECTION)throw new DomainException('Ursprüngliche Zahlungstag-Auswahl prüfen.',422);
  foreach($choices as$tag=>$tagVersion)if(!is_string($tag)||!preg_match('/\A[a-f0-9]{32}\z/D',$tag)||!is_int($tagVersion)||$tagVersion<1)throw new DomainException('Ursprüngliche Zahlungstag-Auswahl prüfen.',422);ksort($choices,SORT_STRING);$request=self::json(['scope'=>'transaction','id'=>$id,'version'=>$version,'choices'=>$choices]);
  return$this->write($key,$request,function(array$a)use($id,$version,$choices,$key,$request):array{
   $source=$this->source($a,$id,true);if($source['source']==='reversal'&&$source['reversal_of']!==null)throw new DomainException('Tags an der ursprünglichen Zahlung bearbeiten. Die Gegenbuchung übernimmt diese Auswahl.',422);
   $this->db->prepare('INSERT INTO finance_payment_tag_states(club_id,transaction_id,version) VALUES(?,?,0) ON DUPLICATE KEY UPDATE version=version')->execute([$a['club_id'],$id]);$q=$this->db->prepare('SELECT version FROM finance_payment_tag_states WHERE club_id=? AND transaction_id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$current=(int)$q->fetchColumn();if($current!==$version)throw new DomainException('Zahlungstags inzwischen geändert. Zuerst vergleichen.',409);
   $old=$this->selected($a,$id);$oldIds=array_column($old,'id');$next=[];foreach($choices as$tag=>$tagVersion){$q=$this->db->prepare('SELECT id,name,active,version FROM finance_payment_tags WHERE club_id=? AND id=? FOR SHARE NOWAIT');$q->execute([$a['club_id'],$tag]);$row=$q->fetch();if(!$row)throw new DomainException('Gewählter Zahlungstag nicht verfügbar.',404);if((int)$row['version']!==$tagVersion)throw new DomainException('Gewählter Zahlungstag inzwischen geändert. Zuerst vergleichen.',409);if(!(int)$row['active']&&!in_array($tag,$oldIds,true))throw new DomainException('Inaktive Zahlungstags können nicht neu zugeordnet werden.',422);$next[]=$row;}
   $newIds=array_keys($choices);$compare=$oldIds;sort($compare,SORT_STRING);if($compare===$newIds)throw new DomainException('Keine geänderte Zahlungstag-Auswahl vorhanden.',422);
   usort($next,static fn(array$x,array$y):int=>[$x['name'],$x['id']]<=>[$y['name'],$y['id']]);$before=['transaction_id'=>$id,'version'=>$version,'tags'=>$old];$after=['transaction_id'=>$id,'version'=>$version+1,'tags'=>$next];
   $this->db->prepare('DELETE FROM finance_payment_tag_assignments WHERE club_id=? AND transaction_id=?')->execute([$a['club_id'],$id]);foreach($newIds as$tag)$this->db->prepare('INSERT INTO finance_payment_tag_assignments(club_id,transaction_id,tag_id) VALUES(?,?,?)')->execute([$a['club_id'],$id,$tag]);$q=$this->db->prepare('UPDATE finance_payment_tag_states SET version=version+1 WHERE club_id=? AND transaction_id=? AND version=?');$q->execute([$a['club_id'],$id,$version]);if($q->rowCount()!==1)throw new DomainException('Zahlungstags inzwischen geändert. Zuerst vergleichen.',409);return self::result($this->append($a,'transaction',$id,$version,$before,$after,$key,$request),false);
  });
 }
 public function history(string$scope,string$id):array{$a=$this->actor();$this->ready();if($scope==='transaction')$id=$this->state($id)['transaction_id'];elseif($scope==='catalogue')$this->tag($id);else throw new DomainException('Zahlungstag-Verlauf prüfen.',422);$q=$this->db->prepare('SELECT * FROM finance_payment_tag_events WHERE club_id=? AND scope=? AND object_id=? ORDER BY result_version DESC');$q->execute([$a['club_id'],$scope,$id]);return$q->fetchAll();}
 public function receipt(string$id,string$transaction):array{$a=$this->actor();$state=$this->state($transaction);self::key($id);$q=$this->db->prepare('SELECT * FROM finance_payment_tag_events WHERE club_id=? AND id=?');$q->execute([$a['club_id'],$id]);$row=$q->fetch();if(!$row||($row['scope']==='transaction'&&$row['object_id']!==$state['transaction_id']))throw new DomainException('Zahlungstag-Verlauf nicht verfügbar.',404);return$row;}
}
