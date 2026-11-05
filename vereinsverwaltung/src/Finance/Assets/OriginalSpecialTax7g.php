<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';
/** Separate original section7g(5) election; original book plan never changes. */
final class OriginalSpecialTax7g {
 public const CODES=['62400','62410','62420'];
 public const TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.impairment';
 public const FIELDS=['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'];
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('Sonderabschreibung7g: Originalanschaffung, Vorjahresgewinn, betriebliche Nutzungsbindung und gesondert bestätigte Steuerwahl vollständig abstimmen.');}
 public static function rule(array $r):void{self::need(in_array($r['special_tax_7g_contract']??null,['62400@1','62410@1','62420@1'],true)&&($r['group']??null)==='expense'&&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::TARGET&&($r['allowed_targets']??null)===[self::TARGET]&&!array_key_exists('book_only',$r)&&!array_key_exists('commercial_contract',$r)&&empty($r['disclosures']));if(array_key_exists('codes',$r))self::need($r['codes']===[substr($r['special_tax_7g_contract'],0,5)]);}
 public static function bind(array $report,array $source):array{
  self::need(($report['state']??null)==='closed'&&in_array($report['year']??null,[2025,2026],true)&&($report['previous_sha256']??null)===null&&!isset($report['period'])&&!isset($report['club_scope']));
  $generic=($source['schema']??null)===2;
  self::need(array_keys($source)===array_merge(['schema','year','journal_sha256','item_id','special_account','regular_account','special_cents','previous_profit_cents','domestic_business_use_confirmed','use_through_next_year_confirmed','no_other_elections_confirmed','amount_reviewed','profit_document','eligibility_document','election_document'],$generic?['tax_law']:[])&&$source['schema']===($generic?2:1)&&(!$generic||$source['tax_law']==='EStG7g5')&&$source['year']===$report['year']&&$source['journal_sha256']===$report['closed_sha256']);
  foreach(['item_id','special_account','regular_account'] as $k)self::need(is_string($source[$k]));
  foreach(['domestic_business_use_confirmed','use_through_next_year_confirmed','no_other_elections_confirmed','amount_reviewed'] as $k)self::need($source[$k]===true);
  self::need(is_int($source['previous_profit_cents'])&&abs($source['previous_profit_cents'])<=BalanceCalculation::LIMIT&&$source['previous_profit_cents']<=20000000);
  foreach(['profit_document','eligibility_document','election_document'] as $k)AssetValuation::document($source[$k]);
  $items=$report['asset_schedule']['items']??null;self::need(is_array($items)&&count($items)===1&&isset($items[$source['item_id']]));$item=$items[$source['item_id']];
  self::need(($item['method']??'linear_monthly')==='linear_monthly'&&!array_intersect(array_keys($item),['gwg','degressive','disposal','original_leasing','exceptional','valuation','partial_plan'])&&($item['sphere']??null)==='4'&&substr($item['acquired_on']??'',0,4)===(string)$source['year']&&($item['residual_cents']??null)===0);
  $code=$item['skr_code'];self::need(in_array($code,AssetTypes::codes('movable'),true)&&$code!=='06700');
  $book=$report['calculation']['accounts'];$special=$book[$source['special_account']]??null;$regular=$book[$source['regular_account']]??null;$asset=$book[$item['account']]??null;
  self::need(is_array($special)&&is_array($regular)&&is_array($asset)&&$special['group']==='expense'&&$special['skr_code']===($generic?'62400':(in_array($code,AssetOriginalDepreciation::VEHICLES,true)?'62420':'62410'))&&(!$generic||!in_array($code,AssetOriginalDepreciation::VEHICLES,true))&&$regular['group']==='expense'&&$regular['skr_code']===AssetOriginalDepreciation::code($item,false));
  foreach(self::FIELDS as $f)self::need(($special[$f]??null)===0);
  $n=$source['special_cents'];$basis=$item['cost_closing_cents'];self::need(is_int($n)&&$n>0&&$n<=intdiv($basis*40,100)&&$n<=$item['book_closing_cents']&&$item['cost_opening_cents']===0&&$item['additions_cents']===$basis&&$item['accumulated_opening_cents']===0&&$item['book_opening_cents']===0&&$item['book_closing_cents']===$basis-$item['depreciation_cents']);
  self::need($asset['opening_cents']===0&&$asset['debit_cents']===$basis&&$asset['credit_cents']===$item['depreciation_cents']&&$asset['closing_cents']===$item['book_closing_cents']&&$regular['opening_cents']===0&&$regular['debit_cents']===$item['depreciation_cents']&&$regular['credit_cents']===0&&$regular['amount_cents']===$item['depreciation_cents']);
  return ['schema'=>1,'source'=>$source,'book_item_sha256'=>BalanceSegmentCalculation::hash($item),'asset_account'=>$item['account'],'regular_depreciation_cents'=>$item['depreciation_cents'],'tax_special_cents'=>$n,'tax_closing_cents'=>$item['book_closing_cents']-$n];
 }
 public static function check(array $values):void{
  foreach($values['book']['accounts'] as $row)if(in_array($row['skr_code'],self::CODES,true))foreach(self::FIELDS as $field)self::need($row[$field]===0);
  $active=array_filter($values['tax']['accounts'],static fn($a)=>in_array($a['skr_code'],self::CODES,true)&&array_filter(array_intersect_key($a,array_flip(self::FIELDS)),static fn($n)=>$n!==0));
  if(!$active){self::need(!isset($values['special_tax_7g']));return;}
  $p=$values['special_tax_7g']??null;self::need(is_array($p)&&count($active)===1&&isset($active[$p['source']['special_account']]));$s=$p['source'];$book=$values['book']['accounts'];$tax=$values['tax']['accounts'];
  self::need(isset($values['whole_club_scope'])&&self::bind($values['whole_club_scope']['source'],$s)===$p);
  self::need($values['opening_adjustments']===[]&&!isset($values['asset_tax'])&&!array_key_exists('special_tax_7b',$values));
  foreach(self::FIELDS as $f){$delta=$f==='credit_cents'?$s['special_cents']:(in_array($f,['closing_cents','amount_cents'],true)?-$s['special_cents']:0);self::need($tax[$p['asset_account']][$f]===$book[$p['asset_account']][$f]+$delta);self::need($tax[$s['regular_account']][$f]===$book[$s['regular_account']][$f]);}
  $expected=['opening_cents'=>0,'debit_cents'=>$s['special_cents'],'credit_cents'=>0,'closing_cents'=>$s['special_cents'],'amount_cents'=>$s['special_cents']];foreach($expected as $f=>$n)self::need($tax[$s['special_account']][$f]===$n);
 }
}
