<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Original signed journal is retained; only the displayed balance side changes. */
final class BalanceDirectionalClosing
{
 private const ASS='bs.ass.currAss.receiv.other.';
 private const LIAB='bs.eqLiab.liab.other.';
 private const BANK='bs.ass.currAss.cashEquiv.bank';
 public const CONTRACTS=[
  '14970'=>['other_assets',['bs.ass.currAss.receiv.other.other'],['bs.eqLiab.liab.advPaym']],
  '36950'=>['liabilities',['bs.ass.currAss.inventory.advPaymPaid','bs.ass.currAss.receiv.other.other'],['bs.eqLiab.liab.trade.genOther']],
  '13700'=>['other_assets',[self::ASS.'other'],[self::LIAB.'other']],
  '13720'=>['other_assets',[self::BANK,self::ASS.'other'],[self::LIAB.'other']],
  '13740'=>['other_assets',[self::ASS.'other'],[self::LIAB.'other']],
  '13750'=>['other_assets',[self::ASS.'other'],[self::LIAB.'other']],
  '14950'=>['other_assets',[self::ASS.'vat'],[self::LIAB.'theroffTax.vat']],
  '18950'=>['liabilities',[self::BANK],['bs.eqLiab.liab.bank.other']],
  '36300'=>['liabilities',[self::ASS.'other'],[self::LIAB.'other']],
  '37900'=>['liabilities',[self::ASS.'employees',self::ASS.'socInsur',self::ASS.'otherTaxRec'],[self::LIAB.'employees',self::LIAB.'thereoffSocSec',self::LIAB.'theroffTax.otherTaxes']],
 ];
 private static function rule(int $year,array $row):?array
 {
  $code=$row['skr_code']??'';if(!in_array($year,[2025,2026],true)||!isset(self::CONTRACTS[$code]))return null;
  $raw=file_get_contents(dirname(__DIR__, 2).'/../resources/ebilanz/skr42-6.9.json');$catalog=file_get_contents(dirname(__DIR__, 2).'/../data/skr42-'.$year.'.json');$hash=$year.':'.hash('sha256',$raw.$catalog);static $cache=[];
  if(!isset($cache[$hash])){
   require_once __DIR__.'/BalanceTaxMapping.php';BalanceTaxMapping::preview($year,[]);
   $registry=json_decode($raw,true,64,JSON_THROW_ON_ERROR);$rules=[];
   foreach($registry['rules'] as $r)if(isset($r['directional_balance'])&&(!isset($r['years'])||in_array($year,$r['years'],true)))foreach($r['codes'] as $n){
    $d=$r['directional_balance'];$contract=[$r['group'],$d['debit_targets']??null,$d['credit_targets']??null];
    if((self::CONTRACTS[$n]??null)!==$contract||array_keys($d)!==['debit_targets','credit_targets']||($r['condition']??null)!=='detail_choice'||($r['allowed_targets']??null)!==array_merge($contract[1],$contract[2])||isset($r['disclosures']))throw new DomainException('Quellengebundene Verrechnungskontenzuordnung wurde verändert.');
    $rules[$n]=$r;
   }
   if(count($cache)>=4)array_shift($cache);$cache[$hash]=$rules;
  }
  $r=$cache[$hash][$code]??null;if(!$r||$r['group']!==$row['group'])throw new DomainException('Originales Verrechnungskonto und Bilanzbereich stimmen nicht überein.');return $r;
 }
 public static function presentation(int $year,array $row):?array
 {
  if(self::rule($year,$row)===null)return null;$value=$row['closing_cents'];
  if(!is_int($value)||abs($value)>BalanceCalculation::LIMIT)throw new DomainException('Originalsaldo des Verrechnungskontos prüfen.');
  $row['group']=$value>0?'other_assets':($value<0?'liabilities':$row['group']);$row['amount_cents']=abs($value);return $row;
 }
 public static function choices(int $year,array $row):?array
 {
  $r=self::rule($year,$row);if($r===null)return null;
  $value=$row['closing_cents']??($row['group']==='other_assets'?$row['amount_cents']:-$row['amount_cents']);
  return $value>0?$r['directional_balance']['debit_targets']:($value<0?$r['directional_balance']['credit_targets']:$r['allowed_targets']);
 }
}
