<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Original debtor/creditor counters are retained, never fabricated as zero. */
final class BalanceOriginalReclassification {
 public static function contracts():array {
  $t='bs.ass.currAss.receiv.trade.';$l='bs.eqLiab.liab.';
  return [
   '12580'=>['receivables','12000',[$t.'other',$t.'shareholders',$t.'misc'],[['13000','14969']]],
   '12590'=>['receivables','12000',[$t.'other',$t.'shareholders',$t.'misc'],[['12210','12299'],['12400','12459'],['12500','12579'],['12700','12759'],['12900','12979']]],
   '32490'=>['liabilities','32100',[$l.'bank.other'],[['31500','31839']]],
   '33490'=>['liabilities','33000',[$l.'trade.genOther',$l.'trade.shareholders',$l.'trade.misc'],[['33310','33399'],['33400','33439'],['34200','34239'],['34700','34739']]],
   '35990'=>['liabilities','35700',[$l.'other.other'],[['35000','35699'],['36400','36539']]],
  ];
 }
 private static function rules(int $year):array {
  require_once __DIR__.'/BalanceTaxMapping.php';BalanceTaxMapping::preview($year,[]);$raw=file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json');$key=$year.':'.hash('sha256',$raw);static $cache=[];if(isset($cache[$key]))return $cache[$key];$out=[];$contracts=self::contracts();
  foreach(json_decode($raw,true,64,JSON_THROW_ON_ERROR)['rules'] as $r)if(!isset($r['years'])||in_array($year,$r['years'],true))foreach($r['codes'] as $code){
   if(isset($r['original_reclassification_contract'])){
    $e=$contracts[$code]??null;
    if(!$e||$r['codes']!==[(string)$code]||$r['original_reclassification_contract']!==$code.'@1'||$r['group']!==$e[0]||($r['condition']??null)!=='detail_choice'||($r['allowed_targets']??null)!==$e[2]||!in_array($r['target'],$e[2],true)||!empty($r['disclosures'])||isset($r['book_only']))throw new DomainException('Originalregel des Aufteilungsgegenkontos prüfen.');
   }$out[$code]=$r;
  }if(count($cache)>=4)array_shift($cache);return $cache[$key]=$out;
 }
 private static function amount(array $r,string $f):int {return $r[$f]*($r['group']==='receivables'?1:-1);}
 public static function isContra(int $year,array $r):bool {
  $c=$r['skr_code']??'';$e=self::contracts()[$c]??null;if(!$e||!in_array($year,[2025,2026],true))return false;
  return (self::rules($year)[$c]['original_reclassification_contract']??null)===$c.'@1'&&$r['group']===$e[0]&&self::amount($r,'opening_cents')<=0&&self::amount($r,'closing_cents')<=0;
 }
 private static function split(string $c,array $r,array $source):bool {
  [$g,$primary,$targets,$ranges]=self::contracts()[$c];$code=$r['skr_code'];$rule=$source[$code]??[];$in=false;foreach($ranges as [$lo,$hi])if($code>=$lo&&$code<=$hi)$in=true;
  if(!$in||isset(self::contracts()[$code])||($rule['group']??null)!==$r['group'])return false;
  if($c==='12580'){if($r['group']!=='other_assets')return false;foreach($rule['allowed_targets']??[$rule['target']??''] as $t)if(str_starts_with($t,'bs.ass.currAss.receiv.other.'))return true;return false;}
  return $r['group']===$g;
 }
 public static function issues(int $year,array $rows):array {
  $selected=array_filter($rows,static fn($r)=>isset(self::contracts()[$r['skr_code']]));if(!$selected)return [];$source=self::rules($year);$errors=[];
  foreach($selected as $r){$c=$r['skr_code'];[$g,$primary]=self::contracts()[$c];if(!self::isContra($year,$r))$errors[]='Aufteilungsgegenkonto: Originales Vorzeichen und Jahresregel prüfen.';
   foreach(['opening_cents','closing_cents'] as $f){$gross=0;$contra=0;$parts=0;$hasPrimary=false;$hasParts=false;
    foreach($rows as $a){if($a['skr_code']===$c)$contra+=self::amount($a,$f);if($a['skr_code']===$primary&&$a['group']===$g){$gross+=self::amount($a,$f);$hasPrimary=true;}if(self::split($c,$a,$source)){$parts+=$a[$f]*(in_array($a['group'],['receivables','other_assets'],true)?1:-1);$hasParts=true;}}
    if($gross+$contra<0||$gross+$contra>BalanceCalculation::LIMIT||$parts+$contra<0||$parts+$contra>BalanceCalculation::LIMIT||($contra&&(!$hasPrimary||!$hasParts)))$errors[]='Aufteilungsgegenkonto: Tatsächliches Grundkonto und aufgegliederte Originalbestände decken den Gegenbetrag nicht.';
   }
  }return array_values(array_unique($errors));
 }
}
