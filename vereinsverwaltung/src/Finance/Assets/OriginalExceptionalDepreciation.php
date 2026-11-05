<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
require_once __DIR__.'/AssetValuation.php';

/** Individually evidenced original AfaA; regular depreciation is never reclassified. */
final class OriginalExceptionalDepreciation
{
 public const METHOD='exceptional_original';
 public const TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.tan';
 public const GOODWILL_TARGET='is.netIncome.regular.operatingTC.deprAmort.fixAss.specific.except.goodwill';
 public static function target(string $code):string{return $code==='62090'?self::GOODWILL_TARGET:self::TARGET;}
 public static function counter(array $plan):?string{
  $account=$plan['account']??null;
  if(in_array($account,AssetTypes::codes('goodwill'),true))return '62090';
  if(in_array($account,AssetTypes::codes('building'),true))return '62310';
  if($account==='06700'&&($plan['depreciation_code']??null)==='62620')return '62660';
  if(in_array($account,['05200','05250','05400','05600'],true))return '62320';
  return in_array($account,AssetTypes::codes('movable'),true)?'62330':null;
 }
 public static function need(bool $ok):void{if(!$ok)throw new DomainException('AfaA: ursprünglichen Einzelplan, Jahresnachweis sowie unabhängig bestätigte Buch- und Steuerbeträge prüfen.');}
 public static function rule(array $rule):void{
  $ref=$rule['exceptional_original_contract']??null;self::need(in_array($ref,['62090@1','62310@1','62320@1','62330@1','62660@1'],true)&&$rule['group']==='expense'&&($rule['condition']??null)==='detail_choice'&&$rule['target']===self::target(substr($ref,0,5))&&($rule['allowed_targets']??null)===[self::target(substr($ref,0,5))]&&empty($rule['disclosures'])&&!array_key_exists('book_only',$rule));
  if(isset($rule['codes']))self::need($rule['codes']===[substr($ref,0,5)]);
 }
 public static function input(array $input,int $year,AssetValuationEvidence $e):array{
  self::need(($input['exceptional_book_reviewed']??null)==='1'&&($input['exceptional_tax_reviewed']??null)==='1');
  foreach(['exceptional_code','exceptional_book','exceptional_tax','exceptional_book_reason','exceptional_tax_reason','exceptional_document_id'] as $k)self::need(is_string($input[$k]??null));
  return ['schema'=>1,'year'=>$year,'on'=>$year.'-12-31','code'=>$input['exceptional_code'],
   'book_cents'=>Finance::cents($input['exceptional_book']),'tax_cents'=>Finance::cents($input['exceptional_tax']),
   'book_reason'=>trim($input['exceptional_book_reason']),'tax_reason'=>trim($input['exceptional_tax_reason']),
   'book_reviewed'=>true,'tax_reviewed'=>true,'document'=>$e->reference($input['exceptional_document_id'])];
 }
 public static function calculate(array $plan,array $proof):array{
  self::need(array_keys($proof)===['schema','year','on','code','book_cents','tax_cents','book_reason','tax_reason','book_reviewed','tax_reviewed','document']
   &&$proof['schema']===1&&is_int($proof['year'])&&in_array($proof['year'],[2025,2026],true)
   &&$proof['on']===$proof['year'].'-12-31'&&($plan['method']??null)==='linear_monthly'
   &&substr($plan['acquired_on']??'',0,4)===(string)$proof['year']&&($plan['residual_cents']??null)===0
   &&!array_intersect(array_keys($plan),['original_leasing','gwg','degressive']));
  self::need(!array_diff(array_keys($plan),['basis_cents','residual_cents','acquired_on','months','sphere','account','reason','method','depreciation_code'])&&in_array($plan['sphere']??null,['1','2','3','4'],true)&&is_string($plan['reason']??null)&&trim($plan['reason'])!==''&&mb_strlen($plan['reason'])<=2000);
  $code=$proof['code'];self::need(is_string($code)&&($code===self::counter($plan)||($code==='62330'&&in_array($plan['account']??null,AssetTypes::codes('movable'),true)&&!in_array($plan['account'],AssetOriginalDepreciation::VEHICLES,true))));
  if($code==='62090')self::need(($plan['months']??null)===180);
  self::need($proof['book_reviewed']===true&&$proof['tax_reviewed']===true&&is_int($proof['book_cents'])
   &&is_int($proof['tax_cents'])&&$proof['book_cents']>0&&$proof['book_cents']<=BalanceCalculation::LIMIT
   &&$proof['book_cents']===$proof['tax_cents']);
  foreach(['book_reason','tax_reason'] as $k)self::need(is_string($proof[$k])&&trim($proof[$k])!==''&&mb_strlen($proof[$k])<=2000&&!preg_match('/[\x00-\x1f]/',$proof[$k]));
  AssetValuation::document($proof['document']);$regular=AssetBooks::schedule($plan,$proof['year']);
  self::need(AssetOriginalDepreciation::code($plan)!==null&&$proof['book_cents']<=$regular['closing_cents']);
  $out=['schema'=>1,'source'=>['plan'=>$plan,'proof'=>$proof],'year'=>$proof['year'],
   'opening_cents'=>$regular['opening_cents'],'depreciation_cents'=>$regular['depreciation_cents'],
   'impairment_cents'=>$proof['book_cents'],'closing_cents'=>$regular['closing_cents']-$proof['book_cents']];
  return $out+['sha256'=>BalanceSegmentCalculation::hash($out)];
 }
 public static function snapshot(array $snapshot):array{
  self::need(array_keys($snapshot)===['plan','exceptional']&&is_array($snapshot['plan'])&&is_array($snapshot['exceptional']));
  $v=$snapshot['exceptional'];self::need(is_array($v['source']['proof']??null));
  $expected=self::calculate($snapshot['plan'],$v['source']['proof']);
  self::need(OwnIntangibleStorage::normal($expected)===OwnIntangibleStorage::normal($v));return $v;
 }
}
