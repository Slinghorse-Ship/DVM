<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';
/** Original6b(3) reserve and independently reduced replacement-building basis. */
final class OriginalBasisReduction6b {
 public const FLAGS=['original_taxable_gain_reviewed','six_year_domestic_holding_reviewed','original_reserve_and_remaining_reviewed','same_taxpayer_business_reviewed','domestic_replacement_reviewed','residential_building_reviewed','ordinary_two_percent_reviewed','no_other_elections_reviewed','amount_reviewed'];
 public const DOCUMENTS=['sale','reserve','election'];
 public const KEYS=['schema','year','journal_sha256','item_id','reduction_account','regular_account','reserve_account','release_account','capital_account','reserve_year','sold_kind','held_since','sold_on','sold_tax_book_cents','sale_price_cents','sale_cost_cents','formed_cents','available_cents','reduction_cents','building_completed_on',...self::FLAGS,'sale_document','reserve_document','election_document'];
 public const FIELDS=['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'];
 public const TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.EStG6b_3';
 public static function rule(array $r):void{self::need(($r['basis_reduction_6b_contract']??null)==='62490@1'&&($r['group']??null)==='expense'&&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::TARGET&&($r['allowed_targets']??null)===[self::TARGET]&&!array_key_exists('book_only',$r)&&!array_key_exists('commercial_contract',$r)&&empty($r['disclosures']));if(array_key_exists('codes',$r))self::need($r['codes']===['62490']);}
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('§6b: Originalverkauf, sechsjährige Inlandsbindung, ursprüngliche Rücklage, Restbetrag und konkrete Gebäude-Ersatzinvestition vollständig abstimmen.');}
 private static function day(mixed $v):string{self::need(is_string($v)&&preg_match('/^([0-9]{4})-([0-9]{2})-([0-9]{2})$/D',$v,$m)===1&&checkdate((int)$m[2],(int)$m[3],(int)$m[1]));return $v;}
 public static function bind(array $r,array $s):array{
  self::need(array_keys($s)===self::KEYS&&$s['schema']===1&&($r['state']??null)==='closed'&&in_array($r['year']??null,[2025,2026],true)&&($r['previous_sha256']??null)===null&&!array_key_exists('period',$r)&&!array_key_exists('club_scope',$r)&&$s['year']===$r['year']&&$s['journal_sha256']===$r['closed_sha256']);
  foreach(['item_id','reduction_account','regular_account','reserve_account','release_account','capital_account'] as $k)self::need(is_string($s[$k]));foreach(self::FLAGS as $k)self::need($s[$k]===true);
  $ids=[];foreach(self::DOCUMENTS as $k){self::need(is_array($s[$k.'_document'])&&array_keys($s[$k.'_document'])===['document_id','revision','sha256']);AssetValuation::document($s[$k.'_document']);$ids[]=$s[$k.'_document']['document_id'];}self::need(count(array_unique($ids))===3);
  self::need($s['reserve_year']===$r['year']-1&&in_array($s['sold_kind'],['land','building'],true));$held=self::day($s['held_since']);$sold=self::day($s['sold_on']);$completed=self::day($s['building_completed_on']);
  self::need((int)substr($sold,0,4)===$s['reserve_year']&&$held<$sold&&substr($sold,5)!=='02-29'&&$held<=((int)substr($sold,0,4)-6).substr($sold,4)&&$completed>='1925-01-01'&&$completed<'2023-01-01');
  foreach(['sold_tax_book_cents','sale_price_cents','sale_cost_cents','formed_cents','available_cents','reduction_cents'] as $k)self::need(is_int($s[$k])&&$s[$k]>=0&&$s[$k]<=BalanceCalculation::LIMIT);
  $gain=$s['sale_price_cents']-$s['sale_cost_cents']-$s['sold_tax_book_cents'];$n=$s['reduction_cents'];self::need($n>0&&$n<=$s['available_cents']&&$s['available_cents']<=$s['formed_cents']&&$s['formed_cents']<=$gain);
  $items=$r['asset_schedule']['items']??null;self::need(is_array($items)&&count($items)===1&&isset($items[$s['item_id']]));$i=$items[$s['item_id']];$acquired=self::day($i['acquired_on']);
  self::need($completed<=$acquired&&(int)substr($acquired,0,4)===$r['year']&&($i['method']??'linear_monthly')==='linear_monthly'&&($i['skr_code']??null)==='02400'&&($i['sphere']??null)==='4'&&($i['months']??null)===600&&($i['residual_cents']??null)===0&&!array_intersect(array_keys($i),['gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan']));
  $basis=$i['cost_closing_cents'];self::need($n<$basis&&$i['cost_opening_cents']===0&&$i['accumulated_opening_cents']===0&&$i['book_opening_cents']===0&&$i['additions_cents']===$basis&&$i['book_closing_cents']===$basis-$i['depreciation_cents']);
  $book=$r['calculation']['accounts'];$keys=[$i['account']];foreach(['reduction_account','regular_account','reserve_account','release_account','capital_account'] as $k)$keys[]=$s[$k];self::need(count(array_unique($keys))===6&&!array_diff($keys,array_keys($book)));
  foreach([['reduction_account','62490','expense'],['reserve_account','29810','deferred'],['release_account','49270','revenue']] as [$key,$code,$group]){$a=$book[$s[$key]];self::need($a['skr_code']===$code&&$a['group']===$group);foreach(self::FIELDS as $f)self::need(($a[$f]??null)===0);}
  $regular=$book[$s['regular_account']];$asset=$book[$i['account']];self::need($regular['group']==='expense'&&$regular['skr_code']==='62210'&&$s['capital_account']===$r['result_account']&&$book[$s['capital_account']]['group']==='equity');
  $dep=$i['depreciation_cents'];self::need($asset['opening_cents']===0&&$asset['debit_cents']===$basis&&$asset['credit_cents']===$dep&&$asset['closing_cents']===$i['book_closing_cents']&&$regular['opening_cents']===0&&$regular['debit_cents']===$dep&&$regular['credit_cents']===0&&$regular['amount_cents']===$dep);
  $elapsed=13-(int)substr($acquired,5,2);self::need($dep===intdiv($basis*$elapsed+300,600));$taxDep=intdiv(($basis-$n)*$elapsed+300,600);
  return ['schema'=>1,'source'=>$s,'book_item_sha256'=>BalanceSegmentCalculation::hash($i),'asset_account'=>$i['account'],'original_gain_cents'=>$gain,'tax_basis_cents'=>$basis-$n,'tax_regular_cents'=>$taxDep,'regular_reversal_cents'=>$dep-$taxDep,'tax_closing_cents'=>$basis-$n-$taxDep,'reserve_closing_cents'=>$s['available_cents']-$n];
 }
 public static function check(array $v):void{
  $active=array_filter($v['tax']['accounts'],static fn($a)=>$a['skr_code']==='62490'&&array_filter(array_intersect_key($a,array_flip(self::FIELDS)),static fn($n)=>$n!==0));$p=$v['basis_reduction_6b']??null;if($p===null){self::need(!$active);return;}
  self::need(is_array($p)&&!array_intersect(array_keys($v),['period','club_scope','asset_tax','special_tax_7b','special_tax_7g','basis_reduction_7g'])&&isset($v['whole_club_scope']['source'])&&self::bind($v['whole_club_scope']['source'],$p['source'])===$p);
  $s=$p['source'];self::need(count($active)===1&&isset($active[$s['reduction_account']]));$n=$s['reduction_cents'];$N=$s['available_cents'];$d=$p['regular_reversal_cents'];self::need(count($v['opening_adjustments'])===1&&count($v['current_adjustments'])===($d?3:2));$expected=$v['book']['accounts'];
  foreach(self::FIELDS as $f){$expected[$p['asset_account']][$f]+=$f==='debit_cents'?$d:($f==='credit_cents'?$n:(in_array($f,['closing_cents','amount_cents'],true)?-$n+$d:0));$expected[$s['regular_account']][$f]+=$f==='credit_cents'?$d:(in_array($f,['closing_cents','amount_cents'],true)?-$d:0);$expected[$s['capital_account']][$f]+=in_array($f,['opening_cents','closing_cents'],true)?$N:($f==='amount_cents'?-$N:0);}
  foreach([[$s['reduction_account'],[0,$n,0,$n,$n]],[$s['reserve_account'],[-$N,$n,0,$n-$N,$N-$n]],[$s['release_account'],[0,0,$n,-$n,$n]]] as [$key,$fields])foreach(self::FIELDS as $index=>$field)$expected[$key][$field]=$fields[$index];self::need($expected===$v['tax']['accounts']);
 }
}
