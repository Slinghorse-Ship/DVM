<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/PartnershipCapitalBasis.php';
require_once __DIR__.'/PartnershipBookBasis.php';
/** Independent original sources only; no fabricated journal, grid or tax deduction. */
final class PartnershipHoldingSources
{
 private static function require(bool $ok,string $message='Ursprüngliche HGB- und Steuerquellen der Beteiligung vollständig prüfen.'):void{if(!$ok)throw new DomainException($message);}
 public static function reconcile(array $book,array $capital,array $relationship):array{
  self::require(PartnershipBookBasis::calculate($book['source'],$book['previous']??null)===$book&&PartnershipCapitalBasis::calculate($capital['source'],$capital['previous']??null)===$capital);
  $p=$book['source']['plan'];$s=$capital['source'];
  foreach(['holding_id','club_id','account'] as $k)self::require($p[$k]===$s[$k],'Unabhängige HGB- und Steuerquellen müssen dieselbe ursprüngliche Beteiligung desselben Vereins und denselben Zeitraum betreffen.');
  self::require($book['source']['period']==$s['period']);
  self::require(count($relationship)===4&&!array_diff(array_keys($relationship),['kind','document','reason','confirmed'])&&($relationship['confirmed']??null)===true);
  $d=$relationship['document'];self::require(is_array($d)&&count($d)===3&&!array_diff(array_keys($d),['document_id','revision','sha256'])&&is_string($d['document_id'])&&preg_match('/^[a-f0-9]{32}$/D',$d['document_id'])&&is_int($d['revision'])&&$d['revision']>=1&&is_string($d['sha256'])&&preg_match('/^[a-f0-9]{64}$/D',$d['sha256']));
  self::require(is_string($relationship['reason'])&&trim($relationship['reason'])!==''&&mb_strlen($relationship['reason'])<=2000&&!preg_match('/[\x00-\x1f]/',$relationship['reason']));
  $code=$p['account'];$allowed=in_array($code,['08050','08090'],true)?['parent_company']:(in_array($code,['08000','08030'],true)?['affiliated','parent_company']:['participation']);
  self::require(in_array($relationship['kind'],$allowed,true),'Ursprüngliches Beteiligungsverhältnis und bestätigtes Konto widersprechen sich.');
  $affiliated=in_array($relationship['kind'],['affiliated','parent_company'],true);$prefix='bs.ass.fixAss.fin.'.($affiliated?'sharesInAffil':'particip');$atypical=$s['classification']==='atypical_silent_partner';
  $out=['schema'=>1,'holding_id'=>$p['holding_id'],'club_id'=>$p['club_id'],'account'=>$code,'sphere'=>$p['sphere'],'period'=>$s['period'],
   'book_basis'=>$book,'capital_basis'=>$capital,'relationship'=>$relationship,'book_target'=>$prefix.($atypical?'.silent':'.partnerships'),'tax_target'=>$prefix.'.partnerships.commPart.'.($atypical?'silentAtyp':'other'),
   'parent_disclosures'=>$relationship['kind']==='parent_company'?[$prefix.'.parentComp']:[],
   'opening_difference_cents'=>$capital['opening_cents']-$book['book']['book_opening_cents'],'closing_difference_cents'=>$capital['closing_cents']-$book['book']['book_closing_cents'],'export_authorized'=>false];
  return $out+['sha256'=>hash('sha256',json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))];
 }
}
