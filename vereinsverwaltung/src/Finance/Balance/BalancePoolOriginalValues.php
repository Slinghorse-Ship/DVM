<?php declare(strict_types=1);
namespace Kulturhus\Verein;
require_once dirname(__DIR__, 2).'/Finance/Assets/PoolOriginalJournals.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/PoolOriginalBookJournals.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
use DomainException;
/** Bind a single complete original cohort, independently reviewed for both books. */
final class BalancePoolOriginalValues
{
 public const TARGET='bs.ass.fixAss.tan.otherEquipm.gwgsammelposten';
 public const EXPENSE='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.lowValueAsCollItem';
 public const METHOD='original_pool_cohort';
 private static function need(bool $ok):void{if(!$ok)throw new DomainException('Ursprüngliche HGB- und Steuerregister des Sammelpostens vollständig abstimmen.');}
 private static function same(array $a,array $b):bool{return PoolOriginalSources::fingerprint($a)===PoolOriginalSources::fingerprint($b);}
 public static function rule(array $r):void{
  $code=($r['codes']??null);self::need(is_array($code)&&count($code)===1&&in_array($code[0],['06750','62640'],true));$asset=$code[0]==='06750';$target=$asset?self::TARGET:self::EXPENSE;
  self::need(($r['pool_original_contract']??null)==='original-pool-r73-'.$code[0].'@1'&&($r['group']??null)===($asset?'fixed_assets':'expense')&&($r['target']??null)===$target&&($r['allowed_targets']??null)===[$target]&&($r['condition']??null)==='pool_original'&&($r['source_years']??null)===[2025,2026]&&(!isset($r['years'])||$r['years']===[2025,2026])&&empty($r['disclosures'])&&!array_intersect_key($r,array_flip(['book_only','commercial_contract','partnership_income_contract','partnership_book_target','partnership_book_disclosures','own_intangible_contract','commercial_income_direction','negative_target','directional_balance'])));
 }
 public static function amounts(array $p):array{$out=$p['hgb'];unset($out['disposals_cents'],$out['accumulated_disposals_cents'],$out['book_disposals_cents']);return $out;}
 private static function individuals(array $items,array $b,array $t,array $amounts,string $account):void{
  $ids=$t['pool_proof']['eligible_items'];self::need(count($items)===count($ids)&&!array_diff(array_keys($items),$ids));$originals=$invoices=[];foreach($b['book_proof']['book_source']['items'] as $row)$originals[$row['item_id']]=$row;foreach($t['pool_proof']['source']['population'] as $row)$invoices[$row['item_id']]=$row;
  foreach($items as $id=>$item){$s=$originals[$id];$invoice=$invoices[$id];self::need(is_array($item)&&($item['method']??'linear_monthly')==='linear_monthly'&&!array_diff(array_keys($item),array_merge(array_keys($amounts),['item_id','invoice_id','receipt_number','name','account','skr_code','sphere','acquired_on','months','residual_cents','expected_depreciation_cents','acquisition_hash','depreciation_hash','method'])));self::need($item['item_id']===$id&&$item['invoice_id']===$invoice['invoice_id']&&$item['account']===$account&&$item['skr_code']==='06750'&&$item['sphere']==='4'&&$item['acquired_on']===$s['acquired_on']&&$item['months']===$s['original_life_months']&&$item['residual_cents']===$s['original_residual_cents']&&$item['expected_depreciation_cents']===$s['depreciation_cents']);foreach($amounts as $key=>$n)self::need(is_int($item[$key]??null)&&$item[$key]===$s[$key]);foreach(['acquisition_hash','depreciation_hash'] as $key)self::need(is_string($item[$key]??null)&&preg_match('/^[a-f0-9]{64}$/D',$item[$key])===1);}
  foreach($amounts as $key=>$n){$sum=0;foreach($items as $item)$sum+=$item[$key];self::need($sum===$n);}
 }
 public static function bind(array $report,array $sources):array{
  self::need(isset($report['whole_club_scope'])&&!isset($report['period'])&&!isset($report['club_scope'])&&BalanceWholeClubReport::rebuild($report['whole_club_scope'])===$report);$items=$report['asset_schedule']['items'];self::need(is_array($items)&&count($sources)===1&&$report['asset_schedule']['issues']===[]);
  foreach($sources as $id=>$pair){
   self::need(is_array($pair)&&count($pair)===2&&!array_diff(array_keys($pair),['books','fiscal'])&&is_array($pair['books'])&&is_array($pair['fiscal']));$b=$pair['books'];$t=$pair['fiscal'];self::need(isset($b['book_proof'],$b['journal'],$t['pool_proof'],$t['journal'])&&array_key_exists('previous',$b)&&array_key_exists('previous',$t));self::need(self::same(PoolOriginalBookJournals::verify($b['book_proof'],$b['journal'],$b['previous']),$b)&&self::same(PoolOriginalJournals::verify($t['pool_proof'],$t['journal'],$t['previous']),$t)&&self::same($b['book_proof']['pool_proof'],$t['pool_proof']));$j=$b['journal'];$tj=$t['journal'];$s=$t['pool_proof']['source'];
   self::need($id===$s['pool_id']&&$j['club_id']===$tj['club_id']&&$j['club_id']===$s['club_id']&&$j['pool_id']===$id&&$tj['pool_id']===$id&&$j['year']===$report['year']&&$tj['year']===$report['year']&&$j['chart']===$report['accounts']&&$tj['chart']===$report['accounts']&&$j['result_account']===$report['result_account']&&$tj['result_account']===$report['result_account']&&$j['opening']===$report['opening']&&$tj['opening']===$report['opening']);
   foreach($report['entries'] as $row)self::need(!array_key_exists('document',$row));$brows=$j['entries'];$trows=$tj['entries'];foreach($brows as &$row)unset($row['document']);unset($row);foreach($trows as &$row)unset($row['document']);unset($row);$calc=$b['calculation'];unset($calc['period']);self::need($brows===$report['entries']&&$trows===$report['entries']&&$calc===$report['calculation']&&$b['calculation']===$t['calculation']);
   self::need($j['pool_account']===$tj['pool_account']);$amounts=self::amounts($b['book_proof']);self::individuals($items,$b,$t,$amounts,$j['pool_account']);self::need($report['asset_schedule']['totals']===$amounts&&(($report['previous_sha256']===null)===($b['previous']===null))&&(($report['previous_sha256']===null)===($t['previous']===null)));
   foreach($calc['accounts'] as $key=>$row)if($row['group']==='fixed_assets'&&$key!==$j['pool_account'])foreach(['opening_cents','debit_cents','credit_cents','closing_cents'] as $field)self::need($row[$field]===0);
  }
  $out=['schema'=>1,'club_id'=>$s['club_id'],'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'schedule_sha256'=>PoolOriginalSources::fingerprint($report['asset_schedule']),'sources'=>$sources,'export_authorized'=>false];return $out+['sha256'=>PoolOriginalSources::fingerprint($out)];
 }
 public static function mapping(array $values):array{
  $envelope=$values['pool_original_sources']??null;if($envelope===null)return [];
  self::need(is_array($envelope)&&isset($values['whole_club_scope'])&&!array_intersect_key($values,array_flip(['period','club_scope','asset_tax','partnership_holdings','own_intangible_sources','own_hgb_sources','annual_section']))&&$values['opening_adjustments']===[]&&$values['current_adjustments']===[]&&$values['book']===$values['tax']);$r=BalanceWholeClubReport::rebuild($values['whole_club_scope']);self::need(self::same(self::bind($r,$envelope['sources']),$envelope)&&$values['year']===$envelope['year']&&$values['journal_sha256']===$envelope['journal_sha256']&&self::same($values['asset_schedule'],$r['asset_schedule'])&&$values['book']===$r['calculation']);$out=[];foreach($envelope['sources'] as $pair){$j=$pair['fiscal']['journal'];$out[$j['pool_account']]=self::TARGET;$out[$j['depreciation_account']]=self::EXPENSE;}return $out;
 }
}
