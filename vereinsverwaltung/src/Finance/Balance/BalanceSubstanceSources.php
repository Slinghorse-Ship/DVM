<?php declare(strict_types=1);
namespace Kulturhus\Verein;
use PDO;
require_once dirname(__DIR__, 2).'/Finance/Assets/OriginalSubstance.php';
require_once __DIR__.'/BalanceReadSnapshot.php';
final class BalanceSubstanceSources
{
 public function __construct(private PDO $db,private string $actor){}
 public function annual(array &$p,array $row,bool $lock):void{
  $need=[OriginalSubstance::class,'need'];$a=Permissions::actor($this->db,$this->actor);$club=$a['club_id'];
  $snap=json_decode($row['snapshot'],true,512,JSON_THROW_ON_ERROR);$v=OriginalSubstance::snapshot($snap);$plan=$snap['plan'];foreach(['acquisition_document','estimate_document'] as $k)(new AssetValuationEvidence($this->db,$this->actor))->check($plan[$k],$lock);
  $need($p['year']===$v['year']);foreach(['opening_cents','depreciation_cents','closing_cents'] as $f)$need((int)$row[$f]===$v[$f]);
  (new AssetValuationEvidence($this->db,$this->actor))->check($v['source']['proof']['document'],$lock);
  $current=(new AssetBooks($this->db,$this->actor))->get($row['item_id']);$need($current['item']['ownership']==='club'&&$current['version']===(int)$row['plan_version']&&$current['data']===$plan);
  $links=DocumentCatalog::query($this->db,'SELECT entry_id FROM invoice_position_items WHERE club_id=? AND item_id=?'.($lock?' FOR UPDATE':''),[$club,$row['item_id']])->fetchAll();$need(count($links)===1);
  $sources=new BalanceSources($this->db,$this->actor);$b=$sources->binding('invoice',$links[0]['entry_id']);$need(is_array($b)&&(int)$b['tax_year']===$p['year']);
  $fresh=$sources->preview($p['year'],'invoice',$b['source_id'],$b['options'],$lock);$need(!$fresh['issues']&&hash_equals($b['source_hash'],$fresh['hash']));
  (new BalanceSourceHistory($this->db,$this->actor))->checked($b);
  $original=array_values(array_filter($b['snapshot']['assets'],static fn($i)=>$i['item_id']===$row['item_id']));$need(count($original)===1&&json_decode($original[0]['payload'],true,64,JSON_THROW_ON_ERROR)===$plan&&(int)$original[0]['version']===(int)$row['plan_version']);
  $fixed=$b['snapshot']['asset_accounts'][$row['item_id']]??null;$need(is_string($fixed));$p['accounts']=[$fixed=>$fresh['accounts'][$fixed]];
  $key='src_substance_62200';$catalog=TaxCatalog::accounts($p['year']);$need((bool)($catalog['62200']['active']??false));$p['accounts'][$key]=['name'=>$catalog['62200']['name'],'group'=>'expense','skr_code'=>'62200'];$amount=$v['depreciation_cents'];
  $p['vouchers'][]=['date'=>$p['year'].'-12-31','description'=>'Ursprünglicher Substanzverzehr · '.$row['item_id'],'lines'=>BalanceCalculation::lines([['account'=>$key,'debit_cents'=>$amount,'credit_cents'=>0,'sphere'=>$plan['sphere']],['account'=>$fixed,'debit_cents'=>0,'credit_cents'=>$amount,'sphere'=>'']],$p['accounts'])];
  $p['snapshot']=['annual'=>$row,'invoice_id'=>$b['source_id'],'acquisition_sha256'=>$b['source_hash'],'fixed_account'=>$fixed,'substance'=>$v,'substance_account'=>$key];
  $p['evidence']='Ursprünglicher belegter Mengenverbrauch '.$row['item_id'];
 }
 public static function item(array $plan,array $b,string $id,string $fixed,int $year,?array $annual):array{
  OriginalSubstance::need($annual!==null);$snap=json_decode($annual['snapshot']['annual']['snapshot'],true,512,JSON_THROW_ON_ERROR);$v=OriginalSubstance::snapshot($snap);
  OriginalSubstance::need($snap['plan']===$plan&&$v['year']===$year&&$annual['snapshot']['fixed_account']===$fixed&&$annual['snapshot']['substance']===$v&&hash_equals($b['source_hash'],$annual['snapshot']['acquisition_sha256']));
  return ['item_id'=>$id,'invoice_id'=>$b['source_id'],'receipt_number'=>$b['snapshot']['entry']['display_number'],'account'=>$fixed,'skr_code'=>'02250','sphere'=>$plan['sphere'],'acquired_on'=>$plan['acquired_on'],'months'=>0,'residual_cents'=>$plan['residual_cents'],'method'=>OriginalSubstance::METHOD,'substance'=>$v,'substance_account'=>$annual['snapshot']['substance_account'],'acquisition_hash'=>$b['source_hash'],'depreciation_hash'=>$annual['source_hash'],'cost_opening_cents'=>0,'additions_cents'=>$plan['basis_cents'],'cost_closing_cents'=>$plan['basis_cents'],'accumulated_opening_cents'=>0,'depreciation_cents'=>$v['depreciation_cents'],'accumulated_closing_cents'=>$v['depreciation_cents'],'book_opening_cents'=>0,'book_closing_cents'=>$v['closing_cents'],'expected_depreciation_cents'=>$v['depreciation_cents']];
 }
 public function check(array $report):void{
  $items=array_filter($report['asset_schedule']['items']??[],static fn($i)=>($i['method']??null)===OriginalSubstance::METHOD);if(!$items)return;
  BalanceReadSnapshot::run($this->db,function()use($report,$items){$s=new BalanceSources($this->db,$this->actor);$need=[OriginalSubstance::class,'need'];
   foreach($items as $id=>$item){$need(!array_intersect(array_keys($report),['period','club_scope'])&&$item['acquired_on']>=$report['year'].'-01-01');
    $b=$s->binding('depreciation',$id.':'.$report['year']);$need(is_array($b)&&hash_equals($item['depreciation_hash'],$b['source_hash']));
    $fresh=$s->preview($report['year'],'depreciation',$b['source_id'],$b['options']);$need(!$fresh['issues']&&hash_equals($fresh['hash'],$b['source_hash']));(new BalanceSourceHistory($this->db,$this->actor))->checked($b);
   }return [];
  });
 }
}
