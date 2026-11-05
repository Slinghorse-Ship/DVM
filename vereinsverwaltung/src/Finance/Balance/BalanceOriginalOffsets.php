<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Exact original inventory and material offsets retain their original signed journal. */
final class BalanceOriginalOffsets {
 private const TARGET='bs.ass.currAss.inventory.advPaymReceived';
 private static function rules(int $year):array {
  require_once __DIR__.'/BalanceTaxMapping.php';BalanceTaxMapping::preview($year,[]);
  $raw=file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json');$key=$year.':'.hash('sha256',$raw);static $cache=[];
  if(isset($cache[$key]))return $cache[$key];$out=[];$registry=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
  foreach($registry['rules'] as $r)if(!isset($r['years'])||in_array($year,$r['years'],true))foreach($r['codes'] as $code){
   if(isset($r['original_offset_contract'])){
    $expected=$code==='11900'?['inventory',[self::TARGET]]:($code==='58600'?['expense',self::material()]:null);
    if(!$expected||$r['codes']!==[$code]||$r['original_offset_contract']!==$code.'@1'||$r['group']!==$expected[0]||($r['condition']??null)!=='detail_choice'||($r['allowed_targets']??null)!==$expected[1]||!in_array($r['target'],$expected[1],true)||!empty($r['disclosures'])||isset($r['book_only']))throw new DomainException('Originales Gegenkonto und geprüfte Jahresregel wurden verändert.');
   }$out[$code]=$r;
  }
  if(count($cache)>=4)array_shift($cache);return $cache[$key]=$out;
 }
 public static function material():array {
  $base='is.netIncome.regular.operatingTC.grossTradingProfit.materialServices.material.';$out=[];
  foreach(['reducedRateVAT','generalRateVAT','noDeductVAT','intraEU','misc','unknownVAT'] as $s)$out[]=$base.'rawMatConsSup.'.$s;
  foreach(['reducedRateVAT','generalRateVAT','withoutVAT','intraEU','misc','unknownVAT'] as $s)$out[]=$base.'purchased.'.$s;
  $out[]=$base.'misc';return $out;
 }
 public static function isInventoryContra(int $year,array $row):bool {
  if(($row['skr_code']??'')!=='11900'||!in_array($year,[2025,2026],true))return false;
  return (self::rules($year)['11900']['original_offset_contract']??null)==='11900@1'&&$row['group']==='inventory'&&$row['opening_cents']<=0&&$row['closing_cents']<=0;
 }
 public static function issues(int $year,array $rows):array {
  $selected=array_filter($rows,static fn($r)=>in_array($r['skr_code'],['11900','58600'],true));if(!$selected)return [];$rules=self::rules($year);$errors=[];
  foreach($selected as $row){
   if(($rules[$row['skr_code']]['original_offset_contract']??null)!==$row['skr_code'].'@1')throw new DomainException('Originale Jahresregel des Gegenkontos fehlt.');
   if($row['skr_code']==='11900'&&!self::isInventoryContra($year,$row))$errors[]='Erhaltene Anzahlungen: Unveränderten Habensaldo und offenen Vorratsabzug prüfen.';
   if($row['skr_code']==='58600'&&($row['group']!=='expense'||$row['opening_cents']!==0||$row['closing_cents']>0))$errors[]='Verrechnete Stoffkosten: Negativen Aufwand ohne Eröffnungsbestand anhand der Grundkosten belegen.';
  }
  if(array_filter($selected,static fn($r)=>$r['skr_code']==='11900'))foreach(['opening_cents','closing_cents'] as $field){
   $net=0;foreach($rows as $row){if($row['skr_code']==='11900'){$net+=$row[$field];continue;}$rule=$rules[$row['skr_code']]??[];$targets=$rule['allowed_targets']??[$rule['target']??''];
    if($row['group']==='inventory'&&($rule['group']??null)==='inventory'&&$targets&&!array_filter($targets,static fn($t)=>!str_starts_with($t,'bs.ass.currAss.inventory.')||$t==='bs.ass.currAss.inventory.advPaymPaid'))$net+=$row[$field];
   }if($net<0||$net>BalanceCalculation::LIMIT)$errors[]='Erhaltene Anzahlungen überschreiten die tatsächlich vorhandenen zugehörigen Vorräte. Gesonderten Verbindlichkeitsausweis prüfen.';
  }
  if(array_filter($selected,static fn($r)=>$r['skr_code']==='58600')){
   $net=0;foreach($rows as $row)if($row['group']==='expense'&&(($row['skr_code']>='50000'&&$row['skr_code']<='50999')||$row['skr_code']==='58600'))$net+=$row['closing_cents'];
   if($net<0||$net>BalanceCalculation::LIMIT)$errors[]='Verrechnete Stoffkosten überschreiten die tatsächlichen Grundkosten auf 50000–50999.';
  }return array_values(array_unique($errors));
 }
}
