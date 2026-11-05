<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
/** Original HGB sources only. Fiscal mirror capital is an independent contract. */
final class PartnershipBookBasis
{
 public const ACCOUNTS=['08000','08030','08050','08090','08200','08400','08600'];
 public const FIELDS=['cost_opening_cents','additions_cents','disposals_cents','cost_closing_cents','accumulated_opening_cents','impairment_cents','writeup_cents','accumulated_disposals_cents','accumulated_closing_cents','book_opening_cents','book_disposals_cents','book_closing_cents'];
 private const LIMIT=100000000000;
 private static function require(bool $ok,string $message='Ursprünglichen handelsrechtlichen Beteiligungsnachweis vollständig prüfen.'):void{if(!$ok)throw new DomainException($message);}
 private static function keys(mixed $v,array $keys):bool{return is_array($v)&&count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
 private static function cents(mixed $v,int $min=0):int{self::require(is_int($v)&&$v>=$min&&$v<=self::LIMIT,'Ursprüngliche handelsrechtliche Centbeträge prüfen.');return $v;}
 private static function note(mixed $v):void{self::require(is_string($v)&&trim($v)!==''&&mb_strlen($v)<=2000&&!preg_match('/[\x00-\x1f]/',$v),'Handelsrechtliche Bewertung aus dem Originalnachweis begründen.');}
 private static function day(mixed $v):\DateTimeImmutable{self::require(is_string($v)&&preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v));$d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);self::require($d!==false&&$d->format('Y-m-d')===$v&&(int)$d->format('Y')>=1900&&(int)$d->format('Y')<=2199);return $d;}
 private static function document(mixed $v):void{self::require(self::keys($v,['document_id','revision','sha256'])&&is_string($v['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$v['document_id'])&&is_int($v['revision'])&&$v['revision']>=1&&is_string($v['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$v['sha256']));}
 private static function hash(array $v):string{return hash('sha256',json_encode($v,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 public static function calculate(array $s,?array $previous=null,int $depth=0):array{
  self::require($depth>=0&&$depth<=100&&self::keys($s,['schema','plan','period','events'])&&$s['schema']===1);
  $p=$s['plan'];self::require(self::keys($p,['holding_id','club_id','account','basis_cents','acquired_on','sphere','document','reason','confirmed'])&&in_array($p['account'],self::ACCOUNTS,true)&&in_array($p['sphere'],['1','2','3','4'],true)&&$p['confirmed']===true);
  self::require(is_string($p['holding_id'])&&preg_match('/^[a-f0-9]{32}$/D',$p['holding_id'])&&is_string($p['club_id'])&&trim($p['club_id'])!==''&&mb_strlen($p['club_id'])<=64&&!preg_match('/[\x00-\x1f]/',$p['club_id']));
  $cost=self::cents($p['basis_cents'],1);$acquired=self::day($p['acquired_on']);self::document($p['document']);self::note($p['reason']);
  self::require(self::keys($s['period'],['start','end']));$period=$s['period'];$start=self::day($period['start']);$end=self::day($period['end']);self::require($start<=$end&&$acquired<=$end);
  self::require(is_array($s['events'])&&array_is_list($s['events'])&&count($s['events'])<=200);
  $remaining=$cost;$value=$cost;$ids=[];
  if($previous!==null){
   self::require(is_array($previous['source']??null)&&self::calculate($previous['source'],$previous['previous']??null,$depth+1)===$previous);
   $prior=$previous['source'];self::require($prior['plan']==$p&&self::day($prior['period']['end'])->modify('+1 day')==$start,'Ursprünglichen unmittelbaren HGB-Bewertungsvortrag nachweisen.');
   $remaining=$previous['book']['cost_closing_cents'];$value=$previous['book']['book_closing_cents'];$ids=$previous['event_ids'];
  }else self::require($acquired>=$start,'Ältere Beteiligung benötigt ihren vollständig nachgerechneten ursprünglichen HGB-Vortrag.');
  $before=$acquired<$start;$openingCost=$before?$remaining:0;$openingValue=$before?$value:0;
  $row=array_fill_keys(self::FIELDS,0);$row['cost_opening_cents']=$openingCost;$row['book_opening_cents']=$openingValue;$row['accumulated_opening_cents']=$openingCost-$openingValue;$row['additions_cents']=$before?0:$cost;
  $last=$period['start'];$movements=[];
  foreach($s['events'] as $e){
   self::require(is_array($e)&&in_array($e['kind']??null,['addition','impairment','writeup','disposal'],true));
   $extra=match($e['kind']){'addition'=>['cost_cents'],'impairment'=>['closing_cents','permanent'],'writeup'=>['closing_cents'],'disposal'=>['cost_cents','carrying_cents','allocation_document','no_proceeds']};
   self::require(self::keys($e,array_merge(['id','on','kind','document','reason','confirmed'],$extra))&&$e['confirmed']===true&&is_string($e['id'])&&preg_match('/^[a-f0-9]{32}$/D',$e['id'])&&!in_array($e['id'],$ids,true));
   self::day($e['on']);self::require($e['on']>=$last&&$e['on']<=$period['end']&&$e['on']>=$p['acquired_on']&&$remaining>0);self::document($e['document']);self::note($e['reason']);$old=$value;$costDelta=0;
   if($e['kind']==='addition'){$n=self::cents($e['cost_cents'],1);$remaining=self::cents($remaining+$n,1);$value=self::cents($value+$n);$row['additions_cents']+=$n;$costDelta=$n;}
   elseif($e['kind']==='disposal'){
    $n=self::cents($e['cost_cents'],1);$v=self::cents($e['carrying_cents']);self::document($e['allocation_document']);self::require($e['no_proceeds']===true);
    self::require($n<=$remaining&&$v<=$n&&$v<=$value&&$value-$v<=$remaining-$n&&($n!==$remaining||$v===$value),'Tatsächliche ursprüngliche Kosten und Restbuchwerte des abgegangenen Anteils abstimmen.');
    $remaining-=$n;$value-=$v;$row['disposals_cents']+=$n;$row['book_disposals_cents']+=$v;$row['accumulated_disposals_cents']+=$n-$v;$costDelta=-$n;
   }else{
    $value=self::cents($e['closing_cents']);self::require($value<=$remaining,'HGB-Wertaufholung bleibt durch die ursprünglichen Restkosten begrenzt.');
    if($e['kind']==='impairment'){self::require(is_bool($e['permanent'])&&$value<$old);$row['impairment_cents']+=$old-$value;}
    else{self::require($value>$old);$row['writeup_cents']+=$value-$old;}
   }
   $ids[]=$e['id'];$last=$e['on'];$movements[]=['event_id'=>$e['id'],'on'=>$e['on'],'kind'=>$e['kind'],'cost_change_cents'=>$costDelta,'book_change_cents'=>$value-$old];foreach($row as $n)self::cents($n);
  }
  $row['cost_closing_cents']=$remaining;$row['book_closing_cents']=$value;$row['accumulated_closing_cents']=$remaining-$value;
  self::require($row['cost_opening_cents']+$row['additions_cents']-$row['disposals_cents']===$remaining&&$row['accumulated_opening_cents']+$row['impairment_cents']-$row['writeup_cents']-$row['accumulated_disposals_cents']===$row['accumulated_closing_cents']&&$openingValue+$row['additions_cents']-$row['impairment_cents']+$row['writeup_cents']-$row['book_disposals_cents']===$value);
  $out=['schema'=>1,'source'=>$s,'previous'=>$previous,'event_ids'=>$ids,'book'=>$row,'movements'=>$movements,'export_authorized'=>false];return $out+['sha256'=>self::hash($out)];
 }
}
