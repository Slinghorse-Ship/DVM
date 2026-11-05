<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangibleJournals.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangiblePlans.php';
/** Compare original proofs with every actual book and tax journal row. No export permission. */
final class BalanceOwnIntangibleValues
{
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('Originale Entwicklungsregister und tatsächliche Steueränderungen vollständig abstimmen.');}
 public static function same(mixed $a,mixed $b):bool{return OwnIntangibleJournals::fingerprint(['value'=>$a])===OwnIntangibleJournals::fingerprint(['value'=>$b]);}
 public static function entries(array $report,string $id,array $links):array{
  $report=self::journalReport($report);
  $item=$report['asset_schedule']['items'][$id]??null;self::need(is_array($item)&&($item['method']??null)===OwnIntangiblePlans::METHOD);
  $book=$item['own_book'];$docs=[];$events=[];
  foreach($book['source_proof']['source']['costs'] as $cost){$key=$cost['journal_id'];self::need(!isset($docs[$key])||$docs[$key]===$cost['document']);$docs[$key]=$cost['document'];}
  foreach($book['events'] as $row)$events[$row['original']['id']]=$row['original'];
  self::need(count($links)===count($events)&&count(array_unique(array_values($links)))===count($events)&&!array_diff(array_values($links),array_keys($events)));
  foreach($links as $voucher=>$event){self::need(!isset($docs[$voucher]));$docs[$voucher]=$events[$event]['document'];}
  $entries=$report['entries'];$found=[];
  foreach($entries as &$row){self::need(!array_key_exists('document',$row));if(isset($docs[$row['id']])){$row['document']=$docs[$row['id']];$found[$row['id']]=true;}}unset($row);
  self::need(!array_diff(array_keys($docs),array_keys($found)));return $entries;
 }
 public static function scope(array $report,array $review):string{
  self::journalReport($report);self::need(!isset($report['period'])&&($report['state']??null)==='closed');
  self::need(empty($review['asset_tax_plans'])&&empty($review['partnership_holdings']));
  $ids=[];foreach($report['asset_schedule']['items']??[] as $id=>$item){self::need(($item['method']??null)===OwnIntangiblePlans::METHOD);$ids[]=$id;}
  self::need(count($ids)===1);return $ids[0];
 }
 public static function journalReport(array $report):array{
  if(isset($report['club_scope'])){
   self::need(!isset($report['whole_club_scope'])&&BalanceClubTaxReport::rebuild($report['club_scope'])===$report);
   $whole=BalanceWholeClubReport::fromReport($report['club_scope']['source']);
   self::need($whole['calculation']===$report['calculation']&&$whole['opening']===$report['opening']&&self::same($whole['asset_schedule'],$report['asset_schedule']));
   return $whole;
  }
  self::need(isset($report['whole_club_scope'])&&BalanceWholeClubReport::rebuild($report['whole_club_scope'])===$report);return $report;
 }
 public static function bind(array $report,array $review,array $book,array $tax):?array{
  if(empty($review['own_intangible_sources']))return null;
  $id=self::scope($report,$review);$rows=$review['own_intangible_sources'];self::need(is_array($rows)&&array_keys($rows)===[$id]&&is_array($rows[$id]));
  $p=$rows[$id];self::need(self::same(OwnIntangibleJournals::verify($p['book_proof'],$p['journal'],$p['previous']),$p));$j=$p['journal'];$item=$report['asset_schedule']['items'][$id];
  self::need($j['item_id']===$id&&$j['year']===$report['year']&&$j['period']===BalancePeriod::calendar($report['year'])&&$j['account_key']===$item['account']&&$j['chart']===$report['accounts']&&$j['result_account']===$report['result_account']&&$j['book_opening']===$report['opening']);
  self::need(self::same($p['book_proof'],$item['own_book'])&&self::same($j['book_entries'],self::entries($report,$id,(array)$j['book_links'])));
  $expected=$j['book_entries'];foreach($review['current_adjustments'] as $row)$expected[]=['id'=>'tax-adjustment-'.$row['id'],'date'=>$report['year'].'-12-31','lines'=>$row['lines'],'document'=>$j['fiscal_source']['document']];self::need(self::same($expected,$j['tax_entries']));
  $net=array_fill_keys(array_keys($report['accounts']),0);foreach($report['opening'] as $l)$net[$l['account']]+=$l['debit_cents']-$l['credit_cents'];foreach($review['opening_adjustments'] as $row)foreach($row['lines'] as $l)$net[$l['account']]+=$l['debit_cents']-$l['credit_cents'];
  $actual=array_fill_keys(array_keys($net),0);foreach($j['tax_opening'] as $l)$actual[$l['account']]+=$l['debit_cents']-$l['credit_cents'];self::need($actual===$net);
  $bc=$p['book_calculation'];$tc=$p['tax_calculation'];unset($bc['period'],$tc['period']);self::need($bc===$book&&$tc===$tax);
  self::need(($report['previous_sha256']===null)===($p['previous']===null));
  // A result carry cannot silently change nominal share capital.
  if(in_array($report['accounts'][$report['result_account']]['skr_code'],['29000','29080'],true))self::need($book['annual_result_cents']===0&&$tax['annual_result_cents']===0);
  $out=['schema'=>1,'club_id'=>$j['club_id'],'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'schedule_sha256'=>OwnIntangibleJournals::fingerprint($report['asset_schedule']),'sources'=>$rows,'export_authorized'=>false];
  return $out+['sha256'=>OwnIntangibleJournals::fingerprint($out)];
 }
}
