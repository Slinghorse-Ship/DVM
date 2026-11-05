<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';
/** Original7g(2) basis election and recomputed ordinary tax depreciation. */
final class OriginalBasisReduction7g {
 public const CODES=['62430','62440'];
 public const TARGET='is.netIncome.regular.operatingTC.otherCost.deductValueEStG7g_2';
 public const FIELDS=['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'];
 public const FLAGS=['original_claim_valid_confirmed','same_taxpayer_and_business_confirmed','domestic_use_through_next_year_confirmed','outside_addition_reviewed','no_other_elections_confirmed','amount_reviewed'];
 public const KEYS=['schema','year','journal_sha256','item_id','reduction_account','regular_account','reduction_cents','iab_year','claimed_cents','available_cents','addition_cents','original_claim_valid_confirmed','same_taxpayer_and_business_confirmed','domestic_use_through_next_year_confirmed','outside_addition_reviewed','no_other_elections_confirmed','amount_reviewed','iab_document','usage_document','election_document'];
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('Kürzung7g: ursprünglichen IAB, verfügbaren Restbetrag, unabhängige außerbilanzielle Hinzurechnung und gewählte Anschaffungskostenkürzung vollständig abstimmen.');}
 public static function rule(array $r):void{self::need(in_array($r['basis_reduction_7g_contract']??null,['62430@1','62440@1'],true)&&($r['group']??null)==='expense'&&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::TARGET&&($r['allowed_targets']??null)===[self::TARGET]&&!array_key_exists('book_only',$r)&&!array_key_exists('commercial_contract',$r)&&empty($r['disclosures']));if(array_key_exists('codes',$r))self::need($r['codes']===[substr($r['basis_reduction_7g_contract'],0,5)]);}
 public static function bind(array $r,array $s):array{
  self::need(($r['state']??null)==='closed'&&in_array($r['year']??null,[2025,2026],true)&&($r['previous_sha256']??null)===null&&!array_key_exists('period',$r)&&!array_key_exists('club_scope',$r)&&array_keys($s)===self::KEYS&&$s['schema']===1&&$s['year']===$r['year']&&$s['journal_sha256']===$r['closed_sha256']);
  foreach(['item_id','reduction_account','regular_account'] as $k)self::need(is_string($s[$k]));foreach(self::FLAGS as $k)self::need($s[$k]===true);foreach(['iab','usage','election'] as $k)AssetValuation::document($s[$k.'_document']);
  self::need(is_int($s['iab_year'])&&$s['iab_year']<$r['year']&&$s['iab_year']>=$r['year']-3);foreach(['reduction_cents','claimed_cents','available_cents','addition_cents'] as $k)self::need(is_int($s[$k])&&$s[$k]>0&&$s[$k]<=BalanceCalculation::LIMIT);self::need($s['claimed_cents']<=20000000&&$s['available_cents']<=$s['claimed_cents']&&$s['addition_cents']<=$s['available_cents']&&$s['reduction_cents']<=$s['addition_cents']);
  $items=$r['asset_schedule']['items']??null;self::need(is_array($items)&&count($items)===1&&isset($items[$s['item_id']]));$i=$items[$s['item_id']];
  self::need(($i['method']??'linear_monthly')==='linear_monthly'&&!array_intersect(array_keys($i),['gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan'])&&($i['sphere']??null)==='4'&&substr($i['acquired_on']??'',0,4)===(string)$r['year']&&($i['residual_cents']??null)===0&&in_array($i['skr_code'],AssetTypes::codes('movable'),true)&&$i['skr_code']!=='06700');
  $basis=$i['cost_closing_cents'];$n=$s['reduction_cents'];self::need($s['addition_cents']<=intdiv($basis*50,100)&&$i['cost_opening_cents']===0&&$i['additions_cents']===$basis&&$i['accumulated_opening_cents']===0&&$i['book_opening_cents']===0&&$i['book_closing_cents']===$basis-$i['depreciation_cents']);
  $book=$r['calculation']['accounts'];$counter=$book[$s['reduction_account']]??null;$regular=$book[$s['regular_account']]??null;$asset=$book[$i['account']]??null;self::need(is_array($counter)&&is_array($regular)&&is_array($asset)&&$counter['group']==='expense'&&$counter['skr_code']===(in_array($i['skr_code'],AssetOriginalDepreciation::VEHICLES,true)?'62440':'62430')&&$regular['group']==='expense'&&$regular['skr_code']===AssetOriginalDepreciation::code($i,false));foreach(self::FIELDS as $f)self::need(($counter[$f]??null)===0);
  self::need($asset['opening_cents']===0&&$asset['debit_cents']===$basis&&$asset['credit_cents']===$i['depreciation_cents']&&$asset['closing_cents']===$i['book_closing_cents']&&$regular['opening_cents']===0&&$regular['debit_cents']===$i['depreciation_cents']&&$regular['credit_cents']===0&&$regular['amount_cents']===$i['depreciation_cents']);
  $months=$i['months'];$elapsed=13-(int)substr($i['acquired_on'],5,2);self::need(is_int($months)&&$months>=12&&$months<=1200&&$months%12===0);$taxDep=intdiv(($basis-$n)*$elapsed+intdiv($months,2),$months);$difference=$i['depreciation_cents']-$taxDep;self::need($difference>=0&&$difference<$n);
  return ['schema'=>1,'source'=>$s,'book_item_sha256'=>BalanceSegmentCalculation::hash($i),'asset_account'=>$i['account'],'tax_basis_cents'=>$basis-$n,'tax_regular_cents'=>$taxDep,'regular_reversal_cents'=>$difference,'tax_closing_cents'=>$basis-$n-$taxDep];
 }
 public static function check(array $v):void{
  foreach($v['book']['accounts'] as $a)if(in_array($a['skr_code'],self::CODES,true))foreach(self::FIELDS as $f)self::need($a[$f]===0);
  $active=array_filter($v['tax']['accounts'],static fn($a)=>in_array($a['skr_code'],self::CODES,true)&&array_filter(array_intersect_key($a,array_flip(self::FIELDS)),static fn($n)=>$n!==0));$p=$v['basis_reduction_7g']??null;if(!$active){self::need($p===null);return;}
  self::need(is_array($p)&&!array_key_exists('period',$v)&&!array_key_exists('club_scope',$v)&&isset($v['whole_club_scope']['source'])&&self::bind($v['whole_club_scope']['source'],$p['source'])===$p);
  self::need(count($active)===1&&isset($active[$p['source']['reduction_account']])&&$v['opening_adjustments']===[]&&!array_key_exists('asset_tax',$v)&&!array_key_exists('special_tax_7b',$v)&&!array_key_exists('special_tax_7g',$v));$s=$p['source'];$book=$v['book']['accounts'];$tax=$v['tax']['accounts'];$n=$s['reduction_cents'];$d=$p['regular_reversal_cents'];
  foreach(self::FIELDS as $f){$delta=$f==='debit_cents'?$d:($f==='credit_cents'?$n:(in_array($f,['closing_cents','amount_cents'],true)?-$n+$d:0));self::need($tax[$p['asset_account']][$f]===$book[$p['asset_account']][$f]+$delta);$delta=$f==='credit_cents'?$d:(in_array($f,['closing_cents','amount_cents'],true)?-$d:0);self::need($tax[$s['regular_account']][$f]===$book[$s['regular_account']][$f]+$delta);}
  foreach(['opening_cents'=>0,'debit_cents'=>$n,'credit_cents'=>0,'closing_cents'=>$n,'amount_cents'=>$n] as $f=>$x)self::need($tax[$s['reduction_account']][$f]===$x);
 }
}
