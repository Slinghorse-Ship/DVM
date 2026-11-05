<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';
final class OriginalSubstance {
 public const METHOD='substance_original';
 public const TARGET='bs.ass.fixAss.tan.landBuildings.landWithoutBuildings';
 public const EXPENSE='is.netIncome.regular.operatingTC.deprAmort.fixAss.tan.other';
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('Substanzverzehr: entgeltliche ursprüngliche Kosten, nicht verbrauchbarer Restwert, Originalmengen und getrennte Buch-/Steuerbestätigung abstimmen.');}
 public static function rule(array $r):void{self::need(($r['substance_original_contract']??null)==='02250@1'&&($r['group']??null)==='fixed_assets'&&($r['condition']??null)==='detail_choice'&&($r['target']??null)===self::TARGET&&($r['allowed_targets']??null)===[self::TARGET]&&!array_key_exists('book_only',$r)&&!array_key_exists('commercial_contract',$r)&&empty($r['disclosures']));if(array_key_exists('codes',$r))self::need($r['codes']===['02250']);}
 public static function integer(string $v):int{self::need((bool)preg_match('/^[1-9][0-9]{0,6}$/D',$v)&&(int)$v<=1000000);return (int)$v;}
 public static function input(array $in):array{
  foreach(['basis','residual','acquired_on','sphere','reason','substance_total','substance_unit'] as $k)self::need(is_string($in[$k]??null));self::need(($in['account']??null)==='02250'&&($in['substance_acquired_reviewed']??null)==='1'&&($in['substance_estimate_reviewed']??null)==='1');
  $p=['basis_cents'=>Finance::cents($in['basis']),'residual_cents'=>Finance::cents($in['residual']),'acquired_on'=>$in['acquired_on'],'months'=>0,'sphere'=>$in['sphere'],'account'=>'02250','reason'=>trim($in['reason']),'method'=>self::METHOD,'total_units'=>self::integer($in['substance_total']),'unit'=>$in['substance_unit'],'acquired_reviewed'=>true,'estimate_reviewed'=>true,'acquisition_document'=>$in['substance_acquisition_document']??null,'estimate_document'=>$in['substance_estimate_document']??null];self::plan($p);return $p;
 }
 public static function plan(array $p):void{
  self::need(array_keys($p)===['basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method','total_units','unit','acquired_reviewed','estimate_reviewed','acquisition_document','estimate_document']&&$p['method']===self::METHOD&&$p['account']==='02250'&&$p['months']===0&&is_int($p['basis_cents'])&&$p['basis_cents']>0&&$p['basis_cents']<=BalanceCalculation::LIMIT&&is_int($p['residual_cents'])&&$p['residual_cents']>=0&&$p['residual_cents']<$p['basis_cents']&&is_int($p['total_units'])&&$p['total_units']>0&&$p['total_units']<=1000000&&in_array($p['unit'],['kg','m3','unit'],true)&&is_string($p['acquired_on'])&&in_array(TaxCatalog::documentYear($p['acquired_on']),[2025,2026],true)&&in_array($p['sphere'],['1','2','3','4'],true)&&$p['acquired_reviewed']===true&&$p['estimate_reviewed']===true&&is_string($p['reason'])&&trim($p['reason'])!==''&&mb_strlen($p['reason'])<=2000&&!preg_match('/[\x00-\x1f]/',$p['reason']));foreach(['acquisition_document','estimate_document'] as $k)AssetValuation::document($p[$k]);
 }
 public static function annual(array $p,int $year,array $input,AssetValuationEvidence $e):array{
  self::need(is_string($input['substance_used']??null)&&($input['substance_book_reviewed']??null)==='1'&&($input['substance_tax_reviewed']??null)==='1'&&is_string($input['substance_usage_document_id']??null));
  $s=['schema'=>1,'year'=>$year,'used_units'=>self::integer($input['substance_used']),'book_reviewed'=>true,'tax_reviewed'=>true,'document'=>$e->reference($input['substance_usage_document_id'],true)];return self::calculate($p,$s);
 }
 public static function calculate(array $p,array $s):array{
  self::plan($p);self::need(array_keys($s)===['schema','year','used_units','book_reviewed','tax_reviewed','document']&&$s['schema']===1&&$s['year']===TaxCatalog::documentYear($p['acquired_on'])&&is_int($s['used_units'])&&$s['used_units']>0&&$s['used_units']<=$p['total_units']&&$s['book_reviewed']===true&&$s['tax_reviewed']===true);AssetValuation::document($s['document']);
  $n=intdiv(($p['basis_cents']-$p['residual_cents'])*$s['used_units']+intdiv($p['total_units'],2),$p['total_units']);self::need($n>0&&$n<=$p['basis_cents']-$p['residual_cents']);
  $out=['schema'=>1,'source'=>['plan'=>$p,'proof'=>$s],'year'=>$s['year'],'opening_cents'=>$p['basis_cents'],'depreciation_cents'=>$n,'closing_cents'=>$p['basis_cents']-$n];return $out+['sha256'=>BalanceSegmentCalculation::hash($out)];
 }
 public static function snapshot(array $snap):array{self::need(array_keys($snap)===['plan','substance']&&is_array($snap['plan'])&&is_array($snap['substance']['source']['proof']??null));$v=self::calculate($snap['plan'],$snap['substance']['source']['proof']);self::need(OwnIntangibleStorage::normal($v)===OwnIntangibleStorage::normal($snap['substance']));return $v;}
}
