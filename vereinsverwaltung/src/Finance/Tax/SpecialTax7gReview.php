<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSpecialTax7g.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';
final class SpecialTax7gReview {
 public function __construct(private PDO $db,private string $actor){}
 public function evidence(array $source,bool $lock=false):void{$e=new AssetValuationEvidence($this->db,$this->actor);foreach(['profit_document','eligibility_document','election_document'] as $k)$e->check($source[$k],$lock);}
 public function build(array $report,array $input):array{
  $keys=['item_id','special_account','regular_account','special_amount','previous_profit','domestic_business_use_confirmed','use_through_next_year_confirmed','no_other_elections_confirmed','amount_reviewed','profit_document_id','eligibility_document_id','election_document_id'];
  if(array_diff(array_keys($input),array_merge($keys,['tax_law'])))throw new DomainException('Unbekannte Angaben zur ursprünglichen Steuerwahl.');
  foreach($keys as $k)if(!is_string($input[$k]??null))throw new DomainException('Sonderabschreibung mit allen Originalnachweisen vollständig eingeben.');
  $v=trim($input['previous_profit']);$negative=str_starts_with($v,'-');$profit=($negative?-1:1)*Finance::cents($negative?substr($v,1):$v);
  $e=new AssetValuationEvidence($this->db,$this->actor);
  $s=['schema'=>1,'year'=>$report['year'],'journal_sha256'=>$report['closed_sha256'],'item_id'=>$input['item_id'],'special_account'=>$input['special_account'],'regular_account'=>$input['regular_account'],'special_cents'=>Finance::cents($input['special_amount']),'previous_profit_cents'=>$profit];
  foreach(['domestic_business_use_confirmed','use_through_next_year_confirmed','no_other_elections_confirmed','amount_reviewed'] as $k)$s[$k]=$input[$k]==='1';
  foreach(['profit','eligibility','election'] as $k)$s[$k.'_document']=$e->reference($input[$k.'_document_id'],true);
  $generic=($report['calculation']['accounts'][$input['special_account']]['skr_code']??null)==='62400';$law=$input['tax_law']??'';OriginalSpecialTax7g::need(is_string($law));
  if($generic){OriginalSpecialTax7g::need($law==='EStG7g5');$s['schema']=2;$s['tax_law']=$law;}else OriginalSpecialTax7g::need($law==='');
  OriginalSpecialTax7g::bind($report,$s);return $s;
 }
}
