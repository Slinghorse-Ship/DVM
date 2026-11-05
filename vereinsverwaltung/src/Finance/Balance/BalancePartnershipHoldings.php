<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/PartnershipHoldingMatrix.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
/** Bind independently supplied original matrices to the actual entire journal. */
final class BalancePartnershipHoldings
{
 private static function require(bool $ok,string $message='Beteiligungsquellen mit dem vollständigen ursprünglichen Abschluss und den tatsächlichen Steuerbuchungen verbinden.'):void{if(!$ok)throw new DomainException($message);}
 private static function digest(mixed $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 private static function annual(array $v):array{unset($v['period']);return $v;}
 private static function balances(array $v,array $chart):array{$out=array_fill_keys(array_keys($chart),0);foreach($v as $l)$out[$l['account']]+=$l['debit_cents']-$l['credit_cents'];ksort($out,SORT_STRING);return $out;}
 public static function mapping(array $values):array{
  if(empty($values['partnership_holdings']))return [];
  $report=BalanceWholeClubReport::rebuild($values['whole_club_scope']);$proof=$values['partnership_holdings'];
  $review=['year'=>$values['year'],'journal_sha256'=>$values['journal_sha256'],'complete'=>true,'opening_adjustments'=>$values['opening_adjustments'],'current_adjustments'=>$values['current_adjustments'],'partnership_holdings'=>$proof['holdings']];
  self::require(self::bind($report,$review,$values['book'],$values['tax'])===$proof,'Beteiligungszuordnung gehört nicht zu den vollständig geprüften Steuerwerten.');
  $out=[];foreach($proof['holdings'] as $matrix)$out[$matrix['journal_proof']['journal']['account_key']]=$matrix['target'];return $out;
 }
 public static function bind(array $report,array $review,array $book,array $tax):?array
 {
  if(!array_key_exists('partnership_holdings',$review))return null;
  $holdings=$review['partnership_holdings'];self::require(is_array($holdings)&&count($holdings)<=200);
  if(!$holdings)return null;
  self::require(isset($report['whole_club_scope'])&&!isset($report['period'])&&!isset($report['club_scope']), 'Beteiligungsadapter dieser Quellenstufe benötigt die vollständige ursprüngliche Kalenderbilanz. Bereichs- und Zeitraumquellen gesondert anbinden.');
  self::require(array_key_exists('previous_sha256',$report));
  $used=[];$rows=[];$initial=[];$club=null;$chart=BalanceCalculation::chart($report['accounts']);
  // An automatic result carry must never silently alter nominal share capital.
  if(in_array($chart[$report['result_account']]['skr_code'],['29000','29080'],true))foreach([$book,$tax] as $calculation){
   $remaining=$calculation['annual_result_cents'];foreach($calculation['accounts'] as $account)if($account['group']==='result_use')$remaining+=$account['amount_cents'];
   self::require($remaining===0,'Ergebnisvortrag auf ein gesondertes Ergebniskonto buchen; das ursprüngliche Stammkapital darf durch den automatischen Vortrag nicht verändert werden.');
  }
  foreach($holdings as $holding=>$matrix){
   self::require(is_string($holding)&&preg_match('/^[a-f0-9]{32}$/D',$holding)&&is_array($matrix)&&PartnershipHoldingMatrix::reconcile($matrix['journal_proof'],$matrix['source'],$matrix['previous']??null)===$matrix);
   $p=$matrix['journal_proof'];$j=$p['journal'];$s=$p['sources'];$key=$j['account_key'];
   if($club===null)$club=$j['club_id'];self::require($j['club_id']===$club);
   self::require($holding===$j['holding_id']&&!isset($used[$key])&&$j['year']===$report['year']&&$j['period']===['start'=>$report['year'].'-01-01','end'=>$report['year'].'-12-31']);$used[$key]=true;
   self::require($j['chart']===$report['accounts']&&$j['result_account']===$report['result_account']&&$j['book_opening']===$report['opening']&&$j['book_entries']===$report['entries']);
   self::require(self::annual($p['book_calculation'])===$book&&self::annual($p['tax_calculation'])===$tax);
   if($report['previous_sha256']!==null){
    self::require($p['previous']!==null&&$matrix['previous']!==null&&$p['carry']==='verified_original_journal'&&$matrix['carry']==='verified_original_matrix_journal','Ursprüngliche Vorjahresjournale und steuerliche Originalmatrizen vollständig übernehmen.');
   }elseif($p['carry']==='unbound_previous_journal'||$matrix['carry']==='unbound_previous_matrix'){
    self::require($p['previous']===null&&$matrix['previous']===null,'Anfangsübernahme nur ohne vorangehenden Anwendungsabschluss und ohne behauptetes Vorjahresjournal bestätigen.');
    // Older source registers remain older registers, not a fabricated APP journal.
    $initial[]=$holding;
   }
   $expected=$report['entries'];foreach($review['current_adjustments'] as $e)$expected[]=['id'=>'tax-adjustment-'.$e['id'],'date'=>$report['year'].'-12-31','lines'=>$e['lines']];
   self::require($j['tax_entries']===$expected,'Jede ursprüngliche steuerliche Journalzeile muss der tatsächlichen, bestätigten Wertänderung entsprechen.');
   $opening=self::balances($report['opening'],$chart);foreach($review['opening_adjustments'] as $e)foreach($e['lines'] as $l)$opening[$l['account']]+=$l['debit_cents']-$l['credit_cents'];
   self::require(self::balances($j['tax_opening'],$chart)===$opening);
   foreach($report['asset_schedule']['items']??[] as $id=>$item)if(($item['account']??$item['fixed_account']??null)===$key){
    self::require(($item['method']??null)==='partnership_hgb'&&$id===$holding&&($item['partnership_book']??null)===$s['book_basis']&&($item['partnership_plan']['partnership_relationship']??null)===$s['relationship'],'Ursprüngliche Beteiligung nicht zugleich als gewöhnliche Kostenanlage bewerten; das bestätigte HGB-Register unverändert übernehmen.');
   }
   $rows[$holding]=$matrix;
  }
  $out=['schema'=>1,'club_id'=>$club,'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'ordinary_schedule_sha256'=>self::digest($report['asset_schedule']),
   'book_calculation_sha256'=>self::digest($book),'tax_calculation_sha256'=>self::digest($tax),'initial_opening_holdings'=>$initial,'holdings'=>$rows,'export_authorized'=>false];
  return $out+['sha256'=>self::digest($out)];
 }
}
