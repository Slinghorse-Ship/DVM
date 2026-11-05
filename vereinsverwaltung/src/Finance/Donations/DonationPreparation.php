<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
/** Best-effort post-commit draft creation; a rendering failure never undoes a recorded donation. */
final class DonationPreparation
{
 private static function fingerprint(PDO $db,string $actor,string $type,string $id):string
 {
  $f=new Finance($db,$actor);$source=$type==='goods'?$f->goodsEntry($id):$f->entry($id);$contact=$f->donationContact($type,$id);$q=$db->prepare("SELECT section,payload FROM club_settings WHERE club_id=? AND section IN ('club','tax','paper') ORDER BY section");$q->execute([$source['club_id']]);
  return hash('sha256',json_encode([$source,$contact,$type==='entry'?$f->allocations($id):[],$q->fetchAll(PDO::FETCH_ASSOC)],JSON_THROW_ON_ERROR));
 }
 public static function afterSource(PDO $db,string $actor,string $type,string $id):void
 {
  // A caller must invoke this after committing its domain operation.
  if($db->inTransaction())return;
  try{$f=new Finance($db,$actor);if(!$f->canWrite())return;$source=$type==='goods'?$f->goodsEntry($id):$f->entry($id);if($type!=='goods'&&($type!=='entry'||$source['kind']!=='donation'))return;
   $fingerprint=self::fingerprint($db,$actor,$type,$id);
   try{if($type==='entry'&&in_array($source['payment_state'],['cancelled','reversed'],true))$result=['state'=>'blocked','documents'=>[],'reason'=>'Diese Spende wurde verworfen oder gegengebucht. Kein aktueller Bescheinigungsentwurf.'];else $result=(new DonationForms($db,$actor))->prepareForSource($type==='goods'?'goods':'money',$id);}catch(\Throwable){$result=['state'=>'failed','documents'=>[],'reason'=>'Spende gespeichert. Der Bescheinigungsentwurf konnte noch nicht erstellt werden. Bitte im Formularbereich prüfen und erneut vorbereiten.'];}
   $state=(string)$result['state'];$reason=(string)$result['reason'];$documents=json_encode($result['documents'],JSON_THROW_ON_ERROR);$now=gmdate('c');
   $db->beginTransaction();try{if($fingerprint!==self::fingerprint($db,$actor,$type,$id)){$state='stale';$reason='Spendenangaben wurden geändert. Bitte Entwurf erneut vorbereiten.';$documents='[]';}$old=$db->prepare('SELECT state,reason,documents,source_fingerprint FROM donation_preparation_status WHERE club_id=? AND source_type=? AND source_id=?');$old->execute([$source['club_id'],$type,$id]);$previous=$old->fetch(PDO::FETCH_ASSOC);
    $db->prepare('INSERT INTO donation_preparation_status(club_id,source_type,source_id,state,reason,documents,updated_at,source_fingerprint) VALUES(?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE source_fingerprint=VALUES(source_fingerprint),state=VALUES(state),reason=VALUES(reason),documents=VALUES(documents),updated_at=VALUES(updated_at)')->execute([$source['club_id'],$type,$id,$state,$reason,$documents,$now,$fingerprint]);
    if($previous!==['state'=>$state,'reason'=>$reason,'documents'=>$documents,'source_fingerprint'=>$fingerprint])$db->prepare('INSERT INTO audit_events(club_id,actor_id,action,object_id,object_version,happened_at) VALUES(?,?,?,?,?,?)')->execute([$source['club_id'],$actor,'donation.preparation.'.$state,$type.':'.$id,(int)$source['version'],$now]);$db->commit();
   }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
  }catch(\Throwable){/* The financial mutation has committed. Never report it as rolled back. */}
 }
 public static function afterTransaction(PDO $db,string $actor,string $id):void
 {
  try{$f=new Finance($db,$actor);if(!$f->canWrite())return;$f->transactionDetail($id);foreach($f->allocations($id,true) as $allocation)if(empty($allocation['voided_at']))self::afterSource($db,$actor,'entry',$allocation['entry_id']);}catch(\Throwable){}
 }
 public static function status(PDO $db,string $actor,string $type,string $id):?array
 {
  $f=new Finance($db,$actor);$source=$type==='goods'?$f->goodsEntry($id):$f->entry($id);if(!in_array($type,['entry','goods'],true)||($type==='entry'&&$source['kind']!=='donation'))return null;
  $q=$db->prepare('SELECT * FROM donation_preparation_status WHERE club_id=? AND source_type=? AND source_id=?');$q->execute([$source['club_id'],$type,$id]);$r=$q->fetch(PDO::FETCH_ASSOC);if($r){$r['documents']=json_decode($r['documents'],true,32,JSON_THROW_ON_ERROR);$stale=!hash_equals((string)$r['source_fingerprint'],self::fingerprint($db,$actor,$type,$id));
   if(!$stale&&$r['state']==='ready')foreach($r['documents'] as $doc){try{if((new Documents($db,$actor))->document($doc)['state']!=='active')$stale=true;}catch(\DomainException){$stale=true;}}
   if($stale){$r['state']='stale';$r['reason']='Spender-, Zahlungs- oder Vereinsangaben wurden geändert. Bitte Entwurf erneut vorbereiten; bisherige Entwürfe sind kein aktueller Nachweis.';$r['documents']=[];}
  }return $r?:null;
 }
}
