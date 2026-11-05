<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDOException;

/** The first accepted native creation and its original intention commit together. */
trait DocumentCheckCreations
{
 private function creationSchema():void
 {
  if(!(bool)DocumentCatalog::query($this->db,"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='document_check_creations'")->fetchColumn())throw new DomainException('Nachweis-Anlegeschutz benötigt das Datenbankupdate im Installer.',503);
 }
 private static function creationKey(string $key):void{if(!preg_match('/\A[a-f0-9]{32}\z/D',$key))throw new DomainException('Anlegeformular erneut öffnen.');}
 public function creationReceipt(string $key):?array
 {
  self::creationKey($key);$actor=$this->authority();$this->creationSchema();$row=DocumentCatalog::query($this->db,'SELECT * FROM document_check_creations WHERE club_id=? AND actor_id=? AND request_key=?',[$actor['club_id'],$actor['id'],$key])->fetch();
  if(!$row)return null;
  if(!is_string($row['original_json'])||!is_string($row['original_sha256'])||!hash_equals($row['original_sha256'],hash('sha256',$row['original_json']))||!hash_equals($row['intent_sha256'],hash('sha256',$row['intent_json'])))throw new DomainException('Ursprünglichen Nachweis-Anlegevorgang durch den Betrieb prüfen lassen.',503);
  try{$original=json_decode($row['original_json'],true,32,JSON_THROW_ON_ERROR);$intent=json_decode($row['intent_json'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException $failure){throw new DomainException('Ursprünglichen Nachweis-Anlegevorgang durch den Betrieb prüfen lassen.',503,$failure);}
  if(!is_array($original)||!is_array($intent)||($original['id']??null)!==$row['result_id']||($original['club_id']??null)!==$actor['club_id']||($original['version']??null)!==1||($original['title']??null)!==$row['title'])throw new DomainException('Ursprünglichen Nachweis-Anlegevorgang durch den Betrieb prüfen lassen.',503);
  return ['request_key'=>$key,'id'=>$row['result_id'],'title'=>$row['title'],'created_at'=>$row['created_at'],'intent'=>$intent,'intent_sha256'=>$row['intent_sha256'],'original'=>$original,'original_sha256'=>$row['original_sha256']];
 }
 public function creationPage(mixed $requested=1):array
 {
  $page=self::pageNumber($requested);$owned=!$this->db->inTransaction();if($owned)$this->db->beginTransaction();
  try{$actor=$this->authority();$this->creationSchema();$parameters=[$actor['club_id'],$actor['id']];$total=(int)DocumentCatalog::query($this->db,'SELECT COUNT(*) FROM document_check_creations WHERE club_id=? AND actor_id=?',$parameters)->fetchColumn();$window=Pagination::window($total,$page,25);$rows=DocumentCatalog::query($this->db,'SELECT request_key,result_id,title,created_at FROM document_check_creations WHERE club_id=? AND actor_id=? ORDER BY created_at DESC,request_key DESC LIMIT 25 OFFSET '.$window['offset'],$parameters)->fetchAll();unset($window['offset']);if($owned)$this->db->commit();return ['rows'=>$rows]+$window;}catch(\Throwable $failure){if($owned&&$this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
 public function createOnce(array $data,string $key,?array $file=null):string
 {
  self::creationKey($key);$this->authority();$this->creationSchema();if($this->db->inTransaction())throw new DomainException('Nachweis-Anlegevorgang benötigt eine eigene Transaktion.');
  $input=[];foreach(self::defaults()as$name=>$default){if(in_array($name,['id','version'],true))continue;$value=$data[$name]??$default;if($name==='reminder_days'&&is_int($value))$value=(string)$value;if(!is_string($value)||preg_match('//u',$value)!==1)throw new DomainException('Nachweisangaben prüfen.');$input[$name]=$value;}ksort($input,SORT_STRING);
  $fileIntent=null;if($file!==null){if(!is_string($file['name']??null)||!is_string($file['bytes']??null))throw new DomainException('Eigene vollständig übertragene Datei auswählen.');[$name,$mime,$sha]=Documents::inspectFile($file['name'],$file['bytes']);$file['name']=$name;$fileIntent=['name'=>$name,'mime'=>$mime,'sha256'=>$sha,'size'=>strlen($file['bytes'])];}
  $json=json_encode(['data'=>$input,'file'=>$fileIntent],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$hash=hash('sha256',$json);
  $this->db->beginTransaction();
  try{
   $actor=$this->authority();$parameters=[$actor['club_id'],$actor['id'],$key];
   $insert=DocumentCatalog::query($this->db,'INSERT IGNORE INTO document_check_creations(club_id,actor_id,request_key,intent_json,intent_sha256,created_at) VALUES(?,?,?,?,?,?)',[...$parameters,$json,$hash,gmdate('c')]);$new=$insert->rowCount()===1;
   $row=DocumentCatalog::query($this->db,'SELECT * FROM document_check_creations WHERE club_id=? AND actor_id=? AND request_key=? FOR UPDATE',$parameters)->fetch();
   if(!$row)throw new DomainException('Nachweis-Anlegevorgang konnte nicht bestätigt werden. Anlegevorgänge prüfen.',503);
   if(!hash_equals($row['intent_sha256'],hash('sha256',$row['intent_json'])))throw new DomainException('Ursprünglichen Nachweis-Anlegevorgang durch den Betrieb prüfen lassen.',503);
   if(!hash_equals($row['intent_sha256'],$hash))throw new DomainException('Dieses Nachweisformular wurde bereits mit anderen Angaben gespeichert. Anlegevorgang prüfen.',409);
   if($row['result_id']!==null){$receipt=$this->creationReceipt($key);$this->db->commit();return $receipt['id'];}
   if(!$new)throw new DomainException('Ursprünglichen Nachweis-Anlegevorgang durch den Betrieb prüfen lassen.',503);
   $id=$file===null?$this->save('',0,$input):$this->saveWithUpload('',0,$input,$file['name'],$file['bytes'],$key);
   $original=$this->get($id);$originalJson=json_encode($original,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
   DocumentCatalog::query($this->db,'UPDATE document_check_creations SET result_id=?,title=?,original_json=?,original_sha256=? WHERE club_id=? AND actor_id=? AND request_key=?',[$id,$original['title'],$originalJson,hash('sha256',$originalJson),...$parameters]);
   $this->db->commit();return $id;
  }catch(PDOException $failure){
   if($this->db->inTransaction())try{$this->db->rollBack();}catch(\Throwable){}
   try{$receipt=$this->creationReceipt($key);if($receipt!==null&&hash_equals($receipt['intent_sha256'],$hash))return $receipt['id'];}catch(\Throwable){}
   throw new DomainException('Nachweis-Anlegevorgang konnte nicht bestätigt werden. Anlegevorgänge prüfen.',503,$failure);
  }catch(\Throwable $failure){if($this->db->inTransaction())$this->db->rollBack();throw $failure;}
 }
}
