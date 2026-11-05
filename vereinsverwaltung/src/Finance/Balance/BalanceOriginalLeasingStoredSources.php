<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;use DomainException;
require_once __DIR__.'/BalanceCostAssetSources.php';
require_once __DIR__.'/BalanceSourceHistory.php';
require_once __DIR__.'/BalanceReadSnapshot.php';
/** Re-read actual original contracts and source registers for the confirmed ordinary economic-owner movable leasing contract on original62500. */
final class BalanceOriginalLeasingStoredSources
{
 public function __construct(private PDO $db,private string $actor){}
 private static function need(bool $ok):void{if(!$ok)throw new DomainException('Kaufleasing: unveränderten Originalvertrag, Anschaffung, Anlagenplan und bestätigtes Bewertungsregister prüfen.');}
 private function original(string $type,string $id,int $year,string $hash):array{
  $s=new BalanceSources($this->db,$this->actor);$b=$s->binding($type,$id);self::need(is_array($b)&&(int)$b['tax_year']===$year&&hash_equals($hash,$b['source_hash']));
  $p=$s->preview($year,$type,$id,$b['options']);self::need($p['issues']===[]&&hash_equals($b['source_hash'],$p['hash'])&&(bool)(new BalanceSourceHistory($this->db,$this->actor))->checked($b));return $b;
 }
 public function check(array $report):void{
  $items=array_filter($report['asset_schedule']['items']??[],static fn($i)=>($i['depreciation_code']??null)==='62500');if(!$items)return;
  BalanceReadSnapshot::run($this->db,function()use($report,$items){
   $a=Permissions::actor($this->db,$this->actor);self::need(in_array($a['finance_access'],['read','write'],true));$e=new AssetValuationEvidence($this->db,$this->actor);
   foreach($items as $id=>$item){
    self::need((int)substr($item['acquired_on'],0,4)===$report['year']&&!isset($item['disposal']));
    $current=(new AssetBooks($this->db,$this->actor))->get($id);$plan=$current['data'];OriginalLeasing::check($plan);self::need(($plan['depreciation_code']??null)==='62500'&&OwnIntangibleStorage::normal($item['original_leasing'])===OwnIntangibleStorage::normal($plan['original_leasing']));$e->acquisition($plan['original_leasing']['document'],$item['invoice_id']);
    $b=$this->original('invoice',$item['invoice_id'],(int)substr($plan['acquired_on'],0,4),$item['acquisition_hash']);$original=array_values(array_filter($b['snapshot']['assets'],static fn($i)=>$i['item_id']===$id));self::need(count($original)===1);$original=$original[0];$current=(new AssetBooks($this->db,$this->actor))->get($id);
    self::need($current['item']['ownership']==='club'&&$current['version']===(int)$original['version']&&OwnIntangibleStorage::normal($current['data'])===OwnIntangibleStorage::normal($plan)&&OwnIntangibleStorage::normal(json_decode($original['payload'],true,512,JSON_THROW_ON_ERROR))===OwnIntangibleStorage::normal($plan)&&($b['snapshot']['asset_accounts'][$id]??null)===$item['account']);
    $annual=$this->original('depreciation',$id.':'.$report['year'],$report['year'],$item['depreciation_hash']);$row=DocumentCatalog::query($this->db,'SELECT * FROM asset_book_years WHERE club_id=? AND item_id=? AND year=?',[$a['club_id'],$id,$report['year']])->fetch();self::need($row&&(int)$row['plan_version']===$current['version']&&$row['snapshot']===$annual['snapshot']['annual']['snapshot']);
    $snapshot=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);self::need(OwnIntangibleStorage::normal($snapshot)===OwnIntangibleStorage::normal($plan));

   }
   return [];
  });
 }
}
