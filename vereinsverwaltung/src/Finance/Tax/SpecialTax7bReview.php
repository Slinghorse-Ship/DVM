<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use DomainException;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSpecialTax7b.php';
require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';
final class SpecialTax7bReview {
 public function __construct(private PDO $db,private string $actor){}
 public function evidence(array $s,bool $lock=false):void{$e=new AssetValuationEvidence($this->db,$this->actor);foreach(OriginalSpecialTax7b::DOCUMENTS as $k)$e->check($s[$k.'_document'],$lock);}
 public function build(array $r,array $input):array{
  $keys=['item_id','special_account','regular_account','special_amount','application_on','completed_on','area','aid_value','other_aid'];$keys=array_merge($keys,OriginalSpecialTax7b::FLAGS,array_map(static fn($k)=>$k.'_document_id',OriginalSpecialTax7b::DOCUMENTS));
  if(array_diff(array_keys($input),$keys))throw new DomainException('Unbekannte Neubauwahl.');foreach($keys as $k)if(!is_string($input[$k]??null))throw new DomainException('Neubauwahl mit allen Originalnachweisen vollständig eingeben.');
  $s=['schema'=>1,'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256'],'item_id'=>$input['item_id'],'special_account'=>$input['special_account'],'regular_account'=>$input['regular_account'],'special_cents'=>Finance::cents($input['special_amount']),'application_on'=>$input['application_on'],'completed_on'=>$input['completed_on'],'area_hundredths'=>Finance::cents($input['area']),'aid_value_cents'=>Finance::cents($input['aid_value']),'other_aid_cents'=>Finance::cents($input['other_aid'])];
  foreach(OriginalSpecialTax7b::FLAGS as $k)$s[$k]=$input[$k]==='1';$e=new AssetValuationEvidence($this->db,$this->actor);foreach(OriginalSpecialTax7b::DOCUMENTS as $k)$s[$k.'_document']=$e->reference($input[$k.'_document_id'],true);
  OriginalSpecialTax7b::bind($r,$s);return $s;
 }
}
