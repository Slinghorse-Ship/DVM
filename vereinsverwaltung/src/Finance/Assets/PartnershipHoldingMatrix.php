<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/PartnershipHoldingJournals.php';
/** Original fiscal matrix; no gross movement inferred from assessed net capital. */
final class PartnershipHoldingMatrix
{
 private static function require(bool $ok,string $message='Steuerlichen Original-Anlagenspiegel der Beteiligung vollständig nachweisen.'):void{if(!$ok)throw new DomainException($message);}
 private static function keys(mixed $v,array $keys):bool{return is_array($v)&&count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
 public static function reconcile(array $journalProof,array $source,?array $previous=null,int $depth=0):array
 {
  self::require($depth>=0&&$depth<=100&&PartnershipHoldingJournals::verify($journalProof['sources'],$journalProof['journal'],$journalProof['previous']??null)===$journalProof);
  self::require(self::keys($source,['schema','club_id','holding_id','period','taxonomy','reporting','columns','document','reason','legal_basis','confirmed'])&&$source['schema']===1&&$source['confirmed']===true&&$source['taxonomy']==='6.9'&&$source['reporting']==='original_fiscal_gross_matrix');
  $j=$journalProof['journal'];$s=$journalProof['sources'];$capital=$s['capital_basis'];
  foreach(['club_id','holding_id','period'] as $key)self::require($source[$key]===$j[$key]);
  $d=$source['document'];self::require(self::keys($d,['document_id','revision','sha256'])&&is_string($d['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$d['document_id'])&&is_int($d['revision'])&&$d['revision']>=1&&is_string($d['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$d['sha256']));
  foreach(['reason','legal_basis'] as $key)self::require(is_string($source[$key])&&trim($source[$key])!==''&&mb_strlen($source[$key])<=2000&&!preg_match('/[\x00-\x1f]/',$source[$key]));
  $meta=json_decode(file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/taxonomy-6.9.json'),true,512,JSON_THROW_ON_ERROR);$network=$meta['asset_calculations'];$keys=['all_Prev_period'];
  foreach($network as $parent=>$children){$keys[]=$parent;foreach($children as $child=>$weight)$keys[]=$child;}$keys=array_values(array_unique($keys));$columns=$source['columns'];
  self::require(self::keys($columns,$keys),'Steuerlichen Original-Anlagenspiegel einschließlich unbekannter Spalten vollständig nachweisen; keine Bruttowerte aus dem Nettowert ableiten.');
  foreach($columns as $n)self::require($n===null||(is_int($n)&&abs($n)<=100000000000),'Ursprüngliche Spiegelspalte muss Centwert oder ausdrücklich unbekannt sein; Null ist kein Ersatz für unbekannt.');
  self::require($columns['all']===$capital['closing_cents']&&$columns['all_Prev_period']===$capital['opening_cents'],'Steuerlicher Original-Anlagenspiegel und festgestellter Spiegelbildwert stimmen nicht überein.');
  foreach($network as $parent=>$children){$known=false;$sum=0;foreach($children as $child=>$weight)if($columns[$child]!==null){$known=true;$sum+=$columns[$child]*$weight;}
   if($known)self::require(is_int($columns[$parent])&&$columns[$parent]===$sum,'Ursprüngliche steuerliche Bewegungsgleichung nicht erfüllt: '.$parent);
   else self::require($columns[$parent]===null,'Werthaltige Summenmussfeldspalte benötigt einen ursprünglichen Unterwert: '.$parent);
  }
  $carry=($capital['previous']??null)===null?'initial_original_matrix':'unbound_previous_matrix';
  if($previous!==null){self::require(self::reconcile($previous['journal_proof'],$previous['source'],$previous['previous']??null,$depth+1)===$previous);
   self::require(($journalProof['previous']??null)===$previous['journal_proof'],'Vorherigen steuerlichen Original-Anlagenspiegel mit dem vollständigen ursprünglichen Vorjahresjournal verbinden.');
   self::require($columns['all_Prev_period']===$previous['source']['columns']['all']);$carry='verified_original_matrix_journal';
  }
  $out=['schema'=>1,'journal_proof'=>$journalProof,'source'=>$source,'previous'=>$previous,'carry'=>$carry,'target'=>$s['tax_target'],'columns'=>$columns,'export_authorized'=>false];
  return $out+['sha256'=>hash('sha256',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
 }
}
