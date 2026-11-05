<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/PartnershipBookPlans.php';
/** Stored HGB register -> original dated valuation journal; never a fiscal value. */
final class BalancePartnershipBookSources
{
 public function __construct(private PDO $db,private string $actor){}
 public function annual(array &$p,array $row,bool $lock):void{
  $a=Permissions::actor($this->db,$this->actor);$club=$a['club_id'];$snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);
  $value=PartnershipBookPlans::snapshot($snapshot);$plan=$snapshot['plan'];$source=$value['source'];$v=$value['book'];
  if($source['period']!==BalancePeriod::calendar($p['year'])||$source['plan']['holding_id']!==$row['item_id']||$source['plan']['club_id']!==$club)throw new DomainException('HGB-Register und bestätigter ursprünglicher Gegenstand stimmen nicht überein.');
  foreach(['opening_cents'=>$v['book_opening_cents']+$v['additions_cents'],'depreciation_cents'=>0,'closing_cents'=>$v['book_closing_cents']] as $name=>$expected)if((int)$row[$name]!==$expected)throw new DomainException('Gespeicherter HGB-Jahreswert wurde verändert.');
  PartnershipBookPlans::evidence($this->db,$this->actor,$plan,$value,$lock);$sources=new BalanceSources($this->db,$this->actor);
  if($value['previous']!==null){
   $year=$p['year']-1;$id=$row['item_id'].':'.$year;
   $prior=DocumentCatalog::query($this->db,'SELECT snapshot FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?'.($lock?' FOR UPDATE':''),[$club,$row['item_id'],$year])->fetch();
   if(!$prior||PartnershipBookPlans::snapshot(json_decode($prior['snapshot'],true,512,JSON_THROW_ON_ERROR))!==$value['previous'])throw new DomainException('HGB-Vortrag gehört nicht zum unveränderten gespeicherten Vorjahr.');
   $binding=$sources->binding('depreciation',$id);$fresh=$sources->preview($year,'depreciation',$id,[],$lock);
   if(!$binding||$fresh['issues']||!hash_equals($binding['source_hash'],$fresh['hash']))throw new DomainException('Vorheriges HGB-Register zuerst vollständig ins ursprüngliche Bilanzjournal übernehmen.');
   (new BalanceSourceHistory($this->db,$this->actor))->checked($binding);
  }
  $links=DocumentCatalog::query($this->db,'SELECT entry_id FROM invoice_position_items WHERE club_id=? AND item_id=?'.($lock?' FOR UPDATE':''),[$club,$row['item_id']])->fetchAll();
  if(count($links)!==1)throw new DomainException('HGB-Beteiligung genau einer ursprünglichen Anschaffung zuordnen.');
  $binding=$sources->binding('invoice',$links[0]['entry_id']);if(!$binding||(int)$binding['tax_year']>$p['year'])throw new DomainException('Ursprüngliche Anschaffung zuerst in das Bilanzjournal übernehmen.');
  $fresh=$sources->preview((int)$binding['tax_year'],'invoice',$links[0]['entry_id'],$binding['options'],$lock);
  if($fresh['issues']||!hash_equals($binding['source_hash'],$fresh['hash']))throw new DomainException('Ursprüngliche Anschaffung wurde verändert.');
  (new BalanceSourceHistory($this->db,$this->actor))->checked($binding);
  $fixed=$binding['snapshot']['asset_accounts'][$row['item_id']]??null;$original=array_values(array_filter($binding['snapshot']['assets'],static fn($asset)=>$asset['item_id']===$row['item_id']))[0]??null;
  if(!is_string($fixed)||!$original||json_decode($original['payload'],true,512,JSON_THROW_ON_ERROR)!==$plan||(int)$original['version']!==(int)$row['plan_version'])throw new DomainException('Originalplan, festgestellte Version und Beteiligungskonto vollständig nachweisen.');
  $p['snapshot']=['annual'=>$row,'invoice_id'=>$binding['source_id'],'acquisition_sha256'=>$binding['source_hash'],'fixed_account'=>$fixed,'partnership_book'=>$value];
  $p['accounts']=[$fixed=>$fresh['accounts'][$fixed]];$p['evidence']='Ursprüngliches HGB-Beteiligungsregister '.$row['item_id'].' · '.$p['year'];self::vouchers($p,$plan,$value,$fixed);
 }
 public static function vouchers(array &$p,array $plan,array $value,string $fixed):void{
  if(PartnershipBookPlans::original($plan)!==$value['source']['plan']||PartnershipBookBasis::calculate($value['source'],$value['previous'])!==$value||$value['source']['period']!==BalancePeriod::calendar($p['year']))throw new DomainException('Ursprüngliches HGB-Register nachrechnen.');
  $catalog=TaxCatalog::accounts($p['year']);$accounts=[];$events=[];foreach($value['source']['events'] as $e)$events[$e['id']]=$e;
  foreach($value['movements'] as $movement){
   $event=$events[$movement['event_id']];$kind=$event['kind'];
   if($kind==='addition')throw new DomainException('Kapitalzuführung durch die ursprüngliche Anschaffungsrechnung buchen; keinen bezahlten Gegenposten aus dem HGB-Register erfinden.');
   $up=$kind==='writeup';$code=$up?'49120':($kind==='disposal'?'68970':($event['permanent']?'72000':'72010'));$amount=abs($movement['book_change_cents']);
   if(!($catalog[$code]['active']??false))throw new DomainException('Ursprüngliches HGB-Gegenkonto des Abschlussjahrs fehlt.');
   $key='src_partnership_hgb_'.$code;$p['accounts'][$key]=['name'=>$catalog[$code]['name'],'group'=>$up?'revenue':'expense','skr_code'=>$code];$accounts[$code]=$key;
   if($amount>0)$p['vouchers'][]=['date'=>$movement['on'],'description'=>mb_substr('HGB-Beteiligung · '.$event['reason'],0,500),'lines'=>BalanceCalculation::lines([
    ['account'=>$fixed,'debit_cents'=>$up?$amount:0,'credit_cents'=>$up?0:$amount,'sphere'=>''],
    ['account'=>$key,'debit_cents'=>$up?0:$amount,'credit_cents'=>$up?$amount:0,'sphere'=>$plan['sphere']]],$p['accounts'])];
  }$p['snapshot']['partnership_accounts']=$accounts;
 }
 public static function item(array $plan,array $acquisition,string $id,string $fixed,int $year,?array $annual):array{
  $p=PartnershipBookPlans::original($plan);$value=null;$v=array_fill_keys(PartnershipBookBasis::FIELDS,0);
  if($annual!==null){$snapshot=json_decode($annual['snapshot']['annual']['snapshot'],true,512,JSON_THROW_ON_ERROR);$value=PartnershipBookPlans::snapshot($snapshot);
   if($snapshot['plan']!==$plan||$value['source']['period']!==BalancePeriod::calendar($year))throw new DomainException('Originalplan, HGB-Jahresstand und Abschlussjahr stimmen nicht überein.');$v=$value['book'];
  }elseif($year===(int)substr($p['acquired_on'],0,4)){$v['additions_cents']=$v['cost_closing_cents']=$v['book_closing_cents']=$p['basis_cents'];}
  // Missing yearly values stay missing; no carried acquisition-cost substitute.
  return ['item_id'=>$id,'invoice_id'=>$acquisition['source_id'],'receipt_number'=>$acquisition['snapshot']['entry']['display_number'],'account'=>$fixed,'skr_code'=>$p['account'],
   'sphere'=>$p['sphere'],'acquired_on'=>$p['acquired_on'],'months'=>0,'residual_cents'=>0,'depreciation_cents'=>0,'expected_depreciation_cents'=>0,'method'=>PartnershipBookPlans::METHOD,
   'partnership_plan'=>$plan,'partnership_book'=>$value,'acquisition_hash'=>$acquisition['source_hash'],'depreciation_hash'=>$annual['source_hash']??null,
   'partnership_accounts'=>$annual['snapshot']['partnership_accounts']??[]]+$v;
 }
 public static function checkFixed(array $calculation,array $items):void{
  $expected=[];foreach($items as $item)if(($item['method']??null)===PartnershipBookPlans::METHOD){
   $b=$item['partnership_book'];if($b===null)throw new DomainException('Ursprüngliches HGB-Jahresregister fehlt.');$p=PartnershipBookPlans::original($item['partnership_plan']);
   if($p!==$b['source']['plan']||PartnershipBookBasis::calculate($b['source'],$b['previous'])!==$b)throw new DomainException('HGB-Jahresregister wurde verändert.');
   foreach(PartnershipBookBasis::FIELDS as $name)if(($item[$name]??null)!==$b['book'][$name])throw new DomainException('HGB-Anlagenverzeichnis stimmt nicht mit dem Originalregister überein.');
   $key=$item['account'];$row=$b['book'];$wanted=['opening_cents'=>$row['book_opening_cents'],'debit_cents'=>$row['additions_cents']+$row['writeup_cents'],'credit_cents'=>$row['impairment_cents']+$row['book_disposals_cents'],'closing_cents'=>$row['book_closing_cents']];
   foreach($wanted as $name=>$n)$expected[$key][$name]=($expected[$key][$name]??0)+$n;
  }
  foreach($expected as $key=>$row)foreach($row as $name=>$n)if(($calculation['accounts'][$key][$name]??null)!==$n)throw new DomainException('Sämtliche HGB-Bruttobewegungen auf dem ursprünglichen Beteiligungskonto abstimmen.');
 }
}
