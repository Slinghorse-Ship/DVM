<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/PartnershipHoldingSources.php';
require_once dirname(__DIR__, 2).'/Finance/Balance/BalanceCalculation.php';
require_once dirname(__DIR__, 2).'/Finance/Tax/TaxCatalog.php';
/** Verify actual original journals; never generate balancing or valuation entries. */
final class PartnershipHoldingJournals
{
 private static function require(bool $ok,string $message='Ursprüngliche Beteiligungsbewegungen einzeln mit den tatsächlichen Journalen abstimmen.'):void{if(!$ok)throw new DomainException($message);}
 private static function keys(mixed $v,array $keys):bool{return is_array($v)&&count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
 private static function fixedLines(array $entries,array $chart,string $key):array{
  $out=[];$seen=[];
  foreach($entries as $e){
   self::require(is_string($e['id'])&&!isset($seen[$e['id']]));$seen[$e['id']]=true;
   foreach($e['lines'] as $index=>$line){
    if($line['account']!==$key)continue;self::require(count($e['lines'])===2,'Beteiligungsjournal: mehrzeilige Originalvorgänge benötigen ihren eigenen vollständigen Aufteilungsnachweis.');
    $other=$e['lines'][1-$index];self::require($other['account']!==$key);$ref=$e['id'].':'.$index;self::require(!isset($out[$ref]));
    $out[$ref]=['voucher'=>$e['id'],'on'=>$e['date'],'cents'=>$line['debit_cents']-$line['credit_cents'],'counter'=>$other['account'],'counter_code'=>$chart[$other['account']]['skr_code'],'counter_group'=>$chart[$other['account']]['group']];
   }
  }return $out;
 }
 public static function verify(array $sources,array $j,?array $previous=null,int $depth=0):array{
  self::require($depth>=0&&$depth<=100);
  self::require(PartnershipHoldingSources::reconcile($sources['book_basis'],$sources['capital_basis'],$sources['relationship'])===$sources);
  self::require(self::keys($j,['schema','club_id','holding_id','account_key','result_account','period','year','chart','book_opening','book_entries','tax_opening','tax_entries','book_links','tax_links','document','reason','confirmed'])&&$j['schema']===1&&$j['confirmed']===true);
  foreach(['club_id','holding_id'] as $key)self::require($j[$key]===$sources[$key]);self::require($j['period']==$sources['period']);
  self::require(is_int($j['year'])&&in_array($j['year'],[2025,2026],true),'Beteiligungsjournal: ursprünglichen geprüften Jahreskatalog verwenden.');
  self::require($sources['capital_basis']['opening_cents']>=0&&$sources['capital_basis']['closing_cents']>=0,'Negativer Spiegelbildansatz benötigt seinen eigenen signierten Bilanzadapter; der Originalbetrag bleibt im Quellenstand erhalten.');
  $period=BalancePeriod::check($j['period'],$j['year']);$d=$j['document'];self::require(self::keys($d,['document_id','revision','sha256'])&&is_string($d['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$d['document_id'])&&is_int($d['revision'])&&$d['revision']>=1&&is_string($d['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$d['sha256']));
  self::require(is_string($j['reason'])&&trim($j['reason'])!==''&&mb_strlen($j['reason'])<=2000&&!preg_match('/[\x00-\x1f]/',$j['reason']));
  $chart=BalanceCalculation::chart($j['chart']);$key=$j['account_key'];self::require(is_string($key)&&($chart[$key]['group']??null)==='fixed_assets'&&$chart[$key]['skr_code']===$sources['account']);
  self::require(is_string($j['result_account'])&&($chart[$j['result_account']]['group']??null)==='equity');
  $carry=$sources['book_basis']['previous']!==null||$sources['capital_basis']['previous']!==null?'unbound_previous_journal':'initial_source';
  if($previous!==null){
   self::require(self::verify($previous['sources'],$previous['journal'],$previous['previous']??null,$depth+1)===$previous);$old=$previous['sources'];self::require($sources['book_basis']['previous']===$old['book_basis']&&$sources['capital_basis']['previous']===$old['capital_basis']&&$j['result_account']===$previous['journal']['result_account']);
   foreach($previous['journal']['chart'] as $k=>$row)self::require(($chart[$k]??null)===$row);
   foreach(['book','tax'] as $side){
    $calc=$previous[$side.'_calculation'];$want=array_fill_keys(array_keys($chart),0);foreach(BalanceCalculation::carryForward($calc,$j['result_account']) as $line)$want[$line['account']]+=$line['debit_cents']-$line['credit_cents'];
    $actual=array_fill_keys(array_keys($chart),0);foreach($j[$side.'_opening'] as $line)$actual[$line['account']]+=$line['debit_cents']-$line['credit_cents'];self::require($actual===$want,'Sämtliche ursprünglichen Buch-/Steuerbestände mit ihrem tatsächlichen Vorjahresergebnis unverändert vortragen.');
   }$carry='verified_original_journal';
  }
  $expected=[];$s=$sources['book_basis']['source'];$p=$s['plan'];$basis=$sources['book_basis']['book'];
  if($p['acquired_on']>=$period['start']&&$p['acquired_on']<=$period['end'])$expected['acquisition']=['on'=>$p['acquired_on'],'kind'=>'addition','cents'=>$p['basis_cents']];
  foreach($sources['book_basis']['movements'] as $m)$expected[$m['event_id']]=['on'=>$m['on'],'kind'=>$m['kind'],'cents'=>$m['book_change_cents']];
  $book=BalanceCalculation::build($j['year'],$chart,$j['book_opening'],$j['book_entries'],$period);$tax=BalanceCalculation::build($j['year'],$chart,$j['tax_opening'],$j['tax_entries'],$period);
  self::require(!$book['issues']&&!$tax['issues']&&$book['accounts'][$key]['opening_cents']===$basis['book_opening_cents']&&$book['accounts'][$key]['closing_cents']===$basis['book_closing_cents']&&$tax['accounts'][$key]['opening_cents']===$sources['capital_basis']['opening_cents']&&$tax['accounts'][$key]['closing_cents']===$sources['capital_basis']['closing_cents']);
  self::require($book['accounts'][$key]['debit_cents']===$basis['additions_cents']+$basis['writeup_cents']&&$book['accounts'][$key]['credit_cents']===$basis['impairment_cents']+$basis['book_disposals_cents'],'Ursprüngliche HGB-Bruttobewegungen vollständig abstimmen.');
  $bookLines=self::fixedLines($j['book_entries'],$chart,$key);$taxLines=self::fixedLines($j['tax_entries'],$chart,$key);
  self::require(self::keys($j['book_links'],array_keys($bookLines)));$used=[];$original=[];
  foreach($j['book_links'] as $ref=>$event){
   self::require(is_string($event)&&isset($expected[$event])&&!isset($used[$event]));$used[$event]=true;$actual=$bookLines[$ref];$want=$expected[$event];self::require($actual['on']===$want['on']&&$actual['cents']===$want['cents']);$kind=$want['kind'];$code=$actual['counter_code'];$group=$actual['counter_group'];
   if($kind==='addition')self::require(in_array($group,['cash','receivables','other_assets','liabilities'],true),'Tatsächliche ursprüngliche Zuführung ohne fingierten Ertrag nachweisen.');
   elseif($kind==='impairment')self::require($group==='expense'&&in_array($code,['72000','72010','72070'],true));
   elseif($kind==='writeup')self::require($group==='revenue'&&$code==='49120');
   else self::require($group==='expense'&&$code==='68970');
   if($code==='72070')self::require(in_array($sources['relationship']['kind'],['affiliated','parent_company'],true));$original[$event]=$actual;
  }
  self::require(count($used)===count($expected),'Alle ursprünglichen HGB-Ereignisse an ihre tatsächlichen Journalzeilen binden.');
  self::require(self::keys($j['tax_links'],array_keys($taxLines)));$events=[];$deltas=[];
  foreach($sources['capital_basis']['source']['movements'] as $e)$events[$e['id']]=$e;foreach($sources['capital_basis']['movements'] as $e)$deltas[$e['event_id']]=$e['basis_change_cents'];$used=[];$reversals=[];
  foreach($j['tax_links'] as $ref=>$link){
   self::require(self::keys($link,['kind','event_id'])&&is_string($link['event_id']));$actual=$taxLines[$ref];$event=$link['event_id'];
   if($link['kind']==='capital_movement'){
    self::require(isset($events[$event])&&!isset($used[$event]));$used[$event]=true;$e=$events[$event];self::require($actual['on']===$e['on']&&$actual['cents']===$deltas[$event]);$kind=$e['kind'];$group=$actual['counter_group'];$code=$actual['counter_code'];
    if(in_array($kind,['contribution','withdrawal'],true))self::require(in_array($group,['cash','receivables','other_assets','liabilities'],true));
    elseif($kind==='assessed_profit'){$codes=$sources['capital_basis']['source']['classification']==='atypical_silent_partner'?['70030']:(in_array($sources['relationship']['kind'],['affiliated','parent_company'],true)?['70040','70080']:['70080']);self::require($group==='revenue'&&in_array($code,$codes,true));}
    elseif($kind==='assessed_loss')self::require($group==='expense'&&$code==='72080');
    else throw new DomainException('Kapitalberichtigung benötigt einen gesondert belegten ursprünglichen steuerlichen Gegenposten; nicht als laufenden Gewinn oder Verlust ausgeben.');
   }else{
    self::require($link['kind']==='book_reversal_pair'&&isset($original[$event])&&in_array($expected[$event]['kind'],['impairment','writeup','disposal'],true));$want=$original[$event];self::require($actual['counter']===$want['counter']&&abs($actual['cents'])===abs($want['cents'])&&$actual['on']>=$want['on']);$reversals[$event][]=$actual;
   }
  }
  self::require(count($used)===count($events),'Alle tatsächlichen steuerlichen Kapitalbewegungen einzeln an ihre Originaljournalzeilen binden.');
  foreach($reversals as $event=>$pair)self::require(count($pair)===2&&array_sum(array_column($pair,'cents'))===0&&count(array_filter($pair,static fn($a)=>$a===$original[$event]))===1,'HGB-Bewegung und tatsächliche steuerliche Gegenbuchung als unverändertes Originalpaar nachweisen.');
  foreach($original as $event=>$want){if(isset($reversals[$event]))continue;$found=false;foreach($taxLines as $ref=>$actual)if($actual===$want&&$j['tax_links'][$ref]['kind']==='capital_movement')$found=true;self::require($found,'HGB-Bewegung ohne tatsächlichen steuerlichen Kapitalnachweis oder vollständiges Gegenbuchungspaar.');}
  $out=['schema'=>1,'sources'=>$sources,'previous'=>$previous,'carry'=>$carry,'sources_sha256'=>$sources['sha256'],'journal'=>$j,'book_calculation'=>$book,'tax_calculation'=>$tax,'book_fixed_lines'=>$bookLines,'tax_fixed_lines'=>$taxLines,'reversal_pairs'=>$reversals,'export_authorized'=>false];
  return $out+['sha256'=>hash('sha256',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
 }
}
