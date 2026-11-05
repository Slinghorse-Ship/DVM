<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/BalancePoolOriginalValues.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';
require_once __DIR__.'/BalanceReadSnapshot.php';
/** Original invoice population, individual plans and annual imports; never writes books. */
final class BalancePoolStoredSources
{
 public function __construct(private PDO $db,private string $actor){}
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('Sammelposten: vollständige Originalanschaffungen, Einzelpläne und bestätigte Jahresimporte prüfen.');}
 public static function same(array $a,array $b):bool{return PoolOriginalSources::fingerprint($a)===PoolOriginalSources::fingerprint($b);}
 private function q(string $sql,array $args):\PDOStatement{return DocumentCatalog::query($this->db,$sql,$args);}
 private function access(bool $lock):array{$a=Permissions::actor($this->db,$this->actor);self::need(in_array($a['finance_access'],$lock?['write']:['read','write'],true)&&(!$lock||$this->db->inTransaction()));return $a;}
 public function original(string $type,string $id,int $year,bool $lock=false):array{
  $a=$this->access($lock);if($lock)self::need((bool)$this->q('SELECT source_hash FROM balance_sources WHERE club_id=? AND source_type=? AND source_id=? FOR UPDATE',[$a['club_id'],$type,$id])->fetchColumn());$sources=new BalanceSources($this->db,$this->actor);$b=$sources->binding($type,$id);self::need(is_array($b)&&(int)$b['tax_year']===$year);
  $p=$sources->preview($year,$type,$id,$b['options'],$lock);self::need($p['issues']===[]&&hash_equals($b['source_hash'],$p['hash'])&&(bool)(new BalanceSourceHistory($this->db,$this->actor,$lock))->checked($b));return $b;
 }
 public function population(int $year,bool $lock=false):array{
  self::need(in_array($year,[2025,2026],true));$a=$this->access($lock);$suffix=$lock?' FOR UPDATE':'';
  // Include linked GWGs as well as every original fixed-asset position. An
  // unimported or unlinked original cannot disappear from the annual election.
  $rows=$this->q("SELECT DISTINCT e.id,e.document_date,b.tax_year FROM finance_entries e JOIN accounting_positions p ON p.club_id=e.club_id AND p.entry_id=e.id LEFT JOIN invoice_position_items l ON l.club_id=p.club_id AND l.entry_id=p.entry_id AND l.ordinal=p.ordinal LEFT JOIN balance_sources b ON b.club_id=e.club_id AND b.source_type='invoice' AND b.source_id=e.id WHERE e.club_id=? AND e.kind='expense' AND (p.account_category='fixed' OR l.item_id IS NOT NULL) AND (b.tax_year=? OR (b.source_id IS NULL AND e.document_date>=? AND e.document_date<=?)) ORDER BY e.id".$suffix,[$a['club_id'],$year,$year.'-01-01',$year.'-12-31'])->fetchAll();self::need(count($rows)>=1&&count($rows)<=200);$out=[];
  foreach($rows as $entry){$b=$this->original('invoice',$entry['id'],$year,$lock);$s=$b['snapshot'];self::need(!$s['entry']['cancelled']);$positions=[];foreach($s['positions'] as $p)$positions[(int)$p['ordinal']]=$p;$byOrdinal=[];foreach($s['assets'] as $member)$byOrdinal[(int)$member['ordinal']][]=$member;
   foreach($positions as $ordinal=>$p){if($p['account_category']!=='fixed'&&!isset($byOrdinal[$ordinal]))continue;self::need(count($byOrdinal[$ordinal]??[])===1);$member=$byOrdinal[$ordinal][0];$id=$member['item_id'];self::need(!isset($out[$id])&&$member['ownership']==='club'&&is_string($member['payload']));$plan=json_decode($member['payload'],true,512,JSON_THROW_ON_ERROR);$gross=(int)$p['gross_cents'];$vat=(int)$p['tax_cents'];$deduct=$p['input_tax']==='deductible'?$vat:0;self::need($gross>0&&$gross>=$vat&&$plan['acquired_on']===$b['options']['recognised_on']&&$plan['basis_cents']===$gross-$deduct);
    $out[$id]=['item_id'=>$id,'invoice_id'=>$entry['id'],'invoice_ordinal'=>$ordinal,'acquired_on'=>$plan['acquired_on'],'transaction_kind'=>'purchase','quantity'=>1,'net_cents'=>$gross-$vat,'vat_cents'=>$vat,'deductible_cents'=>$deduct,'basis_cents'=>$gross-$deduct,'plan'=>$plan,'plan_version'=>(int)$member['version'],'binding'=>$b,'label'=>$p['description']];
   }
  }ksort($out,SORT_STRING);return $out;
 }
 public function checkPopulation(array $source,bool $lock=false):void{
  $a=$this->access($lock);self::need($source['club_id']===$a['club_id']);[$cost,$eligible,$excluded]=PoolOriginalSources::population($source);$actual=$this->population($source['formed_year'],$lock);self::need(count($actual)===count($source['population']));$e=new AssetValuationEvidence($this->db,$this->actor);$e->check($source['population_document'],$lock);$e->check($source['election_document'],$lock);
  foreach($source['population'] as $r){$o=$actual[$r['item_id']]??null;self::need(is_array($o));foreach(['item_id','invoice_id','invoice_ordinal','acquired_on','transaction_kind','quantity','net_cents','vat_cents','deductible_cents','basis_cents'] as $key)self::need($r[$key]===$o[$key]);$e->acquisition($r['document'],$r['invoice_id'],$lock);$p=$o['plan'];$current=$this->q('SELECT version,payload FROM asset_book_plans WHERE club_id=? AND item_id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$r['item_id']])->fetch();self::need($current&&(int)$current['version']===$o['plan_version']&&self::same(json_decode($current['payload'],true,512,JSON_THROW_ON_ERROR),$p));
   if(in_array($r['item_id'],$eligible,true))self::need($p['account']==='06750'&&$p['sphere']==='4'&&($p['method']??'linear_monthly')==='linear_monthly'&&$p['months']===60&&$p['residual_cents']===0&&$p['acquired_on']===$source['formed_year'].'-01-01'&&!isset($p['depreciation_code']));else self::need($p['account']!=='06750');
  }
 }
 public function annual(array $report,array $source,bool $lock=false):array{
  $a=$this->access($lock);$out=[];[$cost,$eligible]=PoolOriginalSources::population($source);$population=$this->population($source['formed_year'],$lock);
  foreach($eligible as $id){$item=$report['asset_schedule']['items'][$id]??null;self::need(is_array($item));$p=$population[$id];$b=$this->original('depreciation',$id.':'.$report['year'],$report['year'],$lock);$row=$this->q('SELECT * FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$id,$report['year']])->fetch();self::need($row&&(int)$row['plan_version']===$p['plan_version']);$snap=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);self::need(self::same($snap,$p['plan'])&&$b['snapshot']['annual']['snapshot']===$row['snapshot']&&$b['source_hash']===$item['depreciation_hash']&&$p['binding']['source_hash']===$item['acquisition_hash']);
   foreach(['opening_cents'=>$item['book_opening_cents']+$item['additions_cents'],'depreciation_cents'=>$item['depreciation_cents'],'closing_cents'=>$item['book_closing_cents']] as $key=>$n)self::need((int)$row[$key]===$n);self::need(count($b['entry_ids'])===1);$out[$id]=$b;
  }return $out;
 }
 public function actual(array $report):void{self::need(self::same(BalanceWholeClubReport::fromReport((new BalanceLedger($this->db,$this->actor))->report($report['year'])),$report));}
 public function evidence(array $pair,bool $lock=false,int $depth=0):void{
  self::need($depth>=0&&$depth<2);$b=$pair['books'];$t=$pair['fiscal'];self::need(self::same(PoolOriginalBookJournals::verify($b['book_proof'],$b['journal'],$b['previous']),$b)&&self::same(PoolOriginalJournals::verify($t['pool_proof'],$t['journal'],$t['previous']),$t));$source=$t['pool_proof']['source'];$this->checkPopulation($source,$lock);$e=new AssetValuationEvidence($this->db,$this->actor);$e->check($t['pool_proof']['register']['document'],$lock);$e->check($b['book_proof']['book_source']['document'],$lock);foreach($b['book_proof']['book_source']['items'] as $r)$e->check($r['document'],$lock);foreach([$b,$t] as $p){$e->check($p['journal']['document'],$lock);foreach($p['journal']['entries'] as $row)if(isset($row['document']))$e->check($row['document'],$lock);}
  $report=BalanceWholeClubReport::fromReport((new BalanceLedger($this->db,$this->actor))->report($t['journal']['year']));BalancePoolOriginalValues::bind($report,[$source['pool_id']=>$pair]);$this->annual($report,$source,$lock);
  if($b['previous']!==null||$t['previous']!==null){self::need(is_array($b['previous'])&&is_array($t['previous']));$this->evidence(['books'=>$b['previous'],'fiscal'=>$t['previous']],$lock,$depth+1);$prior=(new BalanceLedger($this->db,$this->actor))->report($report['year']-1);self::need($prior['closed_sha256']===$report['previous_sha256']);}
 }
 public function check(array $report,array $review,?array $carry=null):void{
  if(empty($review['pool_original_sources']))return;BalanceReadSnapshot::run($this->db,function()use($report,$review,$carry){$this->actual($report);self::need(count($review['pool_original_sources'])===1);$pair=reset($review['pool_original_sources']);$this->evidence($pair);$previous=null;
   if($report['previous_sha256']!==null){self::need(is_string($carry['filing_id']??null));$r=(new TaxFilings($this->db,$this->actor))->get($carry['filing_id']);$b=$r['form_data']['balance_preparation']??null;self::need($r['state']==='sent_test'&&$r['environment']==='test'&&$r['family']==='balance'&&$r['data_version']==='Bilanz_6_9'&&(int)$r['tax_year']===$report['year']-1&&is_array($b)&&$b['journal_sha256']===$report['previous_sha256']&&$b['profile']['kind']==='whole_taxable_club');(new BalanceTaxFiling($this->db,$this->actor))->assertCurrent($r);BalanceTaxFiling::assertHistoricalApproval($r);self::need((bool)array_filter((new TaxFilings($this->db,$this->actor))->attempts($r['id']),static fn($a)=>!empty($a['receipt_bound'])));$previous=$b['review']['pool_original_sources'][$pair['fiscal']['pool_proof']['source']['pool_id']]??null;self::need(is_array($previous));}
   self::need(self::same(['books'=>$pair['books']['previous'],'fiscal'=>$pair['fiscal']['previous']],['books'=>$previous['books']??null,'fiscal'=>$previous['fiscal']??null]));BalancePoolOriginalValues::bind($report,$review['pool_original_sources']);
  });
 }
}
