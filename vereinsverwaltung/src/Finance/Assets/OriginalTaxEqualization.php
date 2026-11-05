<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceCalculation.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceSegmentCalculation.php';
require_once __DIR__.'/AssetValuation.php';
/** Original current fiscal valuation and neutral presentation of the approved capital. */
final class OriginalTaxEqualization {
 public const TARGET='bs.eqLiab.equity.netIncome.taxBalanceGenerally.transferDiffTaxAccounts';
 public const ENTRY='tax-equalization-77690';
 public const FLAGS=['original_capital_presentation_reviewed','original_fiscal_values_reviewed','no_other_result_use_reviewed'];
 public const DOCUMENTS=['capital','fiscal','presentation'];
 public const KEYS=['schema','year','journal_sha256','use_account','capital_account','original_current_adjustments',...self::FLAGS,'capital_document','fiscal_document','presentation_document'];
 public static function rule(array $r):void{self::need(($r['tax_equalization_contract']??null)==='77690@1'&&($r['group']??null)==='result_use'&&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::TARGET&&($r['allowed_targets']??null)===[self::TARGET]&&!array_key_exists('book_only',$r)&&!array_key_exists('commercial_contract',$r)&&empty($r['disclosures']));if(array_key_exists('codes',$r))self::need($r['codes']===['77690']);}
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('77690: ursprüngliche Kapitaldarstellung, steuerliche Bewertungsbuchungen und belegte erfolgsneutrale Ergebnisumbuchung vollständig abstimmen.');}
 public static function bind(array $r,array $s):array {
  if(isset($r['whole_club_scope']))$r=$r['whole_club_scope']['source'];
  self::need(array_keys($s)===self::KEYS&&$s['schema']===1&&in_array($r['year']??null,[2025,2026],true)&&($r['state']??null)==='closed'&&($r['issues']??null)===[]&&($r['previous_sha256']??null)===null&&!array_key_exists('period',$r)&&!array_key_exists('club_scope',$r)&&$s['year']===$r['year']&&is_string($s['journal_sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$s['journal_sha256'])===1&&$s['journal_sha256']===$r['closed_sha256']);
  self::need(($r['asset_schedule']['year']??null)===$r['year']&&($r['asset_schedule']['issues']??null)===[]&&($r['asset_schedule']['items']??null)===[]);
  foreach(self::FLAGS as $k)self::need($s[$k]===true);
  $ids=[];foreach(self::DOCUMENTS as $k){$d=$s[$k.'_document'];self::need(is_array($d)&&array_keys($d)===['document_id','revision','sha256']);AssetValuation::document($d);$ids[]=$d['document_id'];}self::need(count(array_unique($ids))===3);
  $book=BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries']);self::need($book===$r['calculation']&&$book['issues']===[]);
  self::need(is_string($s['use_account'])&&is_string($s['capital_account'])&&$s['use_account']!==$s['capital_account']&&isset($book['accounts'][$s['use_account']],$book['accounts'][$s['capital_account']]));
  $use=$book['accounts'][$s['use_account']];$capital=$book['accounts'][$s['capital_account']];self::need($use['group']==='result_use'&&$use['skr_code']==='77690'&&$capital['group']==='equity'&&$capital['skr_code']==='29700'&&$s['capital_account']===$r['result_account']);
  foreach(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'] as $f)self::need($use[$f]===0);
  foreach($book['accounts'] as $key=>$a)if($a['group']==='result_use')self::need($key===$s['use_account']);
  $entries=$s['original_current_adjustments'];self::need(is_array($entries)&&array_is_list($entries)&&count($entries)>=1&&count($entries)<=10);$seen=[];$vouchers=$r['entries'];
  foreach($entries as $i=>$e){self::need(is_array($e)&&array_keys($e)===['id','reason','lines']&&is_string($e['id'])&&preg_match('/^[a-z0-9_-]{1,40}$/D',$e['id'])===1&&$e['id']!==self::ENTRY&&!isset($seen[$e['id']])&&is_string($e['reason'])&&trim($e['reason'])===$e['reason']&&$e['reason']!==''&&mb_strlen($e['reason'])<=1500&&preg_match('/[\x00-\x1f]/',$e['reason'])===0);$seen[$e['id']]=true;
   $lines=BalanceCalculation::lines($e['lines'],$r['accounts']);self::need(count($lines)===2);$groups=[];foreach($lines as $l)$groups[]=$r['accounts'][$l['account']]['group'];sort($groups);self::need($groups===['expense','provisions']);
   $vouchers[]=['id'=>md5('tax-original-'.$i),'date'=>$r['year'].'-12-31','lines'=>$lines];
  }
  $tax=BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$vouchers);self::need($tax['issues']===[]);$change=$tax['annual_result_cents']-$book['annual_result_cents'];self::need($change!==0&&abs($change)<=BalanceCalculation::LIMIT);
  $line=static fn(string $key,int $signed)=>['account'=>$key,'debit_cents'=>max(0,$signed),'credit_cents'=>max(0,-$signed),'sphere'=>''];
  $posting=['id'=>self::ENTRY,'reason'=>'Belegte Ergebnisumbuchung 77690 zur unveränderten ursprünglichen Kapitaldarstellung.','lines'=>[$line($s['use_account'],$change),$line($s['capital_account'],-$change)]];
  return ['schema'=>1,'source'=>$s,'commercial_result_cents'=>$book['annual_result_cents'],'fiscal_result_cents'=>$tax['annual_result_cents'],'current_result_difference_cents'=>$change,'capital_book_amount_cents'=>$capital['amount_cents'],'presentation_entry'=>$posting];
 }
 public static function check(array $v):void {
  $active=array_filter($v['tax']['accounts'],static fn($a)=>$a['skr_code']==='77690'&&array_filter(array_intersect_key($a,array_flip(['opening_cents','debit_cents','credit_cents','closing_cents','amount_cents'])),static fn($n)=>$n!==0));$p=$v['tax_equalization']??null;if($p===null){self::need(!$active);return;}
  self::need(is_array($p)&&isset($v['whole_club_scope']['source'])&&!array_intersect(array_keys($v),['period','club_scope','asset_tax','special_tax_7g','special_tax_7b','basis_reduction_7g','basis_reduction_6b','pension_offsets'])&&self::bind($v['whole_club_scope']['source'],$p['source'])===$p);
  $s=$p['source'];self::need(count($active)===1&&isset($active[$s['use_account']])&&$v['opening_adjustments']===[]&&$v['current_adjustments']===[...$s['original_current_adjustments'],$p['presentation_entry']]);
  $r=$v['whole_club_scope']['source'];$entries=$r['entries'];foreach($v['current_adjustments'] as $i=>$e)$entries[]=['id'=>md5('tax-replay-'.$i),'date'=>$r['year'].'-12-31','lines'=>$e['lines']];
  self::need(BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$r['entries'])===$v['book']&&BalanceCalculation::build($r['year'],$r['accounts'],$r['opening'],$entries)===$v['tax']&&$v['result_difference_cents']===$p['current_result_difference_cents']);
 }
}
