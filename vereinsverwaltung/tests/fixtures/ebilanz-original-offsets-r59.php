<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/balance-club-tax.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceTaxMapping as M,BalanceCombinedClubValues as V,BalanceWholeClubReport as W,BalanceTaxValues as T};
function originalOffsetsR59(int $year,string $kind,bool $opening=false,string $bad='',bool $dated=false):array {
 $code=$kind;$advance=$code==='11900';if(!in_array($code,['11900','58600'],true))throw new DomainException('Original offset account required.');
 $chart=C::chart(['bank'=>['name'=>'Synthetic original bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic retained capital','group'=>'equity','skr_code'=>'25000'],
 'hb'=>['name'=>'Original synthetic signed offset','group'=>$advance?'inventory':'expense','skr_code'=>$code],
 'base'=>['name'=>'Original underlying inventory or material cost','group'=>$advance?'inventory':'expense','skr_code'=>$advance?'10000':'50000'],
 'stock'=>['name'=>'Original actual material inventory','group'=>'inventory','skr_code'=>'10000'],
 'sales'=>['name'=>'Original synthetic exempt and taxable income','group'=>'revenue','skr_code'=>'']]);
 $line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
 $start=[$line('bank',100000,0),$line('capital',0,106000),$line($advance?'base':'stock',6000,0)];$adjust=[];$current=[];$entries=[];
 if($advance&&$opening){$start[]=$line('hb',0,500);$start[1]['credit_cents']-=500;$adjust=[['id'=>'original-advance-opening','reason'=>'Synthetic separately confirmed original opening advance value','lines'=>[$line('hb',100,0),$line('capital',0,100)]]];}
 $amount=$bad==='overdraw'?12000:1234;$positive=$bad==='positive';
 if($advance){
  $entries[]=['id'=>md5('original-advance-'.$year),'date'=>$year.'-06-30','description'=>'Actual net received advance on retained inventory orders; explicitly reviewed open deduction','lines'=>[$line('hb',$positive?$amount:0,$positive?0:$amount),$line('bank',$positive?0:$amount,$positive?$amount:0)]];
  $current=[['id'=>'original-advance-tax','reason'=>'Synthetic independently confirmed current received advance valuation difference','lines'=>[$line('hb',234,0),$line('bank',0,234)]]];
 }else{
  $entries[]=['id'=>md5('original-material-cost-'.$year),'date'=>$year.'-06-01','description'=>'Actual underlying original50000 cost','lines'=>[$line('base',5000,0,'4'),$line('bank',0,5000)]];
  $entries[]=['id'=>md5('original-material-contra-'.$year),'date'=>$year.'-06-30','description'=>'Actual documented material cost transfer to retained inventory','lines'=>[$line('hb',$positive?$amount:0,$positive?0:$amount,'4'),$line('stock',$positive?0:$amount,$positive?$amount:0)]];
  $current=[['id'=>'original-material-tax','reason'=>'Synthetic separately confirmed tax cost/inventory difference; no deduction calculated','lines'=>[$line('hb',234,0,'4'),$line('stock',0,234)]]];
 }
 $units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic assets','sphere'=>'2'],'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic taxable food','sphere'=>'4']];$dest=[];
 foreach($units as $unit=>$u){$id=md5('synthetic-hb-income-'.$year.'-'.$unit);$n=101+count($dest)*31;$dest[$id]=$unit;$entries[]=['id'=>$id,'date'=>$year.'-11-01','description'=>'Original synthetic income','lines'=>[$line('bank',$n,0),$line('sales',0,$n,$u['sphere'])]];}
 $source=['year'=>$year,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$chart,$start,$entries],JSON_THROW_ON_ERROR)),'previous_sha256'=>null,'result_account'=>'capital','issues'=>[],
  'accounts'=>$chart,'opening'=>$start,'entries'=>$entries,'calculation'=>C::build($year,$chart,$start,$entries),
  'asset_schedule'=>['year'=>$year,'items'=>[],'totals'=>array_fill_keys(R::FIELDS,0),'issues'=>[]]];
 if($dated){$source['period']=['start'=>$year.'-03-01','end'=>$year.'-12-31'];$source['asset_schedule']=['period'=>$source['period'],'items'=>[],'issues'=>[]];$source['calculation']=C::build($year,$chart,$start,$entries,$source['period']);$source['closed_sha256']=hash('sha256',$source['closed_sha256'].'-original-dated');}
 if($source['calculation']['issues'])throw new DomainException(implode(' ',$source['calculation']['issues']));
 $alloc=[];foreach(S::sources($source) as $key=>$l){
  $parts=[($dest[$l['voucher']]??'food')=>$l['cents']];
  if($bad==='uncovered-sphere'&&$advance&&$l['voucher']==='opening'){
   if($l['line']['account']==='base')$parts=['ideal'=>$l['cents']];
   if($l['line']['account']==='capital')$parts=['ideal'=>6000,'food'=>$l['cents']-6000];
  }
  $alloc[$key]=['parts'=>$parts,'reason'=>'Synthetic original allocation'];
 }
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
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(originalOffsetsR59((int)$argv[1],$argv[2],($argv[3]??'')==='opening',$argv[4]??'',($argv[5]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
