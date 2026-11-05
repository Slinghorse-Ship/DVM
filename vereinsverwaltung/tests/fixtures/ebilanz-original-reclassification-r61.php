<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/balance-club-tax.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceTaxMapping as M,BalanceCombinedClubValues as V,BalanceWholeClubReport as W,BalanceTaxValues as T};
function originalReclassificationR61(int $year,string $kind,bool $opening=false,string $bad='',bool $dated=false):array {
 $code=$kind;$spec=['12580'=>['receivables','12000','13500','other_assets'],'12590'=>['receivables','12000','12500','receivables'],'32490'=>['liabilities','32100','31510','liabilities'],'33490'=>['liabilities','33000','33400','liabilities'],'35990'=>['liabilities','35700','35100','liabilities']][$code]??null;
 if(!$spec)throw new DomainException('Original reclassification account required.');[$group,$primary,$split,$splitGroup]=$spec;$asset=$group==='receivables';$advance=true;
 $chart=C::chart(['bank'=>['name'=>'Synthetic original bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic retained capital','group'=>'equity','skr_code'=>'25000'],
 'hb'=>['name'=>'Original synthetic retained split counter','group'=>$group,'skr_code'=>$code],
 'base'=>['name'=>'Original documented unsplit primary balance','group'=>$group,'skr_code'=>$primary],
 'split'=>['name'=>'Original documented allocated customer or supplier balance','group'=>$splitGroup,'skr_code'=>$split],
 'sales'=>['name'=>'Original synthetic exempt and taxable income','group'=>'revenue','skr_code'=>'']]);
 $line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
 $start=[$line('bank',100000,0),$line('capital',0,$asset?106000:94000),$line('base',$asset?6000:0,$asset?0:6000)];$adjust=[];$current=[];$entries=[];
 if($opening){$start[]=$line('hb',$asset?0:500,$asset?500:0);$start[]=$line('split',$asset?500:0,$asset?0:500);
  $adjust=[['id'=>'original-split-opening-tax','reason'=>'Synthetic separately confirmed original opening split valuation difference','lines'=>[$line('hb',$asset?100:0,$asset?0:100),$line('split',$asset?0:100,$asset?100:0)]]];
 }
 $amount=$bad==='overdraw'?7000:1234;$positive=$bad==='positive';$d=$asset?$positive:!$positive;
 $entries[]=['id'=>md5('original-split-'.$year.'-'.$code),'date'=>$year.'-06-30','description'=>'Actual documented original customer or supplier split; same retained primary account and separately allocated original balance','lines'=>[$line('hb',$d?$amount:0,$d?0:$amount),$line('split',$d?0:$amount,$d?$amount:0)]];
 $taxDelta=$bad==='tax-overdraw'?7000:234;$reverse=$bad!=='tax-overdraw';$d=$asset?$reverse:!$reverse;
 $current=[['id'=>'original-split-current-tax','reason'=>'Synthetic independently confirmed actual tax primary/allocated balance difference; no inferred deduction or manufactured zero','lines'=>[$line('hb',$d?$taxDelta:0,$d?0:$taxDelta),$line('split',$d?0:$taxDelta,$d?$taxDelta:0)]]];
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
   if($asset&&$l['line']['account']==='capital')$parts=['ideal'=>6000,'food'=>$l['cents']-6000];
   if(!$asset&&$l['line']['account']==='bank')$parts=['ideal'=>6000,'food'=>$l['cents']-6000];
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
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(originalReclassificationR61((int)$argv[1],$argv[2],($argv[3]??'')==='opening',$argv[4]??'',($argv[5]??'')==='dated'),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
