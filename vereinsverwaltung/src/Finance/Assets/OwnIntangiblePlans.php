<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once __DIR__.'/OwnIntangibleEvidence.php';

/** Original development register. HGB preparation never implies a fiscal assessment. */
final class OwnIntangiblePlans
{
 public const METHOD='own_intangible_hgb';
 private static function need(bool $ok):void{if(!$ok)throw new DomainException('Entwicklungsprojekt, Kostenquellen und ursprüngliche HGB-Jahreswerte vollständig prüfen.');}
 public static function input(array $input):array{
  self::need(($input['asset_reviewed']??null)==='1'&&($input['depreciation_code']??'')===''&&($input['financial_profile']??'')===''&&is_array($input['own_source']??null));
  $s=$input['own_source'];$proof=OwnIntangibleOriginalSources::calculate($s);
  self::need(is_string($input['sphere']??null)&&isset(TaxCatalog::SPHERES[$input['sphere']])&&$input['sphere']!=='9'&&$s['period']['start']<=date('Y-m-d'));
  foreach($s['costs'] as $cost)self::need($cost['on']<=date('Y-m-d'));
  if($s['ready_on']!==null)self::need($s['ready_on']<=date('Y-m-d'));
  // Common register date is a period anchor, explicitly labelled as such; no acquisition is invented.
  return ['basis_cents'=>$proof['hgb_original_cost_addition_cents'],'residual_cents'=>0,'acquired_on'=>$s['period']['start'],'date_role'=>'original_cost_period_start',
   'months'=>$s['life_months']??0,'sphere'=>$input['sphere'],'account'=>$s['account'],'reason'=>$s['book_reason'],'method'=>self::METHOD,'own_source'=>$s];
 }
 public static function original(array $plan):array{
  self::need(($plan['method']??null)===self::METHOD&&is_array($plan['own_source']??null));
  self::need(OwnIntangibleOriginalSources::fingerprint(self::input(['asset_reviewed'=>'1','sphere'=>$plan['sphere'],'own_source'=>$plan['own_source']]))===OwnIntangibleOriginalSources::fingerprint($plan));
  return $plan['own_source'];
 }
 public static function snapshot(mixed $snapshot):array{
  self::need(is_array($snapshot)&&array_keys($snapshot)===['plan','own_book']&&is_array($snapshot['plan'])&&is_array($snapshot['own_book']));
  $s=self::original($snapshot['plan']);$b=$snapshot['own_book'];
  $expected=OwnIntangibleBookBasis::calculate($b['source_proof'],$b['book_source'],$b['previous']);self::need(OwnIntangibleBookBasis::fingerprint($expected)===OwnIntangibleBookBasis::fingerprint($b));$b=$expected;
  $first=$b;while($first['previous']!==null)$first=$first['previous'];
  self::need(OwnIntangibleOriginalSources::fingerprint($first['source_proof']['source'])===OwnIntangibleOriginalSources::fingerprint($s));
  return $b;
 }
 /** Actual stored journal amount/date are read here, never trusted from submitted totals. */
 public static function formCosts(PDO $db,string $actor,array $input,int $year,bool $lock):array{
  $rows=$input['own_costs']??[];self::need(is_array($rows)&&array_is_list($rows)&&count($rows)<=200);$a=Permissions::actor($db,$actor);
  self::need(in_array($a['finance_access'],$lock?['write']:['read','write'],true)&&(!$lock||$db->inTransaction()));$out=[];$docs=new AssetValuationEvidence($db,$actor);
  foreach($rows as $r){
   self::need(is_array($r)&&!array_diff(array_keys($r),['id','source_line','journal_id','line_ordinal','phase','category','attributed','document_id','reason','confirmed']));
   if(array_key_exists('source_line',$r)){
    self::need(!array_key_exists('journal_id',$r)&&!array_key_exists('line_ordinal',$r)&&is_string($r['source_line']));
    if($r['source_line']==='')continue;self::need(preg_match('/^([a-f0-9]{32}):(0|[1-9][0-9]{0,3})$/D',$r['source_line'],$parts)===1);$r['journal_id']=$parts[1];$r['line_ordinal']=$parts[2];
   }
   if(($r['journal_id']??'')==='')continue;
   foreach(['id','journal_id','line_ordinal','phase','category','attributed','document_id','reason'] as $key)self::need(is_string($r[$key]??null));
   self::need(preg_match('/^[a-f0-9]{32}$/D',$r['journal_id'])===1&&preg_match('/^(0|[1-9][0-9]{0,3})$/D',$r['line_ordinal'])===1);
   $entry=DocumentCatalog::query($db,'SELECT booked_on,posting_lines FROM balance_entries WHERE club_id=? AND tax_year=? AND id=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$year,$r['journal_id']])->fetch();self::need((bool)$entry);
   $lines=json_decode($entry['posting_lines'],true,512,JSON_THROW_ON_ERROR);$ordinal=(int)$r['line_ordinal'];$line=$lines[$ordinal]??null;
   self::need(is_array($line)&&is_int($line['debit_cents']??null)&&($line['credit_cents']??null)===0);
   $out[]=['id'=>$r['id'],'on'=>$entry['booked_on'],'phase'=>$r['phase'],'category'=>$r['category'],'booked_cents'=>$line['debit_cents'],'attributable_cents'=>Finance::cents($r['attributed']),
    'journal_id'=>$r['journal_id'],'line_ordinal'=>$ordinal,'document'=>$docs->reference($r['document_id'],$lock),'reason'=>trim($r['reason']),'confirmed'=>($r['confirmed']??null)==='1'];
  }return $out;
 }
 public static function costChoices(PDO $db,string $actor,int $year):array{
  self::need(in_array($year,[2025,2026],true));$a=Permissions::actor($db,$actor);self::need(in_array($a['finance_access'],['read','write'],true));
  $chart=DocumentCatalog::query($db,'SELECT accounts FROM balance_years WHERE club_id=? AND tax_year=?',[$a['club_id'],$year])->fetchColumn();if(!is_string($chart))return []; $chart=json_decode($chart,true,512,JSON_THROW_ON_ERROR);
  $rows=DocumentCatalog::query($db,'SELECT id,booked_on,description,posting_lines FROM balance_entries e WHERE club_id=? AND tax_year=? AND reversal_of IS NULL AND NOT EXISTS(SELECT 1 FROM balance_entries r WHERE r.club_id=e.club_id AND r.tax_year=e.tax_year AND r.reversal_of=e.id) ORDER BY booked_on,id LIMIT 10001',[$a['club_id'],$year])->fetchAll();self::need(count($rows)<=10000);$out=[];
  foreach($rows as $row)foreach(json_decode($row['posting_lines'],true,512,JSON_THROW_ON_ERROR) as $ordinal=>$line){$account=$chart[$line['account']]??null;
   if($account&&$account['group']==='expense'&&!in_array($account['skr_code'],['62010','62110','68960'],true)&&$line['credit_cents']===0&&$line['debit_cents']>0)$out[$row['id'].':'.$ordinal]=['date'=>$row['booked_on'],'description'=>$row['description'],'account'=>$account['name'],'amount_cents'=>$line['debit_cents']];
  }return $out;
 }
 public static function formSource(PDO $db,string $actor,string $item,array $input,bool $lock):array{
  foreach(['own_year','account','own_product_kind','own_stage','reason','own_document_id'] as $key)self::need(is_string($input[$key]??null));
  self::need(in_array($input['own_year'],['2025','2026'],true)&&in_array($input['own_capitalise']??null,['yes','no'],true));$a=Permissions::actor($db,$actor);$year=(int)$input['own_year'];
  $ready=$input['own_stage']==='ready';$life=null;
  if($ready){self::need(is_string($input['own_life_months']??null)&&preg_match('/^[1-9][0-9]{1,3}$/D',$input['own_life_months'])===1&&is_string($input['own_ready_on']??null));$life=(int)$input['own_life_months'];}
  return ['schema'=>1,'club_id'=>$a['club_id'],'item_id'=>$item,'project_id'=>$item,'account'=>$input['account'],'period'=>BalancePeriod::calendar($year),'product_kind'=>$input['own_product_kind'],
   'own_creation'=>($input['own_creation']??null)==='1','separated_research_development'=>($input['own_separated']??null)==='1','capitalise'=>$input['own_capitalise']==='yes',
   'stage'=>$input['own_stage'],'ready_on'=>$ready?$input['own_ready_on']:null,'life_months'=>$life,'costs'=>self::formCosts($db,$actor,$input,$year,$lock),
   'book_document'=>(new AssetValuationEvidence($db,$actor))->reference($input['own_document_id'],$lock),'book_reason'=>trim($input['reason']),'book_confirmed'=>($input['asset_reviewed']??null)==='1'];
 }
 public static function formEvents(PDO $db,string $actor,array $input,bool $lock):array{
  $rows=$input['own_events']??[];self::need(is_array($rows)&&array_is_list($rows)&&count($rows)<=200);$out=[];$docs=new AssetValuationEvidence($db,$actor);
  foreach($rows as $r){
   self::need(is_array($r)&&!array_diff(array_keys($r),['id','on','kind','amount','counter_code','document_id','reason','confirmed']));if(($r['kind']??'')==='')continue;
   foreach(['id','on','kind','amount','counter_code','document_id','reason'] as $key)self::need(is_string($r[$key]??null));
   $out[]=['id'=>$r['id'],'on'=>$r['on'],'kind'=>$r['kind'],'amount_cents'=>Finance::cents($r['amount']),'counter_code'=>$r['counter_code'],
    'document'=>$docs->reference($r['document_id'],$lock),'reason'=>trim($r['reason']),'confirmed'=>($r['confirmed']??null)==='1'];
  }return $out;
 }
 public static function annual(PDO $db,string $actor,array $plan,int $year,array $input,?array $previous):array{
  self::need(($input['own_year_reviewed']??null)==='1'&&($input['disposal_kind']??'')==='');$original=self::original($plan);$source=$original;
  if($previous!==null){
   self::need(is_string($input['own_document_id']??null)&&is_string($input['own_reason']??null));$source['period']=BalancePeriod::calendar($year);$source['costs']=self::formCosts($db,$actor,$input,$year,true);
   $source['book_document']=(new AssetValuationEvidence($db,$actor))->reference($input['own_document_id'],true);$source['book_reason']=trim($input['own_reason']);$source['book_confirmed']=true;
  }
  self::need($source['period']===BalancePeriod::calendar($year));$proof=OwnIntangibleOriginalSources::calculate($source,$previous['source_proof']??null);
  $book=['schema'=>1,'club_id'=>$source['club_id'],'item_id'=>$source['item_id'],'period'=>$source['period']];
  foreach(['opening_cost','opening_amort','opening_impairment','closing_book'] as $key){self::need(is_string($input['own_'.$key]??null));$book[$key.'_cents']=Finance::cents($input['own_'.$key]);}
  self::need(is_string($input['own_book_document_id']??null)&&is_string($input['own_book_reason']??null));
  $book+=['events'=>self::formEvents($db,$actor,$input,true),'document'=>(new AssetValuationEvidence($db,$actor))->reference($input['own_book_document_id'],true),
   'reason'=>trim($input['own_book_reason']),'confirmed'=>true];$value=OwnIntangibleBookBasis::calculate($proof,$book,$previous);
  (new OwnIntangibleEvidence($db,$actor))->checkBook($value,true);self::allocation($db,$actor,$proof,true);return $value;
 }
 /** Serialized by AssetBooks' club writer lock. Latest annual original replaces its plan's first-year copy. */
 public static function allocation(PDO $db,string $actor,array $proof,bool $lock):void{
  $s=$proof['source'];$a=Permissions::actor($db,$actor);self::need($a['club_id']===$s['club_id']&&(!$lock||$db->inTransaction()));$year=(int)substr($s['period']['start'],0,4);$totals=[];
  $add=static function(array $source)use(&$totals):void{foreach($source['costs'] as $c){$key=$c['journal_id'].':'.$c['line_ordinal'];if(isset($totals[$key]))self::need($totals[$key]['booked']===$c['booked_cents']);$totals[$key]=['booked'=>$c['booked_cents'],'allocated'=>($totals[$key]['allocated']??0)+$c['attributable_cents']];self::need($totals[$key]['allocated']<=$totals[$key]['booked']);}};
  $add($s);$plans=DocumentCatalog::query($db,'SELECT item_id,payload FROM asset_book_plans WHERE club_id=? ORDER BY item_id'.($lock?' FOR UPDATE':''),[$a['club_id']])->fetchAll();
  foreach($plans as $row){if($row['item_id']===$s['item_id'])continue;$plan=json_decode($row['payload'],true,512,JSON_THROW_ON_ERROR);if(($plan['method']??null)!==self::METHOD)continue;$source=self::original($plan);self::need($source['project_id']!==$s['project_id']);
   $annual=DocumentCatalog::query($db,'SELECT snapshot FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?'.($lock?' FOR UPDATE':''),[$a['club_id'],$row['item_id'],$year])->fetchColumn();
   if(is_string($annual)){$snap=json_decode($annual,true,512,JSON_THROW_ON_ERROR);self::need($snap['plan']===$plan);$source=self::snapshot($snap)['source_proof']['source'];}
   if($source['period']!==$s['period'])continue;(new OwnIntangibleEvidence($db,$actor))->checkSources(OwnIntangibleOriginalSources::calculate($source,$annual?self::snapshot($snap)['source_proof']['previous']:null),$lock);$add($source);
  }
 }
}
