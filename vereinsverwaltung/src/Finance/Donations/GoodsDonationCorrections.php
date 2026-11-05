<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** A correction retains its actual previous source and complete positions. */
trait GoodsDonationCorrections
{
 private static function goodsCorrectionJson(mixed $value):string
 {
  $sort=static function(mixed $item)use(&$sort):mixed{if(!is_array($item))return $item;if(!array_is_list($item))ksort($item,SORT_STRING);foreach($item as$key=>$child)$item[$key]=$sort($child);return $item;};
  return json_encode($sort($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION);
 }
 private function goodsCorrectionKnown(array $actor,string $key,string $request):?array
 {
  $q=$this->db->prepare('SELECT * FROM goods_donation_corrections WHERE club_id=? AND request_key=?');$q->execute([$actor['club_id'],$key]);$row=$q->fetch();if(!$row)return null;
  if($row['actor_id']!==$actor['id']||$row['request_sha256']!==hash('sha256',$request)||$row['request_json']!==$request)throw new DomainException('Vorgangskennung gehört zu einer anderen Sachspendenkorrektur.',409);
  return $row;
 }
 private function goodsCorrectionPayload(string $id,int $version,array $data,string $reason):array
 {
  if($version<1)throw new DomainException('Ursprüngliche Sachspendenfassung prüfen.',422);
  if(!mb_check_encoding($reason,'UTF-8')||trim($reason)===''||mb_strlen($reason,'UTF-8')>500)throw new DomainException('Bitte einen Änderungsgrund angeben (höchstens 500 Zeichen).',422);
  $fields=['donor','designation','description','received_on','valuation','origin','evidence_present','positions'];
  if(array_diff(array_keys($data),$fields))throw new DomainException('Ursprüngliche Sachspendenangaben prüfen.',422);
  foreach(['donor','designation','description','received_on','valuation','origin']as$field)if(!array_key_exists($field,$data)||(!is_string($data[$field])||!mb_check_encoding($data[$field],'UTF-8')))throw new DomainException('Ursprüngliche Sachspendenangaben prüfen.',422);
  if(!is_array($data['positions']??null)||!array_is_list($data['positions']))throw new DomainException('Ursprüngliche Sachspendenpositionen prüfen.',422);
  foreach($data['positions']as$position){
   if(!is_array($position)||array_diff(array_keys($position),['description','amount','tax_rate','sphere','account','booking_key','input_tax']))throw new DomainException('Ursprüngliche Sachspendenpositionen prüfen.',422);
   foreach($position as$value)if(!is_string($value)||!mb_check_encoding($value,'UTF-8'))throw new DomainException('Ursprüngliche Sachspendenpositionen prüfen.',422);
  }
  if(!in_array($data['evidence_present']??null,['0','1',0,1],true))throw new DomainException('Ursprünglichen Unterlagenvermerk prüfen.',422);
  return ['source_id'=>$id,'source_version'=>$version,'data'=>$data,'reason'=>$reason];
 }
 private function goodsCorrectionPreviewLocked(string $id,int $version,array $data,string $reason,bool $write=false):array
 {
  $a=$this->actor(true);
  if($write){$q=$this->db->prepare('SELECT * FROM goods_donations WHERE club_id=? AND id=? FOR UPDATE NOWAIT');$q->execute([$a['club_id'],$id]);$source=$q->fetch();if(!$source)throw new DomainException('Sachspende nicht verfügbar.',404);$old=$this->positions('goods',$id,true);}
  else{$current=$this->readCurrentPositionState('goods',$id);$source=$current['source'];$old=$current['positions'];}
  if((int)$source['version']!==$version)throw new DomainException('Sachspende inzwischen geändert. Bitte erneut vergleichen.',409);
  $claim=$this->db->prepare("SELECT 1 FROM donation_receipt_claims WHERE club_id=? AND source_type='goods' AND source_id=? FOR SHARE NOWAIT");$claim->execute([$a['club_id'],$id]);
  if($claim->fetchColumn())throw new DomainException('Zuerst die zugehörige Zuwendungsbescheinigung begründet stornieren.',409);
  $date=self::field($data,'received_on',10,true);self::date($date);if($date>$this->businessDay())throw new DomainException('Wert oder Eingangsdatum prüfen.',422);
  if(!in_array($data['origin'],['private','business','unknown'],true))throw new DomainException('Ursprung der Zuwendung prüfen.',422);
  $rows=$this->normalizePositions($data['positions'],'goods',$date,$old);$amount=(int)array_sum(array_column($rows,'gross_cents'));if($amount<1)throw new DomainException('Wert oder Eingangsdatum prüfen.',422);
  $next=$source;foreach(['donor'=>200,'designation'=>200,'description'=>2000,'valuation'=>2000]as$field=>$max)$next[$field]=self::field($data,$field,$max,$field!=='designation');
  $next['received_on']=$date;$next['origin']=$data['origin'];$next['evidence_present']=(int)$data['evidence_present'];$next['value_cents']=$amount;$next['version']=$version+1;
  $equal=$source;$equal['version']=$version+1;
  if(self::goodsCorrectionJson($next)===self::goodsCorrectionJson($equal)&&self::goodsCorrectionJson($old)===self::goodsCorrectionJson($rows))throw new DomainException('Keine geänderten Sachspendenangaben vorhanden.',422);
  $snapshot=['source'=>$source,'positions'=>$old,'next_source'=>$next,'next_positions'=>$rows,'request'=>$this->goodsCorrectionPayload($id,$version,$data,$reason)];
  return $snapshot+['hash'=>hash('sha256',self::goodsCorrectionJson($snapshot))];
 }
 public function reviewGoodsCorrection(string $id,int $version,array $data,string $reason):array
 {
  $this->goodsCorrectionPayload($id,$version,$data,$reason);
  return $this->positionRead(fn()=>$this->goodsCorrectionPreviewLocked($id,$version,$data,$reason));
 }
 public function correctGoods(string $id,int $version,array $data,string $reason,string $reviewHash,string $key):array
 {
  $a=$this->actor(true);if($this->db->inTransaction())throw new DomainException('Sachspendenkorrektur benötigt einen eigenen Speichervorgang.',409);
  if(!preg_match('/\A[a-f0-9]{32}\z/D',$key)||!preg_match('/\A[a-f0-9]{64}\z/D',$reviewHash))throw new DomainException('Sachspendenkorrektur erneut prüfen.',422);
  $request=self::goodsCorrectionJson($this->goodsCorrectionPayload($id,$version,$data,$reason)+['review_hash'=>$reviewHash]);if($known=$this->goodsCorrectionKnown($a,$key,$request))return ['replayed'=>true,'correction'=>$known];
  try{$result=$this->transaction(function()use($id,$version,$data,$reason,$reviewHash,$key,$request){
   $a=$this->actor(true);if($known=$this->goodsCorrectionKnown($a,$key,$request))return ['replayed'=>true,'correction'=>$known];
   $preview=$this->goodsCorrectionPreviewLocked($id,$version,$data,$reason,true);if(!hash_equals($preview['hash'],$reviewHash))throw new DomainException('Sachspende oder Korrektur seit der Prüfung geändert. Erneut vergleichen.',409);
   $next=$preview['next_source'];$fields=['donor','designation','description','received_on','valuation','origin','evidence_present','value_cents'];$assign=implode(',',array_map(static fn(string $field):string=>$field.'=?',$fields));$values=array_map(static fn(string $field):mixed=>$next[$field],$fields);
   $q=$this->db->prepare('UPDATE goods_donations SET '.$assign.',version=version+1 WHERE club_id=? AND id=? AND version=?');$q->execute([...$values,$a['club_id'],$id,$version]);if($q->rowCount()!==1)throw new DomainException('Sachspende inzwischen geändert. Bitte erneut vergleichen.',409);
   $this->db->prepare('DELETE FROM accounting_positions WHERE club_id=? AND goods_id=?')->execute([$a['club_id'],$id]);$this->insertPositions($a,'goods',$id,$preview['next_positions']);
   $correction=bin2hex(random_bytes(16));$at=gmdate('Y-m-d\TH:i:s\Z');$this->db->prepare('INSERT INTO goods_donation_corrections(club_id,id,goods_id,source_version,result_version,request_key,actor_id,happened_at,reason,request_json,request_sha256,review_sha256,previous_source,next_source,previous_positions,next_positions) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],$correction,$id,$version,$version+1,$key,$a['id'],$at,trim($reason),$request,hash('sha256',$request),$reviewHash,self::goodsCorrectionJson($preview['source']),self::goodsCorrectionJson($next),self::goodsCorrectionJson($preview['positions']),self::goodsCorrectionJson($preview['next_positions'])]);
   $this->db->prepare('INSERT INTO accounting_revisions(club_id,object_type,object_id,version,previous_positions,next_positions,reason,actor_id,happened_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$a['club_id'],'goods',$id,$version+1,self::goodsCorrectionJson($preview['positions']),self::goodsCorrectionJson($preview['next_positions']),trim($reason),$a['id'],$at]);$this->audit($a,'donation.goods.corrected',$id,$version+1);
   return ['replayed'=>false,'correction'=>$this->goodsCorrectionKnown($a,$key,$request)];
  });}catch(\PDOException $failure){if((int)($failure->errorInfo[1]??0)===1062&&($known=$this->goodsCorrectionKnown($a,$key,$request)))return ['replayed'=>true,'correction'=>$known];if(in_array((int)($failure->errorInfo[1]??0),[1205,1213,3572],true))throw new DomainException('Sachspende wird gerade bearbeitet. Bitte erneut prüfen.',503,$failure);throw $failure;}
  if(!$result['replayed'])DonationPreparation::afterSource($this->db,$this->actorId,'goods',$id);return $result;
 }
 public function goodsCorrectionHistory(string $id):array
 {
  $a=$this->actor();$this->goodsEntry($id);$q=$this->db->prepare('SELECT * FROM goods_donation_corrections WHERE club_id=? AND goods_id=? ORDER BY result_version DESC');$q->execute([$a['club_id'],$id]);return $q->fetchAll();
 }
}
