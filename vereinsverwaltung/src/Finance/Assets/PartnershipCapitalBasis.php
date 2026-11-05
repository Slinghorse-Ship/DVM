<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;

/** Prototype source arithmetic only. No journal, asset grid, tax election or export. */
final class PartnershipCapitalBasis
{
 public const COMPONENTS=['capital_share_cents','supplementary_share_cents','special_share_cents'];
 private const LIMIT=100000000000;
 private static function require(bool $ok,string $message='Originalen steuerlichen Beteiligungsansatz vollständig nachweisen.'):void{if(!$ok)throw new DomainException($message);}
 private static function keys(mixed $v,array $keys):bool{return is_array($v)&&count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
 private static function signed(mixed $n):bool{return is_int($n)&&$n>=-self::LIMIT&&$n<=self::LIMIT;}
 private static function note(mixed $v):bool{return is_string($v)&&trim($v)!==''&&mb_strlen($v)<=2000&&!preg_match('/[\x00-\x1f]/',$v);}
 private static function document(mixed $v):void{
  self::require(self::keys($v,['document_id','revision','sha256'])&&is_string($v['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$v['document_id'])&&is_int($v['revision'])&&$v['revision']>=1&&is_string($v['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$v['sha256']));
 }
 private static function components(mixed $v,bool $allowNegative=false):int{
  self::require(self::keys($v,self::COMPONENTS));foreach($v as $n)self::require(self::signed($n));$total=array_sum($v);
  self::require($total>=($allowNegative?-self::LIMIT:0)&&$total<=self::LIMIT,'Negativen Beteiligungsansatz mit eigenständigem Kapital-/Verbindlichkeitsnachweis behandeln; nicht auf null setzen.');return $total;
 }
 private static function date(mixed $v):\DateTimeImmutable{
  self::require(is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v));$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);self::require($d!==false&&$d->format('Y-m-d')===$v);return $d;
 }
 private static function hash(array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 public static function calculate(array $s,?array $previous=null,int $depth=0):array{
  self::require($depth>=0&&$depth<=100,'Steuerliche Kapitalvorträge sind zu umfangreich.');
  self::require(self::keys(array_diff_key($s,['negative_basis'=>true]),['schema','holding_id','club_id','period','account','classification','basis_scope','opening','closing','movements','document','reason','confirmed']));
  self::require($s['schema']===1&&$s['confirmed']===true&&$s['basis_scope']==='original_assessed_components_no_duplicate_assets');
  self::require(is_string($s['holding_id'])&&preg_match('/^[a-f0-9]{32}$/D',$s['holding_id'])&&is_string($s['club_id'])&&trim($s['club_id'])!==''&&mb_strlen($s['club_id'])<=64&&!preg_match('/[\x00-\x1f]/',$s['club_id']));
  self::require(in_array($s['account'],['08000','08030','08050','08090','08200','08400','08600'],true));
  self::require(in_array($s['classification'],['commercial_partner','atypical_silent_partner'],true)&&($s['account']!=='08400'||$s['classification']==='atypical_silent_partner'));
  self::require(self::note($s['reason'])&&self::keys($s['period'],['start','end']));$period=$s['period'];$start=self::date($period['start']);$end=self::date($period['end']);self::require($start<=$end&&(int)$start->format('Y')>=1900&&(int)$end->format('Y')<=2199);
  $negative=$s['negative_basis']??null;$allowNegative=$negative!==null;
  if($allowNegative){self::require(self::keys($negative,['reporting','document','reason','confirmed'])&&$negative['reporting']==='original_signed_mirror_asset'&&$negative['confirmed']===true&&self::note($negative['reason']),'Negativen ursprünglichen Spiegelbildansatz mit eigenständigem Originalnachweis ausdrücklich bestätigen.');self::document($negative['document']);}
  self::document($s['document']);$opening=self::components($s['opening'],$allowNegative);$closing=self::components($s['closing'],$allowNegative);
  if($previous!==null){
   self::require(is_array($previous['source']??null)&&self::calculate($previous['source'],$previous['previous']??null,$depth+1)===$previous);
   $prior=$previous['source'];self::require($prior['holding_id']===$s['holding_id']&&$prior['club_id']===$s['club_id']&&$prior['account']===$s['account']&&$prior['classification']===$s['classification']&&$prior['basis_scope']===$s['basis_scope']&&$prior['closing']==$s['opening']&&self::date($prior['period']['end'])->modify('+1 day')==$start,'Unveränderten unmittelbaren steuerlichen Kapitalvortrag verwenden.');
  }
  self::require(is_array($s['movements'])&&array_is_list($s['movements'])&&count($s['movements'])<=200);
  $running=$s['opening'];$seen=array_fill_keys($previous['event_ids']??[],true);$last=$period['start'];$deltas=[];$ids=$previous['event_ids']??[];
  foreach($s['movements'] as $e){
   self::require(self::keys($e,['id','on','kind','components','document','reason','confirmed']));
   self::require(is_string($e['id'])&&preg_match('/^[a-f0-9]{32}$/D',$e['id'])&&!isset($seen[$e['id']])&&$e['confirmed']===true);
   self::date($e['on']);self::require($e['on']>=$last&&$e['on']<=$period['end']);self::document($e['document']);
   self::require(in_array($e['kind'],['contribution','withdrawal','assessed_profit','assessed_loss','assessed_basis_correction'],true)&&self::note($e['reason'])&&self::keys($e['components'],self::COMPONENTS));
   foreach($e['components'] as $n)self::require(self::signed($n));$delta=array_sum($e['components']);self::require(self::signed($delta)&&$delta!==0);
   if(in_array($e['kind'],['contribution','assessed_profit'],true))self::require($delta>0);
   if(in_array($e['kind'],['withdrawal','assessed_loss'],true))self::require($delta<0);
   foreach($e['components'] as $k=>$n)$running[$k]+=$n;self::components($running,$allowNegative);$seen[$e['id']]=true;$last=$e['on'];$ids[]=$e['id'];$deltas[]=['event_id'=>$e['id'],'basis_change_cents'=>$delta];
  }
  self::require($running==$s['closing']&&$opening+array_sum(array_column($deltas,'basis_change_cents'))===$closing,'Originale Kapitalanteile, Ergänzungs-/Sonderwerte und Bewegungen stimmen nicht mit dem Abschlussnachweis überein.');
  $out=['schema'=>1,'source'=>$s,'previous'=>$previous,'opening_cents'=>$opening,'closing_cents'=>$closing,'event_ids'=>$ids,'movements'=>$deltas,'export_authorized'=>false];return $out+['sha256'=>self::hash($out)];
 }
}
