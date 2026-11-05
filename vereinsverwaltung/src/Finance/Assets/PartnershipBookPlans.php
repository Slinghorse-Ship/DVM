<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once __DIR__.'/PartnershipBookBasis.php';
require_once __DIR__.'/AssetValuationEvidence.php';
/** Original HGB register only. Fiscal assessments are separate source records. */
final class PartnershipBookPlans
{
 public const METHOD='partnership_hgb';
 public static function input(array $input):array{
  foreach(['basis','acquired_on','sphere','account','reason','holding_id','club_id','partnership_relationship'] as $name)if(!is_string($input[$name]??null))throw new DomainException('Ursprüngliche Beteiligung, Anschaffung und Beziehung vollständig angeben.');
  if(($input['asset_reviewed']??null)!=='1'||($input['depreciation_code']??'')!==''||($input['financial_profile']??'')!=='')throw new DomainException('HGB-Beteiligung gesondert bestätigen; keine gewöhnliche Abschreibung oder Kapitalgesellschaftsbewertung übernehmen.');
  $p=['holding_id'=>$input['holding_id'],'club_id'=>$input['club_id'],'account'=>$input['account'],'basis_cents'=>Finance::cents($input['basis']),
   'acquired_on'=>$input['acquired_on'],'sphere'=>$input['sphere'],'document'=>$input['partnership_document']??null,'reason'=>trim($input['reason']),'confirmed'=>true];
  if($p['acquired_on']>date('Y-m-d'))throw new DomainException('Ursprüngliche Anschaffung kann nicht in der Zukunft liegen.');
  $year=(int)substr($p['acquired_on'],0,4);PartnershipBookBasis::calculate(['schema'=>1,'plan'=>$p,'period'=>BalancePeriod::calendar($year),'events'=>[]]);
  $kind=$input['partnership_relationship'];
  $allowed=in_array($p['account'],['08050','08090'],true)?['parent_company']:(in_array($p['account'],['08000','08030'],true)?['affiliated','parent_company']:['participation']);
  if(!in_array($kind,$allowed,true)||($input['partnership_relationship_reviewed']??null)!=='1')throw new DomainException('Belegte Beziehung und ursprüngliches Beteiligungskonto prüfen.');
  $relationship=['kind'=>$kind,'document'=>$input['partnership_relationship_document']??null,'reason'=>trim($input['partnership_relationship_reason']??''),'confirmed'=>true];
  AssetValuation::document($relationship['document']);if($relationship['reason']===''||mb_strlen($relationship['reason'])>2000||preg_match('/[\x00-\x1f]/',$relationship['reason']))throw new DomainException('Beziehung zur ursprünglichen Beteiligung begründen.');
  return ['basis_cents'=>$p['basis_cents'],'residual_cents'=>0,'acquired_on'=>$p['acquired_on'],'months'=>0,'sphere'=>$p['sphere'],'account'=>$p['account'],'reason'=>$p['reason'],'method'=>self::METHOD,'partnership_plan'=>$p,'partnership_relationship'=>$relationship];
 }
 public static function original(array $data):array{
  if(($data['method']??null)!==self::METHOD||!is_array($data['partnership_plan']??null)||!is_array($data['partnership_relationship']??null))throw new DomainException('Gespeicherten ursprünglichen HGB-Beteiligungsplan prüfen.');
  $p=$data['partnership_plan'];$relationship=$data['partnership_relationship'];
  $input=['basis'=>OutgoingInvoices::amount($p['basis_cents']),'acquired_on'=>$p['acquired_on'],'sphere'=>$p['sphere'],'account'=>$p['account'],'reason'=>$p['reason'],
   'holding_id'=>$p['holding_id'],'club_id'=>$p['club_id'],'partnership_document'=>$p['document'],'asset_reviewed'=>'1','partnership_relationship'=>$relationship['kind'],
   'partnership_relationship_document'=>$relationship['document'],'partnership_relationship_reason'=>$relationship['reason'],'partnership_relationship_reviewed'=>'1'];
  if(self::input($input)!==$data)throw new DomainException('HGB-Beteiligungsplan wurde gegenüber seinem ursprünglichen Nachweis verändert.');return $p;
 }
 public static function snapshot(mixed $snapshot):array{
  if(!is_array($snapshot)||array_keys($snapshot)!==['plan','partnership_book']||!is_array($snapshot['plan'])||!is_array($snapshot['partnership_book']))throw new DomainException('Ursprünglichen Jahresstand der HGB-Beteiligung prüfen.');
  $p=self::original($snapshot['plan']);$b=$snapshot['partnership_book'];
  if(($b['source']['plan']??null)!==$p||PartnershipBookBasis::calculate($b['source'],$b['previous']??null)!==$b)throw new DomainException('Originalplan und HGB-Jahresregister stimmen nicht überein.');return $b;
 }
 public static function evidence(PDO $db,string $actor,array $data,?array $book=null,bool $lock=false):void{
  $p=self::original($data);$a=Permissions::actor($db,$actor);
  if($a['club_id']!==$p['club_id']||!in_array($a['finance_access'],$lock?['write']:['read','write'],true)||($lock&&!$db->inTransaction()))throw new DomainException('Finanzberechtigung für genau diesen ursprünglichen Beteiligungsplan erforderlich.');
  $e=new AssetValuationEvidence($db,$actor);$e->check($data['partnership_relationship']['document'],$lock);$e->check($p['document'],$lock);
  if($book===null)return;
  if(($book['source']['plan']??null)!==$p||PartnershipBookBasis::calculate($book['source'],$book['previous']??null)!==$book)throw new DomainException('HGB-Register unverändert nachrechnen.');
  $depth=0;do{
   if(++$depth>101)throw new DomainException('Zu viele ursprüngliche Bewertungsstände.');
   $e->check($book['source']['plan']['document'],$lock);
   foreach($book['source']['events'] as $event){$e->check($event['document'],$lock);if(isset($event['allocation_document']))$e->check($event['allocation_document'],$lock);}
   $book=$book['previous'];
  }while($book!==null);
 }
 public static function formEvents(PDO $db,string $actor,array $input,bool $lock):array{
  $rows=$input['partnership_book_events']??[];if(!is_array($rows)||!array_is_list($rows)||count($rows)>200)throw new DomainException('Ursprüngliche HGB-Ereignisse vollständig und zeitlich geordnet angeben.');
  $e=new AssetValuationEvidence($db,$actor);$events=[];
  foreach($rows as $r){
   if(!is_array($r)||array_diff(array_keys($r),['id','kind','on','book','cost','reason','document_id','allocation_document_id','permanent','confirmed','no_proceeds']))throw new DomainException('HGB-Ereignis erneut prüfen.');
   if(($r['kind']??'')==='')continue;
   foreach(['id','kind','on','book','reason','document_id'] as $name)if(!is_string($r[$name]??null))throw new DomainException('HGB-Ereignis vollständig eingeben.');
   if(!in_array($r['kind'],['impairment','writeup','disposal'],true))throw new DomainException('Weitere Kapitalzuführungen anhand ihrer ursprünglichen Anschaffungsrechnung gesondert übernehmen.');
   $v=['id'=>$r['id'],'on'=>$r['on'],'kind'=>$r['kind'],'document'=>$e->reference($r['document_id'],$lock),'reason'=>trim($r['reason']),'confirmed'=>($r['confirmed']??null)==='1'];
   if($r['kind']==='disposal'){
    if(!is_string($r['cost']??null)||!is_string($r['allocation_document_id']??null))throw new DomainException('Abgang mit tatsächlichen Anschaffungskosten und Aufteilungsbeleg nachweisen.');
    $v+=['cost_cents'=>Finance::cents($r['cost']),'carrying_cents'=>Finance::cents($r['book']),'allocation_document'=>$e->reference($r['allocation_document_id'],$lock),'no_proceeds'=>($r['no_proceeds']??null)==='1'];
   }else{$v['closing_cents']=Finance::cents($r['book']);if($r['kind']==='impairment')$v['permanent']=($r['permanent']??null)==='1';}
   $events[]=$v;
  }return $events;
 }
}
