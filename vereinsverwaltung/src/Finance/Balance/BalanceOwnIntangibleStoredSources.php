<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
require_once __DIR__.'/BalanceReadSnapshot.php';
require_once __DIR__.'/BalanceWholeClubReport.php';
require_once __DIR__.'/BalanceOwnIntangibleValues.php';
require_once __DIR__.'/BalanceSegments.php';
require_once __DIR__.'/BalanceClearings.php';
/** Read actual plans, annual values, original source history and retained prior filings. */
final class BalanceOwnIntangibleStoredSources
{
 public function __construct(private PDO $db,private string $actor){}
 private function need(bool $ok):void{BalanceOwnIntangibleValues::need($ok);}
 public function bookLinks(array $report,string $id):array{
  $a=Permissions::actor($this->db,$this->actor);$this->need(in_array($a['finance_access'],['read','write'],true));$item=$report['asset_schedule']['items'][$id]??null;
  $this->need(is_array($item)&&($item['method']??null)===OwnIntangiblePlans::METHOD);$proof=$item['own_book'];$source=$proof['source_proof']['source'];$this->need($source['club_id']===$a['club_id']&&$source['item_id']===$id);
  (new OwnIntangibleEvidence($this->db,$this->actor))->checkBook($proof);OwnIntangiblePlans::allocation($this->db,$this->actor,$proof['source_proof'],false);
  $plan=DocumentCatalog::query($this->db,'SELECT version,payload FROM asset_book_plans WHERE club_id=? AND item_id=?',[$a['club_id'],$id])->fetch();$this->need((bool)$plan);
  $data=json_decode($plan['payload'],true,512,JSON_THROW_ON_ERROR);$this->need(BalanceOwnIntangibleValues::same($data,$item['own_plan']));
  foreach($source['costs'] as $cost){
   $raw=DocumentCatalog::query($this->db,'SELECT posting_lines FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?',[$a['club_id'],$report['year'],$cost['journal_id']])->fetchColumn();$this->need(is_string($raw));$lines=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
   $this->need(TaxCatalog::sphereOf($lines[$cost['line_ordinal']]['sphere'])===$data['sphere']);
  }
  $book=$proof;$depth=0;$links=[];
  do{
   $this->need(++$depth<=2);$year=(int)substr($book['book_source']['period']['start'],0,4);$this->need($book['book_source']['period']===BalancePeriod::calendar($year));
   $row=DocumentCatalog::query($this->db,'SELECT * FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?',[$a['club_id'],$id,$year])->fetch();$this->need($row&&(int)$row['plan_version']===(int)$plan['version']);
   $snap=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$this->need(BalanceOwnIntangibleValues::same($snap['plan'],$data)&&BalanceOwnIntangibleValues::same(OwnIntangiblePlans::snapshot($snap),$book));
   $h=$book['hgb'];foreach(['opening_cents'=>$h['book_opening_cents']+$h['additions_cents'],'depreciation_cents'=>$h['amortisation_cents'],'closing_cents'=>$h['book_closing_cents']] as $key=>$n)$this->need((int)$row[$key]===$n);
   $sources=new BalanceSources($this->db,$this->actor);$binding=$sources->binding('depreciation',$id.':'.$year);$fresh=$sources->preview($year,'depreciation',$id.':'.$year);
   $this->need($binding&&!$fresh['issues']&&hash_equals($binding['source_hash'],$fresh['hash'])&&$binding['snapshot']['fixed_account']===$item['account']&&count((new BalanceSourceHistory($this->db,$this->actor))->checked($binding))>0);
   $ids=$binding['entry_ids'];$events=$binding['snapshot']['own_event_ids'];$this->need(count($ids)===count($events));
   if($year===$report['year']){$this->need($binding['source_hash']===$item['depreciation_hash']&&$events===$item['own_event_ids']);foreach($ids as $ordinal=>$voucher)$links[$voucher]=$events[$ordinal];}
   $book=$book['previous'];
  }while($book!==null);
  $book=$proof;while($book['previous']!==null)$book=$book['previous'];$this->need(BalanceOwnIntangibleValues::same(OwnIntangiblePlans::original($data),$book['source_proof']['source']));
  return $links;
 }
 public function actual(array $report):void{
  $fresh=BalanceWholeClubReport::fromReport((new BalanceLedger($this->db,$this->actor))->report($report['year']));$this->need(BalanceOwnIntangibleValues::same($fresh,BalanceOwnIntangibleValues::journalReport($report)));
  if(isset($report['club_scope']))$this->need(BalanceOwnIntangibleValues::same($this->club($report['year']),$report));
 }
 private function club(int $year,int $depth=0):array{
  $this->need($depth<2);(new BalanceClearings($this->db,$this->actor))->assertExportable($year);$state=(new BalanceSegments($this->db,$this->actor))->get($year);$previous=$state['journal']['previous_sha256']===null?null:$this->club($year-1,$depth+1);return BalanceClubTaxReport::fromState($state,$previous);
 }
 public function check(array $report,array $review,?array $carry=null):void{
  if(empty($review['own_intangible_sources']))return;
  BalanceReadSnapshot::run($this->db,function()use($report,$review,$carry){
   $this->actual($report);$id=BalanceOwnIntangibleValues::scope($report,$review);$this->need(array_keys($review['own_intangible_sources'])===[$id]);$p=$review['own_intangible_sources'][$id];
   $links=$this->bookLinks($report,$id);$this->need(BalanceOwnIntangibleValues::same($links,(array)$p['journal']['book_links']));(new OwnIntangibleEvidence($this->db,$this->actor))->checkJournals($p);
   $previous=null;
   if($report['previous_sha256']!==null){
    $this->need(is_string($carry['filing_id']??null));$r=(new TaxFilings($this->db,$this->actor))->get($carry['filing_id']);$b=$r['form_data']['balance_preparation']??null;
    $this->need($r['state']==='sent_test'&&$r['environment']==='test'&&$r['family']==='balance'&&$r['data_version']==='Bilanz_6_9'&&(int)$r['tax_year']===$report['year']-1&&is_array($b)&&$b['journal_sha256']===$report['previous_sha256']&&in_array($b['profile']['kind'],isset($report['club_scope'])?['club_taxable_businesses',BalanceTaxFiling::COMBINED]:['whole_taxable_club'],true));
    (new BalanceTaxFiling($this->db,$this->actor))->assertCurrent($r);BalanceTaxFiling::assertHistoricalApproval($r);
    $this->need((bool)array_filter((new TaxFilings($this->db,$this->actor))->attempts($r['id']),static fn($attempt)=>!empty($attempt['receipt_bound'])));
    $previous=$b['review']['own_intangible_sources'][$id]??null;$this->need(is_array($previous));
   }
   $this->need(BalanceOwnIntangibleValues::same($p['previous'],$previous));
   $ordinary=$review;unset($ordinary['own_intangible_sources']);$ordinary['complete']=true;$v=BalanceTaxValues::build($report,$ordinary);BalanceOwnIntangibleValues::bind($report,$review,$v['book'],$v['tax']);
  });
 }
}
