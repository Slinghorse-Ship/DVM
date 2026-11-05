<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/OwnIntangiblePlans.php';

/** Original recorded HGB events become dated source vouchers, without a purchase invoice. */
final class BalanceOwnIntangibleSources
{
 public function __construct(private PDO $db,private string $actor){}
 private static function need(bool $ok):void{if(!$ok)throw new DomainException('Originalregister, Entwicklungskosten und HGB-Bilanzquelle vollständig abstimmen.');}
 private function q(string $sql,array $args,bool $lock):\PDOStatement{return DocumentCatalog::query($this->db,$sql.($lock?' FOR UPDATE':''),$args);}
 public function annual(array &$p,array $row,bool $lock):void{
  $a=Permissions::actor($this->db,$this->actor);$club=$a['club_id'];$snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$value=OwnIntangiblePlans::snapshot($snapshot);$plan=$snapshot['plan'];$source=$value['source_proof']['source'];$h=$value['hgb'];
  self::need($source['club_id']===$club&&$source['item_id']===$row['item_id']&&$source['period']===BalancePeriod::calendar($p['year']));
  $stored=$this->q('SELECT version,payload FROM asset_book_plans WHERE club_id=? AND item_id=?',[$club,$row['item_id']],$lock)->fetch();
  self::need($stored&&(int)$stored['version']===(int)$row['plan_version']&&json_decode($stored['payload'],true,512,JSON_THROW_ON_ERROR)===$plan);
  foreach(['opening_cents'=>$h['book_opening_cents']+$h['additions_cents'],'depreciation_cents'=>$h['amortisation_cents'],'closing_cents'=>$h['book_closing_cents']] as $key=>$n)self::need((int)$row[$key]===$n);
  (new OwnIntangibleEvidence($this->db,$this->actor))->checkBook($value,$lock);OwnIntangiblePlans::allocation($this->db,$this->actor,$value['source_proof'],$lock);
  $journal=$this->q('SELECT accounts FROM balance_years WHERE club_id=? AND tax_year=?',[$club,$p['year']],$lock)->fetchColumn();self::need(is_string($journal));$chart=BalanceCalculation::chart(json_decode($journal,true,512,JSON_THROW_ON_ERROR));
  $catalog=TaxCatalog::accounts($p['year']);$code=$source['account'];$fixed=self::account($p,$chart,$catalog,$code,'fixed_assets');$sources=new BalanceSources($this->db,$this->actor);
  if($value['previous']!==null){
   $prior=$this->q('SELECT snapshot FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?',[$club,$row['item_id'],$p['year']-1],$lock)->fetchColumn();self::need(is_string($prior));
   self::need(OwnIntangibleBookBasis::fingerprint(OwnIntangiblePlans::snapshot(json_decode($prior,true,512,JSON_THROW_ON_ERROR)))===OwnIntangibleBookBasis::fingerprint($value['previous']));
   $id=$row['item_id'].':'.($p['year']-1);$binding=$sources->binding('depreciation',$id);$fresh=$sources->preview($p['year']-1,'depreciation',$id,[],$lock);
   self::need($binding&&!$fresh['issues']&&hash_equals($binding['source_hash'],$fresh['hash'])&&($binding['snapshot']['fixed_account']??null)===$fixed);
   self::need(count((new BalanceSourceHistory($this->db,$this->actor,$lock))->checked($binding))>0);
  }
  $originals=[];foreach($source['costs'] as $cost){$entry=$this->q('SELECT id,booked_on,description,evidence,posting_lines,reversal_of FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$club,$p['year'],$cost['journal_id']],$lock)->fetch();self::need((bool)$entry&&$entry['reversal_of']===null);$originals[$entry['id']]=$entry;}
  ksort($originals,SORT_STRING);$p['snapshot']=['annual'=>$row,'plan_version'=>(int)$stored['version'],'fixed_account'=>$fixed,'own_book'=>$value,'original_cost_entries'=>$originals];
  $p['evidence']='Originales HGB-Entwicklungsregister '.$row['item_id'].' · '.$p['year'];self::vouchers($p,$plan,$value,$fixed,$chart);
 }
 private static function account(array &$p,array $chart,array $catalog,string $code,string $group):string{
  self::need(!empty($catalog[$code]['active']));$candidates=array_keys(array_filter($chart,static fn($a)=>$a['skr_code']===$code));self::need(count($candidates)<=1);
  $key=$candidates[0]??('src_own_hgb_'.$code);$account=$chart[$key]??['name'=>$catalog[$code]['name'],'group'=>$group,'skr_code'=>$code];self::need($account['group']===$group);$p['accounts'][$key]=$account;return $key;
 }
 public static function vouchers(array &$p,array $plan,array $value,string $fixed,array $chart):void{
  self::need(OwnIntangiblePlans::original($plan)['item_id']===$value['source_proof']['source']['item_id']&&OwnIntangibleBookBasis::fingerprint(OwnIntangibleBookBasis::calculate($value['source_proof'],$value['book_source'],$value['previous']))===OwnIntangibleBookBasis::fingerprint($value)&&$value['book_source']['period']===BalancePeriod::calendar($p['year']));
  $catalog=TaxCatalog::accounts($p['year']);$map=[];$ids=[];
  foreach($value['events'] as $trace){$event=$trace['original'];$up=in_array($event['kind'],['capitalisation','writeup'],true);$code=$event['counter_code'];$key=self::account($p,$chart,$catalog,$code,$up?'revenue':'expense');$map[$code]=$key;$n=$event['amount_cents'];
   $p['vouchers'][]=['date'=>$event['on'],'description'=>mb_substr('HGB-Entwicklungsprojekt · '.$event['reason'],0,500),'lines'=>BalanceCalculation::lines([
    ['account'=>$fixed,'debit_cents'=>$up?$n:0,'credit_cents'=>$up?0:$n,'sphere'=>''],
    ['account'=>$key,'debit_cents'=>$up?0:$n,'credit_cents'=>$up?$n:0,'sphere'=>$plan['sphere']]],$p['accounts'])];$ids[]=$event['id'];
  }
  $p['snapshot']['own_accounts']=$map;$p['snapshot']['own_event_ids']=$ids;
 }
 public static function item(array $plan,array $binding,int $year):array{
  $snap=json_decode($binding['snapshot']['annual']['snapshot'],true,512,JSON_THROW_ON_ERROR);$value=OwnIntangiblePlans::snapshot($snap);$h=$value['hgb'];$s=$value['source_proof']['source'];
  self::need($snap['plan']===$plan&&$s['period']===BalancePeriod::calendar($year)&&($binding['snapshot']['own_book']['sha256']??null)===$value['sha256']);
  return ['item_id'=>$s['item_id'],'invoice_id'=>null,'receipt_number'=>'Originales Entwicklungsregister','account'=>$binding['snapshot']['fixed_account'],'skr_code'=>$s['account'],'sphere'=>$plan['sphere'],
   'acquired_on'=>$plan['acquired_on'],'date_role'=>$plan['date_role'],'months'=>$plan['months'],'residual_cents'=>0,'method'=>OwnIntangiblePlans::METHOD,'own_plan'=>$plan,'own_book'=>$value,
   'own_accounts'=>$binding['snapshot']['own_accounts'],'own_event_ids'=>$binding['snapshot']['own_event_ids'],'acquisition_hash'=>null,'depreciation_hash'=>$binding['source_hash'],
   'cost_opening_cents'=>$h['cost_opening_cents'],'cost_closing_cents'=>$h['cost_closing_cents'],'additions_cents'=>$h['additions_cents'],
   'accumulated_opening_cents'=>$h['amort_opening_cents']+$h['impairment_opening_cents'],'accumulated_closing_cents'=>$h['amort_closing_cents']+$h['impairment_closing_cents'],
   'book_opening_cents'=>$h['book_opening_cents'],'book_closing_cents'=>$h['book_closing_cents'],'depreciation_cents'=>$h['amortisation_cents'],'expected_depreciation_cents'=>$h['amortisation_cents'],
   'impairment_cents'=>$h['impairment_cents'],'writeup_cents'=>$h['writeup_cents'],'disposals_cents'=>$h['disposed_cost_cents'],'accumulated_disposals_cents'=>$h['disposed_amort_cents']+$h['disposed_impairment_cents'],'book_disposals_cents'=>$h['disposed_book_cents']];
 }
 public static function checkFixed(array $calculation,array $items):void{
  $totals=[];foreach($items as $item)if(($item['method']??null)===OwnIntangiblePlans::METHOD){$value=$item['own_book'];self::need(OwnIntangibleBookBasis::fingerprint(OwnIntangibleBookBasis::calculate($value['source_proof'],$value['book_source'],$value['previous']))===OwnIntangibleBookBasis::fingerprint($value));$h=$value['hgb'];$key=$item['account'];
   foreach(['opening_cents'=>$h['book_opening_cents'],'debit_cents'=>$h['additions_cents']+$h['writeup_cents'],'credit_cents'=>$h['amortisation_cents']+$h['impairment_cents']+$h['disposed_book_cents'],'closing_cents'=>$h['book_closing_cents']] as $field=>$n)$totals[$key][$field]=($totals[$key][$field]??0)+$n;
  }foreach($totals as $key=>$row)foreach($row as $field=>$n)self::need(($calculation['accounts'][$key][$field]??null)===$n);
 }
}
