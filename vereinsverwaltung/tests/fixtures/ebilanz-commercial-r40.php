<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/balance-club-tax.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceTaxMapping as M,BalanceCombinedClubValues as V,BalanceWholeClubReport as W,BalanceTaxValues as T};
function commercialR40(int $year,string $kind,bool $opening=false,string $bad='',bool $dated=false):array {
 $asset=$kind==='active';$code=match($kind){'active'=>'19500','provision'=>'30600','passive'=>'30650',default=>throw new DomainException('Exact original deferred-tax kind required.')};
 $group=$asset?'other_assets':($kind==='provision'?'provisions':'deferred');$counter=$asset?'76490':'76450';
 $chart=C::chart(['bank'=>['name'=>'Synthetic bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic capital','group'=>'equity','skr_code'=>'25000'],
 'hb'=>['name'=>'Original synthetic deferred tax','group'=>$group,'skr_code'=>$code],'hb-result'=>['name'=>'Original synthetic deferred-tax profit effect','group'=>'expense','skr_code'=>$counter],
 'sales'=>['name'=>'Original synthetic exempt and taxable income','group'=>'revenue','skr_code'=>'']]);
 $line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
 $start=[$line('bank',100000,0),$line('capital',0,100000)];$adjust=[];
 if($opening){$start[]=$line('hb',$asset?1000:0,$asset?0:1000);$start[1]['credit_cents']+=$asset?1000:-1000;$adjust=[['id'=>'original-hb-opening','reason'=>'Synthetic confirmed opening deferred-tax elimination','lines'=>[$line('hb',$asset?0:1000,$asset?1000:0),$line('capital',$asset?1000:0,$asset?0:1000)]]];}
 $entry=['id'=>md5('synthetic-original-hb-'.$year.'-'.$kind),'date'=>$year.'-06-30','description'=>'Original synthetic documented deferred-tax valuation',
  'lines'=>[$line('hb',$asset?1234:0,$asset?0:1234),$line('hb-result',$asset?0:1234,$asset?1234:0,'4')]];
 $current=[['id'=>'original-hb-current','reason'=>'Synthetic confirmed current deferred-tax elimination','lines'=>[$line('hb',$asset?0:1234,$asset?1234:0),$line('hb-result',$asset?1234:0,$asset?0:1234,'4')]]];
 if($bad==='missing-current')$current=[];
 if($bad==='missing-opening')$adjust=[];
 if($bad==='one-cent'){$current[0]['lines'][0]['debit_cents']=$asset?0:1233;$current[0]['lines'][0]['credit_cents']=$asset?1233:0;$current[0]['lines'][1]['debit_cents']=$asset?1233:0;$current[0]['lines'][1]['credit_cents']=$asset?0:1233;}
 $units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic assets','sphere'=>'2'],'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic taxable food','sphere'=>'4']];$entries=[$entry];$dest=[];
 foreach($units as $unit=>$u){$id=md5('synthetic-hb-income-'.$year.'-'.$unit);$n=101+count($dest)*31;$dest[$id]=$unit;$entries[]=['id'=>$id,'date'=>$year.'-11-01','description'=>'Original synthetic income','lines'=>[$line('bank',$n,0),$line('sales',0,$n,$u['sphere'])]];}
 $source=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$start,$entries],JSON_THROW_ON_ERROR)),'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],
  'accounts'=>$chart,'opening'=>$start,'entries'=>$entries,'calculation'=>C::build($year,$chart,$start,$entries),
  'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(R::FIELDS,0),'issues'=>[]]];
 if($dated){$source['period']=['start'=>$year.'-03-01','end'=>$year.'-12-31'];$source['asset_schedule']=['period'=>$source['period'],'items'=>[],'issues'=>[]];$source['calculation']=C::build($year,$chart,$start,$entries,$source['period']);$source['closed_sha256']=hash('sha256',$source['closed_sha256'].'-original-dated');}
 if($source['calculation']['issues'])throw new DomainException(implode(' ',$source['calculation']['issues']));
 $alloc=[];foreach(S::sources($source) as $key=>$l)$alloc[$key]=['parts'=>[($dest[$l['voucher']]??'food')=>$l['cents']],'reason'=>'Synthetic original allocation'];
 if($dated){
  $a=S::build($source,$units,$alloc,[]);$w=\Kulturhus\Verein\BalanceInternalClearing::build('synthetic-original-hb',$source,$a['units'],$a['allocations'],['advances'=>[],'repayments'=>[]]);
  $statement=\Kulturhus\Verein\BalanceClearingStatement::build($source,$a,$w);$source['assets']=\Kulturhus\Verein\BalancePeriodAssetAllocation::build($source,$a['units'],$statement['units'],[]);
  $ref=['revision'=>2,'sha256'=>hash('sha256','Synthetic original HGB complete area review')];
  $income=\Kulturhus\Verein\BalanceClubIncomeReconciliation::rebuild(['schema'=>3,'kind'=>'club_income_reconciliation','club_id'=>'synthetic-original-hb','source'=>$source,'allocation'=>$a,'clearing'=>$w,'review'=>['allocation'=>$ref,'clearing'=>$ref],'previous'=>null]);
  $part=\Kulturhus\Verein\BalancePeriodClubReport::build($income);
 }else{$state=clubTaxState($source,$units,$alloc);$part=R::fromState($state);}

 $review=['year'=>$year,'journal_sha256'=>$part['closed_sha256'],'complete'=>true,'opening_adjustments'=>$adjust,'current_adjustments'=>$current];
 if($dated){
  $review['period']=$source['period'];$review['asset_tax_plans']=[];$values=\Kulturhus\Verein\BalancePeriodCombinedValues::build($income,$review);
  $single=\Kulturhus\Verein\BalancePeriodFilingValues::whole($source,array_replace($review,['journal_sha256'=>$source['closed_sha256'],'capital_movements'=>[]]));
 }else{
  $values=V::build($state,['year'=>$year,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'taxable_review'=>$review]);
  $single=T::build(W::fromReport($source),array_replace($review,['journal_sha256'=>$source['closed_sha256']]));
 }

 return ['year'=>$year,'kind'=>$kind,'opening'=>$opening,'dated'=>$dated,'values'=>$values,'whole_taxable'=>$single,'source'=>$source];
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(commercialR40((int)$argv[1],$argv[2],($argv[3]??'')==='opening',$argv[4]??'',($argv[5]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
