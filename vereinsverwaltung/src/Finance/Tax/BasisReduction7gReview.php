<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalBasisReduction7g.php';require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';
final class BasisReduction7gReview {
 public function __construct(private PDO $db,private string $actor){}
 public function evidence(array $s,bool $lock=false):void{$e=new AssetValuationEvidence($this->db,$this->actor);foreach(['iab','usage','election'] as $k)$e->check($s[$k.'_document'],$lock);}
 public function build(array $r,array $in):array{
  $keys=['item_id','reduction_account','regular_account','reduction_amount','iab_year','claimed_amount','available_amount','addition_amount',...OriginalBasisReduction7g::FLAGS,'iab_document_id','usage_document_id','election_document_id'];if(array_diff(array_keys($in),$keys))throw new DomainException('Unbekannte Angaben zur Anschaffungskostenkürzung.');foreach($keys as $k)if(!is_string($in[$k]??null))throw new DomainException('Ursprüngliche IAB-Angaben und Originalnachweise vollständig eingeben.');if(!preg_match('/^20[0-9]{2}$/D',$in['iab_year']))throw new DomainException('Ursprüngliches IAB-Jahr prüfen.');
  $s=['schema'=>1,'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'item_id'=>$in['item_id'],'reduction_account'=>$in['reduction_account'],'regular_account'=>$in['regular_account'],'reduction_cents'=>Finance::cents($in['reduction_amount']),'iab_year'=>(int)$in['iab_year']];foreach(['claimed','available','addition'] as $k)$s[$k.'_cents']=Finance::cents($in[$k.'_amount']);foreach(OriginalBasisReduction7g::FLAGS as $k)$s[$k]=$in[$k]==='1';$e=new AssetValuationEvidence($this->db,$this->actor);foreach(['iab','usage','election'] as $k)$s[$k.'_document']=$e->reference($in[$k.'_document_id'],true);OriginalBasisReduction7g::bind($r,$s);return $s;
 }
}
