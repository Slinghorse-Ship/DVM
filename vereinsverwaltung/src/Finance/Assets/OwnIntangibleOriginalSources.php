<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;use DateTimeImmutable;
/** Independent original cost stage; no journal, database or export authorization. */
final class OwnIntangibleOriginalSources {
 private static function need(bool $ok):void{if(!$ok)throw new DomainException('Originale Entwicklungskosten und Ansatznachweise vollständig prüfen.');}
 private static function keys(array $v,array $keys):bool{return count($v)===count($keys)&&!array_diff(array_keys($v),$keys);}
 private static function day(mixed $v):DateTimeImmutable{self::need(is_string($v)&&preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)===1);$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);self::need($d!==false&&$d->format('Y-m-d')===$v);return $d;}
 private static function money(mixed $v):void{self::need(is_int($v)&&$v>=0&&$v<=100000000000);}
 private static function note(mixed $v):void{self::need(is_string($v)&&trim($v)!==''&&mb_strlen($v)<=2000&&!preg_match('/[\x00-\x1f]/',$v));}
 private static function doc(mixed $v):void{self::need(is_array($v)&&self::keys($v,['document_id','revision','sha256'])&&is_string($v['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$v['document_id'])===1&&is_int($v['revision'])&&$v['revision']>=1&&is_string($v['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$v['sha256'])===1);}
 private static function normal(mixed $v,?string $key=null):mixed{$object=is_object($v)||$key==='cost_rows';if(is_object($v))$v=get_object_vars($v);elseif(!is_array($v))return $v;$list=!$object&&array_is_list($v);if(!$list)ksort($v,SORT_STRING);foreach($v as $k=>&$x)$x=self::normal($x,is_string($k)?$k:null);unset($x);return $list?$v:(object)$v;}
 private static function hash(array $v):string{return hash('sha256',json_encode(self::normal($v),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));}
 public static function fingerprint(array $value):string{return self::hash($value);}
 public static function calculate(array $s,?array $previous=null,int $depth=0):array {
  self::need($depth>=0&&$depth<2);
  self::need(self::keys($s,['schema','club_id','item_id','project_id','account','period','product_kind','own_creation','separated_research_development','capitalise','stage','ready_on','life_months','costs','book_document','book_reason','book_confirmed'])&&$s['schema']===1);
  foreach(['item_id','project_id'] as $key)self::need(is_string($s[$key])&&preg_match('/^[a-f0-9]{32}$/D',$s[$key])===1);
  self::need(is_string($s['club_id'])&&trim($s['club_id'])!==''&&mb_strlen($s['club_id'])<=128&&!preg_match('/[\x00-\x1f]/',$s['club_id']));
  self::need(in_array($s['account'],['01440','01450','01460','01470','01480'],true)&&$s['own_creation']===true&&is_bool($s['capitalise'])&&is_bool($s['separated_research_development'])&&$s['book_confirmed']===true);
  self::need(is_array($s['period'])&&self::keys($s['period'],['start','end']));$start=self::day($s['period']['start']);$end=self::day($s['period']['end']);$year=(int)$start->format('Y');self::need(in_array($year,[2025,2026],true)&&$start->format('m-d')==='01-01'&&$end->format('Y-m-d')===$year.'-12-31');
  $kinds=['01440'=>['software'],'01450'=>['licence_franchise'],'01460'=>['concession_patent'],'01470'=>['recipe_procedure_intangible_prototype'],'01480'=>['software','licence_franchise','concession_patent','recipe_procedure_intangible_prototype']];self::need(in_array($s['product_kind'],$kinds[$s['account']],true)&&in_array($s['stage'],['development','ready'],true));
  if($s['capitalise'])self::need($s['separated_research_development']);
  if($s['stage']==='development')self::need($s['account']==='01480'&&$s['ready_on']===null&&$s['life_months']===null);
  else self::need($s['account']!=='01480'&&self::day($s['ready_on'])<=$end&&is_int($s['life_months'])&&$s['life_months']>=12&&$s['life_months']<=1200&&$s['life_months']%12===0);
  self::doc($s['book_document']);self::note($s['book_reason']);self::need(is_array($s['costs'])&&array_is_list($s['costs'])&&count($s['costs'])<=200);
  $allowed=['material','manufacturing','special_manufacturing','material_overhead','manufacturing_overhead','manufacturing_asset_consumption','general_administration_elected','social_facilities_elected','voluntary_social_elected','pension_elected','manufacturing_borrowing_interest_elected'];$eligible=$excluded=0;$seen=[];$rows=[];
  foreach($s['costs'] as $c){
   self::need(is_array($c)&&self::keys($c,['id','on','phase','category','booked_cents','attributable_cents','journal_id','line_ordinal','document','reason','confirmed']));self::need(is_string($c['id'])&&preg_match('/^[a-f0-9]{32}$/D',$c['id'])===1&&!isset($seen[$c['id']]));$seen[$c['id']]=true;
   $on=self::day($c['on']);self::need($start<=$on&&$on<=$end&&in_array($c['phase'],['research','development'],true)&&in_array($c['category'],array_merge($allowed,['research','sales']),true)&&$c['confirmed']===true);self::money($c['booked_cents']);self::money($c['attributable_cents']);self::need($c['attributable_cents']<=$c['booked_cents']);
   self::need(is_string($c['journal_id'])&&preg_match('/^[a-f0-9]{32}$/D',$c['journal_id'])===1&&is_int($c['line_ordinal'])&&$c['line_ordinal']>=0);$identity=$c['journal_id'].':'.$c['line_ordinal'];self::need(!isset($seen[$identity]));$seen[$identity]=true;self::doc($c['document']);self::note($c['reason']);if($s['stage']==='ready')self::need($on<=self::day($s['ready_on']));
   $ok=$c['phase']==='development'&&in_array($c['category'],$allowed,true);$amount=$c['attributable_cents'];if($ok)$eligible+=$amount;else $excluded+=$amount;self::money($eligible);self::money($excluded);$rows[$c['id']]=['eligible'=>$ok,'original_attributed_cents'=>$amount];
  }
  $opening=0;
  if($previous!==null){self::need(self::keys($previous,['schema','source','previous','original_eligible_current_cost_cents','original_excluded_current_cost_cents','hgb_original_cost_addition_cents','hgb_cumulative_original_cost_cents','cost_rows','depreciation_cents','journal_verified','export_authorized','sha256'])&&is_array($previous['source'])&&($previous['previous']===null||is_array($previous['previous']))&&(is_array($previous['cost_rows'])||$previous['cost_rows'] instanceof \stdClass));$expected=self::calculate($previous['source'],$previous['previous'],$depth+1);$given=$previous;$given['cost_rows']=(object)$given['cost_rows'];self::need(self::hash($expected)===self::hash($given));$previous=$expected;$ps=$previous['source'];foreach(['club_id','item_id','project_id','account','product_kind','capitalise','stage','ready_on','life_months'] as $key)self::need($ps[$key]===$s[$key]);self::need(self::day($ps['period']['end'])->modify('+1 day')==$start);$opening=$previous['hgb_cumulative_original_cost_cents'];foreach($s['costs'] as $c)foreach($ps['costs'] as $old)self::need($c['id']!==$old['id']&&[$c['journal_id'],$c['line_ordinal']]!==[$old['journal_id'],$old['line_ordinal']]);}
  $current=$s['capitalise']?$eligible:0;self::money($opening+$current);$out=['schema'=>1,'source'=>$s,'previous'=>$previous,'original_eligible_current_cost_cents'=>$eligible,'original_excluded_current_cost_cents'=>$excluded,'hgb_original_cost_addition_cents'=>$current,'hgb_cumulative_original_cost_cents'=>$opening+$current,'cost_rows'=>(object)$rows,'depreciation_cents'=>null,'journal_verified'=>false,'export_authorized'=>false];return $out+['sha256'=>self::hash($out)];
 }
}
