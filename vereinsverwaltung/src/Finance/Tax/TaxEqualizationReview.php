<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalTaxEqualization.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';
/** Document resolution is separate from the original arithmetic and the transaction. */
final class TaxEqualizationReview {
 public function __construct(private PDO $db,private string $actor){}
 public function evidence(array $s,bool $lock=false):void{$e=new AssetValuationEvidence($this->db,$this->actor);foreach(OriginalTaxEqualization::DOCUMENTS as $k)$e->check($s[$k.'_document'],$lock);}
 public function build(array $r,array $review,array $in):array {
  $keys=['use_account','capital_account',...OriginalTaxEqualization::FLAGS];foreach(OriginalTaxEqualization::DOCUMENTS as $k)$keys[]=$k.'_document_id';
  if(array_diff(array_keys($in),$keys)||array_diff($keys,array_keys($in)))throw new DomainException('77690: vollständige Originalangaben erforderlich.');foreach($keys as $k)if(!is_string($in[$k]))throw new DomainException('77690: Originalangaben einzeln bestätigen.');
  OriginalTaxEqualization::need(($review['opening_adjustments']??null)===[]&&!array_intersect(array_keys($review),['pension_offsets','special_tax_7g','special_tax_7b','basis_reduction_7g','basis_reduction_6b','pool_original_sources','own_intangible_sources','partnership_holdings'])&&empty($review['asset_tax_plans']));
  $entries=$review['current_adjustments']??null;OriginalTaxEqualization::need(is_array($entries)&&array_is_list($entries));
  if(isset($review['tax_equalization'])){OriginalTaxEqualization::need(end($entries)['id']===OriginalTaxEqualization::ENTRY);array_pop($entries);}
  $s=['schema'=>1,'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'use_account'=>$in['use_account'],'capital_account'=>$in['capital_account'],'original_current_adjustments'=>$entries];
  foreach(OriginalTaxEqualization::FLAGS as $k)$s[$k]=$in[$k]==='1';$e=new AssetValuationEvidence($this->db,$this->actor);
  foreach(OriginalTaxEqualization::DOCUMENTS as $k)$s[$k.'_document']=$e->reference($in[$k.'_document_id'],true);
  OriginalTaxEqualization::bind($r,$s);return $s;
 }
}
