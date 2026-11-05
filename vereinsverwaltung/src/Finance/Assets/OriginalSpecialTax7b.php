<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';
/** New-regime original7b: initial calendar, one wholly eligible acquired building. */
final class OriginalSpecialTax7b {
 public const CODES=['62450'];
 public const TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.impairment';
 public const FIELDS=['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'];
 public const FLAGS=['new_dwelling_confirmed','german_location_confirmed','building_cost_only_confirmed','all_area_eligible_confirmed','qng_eh40_confirmed','ten_year_rental_confirmed','clawback_obligations_confirmed','business_income_confirmed','aid_value_reviewed','ordinary_aid_only_confirmed','no_other_elections_confirmed','amount_reviewed'];
 public const DOCUMENTS=['construction','cost_area','qng','rental','aid','election'];
 public const KEYS=['schema','year','journal_sha256','item_id','special_account','regular_account','special_cents','application_on','completed_on','area_hundredths','aid_value_cents','other_aid_cents','new_dwelling_confirmed','german_location_confirmed','building_cost_only_confirmed','all_area_eligible_confirmed','qng_eh40_confirmed','ten_year_rental_confirmed','clawback_obligations_confirmed','business_income_confirmed','aid_value_reviewed','ordinary_aid_only_confirmed','no_other_elections_confirmed','amount_reviewed','construction_document','cost_area_document','qng_document','rental_document','aid_document','election_document'];
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('Sonderabschreibung7b: Originalkosten, Fläche, Neubau, QNG, Mietbindung und getrennte Steuer- und Beihilfewahl abstimmen.');}
 public static function originalDate(mixed $v):string{self::need(is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)===1&&checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)));return $v;}
 public static function rule(array $r):void{self::need(($r['special_tax_7b_contract']??null)==='62450@1'&&($r['group']??null)==='expense'&&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::TARGET&&($r['allowed_targets']??null)===[self::TARGET]&&!array_key_exists('book_only',$r)&&!array_key_exists('commercial_contract',$r)&&empty($r['disclosures']));if(array_key_exists('codes',$r))self::need($r['codes']===self::CODES);}
 public static function amounts(mixed $basis,mixed $area,mixed $special,mixed $date):array{
  self::need(is_int($basis)&&is_int($area)&&is_int($special)&&$basis>0&&$basis<=100000000000&&$area>0&&$area<=1000000000);$date=self::originalDate($date);
  self::need($basis<=$area*5200);$specialBasis=min($basis,$area*4000);$maximum=intdiv($specialBasis,20);self::need($special>0&&$special<=$maximum);
  $regular=intdiv($basis*3*(13-(int)substr($date,5,2))+600,1200);self::need($regular+$special<=$basis);
  return ['tax_basis_cents'=>$basis,'special_basis_cents'=>$specialBasis,'special_maximum_cents'=>$maximum,'tax_regular_cents'=>$regular,'tax_special_cents'=>$special,'tax_closing_cents'=>$basis-$regular-$special];
 }
 public static function bind(array $r,array $s):array{
  self::need(($r['state']??null)==='closed'&&in_array($r['year']??null,[2025,2026],true)&&($r['previous_sha256']??null)===null&&!array_key_exists('period',$r)&&!array_key_exists('club_scope',$r)&&array_keys($s)===self::KEYS&&$s['schema']===1&&$s['year']===$r['year']&&$s['journal_sha256']===$r['closed_sha256']);
  foreach(['item_id','special_account','regular_account'] as $k)self::need(is_string($s[$k])&&$s[$k]!=='');foreach(self::FLAGS as $k)self::need($s[$k]===true);foreach(self::DOCUMENTS as $k)AssetValuation::document($s[$k.'_document']);
  $application=self::originalDate($s['application_on']);$completed=self::originalDate($s['completed_on']);self::need($application>='2023-01-01'&&$application<'2029-10-01');
  $items=$r['asset_schedule']['items']??null;self::need(is_array($items)&&count($items)===1&&isset($items[$s['item_id']]));$i=$items[$s['item_id']];$acquired=self::originalDate($i['acquired_on']??null);
  self::need($application<=$completed&&$completed<=$acquired&&substr($completed,0,4)===substr($acquired,0,4)&&substr($acquired,0,4)===(string)$r['year']);
  self::need(($i['method']??'linear_monthly')==='linear_monthly'&&!array_intersect(array_keys($i),['gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan','substance'])&&($i['sphere']??null)==='4'&&($i['residual_cents']??null)===0&&in_array($i['skr_code'],AssetTypes::codes('building'),true));
  $basis=$i['cost_closing_cents'];$calculated=self::amounts($basis,$s['area_hundredths'],$s['special_cents'],$acquired);self::need($i['cost_opening_cents']===0&&$i['additions_cents']===$basis&&$i['accumulated_opening_cents']===0&&$i['book_opening_cents']===0&&$i['book_closing_cents']===$basis-$i['depreciation_cents']);
  $book=$r['calculation']['accounts'];$special=$book[$s['special_account']]??null;$regular=$book[$s['regular_account']]??null;$asset=$book[$i['account']]??null;self::need(is_array($special)&&is_array($regular)&&is_array($asset)&&$special['group']==='expense'&&$special['skr_code']==='62450'&&$regular['group']==='expense'&&$regular['skr_code']===AssetOriginalDepreciation::code($i,false));foreach(self::FIELDS as $f)self::need(($special[$f]??null)===0);
  self::need($asset['opening_cents']===0&&$asset['debit_cents']===$basis&&$asset['credit_cents']===$i['depreciation_cents']&&$asset['closing_cents']===$i['book_closing_cents']&&$regular['opening_cents']===0&&$regular['debit_cents']===$i['depreciation_cents']&&$regular['credit_cents']===0&&$regular['amount_cents']===$i['depreciation_cents']);
  self::need(is_int($s['aid_value_cents'])&&$s['aid_value_cents']>0&&$s['aid_value_cents']<=30000000&&is_int($s['other_aid_cents'])&&$s['other_aid_cents']>=0&&$s['other_aid_cents']<=30000000&&$s['aid_value_cents']+$s['other_aid_cents']<=30000000);
  return ['schema'=>1,'source'=>$s,'book_item_sha256'=>BalanceSegmentCalculation::hash($i),'asset_account'=>$i['account'],'regular_additional_cents'=>$calculated['tax_regular_cents']-$i['depreciation_cents']]+$calculated;
 }
 public static function check(array $v):void{
  foreach($v['book']['accounts'] as $a)if(in_array($a['skr_code'],self::CODES,true))foreach(self::FIELDS as $f)self::need($a[$f]===0);
  $active=array_filter($v['tax']['accounts'],static fn($a)=>in_array($a['skr_code'],self::CODES,true)&&array_filter(array_intersect_key($a,array_flip(self::FIELDS)),static fn($n)=>$n!==0));$p=$v['special_tax_7b']??null;if($p===null){self::need(!$active);return;}
  self::need(is_array($p)&&!array_intersect(array_keys($v),['period','club_scope','asset_tax','special_tax_7g','basis_reduction_7g'])&&$v['opening_adjustments']===[]&&isset($v['whole_club_scope'])&&self::bind($v['whole_club_scope']['source'],$p['source'])===$p);$s=$p['source'];self::need(count($active)===1&&isset($active[$s['special_account']]));$b=$v['book']['accounts'];$t=$v['tax']['accounts'];$n=$s['special_cents'];$d=$p['regular_additional_cents'];
  foreach(self::FIELDS as $f){$delta=$f==='debit_cents'?max(0,-$d):($f==='credit_cents'?$n+max(0,$d):(in_array($f,['closing_cents','amount_cents'],true)?-$n-$d:0));self::need($t[$p['asset_account']][$f]===$b[$p['asset_account']][$f]+$delta);$delta=$f==='debit_cents'?max(0,$d):($f==='credit_cents'?max(0,-$d):(in_array($f,['closing_cents','amount_cents'],true)?$d:0));self::need($t[$s['regular_account']][$f]===$b[$s['regular_account']][$f]+$delta);}
  foreach(['opening_cents'=>0,'debit_cents'=>$n,'credit_cents'=>0,'closing_cents'=>$n,'amount_cents'=>$n] as $f=>$x)self::need($t[$s['special_account']][$f]===$x);
 }
}
