<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
final class MemberBankSettings
{
 public function __construct(private PDO $db,private string $actor){}
 private function actor():array{if(!BoardAccess::superuser($this->db,$this->actor))throw new DomainException('SEPA-Einrichtung ist nur für Superuser freigegeben.',403);return Permissions::actor($this->db,$this->actor);}
 private function q(string $sql,array $args=[]):\PDOStatement{$q=$this->db->prepare($sql);$q->execute($args);return $q;}
 public function get():array
 {
  $own=!$this->db->inTransaction();try{if($own)$this->db->exec('START TRANSACTION READ ONLY');$state=$this->state(false);if($own)$this->db->commit();return $state;}catch(\Throwable $failure){if($own&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 private function state(bool $lock):array
 {
  $a=$this->actor();$suffix=$lock?' FOR UPDATE NOWAIT':'';
  $row=$this->q('SELECT * FROM member_bank_config WHERE club_id=?'.$suffix,[$a['club_id']])->fetch()?:['sepa_enabled'=>0,'bank_online_mandate_accepted'=>0,'creditor_id'=>'','creditor_name'=>'','creditor_address'=>''];
  $row['club_id']=$a['club_id'];$sql=$lock?"SELECT object_version FROM audit_events WHERE club_id=? AND object_id=? AND action='member-bank.configured' ORDER BY object_version DESC,sequence DESC LIMIT 1 FOR UPDATE NOWAIT":"SELECT COALESCE(MAX(object_version),0) FROM audit_events WHERE club_id=? AND object_id=? AND action='member-bank.configured'";
  $row['version']=(int)$this->q($sql,[$a['club_id'],'member-bank-config:'.$a['club_id']])->fetchColumn();return $row;
 }
 public static function fingerprint(array $state):string
 {
  $fields=[];foreach(['club_id','version','sepa_enabled','bank_online_mandate_accepted','creditor_id','creditor_name','creditor_address']as$key)$fields[$key]=in_array($key,['version','sepa_enabled','bank_online_mandate_accepted'],true)?(int)($state[$key]??0):(string)($state[$key]??'');return hash('sha256',json_encode($fields,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
 }
 public function save(int $version,array $data):array
 {
  $this->actor();if($version<0)throw new DomainException('Formular prüfen.',422);if(isset($data['sepa_enabled'])&&(!is_string($data['sepa_enabled'])||!in_array($data['sepa_enabled'],['','1'],true)))throw new DomainException('Freischaltung des SEPA-Mandats prüfen.',422);$values=[];foreach(['creditor_id'=>35,'creditor_name'=>200,'creditor_address'=>500] as $field=>$max){$v=$data[$field]??'';if(!is_string($v)||preg_match('//u',$v)!==1||mb_strlen(trim($v))>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new DomainException('Gläubigerangaben prüfen.');$values[$field]=trim($v);}
  $values['creditor_id']=strtoupper(str_replace(' ','',$values['creditor_id']));
  if($values['creditor_id']!==''&&!preg_match('/^DE[0-9]{2}[A-Z0-9]{3}[0-9]{11}$/D',$values['creditor_id']))throw new DomainException('Deutsche Gläubiger-ID mit 18 Zeichen eingeben.');
  if($values['creditor_id']!==''){$numeric=substr($values['creditor_id'],7).'1314'.substr($values['creditor_id'],2,2);$mod=0;foreach(str_split($numeric) as $digit)$mod=($mod*10+(int)$digit)%97;if($mod!==1)throw new DomainException('Prüfziffer der Gläubiger-ID stimmt nicht.');}
  $enabled=($data['sepa_enabled']??'')==='1';if($enabled&&in_array('',$values,true))throw new DomainException('Vor Freischaltung alle Gläubigerangaben vervollständigen.');
  $this->db->beginTransaction();try{$a=$this->actor();if(!$this->q('SELECT id FROM clubs WHERE id=? FOR UPDATE NOWAIT',[$a['club_id']])->fetchColumn())throw new DomainException('Eigenen aktiven Verein auswählen.',403);$current=$this->state(true);if($current['version']!==$version)throw new DomainException('SEPA-Einstellungen inzwischen geändert. Bitte neu öffnen.',409);if(array_key_exists('member_bank_settings_fingerprint',$data)&&(!is_string($data['member_bank_settings_fingerprint'])||!hash_equals(self::fingerprint($current),$data['member_bank_settings_fingerprint'])))throw new DomainException('Geprüfte SEPA-Einstellungen passen nicht zum aktuellen Verein und Originalstand.',409);$this->q('INSERT INTO member_bank_config(club_id,sepa_enabled,creditor_id,creditor_name,creditor_address) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE sepa_enabled=VALUES(sepa_enabled),creditor_id=VALUES(creditor_id),creditor_name=VALUES(creditor_name),creditor_address=VALUES(creditor_address)',[$a['club_id'],(int)$enabled,$values['creditor_id'],$values['creditor_name'],$values['creditor_address']]);$this->q('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)',[$a['club_id'],$a['id'],'member-bank.configured','member-bank-config:'.$a['club_id'],$version+1,gmdate('c')]);$this->db->commit();}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof \PDOException&&in_array((int)($e->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('SEPA-Einstellungen werden gerade bearbeitet. Aktuellen Stand erneut prüfen.',409,$e);throw $e;}return $this->get();
 }
}
