<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalBasisReduction6b.php';require_once dirname(__DIR__, 2).'/Finance/Assets/AssetValuationEvidence.php';
final class BasisReduction6bReview {
 public function __construct(private PDO $db,private string $actor){}
 public function evidence(array $s,bool $lock=false):void{$e=new AssetValuationEvidence($this->db,$this->actor);foreach(OriginalBasisReduction6b::DOCUMENTS as $k)$e->check($s[$k.'_document'],$lock);}
 public function build(array $r,array $in):array{
  $keys=[];foreach(OriginalBasisReduction6b::KEYS as $k){if(in_array($k,['schema','year','journal_sha256'],true))continue;$keys[]=str_ends_with($k,'_cents')?substr($k,0,-6).'_amount':(str_ends_with($k,'_document')?$k.'_id':$k);}if(array_diff(array_keys($in),$keys))throw new DomainException('Unbekannte §6b-Angaben.');foreach($keys as $k)if(!is_string($in[$k]??null))throw new DomainException('Originalverkauf, Rücklage und Ersatzinvestition vollständig eingeben.');
  $s=['schema'=>1,'year'=>$r['year'],'journal_sha256'=>$r['closed_sha256']];$e=new AssetValuationEvidence($this->db,$this->actor);
  foreach(OriginalBasisReduction6b::KEYS as $k){if(array_key_exists($k,$s))continue;if(str_ends_with($k,'_cents'))$s[$k]=Finance::cents($in[substr($k,0,-6).'_amount']);elseif(str_ends_with($k,'_document'))$s[$k]=$e->reference($in[$k.'_id'],true);elseif(in_array($k,OriginalBasisReduction6b::FLAGS,true))$s[$k]=$in[$k]==='1';elseif($k==='reserve_year'){if(!preg_match('/^20[0-9]{2}$/D',$in[$k]))throw new DomainException('Originales Rücklagenjahr prüfen.');$s[$k]=(int)$in[$k];}else $s[$k]=$in[$k];}
  OriginalBasisReduction6b::bind($r,$s);return $s;
 }
}
