<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use DateTimeImmutable;
/** Original HGB event replay. No journal or export authorization. */
final class OwnIntangibleBookBasis {
 private static function need(bool $ok):void{if(!$ok)throw new DomainException('Ursprüngliche HGB-Bewegungen selbst geschaffener Werte vollständig abstimmen.');}
 private static function keys(array $v,array $keys):bool{return count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
 private static function money(mixed $n):void{self::need(is_int($n)&&$n>=0&&$n<=100000000000);}
 private static function day(mixed $s):DateTimeImmutable{self::need(is_string($s)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$s)===1);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$s);self::need($d!==false&&$d->format('Y-m-d')===$s);return $d;}
 private static function doc(mixed $v):void{self::need(is_array($v)&&self::keys($v,['document_id','revision','sha256'])&&is_string($v['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$v['document_id'])===1&&is_int($v['revision'])&&$v['revision']>0&&is_string($v['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$v['sha256'])===1);}
 private static function note(mixed $s):void{self::need(is_string($s)&&trim($s)!==''&&mb_strlen($s)<=2000&&!preg_match('/[\x00-\x1f]/',$s));}
 private static function normal(mixed $v,?string $key=null):mixed{$object=is_object($v)||$key==='cost_rows';if(is_object($v))$v=get_object_vars($v);elseif(!is_array($v))return $v;$list=!$object&&array_is_list($v);if(!$list)ksort($v,SORT_STRING);foreach($v as $k=>&$x)$x=self::normal($x,is_string($k)?$k:null);unset($x);return $list?$v:(object)$v;}
 private static function hash(array $v):string{return hash('sha256',json_encode(self::normal($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 public static function fingerprint(array $value):string{return self::hash($value);}
 public static function calculate(array $proof,array $book,?array $previous=null,int $depth=0):array {
  self::need($depth>=0&&$depth<2&&isset($proof['source'])&&is_array($proof['source'])&&array_key_exists('previous',$proof)&&($proof['previous']===null||is_array($proof['previous'])));
  $expected=OwnIntangibleOriginalSources::calculate($proof['source'],$proof['previous']);self::need(self::hash($expected)===self::hash($proof));$proof=$expected;$s=$proof['source'];
  self::need(self::keys($book,['schema','club_id','item_id','period','opening_cost_cents','opening_amort_cents','opening_impairment_cents','closing_book_cents','events','document','reason','confirmed'])&&$book['schema']===1&&$book['confirmed']===true);
  foreach(['club_id','item_id','period'] as $k)self::need($book[$k]===$s[$k]);self::doc($book['document']);self::note($book['reason']);foreach(['opening_cost_cents','opening_amort_cents','opening_impairment_cents','closing_book_cents'] as $k)self::money($book[$k]);
  if($previous===null){self::need($proof['previous']===null);foreach(['opening_cost_cents','opening_amort_cents','opening_impairment_cents'] as $k)self::need($book[$k]===0);}
  else {self::need(self::keys($previous,['schema','source_proof','book_source','previous','hgb','events','journal_verified','export_authorized','sha256'])&&is_array($previous['source_proof'])&&is_array($previous['book_source'])&&($previous['previous']===null||is_array($previous['previous'])));$prior=self::calculate($previous['source_proof'],$previous['book_source'],$previous['previous'],$depth+1);self::need(self::hash($prior)===self::hash($previous)&&self::hash($proof['previous'])===self::hash($prior['source_proof']));$previous=$prior;foreach(['cost','amort','impairment'] as $k)self::need($book['opening_'.$k.'_cents']===$prior['hgb'][$k.'_closing_cents']);}
  self::need(is_array($book['events'])&&array_is_list($book['events'])&&count($book['events'])<=200);$cost=$book['opening_cost_cents'];$amort=$book['opening_amort_cents'];$impair=$book['opening_impairment_cents'];self::need($amort+$impair<=$cost);
  $totals=array_fill_keys(['additions','amortisation','impairment','writeup','disposed_cost','disposed_amort','disposed_impairment','disposed_book'],0);$trace=[];$seen=[];$last=self::day($s['period']['start']);
  foreach($book['events'] as $e){
   self::need(is_array($e)&&self::keys($e,['id','on','kind','amount_cents','counter_code','document','reason','confirmed'])&&is_string($e['id'])&&preg_match('/^[a-f0-9]{32}$/D',$e['id'])===1&&!isset($seen[$e['id']])&&$e['confirmed']===true);$seen[$e['id']]=true;$on=self::day($e['on']);self::need($last<=$on&&$on<=self::day($s['period']['end']));$last=$on;self::doc($e['document']);self::note($e['reason']);self::money($e['amount_cents']);$n=$e['amount_cents'];self::need($n>0&&in_array($e['kind'],['capitalisation','amortisation','impairment','writeup','disposal'],true));
   switch($e['kind']){
    case 'capitalisation':$available=0;$sourceRows=(array)$proof['cost_rows'];foreach($s['costs'] as $c)if($sourceRows[$c['id']]['eligible']&&self::day($c['on'])<=$on)$available+=$c['attributable_cents'];self::need($s['capitalise']&&$e['counter_code']==='48250'&&$totals['additions']+$n<=$available&&($s['stage']==='development'||$on<=self::day($s['ready_on'])));$cost+=$n;$totals['additions']+=$n;break;
    case 'amortisation':self::need($s['stage']==='ready'&&$on>=self::day($s['ready_on'])&&$e['counter_code']==='62010'&&$n<=$cost-$amort-$impair);$amort+=$n;$totals['amortisation']+=$n;break;
    case 'impairment':self::need($e['counter_code']==='62110'&&$n<=$cost-$amort-$impair);$impair+=$n;$totals['impairment']+=$n;break;
    case 'writeup':self::need($e['counter_code']==='49110'&&$n<=$impair);$impair-=$n;$totals['writeup']+=$n;break;
    case 'disposal':self::need($e['counter_code']==='68960'&&$n===$cost-$amort-$impair);$totals['disposed_book']+=$n;$totals['disposed_cost']+=$cost;$totals['disposed_amort']+=$amort;$totals['disposed_impairment']+=$impair;$cost=$amort=$impair=0;break;
   }
   self::need($amort+$impair<=$cost);self::money($cost);$trace[]=['original'=>$e,'cost_after_cents'=>$cost,'amort_after_cents'=>$amort,'impairment_after_cents'=>$impair,'book_after_cents'=>$cost-$amort-$impair];
  }
  self::need($totals['additions']===$proof['hgb_original_cost_addition_cents']&&$cost-$amort-$impair===$book['closing_book_cents']);$hgb=['cost_opening_cents'=>$book['opening_cost_cents'],'amort_opening_cents'=>$book['opening_amort_cents'],'impairment_opening_cents'=>$book['opening_impairment_cents'],'book_opening_cents'=>$book['opening_cost_cents']-$book['opening_amort_cents']-$book['opening_impairment_cents'],'cost_closing_cents'=>$cost,'amort_closing_cents'=>$amort,'impairment_closing_cents'=>$impair,'book_closing_cents'=>$cost-$amort-$impair];foreach($totals as $k=>$n)$hgb[$k.'_cents']=$n;
  $out=['schema'=>1,'source_proof'=>$proof,'book_source'=>$book,'previous'=>$previous,'hgb'=>$hgb,'events'=>$trace,'journal_verified'=>false,'export_authorized'=>false];return $out+['sha256'=>self::hash($out)];
 }
}
