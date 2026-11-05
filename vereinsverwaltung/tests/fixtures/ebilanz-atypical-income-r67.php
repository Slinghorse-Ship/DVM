<?php declare(strict_types=1);
require_once __DIR__.'/../../src/bootstrap.php';require_once __DIR__.'/balance-club-tax.php';require_once __DIR__.'/../../src/Finance/Balance/BalanceCombinedClubValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodCombinedValues.php';require_once __DIR__.'/../../src/Finance/Balance/BalancePeriodFilingValues.php';
use Kulturhus\Verein\{BalanceCalculation as C,BalanceSegmentCalculation as S,BalanceClubTaxReport as R,BalanceTaxMapping as M,BalanceCombinedClubValues as V,BalanceWholeClubReport as W,BalanceTaxValues as T};
function atypicalIncomeR67(int $year,string $mode='debit',bool $dated=false,string $bad=''):array {
 if(!in_array($mode,['debit','credit','cancelled','inactive'],true))throw new DomainException('Actual original atypical profit/loss mode required.');$code='73980';$kind=$code;$opening=false;
 $chart=C::chart(['bank'=>['name'=>'Synthetic actual bank','group'=>'cash','skr_code'=>'18000'],'capital'=>['name'=>'Synthetic retained capital','group'=>'equity','skr_code'=>'25000'],
 'hb'=>['name'=>'Original documented atypical profit distribution or loss compensation','group'=>'expense','skr_code'=>'73980'],
 'tax-capital'=>['name'=>'Actual independently confirmed fiscal participant capital','group'=>'equity','skr_code'=>'25000'],
 'sales'=>['name'=>'Original actual exempt and taxable income','group'=>'revenue','skr_code'=>'']]);
 $line=static fn($a,$d,$c,$s='')=>['account'=>$a,'debit_cents'=>$d,'credit_cents'=>$c,'sphere'=>$s];
 $start=[$line('bank',100000,0),$line('capital',0,100000)];$adjust=[];$current=[];$entries=[];$movements=[];
 if($mode!=='inactive'){
  $debit=$mode!=='credit';$entries[]=['id'=>md5('original-atypical-payment-'.$year.'-'.$mode),'date'=>$year.'-06-30','description'=>'Original independently documented atypical contractual profit payment or loss compensation',
   'lines'=>[$line('hb',$debit?1234:0,$debit?0:1234,'4'),$line('bank',$debit?0:1234,$debit?1234:0)]];
  if($mode==='cancelled')$entries[]=['id'=>md5('original-atypical-cancel-'.$year),'date'=>$year.'-07-01','description'=>'Actual original full cancellation','lines'=>[$line('hb',0,1234,'4'),$line('bank',1234,0)]];
  else {
   $n=$bad==='one-cent'?1233:1234;
   $current=[['id'=>'original-participant-current','reason'=>'Synthetic independently confirmed fiscal participant capital reclassification from original contractual payment; no partner status or quota inferred','lines'=>[$line('hb',$debit?0:$n,$debit?$n:0,'4'),$line('tax-capital',$debit?$n:0,$debit?0:$n)]]];
   $movements['tax:original-participant-current:1']=['kind'=>$debit?'withdrawal':'contribution','reason'=>'Independently confirmed original fiscal participant withdrawal/contribution'];
  }
 }
 if($bad==='missing-current'){$current=[];$movements=[];}
 if($bad==='missing-movement')$movements=[];
 $units=['ideal'=>['name'=>'Synthetic ideal','sphere'=>'1'],'capital'=>['name'=>'Synthetic assets','sphere'=>'2'],'culture'=>['name'=>'Synthetic culture','sphere'=>'3'],'food'=>['name'=>'Synthetic taxable food','sphere'=>'4']];$dest=[];
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
  $single=\Kulturhus\Verein\BalancePeriodFilingValues::whole($source,array_replace($review,['journal_sha256'=>$source['closed_sha256'],'capital_movements'=>$movements]));
 }else{
  $values=V::build($state,['year'=>$year,'journal_sha256'=>$source['closed_sha256'],'complete'=>true,'taxable_review'=>$review]);
  $single=T::build(W::fromReport($source),array_replace($review,['journal_sha256'=>$source['closed_sha256'],'capital_movements'=>$movements]));
 }

 return ['year'=>$year,'mode'=>$mode,'dated'=>$dated,'values'=>$values,'whole_taxable'=>$single,'source'=>$source,'review'=>array_replace($review,['journal_sha256'=>$source['closed_sha256'],'capital_movements'=>$movements])];
}
function atypicalIncomeR67Carry(string $mode):array {
 $f=atypicalIncomeR67(2025,$mode);$v=$f['whole_taxable'];$prior=W::rebuild($v['whole_club_scope']);$review=$f['review'];
 $opening=C::carryForward($prior['calculation'],'capital');$chart=$prior['accounts'];
 $r=['year'=>2026,'state'=>'closed','closed_sha256'=>hash('sha256',json_encode([$prior['closed_sha256'],$opening,2026])),
 'previous_sha256'=>$prior['closed_sha256'],'result_account'=>'capital','issues'=>[],'accounts'=>$chart,'opening'=>$opening,'entries'=>[],
 'calculation'=>C::build(2026,$chart,$opening,[]),'asset_schedule'=>array_replace($prior['asset_schedule'],['year'=>2026])];
 $next=W::fromReport($r);$carry=T::carryForward($prior,$review,$next);
 $after=T::build($next,['year'=>2026,'journal_sha256'=>$next['closed_sha256'],'complete'=>true,'opening_adjustments'=>$carry['entry']?[$carry['entry']]:[],'current_adjustments'=>[]]);
 foreach($v['tax']['accounts'] as $key=>$row)if(!in_array($row['group'],['expense','revenue'],true)&&$key!=='capital'){
  if($after['tax']['accounts'][$key]['opening_cents']!==$row['closing_cents']||$after['book']['accounts'][$key]['opening_cents']!==$v['book']['accounts'][$key]['closing_cents'])throw new DomainException('Original special-post carry changed.');
 }
 if($after['book']['annual_result_cents']!==0||$after['tax']['annual_result_cents']!==0||!$after['capital_comparison']['complete'])throw new DomainException('Original special-post carry result changed.');
 return $after;
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){set_error_handler(static function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});echo json_encode(str_starts_with($argv[2],'carry-')?atypicalIncomeR67Carry(substr($argv[2],6)):atypicalIncomeR67((int)$argv[1],$argv[2],($argv[3]??'')==='dated',$argv[4]??''),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
